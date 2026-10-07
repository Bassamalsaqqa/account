<?php

declare(strict_types=1);

namespace App\Actions\Sales;

use App\Exceptions\NoActiveCompanyException;
use App\Models\Company;
use App\Models\CustomerPayment;
use App\Models\CustomerPaymentApplicationEvent;
use App\Models\PostingBatch;
use App\Models\User;
use App\Services\Money\CustomerApplicationHistory;
use App\Services\Money\CustomerPaymentHistory;
use App\Services\Money\MoneyEventScope;
use App\Services\Posting\AccountingReversalService;
use App\Services\Sales\SalesActorGuard;
use App\Services\Sales\SalesDocumentRules;
use App\Support\Tenancy\CompanyContext;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class ReverseCustomerPaymentAction
{
    public function __construct(
        protected AccountingReversalService $accountingReversalService,
    ) {}

    public function execute(CustomerPayment $payment, User $user, ?string $reason = null, ?string $businessDate = null): CustomerPayment
    {
        $context = app(CompanyContext::class);
        if (! $context->hasCompany() || (int) $context->companyId() !== (int) $payment->company_id) {
            throw new NoActiveCompanyException("Active company context does not match payment company [{$payment->company_id}].");
        }

        if (! auth()->check() || (int) auth()->id() !== (int) $user->id) {
            throw new AuthorizationException('Actor must be authenticated and match user.');
        }

        if (! $user->belongsToCompany($payment->company_id)) {
            throw new AuthorizationException("User does not belong to company [{$payment->company_id}].");
        }

        setPermissionsTeamId($payment->company_id);

        if (! $user->hasPermissionTo('money.receipt.reverse')) {
            throw new AuthorizationException('User does not have permission to reverse customer receipts.');
        }

        return DB::transaction(function () use ($payment, $user, $reason, $businessDate): CustomerPayment {
            $company = Company::where('id', $payment->company_id)->lockForUpdate()->firstOrFail();
            app(SalesActorGuard::class)->lockAndAuthorize((int) $payment->company_id, $user, 'money.receipt.reverse');

            /** @var CustomerPayment $lockedPayment */
            $lockedPayment = CustomerPayment::where('id', $payment->id)->lockForUpdate()->firstOrFail();

            if ($lockedPayment->check_id !== null) {
                app(MoneyEventScope::class)->assertCheckTransition((int) $lockedPayment->check_id, (int) $company->id, $user);
            }
            app(CustomerPaymentHistory::class)->validate($lockedPayment);
            $reversalDate = $businessDate ?? Carbon::now($company->timezone)->toDateString();
            app(SalesDocumentRules::class)->date($reversalDate);
            if ($reversalDate < Carbon::parse($lockedPayment->payment_date)->toDateString()) {
                throw new \InvalidArgumentException('Receipt reversal cannot precede receipt date.');
            }

            if ($lockedPayment->is_reversed) {
                return $lockedPayment;
            }

            $events = CustomerPaymentApplicationEvent::where('company_id', $lockedPayment->company_id)
                ->where('customer_payment_id', $lockedPayment->id)->whereNull('reversed_at')->orderByDesc('id')->lockForUpdate()->get();
            foreach ($events as $event) {
                app(CustomerApplicationHistory::class)->validate($event);
                if ($reversalDate < Carbon::parse($event->application_date)->toDateString()) {
                    throw new \InvalidArgumentException('Reversal cannot precede credit application.');
                }
                if ($event->applied_at === null) {
                    throw new \InvalidArgumentException('Incomplete credit application prevents receipt reversal.');
                }
                $eventReversal = null;
                if ($event->posting_batch_id !== null) {
                    $eventBatch = PostingBatch::where('company_id', $lockedPayment->company_id)->findOrFail($event->posting_batch_id);
                    if ($eventBatch->isReversed()) {
                        throw new \InvalidArgumentException('Credit application reversal provenance is inconsistent.');
                    }
                    $eventReversal = $this->accountingReversalService->reverse($eventBatch, $user, $reason ?? 'Receipt credit application reversal', $reversalDate);
                }
                $event->completeCanonicalReversal($eventReversal, $user, $reason);
            }

            $reversalBatch = null;
            if ($lockedPayment->posting_batch_id !== null) {
                $batch = $lockedPayment->postingBatch;
                if ($batch !== null && ! $batch->isReversed()) {
                    $reversalBatch = $this->accountingReversalService->reverse(
                        $batch,
                        $user,
                        $reason ?? "Reversal of Customer Receipt {$lockedPayment->payment_number}",
                        $reversalDate
                    );
                }
            }

            if ($reversalBatch === null) {
                throw new \InvalidArgumentException('Canonical accounting reversal is required before reversing a receipt.');
            }
            $lockedPayment->completeCanonicalReversal($reversalBatch, $user, $reason);

            app(CustomerPaymentHistory::class)->validate($lockedPayment);
            foreach ($events as $event) {
                app(CustomerApplicationHistory::class)->validate($event->fresh());
            }

            return $lockedPayment->fresh(['allocations', 'customer', 'moneyAccount', 'postingBatch', 'reversalPostingBatch']);
        });
    }
}
