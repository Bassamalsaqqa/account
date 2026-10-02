<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Exceptions\CompanyReassignmentException;
use App\Exceptions\NoActiveCompanyException;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\MoneyAccount;
use App\Models\Quotation;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\SalesInvoiceLotAllocation;
use App\Models\SalesReturn;
use App\Models\StockMovement;
use App\Support\Tenancy\CompanyContext;
use App\Support\Tenancy\CompanyScope;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SalesReconciliationService
{
    /**
     * Audit and reconcile sales records, tenant isolation, invariants and accounting links for a company without mutations.
     */
    public function reconcile(Company $company, bool $isSystem = false): SalesReconciliationReport
    {
        $context = app(CompanyContext::class);

        if ($isSystem) {
            if ($context->hasCompany()) {
                throw new InvalidArgumentException('System mode sales reconciliation requires no active company context.');
            }
        } else {
            if (! $context->hasCompany()) {
                throw new NoActiveCompanyException('Cannot reconcile sales records without an active company context.');
            }

            if ($context->companyId() !== $company->id) {
                throw new CompanyReassignmentException("Cannot reconcile sales records for company [{$company->id}] when active company is [{$context->companyId()}].");
            }
        }

        $execute = function () use ($company): SalesReconciliationReport {
            $violations = [];
            $cid = $company->id;

            // 1. Tenant Isolation Checks
            // Foreign customers referenced in documents
            $foreignCustomersInInvoices = DB::table('sales_invoices')
                ->join('customers', 'sales_invoices.customer_id', '=', 'customers.id')
                ->where('sales_invoices.company_id', $cid)
                ->where('customers.company_id', '!=', $cid)
                ->count();
            if ($foreignCustomersInInvoices > 0) {
                $violations[] = "Detected {$foreignCustomersInInvoices} sales invoices referencing customers from another company.";
            }

            $foreignCustomersInQuotes = DB::table('quotations')
                ->join('customers', 'quotations.customer_id', '=', 'customers.id')
                ->where('quotations.company_id', $cid)
                ->where('customers.company_id', '!=', $cid)
                ->count();
            if ($foreignCustomersInQuotes > 0) {
                $violations[] = "Detected {$foreignCustomersInQuotes} quotations referencing customers from another company.";
            }

            $foreignCustomersInPayments = DB::table('customer_payments')
                ->join('customers', 'customer_payments.customer_id', '=', 'customers.id')
                ->where('customer_payments.company_id', $cid)
                ->where('customers.company_id', '!=', $cid)
                ->count();
            if ($foreignCustomersInPayments > 0) {
                $violations[] = "Detected {$foreignCustomersInPayments} customer payments referencing customers from another company.";
            }

            // Foreign money accounts referenced in payments
            $foreignMoneyAccountsInPayments = DB::table('customer_payments')
                ->join('money_accounts', 'customer_payments.money_account_id', '=', 'money_accounts.id')
                ->where('customer_payments.company_id', $cid)
                ->where('money_accounts.company_id', '!=', $cid)
                ->count();
            if ($foreignMoneyAccountsInPayments > 0) {
                $violations[] = "Detected {$foreignMoneyAccountsInPayments} customer payments referencing money accounts from another company.";
            }

            // 2. Drafts Side-Effect Freedom
            // Draft sales invoices must not have invoice_number, posted_at, lot allocations, movements or GL batches
            $draftInvoices = SalesInvoice::where('company_id', $cid)
                ->where('status', SalesInvoice::STATUS_DRAFT)
                ->get();

            foreach ($draftInvoices as $draftInv) {
                if ($draftInv->invoice_number !== null) {
                    $violations[] = "Draft invoice [ID {$draftInv->id}] has an assigned invoice_number [{$draftInv->invoice_number}].";
                }
                if ($draftInv->posted_at !== null) {
                    $violations[] = "Draft invoice [ID {$draftInv->id}] has a non-null posted_at timestamp.";
                }
                $allocationsCount = SalesInvoiceLotAllocation::where('sales_invoice_id', $draftInv->id)->count();
                if ($allocationsCount > 0) {
                    $violations[] = "Draft invoice [ID {$draftInv->id}] has {$allocationsCount} lot allocations.";
                }
                $movementsCount = StockMovement::where('company_id', $cid)
                    ->where('source_type', 'sales_invoice')
                    ->where('source_id', $draftInv->id)
                    ->count();
                if ($movementsCount > 0) {
                    $violations[] = "Draft invoice [ID {$draftInv->id}] has {$movementsCount} linked stock movements.";
                }
                if ($draftInv->posting_batch_id !== null) {
                    $violations[] = "Draft invoice [ID {$draftInv->id}] has an assigned posting_batch_id [{$draftInv->posting_batch_id}].";
                }
            }

            // Draft returns must not have return_number, posted_at, movements, or GL
            $draftReturns = SalesReturn::where('company_id', $cid)
                ->where('status', SalesReturn::STATUS_DRAFT)
                ->get();

            foreach ($draftReturns as $draftRet) {
                if (! str_starts_with($draftRet->return_number, 'DRAFT-')) {
                    $violations[] = "Draft sales return [ID {$draftRet->id}] has an assigned permanent return_number [{$draftRet->return_number}].";
                }
                if ($draftRet->posted_at !== null) {
                    $violations[] = "Draft sales return [ID {$draftRet->id}] has a non-null posted_at timestamp.";
                }
                $retMovementsCount = StockMovement::where('company_id', $cid)
                    ->where('source_type', 'sales_return')
                    ->where('source_id', $draftRet->id)
                    ->count();
                if ($retMovementsCount > 0) {
                    $violations[] = "Draft sales return [ID {$draftRet->id}] has {$retMovementsCount} linked stock movements.";
                }
                if ($draftRet->posting_batch_id !== null) {
                    $violations[] = "Draft sales return [ID {$draftRet->id}] has an assigned posting_batch_id [{$draftRet->posting_batch_id}].";
                }
            }

            // 3. Document Sequencing & Duplicate Numbers
            // Posted invoices must have numbers, no duplicates
            $duplicateInvoiceNumbers = DB::table('sales_invoices')
                ->where('company_id', $cid)
                ->whereNotNull('invoice_number')
                ->select('invoice_number', DB::raw('COUNT(*) as cnt'))
                ->groupBy('invoice_number')
                ->having('cnt', '>', 1)
                ->get();
            foreach ($duplicateInvoiceNumbers as $dup) {
                $violations[] = "Duplicate invoice_number [{$dup->invoice_number}] found ({$dup->cnt} times).";
            }

            $missingInvoiceNumbers = SalesInvoice::where('company_id', $cid)
                ->where('status', '!=', SalesInvoice::STATUS_DRAFT)
                ->whereNull('invoice_number')
                ->count();
            if ($missingInvoiceNumbers > 0) {
                $violations[] = "Found {$missingInvoiceNumbers} non-draft invoices without an invoice_number.";
            }

            // Duplicate return numbers (posted only)
            $duplicateReturnNumbers = DB::table('sales_returns')
                ->where('company_id', $cid)
                ->whereNotNull('return_number')
                ->where('status', '!=', SalesReturn::STATUS_DRAFT)
                ->select('return_number', DB::raw('COUNT(*) as cnt'))
                ->groupBy('return_number')
                ->having('cnt', '>', 1)
                ->get();
            foreach ($duplicateReturnNumbers as $dup) {
                $violations[] = "Duplicate return_number [{$dup->return_number}] found ({$dup->cnt} times).";
            }

            // Duplicate payment numbers
            $duplicatePaymentNumbers = DB::table('customer_payments')
                ->where('company_id', $cid)
                ->select('payment_number', DB::raw('COUNT(*) as cnt'))
                ->groupBy('payment_number')
                ->having('cnt', '>', 1)
                ->get();
            foreach ($duplicatePaymentNumbers as $dup) {
                $violations[] = "Duplicate payment_number [{$dup->payment_number}] found ({$dup->cnt} times).";
            }

            // 4. Line-to-Header Calculations & FX Rounding
            $allInvoices = SalesInvoice::with(['lines.product'])->where('company_id', $cid)->get();
            foreach ($allInvoices as $inv) {
                $lineSubtotalSum = BigDecimal::zero();
                $lineDiscountSum = BigDecimal::zero();
                $lineTaxSum = BigDecimal::zero();
                $lineTotalSum = BigDecimal::zero();

                foreach ($inv->lines as $line) {
                    $lineSubtotalSum = $lineSubtotalSum->plus(BigDecimal::of((string) $line->line_subtotal));
                    $lineDiscountSum = $lineDiscountSum->plus(BigDecimal::of((string) $line->line_discount));
                    $lineTaxSum = $lineTaxSum->plus(BigDecimal::of((string) $line->line_tax));
                    $lineTotalSum = $lineTotalSum->plus(BigDecimal::of((string) $line->line_total));
                }

                $headerSubtotal = BigDecimal::of((string) $inv->subtotal_currency);
                $headerDiscount = BigDecimal::of((string) $inv->discount_total_currency);
                $headerTax = BigDecimal::of((string) $inv->tax_total_currency);
                $headerGrand = BigDecimal::of((string) $inv->grand_total_currency);

                if (! $lineSubtotalSum->isEqualTo($headerSubtotal)) {
                    $violations[] = "Invoice [ID {$inv->id}] line subtotals sum [{$lineSubtotalSum}] does not match header subtotal [{$headerSubtotal}].";
                }
                if (! $lineDiscountSum->isEqualTo($headerDiscount)) {
                    $violations[] = "Invoice [ID {$inv->id}] line discounts sum [{$lineDiscountSum}] does not match header discount_total [{$headerDiscount}].";
                }
                if (! $lineTaxSum->isEqualTo($headerTax)) {
                    $violations[] = "Invoice [ID {$inv->id}] line taxes sum [{$lineTaxSum}] does not match header tax_total [{$headerTax}].";
                }
                if (! $lineTotalSum->isEqualTo($headerGrand)) {
                    $violations[] = "Invoice [ID {$inv->id}] line totals sum [{$lineTotalSum}] does not match header grand_total [{$headerGrand}].";
                }

                // Base conversion check
                $expectedBase = $headerGrand->multipliedBy(BigDecimal::of((string) $inv->exchange_rate))
                    ->toScale(6, RoundingMode::HALF_UP);
                $headerBase = BigDecimal::of((string) $inv->grand_total_base)->toScale(6, RoundingMode::HALF_UP);
                if (! $expectedBase->isEqualTo($headerBase)) {
                    $violations[] = "Invoice [ID {$inv->id}] base_grand_total [{$headerBase}] does not match grand_total * exchange_rate [{$expectedBase}].";
                }
            }

            // 5. Stock Movement & COGS Consistency for Posted Invoices
            $postedInvoices = $allInvoices->where('status', SalesInvoice::STATUS_POSTED);
            foreach ($postedInvoices as $pInv) {
                if ($pInv->posting_batch_id === null) {
                    $violations[] = "Posted invoice [ID {$pInv->id}] is missing posting_batch_id.";
                } else {
                    $batch = DB::table('posting_batches')->where('id', $pInv->posting_batch_id)->where('company_id', $cid)->first();
                    if ($batch === null) {
                        $violations[] = "Posted invoice [ID {$pInv->id}] references non-existent or foreign posting batch [{$pInv->posting_batch_id}].";
                    } elseif ($batch->source_type !== 'sales_invoice' || (int) $batch->source_id !== (int) $pInv->id) {
                        $violations[] = "Posted invoice [ID {$pInv->id}] posting batch source mismatch (batch source_type: {$batch->source_type}, source_id: {$batch->source_id}).";
                    }
                }

                $totalLineCogs = BigDecimal::zero();
                foreach ($pInv->lines as $line) {
                    if ($line->product?->track_stock) {
                        $allocations = SalesInvoiceLotAllocation::where('sales_invoice_line_id', $line->id)->get();
                        $allocQty = BigDecimal::zero();
                        $allocCogs = BigDecimal::zero();
                        foreach ($allocations as $alloc) {
                            $allocQty = $allocQty->plus(BigDecimal::of((string) $alloc->quantity_allocated_base));
                            $allocCogs = $allocCogs->plus(BigDecimal::of((string) $alloc->total_cost_base));
                        }

                        $lineQty = BigDecimal::of((string) $line->quantity_base);
                        if (! $allocQty->isEqualTo($lineQty)) {
                            $violations[] = "Invoice [ID {$pInv->id}] line [ID {$line->id}] quantity [{$lineQty}] does not match lot allocations total [{$allocQty}].";
                        }

                        $lineCogs = BigDecimal::of((string) ($line->cogs_total_base ?? '0'))->toScale(6, RoundingMode::HALF_UP);
                        $allocCogsScaled = $allocCogs->toScale(6, RoundingMode::HALF_UP);
                        if (! $allocCogsScaled->isEqualTo($lineCogs)) {
                            $violations[] = "Invoice [ID {$pInv->id}] line [ID {$line->id}] cogs_total_base [{$lineCogs}] does not match lot allocations COGS [{$allocCogsScaled}].";
                        }

                        $totalLineCogs = $totalLineCogs->plus($lineCogs);
                    }
                }
            }

            // 6. Sales Returns Over-Quantity and Cost Checks
            $postedReturns = SalesReturn::with('lines')->where('company_id', $cid)->where('status', SalesReturn::STATUS_POSTED)->get();
            foreach ($postedReturns as $pRet) {
                if ($pRet->posting_batch_id === null) {
                    $violations[] = "Posted sales return [ID {$pRet->id}] is missing posting_batch_id.";
                } else {
                    $batch = DB::table('posting_batches')->where('id', $pRet->posting_batch_id)->where('company_id', $cid)->first();
                    if ($batch === null) {
                        $violations[] = "Posted sales return [ID {$pRet->id}] references non-existent or foreign posting batch [{$pRet->posting_batch_id}].";
                    } elseif ($batch->source_type !== 'sales_return' || (int) $batch->source_id !== (int) $pRet->id) {
                        $violations[] = "Posted sales return [ID {$pRet->id}] posting batch source mismatch (batch source_type: {$batch->source_type}, source_id: {$batch->source_id}).";
                    }
                }

                foreach ($pRet->lines as $rLine) {
                    if ($rLine->sales_invoice_line_id !== null) {
                        $origLine = SalesInvoiceLine::find($rLine->sales_invoice_line_id);
                        if ($origLine === null) {
                            $violations[] = "Sales return [ID {$pRet->id}] line [ID {$rLine->id}] references non-existent original invoice line [{$rLine->sales_invoice_line_id}].";
                        } else {
                            // Sum of all posted returns for this invoice line
                            $totalReturned = DB::table('sales_return_lines')
                                ->join('sales_returns', 'sales_return_lines.sales_return_id', '=', 'sales_returns.id')
                                ->where('sales_returns.status', SalesReturn::STATUS_POSTED)
                                ->where('sales_return_lines.sales_invoice_line_id', $origLine->id)
                                ->sum('sales_return_lines.quantity');

                            if (BigDecimal::of((string) $totalReturned)->isGreaterThan(BigDecimal::of((string) $origLine->quantity))) {
                                $violations[] = "Invoice line [ID {$origLine->id}] quantity [{$origLine->quantity}] was over-returned (total returned: {$totalReturned}).";
                            }
                        }
                    }
                }
            }

            // 7. Customer Payments & Allocations
            $payments = CustomerPayment::with(['allocations', 'moneyAccount'])->where('company_id', $cid)->get();
            foreach ($payments as $payment) {
                // Payment currency must match money account currency
                if ($payment->moneyAccount !== null && $payment->currency_code !== $payment->moneyAccount->currency_code) {
                    $violations[] = "Payment [ID {$payment->id}] currency [{$payment->currency_code}] does not match money account currency [{$payment->moneyAccount->currency_code}].";
                }

                $totalAllocated = BigDecimal::zero();
                foreach ($payment->allocations as $allocation) {
                    $totalAllocated = $totalAllocated->plus(BigDecimal::of((string) $allocation->allocated_amount));

                    $inv = SalesInvoice::find($allocation->sales_invoice_id);
                    if ($inv === null) {
                        $violations[] = "Payment [ID {$payment->id}] allocation [ID {$allocation->id}] references non-existent invoice [{$allocation->sales_invoice_id}].";
                    } else {
                        if ($inv->customer_id !== $payment->customer_id) {
                            $violations[] = "Payment [ID {$payment->id}] allocation [ID {$allocation->id}] customer mismatch (payment customer: {$payment->customer_id}, invoice customer: {$inv->customer_id}).";
                        }
                        if ($inv->currency_code !== $payment->currency_code) {
                            $violations[] = "Payment [ID {$payment->id}] allocation [ID {$allocation->id}] currency mismatch (payment: {$payment->currency_code}, invoice: {$inv->currency_code}).";
                        }

                        // Realized FX check: settlement_base - base_applied
                        $expectedFx = BigDecimal::of((string) $allocation->settlement_base_value)
                            ->minus(BigDecimal::of((string) $allocation->base_amount_applied_to_receivable))
                            ->toScale(6, RoundingMode::HALF_UP);
                        $recordedFx = BigDecimal::of((string) $allocation->realized_fx_gain_loss_base)
                            ->toScale(6, RoundingMode::HALF_UP);

                        if (! $expectedFx->isEqualTo($recordedFx)) {
                            $violations[] = "Payment [ID {$payment->id}] allocation [ID {$allocation->id}] realized FX gain/loss [{$recordedFx}] does not match calculated [{$expectedFx}].";
                        }
                    }
                }

                $paymentAmount = BigDecimal::of((string) $payment->amount);
                if ($totalAllocated->isGreaterThan($paymentAmount)) {
                    $violations[] = "Payment [ID {$payment->id}] total allocated [{$totalAllocated}] exceeds payment amount [{$paymentAmount}].";
                }

                if ($payment->posting_batch_id === null) {
                    $violations[] = "Payment [ID {$payment->id}] is missing posting_batch_id.";
                } else {
                    $batch = DB::table('posting_batches')->where('id', $payment->posting_batch_id)->where('company_id', $cid)->first();
                    if ($batch === null) {
                        $violations[] = "Payment [ID {$payment->id}] references non-existent or foreign posting batch [{$payment->posting_batch_id}].";
                    } elseif ($batch->source_type !== 'customer_payment' || (int) $batch->source_id !== (int) $payment->id) {
                        $violations[] = "Payment [ID {$payment->id}] posting batch source mismatch (batch source_type: {$batch->source_type}, source_id: {$batch->source_id}).";
                    }
                }

                if ($payment->is_reversed) {
                    if ($payment->reversal_posting_batch_id === null) {
                        $violations[] = "Reversed payment [ID {$payment->id}] is missing reversal_posting_batch_id.";
                    } else {
                        $revBatch = DB::table('posting_batches')->where('id', $payment->reversal_posting_batch_id)->where('company_id', $cid)->first();
                        if ($revBatch === null) {
                            $violations[] = "Reversed payment [ID {$payment->id}] references non-existent reversal posting batch [{$payment->reversal_posting_batch_id}].";
                        } elseif ((int) $revBatch->reversal_of_id !== (int) $payment->posting_batch_id) {
                            $violations[] = "Reversed payment [ID {$payment->id}] reversal posting batch source mismatch (batch source_type: {$revBatch->source_type}, source_id: {$revBatch->source_id}).";
                        }
                    }
                }
            }

            $violations = array_merge($violations, app(SalesHistoryAudit::class)->audit((int) $cid));

            $stats = [
                'customers_count' => Customer::where('company_id', $cid)->count(),
                'quotations_count' => Quotation::where('company_id', $cid)->count(),
                'invoices_count' => SalesInvoice::where('company_id', $cid)->count(),
                'returns_count' => SalesReturn::where('company_id', $cid)->count(),
                'payments_count' => CustomerPayment::where('company_id', $cid)->count(),
                'money_accounts_count' => MoneyAccount::where('company_id', $cid)->count(),
            ];

            return new SalesReconciliationReport(
                company: $company,
                isHealthy: empty($violations),
                violations: $violations,
                stats: $stats,
            );
        };

        if ($isSystem) {
            return CompanyScope::executeWithoutScope($execute);
        }

        return $execute();
    }
}
