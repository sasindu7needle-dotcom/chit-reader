<?php

namespace Tests\Feature;

use App\Models\FormDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewFeedbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_unresolved_fields_have_specific_errors_and_preserve_edits(): void
    {
        $user = User::factory()->create();
        $document = $this->reviewDocument($user);
        $decisions = array_fill_keys(array_keys(config('ocr.fields')), 'blank');
        $decisions['al_subject_stream'] = 'unreadable';
        $decisions['al_year'] = 'unreadable';
        $fields = ['student_name' => 'Corrected student', 'al_subject_stream' => 'Commerce', 'al_year' => '2028'];

        $response = $this->actingAs($user)->from('/?page=review&document='.$document->id)->post('/documents/'.$document->id.'/review', ['action' => 'approve', 'fields' => $fields, 'decisions' => $decisions]);

        $response->assertSessionHasErrors([
            'decisions.al_subject_stream' => 'Compare this value with the original, then choose Matches original or Blank on original.',
            'decisions.al_year' => 'Compare this value with the original, then choose Matches original or Blank on original.',
        ])->assertSessionHasInput('fields.student_name', 'Corrected student')->assertSessionHasInput('decisions.al_year', 'unreadable');
        $this->assertDatabaseCount('student_inquiries', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseHas('form_documents', ['id' => $document->id, 'processing_status' => 'review']);
    }

    public function test_review_shows_format_issues_beside_fields_before_approval(): void
    {
        $user = User::factory()->create();
        $document = $this->reviewDocument($user);
        $document->fields()->create(['field_key' => 'nic_number', 'raw_text' => '123', 'recognition_status' => 'clear']);
        $document->fields()->create(['field_key' => 'inquiry_date', 'raw_text' => '2026.10.24', 'recognition_status' => 'clear']);
        $document->fields()->create(['field_key' => 'al_year', 'raw_text' => '2028', 'recognition_status' => 'conflicting']);

        $response = $this->actingAs($user)->get('/?page=review&document='.$document->id);

        $response->assertOk()->assertSee('NIC format needs review.')->assertSee('24/10/2026')->assertSee('Check this field')->assertSee('Matches original')->assertSee('id="issue-nic_number"', false)->assertDontSee('>conflicting<', false);
    }

    public function test_confirmed_values_can_be_approved_after_resolving_a_conflict(): void
    {
        $user = User::factory()->create();
        $document = $this->reviewDocument($user);
        $document->fields()->create(['field_key' => 'al_subject_stream', 'raw_text' => 'Commerce', 'recognition_status' => 'conflicting']);
        $decisions = array_fill_keys(array_keys(config('ocr.fields')), 'blank');
        $decisions['al_subject_stream'] = 'accepted';

        $response = $this->actingAs($user)->post('/documents/'.$document->id.'/review', ['action' => 'approve', 'fields' => ['al_subject_stream' => 'Commerce'], 'decisions' => $decisions]);

        $response->assertRedirect('/?page=students')->assertSessionHasNoErrors();
        $this->assertDatabaseHas('student_inquiries', ['form_document_id' => $document->id, 'al_subject_stream' => 'Commerce', 'verification_status' => 'verified']);
        $this->assertDatabaseHas('extracted_fields', ['form_document_id' => $document->id, 'field_key' => 'al_subject_stream', 'reviewer_status' => 'accepted']);
    }

    private function reviewDocument(User $user): FormDocument
    {
        return FormDocument::create(['uploaded_by' => $user->id, 'original_image_path' => 'private.jpg', 'upload_filename' => 'form.jpg', 'image_checksum' => str_repeat('a', 64), 'processing_status' => 'review']);
    }
}
