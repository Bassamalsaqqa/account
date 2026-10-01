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
        Schema::create('inventory_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('idempotency_key', 128);
            $table->string('operation_type', 32); // 'movement', 'transfer'
            $table->unsignedInteger('line_count');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->unique(['company_id', 'idempotency_key'], 'unique_company_operation');
            $table->index(['company_id', 'operation_type']);
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('inventory_operation_id')
                ->nullable()
                ->after('id')
                ->constrained('inventory_operations')
                ->nullOnDelete();
        });
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
