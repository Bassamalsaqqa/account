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
        Schema::create('mobile_offline_sale_leases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('mobile_device_id')->constrained()->restrictOnDelete();
            $table->char('authority_fingerprint', 64);
            $table->char('policy_fingerprint', 64);
            $table->char('catalog_snapshot_version', 64);
            $table->dateTime('issued_at');
            $table->dateTime('expires_at');
            $table->unsignedInteger('posted_sales_count')->default(0);
            $table->decimal('posted_total_amount', 20, 6)->default('0');
            $table->index(['company_id', 'actor_id', 'mobile_device_id', 'expires_at'], 'mobile_sale_lease_scope_expiry');
        });
    }

    public function down(): void
    {
        if (DB::table('mobile_offline_sale_leases')->exists()) {
            throw new RuntimeException('Issued offline leases require a forward correction.');
        }

        Schema::dropIfExists('mobile_offline_sale_leases');
    }
};
