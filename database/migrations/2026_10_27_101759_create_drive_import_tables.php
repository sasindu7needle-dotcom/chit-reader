<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('drive_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('uploaded_by')->constrained('users');
            $table->string('drive_id');
            $table->string('resource_key')->nullable();
            $table->string('kind');
            $table->text('page_token')->nullable();
            $table->string('status')->default('queued');
            $table->text('error_information')->nullable();
            $table->timestamps();
            $table->index(['uploaded_by', 'status']);
        });
        Schema::create('drive_import_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('drive_import_id')->constrained()->cascadeOnDelete();
            $table->string('drive_id');
            $table->string('resource_key')->nullable();
            $table->string('filename');
            $table->string('status')->default('queued');
            $table->foreignId('form_document_id')->nullable()->constrained()->nullOnDelete();
            $table->text('error_information')->nullable();
            $table->timestamps();
            $table->unique(['drive_import_id', 'drive_id']);
            $table->index(['drive_import_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('drive_import_items');
        Schema::dropIfExists('drive_imports');
    }
};
