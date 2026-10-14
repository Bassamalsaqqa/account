<?php

declare(strict_types=1);

namespace Tests\Feature\ProductExperience;

use App\Actions\Company\CreateCompanyAction;
use App\Livewire\Pages\Customers\CustomerDetail;
use App\Models\Customer;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\SalesCorrectionFixtures;
use Tests\TestCase;

class CustomerDetailPresentationTest extends TestCase
{
    use RefreshDatabase;
    use SalesCorrectionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->salesFixtures();
    }

    public function test_null_customer_currency_displays_company_ils_code_and_precision(): void
    {
        $this->customer->update(['default_currency_code' => null]);
        $this->invoice('12.34', '1', 'ILS');

        Livewire::test(CustomerDetail::class, ['publicId' => $this->customer->public_id])
            ->assertViewHas('defaultCurr', 'ILS')->assertViewHas('totalOutstanding', '12.340000')
            ->assertSee('12.34 ILS')->assertSee('ILS');
    }

    public function test_null_customer_currency_uses_three_decimal_jod_company_currency(): void
    {
        app(CompanyContext::class)->clear();
        $this->company = app(CreateCompanyAction::class)->execute($this->owner, ['name_ar' => 'شركة الدينار', 'base_currency_code' => 'JOD']);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        session(['active_company_id' => $this->company->id]);
        $this->customer = Customer::create(['company_id' => $this->company->id, 'name_ar' => 'عميل الدينار',
            'default_currency_code' => null, 'created_by' => $this->owner->id]);
        $this->invoice('12.345', '1', 'JOD');

        Livewire::test(CustomerDetail::class, ['publicId' => $this->customer->public_id])
            ->assertViewHas('defaultCurr', 'JOD')->assertViewHas('totalOutstanding', '12.345000')
            ->assertSee('12.345 JOD');
    }

    public function test_credit_limit_is_labelled_in_company_base_currency_even_when_customer_uses_jod(): void
    {
        $this->customer->update(['default_currency_code' => 'JOD', 'credit_limit' => '123.450000']);
        Livewire::test(CustomerDetail::class, ['publicId' => $this->customer->public_id])
            ->assertViewHas('defaultCurr', 'JOD')->assertViewHas('baseCurrency', 'ILS')
            ->assertSee('123.45 ILS')->assertDontSee('123.450 JOD');
    }

    public function test_permission_revocation_blocks_an_already_open_customer_detail(): void
    {
        $component = Livewire::test(CustomerDetail::class, ['publicId' => $this->customer->public_id]);
        $this->owner->roles()->firstOrFail()->revokePermissionTo('customers.view');
        $component->set('activeTab', 'invoices')->assertForbidden();
    }

    public function test_company_switch_blocks_an_already_open_customer_detail(): void
    {
        $component = Livewire::test(CustomerDetail::class, ['publicId' => $this->customer->public_id]);
        app(CompanyContext::class)->clear();
        $other = app(CreateCompanyAction::class)->execute($this->owner, ['name_ar' => 'شركة أخرى', 'base_currency_code' => 'ILS']);
        app(CompanyContext::class)->setCompany($other, $this->owner);
        session(['active_company_id' => $other->id]);
        $component->set('activeTab', 'invoices')->assertForbidden();
    }

    public function test_foreign_company_customer_cannot_be_opened(): void
    {
        app(CompanyContext::class)->clear();
        $other = app(CreateCompanyAction::class)->execute($this->owner, ['name_ar' => 'شركة أخرى', 'base_currency_code' => 'ILS']);
        app(CompanyContext::class)->setCompany($other, $this->owner);
        session(['active_company_id' => $other->id]);
        Livewire::test(CustomerDetail::class, ['publicId' => $this->customer->public_id])->assertNotFound();
    }

    public function test_deleted_customer_does_not_reuse_the_open_components_cached_model(): void
    {
        $component = Livewire::test(CustomerDetail::class, ['publicId' => $this->customer->public_id]);
        $this->customer->delete();
        $component->set('activeTab', 'notes')->assertNotFound();
    }

    public function test_statement_dates_follow_company_timezone_at_new_year_boundary(): void
    {
        $this->company->update(['timezone' => 'Asia/Hebron']);
        $this->travelTo(now('UTC')->setDate(2026, 12, 31)->setTime(22, 30));
        Livewire::test(CustomerDetail::class, ['publicId' => $this->customer->public_id])
            ->assertSet('statementFrom', '2027-01-01')->assertSet('statementTo', '2027-01-01');
    }
}
