<?php

declare(strict_types=1);

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
        Schema::table('native_command_receipts', function (Blueprint $table): void {
            $table->string('idempotency_key', 128)->collation('utf8mb4_bin')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('native_command_receipts', function (Blueprint $table): void {
            $table->string('idempotency_key', 128)->collation('ascii_bin')->change();
        });
    }
};
