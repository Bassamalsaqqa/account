<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Legacy devices deliberately remain unowned and cannot authenticate/re-enrol.
        Schema::table('mobile_devices', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
        });
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'barcode']);
            $table->dropForeign(['tax_rate_id']);
            $table->dropColumn(['tax_rate_id', 'barcode']);
        });
        Schema::table('company_mobile_settings', function (Blueprint $table): void {
            $table->unsignedInteger('max_offline_hours')->nullable()->default(null)->change();
            $table->unsignedInteger('max_offline_sales_count')->nullable()->default(null)->change();
            $table->decimal('max_offline_total_amount', 20, 6)->nullable()->default(null)->change();
            $table->string('catalog_snapshot_version', 64)->nullable()->default(null)->change();
        });
        // Prior defaults are not evidence of policy approval.
        DB::table('company_mobile_settings')->update([
            'offline_policy_enabled' => false, 'max_offline_hours' => null,
            'max_offline_sales_count' => null, 'max_offline_total_amount' => null,
            'catalog_snapshot_version' => null,
        ]);
    }

    public function down(): void
    {
        Schema::table('mobile_devices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('user_id');
        });
        Schema::table('products', function (Blueprint $table): void {
            $table->foreignId('tax_rate_id')->nullable()->constrained('tax_rates')->nullOnDelete();
            $table->string('barcode', 64)->nullable();
            $table->index(['company_id', 'barcode']);
        });
        // Do not restore invented policy values on rollback.
    }
};
