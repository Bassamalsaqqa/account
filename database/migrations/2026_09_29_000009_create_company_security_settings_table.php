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
        Schema::create('company_security_settings', function (Blueprint $table) {
            $table->foreignId('company_id')->primary()->constrained('companies')->cascadeOnDelete();
            $table->boolean('require_2fa_for_owner')->default(true);
            $table->boolean('require_2fa_for_admin')->default(false);
            $table->unsignedInteger('public_share_default_expiry_days')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_security_settings');
    }
};
