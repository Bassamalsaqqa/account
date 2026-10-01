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
        Schema::create('inventory_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('idempotency_key', 128);
            $table->string('operation_type', 32); // 'movement', 'transfer'
            $table->unsignedInteger('line_count');
            $table->char('request_hash', 64);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'idempotency_key'], 'unique_company_operation');
            $table->index(['company_id', 'operation_type']);
        });

        if (DB::table('stock_movements')->count() === 0) {
            Schema::table('stock_movements', function (Blueprint $table) {
                $table->foreignId('inventory_operation_id')
                    ->after('id')
                    ->constrained('inventory_operations')
                    ->restrictOnDelete();
            });
        } else {
            Schema::table('stock_movements', function (Blueprint $table) {
                $table->foreignId('inventory_operation_id')
                    ->nullable()
                    ->after('id');
            });

            $existingMovements = DB::table('stock_movements')->whereNull('inventory_operation_id')->get();
            foreach ($existingMovements as $mv) {
                $opId = DB::table('inventory_operations')->insertGetId([
                    'company_id' => $mv->company_id,
                    'idempotency_key' => $mv->idempotency_key ?? ('legacy-op-'.$mv->id),
                    'operation_type' => 'movement',
                    'line_count' => 1,
                    'request_hash' => hash('sha256', 'legacy-'.$mv->id),
                    'created_by' => $mv->created_by,
                    'created_at' => $mv->created_at ?? now(),
                    'updated_at' => $mv->created_at ?? now(),
                ]);
                DB::table('stock_movements')->where('id', $mv->id)->update(['inventory_operation_id' => $opId]);
            }

            Schema::table('stock_movements', function (Blueprint $table) {
                $table->unsignedBigInteger('inventory_operation_id')->nullable(false)->change();
                $table->foreign('inventory_operation_id')
                    ->references('id')
                    ->on('inventory_operations')
                    ->restrictOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropForeign(['inventory_operation_id']);
            $table->dropColumn('inventory_operation_id');
        });

        Schema::dropIfExists('inventory_operations');
    }
};
