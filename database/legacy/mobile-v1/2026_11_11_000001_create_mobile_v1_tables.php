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
        Schema::create('mobile_devices', function (Blueprint $table): void {
            $table->id();
            $table->char('device_id', 36)->unique();
            $table->string('device_name', 100);
            $table->string('platform', 16)->nullable();
            $table->string('app_version', 64)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 255)->nullable();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('revoked_at');
        });

        Schema::create('mobile_auth_challenges', function (Blueprint $table): void {
            $table->id();
            $table->char('challenge_id', 36)->unique();
            $table->char('device_id', 36);
            $table->char('nonce_hash', 64);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('app_version', 64)->nullable();
            $table->string('platform', 16)->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['device_id', 'consumed_at', 'expires_at']);
        });

        Schema::create('mobile_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->char('token_hash', 64)->unique();
            $table->char('device_id', 36);
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $table->string('password_epoch', 64);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['device_id', 'revoked_at']);
            $table->index(['user_id', 'company_id', 'revoked_at']);
            $table->index('expires_at');
        });

        Schema::create('company_mobile_settings', function (Blueprint $table): void {
            $table->foreignId('company_id')->primary()->constrained('companies')->cascadeOnDelete();
            $table->foreignId('walk_in_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('approved_cash_account_id')->nullable()->constrained('money_accounts')->nullOnDelete();
            $table->foreignId('default_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->foreignId('default_tax_rate_id')->nullable()->constrained('tax_rates')->nullOnDelete();
            $table->boolean('offline_policy_enabled')->default(false);
            $table->boolean('offline_cash_only')->default(true);
            $table->unsignedInteger('max_offline_hours')->default(12);
            $table->unsignedInteger('max_offline_sales_count')->default(50);
            $table->decimal('max_offline_total_amount', 20, 6)->default(5000);
            $table->string('catalog_snapshot_version', 64)->default('snap-20261110-v1');
            $table->timestamps();
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->foreignId('tax_rate_id')->nullable()->after('default_sale_price_base')->constrained('tax_rates')->nullOnDelete();
            $table->string('barcode', 64)->nullable()->after('sku');
            $table->index(['company_id', 'barcode']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'barcode']);
            $table->dropForeign(['tax_rate_id']);
            $table->dropColumn(['tax_rate_id', 'barcode']);
        });

        Schema::dropIfExists('company_mobile_settings');
        Schema::dropIfExists('mobile_access_tokens');
        Schema::dropIfExists('mobile_auth_challenges');
        Schema::dropIfExists('mobile_devices');
    }
};
