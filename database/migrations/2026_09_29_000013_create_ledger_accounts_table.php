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
        Schema::create('ledger_accounts', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('code', 32);
            $table->string('system_key', 64)->nullable();
            $table->string('name_ar', 255);
            $table->string('name_en', 255)->nullable();
            $table->string('account_type', 32); // asset, liability, equity, revenue, expense
            $table->string('normal_balance', 8); // debit, credit
            $table->foreignId('parent_id')->nullable()->constrained('ledger_accounts')->nullOnDelete();
            $table->boolean('is_control')->default(false);
            $table->boolean('is_system')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'system_key']);
            $table->index(['company_id', 'account_type', 'active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ledger_accounts');
    }
};
