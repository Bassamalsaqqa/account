<?php

declare(strict_types=1);

namespace Tests\Feature\ProductExperience;

use App\Actions\Company\CreateCompanyAction;
use App\Application\AI\AssistantRecordContext;
use App\Application\AI\AssistantTurnService;
use App\Application\AI\AssistantUnavailable;
use App\Models\Customer;
use App\Models\PostingLine;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Sales\DocumentDataBuilder;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\AiV22\AccountingTurnFixtures;
use Tests\TestCase;

/**
 * Focused tests for bounded actual CURRENT sales invoice answers on invoice screen context.
 * Reuses canonical AccountingTurnFixtures trait and owned MariaDB/RefreshDatabase harness.
 */
final class CurrentInvoiceContextTest extends TestCase
{
    use AccountingTurnFixtures;
    use RefreshDatabase;

    private function invoiceContext(SalesInvoice $invoice): AssistantRecordContext
    {
        return AssistantRecordContext::resolve($this->company, $this->owner, 'invoice', $invoice->public_id);
    }

    public function test_current_invoice_en_returns_exact_details_without_llm_or_financial_writes(): void
    {
        Http::fake();
        // Sep invoice is 450.00 ILS and paid in full (sepPayment: 450.00 ILS, payment_status: paid, outstanding: 0.000000)
        // Ahmed's latest invoice is octInvoice2 (300.00 ILS), and Ahmed's total debt is 1000.00 ILS.
        $context = $this->invoiceContext($this->sepInvoice);

        $before = [SalesInvoice::count(), PostingLine::count(), StockMovement::count()];

        $result = app(AssistantTurnService::class)->run(
            $this->company,
            $this->owner,
            'what is this invoice total?',
            (string) Str::uuid(),
            'inv-session-1',
            null,
            $context
        );

        $this->assertSame('answer', $result['status']);
        $this->assertSame(0, $result['model_calls']);
        Http::assertNothingSent();

        // Financial fingerprint must remain completely unchanged (no writes)
        $this->assertSame($before, [SalesInvoice::count(), PostingLine::count(), StockMovement::count()]);

        $report = $result['reports'][0];
        $this->assertSame('sales.last-invoice', $report['report_key']);
        $this->assertCount(1, $report['rows']);

        $row = $report['rows'][0];
        $this->assertSame($this->sepInvoice->invoice_number, $row['invoice_number']);
        $this->assertSame('2026-09-15', $row['issue_date']);
        $this->assertSame('posted', $row['status']);
        $this->assertSame('paid', $row['payment_status']);
        $this->assertSame('ILS', $row['currency_code']);
        $this->assertSame('450.000000', $row['grand_total_currency']);
        $this->assertSame('0.000000', $row['outstanding_balance']);

        // Totals check
        $this->assertSame('450.000000', $report['totals']['grand_total_currency']);
        $this->assertSame('0.000000', $report['totals']['outstanding_balance']);
        $this->assertSame(route('invoices.show', ['publicId' => $this->sepInvoice->public_id]), $report['source_url']);
    }

    public function test_current_invoice_ar_returns_exact_details_without_llm(): void
    {
        Http::fake();
        $context = $this->invoiceContext($this->sepInvoice);

        $result = app(AssistantTurnService::class)->run(
            $this->company,
            $this->owner,
            'شو تفاصيل هالفاتورة؟',
            (string) Str::uuid(),
            'inv-session-2',
            null,
            $context
        );

        $this->assertSame('answer', $result['status']);
        $this->assertSame(0, $result['model_calls']);
        Http::assertNothingSent();

        $report = $result['reports'][0];
        $row = $report['rows'][0];
        $this->assertSame($this->sepInvoice->invoice_number, $row['invoice_number']);
        $this->assertSame('450.000000', $row['grand_total_currency']);
        $this->assertSame('450.000000', $row['subtotal_currency']);
        $this->assertSame('0.000000', $row['discount_total_currency']);
        $this->assertSame('0.000000', $row['tax_total_currency']);
        $this->assertSame('paid', $row['payment_status']);
        $this->assertSame('0.000000', $row['outstanding_balance']);
    }

