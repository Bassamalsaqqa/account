<?php

declare(strict_types=1);

namespace Tests\Feature\Ux0;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Purchasing\PostVendorPaymentAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Exceptions\IdempotencyConflictException;
use App\Livewire\Pages\Customers\CustomerForm;
use App\Livewire\Pages\Purchasing\PurchaseForm;
use App\Livewire\Pages\Purchasing\VendorForm;
use App\Livewire\Pages\Sales\InvoiceForm;
use App\Livewire\Pages\Sales\QuotationForm;
use App\Models\Customer;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Purchasing\PayablesReconciliationService;
use App\Services\Purchasing\PurchaseReadModel;
use App\Services\Purchasing\PurchaseReturnReadModel;
use App\Services\Purchasing\VendorCatalogService;
use App\Services\Sales\CustomerCatalogService;
use App\Services\Sales\DocumentDataBuilder;
use App\Services\Sales\QuickPartyCreation;
use App\Services\Search\GlobalSearch;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Feature\Phase5E\Phase5ETestCase;

final class PartySingleNameOnboardingTest extends Phase5ETestCase
{
    public function test_customer_arabic_only_name_creation_via_catalog_service_and_livewire(): void
    {
        // 1. Catalog service
        $customer = app(CustomerCatalogService::class)->save($this->company, $this->owner, [
            'name_ar' => 'شركة الأمل للتجارة',
            'phone' => '0599000111',
            'status' => 'active',
        ]);

        $this->assertSame('شركة الأمل للتجارة', $customer->name_ar);
        $this->assertNull($customer->name_en);
        $this->assertSame('شركة الأمل للتجارة', $customer->displayName());

        app()->setLocale('en');
        $this->assertSame('شركة الأمل للتجارة', $customer->displayName());
        app()->setLocale('ar');

        // 2. Livewire CustomerForm
        Livewire::test(CustomerForm::class)
            ->set('name_ar', 'عميل عربي فقط')
            ->set('name_en', null)
            ->set('phone', '0599222333')
            ->set('default_currency_code', 'ILS')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('customers', [
            'company_id' => $this->company->id,
            'name_ar' => 'عميل عربي فقط',
            'name_en' => null,
        ]);
    }

    public function test_customer_english_only_name_creation_via_catalog_service_and_livewire(): void
    {
        // 1. Catalog service
        $customer = app(CustomerCatalogService::class)->save($this->company, $this->owner, [
            'name_en' => 'Acme Global Corp',
            'phone' => '0599444555',
            'status' => 'active',
        ]);

        $this->assertNull($customer->name_ar);
        $this->assertSame('Acme Global Corp', $customer->name_en);
        $this->assertSame('Acme Global Corp', $customer->displayName());

        app()->setLocale('en');
        $this->assertSame('Acme Global Corp', $customer->displayName());
        app()->setLocale('ar');

        // 2. Livewire CustomerForm
        Livewire::test(CustomerForm::class)
            ->set('name_ar', null)
            ->set('name_en', 'Horizon Technology')
            ->set('phone', null) // Phone is optional
            ->set('default_currency_code', 'ILS')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('customers', [
            'company_id' => $this->company->id,
            'name_ar' => null,
            'name_en' => 'Horizon Technology',
        ]);
    }

    public function test_customer_bilingual_names_respects_locale_display(): void
    {
        $customer = app(CustomerCatalogService::class)->save($this->company, $this->owner, [
            'name_ar' => 'شركة النور',
            'name_en' => 'Al-Noor Co',
            'status' => 'active',
        ]);

        app()->setLocale('ar');
        $this->assertSame('شركة النور', $customer->displayName());

        app()->setLocale('en');
        $this->assertSame('Al-Noor Co', $customer->displayName());

        app()->setLocale('ar');
    }

    public function test_vendor_arabic_only_name_creation_via_catalog_service_and_livewire(): void
    {
        // 1. Catalog service
        $vendor = app(VendorCatalogService::class)->save($this->company, $this->owner, [
            'name_ar' => 'مورد التوريدات السريعة',
            'phone' => '0599777888',
        ]);

        $this->assertSame('مورد التوريدات السريعة', $vendor->name_ar);
        $this->assertNull($vendor->name_en);
        $this->assertSame('مورد التوريدات السريعة', $vendor->displayName());

        app()->setLocale('en');
        $this->assertSame('مورد التوريدات السريعة', $vendor->displayName());
        app()->setLocale('ar');

        // 2. Livewire VendorForm
        Livewire::test(VendorForm::class)
            ->set('name_ar', 'مورد مواد بناء')
            ->set('name_en', null)
            ->set('phone', null) // Phone is optional
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('vendors', [
            'company_id' => $this->company->id,
            'name_ar' => 'مورد مواد بناء',
            'name_en' => null,
        ]);
    }

