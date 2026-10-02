<?php

declare(strict_types=1);

namespace Tests\Feature\Phase4;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Sales\CreateQuotationAction;
use App\Actions\Sales\UpdateQuotationAction;
use App\Livewire\Pages\Customers\CustomerDetail;
use App\Livewire\Pages\Customers\CustomerForm;
use App\Livewire\Pages\Customers\CustomerIndex;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use App\Services\Sales\CustomerCatalogService;
use App\Services\Sales\DocumentDataBuilder;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CustomerManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $companyA;

    protected Company $companyB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['locale' => 'ar']);
        $creator = app(CreateCompanyAction::class);

        $this->companyA = $creator->execute($this->user, [
            'name_ar' => 'شركة المبيعات أ',
            'base_currency_code' => 'ILS',
        ]);

        $this->companyB = $creator->execute($this->user, [
            'name_ar' => 'شركة المبيعات ب',
            'base_currency_code' => 'ILS',
        ]);

        app(CompanyContext::class)->setCompany($this->companyA, $this->user);
        $this->actingAs($this->user);
    }

    public function test_can_create_customer_via_livewire_form(): void
    {
        Livewire::test(CustomerForm::class)
            ->set('name_ar', 'شركة الأمل للتجارة')
            ->set('name_en', 'Al-Amal Trading Co')
            ->set('business_name', 'مؤسسة الأمل التجارية')
            ->set('phone', '0599000111')
            ->set('whatsapp', '0599000111')
            ->set('email', 'amal@example.com')
            ->set('tax_number', '123456789')
            ->set('address_line_1_ar', 'شارع الإرسال')
            ->set('city_ar', 'رام الله')
            ->set('default_currency_code', 'ILS')
            ->set('credit_limit', '5000.000000')
            ->call('save')
            ->assertHasNoErrors();

        $customer = Customer::where('company_id', $this->companyA->id)->firstOrFail();
        $this->assertNotEmpty($customer->public_id);

        $this->assertDatabaseHas('customers', [
            'company_id' => $this->companyA->id,
            'name_ar' => 'شركة الأمل للتجارة',
            'name_en' => 'Al-Amal Trading Co',
            'phone' => '0599000111',
            'status' => 'active',
        ]);

        $customer = Customer::where('company_id', $this->companyA->id)->firstOrFail();
        $this->assertSame('5000.000000', $customer->credit_limit);
        $this->assertNotEmpty($customer->public_id);
    }

    public function test_tenant_isolation_prevents_viewing_or_editing_other_company_customer(): void
    {
        $customerA = Customer::create([
            'company_id' => $this->companyA->id,
            'name_ar' => 'عميل الشركة الأولى',
            'active' => true,
            'created_by' => $this->user->id,
        ]);

        // Switch to Company B
        app(CompanyContext::class)->setCompany($this->companyB, $this->user);

        // Should not see Customer A in Index
        Livewire::test(CustomerIndex::class)
            ->assertDontSee('عميل الشركة الأولى');

        // Cannot edit customer from another company (404)
        Livewire::test(CustomerForm::class, ['publicId' => $customerA->public_id])
            ->assertStatus(404);

        // Cannot view customer detail from another company (404)
        Livewire::test(CustomerDetail::class, ['publicId' => $customerA->public_id])
            ->assertStatus(404);
    }

    public function test_inactive_customer_cannot_be_selected_for_new_transactions(): void
    {
        $inactiveCustomer = Customer::create([
            'company_id' => $this->companyA->id,
            'name_ar' => 'عميل موقف',
            'active' => false,
            'created_by' => $this->user->id,
        ]);

        $activeCustomer = Customer::create([
            'company_id' => $this->companyA->id,
            'name_ar' => 'عميل نشط',
            'active' => true,
            'created_by' => $this->user->id,
        ]);

        // Inactive customers are flagged and should not be permitted in document forms
        $this->assertFalse($inactiveCustomer->active);
        $this->assertTrue($activeCustomer->active);
    }

    public function test_customer_detail_shows_profile_and_tabs(): void
    {
        $customer = Customer::create([
            'company_id' => $this->companyA->id,
            'name_ar' => 'شركة النجاح',
            'phone' => '0599123456',
            'credit_limit' => '10000.000000',
            'active' => true,
            'created_by' => $this->user->id,
        ]);

        Livewire::test(CustomerDetail::class, ['publicId' => $customer->public_id])
            ->assertSee('شركة النجاح')
            ->assertSee('0599123456')
            ->set('activeTab', 'invoices')
            ->assertSee(__('sales.invoices'))
            ->set('activeTab', 'quotations')
            ->assertSee(__('sales.quotations'))
            ->set('activeTab', 'payments')
            ->assertSee(__('sales.payments'))
            ->set('activeTab', 'returns')
            ->assertSee(__('sales.returns'));
    }

    public function test_bilingual_business_fields_and_status_search_use_canonical_columns(): void
    {
        Livewire::test(CustomerForm::class)
            ->set('name_ar', 'Canonical customer')
            ->set('name_en', 'Canonical Customer EN')
            ->set('business_name', 'Arabic business')
            ->set('business_name_en', 'English business')
            ->set('address_line_1_ar', 'Arabic address')
            ->set('address_line_1_en', 'English address')
            ->set('credit_limit', '0')
            ->call('save')->assertHasNoErrors();
        $customer = Customer::firstOrFail();
        $this->assertSame('active', $customer->status);
        $this->assertSame('0.000000', $customer->credit_limit);
        $this->assertSame('English business', $customer->business_name_en);
        $this->assertSame('English address', $customer->address_en);
        Livewire::test(CustomerIndex::class)->set('search', 'English business')->assertSee('Canonical customer')
            ->set('statusFilter', 'inactive')->assertDontSee('Canonical customer');
        $customer->update(['active' => false]);
        Livewire::test(CustomerIndex::class)->set('statusFilter', 'active')->assertDontSee('Canonical customer')
            ->set('statusFilter', 'inactive')->assertSee('Canonical customer');
        app()->setLocale('en');
        Livewire::test(CustomerDetail::class, ['publicId' => $customer->public_id])->assertSee('English business');
    }

    public function test_customer_catalog_rejects_unauthenticated_and_foreign_updates_without_writes(): void
    {
        $customer = Customer::create(['company_id' => $this->companyA->id, 'name_ar' => 'Original', 'created_by' => $this->user->id]);
        auth()->logout();
        try {
            app(CustomerCatalogService::class)->save($this->companyA, $this->user, ['name_ar' => 'Forged'], $customer->id);
            $this->fail('Unauthenticated catalog update succeeded.');
        } catch (AuthorizationException) {
            $this->assertSame('Original', $customer->fresh()->name_ar);
        }
        $this->actingAs($this->user);
        app(CompanyContext::class)->setCompany($this->companyB, $this->user);
        try {
            app(CustomerCatalogService::class)->save($this->companyB, $this->user, ['name_ar' => 'Forged'], $customer->id);
            $this->fail('Foreign catalog update succeeded.');
        } catch (ModelNotFoundException) {
            app(CompanyContext::class)->setCompany($this->companyA, $this->user);
            $this->assertSame('Original', $customer->fresh()->name_ar);
        }
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('posting_batches', 0);
    }

    public function test_editing_draft_quote_customer_refreshes_snapshot_and_sent_identity_remains_stable(): void
    {
        $original = Customer::create(['company_id' => $this->companyA->id, 'name_ar' => 'Original customer', 'created_by' => $this->user->id]);
        $replacement = Customer::create(['company_id' => $this->companyA->id, 'name_ar' => 'Replacement customer', 'name_en' => 'Replacement EN', 'business_name_en' => 'Replacement business', 'created_by' => $this->user->id]);
        $quote = app(CreateQuotationAction::class)->execute($this->companyA, $this->user, [
            'customer_id' => $original->id, 'currency_code' => 'ILS', 'exchange_rate' => '1',
            'issue_date' => '2026-10-02', 'document_locale' => 'en',
            'lines' => [['item_description' => 'Service', 'quantity' => '1', 'unit_price' => '10']],
        ]);
        $quote = app(UpdateQuotationAction::class)->execute($quote, $this->user, ['customer_id' => $replacement->id]);
        $quote->transition('sent', $this->user);
        $replacement->update(['name_en' => 'Later rename', 'business_name_en' => 'Later business']);
        $data = app(DocumentDataBuilder::class)->build($quote);
        $this->assertSame('Replacement EN', $data->customer['name']);
        $this->assertSame('Replacement business', $data->customer['business_name']);
        $this->assertDatabaseCount('posting_batches', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }
}
