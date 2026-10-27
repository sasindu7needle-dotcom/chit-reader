<?php

namespace App\Jobs;

use App\Models\FormDocument;
use App\Services\GoogleDriveService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ImportDriveImage implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 300;

    public function __construct(public int $itemId) {}

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('drive-image-'.$this->itemId))->releaseAfter(30)->expireAfter(360)];
    }

    public function handle(GoogleDriveService $drive): void
    {
        $item = DB::table('drive_import_items')->find($this->itemId);
        if (! $item || $item->status === 'imported') {
            return;
        }
        $import = DB::table('drive_imports')->find($item->drive_import_id);
        $file = $drive->file($item->drive_id, $item->resource_key);
        if (! in_array($file['mimeType'], ['image/jpeg', 'image/png', 'image/webp'], true) || (int) ($file['size'] ?? 0) > 15360 * 1024) {
            $this->failed(null);

            return;
        }
        $temporary = tempnam(sys_get_temp_dir(), 'drive-');
        $path = null;
        try {
            $response = $drive->request($item->drive_id, $file['resourceKey'] ?? $item->resource_key)
                ->withOptions(['sink' => $temporary, 'progress' => function (float $total, float $downloaded): void {
                    if (max($total, $downloaded) > 15360 * 1024) {
                        throw new RuntimeException('Drive image exceeds 15 MB.');
                    }
                }])->get('/files/'.$item->drive_id, ['alt' => 'media'])->throw();
            if ($response->status() !== 200) {
                throw new RuntimeException('Drive did not return image content.');
            }
            $size = @getimagesize($temporary);
            if (filesize($temporary) > 15360 * 1024 || ! $size || ! in_array($size['mime'], ['image/jpeg', 'image/png', 'image/webp'], true) || min($size[0], $size[1]) < 600 || max($size[0], $size[1]) > 5000) {
                $this->failed(null);

                return;
            }
            $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$size['mime']];
            $path = 'documents/'.Str::uuid().'.'.$extension;
            $stream = fopen($temporary, 'rb');
            try {
                if (! Storage::put($path, $stream)) {
                    throw new RuntimeException('Could not store Drive image.');
                }
            } finally {
                fclose($stream);
            }
            DB::transaction(function () use ($item, $import, $path, $temporary) {
                $document = FormDocument::create([
                    'uploaded_by' => $import->uploaded_by, 'original_image_path' => $path,
                    'upload_filename' => $item->filename, 'image_checksum' => hash_file('sha256', $temporary),
                ]);
                DB::table('drive_import_items')->where('id', $item->id)->update([
                    'form_document_id' => $document->id, 'status' => 'imported', 'error_information' => null, 'updated_at' => now(),
                ]);
                ProcessDocument::dispatch($document->id);
            });
            $path = null;
        } finally {
            if ($path) {
                Storage::delete($path);
            }
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        DB::table('drive_import_items')->where('id', $this->itemId)->update([
            'status' => 'failed', 'error_information' => 'Image import failed. Check Drive access and use JPG, PNG or WEBP, up to 15 MB, 600-5000 pixels per side.', 'updated_at' => now(),
        ]);
    }
}
