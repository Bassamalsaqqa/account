<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Exceptions\CompanyReassignmentException;
use App\Exceptions\NoActiveCompanyException;
use App\Models\Company;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Vendor;
use App\Models\VendorPayment;
use App\Models\VendorPaymentAllocation;
use App\Models\VendorPaymentApplicationEvent;
use App\Support\Tenancy\CompanyContext;
use App\Support\Tenancy\CompanyScope;
use InvalidArgumentException;

class PayablesReconciliationService
{
    public function __construct(
        protected PayableReliefHistory $reliefHistory,
    ) {}

    /**
     * Audit and reconcile payables records, tenant isolation, invariants and accounting links for a company without mutations.
     */
    public function reconcile(Company $company, bool $isSystem = false): PayablesReconciliationReport
    {
        $context = app(CompanyContext::class);

        if ($isSystem) {
            if ($context->hasCompany()) {
                throw new InvalidArgumentException('System mode payables reconciliation requires no active company context.');
            }
        } else {
            if (! $context->hasCompany()) {
                throw new NoActiveCompanyException('Cannot reconcile payables records without an active company context.');
            }

            if ($context->companyId() !== $company->id) {
                throw new CompanyReassignmentException("Cannot reconcile payables records for company [{$company->id}] when active company is [{$context->companyId()}].");
            }
        }

        $execute = function () use ($company): PayablesReconciliationReport {
            $violations = [];
            $cid = (int) $company->id;
            $stats = [];

            // 1. Tenant Isolation Checks
            $foreignVendorsInPurchases = Purchase::withoutGlobalScopes()
                ->join('vendors', 'purchases.vendor_id', '=', 'vendors.id')
                ->where('purchases.company_id', $cid)
                ->where('vendors.company_id', '!=', $cid)
                ->count();
            if ($foreignVendorsInPurchases > 0) {
                $violations[] = "Detected {$foreignVendorsInPurchases} purchases referencing vendors from another company.";
            }

            $foreignVendorsInPayments = VendorPayment::withoutGlobalScopes()
                ->join('vendors', 'vendor_payments.vendor_id', '=', 'vendors.id')
                ->where('vendor_payments.company_id', $cid)
                ->where('vendors.company_id', '!=', $cid)
                ->count();
            if ($foreignVendorsInPayments > 0) {
                $violations[] = "Detected {$foreignVendorsInPayments} vendor payments referencing vendors from another company.";
            }

            $foreignMoneyAccountsInPayments = VendorPayment::withoutGlobalScopes()
                ->join('money_accounts', 'vendor_payments.money_account_id', '=', 'money_accounts.id')
                ->where('vendor_payments.company_id', $cid)
                ->where('money_accounts.company_id', '!=', $cid)
                ->count();
            if ($foreignMoneyAccountsInPayments > 0) {
                $violations[] = "Detected {$foreignMoneyAccountsInPayments} vendor payments referencing money accounts from another company.";
            }

            $foreignAllocations = VendorPaymentAllocation::withoutGlobalScopes()
                ->join('vendor_payments', 'vendor_payment_allocations.vendor_payment_id', '=', 'vendor_payments.id')
                ->where('vendor_payment_allocations.company_id', $cid)
                ->where('vendor_payments.company_id', '!=', $cid)
                ->count();
            if ($foreignAllocations > 0) {
                $violations[] = "Detected {$foreignAllocations} payment allocations belonging to another company's payment.";
            }

            $foreignEvents = VendorPaymentApplicationEvent::withoutGlobalScopes()
                ->join('vendor_payments', 'vendor_payment_application_events.vendor_payment_id', '=', 'vendor_payments.id')
                ->where('vendor_payment_application_events.company_id', $cid)
                ->where('vendor_payments.company_id', '!=', $cid)
                ->count();
            if ($foreignEvents > 0) {
                $violations[] = "Detected {$foreignEvents} advance application events belonging to another company's payment.";
            }

            // Audit the liability sources even when no payment has referenced them yet.
            foreach (Purchase::where('company_id', $cid)->where('status', Purchase::STATUS_POSTED)->get() as $purchase) {
                try {
                    app(PurchasePostingCommandBuilder::class)->validatePosted($purchase);
                } catch (\Throwable $e) {
                    $violations[] = "Purchase [ID {$purchase->id}] integrity failure: {$e->getMessage()}";
                }
            }
            foreach (PurchaseReturn::where('company_id', $cid)->where('status', Purchase::STATUS_POSTED)->get() as $return) {
                try {
                    app(PurchaseReturnPostingCommandBuilder::class)->validatePosted($return);
                } catch (\Throwable $e) {
                    $violations[] = "Purchase Return [ID {$return->id}] integrity failure: {$e->getMessage()}";
                }
            }

            // 2. Vendor Payment Integrity
            $payments = VendorPayment::where('company_id', $cid)->get();
            $stats['total_vendor_payments'] = $payments->count();
            $stats['posted_vendor_payments'] = $payments->whereNotNull('posting_batch_id')->count();
            $stats['reversed_vendor_payments'] = $payments->where('is_reversed', true)->count();

            foreach ($payments as $payment) {
                $label = "Payment [ID {$payment->id}, {$payment->payment_number}]";
                if ($payment->posting_batch_id === null) {
                    $violations[] = "{$label} is missing a canonical posting batch.";

                    continue;
                }

                try {
                    app(VendorPaymentPostedIntegrityValidator::class)->validate($payment);
                } catch (\Throwable $e) {
                    $violations[] = "{$label} integrity failure: {$e->getMessage()}";
                }
            }

            // 3. Application Events Integrity
            $events = VendorPaymentApplicationEvent::where('company_id', $cid)->get();
            $stats['total_application_events'] = $events->count();

            foreach ($events as $event) {
                $label = "Application Event [ID {$event->id}]";
                try {
                    app(VendorPaymentApplicationIntegrityValidator::class)->validate($event);
                } catch (\Throwable $e) {
                    $violations[] = "{$label} integrity failure: {$e->getMessage()}";
                }
            }

            $stats['violations_count'] = count($violations);

            return new PayablesReconciliationReport(
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
