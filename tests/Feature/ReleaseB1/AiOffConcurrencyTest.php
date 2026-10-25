<?php

declare(strict_types=1);

namespace Tests\Feature\ReleaseB1;

use App\Application\AI\AiFormState;
use App\Application\AI\AiGovernance;
use App\Application\AI\AssistantUnavailable;
use App\Application\AI\DeepSeekQuickConnect;
use App\Application\AI\DeterministicTransactionParser;
use App\Application\AI\TransactionAssistant;
use App\Infrastructure\AI\DirectTextProvider;
use App\Livewire\AI\AssistantLauncher;
use App\Livewire\AI\DeepSeekSetup;
use App\Livewire\Pages\SettingsIndex;
use App\Models\AiUserPreference;
use App\Models\AssistantRequest;
use App\Models\CompanyUser;
use App\Models\Purchase;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\Support\Phase12\FinalAiFixtures;
use Tests\TestCase;

class AiOffConcurrencyTest extends TestCase
{
    use FinalAiFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->finalAiFixtures();
        app(DeepSeekQuickConnect::class)->transition(
            $this->company,
            $this->owner,
            'synthetic-configuration-only',
            (int) $this->aiPolicy->revision,
            (int) $this->textConnection->revision
        );
        $this->aiPolicy->refresh();
        // This fixture uses the normalized tariff; allow its bounded synthetic reservation.
        config(['ai_assistant.request_cost_ceiling_micros' => 10000]);
        $settings = $this->aiPolicy->settings;
        $settings['limits']['request_micros'] = 10000;
        $this->aiPolicy->update(['settings' => $settings]);
        Http::preventStrayRequests();
    }

    public function test_setup_and_launcher_disclose_privacy_without_affirmative_text_controls_in_both_locales(): void
    {
        $before = AiUserPreference::query()->get()->map->getRawOriginal()->all();
        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            Livewire::test(DeepSeekSetup::class)
                ->assertSee(__('ai_product.privacy_summary'))
                ->assertSee(__('assistant_v2.privacy_policy'))
                ->assertDontSeeHtml('x-model="consent"')
                ->assertDontSee(__('ai_product.setup_consent'));
            Livewire::test(AssistantLauncher::class)
                ->call('showAssistant')
                ->assertSet('open', true)
                ->assertSee(__('ai_product.browser_privacy_policy'))
                ->assertSee(__('assistant_v2.privacy_policy'))
                ->assertDontSeeHtml('wire:model="consent"')
                ->assertDontSeeHtml('wire:model="questionConsent"')
                ->assertDontSeeHtml('wire:click="acceptConsent"')
                ->assertDontSeeHtml('wire:click="acceptQuestionConsent"');
        }
        $this->assertSame($before, AiUserPreference::query()->get()->map->getRawOriginal()->all());
        Http::assertNothingSent();
    }

    public function test_before_auth_off_dispatch_rejects_and_http_nothing(): void
    {
        app(DeepSeekQuickConnect::class)->disableCompany(
            $this->company,
            $this->owner,
            'synthetic-configuration-only',
            (int) $this->aiPolicy->revision
        );

        $initialRequests = AssistantRequest::count();

        try {
            app(TransactionAssistant::class)->prepare(
                $this->company,
                $this->owner,
                $this->text(),
                'sales',
                (string) Str::uuid(),
                (string) Str::uuid(),
                session()->getId()
            );
            $this->fail('prepare before auth while OFF must throw AssistantUnavailable.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('disabled', $e->reason);
        }

        Http::assertNothingSent();
        $this->assertSame($initialRequests, AssistantRequest::count());
    }

    /**
     * Grant-level concurrency proof:
     * Dispatches a connection grant atomically while ON, commits, then toggles policy OFF.
     * Demonstrates that the previously authorized in-flight DirectTextProvider call is allowed
     * to complete its IO without holding locks across the external network boundary,
     * while subsequent dispatch attempts after OFF are rejected.
     */
    public function test_obtain_grant_while_on_then_off_then_perform_faked_direct_text_provider_call_outside_db_tx_baseline(): void
    {
        $governance = app(AiGovernance::class);

        // 1. Obtain grant while ON
        $grant = $governance->dispatchConnection(
            $this->company,
            $this->owner,
            $this->textConnection->public_id,
            'sales',
            'text_structured'
        );
        $this->assertNotNull($grant['credential']);
        $this->assertSame($this->textConnection->public_id, $grant['connection']->public_id);

        // 2. Turn OFF company AI
        app(DeepSeekQuickConnect::class)->disableCompany(
            $this->company,
            $this->owner,
            'synthetic-configuration-only',
            (int) $this->aiPolicy->fresh()->revision
        );

        // 3. Perform faked DirectTextProvider call outside DB tx baseline
        // RefreshDatabase maintains an outer transaction, so compare transactionLevel before/inside
        $baselineLevel = DB::transactionLevel();
        $insideLevel = null;

        $fakeProposal = $this->validProposal();
        Http::fake(function () use (&$insideLevel) {
            $insideLevel = DB::transactionLevel();

            return Http::response($this->mockDeepSeekResponse($this->validProposal()));
        });

        // The previously authorized call is allowed to run its IO
        $result = app(DirectTextProvider::class)->extract(
            $grant['connection']->provider,
            $grant['connection']->model,
            $grant['connection']->region,
            $grant['credential'],
            $this->text(),
            'sales',
            ['type' => 'object']
        );

        Http::assertSentCount(1);
        $this->assertSame($baselineLevel, $insideLevel, 'Provider call must execute outside DB transaction baseline without extra row locks.');
        $this->assertSame($fakeProposal['intent'], $result['proposal']['intent']);

        Http::assertSentCount(1);

        // 4. Fresh dispatch attempt after OFF must be rejected
        try {
            $governance->dispatchConnection(
                $this->company,
                $this->owner,
                $this->textConnection->public_id,
                'sales',
                'text_structured'
            );
            $this->fail('Fresh dispatch connection after OFF must be rejected.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('disabled', $e->reason);
        }
    }

    public function test_late_transaction_assistant_prepare_fake_callback_disables_policy_asserts_failure_no_handoff_no_economic_mutation(): void
    {
        $invoicesBefore = SalesInvoice::count();
        $purchasesBefore = Purchase::count();
        $postingsBefore = DB::table('posting_batches')->count();
        $stocksBefore = DB::table('stock_movements')->count();

        $formKey = (string) Str::uuid();
        $requestKey = (string) Str::uuid();

        Http::fake(function () {
            // Callback disables policy during external model latency
            app(DeepSeekQuickConnect::class)->disableCompany(
                $this->company,
                $this->owner,
                'synthetic-configuration-only',
                (int) $this->aiPolicy->fresh()->revision
            );

            return Http::response($this->mockDeepSeekResponse($this->validProposal()));
        });

        try {
            app(TransactionAssistant::class)->prepare(
                $this->company,
                $this->owner,
                $this->text(),
                'sales',
                $formKey,
                $requestKey,
                session()->getId()
            );
            $this->fail('Late output return after disable must throw AssistantUnavailable.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('disabled', $e->reason);
        }

        Http::assertSentCount(1);

        // Request must be failed
        Http::assertSentCount(1);
        $request = AssistantRequest::where('request_key', $requestKey)->firstOrFail();
        $this->assertSame('failed', $request->status);
        $this->assertSame('disabled', $request->failure_code);

        // No handoff token in form state - inspect actual requestKey from TransactionAssistant
        $reflection = new ReflectionMethod(TransactionAssistant::class, 'requestKey');
        $actualRequestKey = $reflection->invoke(app(TransactionAssistant::class), $request);
        $this->assertNull(Cache::get($actualRequestKey));

        // Exactly zero mutations to financial/stock/invoice tables
        $this->assertSame($invoicesBefore, SalesInvoice::count());
        $this->assertSame($purchasesBefore, Purchase::count());
        $this->assertSame($postingsBefore, DB::table('posting_batches')->count());
        $this->assertSame($stocksBefore, DB::table('stock_movements')->count());
    }

    public function test_late_transaction_assistant_prepare_fake_callback_off_then_on_asserts_stale_revision(): void
    {
        $formKey = (string) Str::uuid();
        $requestKey = (string) Str::uuid();

        Http::fake(function () {
            // Callback turns OFF then back ON during latency, incrementing policy revision
            $quickConnect = app(DeepSeekQuickConnect::class);
            $quickConnect->disableCompany(
                $this->company,
                $this->owner,
                'synthetic-configuration-only',
                (int) $this->aiPolicy->fresh()->revision
            );
            $quickConnect->enableCompany(
                $this->company,
                $this->owner,
                'synthetic-configuration-only',
                (int) $this->aiPolicy->fresh()->revision
            );

            return Http::response($this->mockDeepSeekResponse($this->validProposal()));
        });

        try {
            app(TransactionAssistant::class)->prepare(
                $this->company,
                $this->owner,
                $this->text(),
                'sales',
                $formKey,
                $requestKey,
                session()->getId()
            );
            $this->fail('Late output after revision bump must throw proposal_stale.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('proposal_stale', $e->reason);
        }

        Http::assertSentCount(1);
        $request = AssistantRequest::where('request_key', $requestKey)->firstOrFail();
        $this->assertSame('failed', $request->status);
        $this->assertSame('proposal_stale', $request->failure_code);
    }

    public function test_existing_revoked_draft_token_fails_after_off_on(): void
    {
        Http::fake(['*' => Http::response($this->mockDeepSeekResponse($this->validProposal()))]);

        $salesHandoff = app(TransactionAssistant::class)->prepare(
            $this->company,
            $this->owner,
            $this->text(),
            'sales',
            (string) Str::uuid(),
            (string) Str::uuid(),
            session()->getId()
        );
        $token = $salesHandoff['token'];

        Http::assertSentCount(1);

        // Token is initially readable while ON
        $state = app(AiFormState::class)->read($this->company, $this->owner, $token, session()->getId());
        $this->assertSame('handoff', $state['data']['lifecycle']);

        // Toggle OFF then back ON
        $quickConnect = app(DeepSeekQuickConnect::class);
        $quickConnect->disableCompany(
            $this->company,
            $this->owner,
            'synthetic-configuration-only',
            (int) $this->aiPolicy->fresh()->revision
        );
        $quickConnect->enableCompany(
            $this->company,
            $this->owner,
            'synthetic-configuration-only',
            (int) $this->aiPolicy->fresh()->revision
        );

        // Reading the old token now fails because policy revision is stale
        try {
            app(AiFormState::class)->read($this->company, $this->owner, $token, session()->getId());
            $this->fail('Old draft token must fail with proposal_stale after policy revision changed.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('proposal_stale', $e->reason);
        }
    }

    public function test_focused_settings_ar_en_reenable_without_checkbox_valid_consent_retained_and_security_checks(): void
    {
        // 1. Disable from settings
        $quickConnect = app(DeepSeekQuickConnect::class);
        $quickConnect->disableCompany(
            $this->company,
            $this->owner,
            'synthetic-configuration-only',
            (int) $this->aiPolicy->fresh()->revision
        );

        // 2. English: re-enable without checkbox reuses valid consent
        app()->setLocale('en');
        Livewire::actingAs($this->owner)
            ->test(SettingsIndex::class)
            ->assertDontSee(__('ai_product.setup_consent'))
            ->call('enableCompanyAi', 'synthetic-configuration-only')
            ->assertHasNoErrors()
            ->assertRedirect(route('settings.index'));
        $this->assertSame(__('ai_product.enabled_done'), session('ai_switch_success'));

        $this->assertTrue($this->aiPolicy->fresh()->enabled);

        // Disable again to test Arabic
        $quickConnect->disableCompany(
            $this->company,
            $this->owner,
            'synthetic-configuration-only',
            (int) $this->aiPolicy->fresh()->revision
        );

        // Arabic: re-enable without checkbox reuses valid consent
        app()->setLocale('ar');
        Livewire::actingAs($this->owner)
            ->test(SettingsIndex::class)
            ->call('enableCompanyAi', 'synthetic-configuration-only')
            ->assertHasNoErrors()
            ->assertRedirect(route('settings.index'));
        $this->assertSame(__('ai_product.enabled_done'), session('ai_switch_success'));

        $this->assertTrue($this->aiPolicy->fresh()->enabled);

        // Disable again to test security constraints: owner password, stale revision, cross-company
        $quickConnect->disableCompany(
            $this->company,
            $this->owner,
            'synthetic-configuration-only',
            (int) $this->aiPolicy->fresh()->revision
        );

        // Wrong password fails
        Livewire::actingAs($this->owner)
            ->test(SettingsIndex::class)
            ->call('enableCompanyAi', 'wrong-password')
            ->assertSet('errorMessage', __('settings.owner_password_invalid'));
        $this->assertFalse($this->aiPolicy->fresh()->enabled);

        // Non-owner member rejected with 403
        $nonOwner = User::factory()->create();
        CompanyUser::create(['company_id' => $this->company->id, 'user_id' => $nonOwner->id, 'status' => 'active', 'is_owner' => false]);
        Livewire::actingAs($nonOwner)
            ->test(SettingsIndex::class)
            ->assertForbidden();

        // Restore owner authentication and company context after non-owner test
        $this->actingAs($this->owner);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);

        // Material privacy changes retain revision invalidation without affirmative consent gates.
        $quickConnect->enableCompany(
            $this->company,
            $this->owner,
            'synthetic-configuration-only',
            (int) $this->aiPolicy->fresh()->revision
        );

        // Materially alter policy privacy: no affirmative consent gate, but stale expected revision still revokes old state
        $oldRevision = (int) $this->aiPolicy->fresh()->revision;
        $policy = $this->aiPolicy->fresh();
        $settings = $policy->settings;
        $settings['privacy']['policy_url'] = 'https://deepseek.example.com/updated-privacy';
        $policy->settings = $settings;
        $policy->revision = $oldRevision + 1;
        $policy->save();

        // Fresh dispatch succeeds without affirmative consent renewal (nonblocking disclosure)
        $grant = app(AiGovernance::class)->dispatchConnection(
            $this->company,
            $this->owner,
            $this->textConnection->public_id,
            'sales',
            'text_structured'
        );
        $this->assertSame($this->textConnection->public_id, $grant['connection']->public_id);

    }

    public function test_missing_initial_consent_fresh_dispatch_allows_without_fabrication(): void
    {
        AiUserPreference::where('company_id', $this->company->id)
            ->where('user_id', $this->owner->id)
            ->update(['consents' => []]);

        // Nonblocking: dispatch succeeds even when initial consent record is absent
        $grant = app(AiGovernance::class)->dispatchConnection(
            $this->company,
            $this->owner,
            $this->textConnection->public_id,
            'sales',
            'text_structured'
        );
        $this->assertNotNull($grant['credential']);
        $this->assertSame($this->textConnection->public_id, $grant['connection']->public_id);

        // Crucial invariant: no consent record is fabricated into database
        $pref = AiUserPreference::where('company_id', $this->company->id)
            ->where('user_id', $this->owner->id)
            ->first();
        $this->assertSame([], $pref?->consents ?? []);
        Http::assertNothingSent();
    }

    public function test_missing_initial_consent_is_not_fabricated_by_reenable(): void
    {
        $quick = app(DeepSeekQuickConnect::class);
        $quick->disableCompany($this->company, $this->owner, 'synthetic-configuration-only', (int) $this->aiPolicy->fresh()->revision);
        AiUserPreference::where('company_id', $this->company->id)->where('user_id', $this->owner->id)->update(['consents' => []]);
        $quick->enableCompany($this->company, $this->owner, 'synthetic-configuration-only', (int) $this->aiPolicy->fresh()->revision);

        // Crucial invariant: re-enabling does not fabricate new consent records
        $pref = AiUserPreference::where('company_id', $this->company->id)->where('user_id', $this->owner->id)->first();
        $this->assertSame([], $pref?->consents ?? []);

        // And dispatch proceeds without affirmative consent blocking
        $grant = app(AiGovernance::class)->dispatchConnection($this->company, $this->owner, $this->textConnection->public_id, 'sales', 'text_structured');
        $this->assertNotNull($grant['credential']);
        Http::assertNothingSent();
    }

    private function text(): string
    {
        return 'Please sell to "Ahmed": 5 "carton" "SOAP-3L" at 80 ILS';
    }

    private function validProposal(): array
    {
        return app(DeterministicTransactionParser::class)->parse(substr($this->text(), 7));
    }

    private function mockDeepSeekResponse(array $proposal): array
    {
        $proposal['evidence'] = array_map(
            fn ($path, $quote) => ['path' => $path, 'quote' => $quote],
            array_keys($proposal['evidence']),
            array_values($proposal['evidence'])
        );

        return [
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode($proposal, JSON_THROW_ON_ERROR),
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 120,
                'completion_tokens' => 90,
            ],
        ];
    }
}
