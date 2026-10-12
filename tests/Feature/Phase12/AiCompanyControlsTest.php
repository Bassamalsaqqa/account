<?php

declare(strict_types=1);

namespace Tests\Feature\Phase12;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Company\UpdateRolePermissionsAction;
use App\Application\AI\AiFormState;
use App\Application\AI\AiGovernance;
use App\Application\AI\AssistantUnavailable;
use App\Application\AI\TransactionAssistant;
use App\Livewire\AI\AiSettings;
use App\Livewire\AI\AssistantLauncher;
use App\Models\AssistantRequest;
use App\Models\CompanyUser;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\Phase12\FinalAiFixtures;
use Tests\TestCase;

class AiCompanyControlsTest extends TestCase
{
    use FinalAiFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->finalAiFixtures();
        Http::preventStrayRequests();
    }

    public function test_owner_controls_preserve_allowed_scopes_while_settings_show_only_simple_setup(): void
    {
        $this->controls(false, ['sales']);
        $settings = $this->aiPolicy->fresh()->settings;
        $this->assertFalse($settings['global_button_enabled']);
        $this->assertSame(['sales'], $settings['allowed_intents']);
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            Livewire::test(AiSettings::class)
                ->assertSee(__('ai_product.title'))
                ->assertSee(__('ai_product.test'))
                ->assertDontSee('wire:model="globalButtonEnabled"', false)
                ->assertDontSee('wire:model="allowedIntents"', false)
                ->assertDontSee('wire:click="save"', false);
        }
        Http::assertNothingSent();
    }

    public function test_global_button_control_preserves_permitted_contextual_workflow(): void
    {
        $this->controls(false, ['sales']);
        Livewire::test(AssistantLauncher::class)->assertDontSee(__('ai_product.launcher'))
            ->set('text', $this->note())->call('prepare')
            ->assertSet('error', __('assistant_v2.error_disabled'));
        $this->assertSame(0, AssistantRequest::count());
        Livewire::test(AssistantLauncher::class, ['context' => 'sales'])
            ->assertSee(__('ai_product.launcher'))->call('showAssistant')->assertSet('open', true);
        $result = app(TransactionAssistant::class)->prepare($this->company, $this->owner, $this->note(),
            'sales', (string) Str::uuid(), (string) Str::uuid(), session()->getId());
        $this->assertSame('handoff', $result['state']);
        $this->assertSame(0, DB::table('sales_invoices')->count());
        Http::assertNothingSent();
    }

    public function test_disabled_purchase_cannot_be_selected_or_inferred_by_an_owner(): void
    {
        $this->controls(true, ['sales']);
        $this->assertSame(['sales'], app(AiGovernance::class)->allowedIntents($this->company, $this->owner));
        Livewire::test(AssistantLauncher::class, ['context' => 'purchase'])->assertDontSee(__('ai_product.launcher'));
        foreach (['purchase', 'auto'] as $context) {
            try {
                app(TransactionAssistant::class)->prepare($this->company, $this->owner,
                    'buy from "Vendor": 5 "carton" "SOAP-3L" at 80 ILS', $context,
                    (string) Str::uuid(), (string) Str::uuid(), session()->getId());
                $this->fail('Company intent limits must apply even to the Owner and automatic inference.');
            } catch (AuthorizationException) {
                $this->assertSame(0, AssistantRequest::count());
            }
        }
        $this->assertSame(0, DB::table('purchases')->count());
        Http::assertNothingSent();
    }

    public function test_no_enabled_intents_refuses_global_and_contextual_usage(): void
    {
        $this->controls(true, []);
        $this->assertSame([], app(AiGovernance::class)->allowedIntents($this->company, $this->owner));
        foreach (['auto', 'sales', 'purchase'] as $context) {
            Livewire::test(AssistantLauncher::class, ['context' => $context])->assertDontSee(__('ai_product.launcher'));
            try {
                app(AiGovernance::class)->authorizeUse($this->company, $this->owner, $context);
                $this->fail('An empty company intent allowlist must deny all AI workflows.');
            } catch (AuthorizationException) {
                $this->assertSame(0, AssistantRequest::count());
            }
        }
        Http::assertNothingSent();
    }

    public function test_policy_change_invalidates_an_existing_form_and_open_global_launcher(): void
    {
        $launcher = Livewire::test(AssistantLauncher::class)->call('showAssistant');
        $form = (string) Str::uuid();
        $result = app(TransactionAssistant::class)->prepare($this->company, $this->owner, $this->note(),
            'sales', $form, (string) Str::uuid(), session()->getId());
        $this->controls(false, ['purchase']);
        $launcher->set('text', $this->note())->call('prepare')
            ->assertSet('error', __('assistant_v2.error_disabled'));
        try {
            app(AiFormState::class)->read($this->company, $this->owner, $result['token'], session()->getId(), $form);
            $this->fail('A changed policy cannot leave an old capability usable.');
        } catch (AssistantUnavailable|AuthorizationException) {
            $this->assertSame(0, DB::table('sales_invoices')->count());
        }
        Http::assertNothingSent();
    }

    public function test_invalid_intent_lists_cannot_change_policy_or_revision(): void
    {
        $before = $this->aiPolicy->fresh()->toArray();
        foreach ([['sales', 'sales'], ['future_api'], ['named' => 'sales']] as $intents) {
            try {
                $this->controls(true, $intents);
                $this->fail('Intent policy must be a distinct list containing supported workflows only.');
            } catch (ValidationException) {
                $this->assertSame($before, $this->aiPolicy->fresh()->toArray());
            }
        }
        Http::assertNothingSent();
    }

    public function test_administrator_in_a_has_no_ai_authority_after_switching_to_viewer_only_b(): void
    {
        $actor = User::factory()->create();
        CompanyUser::create(['company_id' => $this->company->id, 'user_id' => $actor->id, 'status' => 'active', 'is_owner' => false]);
        $administrator = Role::where('company_id', $this->company->id)->where('name', 'Administrator')->firstOrFail();
        foreach (['ai.assistant.use', 'ai.sales.use'] as $permission) {
            app(UpdateRolePermissionsAction::class)->execute($this->company, $administrator, $permission, true, $this->owner);
        }
        $actor->assignRole($administrator);
        $context = app(CompanyContext::class);
        $context->clear();
        $otherOwner = User::factory()->create();
        $other = app(CreateCompanyAction::class)->execute($otherOwner, ['name_ar' => 'شركة أخرى', 'name_en' => 'Viewer Company B', 'base_currency_code' => 'ILS']);
        $this->actingAs($otherOwner);
        $context->setCompany($other, $otherOwner);
        $settings = [...$this->aiPolicy->settings, 'allowed_connections' => [], 'pricing' => [],
            'text_connection' => null, 'speech_connection' => null, 'fallback' => ['enabled' => false]];
        app(AiGovernance::class)->updatePolicy($other, $otherOwner, [
            'enabled' => true, 'personal_credentials_enabled' => false, 'follow_up_enabled' => false,
            'workflow_mode' => 'seamless', 'speech_method' => 'disabled', 'settings' => $settings,
        ]);
        CompanyUser::create(['company_id' => $other->id, 'user_id' => $actor->id, 'status' => 'active', 'is_owner' => false]);
        $viewer = Role::where('company_id', $other->id)->where('name', 'Viewer')->firstOrFail();
        $actor->assignRole($viewer);

        $this->actingAs($actor);
        $context->setCompany($this->company, $actor);
        $this->assertSame(['sales'], app(AiGovernance::class)->allowedIntents($this->company, $actor));
        $this->assertCount(1, app(AiGovernance::class)->visibleConnections($this->company, $actor));
        $context->setCompany($other, $actor);
        $this->assertSame([], app(AiGovernance::class)->allowedIntents($other, $actor));
        try {
            app(AiGovernance::class)->authorizeUse($other, $actor, 'sales');
            $this->fail('Company A roles must not authorize AI in Viewer-only company B.');
        } catch (AuthorizationException) {
            $this->assertSame(0, AssistantRequest::count());
        }
        try {
            app(AiGovernance::class)->visibleConnections($other, $actor);
            $this->fail('Company A connections must not become visible in company B.');
        } catch (AuthorizationException) {
            $this->assertSame(0, DB::table('sales_invoices')->count());
        }
        Http::assertNothingSent();
    }

    private function controls(bool $global, array $intents): void
    {
        $policy = $this->aiPolicy->fresh();
        $this->aiPolicy = app(AiGovernance::class)->updatePolicy($this->company, $this->owner, [
            'enabled' => $policy->enabled, 'personal_credentials_enabled' => $policy->personal_credentials_enabled,
            'follow_up_enabled' => $policy->follow_up_enabled, 'workflow_mode' => $policy->workflow_mode,
            'speech_method' => $policy->speech_method,
            'settings' => [...$policy->settings, 'global_button_enabled' => $global, 'allowed_intents' => $intents],
        ], (int) $policy->revision);
    }

    private function note(): string
    {
        return 'sell to "Ahmed": 5 "carton" "SOAP-3L" at 80 ILS';
    }
}