    public function test_current_invoice_unpaid_nonlatest_shows_truthful_outstanding(): void
    {
        Http::fake();
        // octInvoice1 is 500.00 ILS (unpaid, outstanding: 500.000000), while Ahmed's latest invoice is octInvoice2 (300.00 ILS)
        $context = $this->invoiceContext($this->octInvoice1);

        $result = app(AssistantTurnService::class)->run(
            $this->company,
            $this->owner,
            'what is this invoice total?',
            (string) Str::uuid(),
            'inv-session-3',
            null,
            $context
        );

        $this->assertSame('answer', $result['status']);
        $this->assertSame(0, $result['model_calls']);
        Http::assertNothingSent();

        $row = $result['reports'][0]['rows'][0];
        $this->assertSame($this->octInvoice1->invoice_number, $row['invoice_number']);
        $this->assertSame('500.000000', $row['grand_total_currency']);
        $this->assertSame('500.000000', $row['outstanding_balance']);
        $this->assertSame('unpaid', $row['payment_status']);
    }

    public function test_current_invoice_never_substitutes_latest_invoice_or_customer_aggregate(): void
    {
        Http::fake();
        // User is viewing sepInvoice (450.00 ILS, fully paid)
        $context = $this->invoiceContext($this->sepInvoice);

        $result = app(AssistantTurnService::class)->run(
            $this->company,
            $this->owner,
            'what is this invoice total?',
            (string) Str::uuid(),
            'inv-session-4',
            null,
            $context
        );

        $report = $result['reports'][0];
        $row = $report['rows'][0];

        // Must be sepInvoice
        $this->assertSame($this->sepInvoice->invoice_number, $row['invoice_number']);
        $this->assertSame('450.000000', $row['grand_total_currency']);

        // Must NEVER substitute Ahmed's latest invoice (octInvoice2: 300.00 ILS)
        $this->assertNotSame($this->octInvoice2->invoice_number, $row['invoice_number']);
        $this->assertNotSame('300.000000', $row['grand_total_currency']);

        // Must NEVER substitute customer aggregate debt (1000.00 ILS)
        $this->assertNotSame('1000.000000', $row['grand_total_currency']);
        $this->assertNotSame('1000.000000', $row['outstanding_balance']);
    }

    public function test_current_invoice_policy_denied_when_report_capability_not_allowed(): void
    {
        Http::fake();
        // Remove sales.last-invoice from allowed reports policy
        $settings = $this->aiPolicy->settings;
        $settings['questions']['allowed_reports'] = array_values(array_diff($settings['questions']['allowed_reports'], ['sales.last-invoice']));
        $this->aiPolicy->update(['settings' => $settings]);

        $context = $this->invoiceContext($this->sepInvoice);

        $this->expectException(AssistantUnavailable::class);
        app(AssistantTurnService::class)->run(
            $this->company,
            $this->owner,
            'what is this invoice total?',
            (string) Str::uuid(),
            'inv-session-5',
            null,
            $context
        );
    }

