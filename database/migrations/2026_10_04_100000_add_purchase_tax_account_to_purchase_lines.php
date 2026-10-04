<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_lines', function (Blueprint $table): void {
            $table->foreignId('purchase_tax_account_id')->nullable()->constrained('ledger_accounts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_lines', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('purchase_tax_account_id');
        });
    }
};
