<?php

declare(strict_types=1);

namespace App\Actions\Sales;

use App\Exceptions\NoActiveCompanyException;
use App\Models\Company;
use App\Models\CustomerPayment;
use App\Models\User;
use App\Services\Posting\AccountingReversalService;
use App\Services\Sales\SalesActorGuard;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class ReverseCustomerPaymentAction
{
    public function __construct(
        protected AccountingReversalService $accountingReversalService,
    ) {}

    public function execute(CustomerPayment $payment, User $user, ?string $reason = null): CustomerPayment
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

        return DB::transaction(function () use ($payment, $user, $reason): CustomerPayment {
            Company::where('id', $payment->company_id)->lockForUpdate()->firstOrFail();
            app(SalesActorGuard::class)->lockAndAuthorize((int) $payment->company_id, $user, 'money.receipt.reverse');

            /** @var CustomerPayment $lockedPayment */
            $lockedPayment = CustomerPayment::where('id', $payment->id)->lockForUpdate()->firstOrFail();

            if ($lockedPayment->is_reversed) {
                return $lockedPayment;
            }

            $reversalBatch = null;
            if ($lockedPayment->posting_batch_id !== null) {
                $batch = $lockedPayment->postingBatch;
                if ($batch !== null && ! $batch->isReversed()) {
                    $reversalBatch = $this->accountingReversalService->reverse(
                        $batch,
                        $user,
                        $reason ?? "Reversal of Customer Receipt {$lockedPayment->payment_number}"
                    );
                }
            }

            if ($reversalBatch === null) {
                throw new \InvalidArgumentException('Canonical accounting reversal is required before reversing a receipt.');
            }
            $lockedPayment->completeCanonicalReversal($reversalBatch, $user, $reason);

            return $lockedPayment->fresh(['allocations', 'customer', 'moneyAccount', 'postingBatch', 'reversalPostingBatch']);
        });
    }
}
