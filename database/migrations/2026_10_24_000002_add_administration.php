<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('role')->default('reviewer');
        });
        Schema::create('workspace_settings', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->text('value')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_settings');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('role'));
    }
};
