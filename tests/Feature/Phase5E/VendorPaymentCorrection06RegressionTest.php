<?php

declare(strict_types=1);

namespace Tests\Feature\Phase5E;

use App\Actions\Purchasing\PostVendorPaymentAction;
use App\Livewire\Pages\Purchasing\PaymentForm;
use App\Livewire\Pages\Purchasing\VendorDetail;
use App\Models\DocumentSequence;
use App\Models\VendorPayment;
use App\Services\Purchasing\VendorPaymentPostedIntegrityValidator;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;

final class VendorPaymentCorrection06RegressionTest extends Phase5ETestCase
{
    private string $originalTimezone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalTimezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        config(['app.timezone' => 'UTC']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        date_default_timezone_set($this->originalTimezone);
        parent::tearDown();
    }

    private function freezeCompanyTime(string $utc, string $timezone): void
    {
        Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
        $this->company->update(['timezone' => $timezone]);
        $this->activate($this->owner);
    }

    public static function localDateBoundaries(): array
    {
        return [
            'Hebron local midnight ahead' => ['2026-10-06 22:30:00', 'Asia/Hebron', '2026-10-07', '2026-01-01'],
            'Tokyo local New Year ahead' => ['2026-12-31 23:30:00', 'Asia/Tokyo', '2027-01-01', '2027-01-01'],
            'Honolulu local day behind' => ['2026-10-07 01:30:00', 'Pacific/Honolulu', '2026-10-06', '2026-01-01'],
        ];
    }

    #[DataProvider('localDateBoundaries')]
    public function test_payment_default_follows_company_calendar_not_utc(string $utc, string $timezone, string $date, string $yearStart): void
    {
        $this->freezeCompanyTime($utc, $timezone);
        $this->assertSame($date, Carbon::now($this->company->timezone)->toDateString());
        $this->assertNotSame($date, Carbon::now()->toDateString());
        Livewire::test(PaymentForm::class)->assertOk()->assertSet('payment_date', $date);
    }

    #[DataProvider('localDateBoundaries')]
    public function test_unchanged_default_posts_matching_payment_batch_and_sequence_year_and_retries_exactly(string $utc, string $timezone, string $date, string $yearStart): void
    {
        $this->freezeCompanyTime($utc, $timezone);
        $page = Livewire::test(PaymentForm::class)->assertSet('payment_date', $date)
            ->set('vendor_id', $this->vendor->id)->set('money_account_id', $this->ilsCashAccount->id)->set('amount', '1.00');
        $intent = [
            'vendor_id' => $page->get('vendor_id'), 'money_account_id' => $page->get('money_account_id'),
            'payment_date' => $page->get('payment_date'), 'payment_method' => $page->get('payment_method'),
            'amount' => $page->get('amount'), 'exchange_rate' => $page->get('exchange_rate'),
            'document_locale' => $page->get('document_locale'), 'reference_number' => $page->get('reference_number'),
            'notes' => $page->get('notes'), 'idempotency_key' => $page->get('idempotency_key'), 'allocations' => [],
        ];
        $page->call('save')->assertHasNoErrors();
        $payment = VendorPayment::sole();
        $this->assertSame($date, $payment->payment_date->toDateString());
        $this->assertSame($date, $payment->postingBatch->posting_date->toDateString());
        $year = (int) substr($date, 0, 4);
        $this->assertStringStartsWith('VPM-'.$year.'-', $payment->payment_number);
        $sequence = DocumentSequence::where('company_id', $this->company->id)->where('document_type', DocumentSequence::TYPE_VENDOR_PAYMENT)->where('year', $year)->sole();
        $this->assertSame($year, $sequence->year);
        app(VendorPaymentPostedIntegrityValidator::class)->validate($payment);
        $counts = [DB::table('vendor_payments')->count(), DB::table('posting_batches')->count()];
        $sequences = DB::table('document_sequences')->pluck('next_number', 'id')->all();
        $this->assertSame($payment->id, app(PostVendorPaymentAction::class)->execute($this->company, $this->owner, $intent)->id);
        $this->assertSame($counts, [DB::table('vendor_payments')->count(), DB::table('posting_batches')->count()]);
        $this->assertSame($sequences, DB::table('document_sequences')->pluck('next_number', 'id')->all());
    }

    #[DataProvider('localDateBoundaries')]
    public function test_statement_defaults_use_company_today_and_start_of_company_year(string $utc, string $timezone, string $date, string $yearStart): void
    {
        $this->freezeCompanyTime($utc, $timezone);
        $this->assertNotSame($date, Carbon::now()->toDateString());
        Livewire::test(VendorDetail::class, ['publicId' => $this->vendor->public_id])->assertOk()
            ->assertSet('statementFrom', $yearStart)->assertSet('statementTo', $date)
            ->set('activeTab', 'statement')
            ->assertViewHas('statementData', fn ($data) => $data['from_date'] === $yearStart && $data['to_date'] === $date);
    }

