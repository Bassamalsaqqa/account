<?php

declare(strict_types=1);

namespace App\Services\Money\Contracts;

use App\Models\Check;
use App\Models\User;
use App\Services\Money\MoneyEventCapability;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;

interface CheckFinancialSourceAdapter
{
    public function sourceType(): string;

    public function sourceId(): int;

    public function sourcePublicId(): string;

    public function sourceModel(): Model;

    public function direction(): string;

    public function currencyCode(): string;

    public function amount(): BigDecimal;

    public function exchangeRate(): BigDecimal;

    public function amountBase(): BigDecimal;

    public function businessDate(): string;

    public function createdBy(): int;

    public function postingBatchId(): ?int;

    public function isReversed(): bool;

    public function reversalPostingBatchId(): ?int;

    /** @return array<string, mixed>|null */
    public function partySnapshot(): ?array;

    public function partyDisplayName(): string;

    public function validateIntegrity(Check $check): void;

    public function authorizeRead(int $companyId, User $actor): void;

    public function authorizeReverse(int $companyId, User $actor): void;

    public function assertCanReverse(Check $check, ?string $reason = null): void;

    public function executeReversal(Check $check, User $actor, ?string $reason, string $date, MoneyEventCapability $capability): int;
}
