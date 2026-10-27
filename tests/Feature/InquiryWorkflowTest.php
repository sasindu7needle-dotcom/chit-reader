<?php

namespace Tests\Feature;

use App\Jobs\ProcessDocument;
use App\Models\FormDocument;
use App\Models\User;
use App\Services\GeminiVisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InquiryWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_workspace_screen_renders(): void
    {
        foreach (['dashboard', 'upload', 'queue', 'review', 'students', 'settings'] as $page) {
            $this->get('/?page='.$page)->assertOk();
        }
    }

    public function test_upload_requires_authentication(): void
    {
        $this->post('/documents')->assertRedirect('/login');
    }

    public function test_missing_api_key_is_reported_before_accepting_an_upload(): void
    {
        config(['ocr.key' => null]);
        Queue::fake();
        $this->actingAs(User::factory()->create())->post('/documents', ['forms' => [UploadedFile::fake()->image('form.jpg', 900, 1200)]])->assertSessionHasErrors('ocr');
        $this->assertDatabaseCount('form_documents', 0);
        Queue::assertNothingPushed();
        $this->get('/?page=queue')->assertOk()->assertSee('OCR setup required');
    }

    public function test_processing_status_only_returns_the_current_users_documents(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        foreach ([$user, $other] as $owner) {
            FormDocument::create(['uploaded_by' => $owner->id, 'original_image_path' => 'private.jpg', 'upload_filename' => 'form.jpg', 'image_checksum' => str_repeat('c', 64)]);
        }
        $this->actingAs($user)->getJson('/processing-status')->assertOk()->assertJsonCount(1)->assertJsonPath('0.processing_status', 'queued')->assertJsonMissingPath('0.original_image_path');
    }

    public function test_upload_is_private_and_queued(): void
    {
        config(['ocr.key' => 'test']);
        Storage::fake('local');
        Queue::fake();
        $user = User::factory()->create();
        $this->actingAs($user)->post('/documents', ['forms' => [UploadedFile::fake()->image('form.jpg', 900, 1200)]])->assertRedirect('/?page=queue');
        $doc = FormDocument::firstOrFail();
        Storage::assertExists($doc->original_image_path);
        Queue::assertPushed(ProcessDocument::class);
        $this->actingAs(User::factory()->create())->get('/documents/'.$doc->id.'/image')->assertForbidden();
    }

    public function test_malformed_provider_output_is_rejected(): void
    {
        config(['ocr.key' => 'test']);
        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => '{"fields":[]}']]]]]])]);
        $this->expectException(ValidationException::class);
        app(GeminiVisionService::class)->extract('test', 'image/png');
    }

    public function test_disagreement_is_flagged_and_phone_fields_stay_separate(): void
    {
        Storage::fake('local');
        config(['ocr.key' => 'test']);
        $doc = FormDocument::create(['uploaded_by' => User::factory()->create()->id, 'original_image_path' => UploadedFile::fake()->image('form.jpg', 900, 1200)->store('documents'), 'upload_filename' => 'form.jpg', 'image_checksum' => str_repeat('a', 64)]);
        $fields = [];
        foreach (config('ocr.fields') as $key => $label) {
            $fields[] = ['field_key' => $key, 'raw_text' => $key === 'contact_number' ? '0753964881' : null, 'status' => $key === 'contact_number' ? 'clear' : 'blank', 'notes' => '', 'bounding_box' => []];
        }$second = $fields;
        $second[1]['raw_text'] = '0753964887';
        $response = fn ($f) => ['candidates' => [['content' => ['parts' => [['text' => json_encode(['fields' => $f])]]]]]];
        Http::fake(['*' => Http::sequence()->push(['candidates' => [['content' => ['parts' => [['text' => '{"readable":true,"issues":[]}']]]]]])->push($response($fields))->push($response($second))]);
        app(ProcessDocument::class, ['documentId' => $doc->id])->handle(app(GeminiVisionService::class));
        $this->assertDatabaseHas('extracted_fields', ['form_document_id' => $doc->id, 'field_key' => 'contact_number', 'recognition_status' => 'conflicting', 'normalized_value' => null]);
        $this->assertDatabaseHas('extracted_fields', ['form_document_id' => $doc->id, 'field_key' => 'whatsapp_number', 'raw_text' => null, 'recognition_status' => 'blank']);
        $this->assertDatabaseMissing('student_inquiries', ['form_document_id' => $doc->id]);
        $this->actingAs(User::findOrFail($doc->uploaded_by))->get('/?page=review&document='.$doc->id)->assertOk()->assertSee('Compare recognition evidence')->assertDontSee('Document history');
    }

    public function test_quality_warnings_allow_extraction_but_require_manual_confirmation(): void
    {
        Storage::fake('local');
        $document = FormDocument::create(['uploaded_by' => User::factory()->create()->id, 'original_image_path' => UploadedFile::fake()->image('form.jpg', 900, 1200)->store('documents'), 'upload_filename' => 'form.jpg', 'image_checksum' => str_repeat('d', 64)]);
        $quality = ['readable' => false, 'issues' => ['Student name is partially scribbled.']];
        $fields = [];
        foreach (array_keys(config('ocr.fields')) as $key) {
            $fields[] = ['field_key' => $key, 'raw_text' => $key === 'contact_number' ? '0753964881' : null, 'status' => $key === 'contact_number' ? 'clear' : 'blank', 'notes' => '', 'bounding_box' => []];
        }
        $this->mock(GeminiVisionService::class, function ($mock) use ($quality, $fields): void {
            $mock->shouldReceive('assess')->once()->andReturn($quality);
            $mock->shouldReceive('extract')->twice()->andReturn(['fields' => $fields]);
        });
        Http::preventStrayRequests();

        app(ProcessDocument::class, ['documentId' => $document->id])->handle(app(GeminiVisionService::class));

        $this->assertSame('review', $document->fresh()->processing_status);
        $this->assertNull($document->fresh()->error_information);
        $phone = $document->fields()->where('field_key', 'contact_number')->firstOrFail();
        $this->assertSame('0753964881', $phone->raw_text);
        $this->assertSame('uncertain', $phone->recognition_status);
        $this->assertNull($phone->normalized_value);
        $this->assertSame($quality, $phone->evidence['quality']);
        $this->assertDatabaseHas('extracted_fields', ['form_document_id' => $document->id, 'field_key' => 'whatsapp_number', 'raw_text' => null, 'recognition_status' => 'blank']);
        $this->assertDatabaseCount('student_inquiries', 0);
        $this->actingAs(User::findOrFail($document->uploaded_by))->get('/?page=review&document='.$document->id)->assertOk()->assertSee('Student name is partially scribbled.');
    }

    public function test_approval_persists_separate_columns_and_audit_history(): void
    {
        $user = User::factory()->create();
        $doc = FormDocument::create(['uploaded_by' => $user->id, 'original_image_path' => 'private.jpg', 'upload_filename' => 'form.jpg', 'image_checksum' => str_repeat('b', 64), 'processing_status' => 'review']);
        $values = array_fill_keys(array_keys(config('ocr.fields')), null);
        $values['student_name'] = 'N. Perera';
        $values['contact_number'] = '0753964881';
        $values['whatsapp_number'] = '0771234567';
        $decisions = array_fill_keys(array_keys(config('ocr.fields')), 'accepted');
        $this->actingAs($user)->post('/documents/'.$doc->id.'/review', ['fields' => $values, 'decisions' => $decisions, 'action' => 'approve'])->assertRedirect('/?page=students');
        $this->assertDatabaseHas('student_inquiries', ['student_name' => 'N. Perera', 'contact_number' => '0753964881', 'whatsapp_number' => '0771234567', 'verification_status' => 'verified']);
        $this->assertDatabaseHas('audit_logs', ['form_document_id' => $doc->id, 'action' => 'approve']);
    }

    public function test_unresolved_fields_block_approval(): void
    {
        $u = User::factory()->create();
        $d = FormDocument::create(['uploaded_by' => $u->id, 'original_image_path' => 'x', 'upload_filename' => 'x.jpg', 'image_checksum' => str_repeat('c', 64), 'processing_status' => 'review']);
        $decisions = array_fill_keys(array_keys(config('ocr.fields')), 'blank');
        $decisions['contact_number'] = 'unreadable';
        $this->actingAs($u)->post('/documents/'.$d->id.'/review', ['action' => 'approve', 'fields' => [], 'decisions' => $decisions])->assertSessionHasErrors('review');
        $this->assertDatabaseCount('student_inquiries', 0);
    }
}