    public function test_default_statement_includes_local_today_excludes_future_and_ages_at_same_new_year_cutoff(): void
    {
        $this->freezeCompanyTime('2026-12-31 23:30:00', 'Asia/Tokyo');
        $prior = $this->createAndPostPurchase(['purchase_date' => '2026-12-31', 'due_date' => '2026-12-31', 'lines' => [['quantity' => '1', 'unit_cost' => '50']]]);
        $today = $this->createAndPostPurchase(['purchase_date' => '2027-01-01', 'due_date' => '2027-01-01', 'lines' => [['quantity' => '1', 'unit_cost' => '100']]]);
        $future = $this->createAndPostPurchase(['purchase_date' => '2027-01-02', 'due_date' => '2027-01-02', 'lines' => [['quantity' => '1', 'unit_cost' => '900']]]);
        Livewire::test(PaymentForm::class)->assertSet('payment_date', '2027-01-01')
            ->set('vendor_id', $this->vendor->id)->set('money_account_id', $this->ilsCashAccount->id)->set('amount', '20.00')
            ->call('save')->assertHasNoErrors();
        $payment = VendorPayment::sole();
        $page = Livewire::test(VendorDetail::class, ['publicId' => $this->vendor->public_id])
            ->assertSet('statementFrom', '2027-01-01')->assertSet('statementTo', '2027-01-01')->set('activeTab', 'statement');
        $page->assertViewHas('statementData', function ($data) use ($today, $future, $payment): bool {
            $statement = $data['currencies']['ILS'];
            $numbers = array_column($statement['entries'], 'number');
            $this->assertSame('2027-01-01', $data['to_date']);
            $this->assertContains($today->purchase_number, $numbers);
            $this->assertContains($payment->payment_number, $numbers);
            $this->assertNotContains($future->purchase_number, $numbers);
            $this->assertSame('50.00', $statement['opening_balance']);
            $this->assertSame('130.00', $statement['closing_balance']);
            $this->assertSame('130.00', $statement['aging']['signed_vendor_balance']);
            $this->assertSame('150.00', $statement['aging']['gross_open_purchases']);
            $this->assertSame('100.00', $statement['aging']['current']);
            $this->assertSame('50.00', $statement['aging']['days_1_30']);
            $this->assertSame('20.00', $statement['aging']['unapplied_credit_position']);

            return true;
        });
        $this->assertNotSame($prior->purchase_number, $today->purchase_number);
    }

    public function test_explicit_statement_date_only_filters_remain_unchanged_on_refresh_and_query(): void
    {
        $this->freezeCompanyTime('2026-12-31 23:30:00', 'Asia/Tokyo');
        $this->createAndPostPurchase(['purchase_date' => '2026-12-31', 'due_date' => '2026-12-31']);
        Livewire::test(VendorDetail::class, ['publicId' => $this->vendor->public_id])
            ->set('statementFrom', '2026-12-01')->set('statementTo', '2026-12-31')->set('activeTab', 'statement')
            ->call('$refresh')->assertSet('statementFrom', '2026-12-01')->assertSet('statementTo', '2026-12-31')
            ->assertViewHas('statementData', fn ($data) => $data['from_date'] === '2026-12-01' && $data['to_date'] === '2026-12-31'
                && $data['currencies']['ILS']['closing_balance'] === '100.00'
                && $data['currencies']['ILS']['aging']['signed_vendor_balance'] === '100.00');
    }

    public function test_explicit_payment_business_date_is_not_replaced_or_timezone_converted(): void
    {
        $this->freezeCompanyTime('2026-12-31 23:30:00', 'Asia/Tokyo');
        Livewire::test(PaymentForm::class)->assertSet('payment_date', '2027-01-01')
            ->set('payment_date', '2026-12-20')->set('vendor_id', $this->vendor->id)->set('money_account_id', $this->ilsCashAccount->id)
            ->set('amount', '1.00')->call('$refresh')->assertSet('payment_date', '2026-12-20')
            ->call('save')->assertHasNoErrors();
        $payment = VendorPayment::sole();
        $this->assertSame('2026-12-20', $payment->payment_date->toDateString());
        $this->assertSame('2026-12-20', $payment->postingBatch->posting_date->toDateString());
        $this->assertStringStartsWith('VPM-2026-', $payment->payment_number);
    }
}
