<?php

declare(strict_types=1);

namespace Tests\Feature\Phase5A;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Purchasing\EnsurePurchasingFoundationAction;
use App\Actions\Sales\SaveTaxRateAction;
use App\Domain\Sales\Calculators\SalesLineCalculationInput;
use App\Domain\Sales\Calculators\SalesLineCalculator;
use App\Exceptions\CompanyReassignmentException;
use App\Livewire\Pages\Purchasing\Settings\PurchaseSettingsForm;
use App\Livewire\Pages\Purchasing\VendorDetail;
use App\Livewire\Pages\Purchasing\VendorForm;
use App\Livewire\Pages\Purchasing\VendorIndex;
use App\Livewire\Pages\Sales\Settings\TaxRateSettings;
use App\Models\Company;
use App\Models\CompanyPurchaseSetting;
use App\Models\DocumentSequence;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Services\Purchasing\PurchaseSettingsService;
use App\Services\Purchasing\VendorCatalogService;
use App\Services\Tenancy\CompanyRoleService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PurchasingFoundationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        app(CompanyContext::class)->clear();
        $this->owner = User::factory()->create(['locale' => 'ar']);
        $this->company = app(CreateCompanyAction::class)->execute($this->owner, [
            'name_ar' => 'شركة الاختبار', 'name_en' => 'Test Company', 'base_currency_code' => 'ILS',
        ]);
        $this->activate();
    }

    private function activate(?Company $company = null, ?User $user = null): void
    {
        $company ??= $this->company;
        $user ??= $this->owner;
        app(CompanyContext::class)->setCompany($company, $user);
        $this->actingAs($user);
        setPermissionsTeamId($company->id);
        $user->unsetRelation('roles')->unsetRelation('permissions');
    }

    private function otherCompany(): Company
    {
        app(CompanyContext::class)->clear();
        $other = app(CreateCompanyAction::class)->execute(User::factory()->create(), ['name_ar' => 'شركة أخرى']);
        $this->activate();

        return $other;
    }

    private function vendor(array $data = []): Vendor
    {
        return app(VendorCatalogService::class)->save($this->company, $this->owner, array_replace([
            'name_ar' => 'مورد الأدوات', 'name_en' => 'Tools Supplier', 'code' => ' v-001 ',
            'email' => 'vendor@example.test', 'preferred_locale' => 'ar', 'default_currency_code' => 'ILS',
        ], $data));
    }

    private function customActor(array $permissions): User
    {
        $user = User::factory()->create(['locale' => 'ar', 'last_active_company_id' => $this->company->id]);
        $this->company->users()->attach($user->id, ['status' => 'active', 'is_owner' => false, 'joined_at' => now()]);
        $role = Role::create(['name' => 'CustomPurchasingRole', 'guard_name' => 'web', 'company_id' => $this->company->id]);
        $role->syncPermissions($permissions);
        $user->assignRole($role);
        $this->activate($this->company, $user);

        return $user;
    }

    private function taxData(): array
    {
        return ['code' => 'VAT16', 'name_ar' => 'ضريبة', 'rate' => '16.000000', 'active' => true,
            'calculation' => 'exclusive', 'sales_tax_account_id' => LedgerAccount::where('system_key', 'tax_output')->value('id')];
    }

    public function test_vendor_schema_normalization_lifecycle_and_audit(): void
    {
        $vendor = $this->vendor(['phone' => '0591234567', 'tax_number' => 'T123', 'city_ar' => 'الخليل', 'country_code' => 'ps']);
        $this->assertSame('V-001', $vendor->code);
        $this->assertSame('PS', $vendor->country_code);
        $this->assertTrue(Str::isUlid($vendor->public_id));
        $this->assertSame($this->owner->id, $vendor->created_by);
        $this->assertSame('مورد الأدوات', $vendor->displayName());
        app()->setLocale('en');
        $this->assertSame('Tools Supplier', $vendor->displayName());
        $changed = app(VendorCatalogService::class)->save($this->company, $this->owner, ['name_ar' => 'مورد الأدوات', 'active' => false], $vendor->id);
        $this->assertSame('inactive', $changed->status);
        $this->assertSame($this->owner->id, $changed->updated_by);
        $this->assertSame(2, DB::table('audit_events')->where('event_key', 'vendor.saved')->count());
        $changed->delete();
        $this->assertSoftDeleted('vendors', ['id' => $vendor->id]);
        $this->assertNotNull(Vendor::withTrashed()->find($vendor->id));
        $this->assertDatabaseCount('posting_batches', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_vendor_code_uniqueness_is_company_scoped_and_null_codes_are_allowed(): void
    {
        $this->vendor();
        try {
            $this->vendor(['code' => 'v-001']);
            $this->fail('Duplicate normalized code was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('code', $exception->errors());
        }
        $this->vendor(['code' => ' ']);
        $this->vendor(['code' => null]);
        $other = $this->otherCompany();
        $otherOwner = $other->users()->firstOrFail();
        $this->activate($other, $otherOwner);
        $vendor = app(VendorCatalogService::class)->save($other, $otherOwner, ['name_ar' => 'مورد آخر', 'code' => 'v-001']);
        $this->assertSame('V-001', $vendor->code);
        $this->assertSame($other->id, $vendor->company_id);
        $this->assertSame(4, DB::table('vendors')->count());
    }

    public static function invalidVendorData(): array
    {
        return [
            'unknown currency' => ['default_currency_code', 'EUR'],
            'disabled currency' => ['default_currency_code', 'USD'],
            'unknown language' => ['preferred_locale', 'fr'],
            'disabled language' => ['preferred_locale', 'en'],
            'email' => ['email', 'not-an-email'],
            'status' => ['status', 'closed'],
            'country' => ['country_code', 'USA'],
            'missing name' => ['name_ar', ' '],
        ];
    }

    #[DataProvider('invalidVendorData')]
    public function test_vendor_authoritative_validation(string $field, string $value): void
    {
        $this->company->currencies()->where('currency_code', 'USD')->update(['enabled' => false]);
        $this->company->languages()->where('locale', 'en')->update(['enabled' => false]);
        try {
            $this->vendor([$field => $value]);
            $this->fail('Invalid Vendor data accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
        $this->assertDatabaseCount('vendors', 0);
    }

    public function test_model_defends_company_ownership_and_enabled_preferences(): void
    {
        $vendor = $this->vendor();
        $other = $this->otherCompany();
        try {
            $vendor->update(['company_id' => $other->id]);
            $this->fail('Vendor reassignment allowed.');
        } catch (CompanyReassignmentException $exception) {
            $this->assertSame($this->company->id, $vendor->fresh()->company_id);
        }
        try {
            $vendor->fresh()->update(['preferred_locale' => 'fr']);
            $this->fail('Model accepted invalid locale.');
        } catch (ValidationException $exception) {
            $this->assertSame('ar', $vendor->fresh()->preferred_locale);
        }
    }

    public function test_foreign_vendor_read_and_mutation_fail_without_disclosure(): void
    {
        $vendor = $this->vendor();
        $other = $this->otherCompany();
        $otherOwner = $other->users()->firstOrFail();
        $this->activate($other, $otherOwner);
        $this->get('/vendors/'.$vendor->public_id)->assertNotFound();
        $this->get('/vendors/'.$vendor->public_id.'/edit')->assertNotFound();
        $this->assertNull(Vendor::find($vendor->id));
        try {
            app(VendorCatalogService::class)->save($other, $otherOwner, ['name_ar' => 'تم العبث'], $vendor->id);
            $this->fail('Foreign Vendor updated.');
        } catch (ModelNotFoundException $exception) {
            $this->assertDatabaseHas('vendors', ['id' => $vendor->id, 'name_ar' => 'مورد الأدوات']);
        }
    }

    public function test_vendor_index_search_filter_and_authorized_routes(): void
    {
        $vendor = $this->vendor(['phone' => '0591234567']);
        $this->vendor(['code' => 'V2', 'name_ar' => 'مورد غير نشط', 'active' => false]);
        Livewire::test(VendorIndex::class)->set('search', '0591234567')->assertSee('مورد الأدوات')->assertDontSee('مورد غير نشط');
        Livewire::test(VendorIndex::class)->set('statusFilter', 'inactive')->assertSee('مورد غير نشط')->assertDontSee('مورد الأدوات');
        $this->get('/vendors')->assertOk();
        $this->get('/vendors/create')->assertOk();
        $this->get('/vendors/'.$vendor->public_id)->assertOk();
        $this->get('/vendors/'.$vendor->public_id.'/edit')->assertOk();
        $actor = $this->customActor(['vendors.view']);
        $this->get('/vendors')->assertOk()->assertDontSee(route('vendors.create'), false);
        $this->get('/vendors/create')->assertForbidden();
        $this->get('/vendors/'.$vendor->public_id.'/edit')->assertForbidden();
        try {
            app(VendorCatalogService::class)->save($this->company, $actor, ['name_ar' => 'ممنوع']);
            $this->fail('View-only actor mutated Vendor.');
        } catch (AuthorizationException $exception) {
            $this->assertDatabaseCount('vendors', 2);
        }
    }

    public static function staleChanges(): array
    {
        return [['permission'], ['membership'], ['company']];
    }

    #[DataProvider('staleChanges')]
    public function test_stale_vendor_form_cannot_mutate_after_authority_changes(string $change): void
    {
        $actor = $this->customActor(['vendors.manage', 'vendors.view']);
        $form = Livewire::test(VendorForm::class)->set('name_ar', 'لا يجب الحفظ');
        $this->revoke($actor, $change, 'vendors.manage');
        $form->call('save')->assertForbidden();
        $this->assertDatabaseCount('vendors', 0);
    }

    private function revoke(User $actor, string $change, string $permission): void
    {
        if ($change === 'permission') {
            $actor->roles()->firstOrFail()->revokePermissionTo($permission);
        } elseif ($change === 'membership') {
            DB::table('company_user')->where('company_id', $this->company->id)->where('user_id', $actor->id)->update(['status' => 'inactive']);
        } else {
            $other = $this->otherCompany();
            $other->users()->attach($actor->id, ['status' => 'active', 'is_owner' => false]);
            $this->activate($other, $actor);
        }
    }

    public function test_stale_vendor_detail_cannot_read_after_permission_revocation(): void
    {
        $vendor = $this->vendor();
        $actor = $this->customActor(['vendors.view']);
        $detail = Livewire::test(VendorDetail::class, ['publicId' => $vendor->public_id]);
        $this->revoke($actor, 'permission', 'vendors.view');
        $detail->call('$refresh')->assertForbidden();
    }

    public function test_service_rejects_inactive_membership_and_unauthenticated_actor(): void
    {
        DB::table('company_user')->where('company_id', $this->company->id)->update(['status' => 'inactive']);
        try {
            $this->vendor();
            $this->fail('Inactive membership accepted.');
        } catch (AuthorizationException $exception) {
            $this->assertDatabaseCount('vendors', 0);
        }
        DB::table('company_user')->where('company_id', $this->company->id)->update(['status' => 'active']);
        auth()->logout();
        $this->expectException(AuthorizationException::class);
        $this->vendor();
    }

    public function test_purchase_settings_are_bounded_and_save_scoped_defaults(): void
    {
        $warehouse = Warehouse::firstOrFail();
        $settings = app(PurchaseSettingsService::class)->save($this->company, $this->owner, [
            'default_payment_terms_days' => 30, 'default_receiving_warehouse_id' => $warehouse->id,
            'warn_duplicate_vendor_invoice' => false,
        ]);
        $this->assertSame(30, $settings->default_payment_terms_days);
        $this->assertSame($warehouse->id, $settings->default_receiving_warehouse_id);
        $this->assertFalse($settings->warn_duplicate_vendor_invoice);
        $this->assertDatabaseHas('audit_events', ['event_key' => 'settings.purchases.saved']);
        $this->assertDatabaseCount('posting_batches', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public static function invalidSettings(): array
    {
        return [['foreign'], ['inactive'], ['negative'], ['too large'], ['fraction']];
    }

    #[DataProvider('invalidSettings')]
    public function test_invalid_purchase_settings_leave_exact_state_unchanged(string $case): void
    {
        $warehouse = Warehouse::firstOrFail();
        $terms = 30;
        if ($case === 'foreign') {
            $other = $this->otherCompany();
            $warehouseId = DB::table('warehouses')->where('company_id', $other->id)->value('id');
        } elseif ($case === 'inactive') {
            $warehouse->update(['active' => false]);
            $warehouseId = $warehouse->id;
        } else {
            $warehouseId = $warehouse->id;
            $terms = match ($case) {
                'negative' => -1, 'too large' => 3651, default => '1.5'
            };
        }
        $before = DB::table('company_purchase_settings')->get()->toJson();
        try {
            app(PurchaseSettingsService::class)->save($this->company, $this->owner, [
                'default_payment_terms_days' => $terms, 'default_receiving_warehouse_id' => $warehouseId, 'warn_duplicate_vendor_invoice' => false,
            ]);
            $this->fail('Invalid settings accepted.');
        } catch (InvalidArgumentException|ValidationException $exception) {
            $this->assertSame($before, DB::table('company_purchase_settings')->get()->toJson());
        }
    }

    #[DataProvider('staleChanges')]
    public function test_stale_purchase_settings_lose_authority(string $change): void
    {
        $actor = $this->customActor(['settings.purchases.manage']);
        $form = Livewire::test(PurchaseSettingsForm::class);
        $this->revoke($actor, $change, 'settings.purchases.manage');
        $before = DB::table('company_purchase_settings')->get()->toJson();
        $form->call('save')->assertForbidden();
        $this->assertSame($before, DB::table('company_purchase_settings')->get()->toJson());
    }

    public function test_custom_settings_permission_has_no_role_name_or_cost_bypass(): void
    {
        $actor = $this->customActor(['settings.purchases.manage']);
        $this->get('/settings/purchases')->assertOk();
        $this->get('/vendors')->assertForbidden();
        $this->assertFalse($actor->hasPermissionTo('inventory.cost.view'));
        Livewire::test(PurchaseSettingsForm::class)->set('default_payment_terms_days', 14)->call('save')->assertHasNoErrors();
        $this->assertDatabaseHas('company_purchase_settings', ['company_id' => $this->company->id, 'default_payment_terms_days' => 14]);
    }

    public function test_purchase_settings_lazy_creation_and_unauthorized_service(): void
    {
        CompanyPurchaseSetting::where('company_id', $this->company->id)->delete();
        Livewire::test(PurchaseSettingsForm::class)->assertSet('warn_duplicate_vendor_invoice', true);
        $this->assertDatabaseCount('company_purchase_settings', 1);
        $actor = $this->customActor([]);
        $this->get('/settings/purchases')->assertForbidden();
        $this->expectException(AuthorizationException::class);
        app(PurchaseSettingsService::class)->save($this->company, $actor, ['warn_duplicate_vendor_invoice' => false]);
    }

    public function test_input_tax_configuration_preserves_sales_percentage_semantics_and_can_clear(): void
    {
        $input = LedgerAccount::where('system_key', 'tax_input')->firstOrFail();
        $tax = app(SaveTaxRateAction::class)->execute($this->company, $this->owner, $this->taxData() + ['purchase_tax_account_id' => $input->id]);
        $this->assertSame($input->id, $tax->purchase_tax_account_id);
        $this->assertSame('16.000000', $tax->rate);
        $result = app(SalesLineCalculator::class)->calculate(new SalesLineCalculationInput('1', '100', taxRate: $tax->rate));
        $this->assertSame('16.000000', (string) $result->tax);
        $this->assertSame('116.000000', (string) $result->total);
        $inclusive = app(SalesLineCalculator::class)->calculate(new SalesLineCalculationInput('1', '116', taxRate: $tax->rate, taxInclusive: true));
        $this->assertSame('100.000000', (string) $inclusive->netBeforeTax);
        Livewire::test(TaxRateSettings::class)->assertSee('16.00%')->assertDontSee('1600%')->call('editTaxRate', $tax->id)->assertSet('purchase_tax_account_id', $input->id);
        $tax = app(SaveTaxRateAction::class)->execute($this->company, $this->owner, $this->taxData() + ['purchase_tax_account_id' => null], $tax->id);
        $this->assertNull($tax->purchase_tax_account_id);
    }

    public function test_input_tax_direct_child_valid_and_omitted_field_preserves_existing_configuration(): void
    {
        $input = LedgerAccount::where('system_key', 'tax_input')->firstOrFail();
        $child = LedgerAccount::create(['company_id' => $this->company->id, 'code' => 'INPUT-CHILD', 'name_ar' => 'مدخلات', 'parent_id' => $input->id,
            'account_type' => 'asset', 'normal_balance' => 'debit', 'is_control' => false, 'active' => true]);
        $tax = app(SaveTaxRateAction::class)->execute($this->company, $this->owner, $this->taxData() + ['purchase_tax_account_id' => $child->id]);
        $again = app(SaveTaxRateAction::class)->execute($this->company, $this->owner, $this->taxData(), $tax->id);
        $this->assertSame($child->id, $again->purchase_tax_account_id);
    }

    public static function invalidInputAccounts(): array
    {
        return [['foreign'], ['output'], ['inactive'], ['control'], ['unrelated'], ['wrong balance'], ['zero']];
    }

    #[DataProvider('invalidInputAccounts')]
    public function test_forged_input_tax_accounts_are_rejected_atomically(string $case): void
    {
        $input = LedgerAccount::where('system_key', 'tax_input')->firstOrFail();
        if ($case === 'foreign') {
            $other = $this->otherCompany();
            $id = DB::table('ledger_accounts')->where('company_id', $other->id)->where('system_key', 'tax_input')->value('id');
        } elseif ($case === 'output') {
            $id = LedgerAccount::where('system_key', 'tax_output')->value('id');
        } elseif ($case === 'unrelated') {
            $id = LedgerAccount::where('system_key', 'inventory')->value('id');
        } elseif ($case === 'zero') {
            $id = 0;
        } else {
            $input->update(match ($case) {
                'inactive' => ['active' => false], 'control' => ['is_control' => true], default => ['normal_balance' => 'credit']
            });
            $id = $input->id;
        }
        try {
            app(SaveTaxRateAction::class)->execute($this->company, $this->owner, $this->taxData() + ['purchase_tax_account_id' => $id]);
            $this->fail('Forged Input Tax account accepted.');
        } catch (InvalidArgumentException|ValidationException $exception) {
            $this->assertDatabaseCount('tax_rates', 0);
        }
        $this->assertDatabaseCount('posting_batches', 0);
    }

    public function test_foundation_upgrade_preserves_custom_roles_settings_and_sequence_values(): void
    {
        $sales = DocumentSequence::where('document_type', 'sales_invoice')->firstOrFail();
        $sales->update(['next_number' => 42, 'prefix' => 'CUSTOM']);
        $purchase = DocumentSequence::where('document_type', 'purchase')->firstOrFail();
        $purchase->update(['prefix' => 'BILL', 'next_number' => 12, 'year' => 0, 'reset_policy' => 'never']);
        $manager = Role::where('company_id', $this->company->id)->where('name', 'Manager')->firstOrFail();
        $manager->syncPermissions(['vendors.view']);
        $ownerRole = Role::where('company_id', $this->company->id)->where('name', 'Owner')->firstOrFail();
        $ownerRole->revokePermissionTo('settings.purchases.manage');
        CompanyPurchaseSetting::where('company_id', $this->company->id)->update(['warn_duplicate_vendor_invoice' => false]);
        $before = DB::table('document_sequences')->get()->toJson();
        app(CompanyContext::class)->clear();
        $this->assertSame(0, Artisan::call('purchasing:bootstrap', ['--all' => true]));
        $this->assertSame(0, Artisan::call('purchasing:bootstrap', ['companyPublicId' => $this->company->public_id]));
        $this->activate();
        $this->assertSame($before, DB::table('document_sequences')->get()->toJson());
        $this->assertSame(['vendors.view'], $manager->fresh()->permissions->pluck('name')->all());
        $this->assertTrue($ownerRole->fresh()->hasPermissionTo('settings.purchases.manage'));
        $this->assertFalse(CompanyPurchaseSetting::firstOrFail()->warn_duplicate_vendor_invoice);
        $this->assertDatabaseCount('vendors', 0);
        foreach (['posting_batches', 'posting_lines', 'stock_movements', 'customers', 'sales_invoices', 'tax_rates'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_existing_company_configuration_is_provisioned_without_consuming_numbers(): void
    {
        DocumentSequence::whereIn('document_type', ['purchase', 'purchase_return', 'vendor_payment'])->delete();
        CompanyPurchaseSetting::query()->delete();
        $salesBefore = DB::table('document_sequences')->get()->toJson();
        app(CompanyContext::class)->clear();
        app(EnsurePurchasingFoundationAction::class)->execute($this->company);
        app(EnsurePurchasingFoundationAction::class)->execute($this->company);
        $this->activate();
        $this->assertSame($salesBefore, DB::table('document_sequences')->whereIn('document_type', ['quotation', 'sales_invoice', 'sales_return', 'customer_payment'])->get()->toJson());
        $this->assertDatabaseCount('document_sequences', 7);
        $this->assertDatabaseCount('company_purchase_settings', 1);
        foreach (['purchase' => 'PUR', 'purchase_return' => 'PRT', 'vendor_payment' => 'VPM'] as $type => $prefix) {
            $this->assertDatabaseHas('document_sequences', ['company_id' => $this->company->id, 'document_type' => $type, 'prefix' => $prefix, 'next_number' => 1]);
        }
        $before = DB::table('document_sequences')->get()->toJson();
        $this->get('/vendors')->assertOk();
        $this->get('/settings/purchases')->assertOk();
        $this->get('/settings/sequences')->assertOk();
        $this->assertSame($before, DB::table('document_sequences')->get()->toJson());
    }

    public function test_bootstrap_rejects_ambient_tenant_and_static_catalog_remains_bounded(): void
    {
        $this->assertSame(1, Artisan::call('purchasing:bootstrap', ['--all' => true]));
        $this->assertContains('settings.purchases.manage', CompanyRoleService::PERMISSIONS);
        $purchasing = Role::where('company_id', $this->company->id)->where('name', 'Purchasing')->firstOrFail();
        $this->assertFalse($purchasing->hasPermissionTo('inventory.cost.view'));
        $this->assertFalse($purchasing->hasPermissionTo('settings.purchases.manage'));
        $this->assertTrue($purchasing->hasPermissionTo('vendors.manage'));
    }

    public static function locales(): array
    {
        return [['ar'], ['en']];
    }

    #[DataProvider('locales')]
    public function test_real_vendor_and_settings_views_are_localized_without_cost_payloads(string $locale): void
    {
        $this->owner->update(['locale' => $locale]);
        app()->setLocale($locale);
        $vendor = $this->vendor();
        Livewire::test(VendorIndex::class)->assertSee(__('purchasing.add_vendor'))->assertSee($vendor->displayName());
        Livewire::test(VendorForm::class)->assertSee(__('purchasing.tax_number'))->assertSee(__('purchasing.save_vendor'));
        Livewire::test(VendorDetail::class, ['publicId' => $vendor->public_id])->assertSee(__('purchasing.vendor_details'));
        Livewire::test(PurchaseSettingsForm::class)->assertSee(__('purchasing.warn_duplicate_vendor_invoice'));
        $response = $this->get('/vendors');
        $response->assertOk()->assertSee('dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'"', false);
        $response->assertDontSee('cogs_total_base')->assertDontSee('average_cost_base')->assertDontSee('outstanding_balance');
        if ($locale === 'ar') {
            Livewire::test(VendorForm::class)->assertDontSee('Save Vendor')->assertDontSee('Preferred Document Language');
        }
        $this->assertArrayHasKey('settings.purchases.manage', array_fill_keys(CompanyRoleService::PERMISSIONS, true));
    }

    public function test_vendor_form_persists_all_optional_fields_and_can_edit_inactive_master(): void
    {
        Livewire::test(VendorForm::class)->set('name_ar', 'مورد جديد')->set('name_en', 'New Supplier')
            ->set('code', ' code-2 ')->set('phone', '0598887777')->set('tax_number', 'TX-1')
            ->set('address_ar', 'الخليل')->set('city_en', 'Hebron')->set('postal_code', '12345')
            ->set('preferred_locale', 'en')->set('default_currency_code', 'USD')->set('notes', 'ملاحظة')
            ->set('active', false)->call('save')->assertHasNoErrors();
        $vendor = Vendor::firstOrFail();
        $this->assertSame('CODE-2', $vendor->code);
        $this->assertSame('inactive', $vendor->status);
        $this->assertSame('TX-1', $vendor->tax_number);
        Livewire::test(VendorForm::class, ['publicId' => $vendor->public_id])->set('active', true)->call('save')->assertHasNoErrors();
        $this->assertSame('active', $vendor->fresh()->status);
    }
}
