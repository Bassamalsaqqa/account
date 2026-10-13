<?php

declare(strict_types=1);

namespace Tests\Feature\AiV22;

use App\Actions\Company\CreateCompanyAction;
use App\Application\AI\AssistantDraftTurn;
use App\Application\AI\AssistantUnavailable;
use App\Application\AI\DeterministicTransactionParser;
use App\Livewire\Pages\Sales\InvoiceForm;
use App\Models\AssistantRequest;
use App\Models\CompanyUser;
use App\Models\Customer;
use App\Models\PostingBatch;
use App\Models\Purchase;
use App\Models\SalesInvoice;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Purchasing\VendorCatalogService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\Support\Phase12\FinalAiFixtures;
use Tests\TestCase;

class AssistantDraftTurnTest extends TestCase
{
    use FinalAiFixtures;
    use RefreshDatabase;

    private AssistantDraftTurn $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->finalAiFixtures();
        Http::preventStrayRequests();
        $this->service = app(AssistantDraftTurn::class);
        $this->actingAs($this->owner);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
    }

    public function test_active_context_authorization_is_enforced(): void
    {
        $otherUser = User::factory()->create();
        app(CompanyContext::class)->clear();
        $otherCompany = app(CreateCompanyAction::class)->execute($otherUser, [
            'name_ar' => 'شركة أخرى',
            'name_en' => 'Other Company',
            'base_currency_code' => 'ILS',
        ]);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);

        $this->expectException(AuthorizationException::class);
        $this->service->prepare(
            $otherCompany,
            $this->owner,
            'sell to "Ahmed": 5 "carton" "SOAP-3L" at 80 ILS',
            'sales'
        );
    }

    public function test_missing_caller_identity_cannot_manufacture_a_new_dispatch_or_reservation(): void
    {
        foreach ([[null, (string) Str::uuid(), 'session'], [(string) Str::uuid(), null, 'session'],
            [(string) Str::uuid(), (string) Str::uuid(), null]] as [$request, $form, $session]) {
            try {
                $this->service->prepare($this->company, $this->owner, 'Sell to Ahmed', 'sales', $request, $form, $session);
                $this->fail('Caller-owned identities are mandatory.');
            } catch (AssistantUnavailable $e) {
                $this->assertSame('invalid_request', $e->reason);
            }
        }
        $this->assertSame(0, AssistantRequest::count());
        Http::assertNothingSent();
    }

    public function test_deterministic_sales_command_returns_unsaved_handoff_and_zero_db_invoices(): void
    {
        $formKey = (string) Str::uuid();
        $requestKey = (string) Str::uuid();
        $text = 'sell to "Ahmed": 5 "carton" "SOAP-3L" at 80 ILS';

        $result = $this->service->prepare(
            $this->company,
            $this->owner,
            $text,
            'sales',
            $requestKey,
            $formKey,
            'session-a3-1'
        );

        $this->assertSame('handoff', $result['state']);
        $this->assertSame('sales', $result['intent']);
        $this->assertTrue(Str::isUuid($result['token']));
        $this->assertSame(route('invoices.create', ['assistant' => $result['token']]), $result['url']);
        $this->assertSame($result['url'], $result['handoff_url']);
        $this->assertSame([], $result['questions']);

        // Assert zero database draft invoices and zero posting writes
        $this->assertSame(0, SalesInvoice::count());
        $this->assertSame(0, Purchase::count());
        $this->assertSame(0, PostingBatch::count());
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(1, AssistantRequest::count());
        $this->assertSame('form_ready', AssistantRequest::firstOrFail()->status);
        Http::assertNothingSent();
    }

    public function test_arabic_deterministic_sales_command_returns_unsaved_handoff(): void
    {
        $formKey = (string) Str::uuid();
        $requestKey = (string) Str::uuid();
        $text = 'بيع إلى "أحمد": 5 "كرتونة" "SOAP-3L" بسعر 80 ILS';

        $result = $this->service->prepare(
            $this->company,
            $this->owner,
            $text,
            'auto',
            $requestKey,
            $formKey,
            'session-a3-ar'
        );

        $this->assertSame('handoff', $result['state']);
        $this->assertSame('sales', $result['intent']);
        $this->assertTrue(Str::isUuid($result['token']));
        $this->assertSame(route('invoices.create', ['assistant' => $result['token']]), $result['url']);
        $this->assertSame(0, SalesInvoice::count());
        Http::assertNothingSent();
    }

    public function test_deterministic_purchase_command_returns_unsaved_purchase_handoff(): void
    {
        $this->aiProduct->update(['product_type' => 'stock', 'track_stock' => true, 'track_expiry' => false]);
        $this->aiCarton->update(['is_default_purchase' => true]);
        $vendor = app(VendorCatalogService::class)->save($this->company, $this->owner, [
            'name_ar' => 'مورد التوريدات',
            'name_en' => 'Supplier',
        ]);

        $formKey = (string) Str::uuid();
        $requestKey = (string) Str::uuid();
        $text = 'buy from "Supplier": 2 "carton" "SOAP-3L" at 24 ILS';

        $result = $this->service->prepare(
            $this->company,
            $this->owner,
            $text,
            'purchase',
            $requestKey,
            $formKey,
            'session-a3-purch'
        );

        $this->assertSame('handoff', $result['state']);
        $this->assertSame('purchase', $result['intent']);
        $this->assertSame(route('purchases.create', ['assistant' => $result['token']]), $result['url']);
        $this->assertSame(0, Purchase::count());
        $this->assertSame(0, DB::table('ai_purchase_draft_receipts')->count());
        Http::assertNothingSent();
    }

    public function test_natural_language_provider_path_executes_single_request_without_second_planning(): void
    {
        $naturalText = 'Please sell to "Ahmed": 5 "carton" "SOAP-3L" at 80 ILS';
        $proposal = app(DeterministicTransactionParser::class)->parse(substr($naturalText, 7));
        $proposal['evidence'] = array_map(
            fn ($path, $quote) => ['path' => $path, 'quote' => $quote],
            array_keys($proposal['evidence']),
            array_values($proposal['evidence'])
        );

        Http::fake([
            '*' => Http::response([
                'choices' => [
                    [
                        'message' => ['content' => json_encode($proposal, JSON_THROW_ON_ERROR)],
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 90],
            ]),
        ]);

        $result = $this->service->prepare(
            $this->company,
            $this->owner,
            $naturalText,
            'sales',
            (string) Str::uuid(),
            (string) Str::uuid(),
            'session-a3-nat'
        );

        $this->assertSame('handoff', $result['state']);
        $this->assertSame('sales', $result['intent']);
        $this->assertSame(0, SalesInvoice::count());
        Http::assertSentCount(1);
    }

    public function test_missing_existing_customer_returns_clarification_with_manual_create_link_when_permitted(): void
    {
        $customerCountBefore = Customer::count();
        $formKey = (string) Str::uuid();
        $requestKey = (string) Str::uuid();
        $text = 'sell to "Nonexistent Customer": 5 "carton" "SOAP-3L" at 80 ILS';

        $result = $this->service->prepare(
            $this->company,
            $this->owner,
            $text,
            'sales',
            $requestKey,
            $formKey,
            'session-a3-missing'
        );

        $this->assertSame('clarifying', $result['state']);
        $this->assertSame('sales', $result['intent']);
        $this->assertNotEmpty($result['questions']);

        $partyQuestion = null;
        foreach ($result['questions'] as $q) {
            if ($q['path'] === 'party') {
                $partyQuestion = $q;
                break;
            }
        }

        $this->assertNotNull($partyQuestion);
        $this->assertSame([], $partyQuestion['candidates']);
        $this->assertSame(route('customers.create'), $partyQuestion['create_url']);
        $this->assertSame(route('customers.create'), $result['manual_create_url']);

        // Assert NO invented walk-in customer or master data write occurred
        $this->assertSame($customerCountBefore, Customer::count());
        $this->assertDatabaseMissing('customers', [
            'company_id' => $this->company->id,
            'name_ar' => 'Nonexistent Customer',
        ]);
        $this->assertDatabaseMissing('customers', [
            'company_id' => $this->company->id,
            'code' => 'WALK-IN',
        ]);
        $this->assertSame(0, SalesInvoice::count());
    }

    public function test_missing_existing_customer_without_manage_permission_omits_manual_create_link(): void
    {
        Permission::findOrCreate('sales.invoice.create', 'web');
        Permission::findOrCreate('ai.assistant.use', 'web');

        $staff = User::factory()->create();
        CompanyUser::create(['company_id' => $this->company->id, 'user_id' => $staff->id,
            'status' => 'active', 'is_owner' => false]);
        setPermissionsTeamId($this->company->id);
        $staff->givePermissionTo(['ai.assistant.use', 'ai.sales.use', 'sales.invoice.create',
            'sales.invoice.view', 'customers.view']);

        $this->actingAs($staff);
        app(CompanyContext::class)->setCompany($this->company, $staff);

        $text = 'sell to "Unknown Party": 5 "carton" "SOAP-3L" at 80 ILS';

        $result = $this->service->prepare(
            $this->company,
            $staff,
            $text,
            'sales',
            (string) Str::uuid(),
            (string) Str::uuid(),
            'session-a3-staff'
        );

        $this->assertSame('clarifying', $result['state']);
        $partyQuestion = null;
        foreach ($result['questions'] as $q) {
            if ($q['path'] === 'party') {
                $partyQuestion = $q;
                break;
            }
        }

        $this->assertNotNull($partyQuestion);
        $this->assertSame([], $partyQuestion['candidates']);
        $this->assertNull($partyQuestion['create_url'] ?? null);
        $this->assertNull($result['manual_create_url']);
    }

    public function test_candidate_ambiguity_returns_clarifying_with_candidates_and_no_create_link(): void
    {
        Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => 'أحمد الثاني',
            'name_en' => 'Ahmed',
            'code' => 'C-AHMED-2',
            'created_by' => $this->owner->id,
        ]);

        $formKey = (string) Str::uuid();
        $requestKey = (string) Str::uuid();
        $text = 'sell to "Ahmed": 5 "carton" "SOAP-3L" at 80 ILS';

        // When name matches multiple customers
        $result = $this->service->prepare(
            $this->company,
            $this->owner,
            $text,
            'sales',
            $requestKey,
            $formKey,
            'session-a3-amb'
        );

        $this->assertSame('clarifying', $result['state']);
        $partyQuestion = null;
        foreach ($result['questions'] as $q) {
            if ($q['path'] === 'party') {
                $partyQuestion = $q;
                break;
            }
        }

        $this->assertNotNull($partyQuestion);
        $this->assertNotEmpty($partyQuestion['candidates']);
        $this->assertNull($partyQuestion['create_url'] ?? null);
        $this->assertNull($result['manual_create_url']);

        // Clarify by selecting candidate ID completes handoff
        $chosenId = $partyQuestion['candidates'][0]['value'];
        $clarified = $this->service->clarify(
            $this->company,
            $this->owner,
            $result['token'],
            'session-a3-amb',
            $formKey,
            ['party' => $chosenId]
        );

        $this->assertSame('handoff', $clarified['state']);
        $this->assertSame(route('invoices.create', ['assistant' => $clarified['token']]), $clarified['url']);
    }

    public function test_foreign_company_customer_is_not_accessible_and_remains_missing(): void
    {
        $otherUser = User::factory()->create();
        app(CompanyContext::class)->clear();
        $otherCompany = app(CreateCompanyAction::class)->execute($otherUser, [
            'name_ar' => 'شركة ثانية',
            'name_en' => 'Company Two',
            'base_currency_code' => 'ILS',
        ]);
        app(CompanyContext::class)->setCompany($otherCompany, $otherUser);

        Customer::create([
            'company_id' => $otherCompany->id,
            'name_ar' => 'عميل شركة ثانية',
            'name_en' => 'Foreign Customer',
            'code' => 'C-FOREIGN',
            'created_by' => $otherUser->id,
        ]);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);

        $text = 'sell to "Foreign Customer": 5 "carton" "SOAP-3L" at 80 ILS';

        $result = $this->service->prepare(
            $this->company,
            $this->owner,
            $text,
            'sales',
            (string) Str::uuid(),
            (string) Str::uuid(),
            'session-a3-foreign'
        );

        $this->assertSame('clarifying', $result['state']);
        $partyQuestion = null;
        foreach ($result['questions'] as $q) {
            if ($q['path'] === 'party') {
                $partyQuestion = $q;
                break;
            }
        }

        $this->assertNotNull($partyQuestion);
        $this->assertSame([], $partyQuestion['candidates']);
    }

    public function test_replay_returns_same_handoff_token_and_conflict_fails_closed(): void
    {
        $formKey = (string) Str::uuid();
        $requestKey = (string) Str::uuid();
        $text = 'sell to "Ahmed": 5 "carton" "SOAP-3L" at 80 ILS';

        $first = $this->service->prepare(
            $this->company,
            $this->owner,
            $text,
            'sales',
            $requestKey,
            $formKey,
            'session-replay'
        );

        $second = $this->service->prepare(
            $this->company,
            $this->owner,
            $text,
            'sales',
            $requestKey,
            $formKey,
            'session-replay'
        );

        $this->assertSame($first['token'], $second['token']);
        $this->assertSame($first['url'], $second['url']);
        $this->assertSame(1, AssistantRequest::count());

        // Conflicting text with same requestKey fails closed
        try {
            $this->service->prepare(
                $this->company,
                $this->owner,
                'sell to "Ahmed": 10 "carton" "SOAP-3L" at 80 ILS',
                'sales',
                $requestKey,
                $formKey,
                'session-replay'
            );
            $this->fail('Replay with conflicting payload must throw request_conflict.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('request_conflict', $e->reason);
        }
    }

    public function test_cancellation_discards_memory_and_prevents_subsequent_replay(): void
    {
        $formKey = (string) Str::uuid();
        $requestKey = (string) Str::uuid();
        $text = 'sell to "Ahmed": 5 "carton" "SOAP-3L" at 80 ILS';

        $result = $this->service->prepare(
            $this->company,
            $this->owner,
            $text,
            'sales',
            $requestKey,
            $formKey,
            'session-cancel'
        );

        $this->service->cancel($this->company, $this->owner, $formKey, 'session-cancel');

        $this->assertSame('canceled', AssistantRequest::firstOrFail()->status);
        $this->assertNull(Cache::get('ai-form-state:'.hash('sha256', $result['token'])));

        try {
            $this->service->prepare(
                $this->company,
                $this->owner,
                $text,
                'sales',
                $requestKey,
                $formKey,
                'session-cancel'
            );
            $this->fail('Canceled turn must throw request_canceled.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('request_canceled', $e->reason);
        }
    }

    public function test_unsaved_form_adoption_and_exact_amount_strings(): void
    {
        $text = 'sell to "Ahmed": 5 "carton" "SOAP-3L" at 80 ILS';
        $result = $this->service->prepare(
            $this->company,
            $this->owner,
            $text,
            'sales',
            (string) Str::uuid(),
            (string) Str::uuid(),
            session()->getId()
        );

        // Mount existing InvoiceForm using assistant query parameter
        Livewire::withQueryParams(['assistant' => $result['token']])
            ->test(InvoiceForm::class)
            ->assertSet('customer_id', $this->customer->id)
            ->assertSet('lines.0.quantity', '5')
            ->assertSet('lines.0.unit_price', '80')
            ->assertSee('Unsaved editable form');

        // Zero economic writes until user explicitly saves
        $this->assertSame(0, SalesInvoice::count());
        $this->assertSame(0, PostingBatch::count());
    }

    public function test_unverified_connection_fails_closed_with_readiness_gap(): void
    {
        $this->textConnection->update(['verification_status' => 'unverified']);
        $text = 'Please sell to "Ahmed": 5 "carton" "SOAP-3L" at 80 ILS';

        try {
            $this->service->prepare(
                $this->company,
                $this->owner,
                $text,
                'sales',
                (string) Str::uuid(),
                (string) Str::uuid(),
                'session-unverified'
            );
            $this->fail('Unverified connection must throw connection_unverified.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('connection_unverified', $e->reason);
        }
    }
}
