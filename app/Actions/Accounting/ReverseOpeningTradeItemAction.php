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
use Illuminate\Support\Facades\DB;
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

            // Recheck authorization against the freshly locked item
            if ($lockedItem->isCustomerSide()) {
                if (! $actor->hasPermissionTo('customers.manage')) {
                    throw new AuthorizationException('Reversing opening customer receivables requires customers.manage permission.');
                }
            } elseif ($lockedItem->isVendorSide()) {
                if (! $actor->hasPermissionTo('vendors.manage') || ! $actor->hasPermissionTo('purchasing.cost.view')) {
                    throw new AuthorizationException('Reversing opening supplier payables requires vendors.manage and purchasing.cost.view permissions.');
                }
            }

            if ($lockedItem->isReversed()) {
                throw new InvalidArgumentException("Opening trade item [{$lockedItem->public_id}] is already reversed.");
            }

            if (! $lockedItem->isPosted() || $lockedItem->posting_batch_id === null) {
                throw new InvalidArgumentException("Opening trade item [{$lockedItem->public_id}] is not in a posted state.");
            }

            $prefix = $lockedItem->isCustomerSide() ? 'customer' : 'vendor';
            $allocations = DB::table($prefix.'_payment_allocations as a')
                ->join($prefix.'_payments as p', 'p.id', '=', 'a.'.$prefix.'_payment_id')
                ->join('posting_batches as pb', 'pb.id', '=', 'p.posting_batch_id')
                ->leftJoin('posting_batches as pr', 'pr.id', '=', 'p.reversal_posting_batch_id')
                ->leftJoin($prefix.'_payment_application_events as e', 'e.id', '=', 'a.application_event_id')
                ->leftJoin('posting_batches as eb', 'eb.id', '=', 'e.posting_batch_id')
                ->leftJoin('posting_batches as er', 'er.id', '=', 'e.reversal_posting_batch_id')
                ->where('a.company_id', $lockedCompany->id)
                ->where('p.company_id', $lockedCompany->id)
                ->where('a.opening_trade_item_id', $lockedItem->id)
                ->get([
                    'p.id as payment_id',
                    'p.payment_date',
                    'p.is_reversed as payment_is_reversed',
                    'pb.posting_date as payment_posting_date',
                    'pr.posting_date as payment_reversal_posting_date',
                    'a.application_event_id',
                    'e.application_date as application_date',
                    'e.reversed_at as application_reversed_at',
                    'eb.posting_date as application_posting_date',
                    'er.posting_date as application_reversal_posting_date',
                ]);

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

            $maxPrerequisiteDate = null;
            foreach ($allocations as $alloc) {
                $eventReversed = $alloc->application_event_id !== null && ($alloc->application_reversed_at !== null || $alloc->application_reversal_posting_date !== null);
                $isAllocationActive = ! $alloc->payment_is_reversed && ! $eventReversed;
                if ($isAllocationActive) {
                    $sideLabel = $lockedItem->isCustomerSide() ? 'customer receivable' : 'supplier payable';
                    $typeLabel = $lockedItem->isCustomerSide() ? 'customer payment' : 'vendor payment';
                    throw new ActiveSettlementBlockedException("Cannot reverse opening {$sideLabel} [{$lockedItem->public_id}] while active {$typeLabel} allocations exist. Reverse or unallocate payments first.");
                }

                $settlementDate = (string) ($alloc->application_posting_date ?? $alloc->application_date ?? $alloc->payment_posting_date ?? $alloc->payment_date);
                $settlementDate = substr($settlementDate, 0, 10);
                $reversalDateCandidates = [];
                if ($alloc->payment_is_reversed && $alloc->payment_reversal_posting_date !== null) {
                    $reversalDateCandidates[] = substr((string) $alloc->payment_reversal_posting_date, 0, 10);
                }
                if ($alloc->application_reversal_posting_date !== null) {
                    $reversalDateCandidates[] = substr((string) $alloc->application_reversal_posting_date, 0, 10);
                }
                $latestReversalDate = empty($reversalDateCandidates) ? $settlementDate : max($reversalDateCandidates);
                $prerequisiteDate = max($settlementDate, $latestReversalDate);
                if ($maxPrerequisiteDate === null || $prerequisiteDate > $maxPrerequisiteDate) {
                    $maxPrerequisiteDate = $prerequisiteDate;
                }

                if ($revDateStr < $prerequisiteDate) {
                    throw new InvalidArgumentException("Opening trade balance reversal date [{$revDateStr}] cannot precede prerequisite settlement reversal date [{$prerequisiteDate}].");
                }
            }

            /** @var PostingBatch $originalBatch */
            $originalBatch = PostingBatch::where('company_id', $lockedCompany->id)
                ->where('id', $lockedItem->posting_batch_id)
                ->where('source_type', 'opening_trade_item')
                ->where('source_id', $lockedItem->id)
                ->lockForUpdate()
                ->firstOrFail();

            $capability = new OpeningTradeReversalCapability($lockedCompany->id, $actor->id, $originalBatch->id, (int) $lockedItem->id);
            $this->activeEventScope = $this->eventScope;
            try {
                $reversalBatch = $this->eventScope->withReversalCapability(
                    $this,
                    $capability,
                    function () use ($originalBatch, $actor, $cleanReason, $revDateStr, $lockedItem, $capability, $maxPrerequisiteDate): PostingBatch {
                        $reversalBatch = $this->postingService->reverse(
                            original: $originalBatch,
                            actingUser: $actor,
                            reason: "Opening balance reversal: {$cleanReason}",
                            postingDate: $revDateStr,
                        );

                        if ($reversalBatch->status !== PostingBatch::STATUS_POSTED || (int) $reversalBatch->reversal_of_id !== (int) $originalBatch->id) {
                            throw new InvalidArgumentException('Reversal journal must link to original posting batch.');
                        }
                        if ($originalBatch->source_type !== 'opening_trade_item' || (int) $originalBatch->source_id !== (int) $lockedItem->id) {
                            throw new InvalidArgumentException('Original journal source identity must match opening trade item.');
                        }
                        if ((int) $reversalBatch->company_id !== (int) $originalBatch->company_id) {
                            throw new InvalidArgumentException('Reversal journal company must match opening trade item.');
                        }

                        $actualEffectiveDate = $reversalBatch->posting_date->format('Y-m-d');
                        $openingDateStr = $lockedItem->opening_date->format('Y-m-d');
                        if ($actualEffectiveDate < $openingDateStr) {
                            throw new InvalidArgumentException("Opening trade balance reversal batch effective date [{$actualEffectiveDate}] cannot precede opening date [{$openingDateStr}].");
                        }
                        if ($maxPrerequisiteDate !== null && $actualEffectiveDate < $maxPrerequisiteDate) {
                            throw new InvalidArgumentException("Opening trade balance reversal batch effective date [{$actualEffectiveDate}] cannot precede prerequisite settlement reversal date [{$maxPrerequisiteDate}].");
                        }

                        $freshOriginal = PostingBatch::withoutGlobalScopes()->find($originalBatch->id);
                        if ($freshOriginal === null || (int) $freshOriginal->reversed_by_batch_id !== (int) $reversalBatch->id) {
                            throw new InvalidArgumentException('Original posting batch must reciprocally link to reversal batch.');
                        }

                        $capability->authorizeReversalBatch((int) $reversalBatch->id);
                        $lockedItem->status = OpeningTradeItem::STATUS_REVERSED;
                        $lockedItem->reversal_posting_batch_id = $reversalBatch->id;
                        $lockedItem->reversed_at = now();
                        $lockedItem->reversed_by = $actor->id;
                        $lockedItem->correction_reason = $cleanReason;
                        $lockedItem->save();

                        return $reversalBatch;
                    }
                );
            } finally {
                $this->activeEventScope = null;
            }

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
