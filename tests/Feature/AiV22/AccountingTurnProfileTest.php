<?php

declare(strict_types=1);

namespace Tests\Feature\AiV22;

use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Application\AI\AssistantTurnService;
use App\Models\Customer;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Reproducible synthetic profile. Query traces never retain SQL bindings or request text. */
class AccountingTurnProfileTest extends AccountingTurnAcceptanceTest
{
    private bool $recording = false;

    /** @var list<array{sql:string,milliseconds:float}> */
    private array $profileQueries = [];

    public function test_profile_s_monthly_comparison_and_followup(): void
    {
        $this->profile('S', '800.000000', '450.000000');
    }

    public function test_profile_m_monthly_comparison_and_followup(): void
    {
        for ($index = 0; $index < 25; $index++) {
            Customer::create(['company_id' => $this->company->id, 'name_ar' => 'Synthetic '.$index,
                'name_en' => 'Synthetic '.$index, 'code' => 'PROFILE-'.$index,
                'preferred_locale' => 'en', 'created_by' => $this->owner->id]);
        }
        for ($index = 0; $index < 12; $index++) {
            $draft = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
                'customer_id' => $this->customer->id, 'currency_code' => 'ILS', 'exchange_rate' => '1',
                'issue_date' => $index < 6 ? '2026-10-12' : '2026-09-12',
                'lines' => [['item_description' => 'Synthetic profile item', 'quantity' => '1', 'unit_price' => '10']],
            ]);
            app(PostSalesInvoiceAction::class)->execute($draft, $this->owner);
        }
        $this->profile('M', '860.000000', '510.000000');
    }

    private function profile(string $size, string $expectedCurrent, string $expectedPrevious): void
    {
        $response = $this->mockDeepSeekResponse([['id' => 'profile', 'name' => 'run_report', 'arguments' => [
            'version' => 1, 'type' => 'report_query', 'report_key' => 'sales.summary',
            'filters' => ['preset' => 'this_month', 'compare' => 'previous_period'], 'display' => 'summary',
        ]]]);
        Http::fake(['*' => Http::response($response)]);
        $before = $this->economicFingerprint();
        $this->profileQueries = [];
        $this->recording = false;
        DB::listen(function (QueryExecuted $event): void {
            if (! $this->recording) {
                return;
            }
            // Only parameterized SQL is retained, never bindings, literals, names or IDs.
            $sql = preg_replace("/'(?:''|[^'])*'/", '?', $event->sql) ?? '';
            $sql = preg_replace('/\b\d+\b/', '?', $sql) ?? '';
            $sql = preg_replace('/\s+/', ' ', trim($sql)) ?? '';
            $this->profileQueries[] = ['sql' => $sql, 'milliseconds' => $event->time];
        });
        $service = app(AssistantTurnService::class);
        $this->recording = true;
        $start = hrtime(true);
        $comparison = $service->run($this->company, $this->owner, 'Compare sales this month with last month.',
            (string) Str::uuid(), 'synthetic-profile-'.$size);
        $elapsed = (hrtime(true) - $start) / 1000000;
        $this->recording = false;
        $this->assertSame('answer', $comparison['status']);
        $this->assertSame(1, $comparison['model_calls']);
        $this->assertSame($expectedCurrent, $comparison['reports'][0]['totals']['gross_sales_base']);
        $this->assertSame($expectedPrevious, $comparison['reports'][0]['comparison']['totals']['gross_sales_base']);
        $this->writeProfile($size, 'comparison', $this->profileQueries, $elapsed, $comparison['reports']);

        $this->profileQueries = [];
        $this->recording = true;
        $start = hrtime(true);
        $followup = $service->run($this->company, $this->owner, 'and the month before',
            (string) Str::uuid(), 'synthetic-profile-'.$size, $comparison['conversation_token']);
        $elapsed = (hrtime(true) - $start) / 1000000;
        $this->recording = false;
        $this->assertSame('answer', $followup['status']);
        $this->assertSame(0, $followup['model_calls']);
        $this->assertSame('2026-08-01', $followup['reports'][0]['period']['start_date']);
        $this->assertSame('200.000000', $followup['reports'][0]['totals']['gross_sales_base']);
        $this->writeProfile($size, 'followup', $this->profileQueries, $elapsed, $followup['reports']);
        Http::assertSentCount(1);
        $this->assertSame($before, $this->economicFingerprint());
    }

    /** @return array<string,string> */
    private function economicFingerprint(): array
    {
        $fingerprints = [];
        foreach (['sales_invoices', 'sales_invoice_lines', 'customer_payments', 'posting_batches', 'posting_lines', 'stock_movements'] as $table) {
            $fingerprints[$table] = hash('sha256', DB::table($table)->orderBy('id')->get()->toJson());
        }

        return $fingerprints;
    }

    /**
     * @param  list<array{sql:string,milliseconds:float}>  $queries
     * @param  list<array<string,mixed>>  $reports
     */
    private function writeProfile(string $size, string $operation, array $queries, float $elapsed, array $reports): void
    {
        $path = getenv('AI_V22_PROFILE_PATH');
        if (! is_string($path) || $path === '') {
            return;
        }
        $aggregate = [];
        foreach ($queries as $query) {
            $sql = $query['sql'];
            $aggregate[$sql] ??= ['count' => 0, 'milliseconds' => 0.0];
            $aggregate[$sql]['count']++;
            $aggregate[$sql]['milliseconds'] += $query['milliseconds'];
        }
        uasort($aggregate, static fn (array $left, array $right): int => $right['count'] <=> $left['count']);
        $row = ['size' => $size, 'operation' => $operation, 'query_count' => count($queries),
            'elapsed_ms' => $elapsed, 'sql_ms' => array_sum(array_column($queries, 'milliseconds')),
            'reports_fingerprint' => hash('sha256', json_encode(array_map(static fn (array $report): array => [$report['report_key'], $report['period'], $report['currency'], $report['totals'], $report['comparison']['period'] ?? null, $report['comparison']['totals'] ?? null], $reports), JSON_THROW_ON_ERROR)),
            'current_total' => $reports[0]['totals']['gross_sales_base'],
            'comparison_total' => $reports[0]['comparison']['totals']['gross_sales_base'] ?? null,
            'statements' => $aggregate];
        file_put_contents($path, json_encode($row, JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
