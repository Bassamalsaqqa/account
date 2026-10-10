<?php

declare(strict_types=1);

namespace Tests\Feature\Phase10;

use App\Actions\Sales\ApplyCustomerPaymentCreditAction;
use App\Actions\Sales\CreateMoneyAccountAction;
use App\Actions\Sales\PostCustomerPaymentAction;
use App\Actions\Sales\ReverseCustomerPaymentAction;
use App\Actions\Sales\VoidSalesInvoiceAction;
use App\Domain\Sales\Queries\CustomerStatementQuery;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\MoneyAccount;
use App\Services\Sales\PdfRendererService;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SalesCorrectionFixtures;
use Tests\TestCase;

/** Authenticated outputs only; public issued Statements reuse Phase 9 sharing regressions. */
final class CustomerStatementCertificationTest extends TestCase
{
    use RefreshDatabase;
    use SalesCorrectionFixtures;

    private CapturingCustomerStatementQuery $reader;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-01 12:00:00 UTC');
        $this->salesFixtures();
        $this->company->update(['timezone' => 'Asia/Hebron']);
        $this->reader = new CapturingCustomerStatementQuery;
        $this->app->instance(CustomerStatementQuery::class, $this->reader);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return list<array{string, string}> */
    public static function outputs(): array
    {
        return [['ar', 'print'], ['en', 'print'], ['ar', 'pdf'], ['en', 'pdf']];
    }

