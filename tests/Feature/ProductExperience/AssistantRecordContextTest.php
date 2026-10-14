<?php

declare(strict_types=1);

namespace Tests\Feature\ProductExperience;

use App\Actions\Company\CreateCompanyAction;
use App\Application\AI\AssistantRecordContext;
use App\Application\AI\AssistantTurnContext;
use App\Application\AI\AssistantTurnService;
use App\Application\AI\AssistantUnavailable;
use App\Models\AssistantRequest;
use App\Models\Customer;
use App\Models\PostingLine;
use App\Models\SalesInvoice;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Vendor;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\AiV22\AccountingTurnFixtures;
use Tests\TestCase;

/** Reuse canonical posted fixtures without inheriting unrelated acceptance test methods. */
final class AssistantRecordContextTest extends TestCase
{
    use AccountingTurnFixtures;
    use RefreshDatabase;

    private function current(): AssistantRecordContext
    {
        return AssistantRecordContext::resolve($this->company, $this->owner, 'customer', $this->customer->public_id);
    }

    private function answer(array $filters, string $key = 'customers.balances'): array
    {
        return ['id' => 'synthetic', 'choices' => [['index' => 0, 'finish_reason' => 'tool_calls', 'message' => [
            'role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => [
                'name' => 'run_report', 'arguments' => json_encode(['version' => 1, 'type' => 'report_query',
                    'report_key' => $key, 'filters' => $filters, 'display' => 'summary'], JSON_THROW_ON_ERROR),
            ]]],
        ]]], 'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50]];
    }

    public function test_record_customer_ar_en_uses_exact_canonical_balance_without_model_or_financial_writes(): void
    {
        Http::fake();
        $before = [SalesInvoice::count(), PostingLine::count(), StockMovement::count()];
        foreach (['قديش عليه دين؟', 'what does this customer owe?'] as $question) {
            $result = app(AssistantTurnService::class)->run($this->company, $this->owner, $question,
                (string) Str::uuid(), 'record-session', null, $this->current());
            $this->assertSame('answer', $result['status']);
            $this->assertSame(0, $result['model_calls']);
            $this->assertCount(1, $result['reports'][0]['rows']);
            $this->assertSame('C-AHMED', $result['reports'][0]['rows'][0]['customer_code']);
            $this->assertSame('1000.000000', $result['reports'][0]['rows'][0]['outstanding_balance']);
        }
        $this->assertSame($before, [SalesInvoice::count(), PostingLine::count(), StockMovement::count()]);
        Http::assertNothingSent();
    }

    public function test_record_model_receives_only_screen_type_and_placeholder_then_local_resolution(): void
    {
        Http::fake(['*' => Http::response($this->answer(['customer_name' => '@current']))]);
        $result = app(AssistantTurnService::class)->run($this->company, $this->owner,
            'Can you tell me his current balance?', (string) Str::uuid(), 'record-session', null, $this->current());
        $this->assertSame('answer', $result['status']);
        $this->assertSame('1000.000000', $result['reports'][0]['rows'][0]['outstanding_balance']);
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            $payload = json_encode($request->data(), JSON_THROW_ON_ERROR);
            foreach (['C-AHMED', 'Ahmed', 'أحمد', '1000.000000', $this->customer->public_id] as $private) {
                $this->assertStringNotContainsString($private, $payload);
            }

            return true;
        });
    }

    public function test_record_context_cannot_be_dropped_or_used_for_another_entity_type(): void
    {
        Http::fake(['*' => Http::response($this->answer(['currency_code' => 'ILS']))]);
        $result = app(AssistantTurnService::class)->run($this->company, $this->owner,
            'Can you tell me his current balance?', (string) Str::uuid(), 'record-session', null, $this->current());
        $this->assertSame('clarification', $result['status']);
        $this->assertSame([], $result['reports']);
        $this->assertFalse($this->current()->permitsReference('vendor_name', 'C-AHMED'));
    }

    public function test_record_currency_and_period_qualifiers_are_not_consumed_by_shortcuts(): void
    {
        $this->assertNull($this->current()->shortcut('what does this customer owe in USD?'));
        Http::fake(['*' => Http::response($this->answer(['customer_name' => '@current']))]);
        $result = app(AssistantTurnService::class)->run($this->company, $this->owner,
            'what does this customer owe in USD?', (string) Str::uuid(), 'record-session', null, $this->current());
        $this->assertSame('clarification', $result['status']);
        $this->assertSame([], $result['reports']);
    }

    public function test_record_deleted_context_fails_before_reservation_or_dispatch(): void
    {
        $unposted = Customer::create(['company_id' => $this->company->id, 'code' => 'C-DELETE', 'name_ar' => 'حذف', 'name_en' => 'Disposable']);
        $context = AssistantRecordContext::resolve($this->company, $this->owner, 'customer', $unposted->public_id);
        $unposted->delete();
        Http::fake();
        $before = AssistantRequest::count();
        try {
            app(AssistantTurnService::class)->run($this->company, $this->owner, 'what does this customer owe?',
                (string) Str::uuid(), 'record-session', null, $context);
            $this->fail('A deleted screen broadened the answer.');
        } catch (AssistantUnavailable $error) {
            $this->assertSame('proposal_stale', $error->reason);
        }
        $this->assertSame($before, AssistantRequest::count());
        Http::assertNothingSent();
    }

    public function test_record_permission_downgrade_is_rechecked_before_delivery(): void
    {
        $context = $this->current();
        Http::fake(function (): mixed {
            DB::table('model_has_roles')->where('model_id', $this->owner->id)->where('company_id', $this->company->id)->delete();

            return Http::response($this->answer(['customer_name' => '@current']));
        });
        try {
            app(AssistantTurnService::class)->run($this->company, $this->owner,
                'Can you tell me his current balance?', (string) Str::uuid(), 'record-session', null, $context);
            $this->fail('Revoked grants returned a sensitive answer.');
        } catch (AssistantUnavailable|AuthorizationException $error) {
            $this->assertNotSame('', $error->getMessage());
        }
    }

    public function test_record_context_and_request_key_cannot_be_reused_for_another_customer(): void
    {
        Http::fake();
        $key = (string) Str::uuid();
        $service = app(AssistantTurnService::class);
        $first = $service->run($this->company, $this->owner, 'what does this customer owe?', $key, 'record-session', null, $this->current());
        $other = Customer::create(['company_id' => $this->company->id, 'code' => 'C-OTHER', 'name_ar' => 'سامر', 'name_en' => 'Samer']);
        $otherContext = AssistantRecordContext::resolve($this->company, $this->owner, 'customer', $other->public_id);
        try {
            $service->run($this->company, $this->owner, 'what does this customer owe?', $key, 'record-session', null, $otherContext);
            $this->fail('A request key changed its record.');
        } catch (AssistantUnavailable $error) {
            $this->assertSame('request_conflict', $error->reason);
        }
        try {
            app(AssistantTurnContext::class)->read($this->company, $this->owner, 'record-session', $first['conversation_token'], $otherContext);
            $this->fail('A follow-up changed its record.');
        } catch (AssistantUnavailable $error) {
            $this->assertSame('proposal_stale', $error->reason);
        }
    }

    public function test_record_disabled_turn_flag_is_never_bypassed(): void
    {
        config(['ai_assistant.turn_enabled' => false]);
        $this->expectException(AssistantUnavailable::class);
        app(AssistantTurnService::class)->run($this->company, $this->owner, 'what does this customer owe?',
            (string) Str::uuid(), 'record-session', null, $this->current());
    }

    public function test_record_route_is_server_derived_and_launcher_retains_it(): void
    {
        $route = new Route('GET', 'customers/{publicId}', []);
        $route->name('customers.show')->bind(\Illuminate\Http\Request::create('/customers/'.$this->customer->public_id));
        $this->assertSame(['type' => 'customer', 'public_id' => $this->customer->public_id], AssistantRecordContext::fromRoute($route));
        $response = $this->withSession(['active_company_id' => $this->company->id])->get('/customers/'.$this->customer->public_id)->assertOk();
        preg_match_all('/wire:snapshot="([^"]+)"/', $response->getContent(), $snapshots);
        $found = false;
        foreach ($snapshots[1] as $snapshot) {
            $data = json_decode(html_entity_decode($snapshot, ENT_QUOTES, 'UTF-8'), true, 64, JSON_THROW_ON_ERROR);
            if (($data['memo']['name'] ?? null) === 'ai.assistant-launcher') {
                $found = true;
                $this->assertSame('customer', $data['data']['recordType']);
                $this->assertSame($this->customer->public_id, $data['data']['recordPublicId']);
            }
        }
        $this->assertTrue($found, 'Actual customer route must bind the nested launcher.');
    }

    public function test_record_foreign_company_and_revoked_membership_are_denied(): void
    {
        $otherActor = User::factory()->create();
        app(CompanyContext::class)->clear();
        $otherCompany = app(CreateCompanyAction::class)->execute($otherActor, ['name_ar' => 'شركة ثانية', 'name_en' => 'Other company', 'base_currency_code' => 'ILS']);
        $this->actingAs($otherActor);
        app(CompanyContext::class)->setCompany($otherCompany, $otherActor);
        $foreign = Customer::create(['company_id' => $otherCompany->id, 'code' => 'C-FOREIGN', 'name_ar' => 'غريب', 'name_en' => 'Foreign']);
        $this->actingAs($this->owner);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        try {
            AssistantRecordContext::resolve($this->company, $this->owner, 'customer', $foreign->public_id);
            $this->fail('Foreign screen identity leaked.');
        } catch (AssistantUnavailable $error) {
            $this->assertSame('proposal_stale', $error->reason);
        }
        $context = $this->current();
        DB::table('company_user')->where('company_id', $this->company->id)->where('user_id', $this->owner->id)->update(['status' => 'inactive']);
        $this->expectException(AuthorizationException::class);
        $context->revalidate($this->company, $this->owner);
    }

    public function test_record_followup_cannot_cross_session_or_actor_and_retains_no_server_name_in_model_prior(): void
    {
        $context = $this->current();
        $proposals = $context->shortcut('what does this customer owe?');
        $this->assertSame('@current', $context->modelPrior($proposals)[0]['filters']['customer_name']);
        $token = app(AssistantTurnContext::class)->store($this->company, $this->owner, 'record-session', $proposals, $context);
        $this->expectException(AssistantUnavailable::class);
        app(AssistantTurnContext::class)->read($this->company, $this->owner, 'another-session', $token, $context);
    }

    public function test_record_vendor_and_product_shortcuts_execute_only_scoped_canonical_reports(): void
    {
        $settings = $this->aiPolicy->settings;
        $settings['questions']['allowed_reports'] = [...$settings['questions']['allowed_reports'], 'vendors.balances', 'inventory.stock'];
        $this->aiPolicy->update(['settings' => $settings]);
        $vendor = Vendor::create(['company_id' => $this->company->id, 'code' => 'V-CURRENT', 'name_ar' => 'المورد الحالي', 'name_en' => 'Current vendor']);
        $vendorContext = AssistantRecordContext::resolve($this->company, $this->owner, 'vendor', $vendor->public_id);
        $productContext = AssistantRecordContext::resolve($this->company, $this->owner, 'product', $this->lowStockProduct->public_id);
        Http::fake();
        foreach ([[$vendorContext, 'what do we owe this vendor?', 'vendors.balances'],
            [$productContext, 'how much of this product is in stock?', 'inventory.stock']] as [$context, $question, $key]) {
            $result = app(AssistantTurnService::class)->run($this->company, $this->owner, $question,
                (string) Str::uuid(), 'record-session', null, $context);
            $this->assertSame('answer', $result['status'], $key.': '.($result['reason'] ?? ''));
            $this->assertSame($key, $result['reports'][0]['report_key']);
            $this->assertSame(0, $result['model_calls']);
            parse_str(parse_url($result['reports'][0]['source_url'], PHP_URL_QUERY), $sourceFilters);
            $this->assertSame((string) ($key === 'vendors.balances' ? $vendor->id : $this->lowStockProduct->id),
                $sourceFilters['filters'][$key === 'vendors.balances' ? 'vendor_id' : 'product_id']);
            foreach ($result['reports'][0]['rows'] as $row) {
                if ($key === 'vendors.balances') {
                    $this->assertSame('V-CURRENT', $row['vendor_code']);
                } else {
                    $this->assertSame($this->lowStockProduct->sku, $row['sku']);
                }
            }
        }
        Http::assertNothingSent();
    }

    public function test_record_invoice_uses_its_customer_but_never_substitutes_latest_invoice_for_exact_details(): void
    {
        $context = AssistantRecordContext::resolve($this->company, $this->owner, 'invoice', $this->sepInvoice->public_id);
        Http::fake();
        $result = app(AssistantTurnService::class)->run($this->company, $this->owner, 'قديش عليه دين؟',
            (string) Str::uuid(), 'record-session', null, $context);
        $this->assertSame('1000.000000', $result['reports'][0]['rows'][0]['outstanding_balance']);
        Http::assertNothingSent();
        $this->expectException(AssistantUnavailable::class);
        $context->bind([['filters' => ['customer_name' => '@current']]], 'What is the total of this invoice?');
    }

    public function test_record_compound_question_keeps_company_comparison_independent_of_current_customer(): void
    {
        $mock = $this->answer(['customer_name' => '@current']);
        $mock['choices'][0]['message']['tool_calls'][] = ['id' => 'call_2', 'type' => 'function', 'function' => [
            'name' => 'run_report', 'arguments' => json_encode(['version' => 1, 'type' => 'report_query',
                'report_key' => 'sales.summary', 'filters' => ['preset' => 'this_month', 'compare' => 'previous_period'],
                'display' => 'summary'], JSON_THROW_ON_ERROR),
        ]];
        Http::fake(['*' => Http::response($mock)]);
        $result = app(AssistantTurnService::class)->run($this->company, $this->owner,
            'How much does this customer owe?; Compare company sales this month with last month.',
            (string) Str::uuid(), 'record-session', null, $this->current());
        $this->assertSame('answer', $result['status']);
        $this->assertCount(2, $result['reports']);
        $this->assertSame('1000.000000', $result['reports'][0]['rows'][0]['outstanding_balance']);
        $this->assertSame('800.000000', $result['reports'][1]['totals']['net_sales_base']);
        $this->assertSame('450.000000', $result['reports'][1]['comparison']['totals']['net_sales_base']);
        $this->assertStringNotContainsString('customer_id', $result['reports'][1]['source_url']);
        Http::assertSentCount(1);
    }
}
