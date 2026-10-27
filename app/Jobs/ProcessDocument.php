<?php

namespace App\Jobs;

use App\Models\FormDocument;
use App\Services\FieldNormalizationService;
use App\Services\GeminiVisionService;
use App\Services\ImagePreprocessingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProcessDocument implements ShouldQueue
{
    use Queueable;

    public $tries = 0;

    public int $maxExceptions = 2;

    public $timeout = 3600;

    public function __construct(public int $documentId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('ocr-processing'))->shared()->releaseAfter(30)->expireAfter(3650)];
    }

    public function handle(GeminiVisionService $gemini): void
    {
        $d = FormDocument::findOrFail($this->documentId);
        if (in_array($d->processing_status, ['verified', 'review'])) {
            return;
        }
        $d->update(['processing_status' => 'processing', 'error_information' => null]);
        try {
            $path = Storage::path($d->original_image_path);
            $size = getimagesize($path);
            if (! $size || min($size[0], $size[1]) < 600) {
                throw new \RuntimeException('Image resolution is too low. Upload a sharper image with at least 600 pixels on each side.');
            }
            $images = app(ImagePreprocessingService::class);
            $working = $images->prepare($d->original_image_path, $d->id);
            $d->update(['processed_image_path' => $working]);
            $bytes = Storage::get($working);
            $mime = 'image/png';
            $quality = $gemini->assess($bytes, $mime);
            DB::table('extraction_runs')->insert(['form_document_id' => $d->id, 'provider' => 'gemini', 'model_name' => config('ocr.model'), 'prompt_version' => '1', 'processing_stage' => 'quality_assessment', 'raw_response_json' => json_encode($quality), 'request_status' => 'completed', 'created_at' => now(), 'updated_at' => now()]);
            $a = $gemini->extract($bytes, $mime);
            $b = $gemini->extract($bytes, $mime, 'Independently verify every character, especially phone digits, email and NIC. Flag ambiguous characters.');
            $crops = [];
            $template = $d->template_id ? DB::table('form_templates')->find($d->template_id) : null;
            $anchors = $template ? (json_decode($template->configuration, true)['anchors'] ?? []) : [];
            foreach ($a['fields'] as $f) {
                if ($f['status'] === 'blank') {
                    continue;
                }$crop = $images->crop($working, $anchors[$f['field_key']] ?? $f['bounding_box'], $d->id, $f['field_key']);
                if ($crop) {
                    $crops[$f['field_key']] = ['path' => $crop, 'result' => $gemini->extract(Storage::get($crop), 'image/png', 'Read only the requested field from this crop. If its label is not visible or the crop is incomplete, mark uncertain.', $f['field_key'])];
                }
            }
            DB::transaction(function () use ($a, $b, $d, $crops, $quality) {
                $runs = ['full_document' => $a, 'independent_verification' => $b];
                foreach ($crops as $key => $crop) {
                    $runs['crop_'.$key] = $crop['result'];
                }foreach ($runs as $stage => $r) {
                    DB::table('extraction_runs')->insert(['form_document_id' => $d->id, 'provider' => 'gemini', 'model_name' => config('ocr.model'), 'prompt_version' => '1', 'processing_stage' => $stage, 'raw_response_json' => json_encode($r), 'request_status' => 'completed', 'created_at' => now(), 'updated_at' => now()]);
                }
                $second = collect($b['fields'])->keyBy('field_key');
                foreach ($a['fields'] as $f) {
                    $other = $second[$f['field_key']];
                    $status = $f['raw_text'] !== $other['raw_text'] ? 'conflicting' : $f['status'];
                    if ($other['status'] === 'uncertain' && $status === 'clear') {
                        $status = 'uncertain';
                    }$crop = $crops[$f['field_key']] ?? null;
                    if ($crop) {
                        $cf = $crop['result']['fields'][0];
                        if ($cf['raw_text'] !== $f['raw_text']) {
                            $status = 'conflicting';
                        } elseif ($cf['status'] !== 'clear' && $status === 'clear') {
                            $status = 'uncertain';
                        }
                    }$raw = $f['raw_text'];
                    $evaluation = app(FieldNormalizationService::class)->normalize($f['field_key'], $raw);
                    $normalized = $evaluation['value'];
                    if (! $quality['readable'] && $status === 'clear') {
                        $status = 'uncertain';
                    }
                    if ($evaluation['issues'] && $status !== 'conflicting') {
                        $status = 'uncertain';
                    }
                    $d->fields()->updateOrCreate(['field_key' => $f['field_key']], ['raw_text' => $raw, 'normalized_value' => $status === 'clear' ? $normalized : null, 'recognition_status' => $status, 'review_required' => true, 'evidence' => ['first' => $f, 'second' => $other, 'crop' => $crop, 'validation' => $evaluation['issues'], 'quality' => $quality]]);
                }$d->update(['processing_status' => 'review']);
            });
        } catch (\Throwable $e) {
            $message = str_contains($e->getMessage(), 'GEMINI_API_KEY') ? $e->getMessage() : 'Processing could not complete. Check provider configuration and image quality, then retry.';
            $d->update(['processing_status' => 'failed', 'error_information' => $message]);
            DB::table('extraction_runs')->insert(['form_document_id' => $d->id, 'provider' => 'gemini', 'model_name' => config('ocr.model'), 'prompt_version' => '1', 'processing_stage' => 'pipeline', 'request_status' => 'failed', 'error_information' => get_class($e), 'created_at' => now(), 'updated_at' => now()]);
            throw new \RuntimeException($message);
        }
    }
}
