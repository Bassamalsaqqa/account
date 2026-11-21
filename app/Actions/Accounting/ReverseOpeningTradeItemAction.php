<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Application\Platform\TenantWriteAccess;
use App\Domain\Accounting\Exceptions\ActiveSettlementBlockedException;
use App\Exceptions\CompanyReassignmentException;
use App\Exceptions\NoActiveCompanyException;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\OpeningTradeItem;
use App\Models\PostingBatch;
use App\Models\User;
use App\Services\Accounting\OpeningTradeEventScope;
use App\Services\Accounting\OpeningTradeReversalCapability;
use App\Services\Audit\AuditService;
use App\Services\Posting\AccountingPostingService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

final class ReverseOpeningTradeItemAction
{
    private ?OpeningTradeEventScope $activeEventScope = null;

    public function ownsEventScope(OpeningTradeEventScope $scope): bool
    {
        return $this->activeEventScope === $scope;
    }

    public function __construct(
        protected AccountingPostingService $postingService,
        protected OpeningTradeEventScope $eventScope,
        protected AuditService $auditService,
    ) {}

    public function execute(
        OpeningTradeItem $item,
        User $actor,
        string $reason,
        ?string $reversalDate = null,
    ): OpeningTradeItem {
        $context = app(CompanyContext::class);

        if (! $context->hasCompany()) {
            throw new NoActiveCompanyException('Cannot reverse opening trade item without an active company context.');
        }

        if ((int) $context->companyId() !== (int) $item->company_id) {
            throw new CompanyReassignmentException("Cannot reverse opening trade item for company [{$item->company_id}] when active company is [{$context->companyId()}].");
        }

        $authUser = auth()->user();
        if ($authUser !== null && (int) $authUser->id !== (int) $actor->id) {
            throw new AuthorizationException("Authenticated user does not match action actor [{$actor->id}].");
        }

        if (! $actor->hasPermissionTo('settings.accounting.manage')) {
            throw new AuthorizationException('Reversing opening trade items requires settings.accounting.manage permission.');
        }

        if ($item->isVendorSide() && ! $actor->hasPermissionTo('purchasing.cost.view')) {
            throw new AuthorizationException('Reversing opening supplier payables requires purchasing.cost.view permission.');
        }

        $cleanReason = trim($reason);
        if ($cleanReason === '') {
            throw new InvalidArgumentException('A specific correction reason is required to reverse an opening trade balance.');
        }
        if (mb_strlen($cleanReason) > 512) {
            throw new InvalidArgumentException('Correction reason cannot exceed 512 characters.');
        }

        return app(TenantWriteAccess::class)->transaction((int) $item->company_id, function () use ($item, $actor, $cleanReason, $reversalDate): OpeningTradeItem {
            /** @var Company $lockedCompany */
            $lockedCompany = Company::where('id', $item->company_id)->lockForUpdate()->firstOrFail();
            if ($lockedCompany->status !== 'active') {
                throw new InvalidArgumentException("Cannot reverse opening trade items on inactive company [{$lockedCompany->id}].");
            }

            $member = CompanyUser::where('company_id', $lockedCompany->id)
                ->where('user_id', $actor->id)
                ->lockForUpdate()
                ->first();
            if ($member === null || $member->status !== 'active') {
                throw new AuthorizationException("User [{$actor->id}] is not an active member of company [{$lockedCompany->id}].");
            }

            /** @var OpeningTradeItem $lockedItem */
            $lockedItem = OpeningTradeItem::where('id', $item->id)
                ->where('company_id', $lockedCompany->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedItem->isReversed()) {
                throw new InvalidArgumentException("Opening trade item [{$lockedItem->public_id}] is already reversed.");
            }

            if (! $lockedItem->isPosted() || $lockedItem->posting_batch_id === null) {
                throw new InvalidArgumentException("Opening trade item [{$lockedItem->public_id}] is not in a posted state.");
            }

            // Block reversal if active settlements exist
            if ($lockedItem->isCustomerSide()) {
                $activeCount = $lockedItem->customerPaymentAllocations()
                    ->join('customer_payments', 'customer_payments.id', '=', 'customer_payment_allocations.customer_payment_id')
                    ->where('customer_payments.is_reversed', false)
                    ->count();

                if ($activeCount > 0) {
                    throw new ActiveSettlementBlockedException("Cannot reverse opening customer receivable [{$lockedItem->public_id}] while active customer payment allocations exist. Reverse or unallocate payments first.");
                }
            } else {
                $activeCount = $lockedItem->vendorPaymentAllocations()
                    ->join('vendor_payments', 'vendor_payments.id', '=', 'vendor_payment_allocations.vendor_payment_id')
                    ->where('vendor_payments.is_reversed', false)
                    ->count();

                if ($activeCount > 0) {
                    throw new ActiveSettlementBlockedException("Cannot reverse opening supplier payable [{$lockedItem->public_id}] while active vendor payment allocations exist. Reverse or unallocate payments first.");
                }
            }

            $revDateStr = $reversalDate ?? now($lockedCompany->timezone)->toDateString();
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $revDateStr)) {
                throw new InvalidArgumentException('Reversal date must be in YYYY-MM-DD format.');
            }
            if ($revDateStr < $lockedItem->opening_date->toDateString()) {
                throw new InvalidArgumentException("Reversal date [{$revDateStr}] cannot precede opening date [{$lockedItem->opening_date->toDateString()}].");
            }
            if ($revDateStr > now($lockedCompany->timezone)->toDateString()) {
                throw new InvalidArgumentException('Reversal date cannot be in the future.');
            }

            /** @var PostingBatch $originalBatch */
            $originalBatch = PostingBatch::where('company_id', $lockedCompany->id)
                ->where('id', $lockedItem->posting_batch_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->activeEventScope = $this->eventScope;
            try {
                $reversalBatch = $this->eventScope->withReversalCapability(
                    $this,
                    new OpeningTradeReversalCapability($lockedCompany->id, $actor->id, $originalBatch->id),
                    fn (): PostingBatch => $this->postingService->reverse(
                        original: $originalBatch,
                        actingUser: $actor,
                        reason: "Opening balance reversal: {$cleanReason}",
                        postingDate: $revDateStr,
                    )
                );
            } finally {
                $this->activeEventScope = null;
            }

            $lockedItem->status = OpeningTradeItem::STATUS_REVERSED;
            $lockedItem->reversal_posting_batch_id = $reversalBatch->id;
            $lockedItem->reversed_at = now();
            $lockedItem->reversed_by = $actor->id;
            $lockedItem->correction_reason = $cleanReason;
            $lockedItem->save();

            $this->auditService->log(
                companyId: (int) $lockedCompany->id,
                eventKey: 'accounting.opening_trade_item.reversed',
                summary: "Reversed opening {$lockedItem->side} trade balance #{$lockedItem->public_id}",
                actorUserId: (int) $actor->id,
                subject: $lockedItem,
                after: [
                    'public_id' => $lockedItem->public_id,
                    'status' => $lockedItem->status,
                    'reversal_posting_batch_id' => $reversalBatch->id,
                    'correction_reason' => $cleanReason,
                    'reversal_date' => $revDateStr,
                ],
            );

            return $lockedItem;
        });
    }
}
