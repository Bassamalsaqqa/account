<?php

declare(strict_types=1);

namespace App\Actions\Purchasing;

use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Exceptions\NoActiveCompanyException;
use App\Models\Company;
use App\Models\PostingBatch;
use App\Models\User;
use App\Models\VendorPayment;
use App\Models\VendorPaymentApplicationEvent;
use App\Services\Audit\AuditService;
use App\Services\Money\MoneyEventScope;
use App\Services\Posting\AccountingReversalService;
use App\Services\Purchasing\VendorPaymentPostedIntegrityValidator;
use App\Services\Purchasing\VendorPaymentReversalScope;
use App\Services\Purchasing\VendorPaymentValidationException;
use App\Services\Sales\SalesActorGuard;
use App\Services\Sales\SalesDocumentRules;
use App\Support\Tenancy\CompanyContext;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ReverseVendorPaymentAction
{
    private ?VendorPaymentReversalScope $activeReversalScope = null;

    public function ownsReversalScope(VendorPaymentReversalScope $scope): bool
    {
        return $this->activeReversalScope === $scope;
    }

    public function __construct(
        protected AccountingReversalService $accountingReversalService,
        protected VendorPaymentPostedIntegrityValidator $integrityValidator,
    ) {}

    public function execute(VendorPayment $payment, User $user, ?string $reason = null, ?string $businessDate = null): VendorPayment
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

        if (! $user->hasPermissionTo('money.vendor_payment.reverse') || ! $user->hasPermissionTo('purchasing.cost.view')) {
            throw new AuthorizationException('User does not have permission to reverse vendor payments.');
        }

        return DB::transaction(function () use ($payment, $user, $reason, $businessDate): VendorPayment {
            $lockedCompany = Company::where('id', $payment->company_id)->lockForUpdate()->firstOrFail();
            app(SalesActorGuard::class)->lockAndAuthorize((int) $payment->company_id, $user, 'money.vendor_payment.reverse');
            app(SalesActorGuard::class)->lockAndAuthorize((int) $payment->company_id, $user, 'purchasing.cost.view');

            /** @var VendorPayment $lockedPayment */
            $lockedPayment = VendorPayment::where('id', $payment->id)->lockForUpdate()->firstOrFail();

            if ($lockedPayment->check_id !== null) {
                app(MoneyEventScope::class)->assertCheckTransition((int) $lockedPayment->check_id, (int) $lockedCompany->id, $user);
            }

            if ($lockedPayment->is_reversed) {
                // Verify coherent reversal state on idempotent retry
                $this->integrityValidator->validate($lockedPayment);

                return $lockedPayment->fresh(['allocations', 'vendor', 'moneyAccount', 'postingBatch', 'reversalPostingBatch']);
            }

            if ($lockedPayment->posting_batch_id === null) {
                throw new ImmutableRecordException('Cannot reverse unposted vendor payment.');
            }

            $this->integrityValidator->validate($lockedPayment);

            // One Company-local business date for the complete reversal.
            $reversalDate = $businessDate ?? Carbon::now($lockedCompany->timezone)->toDateString();
            app(SalesDocumentRules::class)->date($reversalDate);

            // 1. Reverse dependent application events newest to oldest
            $events = VendorPaymentApplicationEvent::where('company_id', $lockedPayment->company_id)
                ->where('vendor_payment_id', $lockedPayment->id)
                ->whereNull('reversed_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->get();

            // An inverse cannot economically precede any activity it undoes.
            if ($reversalDate < Carbon::parse($lockedPayment->payment_date)->toDateString()
                || $events->contains(fn (VendorPaymentApplicationEvent $event): bool => $event->application_date->toDateString() > $reversalDate)) {
                throw new VendorPaymentValidationException('purchasing.reversal_before_activity_date');
            }

            $scope = app(VendorPaymentReversalScope::class);
            $this->activeReversalScope = $scope;
            try {
                $scope->withinCanonicalReversal($this, $lockedPayment, $user, $events->pluck('id')->map(fn ($id): int => (int) $id)->all(), function ($capability) use ($events, $lockedPayment, $user, $reason, $reversalDate): void {
                    foreach ($events as $event) {
                        if ($event->applied_at === null) {
                            throw new InvalidArgumentException('Incomplete credit application prevents vendor payment reversal.');
                        }

                        $eventReversal = null;
                        if ($event->posting_batch_id !== null) {
                            $eventBatch = PostingBatch::where('company_id', $lockedPayment->company_id)->findOrFail($event->posting_batch_id);
                            if ($eventBatch->isReversed()) {
                                throw new InvalidArgumentException('Credit application reversal provenance is inconsistent.');
                            }
                            $eventReversal = $this->accountingReversalService->reverse(
                                $eventBatch,
                                $user,
                                $reason ?? 'Vendor payment advance application reversal',
                                $reversalDate
                            );
                        }

                        $event->completeCanonicalReversal($eventReversal, $user, $reason, $capability);
                    }

                    // 2. Reverse original payment batch
                    $originalBatch = PostingBatch::where('company_id', $lockedPayment->company_id)->findOrFail($lockedPayment->posting_batch_id);
                    if ($originalBatch->isReversed()) {
                        throw new InvalidArgumentException('Original vendor payment batch is already reversed.');
                    }

                    $reversalBatch = $this->accountingReversalService->reverse(
                        $originalBatch,
                        $user,
                        $reason ?? "Reversal of Vendor Payment {$lockedPayment->payment_number}",
                        $reversalDate
                    );

                    $lockedPayment->completeCanonicalReversal($reversalBatch, $user, $reason, $capability);

                });
            } finally {
                $this->activeReversalScope = null;
            }

            // Verify the persisted reversal date against its Company-local lifecycle
            // timestamp before commit; a clock crossing rolls back the entire event.
            $this->integrityValidator->validate($lockedPayment->fresh(), expectedReversalDate: $businessDate === null ? $reversalDate : null);

            // Safe nonmonetary audit event
            app(AuditService::class)->log(
                (int) $lockedPayment->company_id,
                'vendor_payment.reversed',
                'Vendor payment reversed',
                (int) $user->id,
                $lockedPayment,
                null,
                null,
                [
                    'payment_id' => (int) $lockedPayment->id,
                    'payment_number' => $lockedPayment->payment_number,
                    'vendor_id' => (int) $lockedPayment->vendor_id,
                    'lifecycle' => 'reversed',
                ],
            );

            return $lockedPayment->fresh(['allocations', 'vendor', 'moneyAccount', 'postingBatch', 'reversalPostingBatch']);
        });
    }
}