    #[DataProvider('outputs')]
    public function test_authenticated_outputs_keep_independent_historical_currency_figures(string $locale, string $format): void
    {
        $this->history();
        $before = $this->economicFingerprint();
        $response = $this->get($this->url($locale, $format, '2026-10-01', '2026-10-03'));
        $response->assertOk();
        $this->assertSame(1, $this->reader->calls);
        $this->assertFigures([
            'ILS' => ['100.00', '55.00', '15.00', '140.00'],
            'USD' => ['20.00', '10.00', '10.00', '20.00'],
            'JOD' => ['3.125', '1.250', '0.125', '4.250'],
        ]);
        $this->assertSame('2026-10-01', $this->reader->last['from_date']);
        $this->assertSame('2026-10-03', $this->reader->last['to_date']);
        foreach ($this->reader->last['currencies'] as $group) {
            foreach ($group['entries'] as $entry) {
                $this->assertGreaterThanOrEqual('2026-10-01', $entry['date']);
                $this->assertLessThanOrEqual('2026-10-03', $entry['date']);
            }
        }
        $this->assertSame(['invoice', 'payment', 'currency_allocation'], array_column($this->reader->last['currencies']['ILS']['entries'], 'type'));
        $this->assertSame(['invoice', 'payment', 'currency_allocation'], array_column($this->reader->last['currencies']['USD']['entries'], 'type'));

        if ($format === 'print') {
            $response->assertSee('dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'"', false);
            foreach (['100.00 ILS', '140.00 ILS', '20.00 USD', '3.125 JOD', '4.250 JOD'] as $figure) {
                $response->assertSee($figure, false);
            }
            $response->assertDontSee('999.00', false);
        } else {
            $response->assertHeader('Content-Type', 'application/pdf');
            $this->assertStringStartsWith('%PDF-', $response->getContent());
            $this->assertGreaterThan(1000, strlen($response->getContent()));
            // Actual renderer artifacts for separate raster/content QA; no operational data.
            $directory = base_path('.ai/delegations/phase10-statement-certification');
            File::ensureDirectoryExists($directory);
            File::put($directory.'/customer-statement-'.$locale.'.pdf', $response->getContent());
        }
        $this->assertSame($before, $this->economicFingerprint());
    }

    public function test_equal_date_window_and_later_reversal_void_cutoffs_are_inclusive(): void
    {
        $this->history();
        foreach (['ar', 'en'] as $locale) {
            // At 22:30 UTC the Company is already on Oct 21; supplied business dates stay Oct 3.
            Carbon::setTestNow('2026-10-20 22:30:00 UTC');
            $this->assertSame('2026-10-21', now($this->company->timezone)->toDateString());
            $this->get($this->url($locale, 'print', '2026-10-03', '2026-10-03'))->assertOk();
            $this->assertFigures([
                'ILS' => ['125.00', '15.00', '0.00', '140.00'],
                'USD' => ['25.00', '0.00', '5.00', '20.00'],
                'JOD' => ['4.250', '0.000', '0.000', '4.250'],
            ]);
            foreach ($this->reader->last['currencies'] as $group) {
                foreach ($group['entries'] as $entry) {
                    $this->assertSame('2026-10-03', $entry['date']);
                }
            }

            $this->get($this->url($locale, 'print', '2026-10-01', '2026-10-02'))->assertOk();
            $this->assertFigures([
                'ILS' => ['100.00', '40.00', '15.00', '125.00'],
                'USD' => ['20.00', '10.00', '5.00', '25.00'],
                'JOD' => ['3.125', '1.250', '0.125', '4.250'],
            ]);
            // Receipt/application reversal on Oct 15 is absent on Oct 14 and present on Oct 15.
            $this->get($this->url($locale, 'print', '2026-10-01', '2026-10-14'))->assertOk();
            $this->assertSame('20.00', $this->reader->last['currencies']['USD']['closing_balance']);
            $this->get($this->url($locale, 'print', '2026-10-01', '2026-10-15'))->assertOk();
            $this->assertSame('25.00', $this->reader->last['currencies']['USD']['closing_balance']);
            $this->assertContains('payment_reversal', array_column($this->reader->last['currencies']['ILS']['entries'], 'type'));
            // Canonical void on Oct 20 removes the 40 ILS invoice exactly at that cutoff.
            $this->get($this->url($locale, 'print', '2026-10-01', '2026-10-19'))->assertOk();
            $beforeVoid = $this->reader->last['currencies']['ILS']['closing_balance'];
            $this->get($this->url($locale, 'print', '2026-10-01', '2026-10-20'))->assertOk();
            $this->assertSame('1139.00', $beforeVoid);
            $this->assertSame('1099.00', $this->reader->last['currencies']['ILS']['closing_balance']);
        }
    }

    public function test_invalid_and_inverted_dates_are_rejected_before_statement_hydration(): void
    {
        foreach (['ar', 'en'] as $locale) {
            foreach ([['2026-10-04', '2026-10-03'], ['2026-02-30', '2026-10-03'], ['bad', '2026-10-03']] as [$from, $to]) {
                $this->getJson($this->url($locale, 'print', $from, $to))->assertUnprocessable();
            }
        }
        $this->assertSame(0, $this->reader->calls);
    }

    #[DataProvider('outputs')]
    public function test_real_source_guard_refuses_counter_seam_1001_before_hydration_or_rendering(string $locale, string $format): void
    {
        // Counter seam only: no claim to have constructed 1,001 canonical economic events.
        // Execute the actual final DocumentRenderLimits service and route ordering unchanged.
        $manager = DB::getFacadeRoot();
        $counter = Mockery::mock(Builder::class);
        $counter->shouldReceive('where')->with('company_id', (int) $this->company->id)->once()->andReturnSelf();
        $counter->shouldReceive('where')->with('customer_id', (int) $this->customer->id)->once()->andReturnSelf();
        $counter->shouldReceive('count')->once()->andReturn(1001);
        $database = Mockery::mock($manager);
        $database->shouldReceive('table')->andReturnUsing(static fn (string $table) => $table === 'sales_invoices' ? $counter : $manager->connection()->table($table));
        DB::swap($database);
        $renderer = Mockery::mock(PdfRendererService::class);
        $renderer->shouldNotReceive('renderDocument');
        $this->app->instance(PdfRendererService::class, $renderer);
        $this->withoutExceptionHandling();
        $before = $this->economicFingerprint();
        try {
            // A narrow date filter must not bypass the all-source count.
            $this->get($this->url($locale, $format, '2026-10-03', '2026-10-03'));
            $this->fail('Over-limit source reached Statement hydration/rendering.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Statement history exceeds the supported source limit.', $exception->getMessage());
        } finally {
            DB::swap($manager);
        }
        $this->assertSame(0, $this->reader->calls);
        $this->assertSame($before, $this->economicFingerprint());
    }

    private function history(): void
    {
        $openingUsd = $this->invoice('20', '3.5', 'USD', ['issue_date' => '2026-09-30']);
        $this->invoice('100', '1', 'ILS', ['issue_date' => '2026-09-30']);
        $this->invoice('3.125', '5', 'JOD', ['issue_date' => '2026-09-30']);
        $voidLater = $this->invoice('40', '1', 'ILS', ['issue_date' => '2026-10-01']);
        $this->invoice('10', '3.5', 'USD', ['issue_date' => '2026-10-01']);
        $this->invoice('1.250', '5', 'JOD', ['issue_date' => '2026-10-01']);
        $ils = app(CreateMoneyAccountAction::class)->execute($this->company, $this->owner, ['account_type' => 'cash', 'currency_code' => 'ILS', 'name_ar' => 'ILS']);
        $jod = app(CreateMoneyAccountAction::class)->execute($this->company, $this->owner, ['account_type' => 'cash', 'currency_code' => 'JOD', 'name_ar' => 'JOD']);
        $advance = $this->payment($ils, '15', '1', 'ils');
        $this->payment($this->cash, '5', '3.5', 'usd');
        $this->payment($jod, '0.125', '5', 'jod');
        Carbon::setTestNow('2026-10-03 12:00:00 UTC');
        app(ApplyCustomerPaymentCreditAction::class)->execute($advance, $this->owner, [
            'application_date' => '2026-10-03', 'idempotency_key' => 'certification-apply',
            'allocations' => [['sales_invoice_id' => $openingUsd->id, 'allocated_amount' => '5', 'payment_currency_amount' => '15']],
        ]);
        $this->invoice('999', '1', 'ILS', ['issue_date' => '2026-10-04']);
        Carbon::setTestNow('2026-10-15 12:00:00 UTC');
        app(ReverseCustomerPaymentAction::class)->execute($advance, $this->owner, 'Synthetic certification reversal', '2026-10-15');
        Carbon::setTestNow('2026-10-20 12:00:00 UTC');
        app(VoidSalesInvoiceAction::class)->execute($voidLater, $this->owner, 'Synthetic certification void');
    }

    private function payment(MoneyAccount $account, string $amount, string $rate, string $key): CustomerPayment
    {
        return app(PostCustomerPaymentAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id, 'money_account_id' => $account->id,
            'payment_date' => '2026-10-02', 'payment_method' => 'cash', 'amount' => $amount,
            'exchange_rate' => $rate, 'idempotency_key' => 'certification-'.$key, 'allocations' => [],
        ]);
    }

