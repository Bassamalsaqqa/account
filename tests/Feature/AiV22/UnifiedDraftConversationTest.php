<?php

declare(strict_types=1);

namespace Tests\Feature\AiV22;

use App\Actions\Company\CreateCompanyAction;
use App\Application\AI\AiGovernance;
use App\Application\AI\AssistantReportCatalog;
use App\Application\AI\DeterministicTransactionParser;
use App\Livewire\AI\AssistantLauncher;
use App\Livewire\Pages\Purchasing\PurchaseForm;
use App\Livewire\Pages\Sales\InvoiceForm;
use App\Models\AiUserPreference;
use App\Models\AssistantRequest;
use App\Models\CompanyUser;
use App\Models\Customer;
use App\Models\User;
use App\Services\Purchasing\VendorCatalogService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\Support\Phase12\FinalAiFixtures;
use Tests\TestCase;

final class UnifiedDraftConversationTest extends TestCase
{
    use FinalAiFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->finalAiFixtures();
        Http::preventStrayRequests();
        config(['ai_assistant.turn_enabled' => true, 'ai_assistant.questions.enabled' => true]);
        $settings = $this->aiPolicy->settings;
        $settings['questions'] = ['enabled' => true, 'allowed_reports' => AssistantReportCatalog::KEYS];
        $this->aiPolicy = app(AiGovernance::class)->updatePolicy($this->company, $this->owner,
            ['enabled' => true, 'personal_credentials_enabled' => false, 'follow_up_enabled' => true,
                'workflow_mode' => 'seamless', 'speech_method' => 'disabled', 'settings' => $settings], $this->aiPolicy->revision);
        $governance = app(AiGovernance::class);
        $governance->recordConsent($this->company, $this->owner, $this->textConnection->public_id,
            $governance->consentFingerprint($this->aiPolicy, $this->textConnection, 'question'), 'question');
    }

    /** @return array<string, array{string, string, string}> */
    public static function naturalTransactions(): array
    {
        return [
            'Palestinian sale' => ['سجل بيعة لأحمد 5 كرتونة SOAP-3L بسعر 80 ILS', 'sales', 'أحمد'],
            'English sale' => ['Please sell 5 carton SOAP-3L to Ahmed at 80 ILS', 'sales', 'Ahmed'],
            'Palestinian purchase' => ['سجل شراء من Supplier عدد 5 carton SOAP-3L بسعر 80 ILS', 'purchase', 'Supplier'],
            'English purchase' => ['Please buy 5 carton SOAP-3L from Supplier at 80 ILS', 'purchase', 'Supplier'],
        ];
    }

    #[DataProvider('naturalTransactions')]
    public function test_natural_request_uses_one_sdk_response_then_opens_existing_editable_unsaved_form(string $text, string $intent, string $party): void
    {
        if ($intent === 'purchase') {
            $this->aiProduct->update(['product_type' => 'stock', 'track_stock' => true]);
            $this->aiCarton->update(['is_default_purchase' => true]);
            app(VendorCatalogService::class)->save($this->company, $this->owner, ['name_ar' => 'المورد', 'name_en' => 'Supplier']);
        }
        $before = $this->economicCounts();
        $proposal = $this->proposal($intent, $party);
        if (str_contains($text, 'كرتونة')) {
            $proposal['lines'][0]['unit'] = 'كرتونة';
            $proposal['evidence']['lines.0.unit'] = 'كرتونة';
        }
        $this->fakeProposal($proposal);
        $component = Livewire::test(AssistantLauncher::class)->call('showAssistant')->set('text', $text)->call('prepare');
        $request = AssistantRequest::firstOrFail();
        self::assertSame('form_ready', $request->status);
        $url = $component->__get('effects')['redirect'];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::assertStringStartsWith(route($intent === 'sales' ? 'invoices.create' : 'purchases.create'), $url);
        $form = Livewire::withQueryParams(['assistant' => $query['assistant']])->test($intent === 'sales' ? InvoiceForm::class : PurchaseForm::class);
        $form->assertSet('lines.0.quantity', '5')->assertSet('lines.0.'.($intent === 'sales' ? 'unit_price' : 'unit_cost'), '80');
        self::assertSame($before, $this->economicCounts());
        Http::assertSentCount(1);
    }

    public function test_multiline_natural_sales_preserves_exact_distinct_line_values(): void
    {
        $proposal = $this->proposal();
        $proposal['lines'][] = [...$proposal['lines'][0], 'quantity' => '2', 'unit_price' => '7.25'];
        foreach (['product' => 'SOAP-3L', 'unit' => 'carton', 'quantity' => '2', 'unit_price' => '7.25'] as $field => $quote) {
            $proposal['evidence']['lines.1.'.$field] = $quote;
        }
        $this->fakeProposal($proposal);
        $before = $this->economicCounts();
        $component = Livewire::test(AssistantLauncher::class)->set('text',
            'Please sell to Ahmed 5 carton SOAP-3L at 80 ILS and 2 carton SOAP-3L at 7.25 ILS')->call('prepare');
        parse_str((string) parse_url($component->__get('effects')['redirect'], PHP_URL_QUERY), $query);
        Livewire::withQueryParams(['assistant' => $query['assistant']])->test(InvoiceForm::class)
            ->assertSet('lines.0.unit_price', '80')->assertSet('lines.1.quantity', '2')->assertSet('lines.1.unit_price', '7.25');
        Http::assertSentCount(1);
        self::assertSame($before, $this->economicCounts());
    }

    public function test_missing_party_and_quantity_are_clarified_without_creating_records(): void
    {
        $proposal = $this->proposal('sales', 'Unknown Customer');
        $proposal['lines'][0]['quantity'] = null;
        unset($proposal['evidence']['lines.0.quantity']);
        $this->fakeProposal($proposal);
        $before = $this->economicCounts();
        $component = Livewire::test(AssistantLauncher::class)->set('text',
            'Please sell to Unknown Customer carton SOAP-3L at 80 ILS')->call('prepare')->assertNoRedirect();
        $paths = array_column($component->get('questions'), 'path');
        self::assertContains('party', $paths);
        self::assertContains('lines.0.quantity', $paths);
        self::assertSame($before, $this->economicCounts());
        Http::assertSentCount(1);
    }

    public function test_ambiguous_party_can_be_selected_locally_without_second_dispatch(): void
    {
        Customer::create(['company_id' => $this->company->id, 'name_ar' => 'أحمد الآخر', 'name_en' => 'Ahmed',
            'code' => 'SECOND-AHMED', 'created_by' => $this->owner->id]);
        $this->fakeProposal($this->proposal());
        $component = Livewire::test(AssistantLauncher::class)->set('text', 'Please sell to Ahmed 5 carton SOAP-3L at 80 ILS')
            ->call('prepare')->assertNoRedirect();
        $questions = $component->get('questions');
        $index = array_search('party', array_column($questions, 'path'), true);
        self::assertNotFalse($index);
        self::assertCount(2, $questions[$index]['candidates']);
        $component->set('answers.'.$index, (string) $this->customer->id)->call('clarify')->assertRedirect();
        Http::assertSentCount(1);
        self::assertSame(0, DB::table('sales_invoices')->count());
    }

    public function test_retry_reuses_request_identity_and_does_not_dispatch_twice(): void
    {
        $this->fakeProposal($this->proposal('sales', 'Unknown Customer'));
        $component = Livewire::test(AssistantLauncher::class)->set('text', 'Please sell to Unknown Customer 5 carton SOAP-3L at 80 ILS');
        $key = $component->get('requestKey');
        $component->call('prepare')->assertNoRedirect();
        $token = $component->get('token');
        $component->call('prepare')->assertSet('requestKey', $key)->assertSet('token', $token);
        self::assertSame(1, AssistantRequest::count());
        Http::assertSentCount(1);
    }

    public function test_report_followup_keeps_bounded_context_then_transaction_discards_it(): void
    {
        $component = Livewire::test(AssistantLauncher::class)->set('text', 'sales this month')->call('prepare');
        self::assertNotEmpty($component->get('turnReports'));
        $context = $component->get('turnContextToken');
        self::assertNotEmpty($context);
        $component->call('resetQuestion')->assertSet('turnContextToken', $context)
            ->set('text', 'طيب وماذا عن الشهر اللي قبله؟')->call('prepare');
        self::assertNotEmpty($component->get('turnReports'));
        Http::assertNothingSent();

        $this->fakeProposal($this->proposal('sales', 'Unknown Customer'));
        $before = $this->economicCounts();
        $component->set('text', 'Please sell to Unknown Customer 5 carton SOAP-3L at 80 ILS')->call('prepare')
            ->assertNoRedirect()->assertSet('turnReports', [])->assertSet('turnContextToken', null)
            ->assertSet('turnScope', null)->assertSet('turnClarification', null);
        self::assertNotEmpty($component->get('questions'));
        self::assertSame($before, $this->economicCounts());
        Http::assertSentCount(1);
    }

    public function test_changed_description_and_cancellation_do_not_replay_old_clarification(): void
    {
        $this->fakeProposal($this->proposal('sales', 'Unknown Customer'));
        $component = Livewire::test(AssistantLauncher::class)->set('text', 'Please sell to Unknown Customer 5 carton SOAP-3L at 80 ILS')
            ->call('prepare')->assertNoRedirect();
        $oldKey = $component->get('requestKey');
        $oldToken = $component->get('token');
        $this->fakeProposal($this->proposal('sales', 'Another Customer'));
        $component->set('text', 'Please sell to Another Customer 5 carton SOAP-3L at 80 ILS')->call('prepare')->assertNoRedirect();
        self::assertNotSame($oldKey, $component->get('requestKey'));
        self::assertNotSame($oldToken, $component->get('token'));
        self::assertSame(2, AssistantRequest::count());
        $component->call('cancel')->assertSet('token', null)->assertSet('questions', [])->assertSet('open', false);
        self::assertSame(0, DB::table('sales_invoices')->count());
    }

    public function test_mixed_report_transaction_choice_requests_clarity_without_dispatch(): void
    {
        $component = Livewire::test(AssistantLauncher::class)->set('text', 'Create sales invoice and report sales this month')->call('prepare');
        self::assertNotNull($component->get('ambiguousChoice'));
        $component->call('chooseOperation', 'report')->assertSet('turnClarification', 'intent')->assertNoRedirect();
        Http::assertNothingSent();
        self::assertSame(0, AssistantRequest::count());
        self::assertSame(0, DB::table('sales_invoices')->count());
    }

    public function test_foreign_party_is_not_resolved_or_created(): void
    {
        $proposal = $this->proposal('sales', 'Foreign Customer');
        $otherOwner = User::factory()->create();
        app(CompanyContext::class)->clear();
        $other = app(CreateCompanyAction::class)->execute($otherOwner, ['name_ar' => 'أخرى', 'name_en' => 'Other', 'base_currency_code' => 'ILS']);
        app(CompanyContext::class)->setCompany($other, $otherOwner);
        Customer::create(['company_id' => $other->id, 'name_ar' => 'أجنبي', 'name_en' => 'Foreign Customer', 'code' => 'FOREIGN', 'created_by' => $otherOwner->id]);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $this->fakeProposal($proposal);
        $before = $this->economicCounts();
        $component = Livewire::test(AssistantLauncher::class)->set('text', 'Please sell to Foreign Customer 5 carton SOAP-3L at 80 ILS')->call('prepare')->assertNoRedirect();
        $party = array_values(array_filter($component->get('questions'), fn ($q) => $q['path'] === 'party'));
        self::assertSame([], $party[0]['candidates']);
        self::assertSame($before, $this->economicCounts());
        Http::assertSentCount(1);
    }

    public function test_revoked_permission_during_model_latency_prevents_handoff(): void
    {
        $proposal = $this->proposal();
        Http::fake(function () use ($proposal) {
            DB::table('model_has_roles')->where('company_id', $this->company->id)->where('model_id', $this->owner->id)->delete();

            return Http::response($this->response($proposal));
        });
        Livewire::test(AssistantLauncher::class)->set('text', 'Please sell to Ahmed 5 carton SOAP-3L at 80 ILS')->call('prepare')->assertForbidden();
        self::assertSame(0, DB::table('sales_invoices')->count());
        Http::assertSentCount(1);
    }

    public function test_missing_text_acceptance_allows_unsaved_draft_without_fabricated_records_or_economic_writes(): void
    {
        AiUserPreference::where('company_id', $this->company->id)->where('user_id', $this->owner->id)->update(['consents' => []]);
        $before = $this->economicCounts();
        $this->fakeProposal($this->proposal());
        Livewire::test(AssistantLauncher::class)->set('text', 'Please sell to Ahmed 5 carton SOAP-3L at 80 ILS')->call('prepare')
            ->assertRedirect();
        self::assertSame('form_ready', AssistantRequest::sole()->status);
        self::assertSame([], AiUserPreference::where('company_id', $this->company->id)->where('user_id', $this->owner->id)->firstOrFail()->consents);
        self::assertSame($before, $this->economicCounts());
        Http::assertSentCount(1);
    }

    public function test_company_switch_cannot_reuse_mounted_conversation(): void
    {
        $component = Livewire::test(AssistantLauncher::class);
        app(CompanyContext::class)->clear();
        $other = app(CreateCompanyAction::class)->execute($this->owner, ['name_ar' => 'ثانية', 'name_en' => 'Other', 'base_currency_code' => 'ILS']);
        app(CompanyContext::class)->setCompany($other, $this->owner);
        $component->set('text', 'sell to Ahmed')->call('prepare')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_restricted_report_reader_cannot_dispatch_transaction(): void
    {
        $user = User::factory()->create();
        CompanyUser::create(['company_id' => $this->company->id, 'user_id' => $user->id, 'status' => 'active', 'is_owner' => false]);
        setPermissionsTeamId($this->company->id);
        $role = Role::create(['company_id' => $this->company->id, 'name' => 'Synthetic report reader', 'guard_name' => 'web']);
        $role->givePermissionTo(['ai.assistant.use', 'reports.sales.view', 'sales.invoice.view']);
        $user->assignRole($role);
        $this->actingAs($user);
        app(CompanyContext::class)->setCompany($this->company, $user);
        Livewire::test(AssistantLauncher::class)->set('text', 'Please sell to Ahmed 5 carton SOAP-3L at 80 ILS')->call('prepare')
            ->assertNoRedirect()->assertSet('error', __('assistant_v2.tx_permission_required'));
        Http::assertNothingSent();
        self::assertSame(0, AssistantRequest::count());
    }

    /** @return array<string, mixed> */
    private function proposal(string $intent = 'sales', string $party = 'Ahmed'): array
    {
        $command = $intent === 'sales' ? 'sell to' : 'buy from';

        return app(DeterministicTransactionParser::class)->parse($command.' "'.$party.'": 5 "carton" "SOAP-3L" at 80 ILS');
    }

    /** @param array<string, mixed> $proposal */
    private function fakeProposal(array $proposal): void
    {
        Http::fake(['*' => Http::response($this->response($proposal))]);
    }

    /** @param array<string, mixed> $proposal
     * @return array<string, mixed>
     */
    private function response(array $proposal): array
    {
        $proposal['evidence'] = array_map(fn ($path, $quote) => ['path' => $path, 'quote' => $quote], array_keys($proposal['evidence']), array_values($proposal['evidence']));

        return ['choices' => [['message' => ['content' => json_encode($proposal, JSON_THROW_ON_ERROR)], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 90]];
    }

    /** @return array<string, int> */
    private function economicCounts(): array
    {
        $counts = [];
        foreach (['sales_invoices', 'purchases', 'posting_batches', 'posting_lines', 'stock_movements', 'customer_payments', 'vendor_payments', 'customers', 'vendors', 'products'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }
}
