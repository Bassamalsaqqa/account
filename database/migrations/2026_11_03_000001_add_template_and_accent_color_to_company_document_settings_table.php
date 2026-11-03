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
        Schema::table('company_document_settings', function (Blueprint $table) {
            $table->string('template', 32)->default('classic')->after('default_document_locale');
            $table->string('accent_color', 16)->default('#255fd6')->after('template');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Prevent accidental destruction of customized tenant settings
        $hasCustomSettings = DB::table('company_document_settings')
            ->where('template', '!=', 'classic')
            ->orWhere('accent_color', '!=', '#255fd6')
            ->exists();

        if ($hasCustomSettings) {
            throw new RuntimeException('Cannot rollback migration: company_document_settings contains customized template or accent color configurations. Back up or reset settings before rolling back.');
        }

        Schema::table('company_document_settings', function (Blueprint $table) {
            $table->dropColumn(['template', 'accent_color']);
        });
    }
};
