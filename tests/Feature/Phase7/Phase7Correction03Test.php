<?php

declare(strict_types=1);

namespace Tests\Feature\Phase7;

use App\Actions\Money\IssueCheckAction;
use App\Actions\Money\ReceiveCheckAction;
use App\Livewire\Pages\Money\CheckDetail;
use App\Livewire\Pages\Money\CheckIndex;
use App\Models\Check;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;

final class Phase7Correction03Test extends Phase7TestCase
{
    public static function locales(): array
    {
        return [['ar'], ['en']];
    }

    private function assertScreens(Check $check, string $expected, array $absent = []): void
    {
        $before = DB::table('posting_batches')->count();
        foreach ([Livewire::test(CheckIndex::class), Livewire::test(CheckDetail::class, ['publicId' => $check->public_id])] as $screen) {
            $screen->assertStatus(200)->assertSee($expected)->assertSee($check->check_number);
            foreach ($absent as $name) {
                $screen->assertDontSee($name);
            }
        }
        $locale = app()->getLocale();
        $this->company->languages()->where('locale', 'en')->update(['enabled' => true]);
        $this->company->unsetRelation('languages');
        foreach ([route('money.checks.index'), route('money.checks.show', $check->public_id)] as $url) {
            $response = $this->withSession(['locale' => $locale, 'active_company_id' => $this->company->id])->get($url);
            $response->assertOk()->assertSee($expected)->assertSee('dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'"', false);
            foreach ($absent as $name) {
                $response->assertDontSee($name);
            }
        }
        $this->assertSame($before, DB::table('posting_batches')->count());
    }

    #[DataProvider('locales')]
    public function test_customer_rename_does_not_change_check_index_or_detail(string $locale): void
    {
        $customer = Customer::create(['company_id' => $this->company->id, 'name_ar' => 'عميل تاريخي', 'name_en' => 'Frozen Customer', 'active' => true, 'created_by' => $this->owner->id]);
        $intent = $this->checkIntent();
        $intent['party_id'] = $customer->id;
        $check = app(ReceiveCheckAction::class)->execute($this->company, $this->owner, $intent);
        $snapshot = $check->party_snapshot;
        $customer->update(['name_ar' => 'عميل جديد', 'name_en' => 'Renamed Customer']);
        app()->setLocale($locale);
        $this->assertScreens($check, $snapshot['name_'.$locale], ['عميل جديد', 'Renamed Customer']);
        $this->assertSame($snapshot, $check->fresh()->party_snapshot);
    }

    #[DataProvider('locales')]
    public function test_vendor_rename_does_not_change_check_index_or_detail(string $locale): void
    {
        $this->vendor->update(['name_ar' => 'مورد تاريخي', 'name_en' => 'Frozen Vendor']);
        $check = $this->check('outgoing');
        $snapshot = $check->party_snapshot;
        $this->vendor->update(['name_ar' => 'مورد جديد', 'name_en' => 'Renamed Vendor']);
        app()->setLocale($locale);
        $this->assertScreens($check, $snapshot['name_'.$locale], ['مورد جديد', 'Renamed Vendor']);
        $this->assertSame($snapshot, $check->fresh()->party_snapshot);
    }

    private function phase7Check(string $source): Check
    {
        return app(IssueCheckAction::class)->execute($this->company, $this->owner, [
            'source_type' => $source, 'employee_id' => $this->employee->id, 'category_id' => $this->operatingCategory->id,
            'classification' => 'operating', 'description' => 'Snapshot display receipt', 'payee_name' => 'Frozen Free-text Payee',
            'date' => '2026-10-02', 'due_date' => '2026-10-03', 'currency_code' => 'USD', 'amount' => '100', 'exchange_rate' => '3.5',
            'money_account_id' => $this->usdBankAccount->id, 'check_number' => 'C03-'.$source, 'bank_name' => 'QA Bank', 'idempotency_key' => 'c03-'.$source,
        ]);
    }

    #[DataProvider('locales')]
    public function test_employee_snapshot_survives_rename_and_archival(string $locale): void
    {
        $check = $this->phase7Check('employee_advance');
        $name = $check->party_snapshot['name'];
        $this->employee->update(['name' => 'Renamed Employee', 'active' => false]);
        $this->employee->delete();
        app()->setLocale($locale);
        $this->assertScreens($check, $name, ['Renamed Employee']);
    }

    #[DataProvider('locales')]
    public function test_expense_free_text_payee_is_frozen_on_both_screens(string $locale): void
    {
        $check = $this->phase7Check('expense');
        app()->setLocale($locale);
        $this->assertScreens($check, 'Frozen Free-text Payee');
    }

    public static function snapshots(): array
    {
        return [
            'english falls back to arabic' => ['en', ['name_ar' => 'تاريخي'], 'تاريخي'],
            'arabic falls back to english' => ['ar', ['name_en' => 'Frozen'], 'Frozen'],
            'blank preferred language' => ['en', ['name_en' => '  ', 'name_ar' => 'تاريخي'], 'تاريخي'],
            'employee name' => ['en', ['name' => 'Employee'], 'Employee'],
            'expense payee' => ['ar', ['payee_name' => 'Payee'], 'Payee'],
            'arabic business name' => ['ar', ['business_name_ar' => 'شركة', 'business_name_en' => 'Business'], 'شركة'],
            'english business name' => ['en', ['business_name_ar' => 'شركة', 'business_name_en' => 'Business'], 'Business'],
            'business language fallback' => ['en', ['business_name_ar' => 'شركة'], 'شركة'],
            'unlocalized business name' => ['en', ['business_name' => 'Business'], 'Business'],
            'name before payee and business' => ['ar', ['name' => 'Employee', 'payee_name' => 'Payee', 'business_name_ar' => 'Business'], 'Employee'],
            'empty snapshot' => ['en', [], 'Unavailable'],
            'null snapshot' => ['en', null, 'Unavailable'],
            'malformed snapshot' => ['en', 'broken', 'Unavailable'],
            'malformed field' => ['en', ['name_en' => ['bad'], 'name_ar' => false, 'name' => 42, 'payee_name' => 'Payee'], 'Payee'],
        ];
    }

    #[DataProvider('snapshots')]
    public function test_snapshot_formatter_fallbacks_without_master_queries(string $locale, mixed $snapshot, string $expected): void
    {
        $check = new Check;
        $check->setAttribute('party_snapshot', $snapshot);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            app()->setLocale($locale);
            $this->assertSame($expected, $check->partyDisplayName());
            $this->assertSame($expected, $check->partyDisplayName($locale));
            $this->assertSame([], DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    }

    public function test_snapshot_display_does_not_bypass_source_authorization_or_stale_revocation(): void
    {
        $vendor = $this->check('outgoing');
        $expense = $this->phase7Check('expense');
        $advance = $this->phase7Check('employee_advance');
        $reader = $this->customActor(['money.check.view']);
        $this->activate($reader);
        foreach ([$vendor, $expense, $advance] as $check) {
            Livewire::test(CheckDetail::class, ['publicId' => $check->public_id])->assertForbidden();
            Livewire::test(CheckIndex::class)->assertDontSee($check->check_number);
        }
        $reader->givePermissionTo('money.expense.view');
        $screen = Livewire::test(CheckDetail::class, ['publicId' => $expense->public_id])->assertSee('Frozen Free-text Payee');
        $reader->revokePermissionTo('money.expense.view');
        $screen->call('$refresh')->assertForbidden();
        $reader->roles()->firstOrFail()->revokePermissionTo('money.check.view');
        $this->activate($this->owner);
        $incoming = $this->check();
        $this->activate($reader);
        Livewire::test(CheckDetail::class, ['publicId' => $incoming->public_id])->assertForbidden();
        Livewire::test(CheckIndex::class)->assertForbidden();
    }
}
