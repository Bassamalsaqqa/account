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
        // 1. Add is_transit to warehouses
        Schema::table('warehouses', function (Blueprint $table): void {
            $table->boolean('is_transit')->default(false)->after('is_default');
            $table->index(['company_id', 'is_transit']);
        });

        // 2. Add in_transit_warehouse_id to company_purchase_settings
        Schema::table('company_purchase_settings', function (Blueprint $table): void {
            $table->foreignId('in_transit_warehouse_id')
                ->nullable()
                ->after('default_receiving_warehouse_id')
                ->constrained('warehouses')
                ->restrictOnDelete();
        });

        // 3. Add is_staged and idempotency tracking to purchases
        Schema::table('purchases', function (Blueprint $table): void {
            $table->boolean('is_staged')->default(false)->after('status');
            $table->string('idempotency_key', 255)->nullable()->after('is_staged');
            $table->char('request_hash', 64)->nullable()->after('idempotency_key');
            $table->json('response_snapshot')->nullable()->after('request_hash');

            $table->index(['company_id', 'is_staged']);
            $table->unique(['company_id', 'idempotency_key']);
        });

        // 4. Create goods_receipt_notes table
        Schema::create('goods_receipt_notes', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('purchase_id')->constrained('purchases')->restrictOnDelete();
            $table->string('grn_number', 64);
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->date('receipt_date');
            $table->string('status', 32)->default('draft');
            $table->foreignId('inventory_operation_id')->nullable()->constrained('inventory_operations')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->string('idempotency_key', 255);
            $table->char('request_hash', 64);
            $table->json('response_snapshot')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'grn_number']);
            $table->unique(['company_id', 'idempotency_key']);
            $table->index(['company_id', 'purchase_id']);
            $table->index(['company_id', 'receipt_date']);
        });

        // 5. Create goods_receipt_note_lines table
        Schema::create('goods_receipt_note_lines', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('goods_receipt_note_id')->constrained('goods_receipt_notes')->restrictOnDelete();
            $table->foreignId('purchase_line_id')->constrained('purchase_lines')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('product_unit_id')->constrained('product_units')->restrictOnDelete();
            $table->foreignId('lot_id')->nullable()->constrained('inventory_lots')->restrictOnDelete();
            $table->decimal('quantity', 18, 6);
            $table->decimal('quantity_base', 18, 6);
            $table->foreignId('transfer_out_movement_id')->nullable()->constrained('stock_movements')->restrictOnDelete();
            $table->foreignId('transfer_in_movement_id')->nullable()->constrained('stock_movements')->restrictOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'purchase_line_id']);
            $table->index(['goods_receipt_note_id']);
        });

        // 6. Add return origin tracking to purchase_returns
        Schema::table('purchase_returns', function (Blueprint $table): void {
            $table->foreignId('origin_warehouse_id')
                ->nullable()
                ->after('warehouse_id')
                ->constrained('warehouses')
                ->restrictOnDelete();
            $table->foreignId('origin_goods_receipt_note_id')
                ->nullable()
                ->after('origin_warehouse_id')
                ->constrained('goods_receipt_notes')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_returns', function (Blueprint $table): void {
            $table->dropForeign(['origin_goods_receipt_note_id']);
            $table->dropForeign(['origin_warehouse_id']);
            $table->dropColumn(['origin_goods_receipt_note_id', 'origin_warehouse_id']);
        });

        Schema::dropIfExists('goods_receipt_note_lines');
        Schema::dropIfExists('goods_receipt_notes');

        Schema::table('purchases', function (Blueprint $table): void {
            $table->dropUnique(['company_id', 'idempotency_key']);
            $table->dropIndex(['company_id', 'is_staged']);
            $table->dropColumn(['is_staged', 'idempotency_key', 'request_hash', 'response_snapshot']);
        });

        Schema::table('company_purchase_settings', function (Blueprint $table): void {
            $table->dropForeign(['in_transit_warehouse_id']);
            $table->dropColumn('in_transit_warehouse_id');
        });

        Schema::table('warehouses', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'is_transit']);
            $table->dropColumn('is_transit');
        });
    }
};
