<?php

declare(strict_types=1);

use App\Services\Phase7\Phase7MigrationSafety;
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
        Schema::create('landed_cost_allocations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('expense_id')->constrained('expenses')->restrictOnDelete();
            $table->foreignId('purchase_id')->constrained('purchases')->restrictOnDelete();
            $table->foreignId('purchase_line_id')->constrained('purchase_lines')->restrictOnDelete();

            $table->string('allocation_method', 16); // value, quantity, manual
            $table->decimal('allocated_base', 20, 6);
            $table->string('status', 16)->default('draft'); // draft, locked, cancelled

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->index(['company_id', 'purchase_id', 'status'], 'idx_landed_alloc_purchase_status');
            $table->index(['company_id', 'expense_id', 'status'], 'idx_landed_alloc_expense_status');
        });

        Schema::table('purchase_lines', function (Blueprint $table): void {
            $table->decimal('landed_cost_allocated_base', 20, 6)->default(0)->after('inventory_unit_cost_base');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Phase7MigrationSafety::assertEmpty();
        if (Schema::hasTable('landed_cost_allocations') && DB::table('landed_cost_allocations')->exists()) {
            throw new RuntimeException('Cannot rollback Phase 7 migrations: landed cost allocations exist.');
        }

        if (Schema::hasColumn('purchase_lines', 'landed_cost_allocated_base')) {
            $hasAllocatedLanded = DB::table('purchase_lines')->where('landed_cost_allocated_base', '>', 0)->exists();
            if ($hasAllocatedLanded) {
                throw new RuntimeException('Cannot rollback Phase 7 migrations: purchase lines with capitalized landed cost exist.');
            }

            Schema::table('purchase_lines', function (Blueprint $table): void {
                $table->dropColumn('landed_cost_allocated_base');
            });
        }

        Schema::dropIfExists('landed_cost_allocations');
    }
};