    public function test_vendor_english_only_name_creation_via_catalog_service_and_livewire(): void
    {
        // 1. Catalog service
        $vendor = app(VendorCatalogService::class)->save($this->company, $this->owner, [
            'name_en' => 'Universal Logistics Ltd',
            'phone' => null,
        ]);

        $this->assertNull($vendor->name_ar);
        $this->assertSame('Universal Logistics Ltd', $vendor->name_en);
        $this->assertSame('Universal Logistics Ltd', $vendor->displayName());

        app()->setLocale('en');
        $this->assertSame('Universal Logistics Ltd', $vendor->displayName());
        app()->setLocale('ar');

        // 2. Livewire VendorForm
        Livewire::test(VendorForm::class)
            ->set('name_ar', null)
            ->set('name_en', 'Direct Import Inc')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('vendors', [
            'company_id' => $this->company->id,
            'name_ar' => null,
            'name_en' => 'Direct Import Inc',
        ]);
    }

    public function test_vendor_bilingual_names_respects_locale_display(): void
    {
        $vendor = app(VendorCatalogService::class)->save($this->company, $this->owner, [
            'name_ar' => 'مورد الاتحاد',
            'name_en' => 'Union Vendor',
        ]);

        app()->setLocale('ar');
        $this->assertSame('مورد الاتحاد', $vendor->displayName());

        app()->setLocale('en');
        $this->assertSame('Union Vendor', $vendor->displayName());

        app()->setLocale('ar');
    }

    public function test_string_zero_preserved_as_valid_nonblank_name(): void
    {
        // Customer with Arabic name = "0"
        $cZeroAr = app(CustomerCatalogService::class)->save($this->company, $this->owner, [
            'name_ar' => '0',
            'name_en' => null,
        ]);
        $this->assertSame('0', $cZeroAr->name_ar);
        $this->assertNull($cZeroAr->name_en);
        $this->assertSame('0', $cZeroAr->displayName());

        // Customer with English name = "0"
        $cZeroEn = app(CustomerCatalogService::class)->save($this->company, $this->owner, [
            'name_ar' => null,
            'name_en' => '0',
        ]);
        $this->assertNull($cZeroEn->name_ar);
        $this->assertSame('0', $cZeroEn->name_en);
        $this->assertSame('0', $cZeroEn->displayName());

        // Vendor with Arabic name = "0"
        $vZeroAr = app(VendorCatalogService::class)->save($this->company, $this->owner, [
            'name_ar' => '0',
            'name_en' => null,
        ]);
        $this->assertSame('0', $vZeroAr->name_ar);
        $this->assertNull($vZeroAr->name_en);
        $this->assertSame('0', $vZeroAr->displayName());

        // Vendor with English name = "0"
        $vZeroEn = app(VendorCatalogService::class)->save($this->company, $this->owner, [
            'name_ar' => null,
            'name_en' => '0',
        ]);
        $this->assertNull($vZeroEn->name_ar);
        $this->assertSame('0', $vZeroEn->name_en);
        $this->assertSame('0', $vZeroEn->displayName());
    }

