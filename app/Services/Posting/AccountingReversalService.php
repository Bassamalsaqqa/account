<?php

declare(strict_types=1);

namespace App\Services\Posting;

use App\Domain\Posting\Exceptions\PostingValidationException;
use App\Exceptions\CompanyReassignmentException;
use App\Exceptions\NoActiveCompanyException;
use App\Models\CompanyUser;
use App\Models\PostingBatch;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;

class AccountingReversalService
{
    public function __construct(
        protected AccountingPostingService $postingService
    ) {}

    /**
     * Atomically reverse a posted batch by creating an inverse batch and cross-linking records.
     * Orchestration facade delegating authoritative persistence to AccountingPostingService.
     */
    public function reverse(PostingBatch $batch, ?User $user = null, ?string $reason = null, ?string $postingDate = null): PostingBatch
    {
        if ($reason !== null && mb_strlen($reason) > 512) {
            throw new \InvalidArgumentException('Reversal reason cannot exceed 512 characters.');
        }

        $context = app(CompanyContext::class);

        if (! $context->hasCompany()) {
            throw new NoActiveCompanyException('Cannot reverse transaction without an active company context.');
        }

        if ($context->companyId() !== $batch->company_id) {
            throw new CompanyReassignmentException("Cannot reverse batch for company [{$batch->company_id}] when active company is [{$context->companyId()}].");
        }

        $actingUser = $user ?? $context->user();
        if ($actingUser === null) {
            throw PostingValidationException::invalidPoster(0, $batch->company_id, 'A valid active user is required to reverse a transaction.');
        }

        $isMember = CompanyUser::where('company_id', $batch->company_id)
            ->where('user_id', $actingUser->id)
            ->where('status', 'active')
            ->exists();

        if (! $isMember) {
            throw PostingValidationException::invalidPoster(
                $actingUser->id,
                $batch->company_id,
                "User [{$actingUser->id}] is not an active member of company [{$batch->company_id}]."
            );
        }

        return $this->postingService->reverse($batch, $actingUser, $reason, $postingDate);
    }
}
