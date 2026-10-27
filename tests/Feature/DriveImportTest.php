<?php

namespace Tests\Feature;

use App\Jobs\ImportDriveFolder;
use App\Jobs\ImportDriveImage;
use App\Jobs\ProcessDocument;
use App\Models\FormDocument;
use App\Models\User;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class DriveImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ocr.key' => 'ocr-test', 'drive.key' => 'drive-test', 'queue.default' => 'database']);
        Http::preventStrayRequests();
    }

    public function test_guest_cannot_import_or_retry(): void
    {
        Queue::fake();

        $this->post('/drive-imports')->assertRedirect('/login');
        $this->post('/drive-imports/1/retry')->assertRedirect('/login');

        $this->assertDatabaseCount('drive_imports', 0);
        Queue::assertNothingPushed();
    }

    #[TestWith(['https://drive.google.com/drive/folders/folder-123?resourcekey=key-123', 'folder', 'folder-123', 'key-123'])]
    #[TestWith(['https://drive.google.com/file/d/image-123/view', 'file', 'image-123', null])]
    #[TestWith(['https://drive.google.com/open?id=image-123', 'file', 'image-123', null])]
    public function test_link_is_saved_and_queued_without_downloading(string $link, string $kind, string $id, ?string $key): void
    {
        Queue::fake([ImportDriveFolder::class]);
        $user = User::factory()->create();

        $this->actingAs($user)->post('/drive-imports', ['drive_link' => $link, 'uploaded_by' => 999])->assertRedirect('/?page=queue');

        $this->assertDatabaseHas('drive_imports', ['uploaded_by' => $user->id, 'drive_id' => $id, 'kind' => $kind, 'resource_key' => $key]);
        Queue::assertPushed(ImportDriveFolder::class, fn ($job) => $job->importId === DB::table('drive_imports')->value('id'));
        Http::assertNothingSent();
    }

    #[TestWith(['http://drive.google.com/drive/folders/id'])]
    #[TestWith(['https://drive.google.com.evil.test/drive/folders/id'])]
    #[TestWith(['https://127.0.0.1/file/d/id'])]
    #[TestWith(['https://drive.google.com/open?id[]=id'])]
    #[TestWith(['https://drive.google.com/drive/folders/id?resourcekey[]=key'])]
    #[TestWith(['https://drive.google.com/drive/folders/id%27'])]
    public function test_invalid_links_are_rejected_without_queuing(string $link): void
    {
        Queue::fake();

        $this->actingAs(User::factory()->create())->post('/drive-imports', ['drive_link' => $link])->assertSessionHasErrors('drive_link');

        $this->assertDatabaseCount('drive_imports', 0);
        Queue::assertNothingPushed();
    }

    #[TestWith(['drive.key', null, 'Google Drive access is not configured. Set GOOGLE_DRIVE_API_KEY in the server .env file and enable Google Drive API for that key. The Gemini key is used separately for reading images.'])]
    #[TestWith(['ocr.key', null, 'Gemini is not configured. Set GEMINI_API_KEY in the server .env file before importing.'])]
    #[TestWith(['queue.default', 'sync', 'Drive imports require QUEUE_CONNECTION=database and a running queue worker.'])]
    public function test_import_requires_provider_keys_and_background_queue(string $setting, ?string $value, string $message): void
    {
        config([$setting => $value]);
        Queue::fake();

        $this->actingAs(User::factory()->create())->post('/drive-imports', ['drive_link' => 'https://drive.google.com/drive/folders/folder'])->assertSessionHasErrors(['drive_link' => $message]);

        $this->assertDatabaseCount('drive_imports', 0);
        Queue::assertNothingPushed();
    }

    public function test_folder_pages_queue_more_than_one_hundred_images_without_duplicates(): void
    {
        Queue::fake([ImportDriveFolder::class, ImportDriveImage::class]);
        $import = $this->createImport();
        $files = array_map(fn (int $number): array => ['id' => 'image-'.$number, 'name' => 'Form '.$number.'.jpg', 'mimeType' => 'image/jpeg'], range(1, 100));
        Http::fake(['https://www.googleapis.com/drive/v3/files?*' => Http::sequence()
            ->push(['files' => $files, 'nextPageToken' => 'page-two'])
            ->push(['files' => [$files[0], ['id' => 'image-101', 'name' => 'Final.jpg', 'mimeType' => 'image/jpeg']]])]);

        (new ImportDriveFolder($import))->handle(app(GoogleDriveService::class));
        $this->assertDatabaseHas('drive_imports', ['id' => $import, 'page_token' => 'page-two', 'status' => 'listing']);
        (new ImportDriveFolder($import))->handle(app(GoogleDriveService::class));
        (new ImportDriveFolder($import))->handle(app(GoogleDriveService::class));

        $this->assertDatabaseCount('drive_import_items', 101);
        $this->assertDatabaseHas('drive_imports', ['id' => $import, 'status' => 'listed', 'page_token' => null]);
        Queue::assertPushed(ImportDriveImage::class, 101);
        Queue::assertPushed(ImportDriveFolder::class, 1);
        Http::assertSent(fn (Request $request): bool => ($request['pageToken'] ?? null) === 'page-two' && $request->hasHeader('X-Goog-Api-Key', 'drive-test'));
    }

    public function test_empty_intermediate_page_still_continues_listing(): void
    {
        Queue::fake([ImportDriveFolder::class]);
        $import = $this->createImport();
        Http::fake(['https://www.googleapis.com/drive/v3/files?*' => Http::response(['files' => [], 'nextPageToken' => 'next'])]);

        (new ImportDriveFolder($import))->handle(app(GoogleDriveService::class));

        $this->assertDatabaseHas('drive_imports', ['id' => $import, 'page_token' => 'next', 'status' => 'listing']);
        Queue::assertPushed(ImportDriveFolder::class);
        Http::assertSentCount(1);
    }

    public function test_single_file_import_preserves_resource_key(): void
    {
        Queue::fake([ImportDriveImage::class]);
        $import = $this->createImport(null, ['kind' => 'file', 'drive_id' => 'image', 'resource_key' => 'shared-key']);
        Http::fake(['https://www.googleapis.com/drive/v3/files/image?*' => Http::response(['id' => 'image', 'name' => 'Form.jpg', 'mimeType' => 'image/jpeg'])]);

        (new ImportDriveFolder($import))->handle(app(GoogleDriveService::class));

        $this->assertDatabaseHas('drive_import_items', ['drive_import_id' => $import, 'drive_id' => 'image', 'resource_key' => 'shared-key']);
        $this->assertDatabaseHas('drive_imports', ['id' => $import, 'status' => 'listed']);
        Queue::assertPushed(ImportDriveImage::class, 1);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-Goog-Drive-Resource-Keys', 'image/shared-key'));
    }

    public function test_ocr_jobs_wait_until_the_previous_image_finishes(): void
    {
        $first = (new ProcessDocument(1))->withFakeQueueInteractions();
        $second = (new ProcessDocument(2))->withFakeQueueInteractions();
        $secondRan = false;

        $first->middleware()[0]->handle($first, function () use ($second, &$secondRan): void {
            $second->middleware()[0]->handle($second, function () use (&$secondRan): void {
                $secondRan = true;
            });
        });

        $second->assertReleased(30);
        $this->assertFalse($secondRan);
        $second->middleware()[0]->handle($second, function () use (&$secondRan): void {
            $secondRan = true;
        });
        $this->assertTrue($secondRan);
    }

    public function test_exhausted_import_jobs_expose_safe_retryable_errors(): void
    {
        $import = $this->createImport(null, ['page_token' => 'saved-cursor']);
        $item = $this->createItem($import);

        (new ImportDriveFolder($import))->failed(new \RuntimeException('secret-provider-token'));
        (new ImportDriveImage($item))->failed(new \RuntimeException('secret-provider-token'));

        $this->assertDatabaseHas('drive_imports', ['id' => $import, 'status' => 'failed', 'page_token' => 'saved-cursor']);
        $this->assertDatabaseHas('drive_import_items', ['id' => $item, 'status' => 'failed']);
        $this->assertStringNotContainsString('secret-provider-token', DB::table('drive_imports')->value('error_information'));
    }

    public function test_download_is_stored_privately_and_ocr_is_queued_once(): void
    {
        Storage::fake('local');
        Queue::fake([ProcessDocument::class]);
        $import = $this->createImport();
        $item = $this->createItem($import);
        $image = UploadedFile::fake()->image('original.jpg', 900, 1200)->getContent();
        Http::fake(['https://www.googleapis.com/drive/v3/files/image?*' => Http::sequence()
            ->push(['id' => 'image', 'name' => 'Original.jpg', 'mimeType' => 'image/jpeg', 'size' => (string) strlen($image)])
            ->push($image, 200, ['Content-Type' => 'image/jpeg'])]);

        (new ImportDriveImage($item))->handle(app(GoogleDriveService::class));
        (new ImportDriveImage($item))->handle(app(GoogleDriveService::class));

        $document = FormDocument::sole();
        $this->assertDatabaseHas('drive_import_items', ['id' => $item, 'status' => 'imported', 'form_document_id' => $document->id]);
        $this->assertSame(hash('sha256', $image), $document->image_checksum);
        Storage::assertExists($document->original_image_path);
        Queue::assertPushed(ProcessDocument::class, 1);
        Http::assertSentCount(2);
    }

    #[TestWith(['application/pdf', '100', 'not-an-image'])]
    #[TestWith(['image/jpeg', '16000000', 'oversized'])]
    #[TestWith(['image/jpeg', '100', '<html>Drive sign-in</html>'])]
    public function test_invalid_downloads_fail_without_creating_documents(string $mime, string $size, string $body): void
    {
        Storage::fake('local');
        Queue::fake();
        $item = $this->createItem($this->createImport());
        Http::fake(['https://www.googleapis.com/drive/v3/files/image?*' => Http::sequence()
            ->push(['id' => 'image', 'name' => 'Original.jpg', 'mimeType' => $mime, 'size' => $size])
            ->push($body)]);

        (new ImportDriveImage($item))->handle(app(GoogleDriveService::class));

        $this->assertDatabaseHas('drive_import_items', ['id' => $item, 'status' => 'failed']);
        $this->assertDatabaseCount('form_documents', 0);
        $this->assertSame([], Storage::allFiles('documents'));
        Queue::assertNothingPushed();
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/files/image?'));
    }

    public function test_retry_only_queues_failed_items_and_retains_listing_cursor(): void
    {
        Queue::fake([ImportDriveFolder::class, ImportDriveImage::class]);
        $user = User::factory()->create();
        $import = $this->createImport($user, ['status' => 'failed', 'page_token' => 'resume-here']);
        $failed = $this->createItem($import, ['status' => 'failed']);
        $this->createItem($import, ['drive_id' => 'done', 'status' => 'imported']);

        $this->actingAs($user)->post('/drive-imports/'.$import.'/retry')->assertRedirect();
        $this->post('/drive-imports/'.$import.'/retry')->assertRedirect();

        $this->assertDatabaseHas('drive_imports', ['id' => $import, 'status' => 'queued', 'page_token' => 'resume-here']);
        $this->assertDatabaseHas('drive_import_items', ['id' => $failed, 'status' => 'queued']);
        Queue::assertPushed(ImportDriveFolder::class, 1);
        Queue::assertPushed(ImportDriveImage::class, 1);
    }

    public function test_other_users_cannot_see_or_retry_imports(): void
    {
        Queue::fake();
        $import = $this->createImport(null, ['status' => 'failed', 'error_information' => 'Private Drive error']);

        $this->actingAs(User::factory()->create())->post('/drive-imports/'.$import.'/retry')->assertNotFound();
        $this->get('/?page=queue')->assertOk()->assertDontSee('Private Drive error');

        $this->assertDatabaseHas('drive_imports', ['id' => $import, 'status' => 'failed']);
        Queue::assertNothingPushed();
    }

    public function test_import_progress_escapes_failed_filenames(): void
    {
        $user = User::factory()->create();
        $import = $this->createImport($user, ['status' => 'listed']);
        $this->createItem($import, ['status' => 'failed', 'filename' => '<script>alert(1)</script>']);

        $this->actingAs($user)->get('/?page=queue')->assertOk()->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false)->assertSee('Retry failed imports');
    }

    /** @param array<string, mixed> $attributes */
    private function createImport(?User $user = null, array $attributes = []): int
    {
        return DB::table('drive_imports')->insertGetId(array_replace([
            'uploaded_by' => ($user ?? User::factory()->create())->id,
            'drive_id' => 'folder', 'kind' => 'folder', 'created_at' => now(), 'updated_at' => now(),
        ], $attributes));
    }

    /** @param array<string, mixed> $attributes */
    private function createItem(int $import, array $attributes = []): int
    {
        return DB::table('drive_import_items')->insertGetId(array_replace([
            'drive_import_id' => $import, 'drive_id' => 'image', 'filename' => 'Original.jpg', 'created_at' => now(), 'updated_at' => now(),
        ], $attributes));
    }
}
