<?php

declare(strict_types=1);

namespace Tests\Feature\ReleaseB1;

use App\Actions\Company\CreateCompanyAction;
use App\Application\AI\AiGovernance;
use App\Application\AI\AssistantUnavailable;
use App\Application\AI\DeepSeekQuickConnect;
use App\Application\AI\TransactionAssistant;
use App\Livewire\AI\AssistantLauncher;
use App\Livewire\AI\DeepSeekSetup;
use App\Livewire\Pages\Purchasing\PurchaseForm;
use App\Livewire\Pages\Sales\InvoiceForm;
use App\Livewire\Pages\SettingsIndex;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Purchase;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Purchasing\VendorCatalogService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\Phase12\FinalAiFixtures;
use Tests\TestCase;

class MasterAiSwitchTest extends TestCase
{
    use FinalAiFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->finalAiFixtures();
        app(DeepSeekQuickConnect::class)->transition($this->company, $this->owner, 'synthetic-configuration-only', (int) $this->aiPolicy->revision, (int) $this->textConnection->revision);
        $this->aiPolicy->refresh();
        Http::preventStrayRequests();
    }

    public function test_setup_switch_reload_removes_and_remounts_the_layout_launcher(): void
    {
        Livewire::actingAs($this->owner)
            ->test(DeepSeekSetup::class)
            ->call('disableCompany', 'synthetic-configuration-only')
            ->assertRedirect(route('settings.ai'));
        $this->get(route('settings.ai'))->assertDontSee('wire:name="ai.assistant-launcher"', false);
        Livewire::actingAs($this->owner)
            ->test(DeepSeekSetup::class)
            ->call('enableCompany', 'synthetic-configuration-only', true)
            ->assertRedirect(route('settings.ai'));
        $this->get(route('settings.ai'))->assertSee('wire:name="ai.assistant-launcher"', false);
        Http::assertNothingSent();
    }

    public function test_setup_stale_revision_can_be_reviewed_and_retried_without_reload(): void
    {
        $setup = Livewire::actingAs($this->owner)->test(DeepSeekSetup::class);
        $this->aiPolicy->increment('revision');
        $setup->call('disableCompany', 'synthetic-configuration-only')
            ->assertNoRedirect()
            ->assertSet('policyRevision', (int) $this->aiPolicy->fresh()->revision);
        $this->assertTrue($this->aiPolicy->fresh()->enabled);
        $setup->call('disableCompany', 'synthetic-configuration-only')->assertRedirect(route('settings.ai'));

        $setup = Livewire::test(DeepSeekSetup::class);
        $this->aiPolicy->increment('revision');
        $setup->call('enableCompany', 'synthetic-configuration-only', true)
            ->assertNoRedirect()
            ->assertSet('policyRevision', (int) $this->aiPolicy->fresh()->revision);
        $this->assertFalse($this->aiPolicy->fresh()->enabled);
        $setup->call('enableCompany', 'synthetic-configuration-only', true)->assertRedirect(route('settings.ai'));
        Http::assertNothingSent();
    }

    public function test_setup_start_mounts_an_authorized_open_launcher_on_the_destination(): void
    {
        $response = $this->actingAs($this->owner)->get(route('settings.ai', ['open_assistant' => 1]))->assertOk();
        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $nodes = (new \DOMXPath($dom))->query('//*[@*[name()="wire:name"]="ai.assistant-launcher"]');
        $this->assertSame(1, $nodes->length);
        $snapshot = json_decode($nodes->item(0)->getAttribute('wire:snapshot'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($snapshot['data']['open']);

        app(DeepSeekQuickConnect::class)->disableCompany($this->company, $this->owner, 'synthetic-configuration-only', (int) $this->aiPolicy->fresh()->revision);
        $this->get(route('settings.ai', ['open_assistant' => 1]))->assertOk()->assertDontSee('wire:name="ai.assistant-launcher"', false);
        Http::assertNothingSent();
    }

    public function test_global_off_has_zero_launcher_and_zero_policy_read_overhead_on_normal_pages(): void
    {
        config(['ai_assistant.enabled' => false]);

        $this->assertFalse(AiGovernance::isPresentationEligible($this->company));

        // When global flag is false, isPresentationEligible must NOT perform any database queries.
        $queriesBefore = 0;
        DB::listen(function () use (&$queriesBefore) {
            $queriesBefore++;
        });
        $eligible = AiGovernance::isPresentationEligible($this->company);
        $this->assertFalse($eligible);
        $this->assertSame(0, $queriesBefore, 'Global OFF must short-circuit without any policy lookup.');

        // On normal dashboard, launcher must NOT be mounted or present.
        $response = $this->actingAs($this->owner)->get(route('dashboard'));
        $response->assertOk();
        $response->assertDontSee('ai-launcher');
        $response->assertDontSee('ai.assistant-launcher', false);
        Http::assertNothingSent();
    }

    public function test_company_off_absent_launcher_and_no_setup_prompt_on_normal_pages(): void
    {
        $this->aiPolicy->update(['enabled' => false]);

        $this->assertFalse(AiGovernance::isPresentationEligible($this->company));

        // Normal pages must NOT mount AssistantLauncher and must NOT show any floating setup prompt.
        $response = $this->actingAs($this->owner)->get(route('dashboard'));
        $response->assertOk();
        $response->assertDontSee('ai-launcher');
        $response->assertDontSee(__('ai_product.setup'));

        // Absent policy also must not mount launcher or show setup prompt on normal pages.
        $this->aiPolicy->delete();
        $this->assertFalse(AiGovernance::isPresentationEligible($this->company));
        $responseAbsent = $this->actingAs($this->owner)->get(route('dashboard'));
        $responseAbsent->assertOk();
        $responseAbsent->assertDontSee('ai-launcher');
        $responseAbsent->assertDontSee(__('ai_product.setup'));

        // But Settings and AI settings pages remain accessible to the Owner for configuration.
        $this->actingAs($this->owner)->get(route('settings.index'))->assertOk();
        $this->actingAs($this->owner)->get(route('settings.ai'))->assertOk();
        Http::assertNothingSent();
    }

    public function test_company_on_mounts_existing_launcher_and_authorization(): void
    {
        $this->assertTrue(AiGovernance::isPresentationEligible($this->company));

        Livewire::actingAs($this->owner)
            ->test(AssistantLauncher::class)
            ->assertSee(__('ai_product.launcher'))
            ->call('showAssistant')
            ->assertSet('open', true);

        Http::assertNothingSent();
    }

    public function test_master_switch_on_off_re_enable_lifecycle(): void
    {
        $quickConnect = app(DeepSeekQuickConnect::class);
        $initialRevision = (int) $this->aiPolicy->revision;

        // 1. Disable company AI
        $quickConnect->disableCompany($this->company, $this->owner, 'synthetic-configuration-only', $initialRevision);

        $freshPolicy = $this->aiPolicy->fresh();
        $this->assertFalse($freshPolicy->enabled);
        $this->assertSame($initialRevision + 1, (int) $freshPolicy->revision);

        // Retain credentials, settings, grants, caps and consent history
        $this->assertNotNull($freshPolicy->settings);
        $this->assertSame([$this->textConnection->public_id], $freshPolicy->settings['allowed_connections']);
        $this->assertSame($this->textConnection->public_id, $freshPolicy->settings['text_connection']);
        $this->assertDatabaseHas('ai_provider_connections', [
            'id' => $this->textConnection->id,
            'revoked_at' => null,
            'verification_status' => 'verified',
        ]);

        $auditEvent = AuditEvent::where('company_id', $this->company->id)
            ->where('event_key', 'ai.policy.updated')
            ->latest('id')
            ->firstOrFail();
        $this->assertFalse($auditEvent->meta_json['enabled']);

        // 2. Re-enable company AI
        $disabledRevision = (int) $freshPolicy->revision;
        $quickConnect->enableCompany($this->company, $this->owner, 'synthetic-configuration-only', $disabledRevision);

        $reEnabledPolicy = $this->aiPolicy->fresh();
        $this->assertTrue($reEnabledPolicy->enabled);
        $this->assertSame($disabledRevision + 1, (int) $reEnabledPolicy->revision);
        Http::assertNothingSent();
    }

    public function test_forbidden_revoked_wrong_company_and_stale_revision_toggles(): void
    {
        $quickConnect = app(DeepSeekQuickConnect::class);
        $policyRevision = (int) $this->aiPolicy->revision;

        // Non-owner member cannot disable
        $nonOwner = $this->member(['ai.settings.manage']);
        try {
            $quickConnect->disableCompany($this->company, $nonOwner, 'synthetic-configuration-only', $policyRevision);
            $this->fail('Non-owner must not be authorized to disable company AI.');
        } catch (AuthorizationException) {
            $this->assertTrue($this->aiPolicy->fresh()->enabled);
        }

        $this->actingAs($this->owner);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);

        // Wrong password cannot disable
        try {
            $quickConnect->disableCompany($this->company, $this->owner, 'wrong-password', $policyRevision);
            $this->fail('Invalid password must not disable company AI.');
        } catch (AuthorizationException) {
            $this->assertTrue($this->aiPolicy->fresh()->enabled);
        }

        // Stale revision cannot disable
        try {
            $quickConnect->disableCompany($this->company, $this->owner, 'synthetic-configuration-only', $policyRevision + 99);
            $this->fail('Stale policy revision must reject disable.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('proposal_stale', $e->reason);
            $this->assertTrue($this->aiPolicy->fresh()->enabled);
        }

        // Wrong company owner cannot disable
        app(CompanyContext::class)->clear();
        $otherCompany = app(CreateCompanyAction::class)->execute($this->owner, [
            'name_ar' => 'شركة ثانية', 'name_en' => 'Company Two', 'base_currency' => 'ILS',
        ]);
        try {
            $quickConnect->disableCompany($this->company, $this->owner, 'synthetic-configuration-only', $policyRevision);
            $this->fail('Cross-company actor must not disable another company AI.');
        } catch (AuthorizationException) {
            $this->assertTrue($this->aiPolicy->fresh()->enabled);
        }

        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        // Disable to test enable rejections
        $quickConnect->disableCompany($this->company, $this->owner, 'synthetic-configuration-only', $policyRevision);
        $disabledRevision = (int) $this->aiPolicy->fresh()->revision;

        // Stale revision cannot re-enable
        try {
            $quickConnect->enableCompany($this->company, $this->owner, 'synthetic-configuration-only', $disabledRevision + 99);
            $this->fail('Stale revision must reject re-enable.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('proposal_stale', $e->reason);
            $this->assertFalse($this->aiPolicy->fresh()->enabled);
        }

        // Non-owner cannot re-enable
        try {
            $quickConnect->enableCompany($this->company, $nonOwner, 'synthetic-configuration-only', $disabledRevision);
            $this->fail('Non-owner must not re-enable.');
        } catch (AuthorizationException) {
            $this->assertFalse($this->aiPolicy->fresh()->enabled);
        }
        Http::assertNothingSent();
    }

    public function test_provider_fake_records_zero_dispatch_while_off_including_active_interactions(): void
    {
        $quickConnect = app(DeepSeekQuickConnect::class);
        $quickConnect->disableCompany($this->company, $this->owner, 'synthetic-configuration-only', (int) $this->aiPolicy->revision);

        $policyRevision = (int) $this->aiPolicy->fresh()->revision;
        $connectionRevision = (int) $this->textConnection->revision;

        // Test connection while OFF must reject safely before provider call
        $testResult = $quickConnect->testConnection(
            $this->company,
            $this->owner,
            'synthetic-primary-key-never-real',
            'synthetic-configuration-only',
            true,
            (string) Str::uuid(),
            $policyRevision,
            $connectionRevision
        );
        $this->assertSame('failed', $testResult['status']);
        $this->assertSame('disabled', $testResult['reason']);

        // Refresh health while OFF must reject safely before provider call
        $healthResult = $quickConnect->refreshHealth(
            $this->company,
            $this->owner,
            (string) Str::uuid(),
            $policyRevision,
            $connectionRevision
        );
        $this->assertSame('failed', $healthResult['status']);
        $this->assertSame('disabled', $healthResult['reason']);

        // Launcher interactions while OFF must reject with error without dispatch
        Livewire::actingAs($this->owner)
            ->test(AssistantLauncher::class)
            ->set('text', 'sell 5 cartons')
            ->call('prepare')
            ->assertSet('error', __('assistant_v2.error_disabled'));

        // Query mount while OFF must fail closed
        $this->actingAs($this->owner)
            ->get(route('invoices.create', ['assistant' => (string) Str::uuid()]))
            ->assertForbidden();

        // Exactly ZERO external provider requests dispatched
        Http::assertNothingSent();
    }

    public function test_manual_invoice_and_purchase_fields_preserved_when_ai_disabled_during_unsaved_form(): void
    {
        // 1. Sales Invoice: adopt AI proposal, then disable AI while form is open
        $salesHandoff = app(TransactionAssistant::class)->prepare(
            $this->company,
            $this->owner,
            'sell to "Ahmed": 5 "carton" "SOAP-3L" at 80 ILS',
            'sales',
            (string) Str::uuid(),
            (string) Str::uuid(),
            session()->getId()
        );

        $invoiceForm = Livewire::actingAs($this->owner)
            ->withQueryParams(['assistant' => $salesHandoff['token']])
            ->test(InvoiceForm::class)
            ->assertSet('customer_id', $this->customer->id)
            ->assertSet('lines.0.quantity', '5');

        // Turn AI OFF while user has this unsaved form open
        app(DeepSeekQuickConnect::class)->disableCompany(
            $this->company,
            $this->owner,
            'synthetic-configuration-only',
            (int) $this->aiPolicy->revision
        );

        // Edit manual fields: user continues editing form without crashes
        $invoiceForm->set('lines.0.quantity', '9');
        $invoiceForm->set('notes', 'Manual notes added after AI was turned off');

        // Form save must succeed and create the draft invoice
        $invoiceForm->call('save', false)->assertRedirect();

        $invoice = SalesInvoice::latest('id')->firstOrFail();
        $this->assertSame('draft', $invoice->status);
        $this->assertSame('9.000000', $invoice->lines()->firstOrFail()->quantity);
        $this->assertSame('Manual notes added after AI was turned off', $invoice->notes);

        // 2. Purchasing: adopt AI proposal, disable AI, edit manual fields, save draft
        $this->aiProduct->update(['product_type' => 'stock', 'track_stock' => true, 'track_expiry' => false]);
        $this->aiCarton->update(['is_default_purchase' => true]);
        $vendor = app(VendorCatalogService::class)->save($this->company, $this->owner, ['name_ar' => 'مورد', 'name_en' => 'Supplier']);

        // Temporarily re-enable to prepare purchase handoff
        app(DeepSeekQuickConnect::class)->enableCompany(
            $this->company,
            $this->owner,
            'synthetic-configuration-only',
            (int) $this->aiPolicy->fresh()->revision
        );

        $purchaseHandoff = app(TransactionAssistant::class)->prepare(
            $this->company,
            $this->owner,
            'buy from "Supplier": 3 "carton" "SOAP-3L" at 30 ILS',
            'purchase',
            (string) Str::uuid(),
            (string) Str::uuid(),
            session()->getId()
        );

        $purchaseForm = Livewire::actingAs($this->owner)
            ->withQueryParams(['assistant' => $purchaseHandoff['token']])
            ->test(PurchaseForm::class)
            ->assertSet('vendor_id', $vendor->id)
            ->assertSet('lines.0.unit_cost', '30');

        // Turn AI OFF again
        app(DeepSeekQuickConnect::class)->disableCompany(
            $this->company,
            $this->owner,
            'synthetic-configuration-only',
            (int) $this->aiPolicy->fresh()->revision
        );

        // Manual edit and save succeeds
        $purchaseForm->set('vendor_invoice_number', 'VENDOR-INV-99')
            ->set('lines.0.unit_cost', '32')
            ->call('save')
            ->assertRedirect();

        $purchase = Purchase::latest('id')->firstOrFail();
        $this->assertSame('draft', $purchase->status);
        $this->assertSame('VENDOR-INV-99', $purchase->vendor_invoice_number);
        $this->assertSame('96.000000', $purchase->grand_total_currency);

        Http::assertNothingSent();
    }

    public function test_settings_overview_master_switch_ownership_and_ar_en_copy(): void
    {
        // Test in English
        app()->setLocale('en');
        $overviewEn = Livewire::actingAs($this->owner)
            ->test(SettingsIndex::class)
            ->assertSee(__('settings.card_ai_title'))
            ->assertSee(__('settings.ai_status_enabled'))
            ->assertSee(__('settings.ai_switch_disable'));

        // Toggle OFF from Settings Overview
        $overviewEn->call('disableCompanyAi', 'synthetic-configuration-only')
            ->assertHasNoErrors();

        $this->assertFalse($this->aiPolicy->fresh()->enabled);

        // After disable, shows disabled status
        Livewire::actingAs($this->owner)
            ->test(SettingsIndex::class)
            ->assertSee(__('settings.ai_status_disabled'))
            ->assertSee(__('settings.ai_switch_enable'));

        // Toggle back ON from Settings Overview with consent
        Livewire::actingAs($this->owner)
            ->test(SettingsIndex::class)
            ->call('enableCompanyAi', 'synthetic-configuration-only', true)
            ->assertHasNoErrors();

        $this->assertTrue($this->aiPolicy->fresh()->enabled);

        // Test in Arabic
        app()->setLocale('ar');
        Livewire::actingAs($this->owner)
            ->test(SettingsIndex::class)
            ->assertSee('المساعد الذكي')
            ->assertSee('مفعّل')
            ->assertSee('تعطيل الذكاء الاصطناعي للمنشأة');

        // Non-owner member sees restricted notice
        $nonOwner = $this->member(['settings.company.view']);
        Livewire::actingAs($nonOwner)
            ->test(SettingsIndex::class)
            ->assertDontSee('تعطيل الذكاء الاصطناعي للمنشأة');

        Http::assertNothingSent();
    }

    /** @param list<string> $permissions */
    private function member(array $permissions): User
    {
        $member = User::factory()->create();
        CompanyUser::create(['company_id' => $this->company->id, 'user_id' => $member->id, 'status' => 'active', 'is_owner' => false]);
        $role = Role::create(['company_id' => $this->company->id, 'name' => 'B1 limited '.$member->id, 'guard_name' => 'web']);
        $role->syncPermissions($permissions);
        $member->assignRole($role);
        $this->actingAs($member);
        app(CompanyContext::class)->setCompany($this->company, $member);

        return $member;
    }
}
