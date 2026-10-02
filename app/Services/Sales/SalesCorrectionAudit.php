<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Actions\Sales\ApplyCustomerPaymentCreditAction;
use App\Domain\Sales\Calculators\SalesLineCalculationInput;
use App\Domain\Sales\Calculators\SalesLineCalculator;
use App\Models\SalesInvoice;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

final class SalesCorrectionAudit
{
    /** @return list<string> */
    public function audit(int $companyId): array
    {
        $errors = [];
        foreach (DB::table('customer_payment_allocations')->where('company_id', $companyId)->whereNotNull('application_event_id')->get() as $allocation) {
            $event = DB::table('customer_payment_application_events')->where('id', $allocation->application_event_id)->first();
            if ($event === null || (int) $event->company_id !== $companyId || (int) $event->customer_payment_id !== (int) $allocation->customer_payment_id) {
                $errors[] = "Allocation [{$allocation->id}] application event provenance mismatch.";
            }
        }
        $accounts = DB::table('ledger_accounts')->where('company_id', $companyId)->pluck('id', 'system_key');
        $quotes = DB::table('quotations')->where('company_id', $companyId)->get();
        foreach ($quotes as $quote) {
            $invoices = DB::table('sales_invoices')->where('quotation_id', $quote->id)->get();
            foreach ($invoices as $linked) {
                if ((int) $linked->company_id !== $companyId || (int) $linked->customer_id !== (int) $quote->customer_id) {
                    $errors[] = "Quotation [{$quote->id}] linked invoice company/customer provenance mismatch.";
                }
            }
            if ($invoices->count() > 1) {
                $errors[] = "Quotation [{$quote->id}] links multiple invoices.";
            }
            if ($quote->status === 'converted' && ($quote->converted_to_invoice_id === null || $invoices->count() !== 1
                || (int) $invoices->first()->id !== (int) $quote->converted_to_invoice_id)) {
                $errors[] = "Quotation [{$quote->id}] converted reciprocal invoice missing/mismatched.";
            }
            if ($quote->status !== 'converted' && ($invoices->isNotEmpty() || $quote->converted_to_invoice_id !== null)) {
                $errors[] = "Quotation [{$quote->id}] has invoice links without converted state.";
            }
        }
        foreach (DB::table('sales_invoices')->where('company_id', $companyId)->whereNotNull('quotation_id')->get() as $invoice) {
            $quote = DB::table('quotations')->where('id', $invoice->quotation_id)->first();
            if ($quote === null || (int) $quote->company_id !== $companyId || (int) $quote->customer_id !== (int) $invoice->customer_id
                || $quote->status !== 'converted' || (int) $quote->converted_to_invoice_id !== (int) $invoice->id) {
                $errors[] = "Invoice [{$invoice->id}] quotation company/customer/state/reciprocal provenance mismatch.";
            }
        }
        foreach (DB::table('sales_invoice_lines as l')->join('sales_invoices as i', 'i.id', '=', 'l.sales_invoice_id')
            ->where('i.company_id', $companyId)->get(['l.*', 'i.currency_code', 'i.exchange_rate']) as $line) {
            try {
                $result = app(SalesLineCalculator::class)->calculate(new SalesLineCalculationInput(
                    quantity: $line->quantity, unitPrice: $line->unit_price, discountType: $line->discount_type,
                    discountValue: $line->discount_value ?? '0', taxRate: $line->tax_rate_snapshot, taxInclusive: (bool) $line->tax_inclusive,
                    currencyMinorUnits: $line->currency_code === 'JOD' ? 3 : 2, exchangeRate: $line->exchange_rate));
                if (! $result->tax->isEqualTo($line->line_tax) || ! $result->total->isEqualTo($line->line_total)) {
                    $errors[] = "Invoice line [{$line->id}] exact percentage-point tax calculation mismatch.";
                }
            } catch (\Throwable $e) {
                $errors[] = "Invoice line [{$line->id}] invalid tax/calculation snapshot.";
            }
        }
        foreach (DB::table('customer_payment_application_events')->where('company_id', $companyId)->get() as $event) {
            $label = "Credit application [{$event->id}]";
            $payment = DB::table('customer_payments')->where('id', $event->customer_payment_id)->first();
            $rows = DB::table('customer_payment_allocations')->where('application_event_id', $event->id)->orderBy('sales_invoice_id')->get();
            if ($payment === null || (int) $payment->company_id !== $companyId || $event->applied_at === null || $rows->isEmpty()
                || ! DB::table('company_user')->where('company_id', $companyId)->where('user_id', $event->applied_by)->exists()) {
                $errors[] = "$label payment/actor/completion provenance mismatch.";

                continue;
            }
            $intent = [];
            $expected = [];
            foreach ($rows as $row) {
                $intent[] = ['sales_invoice_id' => (int) $row->sales_invoice_id, 'allocated_amount' => $row->allocated_amount];
                $invoice = DB::table('sales_invoices')->where('id', $row->sales_invoice_id)->first();
                if ($invoice === null || $invoice->posting_batch_id === null || (int) $row->company_id !== $companyId || (int) $row->customer_payment_id !== (int) $payment->id
                    || (int) $invoice->company_id !== $companyId || (int) $invoice->customer_id !== (int) $payment->customer_id || $invoice->currency_code !== $payment->currency_code) {
                    $errors[] = "$label allocation tenant/customer/currency mismatch.";

                    continue;
                }
                $prior = app(ReceivableReliefHistory::class)->before($companyId, (int) $invoice->id, (int) $row->prior_posting_batch_id, (int) $row->id);
                $cumulative = $prior['amount']->plus($row->allocated_amount);
                $target = $cumulative->isEqualTo($invoice->grand_total_currency) ? BigDecimal::of($invoice->grand_total_base)
                    : $cumulative->multipliedBy($invoice->exchange_rate)->toScale(6, RoundingMode::HALF_UP);
                $book = $target->minus($prior['base']);
                $fx = BigDecimal::of($row->settlement_base_value)->minus($book);
                if ($cumulative->isGreaterThan($invoice->grand_total_currency) || ! $book->isEqualTo($row->base_amount_applied_to_receivable)
                    || ! $fx->isEqualTo($row->realized_fx_gain_loss_base) || ! BigDecimal::of($row->allocated_amount)->isPositive()
                    || ! BigDecimal::of($row->invoice_exchange_rate)->isEqualTo($invoice->exchange_rate)
                    || ! BigDecimal::of($row->payment_exchange_rate)->isEqualTo($payment->exchange_rate)) {
                    $errors[] = "$label AR relief/FX/cap mismatch.";
                }
                $this->add($expected, (int) ($accounts['accounts_receivable'] ?? 0), $fx);
                $this->add($expected, (int) ($accounts[$fx->isNegative() ? 'fx_loss' : 'fx_gain'] ?? 0), $fx->negated());
            }
            if ($event->request_hash !== ApplyCustomerPaymentCreditAction::requestHash($companyId, (int) $payment->id, (int) $event->applied_by, $event->application_date, $intent)
                || ! preg_match('/^[A-Za-z0-9:_-]{1,128}$/D', $event->idempotency_key)) {
                $errors[] = "$label request fingerprint/idempotency mismatch.";
            }
            $nonzero = array_filter($expected, fn (BigDecimal $n): bool => ! $n->isZero());
            // Offsetting gains/losses still require the corresponding actual FX lines.
            if ($event->posting_batch_id === null && $nonzero !== []) {
                $errors[] = "$label missing FX posting.";
            } elseif ($event->posting_batch_id !== null) {
                $errors = array_merge($errors, app(SalesHistoryAudit::class)->batch($companyId, (int) $event->posting_batch_id, 'customer_payment_application', (int) $event->id, $expected));
            }
            if ($event->reversed_at !== null) {
                if ($event->reversed_by === null || ! $payment->is_reversed) {
                    $errors[] = "$label reversal provenance mismatch.";
                }
                if ($event->posting_batch_id !== null) {
                    $errors = array_merge($errors, app(SalesHistoryAudit::class)->reversal($companyId, (int) $event->posting_batch_id, (int) $event->reversal_posting_batch_id));
                } elseif ($event->reversal_posting_batch_id !== null) {
                    $errors[] = "$label unexpected zero-FX reversal batch.";
                }
            } elseif ($payment->is_reversed) {
                $errors[] = "$label remains active on a reversed payment.";
            }
        }
        foreach (DB::table('customer_payments')->where('company_id', $companyId)->get() as $payment) {
            $amount = BigDecimal::zero();
            $base = BigDecimal::zero();
            foreach (DB::table('customer_payment_allocations')->where('customer_payment_id', $payment->id)->orderBy('id')->get() as $allocation) {
                // Replay all allocations at their original append order, including applications later reversed with the receipt.
                $amount = $amount->plus($allocation->allocated_amount);
                $target = $amount->isEqualTo($payment->amount) ? BigDecimal::of($payment->amount_base)
                    : $amount->multipliedBy($payment->exchange_rate)->toScale(6, RoundingMode::HALF_UP);
                if ($amount->isGreaterThan($payment->amount) || ! $target->minus($base)->isEqualTo($allocation->settlement_base_value)) {
                    $errors[] = "Payment [{$payment->id}] exact cumulative settlement residual mismatch.";
                }
                $base = $base->plus($allocation->settlement_base_value);
            }
        }
        foreach (SalesInvoice::where('company_id', $companyId)->where('status', 'posted')->get() as $invoice) {
            $paid = BigDecimal::zero();
            foreach ($invoice->paymentAllocations()->active()->get() as $allocation) {
                $paid = $paid->plus($allocation->allocated_amount);
            }
            // A later legitimate return may create customer credit; allocation caps use their original financial chronology.
            if ($paid->isGreaterThan($invoice->grand_total_currency)) {
                $errors[] = "Invoice [{$invoice->id}] is over-applied.";
            }
        }

        return $errors;
    }

    /** @param array<int, BigDecimal> $totals */
    private function add(array &$totals, int $account, BigDecimal $amount): void
    {
        $totals[$account] = ($totals[$account] ?? BigDecimal::zero())->plus($amount);
    }
}
