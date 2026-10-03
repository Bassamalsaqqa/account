<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_line_lots', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_line_id')->constrained()->restrictOnDelete();
            $table->string('lot_number', 128)->nullable();
            $table->date('expiry_date')->nullable();
            $table->decimal('quantity', 20, 6);
            $table->decimal('quantity_base', 20, 6);
            $table->foreignId('created_inventory_lot_id')->nullable()->constrained('inventory_lots')->restrictOnDelete();
            $table->foreignId('stock_movement_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'purchase_line_id']);
            $table->index(['company_id', 'expiry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_line_lots');
    }
};
