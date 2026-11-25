<?php

declare(strict_types=1);

namespace Tests\Feature\Phase4;

use App\Actions\Sales\ConvertQuotationToInvoiceAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Livewire\Pages\Sales\InvoiceForm;
use App\Livewire\Pages\Sales\QuotationDetail;
use App\Livewire\Pages\Sales\QuotationForm;
use App\Models\AuditEvent;
use App\Models\Quotation;
use App\Models\TaxRate;
use App\Services\Sales\SalesReconciliationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SalesCorrectionFixtures;
use Tests\TestCase;

class QuotationEditAndDeletionCorrectionTest extends TestCase
{
    use RefreshDatabase;
    use SalesCorrectionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->salesFixtures();
    }

    public static function locales(): array
    {
        return [['ar'], ['en']];
    }

    #[DataProvider('locales')]
    public function test_sent_returns_to_draft_before_editing_and_resend_preserves_first_send(string $locale): void
    {
        app()->setLocale($locale);
        $quote = $this->quote();
        Livewire::test(QuotationForm::class, ['publicId' => $quote->public_id])->assertStatus(200);
        Livewire::test(QuotationDetail::class, ['publicId' => $quote->public_id])
            ->assertViewHas('canEdit', true)->assertViewHas('canReturnToDraft', false)
            ->assertSeeHtml('href="'.route('quotations.edit', $quote->public_id).'"');
        $quote->transition(Quotation::STATUS_SENT, $this->owner);
        $sentAt = $quote->sent_at->toDateTimeString();
        Livewire::test(QuotationForm::class, ['publicId' => $quote->public_id])->assertStatus(400);
        $detail = Livewire::test(QuotationDetail::class, ['publicId' => $quote->public_id])
            ->assertViewHas('canEdit', false)->assertViewHas('canReturnToDraft', true)
            ->assertDontSeeHtml('href="'.route('quotations.edit', $quote->public_id).'"')
            ->assertSee(__('sales.return_to_draft'));
        $this->assertSame(Quotation::STATUS_SENT, $quote->fresh()->status);
        $this->travel(1)->hours();
        $detail->call('returnToDraft')->assertStatus(200)
            ->assertViewHas('canEdit', true)->assertViewHas('canReturnToDraft', false)
            ->assertSeeHtml('href="'.route('quotations.edit', $quote->public_id).'"');
        $this->assertSame(Quotation::STATUS_DRAFT, $quote->fresh()->status);
        $this->assertSame($sentAt, $quote->fresh()->sent_at->toDateTimeString());
        $audit = AuditEvent::where('event_key', 'sales.quote.transitioned')->where('subject_id', $quote->id)->latest('id')->firstOrFail();
        $this->assertSame(['status' => 'sent'], $audit->before_json);
        $this->assertSame(['status' => 'draft'], $audit->after_json);
        $this->assertSame($this->owner->id, $audit->actor_user_id);
        Livewire::test(QuotationForm::class, ['publicId' => $quote->public_id])->assertStatus(200)
            ->set('notes', 'Edited draft')->call('save')->assertHasNoErrors()
            ->assertRedirect(route('quotations.show', $quote->public_id));
        $this->assertSame('Edited draft', $quote->fresh()->notes);
        Livewire::test(QuotationDetail::class, ['publicId' => $quote->public_id])->call('markAsSent')->assertStatus(200)
            ->assertViewHas('canEdit', false)->assertViewHas('canReturnToDraft', true);
        $this->assertSame(Quotation::STATUS_SENT, $quote->fresh()->status);
        $this->assertSame($sentAt, $quote->fresh()->sent_at->toDateTimeString());
    }

    public function test_percentage_discount_matches_preview_and_saved_quotation(): void
    {
        $quote = $this->quote();

        Livewire::test(QuotationForm::class, ['publicId' => $quote->public_id])
            ->assertSeeHtml('value="percent"')
            ->set('lines.0.discount_type', 'percent')
            ->set('lines.0.discount_value', '10')
            ->call('recalculate')
            ->assertSet('previewGrandTotal', '90.00')
            ->call('save')
            ->assertHasNoErrors();

        $quote->refresh();
        $this->assertSame('90.000000', $quote->grand_total_currency);
        $this->assertSame('10.000000', $quote->discount_total_currency);
        $this->assertSame('percent', $quote->lines()->sole()->discount_type);
    }

    public function test_old_percentage_discount_is_corrected_when_the_draft_is_saved_again(): void
    {
        $quote = $this->quote();
        DB::table('quotation_lines')->where('quotation_id', $quote->id)->update([
            'discount_type' => 'percentage',
            'discount_value' => '10',
        ]);
        $this->assertSame('100.000000', $quote->grand_total_currency);

        $form = Livewire::test(QuotationForm::class, ['publicId' => $quote->public_id])
            ->assertSet('lines.0.discount_type', 'percent')
            ->assertSet('previewGrandTotal', '90.00');
        $this->assertSame('100.000000', $quote->fresh()->grand_total_currency);

        $form->call('save')->assertHasNoErrors();
        $this->assertSame('90.000000', $quote->fresh()->grand_total_currency);
        $this->assertSame('percent', $quote->lines()->sole()->discount_type);
    }

    public static function salesPreviewForms(): array
    {
        return [[InvoiceForm::class], [QuotationForm::class]];
    }

    #[DataProvider('salesPreviewForms')]
    public function test_live_preview_batches_tax_rates_for_twenty_lines(string $component): void
    {
        $taxRates = [];
        foreach (['16', '5'] as $percentage) {
            $taxRates[] = TaxRate::create([
                'company_id' => $this->company->id,
                'code' => 'VAT'.$percentage,
                'name_ar' => 'ضريبة',
                'rate' => $percentage,
                'calculation' => TaxRate::CALC_EXCLUSIVE,
                'active' => true,
            ])->id;
        }

        $form = Livewire::test($component);
        $line = [...$form->get('lines')[0], 'quantity' => '2', 'unit_price' => '25'];
        $lines = [];
        for ($index = 0; $index < 20; $index++) {
            $lines[] = [...$line, 'tax_rate_id' => $taxRates[$index % 2]];
        }

        $form->set('lines', $lines);
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $form->call('recalculate')->assertSet('previewGrandTotal', '1105.00');
            $taxQueries = array_filter(DB::getQueryLog(), static fn (array $query): bool => str_contains($query['query'], 'from `tax_rates`'));
            $this->assertLessThanOrEqual(2, count($taxQueries));
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    public static function closedStates(): array
    {
        return [['accepted'], ['rejected'], ['expired'], ['converted']];
    }

    #[DataProvider('closedStates')]
    public function test_closed_quotation_cannot_return_to_draft(string $state): void
    {
        $quote = $this->quote();
        $quote->transition('sent', $this->owner);
        $quote->transition($state === 'converted' ? 'accepted' : $state, $this->owner);
        if ($state === 'converted') {
            app(ConvertQuotationToInvoiceAction::class)->execute($quote, $this->owner);
        }
        $detail = Livewire::test(QuotationDetail::class, ['publicId' => $quote->public_id])
            ->assertViewHas('canEdit', false)->assertViewHas('canReturnToDraft', false);
        $auditCount = AuditEvent::count();
        try {
            $detail->call('returnToDraft');
            $this->fail('Closed quotation returned to draft.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame($state, $quote->fresh()->status);
            $this->assertSame($auditCount, AuditEvent::count());
        }
    }

    public function test_revoked_edit_permission_cannot_return_stale_sent_component_to_draft(): void
    {
        $quote = $this->quote();
        $quote->transition('sent', $this->owner);
        $detail = Livewire::test(QuotationDetail::class, ['publicId' => $quote->public_id])->assertViewHas('canReturnToDraft', true);
        $auditCount = AuditEvent::count();
        $this->owner->roles->firstOrFail()->revokePermissionTo('sales.quote.edit');
        $detail->call('returnToDraft')->assertStatus(403);
        $this->assertSame('sent', $quote->fresh()->status);
        $this->assertSame($auditCount, AuditEvent::count());
    }

    public function test_inactive_membership_cannot_return_stale_sent_component_to_draft(): void
    {
        $quote = $this->quote();
        $quote->transition('sent', $this->owner);
        $detail = Livewire::test(QuotationDetail::class, ['publicId' => $quote->public_id]);
        DB::table('company_user')->where('company_id', $this->company->id)->where('user_id', $this->owner->id)->update(['status' => 'inactive']);
        $auditCount = AuditEvent::count();
        $detail->call('returnToDraft')->assertStatus(403);
        $this->assertSame('sent', $quote->fresh()->status);
        $this->assertSame($auditCount, AuditEvent::count());
    }

    public static function deletionPaths(): array
    {
        return [['invoice', false], ['invoice', true], ['quote', false], ['quote', true]];
    }

    #[DataProvider('deletionPaths')]
    public function test_converted_provenance_rejects_model_and_raw_deletion(string $subject, bool $raw): void
    {
        $quote = $this->quote();
        $quote->transition('sent', $this->owner);
        $quote->transition('accepted', $this->owner);
        $invoice = app(ConvertQuotationToInvoiceAction::class)->execute($quote, $this->owner);
        $this->assertSame('draft', $invoice->status);
        try {
            $model = $subject === 'invoice' ? $invoice : $quote->fresh();
            if ($raw) {
                DB::table($model->getTable())->where('id', $model->id)->delete();
            } else {
                // A dirty in-memory link must not bypass persisted provenance protection.
                if ($subject === 'invoice') {
                    $model->quotation_id = null;
                }
                $model->delete();
            }
            $this->fail('Converted provenance was deleted.');
        } catch (ImmutableRecordException|QueryException $exception) {
            if ($raw) {
                $this->assertInstanceOf(QueryException::class, $exception);
                $this->assertSame(1451, $exception->errorInfo[1]);
            } else {
                $this->assertInstanceOf(ImmutableRecordException::class, $exception);
            }
        }
        $this->assertSame('converted', $quote->fresh()->status);
        $this->assertSame($invoice->id, $quote->fresh()->converted_to_invoice_id);
        $this->assertSame($quote->id, $invoice->fresh()->quotation_id);
        $this->assertTrue(app(SalesReconciliationService::class)->reconcile($this->company)->isHealthy);
    }

    public function test_unlinked_drafts_remain_deletable(): void
    {
        $quote = $this->quote();
        $invoice = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id, 'currency_code' => 'ILS', 'exchange_rate' => '1', 'issue_date' => '2026-10-03',
            'lines' => [['item_description' => 'Service', 'quantity' => '1', 'unit_price' => '100']],
        ]);
        $this->assertNull($invoice->quotation_id);
        $this->assertTrue($invoice->delete());
        $this->assertTrue($quote->delete());
        $this->assertDatabaseMissing('sales_invoices', ['id' => $invoice->id]);
        $this->assertDatabaseMissing('quotations', ['id' => $quote->id]);
        $this->assertTrue(app(SalesReconciliationService::class)->reconcile($this->company)->isHealthy);
    }

    public function test_both_provenance_foreign_keys_are_restrictive(): void
    {
        $rows = DB::select("SELECT TABLE_NAME, REFERENCED_TABLE_NAME, DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME IN ('sales_invoices_quotation_id_foreign', 'quotes_converted_invoice_fk')");
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame('RESTRICT', $row->DELETE_RULE);
            $this->assertSame($row->TABLE_NAME === 'quotations' ? 'sales_invoices' : 'quotations', $row->REFERENCED_TABLE_NAME);
        }
    }
}