    public function test_whitespace_and_blank_names_are_rejected(): void
    {
        // Customer catalog service
        try {
            app(CustomerCatalogService::class)->save($this->company, $this->owner, [
                'name_ar' => '   ',
                'name_en' => '',
            ]);
            $this->fail('Blank customer name was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('name_ar', $e->errors());
        }

        // Vendor catalog service
        try {
            app(VendorCatalogService::class)->save($this->company, $this->owner, [
                'name_ar' => '',
                'name_en' => '    ',
            ]);
            $this->fail('Blank vendor name was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('name_ar', $e->errors());
        }

        // CustomerForm
        Livewire::test(CustomerForm::class)
            ->set('name_ar', '   ')
            ->set('name_en', '')
            ->call('save')
            ->assertHasErrors(['name_ar']);
    }

    public function test_partial_update_resolves_omitted_name_and_refuses_clearing_sole_name(): void
    {
        $customer = app(CustomerCatalogService::class)->save($this->company, $this->owner, [
            'name_ar' => 'العميل الثابت',
            'name_en' => null,
            'phone' => '0599111111',
        ]);

        // Partial update: updating phone without providing name fields resolves name_ar from locked persisted record
        $updated = app(CustomerCatalogService::class)->save($this->company, $this->owner, [
            'phone' => '0599999999',
        ], $customer->id);

        $this->assertSame('العميل الثابت', $updated->name_ar);
        $this->assertSame('0599999999', $updated->phone);

        // Attempting to blank out the only existing name must fail
        try {
            app(CustomerCatalogService::class)->save($this->company, $this->owner, [
                'name_ar' => null,
                'name_en' => null,
            ], $customer->id);
            $this->fail('Blanking sole customer name was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('name_ar', $e->errors());
        }

        // Switching sole name from Arabic to English succeeds
        $switched = app(CustomerCatalogService::class)->save($this->company, $this->owner, [
            'name_ar' => null,
            'name_en' => 'Switched English Customer',
        ], $customer->id);

        $this->assertNull($switched->name_ar);
        $this->assertSame('Switched English Customer', $switched->name_en);

        // Same for Vendor
        $vendor = app(VendorCatalogService::class)->save($this->company, $this->owner, [
            'name_ar' => 'المورد الثابت',
            'name_en' => null,
            'phone' => '0599222222',
        ]);

        $vUpdated = app(VendorCatalogService::class)->save($this->company, $this->owner, [
            'phone' => '0599888888',
        ], $vendor->id);

        $this->assertSame('المورد الثابت', $vUpdated->name_ar);
        $this->assertSame('0599888888', $vUpdated->phone);

        try {
            app(VendorCatalogService::class)->save($this->company, $this->owner, [
                'name_ar' => '   ',
                'name_en' => null,
            ], $vendor->id);
            $this->fail('Blanking sole vendor name was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('name_ar', $e->errors());
        }
    }

    public function test_quick_party_creation_idempotency_and_replay(): void
    {
        $keyCust = (string) Str::uuid();
        $dataCust = [
            'name_ar' => null,
            'name_en' => 'Quick English Customer',
            'phone' => '0599333444',
            'code' => null,
            'preferred_locale' => 'en',
            'default_currency_code' => 'ILS',
            'status' => 'active',
        ];

        $custFirst = app(QuickPartyCreation::class)->execute(
            $this->company,
            $this->owner,
            'customer',
            $keyCust,
            $dataCust,
            'sales.invoice.create'
        );

        $this->assertNull($custFirst->name_ar);
        $this->assertSame('Quick English Customer', $custFirst->name_en);

        // Replay with exact payload returns identical customer
        $custReplay = app(QuickPartyCreation::class)->execute(
            $this->company,
            $this->owner,
            'customer',
            $keyCust,
            $dataCust,
            'sales.invoice.create'
        );

        $this->assertSame($custFirst->id, $custReplay->id);

        // Altered payload with same key is rejected
        $dataCustAltered = $dataCust;
        $dataCustAltered['name_en'] = 'Different Customer Name';

        try {
            app(QuickPartyCreation::class)->execute(
                $this->company,
                $this->owner,
                'customer',
                $keyCust,
                $dataCustAltered,
                'sales.invoice.create'
            );
            $this->fail('Altered quick party payload was accepted.');
        } catch (IdempotencyConflictException) {
            $this->assertSame(1, Customer::where('name_en', 'Quick English Customer')->count());
        }

        // Quick vendor with single Arabic name
        $keyVend = (string) Str::uuid();
        $dataVend = [
            'name_ar' => 'مورد سريع',
            'name_en' => null,
            'phone' => null,
            'code' => null,
            'preferred_locale' => 'ar',
            'default_currency_code' => 'ILS',
            'status' => 'active',
        ];

        $vendFirst = app(QuickPartyCreation::class)->execute(
            $this->company,
            $this->owner,
            'vendor',
            $keyVend,
            $dataVend,
            'purchasing.purchase.create'
        );

        $this->assertSame('مورد سريع', $vendFirst->name_ar);
        $this->assertNull($vendFirst->name_en);

        $vendReplay = app(QuickPartyCreation::class)->execute(
            $this->company,
            $this->owner,
            'vendor',
            $keyVend,
            $dataVend,
            'purchasing.purchase.create'
        );

        $this->assertSame($vendFirst->id, $vendReplay->id);
    }

    public function test_quick_party_creation_draft_continuity_in_invoice_form(): void
    {
        $component = Livewire::test(InvoiceForm::class);

        // User enters draft line with custom unit price
        $component->set('lines.0.product_id', $this->product->id);
        $component->set('lines.0.quantity', '5');
        $component->set('lines.0.unit_price', '42.50');

        // User opens quick customer modal
        $component->call('openQuickCustomerModal');
        $component->assertSet('showQuickCustomerModal', true);

        // Cancelling modal preserves draft lines and prices
        $component->call('closeQuickCustomerModal');
        $component->assertSet('showQuickCustomerModal', false);
        $component->assertSet('lines.0.quantity', '5');
        $component->assertSet('lines.0.unit_price', '42.50');

        // User opens quick customer modal again and saves English-only customer
        $component->call('openQuickCustomerModal');
        $component->set('quick_customer_name_ar', '');
        $component->set('quick_customer_name_en', 'Livewire Fast Customer');
        $component->set('quick_customer_phone', '0599555666');
        $component->call('saveQuickCustomer');
        $component->assertHasNoErrors();
        $component->assertSet('showQuickCustomerModal', false);

        // Newly created customer is selected in the parent form
        $newCustomer = Customer::where('name_en', 'Livewire Fast Customer')->firstOrFail();
        $component->assertSet('customer_id', $newCustomer->id);

        // Draft lines and custom prices remain completely intact
        $component->assertSet('lines.0.quantity', '5');
        $component->assertSet('lines.0.unit_price', '42.50');
    }

    public function test_quick_party_creation_draft_continuity_in_purchase_form(): void
    {
        $component = Livewire::test(PurchaseForm::class);

        // User adds a line with custom price
        $component->set('lines.0.product_id', $this->product->id);
        $component->set('lines.0.quantity', '7');
        $component->set('lines.0.unit_cost', '19.99');

        // User opens quick vendor modal
        $component->call('openQuickVendorModal');
        $component->assertSet('showQuickVendorModal', true);

        // Cancelling preserves lines
        $component->call('closeQuickVendorModal');
        $component->assertSet('showQuickVendorModal', false);
        $component->assertSet('lines.0.quantity', '7');
        $component->assertSet('lines.0.unit_cost', '19.99');

        // User opens quick vendor modal again and creates Arabic-only vendor
        $component->call('openQuickVendorModal');
        $component->set('quick_vendor_name_ar', 'مورد المسودة السريع');
        $component->set('quick_vendor_name_en', '');
        $component->call('saveQuickVendor');
        $component->assertHasNoErrors();
        $component->assertSet('showQuickVendorModal', false);

        $newVendor = Vendor::where('name_ar', 'مورد المسودة السريع')->firstOrFail();
        $component->assertSet('vendor_id', $newVendor->id);

        // Lines and prices intact
        $component->assertSet('lines.0.quantity', '7');
        $component->assertSet('lines.0.unit_cost', '19.99');
    }

    public function test_posted_document_snapshot_immutability_and_document_data_builder(): void
    {
        $customer = app(CustomerCatalogService::class)->save($this->company, $this->owner, [
            'name_ar' => null,
            'name_en' => 'English Customer Only',
            'default_currency_code' => 'ILS',
            'status' => 'active',
        ]);

        $this->createAndPostPurchase();

        $invoice = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $customer->id,
            'warehouse_id' => $this->warehouse->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-10-28',
            'document_locale' => 'ar',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'item_description' => 'Test Item Description',
                    'quantity' => '2',
                    'unit_price' => '25.00',
                ],
            ],
        ]);

        $posted = app(PostSalesInvoiceAction::class)->execute($invoice, $this->owner);

        // Customer snapshot has name_ar as null and name_en as 'English Customer Only'
        $snapshot = $posted->customer_snapshot;
        $this->assertIsArray($snapshot);
        $this->assertNull($snapshot['name_ar']);
        $this->assertSame('English Customer Only', $snapshot['name_en']);

        // DocumentDataBuilder succeeds in 'ar' locale (fallback to name_en)
        $docDataAr = app(DocumentDataBuilder::class)->build($posted, false, 'ar');
        $this->assertSame('English Customer Only', $docDataAr->customer['name']);

        // DocumentDataBuilder succeeds in 'en' locale
        $docDataEn = app(DocumentDataBuilder::class)->build($posted, false, 'en');
        $this->assertSame('English Customer Only', $docDataEn->customer['name']);

        // Later master record rename does NOT affect posted snapshot
        $customer->update(['name_en' => 'Renamed Customer Later']);
        $freshInvoice = SalesInvoice::findOrFail($posted->id);
        $this->assertSame('English Customer Only', $freshInvoice->customer_snapshot['name_en']);
    }