    public function test_current_invoice_permission_denied_when_sales_invoice_view_revoked(): void
    {
        Http::fake();
        $context = $this->invoiceContext($this->sepInvoice);

        // Revoke user role & permissions
        DB::table('model_has_roles')->where('model_id', $this->owner->id)->where('company_id', $this->company->id)->delete();

        try {
            app(AssistantTurnService::class)->run(
                $this->company,
                $this->owner,
                'what is this invoice total?',
                (string) Str::uuid(),
                'inv-session-6',
                null,
                $context
            );
            $this->fail('Revoked sales.invoice.view must be rejected.');
        } catch (AssistantUnavailable|AuthorizationException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
    }

    public function test_current_invoice_tenant_and_foreign_company_denied(): void
    {
        $otherActor = User::factory()->create();
        app(CompanyContext::class)->clear();
        $otherCompany = app(CreateCompanyAction::class)->execute($otherActor, [
            'name_ar' => 'شركة أخرى',
            'name_en' => 'Other Company',
            'base_currency_code' => 'ILS',
        ]);
        $this->actingAs($otherActor);
        app(CompanyContext::class)->setCompany($otherCompany, $otherActor);

        // sepInvoice belongs to $this->company, not $otherCompany
        $this->expectException(AssistantUnavailable::class);
        AssistantRecordContext::resolve($otherCompany, $otherActor, 'invoice', $this->sepInvoice->public_id);
    }

    public function test_current_invoice_session_tampering_is_rejected(): void
    {
        Http::fake();
        $context = $this->invoiceContext($this->sepInvoice);

        $result = app(AssistantTurnService::class)->run(
            $this->company,
            $this->owner,
            'what is this invoice total?',
            (string) Str::uuid(),
            'session-alpha',
            null,
            $context
        );

        // This exact invoice read is not a latest-customer report plan: no misleading follow-up token.
        $this->assertNull($result['conversation_token']);
        $this->expectException(AssistantUnavailable::class);
        app(AssistantTurnService::class)->run($this->company, $this->owner,
            'what is this invoice total?', $result['request_key'], 'session-beta', null, $context);
    }

    public function test_current_invoice_qualifiers_cannot_drop_currency_or_period(): void
    {
        $context = $this->invoiceContext($this->sepInvoice);

        // Shortcuts must NOT consume qualified requests
        $this->assertNull($context->shortcut('what is this invoice total in USD?'));
        $this->assertNull($context->shortcut('what is this invoice total this month?'));
        $this->assertNull($context->shortcut('شو تفاصيل هالفاتورة بالدولار؟'));

        Http::fake();
        foreach (['what is this invoice total in USD?', 'what is this invoice total in bitcoin for Samer?',
            'what is this invoice total this month?', 'شو تفاصيل هالفاتورة بالدولار؟',
            'what is this invoice total; what is his last invoice?'] as $text) {
            $result = app(AssistantTurnService::class)->run($this->company, $this->owner,
                $text, (string) Str::uuid(), 'inv-qualifier-session', null, $context);
            $this->assertSame('clarification', $result['status']);
            $this->assertSame([], $result['reports']);
            $this->assertSame(0, $result['model_calls']);
        }
        Http::assertNothingSent();
    }

    public function test_current_invoice_no_sensitive_cost_or_profit_fields_in_response(): void
    {
        Http::fake();
        $context = $this->invoiceContext($this->sepInvoice);

        $result = app(AssistantTurnService::class)->run(
            $this->company,
            $this->owner,
            'what is this invoice total?',
            (string) Str::uuid(),
            'inv-session-8',
            null,
            $context
        );

        $serialized = json_encode($result, JSON_THROW_ON_ERROR);

        // Restricted cost, profit, and internal ledger fields must never be exposed
        $this->assertStringNotContainsString('cogs_total_base', $serialized);
        $this->assertStringNotContainsString('cogs_base', $serialized);
        $this->assertStringNotContainsString('gross_profit_base', $serialized);
        $this->assertStringNotContainsString('line_cost_base', $serialized);
        $this->assertStringNotContainsString('posting_batch_id', $serialized);
    }

    public function test_current_invoice_replay_safety(): void
    {
        Http::fake();
        $key = (string) Str::uuid();
        $context = $this->invoiceContext($this->sepInvoice);
        $service = app(AssistantTurnService::class);

        $first = $service->run($this->company, $this->owner, 'what is this invoice total?', $key, 'replay-session', null, $context);
        $this->assertSame('answer', $first['status']);
        $this->assertSame('450.000000', $first['reports'][0]['rows'][0]['grand_total_currency']);

        // Replay with identical key must return same answer with zero model calls
        $replayed = $service->run($this->company, $this->owner, 'what is this invoice total?', $key, 'replay-session', null, $context);
        $this->assertSame('answer', $replayed['status']);
        $this->assertTrue($replayed['replayed'] ?? false);
        $this->assertSame(0, $replayed['model_calls']);
        $this->assertSame('450.000000', $replayed['reports'][0]['rows'][0]['grand_total_currency']);
        $this->assertSame($this->sepInvoice->invoice_number, $replayed['reports'][0]['rows'][0]['invoice_number']);
        Http::assertNothingSent();
    }

    public function test_existing_customer_questions_on_invoice_screen_remain_supported(): void
    {
        Http::fake();
        // Asking about customer's debt while viewing the invoice screen
        $context = $this->invoiceContext($this->sepInvoice);

        $result = app(AssistantTurnService::class)->run(
            $this->company,
            $this->owner,
            'قديش عليه دين؟',
            (string) Str::uuid(),
            'inv-session-cust',
            null,
            $context
        );

        $this->assertSame('answer', $result['status']);
        $this->assertSame(0, $result['model_calls']);
        $this->assertSame('1000.000000', $result['reports'][0]['rows'][0]['outstanding_balance']);
        $this->assertSame('C-AHMED', $result['reports'][0]['rows'][0]['customer_code']);
        Http::assertNothingSent();
    }

    public function test_current_invoice_read_never_changes_later_customer_latest_intent(): void
    {
        Http::fake();
        $context = $this->invoiceContext($this->sepInvoice);
        app(AssistantTurnService::class)->run($this->company, $this->owner,
            'what is this invoice total?', (string) Str::uuid(), 'mixed-session', null, $context);
        $result = app(AssistantTurnService::class)->run($this->company, $this->owner,
            'what is his last invoice?', (string) Str::uuid(), 'mixed-session', null, $context);
        $this->assertSame('answer', $result['status']);
        $this->assertSame($this->octInvoice2->invoice_number, $result['reports'][0]['rows'][0]['invoice_number']);
        $this->assertNotSame($this->sepInvoice->invoice_number, $result['reports'][0]['rows'][0]['invoice_number']);
        Http::assertNothingSent();
    }

    public function test_customer_permission_revoked_during_document_build_blocks_delivery(): void
    {
        $context = $this->invoiceContext($this->sepInvoice);
        $revoked = false;
        DB::listen(function ($query) use (&$revoked): void {
            if (! $revoked && str_contains($query->sql, 'sales_invoice_lines')) {
                $revoked = true;
                $permission = DB::table('permissions')->where('name', 'customers.view')->value('id');
                $roles = DB::table('model_has_roles')->where('model_id', $this->owner->id)
                    ->where('company_id', $this->company->id)->pluck('role_id');
                DB::table('role_has_permissions')->whereIn('role_id', $roles)->where('permission_id', $permission)->delete();
            }
        });
        try {
            app(AssistantTurnService::class)->run($this->company, $this->owner,
                'what is this invoice total?', (string) Str::uuid(), 'downgrade-session', null, $context);
            $this->fail('Revoked customer permission must prevent invoice answer delivery.');
        } catch (AuthorizationException $e) {
            $this->assertTrue($revoked);
        }
    }

    public function test_invoice_lines_render_ar_en_with_historical_snapshots_and_currency_formatting(): void
    {
        $expected = app(DocumentDataBuilder::class)->build($this->sepInvoice, false, 'ar')->lines[0]['item_description'];
        Product::withoutGlobalScopes()->where('company_id', $this->company->id)
            ->update(['name_ar' => 'اسم جديد لا يمثل الفاتورة', 'name_en' => 'New current product name']);
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $result = app(AssistantTurnService::class)->run($this->company, $this->owner,
                $locale === 'ar' ? 'شو تفاصيل هالفاتورة؟' : 'this invoice details',
                (string) Str::uuid(), 'render-session', null, $this->invoiceContext($this->sepInvoice));
            $answer = $result['reports'][0];
            $this->assertSame('current_invoice', $answer['scope']['basis']);
            $this->assertNotEmpty($answer['scope']['lines']);
            $this->assertLessThanOrEqual(5, count($answer['scope']['lines']));
            $html = view('livewire.ai.partials.current-invoice', ['answer' => $answer])->render();
            $this->assertStringContainsString('450.00', $html);
            $this->assertStringNotContainsString('450.000000', $html);
            $this->assertStringNotContainsString('New current product name', $html);
            $this->assertStringNotContainsString('اسم جديد لا يمثل الفاتورة', $html);
            if ($locale === 'ar') {
                $this->assertStringContainsString(e($expected), $html);
            }
        }
    }
}
