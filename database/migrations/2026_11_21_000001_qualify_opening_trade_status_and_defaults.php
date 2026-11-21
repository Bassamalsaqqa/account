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
        Schema::table('opening_trade_items', function (Blueprint $table): void {
            $table->string('status', 20)->default(null)->nullable(false)->change();
        });

        DB::statement("ALTER TABLE opening_trade_items ADD CONSTRAINT oti_status_valid CHECK (status IN ('posted', 'reversed'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('opening_trade_items')->exists()) {
            throw new RuntimeException('Cannot downgrade populated opening trade history. Retain this schema and use a forward correction.');
        }

        DB::statement('ALTER TABLE opening_trade_items DROP CONSTRAINT oti_status_valid');

        Schema::table('opening_trade_items', function (Blueprint $table): void {
            $table->string('status', 20)->default('posted')->change();
        });
    }
};
