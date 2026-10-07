<?php

declare(strict_types=1);

namespace App\Actions\Money;

use App\Models\MoneyTransfer;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Money\MoneyEventCapability;
use App\Services\Money\MoneyEventOwner;
use App\Services\Money\MoneyEventScope;
use App\Services\Money\MoneyTransferHistory;
use App\Services\Money\MoneyValues;
use App\Services\Posting\AccountingReversalService;
use App\Services\Sales\SalesActorGuard;
use App\Services\Sales\SalesDocumentRules;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ReverseMoneyTransferAction implements MoneyEventOwner
{
    private ?MoneyEventScope $activeScope = null;

    public function ownsScope(MoneyEventScope $scope): bool
    {
        return $this->activeScope === $scope;
    }

    public function execute(MoneyTransfer $transfer, User $actor, ?string $date = null, ?string $reason = null): MoneyTransfer
    {
        $reason = MoneyValues::text($reason, 500);

        return DB::transaction(function () use ($transfer, $actor, $date, $reason): MoneyTransfer {
            $company = app(SalesActorGuard::class)->lockAndAuthorize((int) $transfer->company_id, $actor, 'money.transfer.reverse');
            $transfer = MoneyTransfer::where('company_id', $company->id)->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            app(MoneyTransferHistory::class)->validate($transfer);
            if ($transfer->is_reversed) {
                return $transfer;
            }
            $date = $date ?? Carbon::now($company->timezone)->toDateString();
            app(SalesDocumentRules::class)->date($date);
            if ($date < $transfer->transfer_date->toDateString()) {
                throw new InvalidArgumentException('Reversal cannot precede the original transfer.');
            }
            $scope = app(MoneyEventScope::class);
            $this->activeScope = $scope;
            try {
                return $scope->within($this, (int) $company->id, $actor, function (MoneyEventCapability $capability) use ($scope, $transfer, $company, $actor, $date, $reason): MoneyTransfer {
                    $batch = $transfer->postingBatch()->firstOrFail();
                    $scope->prepareReversal($capability, $batch, $date);
                    $reversal = app(AccountingReversalService::class)->reverse($batch, $actor, $reason, $date);
                    $completion = [
                        'company_id' => (int) $company->id, 'is_reversed' => true, 'reversal_date' => $date,
                        'reversed_at' => now(), 'reversed_by' => (int) $actor->id,
                        'reversal_posting_batch_id' => (int) $reversal->id, 'reversal_reason' => $reason,
                    ];
                    $scope->prepareRecord($capability, MoneyTransfer::class.':'.$transfer->id, $completion);
                    $transfer->complete($capability, $completion);
                    app(MoneyTransferHistory::class)->validate($transfer);
                    app(AuditService::class)->log((int) $company->id, 'money_transfer.reversed', 'Money transfer reversed', (int) $actor->id, $transfer,
                        meta: ['transfer_id' => (int) $transfer->id, 'transfer_number' => $transfer->transfer_number]);

                    return $transfer->fresh();
                });
            } finally {
                $this->activeScope = null;
            }
        });
    }
}
