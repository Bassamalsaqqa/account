<?php

declare(strict_types=1);

namespace Tests\Feature\Phase12;

use App\Application\AI\AssistantBudget;
use App\Application\AI\AssistantUnavailable;
use App\Application\AI\SalesAssistant;
use App\Application\AI\SalesProposalResolver;
use App\Livewire\Pages\Sales\InvoiceForm;
use App\Models\AssistantRequest;
use App\Models\CompanyUser;
use App\Models\Customer;
use App\Models\PostingBatch;
use App\Models\SalesInvoice;
use App\Models\StockMovement;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\Phase12\AssistantFixtures;
use Tests\TestCase;

class AssistantWorkflowTest extends TestCase
{
    use AssistantFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assistantFixtures();
    }

    public function test_ten_interpretation_retries_are_one_provider_request_and_zero_business_effects(): void
    {
        $provider = $this->fakeIntent($this->proposal);
        $key = (string) Str::uuid();
        $assistant = app(SalesAssistant::class);
        $result = null;
        for ($i = 0; $i < 10; $i++) {
            $reply = $assistant->interpret($this->company, $this->owner, 'Five cartons', $key, true);
            $result ??= $reply;
            $this->assertSame($result, $reply);
        }
        $this->assertSame(1, $provider->calls);
        $this->assertSame(1, AssistantRequest::count());
        $this->assertSame('60.000000', $result['summary'][0]['base_quantity']);
        $this->assertSame(0, SalesInvoice::count());
        $this->assertSame(0, PostingBatch::count());
        $this->assertSame(0, StockMovement::count());
        $request = AssistantRequest::firstOrFail();
        $this->assertSame(1000, $request->reserved_cost_micros);
        $this->assertSame(600, $request->reported_cost_micros);
        $this->assertStringNotContainsString('Five cartons', json_encode($request->toArray()));
        $cipher = Cache::get($assistant->cacheKey($request));
        $this->assertIsString($cipher);
        $this->assertStringNotContainsString('Ahmed', $cipher);
    }

    public function test_suggestion_only_populates_form_then_canonical_review_and_explicit_post(): void
    {
        $component = Livewire::test(InvoiceForm::class)
            ->set('aiText', 'Five cartons of Dishwashing Liquid for Ahmed at 80 ILS')
            ->set('aiConsent', true)->call('interpretSale')->assertSet('aiError', null)
            ->call('applySaleSuggestion')->assertSet('aiError', null);
        $this->assertSame('5.000000', $component->get('lines')[0]['quantity']);
        $this->assertSame('400.000000', $component->get('previewGrandTotal'));
        $this->assertSame(0, SalesInvoice::count());
        $this->assertSame(0, PostingBatch::count());
        $component->call('save', true)->assertSet('showPreview', true)->assertHasNoErrors();
        $invoice = SalesInvoice::firstOrFail();
        $this->assertTrue($invoice->isDraft());
        $this->assertSame(0, PostingBatch::count());
        $request = AssistantRequest::firstOrFail();
        $this->assertSame('accepted', $request->status);
        $this->assertSame((int) $invoice->id, (int) $request->sales_invoice_id);
        $this->assertNotNull($request->approved_hash);
        $this->assertNotNull($request->command_key);
        $component->call('confirmPost')->assertRedirect();
        $this->assertTrue($invoice->fresh()->isPosted());
        $this->assertSame(1, PostingBatch::count());
    }

    public function test_cancel_forgets_proposal_and_never_creates_a_draft(): void
    {
        $component = Livewire::test(InvoiceForm::class)->set('aiConsent', true)->set('aiText', 'Five cartons')
            ->call('interpretSale')->call('discardSaleSuggestion')->assertSet('aiText', '')->assertSet('aiReview', null);
        $request = AssistantRequest::firstOrFail();
        $this->assertSame('canceled', $request->status);
        $this->assertNull(Cache::get(app(SalesAssistant::class)->cacheKey($request)));
        $this->assertSame(0, SalesInvoice::count());
    }

    public function test_missing_and_duplicate_customer_names_require_clarification(): void
    {
        Customer::create(['company_id' => $this->company->id, 'name_ar' => 'أحمد ثان', 'name_en' => 'Ahmed', 'code' => 'C-OTHER', 'created_by' => $this->owner->id]);
        $resolved = app(SalesProposalResolver::class)->resolve($this->company, $this->owner, $this->proposal);
        $this->assertNull($resolved['payload']);
        $this->assertContains('customer', $resolved['questions']);
        $this->proposal['customer'] = 'C-AHMED';
        $resolved = app(SalesProposalResolver::class)->resolve($this->company, $this->owner, $this->proposal);
        $this->assertSame((int) $this->customer->id, $resolved['payload']['customer_id']);
        $this->proposal['lines'][0]['unit'] = null;
        $resolved = app(SalesProposalResolver::class)->resolve($this->company, $this->owner, $this->proposal);
        $this->assertNull($resolved['payload']);
        $this->assertContains('unit', $resolved['questions']);
    }

    public function test_changed_request_payload_cannot_reuse_an_idempotency_key(): void
    {
        $key = (string) Str::uuid();
        app(SalesAssistant::class)->interpret($this->company, $this->owner, 'Five cartons', $key, true);
        $this->expectException(AssistantUnavailable::class);
        app(SalesAssistant::class)->interpret($this->company, $this->owner, 'Six cartons', $key, true);
    }

    public function test_revoked_membership_refuses_an_already_resolved_token(): void
    {
        $result = app(SalesAssistant::class)->interpret($this->company, $this->owner, 'Five cartons', (string) Str::uuid(), true);
        CompanyUser::where('company_id', $this->company->id)->where('user_id', $this->owner->id)->update(['status' => 'inactive']);
        $this->expectException(AuthorizationException::class);
        app(SalesAssistant::class)->apply($this->company, $this->owner, $result['token']);
    }

    public function test_proposal_expires_and_changed_default_price_requires_reinterpretation(): void
    {
        unset($this->proposal['lines'][0]['unit_price']);
        $this->fakeIntent($this->proposal);
        $assistant = app(SalesAssistant::class);
        $result = $assistant->interpret($this->company, $this->owner, 'Five cartons', (string) Str::uuid(), true);
        $this->aiCarton->update(['default_sale_price_base' => '81']);
        try {
            $assistant->apply($this->company, $this->owner, $result['token']);
            $this->fail('Changed default must invalidate the proposal.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('proposal_changed', $e->reason);
        }
        $this->travel(601)->seconds();
        try {
            $assistant->apply($this->company, $this->owner, $result['token']);
            $this->fail('Expired token must fail.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('proposal_expired', $e->reason);
        }
        $this->assertSame(0, SalesInvoice::count());
    }

    public function test_budget_reservation_survives_failure_and_blocks_more_provider_calls(): void
    {
        config(['ai_assistant.daily_user_cost_ceiling_micros' => 1000]);
        $provider = $this->fakeIntent($this->proposal, 20);
        try {
            app(SalesAssistant::class)->interpret($this->company, $this->owner, 'Five cartons', (string) Str::uuid(), true);
            $this->fail('Low confidence must fail.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('low_confidence', $e->reason);
        }
        try {
            app(SalesAssistant::class)->interpret($this->company, $this->owner, 'Five cartons', (string) Str::uuid(), true);
            $this->fail('Exhausted reservation must fail.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('budget', $e->reason);
        }
        $this->assertSame(1, $provider->calls);
        $this->assertSame(1, AssistantRequest::count());
        $summary = app(AssistantBudget::class)->summary($this->company, $this->owner);
        $this->assertSame(1000, $summary['daily_reserved_micros']);
        $this->assertSame(1000, $summary['monthly_reserved_micros']);
    }

    public function test_disabling_ai_preserves_applied_manual_draft_save_and_audit(): void
    {
        $component = Livewire::test(InvoiceForm::class)->set('aiConsent', true)->set('aiText', 'Five cartons')
            ->call('interpretSale')->call('applySaleSuggestion');
        config(['ai_assistant.enabled' => false]);
        $component->call('save', false)->assertRedirect();
        $this->assertTrue(SalesInvoice::firstOrFail()->isDraft());
        $this->assertSame('accepted', AssistantRequest::firstOrFail()->status);
        $this->assertSame(0, PostingBatch::count());
    }

    public function test_expired_proposal_cache_is_pruned_without_removing_usage_audit(): void
    {
        $assistant = app(SalesAssistant::class);
        $assistant->interpret($this->company, $this->owner, 'Five cartons', (string) Str::uuid(), true);
        $request = AssistantRequest::firstOrFail();
        $this->travel(601)->seconds();
        $this->artisan('ai-assistant:prune')->assertSuccessful();
        $this->assertSame('expired', $request->fresh()->status);
        $this->assertNull(Cache::get($assistant->cacheKey($request)));
        $this->assertSame(1, AssistantRequest::count());
        $this->assertSame(0, DB::table('sales_invoices')->count());
    }
}