    private function url(string $locale, string $format, string $from, string $to): string
    {
        return route('pdf.statement', $this->customer->public_id).'?'.http_build_query(compact('locale', 'format', 'from', 'to'));
    }

    /** @param array<string, array{string, string, string, string}> $expected */
    private function assertFigures(array $expected): void
    {
        $this->assertNotNull($this->reader->last);
        $actual = $this->reader->last['currencies'];
        $this->assertEqualsCanonicalizing(array_keys($expected), array_keys($actual));
        foreach ($expected as $currency => $figures) {
            $this->assertSame($currency, $actual[$currency]['currency']);
            $this->assertSame($figures, array_map(static fn (string $key): string => $actual[$currency][$key], ['opening_balance', 'total_debits', 'total_credits', 'closing_balance']));
        }
    }

    /** @return array<string, string> */
    private function economicFingerprint(): array
    {
        $hashes = [];
        foreach (['posting_batches', 'posting_lines', 'sales_invoices', 'customer_payments', 'customer_payment_allocations', 'customer_payment_application_events', 'stock_movements', 'document_sequences', 'audit_events', 'public_shares'] as $table) {
            $hashes[$table] = hash('sha256', DB::connection()->table($table)->where('company_id', $this->company->id)->orderBy('id')->get()->toJson());
        }

        return $hashes;
    }
}

/** Captures the real query result; neither balances nor output bytes are mocked. */
final class CapturingCustomerStatementQuery extends CustomerStatementQuery
{
    /** @var array<string, mixed>|null */
    public ?array $last = null;

    public int $calls = 0;

    public function execute(Customer $customer, ?string $fromDate = null, ?string $toDate = null): array
    {
        $this->calls++;

        return $this->last = parent::execute($customer, $fromDate, $toDate);
    }
}
