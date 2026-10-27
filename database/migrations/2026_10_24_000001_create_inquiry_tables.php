<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_templates', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->json('configuration');
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::create('form_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('uploaded_by')->constrained('users');
            $t->foreignId('template_id')->nullable()->constrained('form_templates');
            $t->string('original_image_path');
            $t->string('processed_image_path')->nullable();
            $t->string('upload_filename');
            $t->string('image_checksum', 64)->index();
            $t->string('processing_status')->default('queued')->index();
            $t->text('error_information')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
        });
        Schema::create('extraction_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('form_document_id')->constrained()->cascadeOnDelete();
            $t->string('provider');
            $t->string('model_name');
            $t->string('prompt_version')->default('1');
            $t->string('processing_stage');
            $t->json('raw_response_json')->nullable();
            $t->string('request_status');
            $t->text('error_information')->nullable();
            $t->timestamps();
        });
        Schema::create('extracted_fields', function (Blueprint $t) {
            $t->id();
            $t->foreignId('form_document_id')->constrained()->cascadeOnDelete();
            $t->string('field_key');
            $t->text('raw_text')->nullable();
            $t->text('normalized_value')->nullable();
            $t->string('recognition_status');
            $t->boolean('review_required')->default(true);
            $t->string('reviewer_status')->nullable();
            $t->text('reviewed_value')->nullable();
            $t->json('evidence')->nullable();
            $t->timestamps();
            $t->unique(['form_document_id', 'field_key']);
        });
        Schema::create('student_inquiries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('form_document_id')->unique()->constrained()->cascadeOnDelete();
            foreach (['student_name', 'contact_number', 'whatsapp_number', 'email_address', 'home_address', 'school_name', 'nic_number', 'al_subject_stream', 'al_year', 'inquiry_date', 'teacher_name'] as $f) {
                $t->text($f)->nullable();
            }$t->string('verification_status')->default('draft')->index();
            $t->foreignId('verified_by')->nullable()->constrained('users');
            $t->timestamp('verified_at')->nullable();
            $t->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained();
            $t->foreignId('form_document_id')->nullable()->constrained()->nullOnDelete();
            $t->string('action');
            $t->json('changes')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'student_inquiries', 'extracted_fields', 'extraction_runs', 'form_documents', 'form_templates'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