    public function test_migration_down_refusal_when_null_name_ar_exists(): void
    {
        $migration = require database_path('migrations/2026_10_28_100000_allow_nullable_customer_and_vendor_name_ar.php');

        // Customer with null name_ar exists
        $nullCustomer = Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => null,
            'name_en' => 'Nullable Test Customer',
            'created_by' => $this->owner->id,
        ]);

        try {
            $migration->down();
            $this->fail('Migration down() did not refuse rollback when null name_ar records exist.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Cannot reverse migration', $e->getMessage());
        }

        // Even if soft-deleted, down() must refuse
        $nullCustomer->delete();
        $this->assertTrue(Customer::withTrashed()->where('id', $nullCustomer->id)->exists());

        try {
            $migration->down();
            $this->fail('Migration down() did not refuse rollback for soft-deleted null name_ar records.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Cannot reverse migration', $e->getMessage());
        }

        // Force delete the test record
        $nullCustomer->forceDelete();
    }

    public function test_quick_party_creation_hash_stability_replay_and_altered_payload_rejection(): void
    {
        $payload1 = [
            'name_ar' => 'شركة النور القديمة',
            'phone' => '0599111222',
            'code' => 'C1',
        ];

        // Hash stability with historical Arabic-name payload across different key orderings
        $payloadReordered = [
            'code' => 'C1',
            'name_ar' => 'شركة النور القديمة',
            'phone' => '0599111222',
        ];

        $computeHash = function (string $kind, array $data): string {
            ksort($data);

            return hash('sha256', json_encode(['kind' => $kind, 'data' => $data], JSON_THROW_ON_ERROR));
        };

        $hash1 = $computeHash('customer', $payload1);
        $hashReordered = $computeHash('customer', $payloadReordered);
        $this->assertSame($hash1, $hashReordered);

        // Quick create with stable idempotency
        $key = (string) Str::uuid();
        $customer1 = app(QuickPartyCreation::class)->execute(
            $this->company,
            $this->owner,
            'customer',
            $key,
            $payload1,
            'sales.invoice.create'
        );

        // Exact replay returns identical model
        $customerReplay = app(QuickPartyCreation::class)->execute(
            $this->company,
            $this->owner,
            'customer',
            $key,
            $payload1,
            'sales.invoice.create'
        );
        $this->assertSame($customer1->id, $customerReplay->id);

        // Altered payload with same key is rejected with IdempotencyConflictException
        $alteredPayload = array_merge($payload1, ['phone' => '0599999999']);
        $this->expectException(IdempotencyConflictException::class);
        app(QuickPartyCreation::class)->execute(
            $this->company,
            $this->owner,
            'customer',
            $key,
            $alteredPayload,
            'sales.invoice.create'
        );
    }

    public function test_quick_party_creation_sequential_replay_and_isolation(): void
    {
        $key = (string) Str::uuid();
        $payload = [
            'name_ar' => null,
            'name_en' => 'Concurrent Customer Corp',
            'phone' => '0599333444',
            'code' => 'CONC-1',
        ];

        // Sequential simulated double-submit with same idempotency key
        $partyA = app(QuickPartyCreation::class)->execute(
            $this->company,
            $this->owner,
            'customer',
            $key,
            $payload,
            'sales.invoice.create'
        );
        $partyB = app(QuickPartyCreation::class)->execute(
            $this->company,
            $this->owner,
            'customer',
            $key,
            $payload,
            'sales.invoice.create'
        );

        // Exactly one party created, durable receipt reused
        $this->assertSame($partyA->id, $partyB->id);
        $this->assertSame(1, Customer::where('company_id', $this->company->id)->where('code', 'CONC-1')->count());

        // Company isolation: same key in another company creates its own party
        app(CompanyContext::class)->clear();
        $otherOwner = User::factory()->create(['locale' => 'ar']);
        $otherCompany = app(CreateCompanyAction::class)->execute($otherOwner, [
            'name_ar' => 'شركة أخرى',
            'name_en' => 'Other Co',
            'default_locale' => 'ar',
            'base_currency_code' => 'ILS',
        ]);
        app(CompanyContext::class)->setCompany($otherCompany, $otherOwner);
        $this->actingAs($otherOwner);
        setPermissionsTeamId($otherCompany->id);

        $partyOther = app(QuickPartyCreation::class)->execute(
            $otherCompany,
            $otherOwner,
            'customer',
            $key,
            $payload,
            'sales.invoice.create'
        );
        $this->assertNotSame($partyA->id, $partyOther->id);
        $this->assertSame($otherCompany->id, $partyOther->company_id);

        // Restore context for next tests
        $this->activate($this->owner);
    }

    public function test_quotation_quick_customer_creation_draft_continuity(): void
    {
        $component = Livewire::test(QuotationForm::class);

        // User enters quotation line with custom unit price
        $component->set('lines.0.product_id', $this->product->id);
        $component->set('lines.0.quantity', '4');
        $component->set('lines.0.unit_price', '55.00');

        // User opens quick customer modal
        $component->call('openQuickCustomerModal');
        $component->assertSet('showQuickCustomerModal', true);

        // Cancelling modal preserves draft lines and prices
        $component->call('closeQuickCustomerModal');
        $component->assertSet('showQuickCustomerModal', false);
        $component->assertSet('lines.0.quantity', '4');
        $component->assertSet('lines.0.unit_price', '55.00');

        // User opens quick customer modal again and creates English-only customer
        $component->call('openQuickCustomerModal');
        $component->set('quick_customer_name_ar', '');
        $component->set('quick_customer_name_en', 'Quote Fast Customer');
        $component->set('quick_customer_phone', '0599777888');
        $component->call('saveQuickCustomer');
        $component->assertHasNoErrors();
        $component->assertSet('showQuickCustomerModal', false);

        // Newly created customer is selected
        $newCustomer = Customer::where('name_en', 'Quote Fast Customer')->firstOrFail();
        $component->assertSet('customer_id', $newCustomer->id);

        // Draft lines and custom prices remain completely intact
        $component->assertSet('lines.0.quantity', '4');
        $component->assertSet('lines.0.unit_price', '55.00');
    }

    public function test_foreign_currency_and_fx_preservation_during_quick_create(): void
    {
        // 1. Invoice with USD foreign currency
        $invoiceComponent = Livewire::test(InvoiceForm::class);
        $invoiceComponent->set('currency_code', 'USD');
        $invoiceComponent->set('exchange_rate', '3.7500000000');
        $invoiceComponent->set('lines.0.product_id', $this->product->id);
        $invoiceComponent->set('lines.0.quantity', '10');
        $invoiceComponent->set('lines.0.unit_price', '15.00');

        // Open and cancel quick customer modal
        $invoiceComponent->call('openQuickCustomerModal');
        $invoiceComponent->call('closeQuickCustomerModal');
        $invoiceComponent->assertSet('currency_code', 'USD');
        $invoiceComponent->assertSet('exchange_rate', '3.7500000000');
        $invoiceComponent->assertSet('lines.0.quantity', '10');
        $invoiceComponent->assertSet('lines.0.unit_price', '15.00');

        // Create customer via quick modal
        $invoiceComponent->call('openQuickCustomerModal');
        $invoiceComponent->set('quick_customer_name_ar', '');
        $invoiceComponent->set('quick_customer_name_en', 'USD Client Corp');
        $invoiceComponent->call('saveQuickCustomer');
        $invoiceComponent->assertHasNoErrors();

        // Foreign currency, exchange rate, and lines preserved
        $invoiceComponent->assertSet('currency_code', 'USD');
        $invoiceComponent->assertSet('exchange_rate', '3.7500000000');
        $invoiceComponent->assertSet('lines.0.quantity', '10');
        $invoiceComponent->assertSet('lines.0.unit_price', '15.00');

        // 2. Purchase with USD foreign currency
        $purchaseComponent = Livewire::test(PurchaseForm::class);
        $purchaseComponent->set('currency_code', 'USD');
        $purchaseComponent->set('exchange_rate', '3.7500000000');
        $purchaseComponent->set('lines.0.product_id', $this->product->id);
        $purchaseComponent->set('lines.0.quantity', '8');
        $purchaseComponent->set('lines.0.unit_cost', '12.00');

        // Open and cancel quick vendor modal
        $purchaseComponent->call('openQuickVendorModal');
        $purchaseComponent->call('closeQuickVendorModal');
        $purchaseComponent->assertSet('currency_code', 'USD');
        $purchaseComponent->assertSet('exchange_rate', '3.7500000000');
        $purchaseComponent->assertSet('lines.0.quantity', '8');
        $purchaseComponent->assertSet('lines.0.unit_cost', '12.00');

        // Create vendor via quick modal
        $purchaseComponent->call('openQuickVendorModal');
        $purchaseComponent->set('quick_vendor_name_ar', '');
        $purchaseComponent->set('quick_vendor_name_en', 'USD Overseas Supplier');
        $purchaseComponent->call('saveQuickVendor');
        $purchaseComponent->assertHasNoErrors();

        // Foreign currency, exchange rate, and lines preserved
        $purchaseComponent->assertSet('currency_code', 'USD');
        $purchaseComponent->assertSet('exchange_rate', '3.7500000000');
        $purchaseComponent->assertSet('lines.0.quantity', '8');
        $purchaseComponent->assertSet('lines.0.unit_cost', '12.00');
    }

    public function test_purchase_and_return_read_models_preserve_english_only_and_zero_names_in_arabic(): void
    {
        // 1. English-only vendor
        $englishVendor = app(VendorCatalogService::class)->save($this->company, $this->owner, [
            'name_ar' => null,
            'name_en' => 'English Industrial Co',
            'status' => 'active',
        ]);

        $purchase = $this->createAndPostPurchase(['vendor_id' => $englishVendor->id]);
        $return = $this->createAndPostReturn($purchase);

        app()->setLocale('ar');
        $readModel = app(PurchaseReadModel::class);
        $detail = $readModel->detail($purchase, true);
        $this->assertSame('English Industrial Co', $detail['vendor_name']);

        $returnReadModel = app(PurchaseReturnReadModel::class);
        $returnDetail = $returnReadModel->detail($return, true);
        $this->assertSame('English Industrial Co', $returnDetail['vendor_name']);

        // 2. Vendor with name "0"
        $zeroVendor = app(VendorCatalogService::class)->save($this->company, $this->owner, [
            'name_ar' => '0',
            'name_en' => null,
            'status' => 'active',
        ]);
        $zeroPurchase = $this->createAndPostPurchase(['vendor_id' => $zeroVendor->id]);
        $zeroReturn = $this->createAndPostReturn($zeroPurchase);

        app()->setLocale('ar');
        $zeroDetail = $readModel->detail($zeroPurchase, true);
        $this->assertSame('0', $zeroDetail['vendor_name']);

        $zeroReturnDetail = $returnReadModel->detail($zeroReturn, true);
        $this->assertSame('0', $zeroReturnDetail['vendor_name']);

        app()->setLocale('en');
        $zeroDetailEn = $readModel->detail($zeroPurchase, true);
        $this->assertSame('0', $zeroDetailEn['vendor_name']);
        app()->setLocale('ar');
    }

    public function test_global_search_preserves_zero_names_and_english_only_titles(): void
    {
        $zeroCustomer = app(CustomerCatalogService::class)->save($this->company, $this->owner, [
            'name_ar' => '0',
            'code' => 'Z-CUST-1',
            'status' => 'active',
        ]);

        $englishCustomer = app(CustomerCatalogService::class)->save($this->company, $this->owner, [
            'name_ar' => null,
            'name_en' => 'English Search Customer',
            'code' => 'ENG-CUST-1',
            'status' => 'active',
        ]);

        $zeroVendor = app(VendorCatalogService::class)->save($this->company, $this->owner, [
            'name_ar' => '0',
            'code' => 'Z-VEND-1',
            'status' => 'active',
        ]);

        $search = app(GlobalSearch::class);

        // Search for zero customer
        $results = $search->search('Z-CUST');
        $this->assertNotEmpty($results['customer'] ?? []);
        $this->assertSame('0', $results['customer'][0]['title']);

        // Search for English customer
        $resultsEng = $search->search('ENG-CUST');
        $this->assertNotEmpty($resultsEng['customer'] ?? []);
        $this->assertSame('English Search Customer', $resultsEng['customer'][0]['title']);

        // Search for zero vendor
        $resultsVend = $search->search('Z-VEND');
        $this->assertNotEmpty($resultsVend['vendor'] ?? []);
        $this->assertSame('0', $resultsVend['vendor'][0]['title']);
    }

    public function test_synthetic_english_only_vendor_payment_post_and_reconciliation(): void
    {
        $englishVendor = app(VendorCatalogService::class)->save($this->company, $this->owner, [
            'name_ar' => null,
            'name_en' => 'Direct Overseas Supplies',
            'status' => 'active',
        ]);

        $purchase = $this->createAndPostPurchase([
            'vendor_id' => $englishVendor->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '5',
                    'unit_cost' => '20',
                ],
            ],
        ]);

        // Post vendor payment against the purchase
        $payment = app(PostVendorPaymentAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $englishVendor->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-02',
            'payment_method' => 'cash',
            'amount' => '100.000000',
            'exchange_rate' => '1.0000000000',
            'document_locale' => 'ar',
            'idempotency_key' => (string) Str::uuid(),
            'allocations' => [
                [
                    'purchase_id' => $purchase->id,
                    'allocated_amount' => '100.000000',
                ],
            ],
        ]);

        $this->assertTrue($payment->isPosted());
        $this->assertNotNull($payment->postingBatch);

        // Examine the exact posting batch line memo
        $lines = $payment->postingBatch->lines()->orderBy('id')->get();
        $this->assertCount(2, $lines);
        $debitAp = $lines->firstWhere('debit_base', '100.000000');
        $creditCash = $lines->firstWhere('credit_base', '100.000000');
        $this->assertNotNull($debitAp);
        $this->assertNotNull($creditCash);

        // In Arabic document_locale, VendorPaymentHistoryCommands line 58 produces "Vendor Payment {$number} - "
        $this->assertSame("Vendor Payment {$payment->payment_number} - ", $creditCash->description);
        $this->assertSame("Vendor Payment {$payment->payment_number} AP Relief Pur #{$purchase->id}", $debitAp->description);

        // Reconciliation passes
        $recon = app(PayablesReconciliationService::class)->reconcile($this->company);
        $this->assertTrue($recon->isHealthy);
    }

    public function test_type_validation_rejects_malformed_array_names_safely(): void
    {
        // CustomerCatalogService
        try {
            app(CustomerCatalogService::class)->save($this->company, $this->owner, [
                'name_ar' => ['malformed array'],
                'name_en' => 'Valid Name',
            ]);
            $this->fail('Expected ValidationException for array name_ar.');
        } catch (ValidationException $e) {
            $this->assertTrue($e->validator->errors()->has('name_ar'));
        }

        // VendorCatalogService
        try {
            app(VendorCatalogService::class)->save($this->company, $this->owner, [
                'name_ar' => ['malformed array'],
                'name_en' => 'Valid Name',
            ]);
            $this->fail('Expected ValidationException for array name_ar in vendor.');
        } catch (ValidationException $e) {
            $this->assertTrue($e->validator->errors()->has('name_ar'));
        }

        // Saving "0" as sole name works in both
        $zeroCust = app(CustomerCatalogService::class)->save($this->company, $this->owner, [
            'name_ar' => '0',
            'name_en' => null,
        ]);
        $this->assertSame('0', $zeroCust->name_ar);

        $zeroVend = app(VendorCatalogService::class)->save($this->company, $this->owner, [
            'name_ar' => '0',
            'name_en' => null,
        ]);
        $this->assertSame('0', $zeroVend->name_ar);
    }

    public function test_form_validation_error_recovery_and_zero_in_advanced_fields(): void
    {
        // CustomerForm with invalid email and overlong business_name_en shows open details and error
        $customerComponent = Livewire::test(CustomerForm::class)
            ->set('name_en', 'Testing Corp')
            ->set('email', 'not-an-email')
            ->set('business_name_en', str_repeat('A', 256))
            ->call('save')
            ->assertHasErrors(['email', 'business_name_en']);

        $customerHtml = $customerComponent->html();
        $this->assertStringContainsString('<details class="group space-y-6 pt-2" open', $customerHtml);
        $this->assertStringContainsString('id="customer-email-error"', $customerHtml);
        $this->assertStringContainsString('aria-describedby="customer-business-name-en-error"', $customerHtml);
        $this->assertStringContainsString('id="customer-business-name-en-error"', $customerHtml);

        // CustomerForm with "0" in advanced field notes renders details as open
        $zeroNotesComponent = Livewire::test(CustomerForm::class)
            ->set('notes', '0');
        $this->assertStringContainsString('<details class="group space-y-6 pt-2" open', $zeroNotesComponent->html());

        // An invalid advanced vendor field must reopen its disclosure.
        $vendorComponent = Livewire::test(VendorForm::class)
            ->set('name_en', 'Vendor Corp')
            ->set('business_name_en', str_repeat('V', 256))
            ->call('save')
            ->assertHasErrors(['business_name_en']);

        $vendorHtml = $vendorComponent->html();
        $this->assertStringContainsString('<details class="group space-y-4 pt-1" open', $vendorHtml);
    }

    public function test_migration_both_tables_roundtrip_and_vendor_null_rollback_refusal(): void
    {
        $migration = require database_path('migrations/2026_10_28_100000_allow_nullable_customer_and_vendor_name_ar.php');

        // 1. Vendor-only null name_ar refusal
        $nullVendor = Vendor::create([
            'company_id' => $this->company->id,
            'name_ar' => null,
            'name_en' => 'Nullable Test Vendor',
            'created_by' => $this->owner->id,
        ]);

        try {
            $migration->down();
            $this->fail('Migration down() did not refuse rollback when null vendor name_ar exists.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Cannot reverse migration', $e->getMessage());
        }

        // 2. Soft-deleted vendor null name_ar refusal
        $nullVendor->delete();
        $this->assertTrue(Vendor::withTrashed()->where('id', $nullVendor->id)->exists());

        try {
            $migration->down();
            $this->fail('Migration down() did not refuse rollback for soft-deleted null vendor name_ar.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Cannot reverse migration', $e->getMessage());
        }

        $nullVendor->forceDelete();

        // 3. Safe down and up roundtrip when no null records exist
        // Clean out any lingering null name_ar in disposable DB
        Customer::whereNull('name_ar')->forceDelete();
        Vendor::whereNull('name_ar')->forceDelete();

        $migration->down();
        foreach (['customers', 'vendors'] as $table) {
            $column = DB::selectOne("SELECT IS_NULLABLE AS nullable_value FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'name_ar'", [$table]);
            $this->assertSame('NO', $column->nullable_value);
        }

        $migration->up();
        foreach (['customers', 'vendors'] as $table) {
            $column = DB::selectOne("SELECT IS_NULLABLE AS nullable_value FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'name_ar'", [$table]);
            $this->assertSame('YES', $column->nullable_value);
        }
    }

    public function test_new_party_forms_keep_advanced_details_collapsed_without_errors(): void
    {
        foreach ([CustomerForm::class, VendorForm::class] as $form) {
            $html = Livewire::test($form)->html();
            $this->assertMatchesRegularExpression('/<details[^>]*>/', $html);
            $this->assertDoesNotMatchRegularExpression('/<details[^>]*\sopen(?:\s|>)/', $html);
        }
    }
}
