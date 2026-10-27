<?php

namespace Tests\Feature;

use App\Models\FormDocument;
use App\Models\User;
use App\Services\FieldNormalizationService;
use App\Services\OcrEvaluationService;
use App\Services\SpreadsheetExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdministrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_reviewer_cannot_change_administrative_settings(): void
    {
        $this->actingAs(User::factory()->create())->post('/admin/settings', ['model' => 'gemini-2.5-flash', 'retention_days' => 30])->assertForbidden();
    }

    public function test_admin_screens_and_template_creation_work(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => 'admin'])->save();
        $this->actingAs($user)->get('/?page=settings')->assertOk()->assertSee('Add a team member');
        $this->get('/?page=templates')->assertRedirect(route('home'));
        $this->get('/')->assertOk()->assertDontSee('Form templates');
        $this->post('/admin/templates', ['name' => 'Known form', 'anchors' => json_encode(['contact_number' => [200, 50, 260, 450]])])->assertRedirect();
        $this->assertDatabaseHas('form_templates', ['name' => 'Known form']);
        $templateId = DB::table('form_templates')->where('name', 'Known form')->value('id');
        $this->get('/?page=templates&template=999999')->assertRedirect(route('home'));
        $this->get('/?page=upload&template='.$templateId)->assertOk()->assertSee('value="'.$templateId.'" selected', false);
        $this->get('/?page=upload')->assertOk()->assertSee('Known form');
    }

    public function test_only_admins_can_delete_unused_templates(): void
    {
        $templateId = DB::table('form_templates')->insertGetId(['name' => 'Reports', 'configuration' => '{}', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->delete(route('templates.destroy', $templateId))->assertRedirect('/login');
        $user = User::factory()->create();
        $this->actingAs($user)->delete(route('templates.destroy', $templateId))->assertForbidden();
        $this->assertDatabaseHas('form_templates', ['id' => $templateId]);
        $user->forceFill(['role' => 'admin'])->save();
        $this->delete(route('templates.destroy', $templateId))->assertRedirect(route('home', ['page' => 'templates']));
        $this->assertDatabaseMissing('form_templates', ['id' => $templateId]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'template_deleted', 'user_id' => $user->id]);
        $this->delete(route('templates.destroy', $templateId))->assertNotFound();
    }

    public function test_templates_used_by_documents_cannot_be_deleted(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => 'admin'])->save();
        $templateId = DB::table('form_templates')->insertGetId(['name' => 'Reports', 'configuration' => '{}', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $document = FormDocument::create(['uploaded_by' => $user->id, 'template_id' => $templateId, 'original_image_path' => 'private.jpg', 'upload_filename' => 'form.jpg', 'image_checksum' => str_repeat('a', 64)]);
        $this->actingAs($user)->delete(route('templates.destroy', $templateId))->assertSessionHasErrors('template');
        $this->assertDatabaseHas('form_templates', ['id' => $templateId]);
        $this->assertSame($templateId, $document->fresh()->template_id);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'template_deleted']);
    }

    public function test_excel_export_is_an_actual_workbook_with_string_cells(): void
    {
        $this->actingAs(User::factory()->create())->get('/export?format=xlsx')->assertOk()->assertDownload('student-inquiries.xlsx');
        $file = app(SpreadsheetExportService::class)->create([['Name', 'Phone'], ['A & B', '0753964881']]);
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($file));
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertStringContainsString('0753964881', $sheet);
        $this->assertStringContainsString('A &amp; B', $sheet);
        $this->assertNotFalse(simplexml_load_string($sheet));
        $zip->close();
        unlink($file);
    }

    public function test_normalization_never_invents_digits(): void
    {
        $n = app(FieldNormalizationService::class);
        $this->assertSame('0753964881', $n->normalize('contact_number', '075 396 4881')['value']);
        $this->assertNull($n->normalize('contact_number', '075396488?')['value']);
        $this->assertSame('2026-10-24', $n->normalize('inquiry_date', '24/10/2026')['value']);
        $this->assertNull($n->normalize('inquiry_date', '31/02/2026')['value']);
    }

    public function test_evaluation_detects_a_single_wrong_phone_digit(): void
    {
        $r = app(OcrEvaluationService::class)->evaluate(['contact_number' => '0753964881'], ['contact_number' => '0753964887']);
        $this->assertSame(0, $r['exact_match_rate']);
        $this->assertSame(.1, $r['fields']['contact_number']['character_error_rate']);
    }
}
