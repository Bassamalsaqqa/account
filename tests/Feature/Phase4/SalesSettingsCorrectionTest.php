<?php

declare(strict_types=1);

namespace Tests\Feature\Phase4;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\CreateSalesReturnDraftAction;
use App\Actions\Sales\PostSalesReturnAction;
use App\Actions\Sales\SaveTaxRateAction;
use App\Actions\Sales\UpdateDocumentSequenceAction;
use App\Actions\Sales\UpdateMoneyAccountAction;
use App\Actions\Sales\UpdateSalesInvoiceDraftAction;
use App\Domain\Sales\Calculators\SalesLineCalculationInput;
use App\Domain\Sales\Calculators\SalesLineCalculator;
use App\Domain\Sales\Queries\CustomerStatementQuery;
use App\Livewire\Pages\Sales\Settings\DocumentSequenceSettings;
use App\Livewire\Pages\Sales\Settings\MoneyAccountSettings;
use App\Livewire\Pages\Sales\Settings\TaxRateSettings;
use App\Models\CompanyUser;
use App\Models\DocumentSequence;
use App\Models\LedgerAccount;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Sales\SalesReconciliationService;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\Support\SalesCorrectionFixtures;
use Tests\TestCase;

class SalesSettingsCorrectionTest extends TestCase
{
    use RefreshDatabase;
    use SalesCorrectionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->salesFixtures();
    }

    private function taxData(): array
    {
        return ['code' => 'VAT16', 'name_ar' => 'ضريبة المبيعات', 'name_en' => 'VAT', 'rate' => '16.000000', 'calculation' => 'exclusive', 'active' => true,
            'sales_tax_account_id' => LedgerAccount::where('system_key', 'tax_output')->value('id')];
    }

    public function test_tax_ui_domain_percentage_points_agree_and_snapshot_is_exact(): void
    {
        $tax = app(SaveTaxRateAction::class)->execute($this->company, $this->owner, $this->taxData());
        $invoice = $this->invoice('100', '1', 'ILS', ['lines' => [['item_description' => 'Service', 'quantity' => '1', 'unit_price' => '100', 'tax_rate_id' => $tax->id]]]);
        $this->assertSame('16.000000', $invoice->lines->first()->tax_rate_snapshot);
        $this->assertSame('16.000000', $invoice->tax_total_currency);
        $this->assertSame('116.000000', $invoice->grand_total_currency);
        Livewire::test(TaxRateSettings::class)->assertSee('16.00%')->assertDontSee('1600%');
        $tax = app(SaveTaxRateAction::class)->execute($this->company, $this->owner, $this->taxData() + [], $tax->id);
        $inclusive = app(SalesLineCalculator::class)->calculate(new SalesLineCalculationInput('1', '116', taxRate: '16.000000', taxInclusive: true));
        $this->assertSame('100.000000', (string) $inclusive->netBeforeTax);
        $this->assertSame('16.000000', (string) $inclusive->tax);
    }

    public static function invalidRates(): array
    {
        return [[0.16], ['-1'], ['100.000001'], ['16.0000001'], ['bad'], ['1e1']];
    }

    #[DataProvider('invalidRates')]
    public function test_authoritative_tax_rate_rejects_invalid_exact_inputs(mixed $rate): void
    {
        $before = TaxRate::count();
        try {
            app(SaveTaxRateAction::class)->execute($this->company, $this->owner, array_replace($this->taxData(), ['rate' => $rate]));
            $this->fail('Invalid tax accepted.');
        } catch (\InvalidArgumentException $expected) {
            $this->assertSame($before, TaxRate::count());
        }
    }

    public static function calculationCases(): array
    {
        return [['0', '100', '0', '100', 2, null, '0'], ['100', '100', '100', '200', 2, null, '0'], ['16', '1.123', '0.180', '1.303', 3, null, '0'], ['16', '100', '14.40', '104.40', 2, 'percent', '10']];
    }

    #[DataProvider('calculationCases')]
    public function test_percentage_tax_boundaries(string $rate, string $price, string $tax, string $total, int $minor, ?string $discountType, string $discount): void
    {
        $result = app(SalesLineCalculator::class)->calculate(new SalesLineCalculationInput('1', $price, $discountType, $discount, $rate, currencyMinorUnits: $minor));
        $this->assertTrue($result->tax->isEqualTo($tax));
        $this->assertTrue($result->total->isEqualTo($total));
    }

    public function test_unsuitable_tax_accounts_fail_without_configuration_writes(): void
    {
        $output = LedgerAccount::where('system_key', 'tax_output')->firstOrFail();
        $inactive = LedgerAccount::create(['company_id' => $this->company->id, 'code' => 'T-INACTIVE', 'name_ar' => 'Inactive', 'account_type' => 'liability', 'normal_balance' => 'credit', 'parent_id' => $output->id, 'active' => false]);
        foreach ([$inactive->id, LedgerAccount::where('system_key', 'cash_control')->value('id'), LedgerAccount::where('system_key', 'accounts_payable')->value('id')] as $id) {
            try {
                app(SaveTaxRateAction::class)->execute($this->company, $this->owner, array_replace($this->taxData(), ['sales_tax_account_id' => $id]));
                $this->fail('Unsuitable account accepted.');
            } catch (\InvalidArgumentException $e) {
                $this->assertSame(0, TaxRate::count());
            }
        }
    }

    public function test_foreign_tax_account_is_rejected_without_configuration_or_audit_writes(): void
    {
        $context = app(CompanyContext::class);
        $context->clear();
        $foreign = app(CreateCompanyAction::class)->execute($this->owner, ['name_ar' => 'Foreign company', 'base_currency_code' => 'ILS']);
        $context->setCompany($this->company, $this->owner);
        $id = LedgerAccount::withoutGlobalScopes()->where('company_id', $foreign->id)->where('system_key', 'tax_output')->value('id');
        $before = DB::table('audit_events')->count();
        try {
            app(SaveTaxRateAction::class)->execute($this->company, $this->owner, array_replace($this->taxData(), ['sales_tax_account_id' => $id]));
            $this->fail('Foreign account accepted.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame(0, TaxRate::count());
            $this->assertSame($before, DB::table('audit_events')->count());
        }
    }

    public function test_sequence_settings_cannot_change_identity_or_counter_and_validate_configuration(): void
    {
        $sequence = DocumentSequence::firstOrFail();
        $identity = $sequence->only(['company_id', 'document_type', 'year', 'next_number']);
        app(UpdateDocumentSequenceAction::class)->execute($this->company, $this->owner, $sequence->id, ['prefix' => 'VALID', 'padding' => 5, 'reset_policy' => 'never', 'next_number' => 999, 'document_type' => 'forged', 'company_id' => 999, 'year' => 1900]);
        $this->assertSame($identity, $sequence->fresh()->only(array_keys($identity)));
        foreach ([['prefix' => ''], ['prefix' => str_repeat('A', 33)], ['padding' => 0], ['padding' => 11], ['reset_policy' => 'monthly']] as $invalid) {
            $before = $sequence->fresh()->getAttributes();
            try {
                app(UpdateDocumentSequenceAction::class)->execute($this->company, $this->owner, $sequence->id, $invalid + ['prefix' => 'VALID', 'padding' => 5, 'reset_policy' => 'yearly']);
                $this->fail('Invalid sequence configuration accepted.');
            } catch (ValidationException $exception) {
                $this->assertSame($before, $sequence->fresh()->getAttributes());
            }
        }
    }

    public static function permissions(): array
    {
        return [['taxes', TaxRateSettings::class], ['money_accounts', MoneyAccountSettings::class], ['sequences', DocumentSequenceSettings::class]];
    }

    #[DataProvider('permissions')]
    public function test_custom_settings_role_and_revocation_after_mount(string $kind, string $component): void
    {
        $actor = User::factory()->create();
        CompanyUser::create(['company_id' => $this->company->id, 'user_id' => $actor->id, 'status' => 'active', 'is_owner' => false, 'joined_at' => now()]);
        setPermissionsTeamId($this->company->id);
        $role = Role::create(['name' => $kind === 'taxes' ? 'TaxManager' : 'Custom'.$kind, 'guard_name' => 'web', 'company_id' => $this->company->id]);
        $permission = 'settings.'.$kind.'.manage';
        $role->givePermissionTo($permission);
        $actor->assignRole($role);
        app(CompanyContext::class)->setCompany($this->company, $actor);
        $this->actingAs($actor);
        $mounted = Livewire::test($component)->assertStatus(200);
        foreach (self::permissions() as [$otherKind, $otherComponent]) {
            if ($otherKind !== $kind) {
                Livewire::test($otherComponent)->assertStatus(403);
            }
        }
        if ($kind === 'taxes') {
            $tax = app(SaveTaxRateAction::class)->execute($this->company, $actor, $this->taxData());
            app(SaveTaxRateAction::class)->execute($this->company, $actor, array_replace($this->taxData(), ['name_en' => 'Edited']), $tax->id);
            $this->assertSame('Edited', $tax->fresh()->name_en);
        } elseif ($kind === 'money_accounts') {
            app(UpdateMoneyAccountAction::class)->execute($this->company, $actor, $this->cash->id, ['name_ar' => 'Cash', 'name_en' => 'Renamed', 'sort_order' => 0, 'is_active' => true]);
            $this->assertSame('Renamed', $this->cash->fresh()->ledgerAccount->name_en);
        } else {
            $sequence = DocumentSequence::firstOrFail();
            app(UpdateDocumentSequenceAction::class)->execute($this->company, $actor, $sequence->id, ['prefix' => 'CUSTOM', 'padding' => 5, 'reset_policy' => 'yearly']);
            $this->assertSame('CUSTOM', $sequence->fresh()->prefix);
        }
        $role->revokePermissionTo($permission);
        $method = $kind === 'sequences' ? 'updateSequence' : 'save';
        $args = $kind === 'sequences' ? [0] : [];
        $mounted->call($method, ...$args)->assertStatus(403);
        $role->givePermissionTo($permission);
        $mounted = Livewire::test($component)->assertStatus(200);
        DB::table('company_user')->where('company_id', $this->company->id)->where('user_id', $actor->id)->update(['status' => 'inactive']);
        $mounted->call($method, ...$args)->assertStatus(403);
    }

    public function test_money_account_update_preserves_identity_and_ledger_name_and_deactivates(): void
    {
        $values = ['name_ar' => 'Renamed', 'name_en' => 'Renamed', 'sort_order' => 0, 'is_active' => false];
        $updated = app(UpdateMoneyAccountAction::class)->execute($this->company, $this->owner, $this->cash->id, $values);
        $this->assertSame($this->cash->ledger_account_id, $updated->ledger_account_id);
        $this->assertSame('Renamed', $updated->ledgerAccount->name_en);
        $this->assertFalse($updated->is_active);
        foreach (['ledger_account_id' => 999, 'account_type' => 'bank', 'currency_code' => 'ILS'] as $field => $value) {
            try {
                app(UpdateMoneyAccountAction::class)->execute($this->company, $this->owner, $this->cash->id, $values + [$field => $value]);
                $this->fail('Identity change accepted.');
            } catch (\InvalidArgumentException $e) {
                $this->assertSame($this->cash->ledger_account_id, $updated->fresh()->ledger_account_id);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->receipt();
    }

    public function test_nullable_due_date_is_not_overdue_and_can_be_cleared(): void
    {
        $draft = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id, 'currency_code' => 'ILS', 'exchange_rate' => '1', 'issue_date' => '2026-10-02', 'due_date' => '2026-10-03',
            'lines' => [['item_description' => 'Service', 'quantity' => '1', 'unit_price' => '100']],
        ]);
        app(UpdateSalesInvoiceDraftAction::class)->execute($draft, $this->owner, ['due_date' => null]);
        $this->assertNull($draft->fresh()->due_date);
        $invoice = $this->invoice();
        $this->assertNull($invoice->due_date);
        $statement = app(CustomerStatementQuery::class)->execute($this->customer);
        $this->assertSame('100.00', $statement['currencies']['USD']['aging']['unspecified']);
        $this->assertSame('0.00', $statement['currencies']['USD']['aging']['days_90_plus']);
        $this->assertSame('100.00', $statement['currencies']['USD']['aging']['total']);
    }

    public function test_stale_settings_component_cannot_save_into_a_changed_active_company(): void
    {
        $mounted = Livewire::test(TaxRateSettings::class);
        app(CompanyContext::class)->clear();
        $other = app(CreateCompanyAction::class)->execute($this->owner, ['name_ar' => 'Other company', 'base_currency_code' => 'ILS']);
        app(CompanyContext::class)->setCompany($other, $this->owner);
        $mounted->call('save')->assertStatus(403);
        $this->assertSame(0, TaxRate::withoutGlobalScopes()->count());
    }

    public function test_partial_returns_preserve_percentage_tax_and_final_residual(): void
    {
        $tax = app(SaveTaxRateAction::class)->execute($this->company, $this->owner, $this->taxData());
        $invoice = $this->invoice('100', '1', 'ILS', ['lines' => [['item_description' => 'Service', 'quantity' => '3', 'unit_price' => '0.01', 'tax_rate_id' => $tax->id]]]);
        $sum = BigDecimal::zero();
        foreach ([1, 2, 3] as $index) {
            $return = app(CreateSalesReturnDraftAction::class)->execute($this->company, $this->owner, ['sales_invoice_id' => $invoice->id, 'issue_date' => '2026-10-02', 'lines' => [['sales_invoice_line_id' => $invoice->lines->first()->id, 'quantity' => '1']]]);
            $return = app(PostSalesReturnAction::class)->execute($return, $this->owner);
            $sum = $sum->plus($return->tax_total_currency);
        }
        $this->assertTrue($sum->isEqualTo($invoice->tax_total_currency));
        $this->assertTrue($invoice->calculateOutstanding()->isZero());
        $this->assertTrue(app(SalesReconciliationService::class)->reconcile($this->company)->isHealthy);
    }
}
