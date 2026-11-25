<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    private const array COLUMNS = [
        ['users', 'mobile_authority_revision', 'BIGINT UNSIGNED NOT NULL DEFAULT 0'],
        ['mobile_offline_sale_leases', 'revoked_at', 'DATETIME NULL DEFAULT NULL'],
    ];

    private const array TRIGGERS = [
        // Fortify, mobile recovery and password rehashes all write the principal row.
        <<<'SQL'
            CREATE TRIGGER users_advance_mobile_authority BEFORE UPDATE ON users
            FOR EACH ROW
            SET NEW.mobile_authority_revision = OLD.mobile_authority_revision + IF(
                NOT (BINARY NEW.password <=> BINARY OLD.password)
                OR NOT (BINARY NEW.two_factor_secret <=> BINARY OLD.two_factor_secret)
                OR NOT (NEW.two_factor_confirmed_at <=> OLD.two_factor_confirmed_at)
                OR NOT (BINARY NEW.two_factor_recovery_codes <=> BINARY OLD.two_factor_recovery_codes),
                1, 0
            )
            SQL,

        // Policy writers and lease admission take the same setting row before touching leases.
        <<<'SQL'
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
            SQL,

        <<<'SQL'
            CREATE TRIGGER company_mobile_settings_revoke_leases_on_delete
            AFTER DELETE ON company_mobile_settings FOR EACH ROW
            UPDATE mobile_offline_sale_leases SET revoked_at = UTC_TIMESTAMP()
            WHERE company_id = OLD.company_id AND revoked_at IS NULL
            SQL,
    ];

    public function up(): void
    {
        [$columns, $triggers] = $this->installedSteps();

        foreach (self::COLUMNS as $index => [$table, $column, $definition]) {
            if (! $columns[$index]) {
                DB::statement("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
            }
        }

        // The first trigger is only created after both legacy invalidations commit.
        // If interrupted before it exists, these predicates preserve the original retirement.
        if (! $triggers[0]) {
            DB::transaction(function (): void {
                // Legacy bearers cannot prove which MFA state existed when they were issued.
                DB::table('users')->where('mobile_authority_revision', 0)->where(function ($query): void {
                    $query->whereIn('id', DB::table('mobile_access_tokens')->select('user_id'))
                        ->orWhereNotNull('two_factor_secret')
                        ->orWhereNotNull('two_factor_confirmed_at')
                        ->orWhereNotNull('two_factor_recovery_codes');
                })->update(['mobile_authority_revision' => 1]);
                DB::table('mobile_offline_sale_leases')->whereNull('revoked_at')->update(['revoked_at' => DB::raw('UTC_TIMESTAMP()')]);
            });
        }

        foreach (self::TRIGGERS as $index => $sql) {
            if (! $triggers[$index]) {
                DB::unprepared($sql);
            }
        }

        [, $triggers] = $this->installedSteps();
        if (in_array(false, $triggers, true)) {
            throw new RuntimeException('Mobile authority trigger installation is incomplete.');
        }
    }

    public function down(): void
    {
        [$columns, $triggers] = $this->installedSteps();
        if (($columns[0] && DB::table('users')->where('mobile_authority_revision', '>', 0)->exists())
            || DB::table('mobile_offline_sale_leases')->exists()) {
            throw new RuntimeException('Mobile authority or issued lease history requires a forward correction.');
        }

        foreach (array_reverse(self::TRIGGERS, true) as $index => $sql) {
            if ($triggers[$index]) {
                [$name] = $this->triggerDefinition($sql);
                DB::unprepared("DROP TRIGGER `{$name}`");
            }
        }

        foreach (array_reverse(self::COLUMNS, true) as $index => [$table, $column]) {
            if ($columns[$index]) {
                DB::statement("ALTER TABLE `{$table}` DROP COLUMN `{$column}`");
            }
        }
    }

    /** @return array{list<bool>, list<bool>} */
    private function installedSteps(): array
    {
        $columns = [];
        foreach (self::COLUMNS as $index => [$table, $column]) {
            $actual = DB::selectOne(
                'SELECT COLUMN_TYPE AS type, IS_NULLABLE AS nullable, COLUMN_DEFAULT AS default_value, EXTRA AS extra
                FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$table, $column],
                false,
            );
            if ($actual !== null) {
                // MariaDB reports a display width for BIGINT and may report nullable DEFAULT NULL as text.
                $matches = $index === 0
                    ? preg_match('/^bigint(?:\(20\))? unsigned$/D', $actual->type) === 1
                        && $actual->nullable === 'NO' && (string) $actual->default_value === '0'
                    : $actual->type === 'datetime' && $actual->nullable === 'YES'
                        && in_array($actual->default_value, [null, 'NULL'], true);
                if (! $matches || $actual->extra !== '') {
                    throw new RuntimeException("Unexpected definition for {$table}.{$column}.");
                }
            }
            $columns[] = $actual !== null;
        }

        $triggers = [];
        foreach (self::TRIGGERS as $sql) {
            [$name, $timing, $event, $table, $body] = $this->triggerDefinition($sql);
            $actual = DB::selectOne(
                'SELECT EVENT_OBJECT_TABLE AS target_table, ACTION_TIMING AS timing, EVENT_MANIPULATION AS trigger_event,
                        ACTION_ORIENTATION AS orientation, ACTION_STATEMENT AS body, DEFINER AS definer,
                        CURRENT_USER() AS migration_account
                FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?',
                [$name],
                false,
            );
            if ($actual !== null && ($actual->target_table !== $table || $actual->timing !== $timing
                || $actual->trigger_event !== $event || $actual->orientation !== 'ROW'
                || $actual->definer !== $actual->migration_account || $this->normalize($actual->body) !== $body)) {
                throw new RuntimeException("Unexpected definition or definer for trigger {$name}.");
            }
            $triggers[] = $actual !== null;
        }

        $steps = [...$columns, ...$triggers];
        $firstMissing = array_search(false, $steps, true);
        if ($firstMissing !== false && in_array(true, array_slice($steps, $firstMissing + 1), true)) {
            throw new RuntimeException('Mobile authority schema steps are installed out of order.');
        }

        return [$columns, $triggers];
    }

    /** @return array{string, string, string, string, string} */
    private function triggerDefinition(string $sql): array
    {
        if (preg_match('/^CREATE TRIGGER (\w+) (BEFORE|AFTER) (UPDATE|DELETE) ON (\w+) FOR EACH ROW (.+)$/D', $this->normalize($sql), $parts) !== 1) {
            throw new LogicException('Invalid mobile authority trigger declaration.');
        }

        return [$parts[1], $parts[2], $parts[3], $parts[4], $parts[5]];
    }

    private function normalize(string $sql): string
    {
        return (string) preg_replace('/\s+/', ' ', trim($sql));
    }
};
