<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
        $unicodeRowsExist = DB::table('native_command_receipts')
            ->whereNotNull('idempotency_key')
            ->whereRaw('LENGTH(idempotency_key) != CHAR_LENGTH(idempotency_key)')
            ->exists();

        if ($unicodeRowsExist) {
            throw new RuntimeException('Cannot downgrade native_command_receipts idempotency_key collation to ascii_bin: table contains non-ASCII Unicode idempotency keys. Under the forward-schema rollback policy (docs/CONTROLLED_RELEASE_CHECKLIST.md:36), retain the forward schema on rollback.');
        }

        Schema::table('native_command_receipts', function (Blueprint $table): void {
            $table->string('idempotency_key', 128)->collation('ascii_bin')->change();
        });
    }
};
