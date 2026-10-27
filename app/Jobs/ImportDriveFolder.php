<?php

namespace App\Jobs;

use App\Services\GoogleDriveService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Throwable;

class ImportDriveFolder implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 180;

    public function __construct(public int $importId) {}

    public function backoff(): array
    {
        return [30, 120, 300, 600];
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('drive-import-'.$this->importId))->releaseAfter(30)->expireAfter(240)];
    }

    public function handle(GoogleDriveService $drive): void
    {
        $import = DB::table('drive_imports')->find($this->importId);
        if (! $import || $import->status === 'listed') {
            return;
        }
        $page = $import->kind === 'folder'
            ? $drive->folder($import->drive_id, $import->resource_key, $import->page_token)
            : ['files' => [$drive->file($import->drive_id, $import->resource_key)]];
        DB::transaction(function () use ($page, $import) {
            foreach ($page['files'] ?? [] as $file) {
                $inserted = DB::table('drive_import_items')->insertOrIgnore([
                    'drive_import_id' => $import->id, 'drive_id' => $file['id'],
                    'resource_key' => $file['resourceKey'] ?? ($import->kind === 'file' ? $import->resource_key : null),
                    'filename' => mb_substr($file['name'], 0, 255),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                if ($inserted) {
                    $id = DB::table('drive_import_items')->where('drive_import_id', $import->id)->where('drive_id', $file['id'])->value('id');
                    ImportDriveImage::dispatch($id);
                }
            }
            $next = $page['nextPageToken'] ?? null;
            DB::table('drive_imports')->where('id', $import->id)->update([
                'page_token' => $next, 'status' => $next ? 'listing' : 'listed',
                'error_information' => null, 'updated_at' => now(),
            ]);
            if ($next) {
                self::dispatch($import->id);
            }
        });
    }

    public function failed(?Throwable $exception): void
    {
        DB::table('drive_imports')->where('id', $this->importId)->update([
            'status' => 'failed', 'error_information' => 'Could not read Drive. Check link access, API key and quota, then retry.', 'updated_at' => now(),
        ]);
    }
}
