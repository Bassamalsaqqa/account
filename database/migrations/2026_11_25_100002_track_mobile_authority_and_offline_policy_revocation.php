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
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('mobile_authority_revision')->default(0);
        });
        Schema::table('mobile_offline_sale_leases', function (Blueprint $table): void {
            $table->dateTime('revoked_at')->nullable();
        });

        // Legacy bearers cannot prove which MFA state existed when they were issued.
        DB::table('users')->where(function ($query): void {
            $query->whereIn('id', DB::table('mobile_access_tokens')->select('user_id'))
                ->orWhereNotNull('two_factor_secret')
                ->orWhereNotNull('two_factor_confirmed_at')
                ->orWhereNotNull('two_factor_recovery_codes');
        })->update(['mobile_authority_revision' => 1]);
        DB::table('mobile_offline_sale_leases')->update(['revoked_at' => DB::raw('UTC_TIMESTAMP()')]);

        // Fortify, mobile recovery and password rehashes all write the principal row.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER users_advance_mobile_authority BEFORE UPDATE ON users
            FOR EACH ROW
            SET NEW.mobile_authority_revision = OLD.mobile_authority_revision + IF(
                NOT (BINARY NEW.password <=> BINARY OLD.password)
                OR NOT (BINARY NEW.two_factor_secret <=> BINARY OLD.two_factor_secret)
                OR NOT (NEW.two_factor_confirmed_at <=> OLD.two_factor_confirmed_at)
                OR NOT (BINARY NEW.two_factor_recovery_codes <=> BINARY OLD.two_factor_recovery_codes),
                1, 0
            )
            SQL);

        // Policy writers and lease admission take the same setting row before touching leases.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER company_mobile_settings_revoke_leases_on_update
            AFTER UPDATE ON company_mobile_settings FOR EACH ROW
            BEGIN
                IF NOT (NEW.company_id <=> OLD.company_id)
                    OR NOT (NEW.offline_policy_enabled <=> OLD.offline_policy_enabled)
                    OR NOT (NEW.offline_cash_only <=> OLD.offline_cash_only)
                    OR NOT (NEW.max_offline_hours <=> OLD.max_offline_hours)
                    OR NOT (NEW.max_offline_sales_count <=> OLD.max_offline_sales_count)
                    OR NOT (NEW.max_offline_total_amount <=> OLD.max_offline_total_amount)
                    OR NOT (NEW.default_warehouse_id <=> OLD.default_warehouse_id)
                    OR NOT (NEW.approved_cash_account_id <=> OLD.approved_cash_account_id)
                    OR NOT (NEW.walk_in_customer_id <=> OLD.walk_in_customer_id)
                    OR NOT (NEW.default_tax_rate_id <=> OLD.default_tax_rate_id)
                THEN
                    UPDATE mobile_offline_sale_leases SET revoked_at = UTC_TIMESTAMP()
                    WHERE company_id = OLD.company_id AND revoked_at IS NULL;
                END IF;
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER company_mobile_settings_revoke_leases_on_delete
            AFTER DELETE ON company_mobile_settings FOR EACH ROW
            UPDATE mobile_offline_sale_leases SET revoked_at = UTC_TIMESTAMP()
            WHERE company_id = OLD.company_id AND revoked_at IS NULL
            SQL);
    }

    public function down(): void
    {
        if (DB::table('users')->where('mobile_authority_revision', '>', 0)->exists()
            || DB::table('mobile_offline_sale_leases')->exists()) {
            throw new RuntimeException('Mobile authority or issued lease history requires a forward correction.');
        }

        DB::unprepared('DROP TRIGGER IF EXISTS company_mobile_settings_revoke_leases_on_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS company_mobile_settings_revoke_leases_on_update');
        DB::unprepared('DROP TRIGGER IF EXISTS users_advance_mobile_authority');

        Schema::table('mobile_offline_sale_leases', function (Blueprint $table): void {
            $table->dropColumn('revoked_at');
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('mobile_authority_revision');
        });
    }
};
