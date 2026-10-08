<?php

declare(strict_types=1);

namespace App\Services\Phase7;

use App\Domain\Posting\DTO\PostingCommand;
use App\Models\PostingBatch;
use App\Models\User;
use App\Services\Posting\AccountingPostingService;
use App\Services\Posting\AccountingReversalService;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

trait OwnsPhase7Event
{
    private bool $executingPhase7 = false;

    public function ownsPhase7Scope(Phase7EventScope $scope): bool
    {
        return $this->executingPhase7 && $scope === app(Phase7EventScope::class);
    }

    private function canonicalTransaction(int $companyId, User $actor, Closure $callback): mixed
    {
        return DB::transaction(function () use ($companyId, $actor, $callback) {
            $this->executingPhase7 = true;
            try {
                return app(Phase7EventScope::class)->within($this, $companyId, $actor, $callback);
            } finally {
                $this->executingPhase7 = false;
            }
        });
    }

    private function persistPhase7(Model $record): void
    {
        app(Phase7EventScope::class)->persist($this, $record);
    }

    private function createPhase7(Model $record): Model
    {
        $this->persistPhase7($record);

        return $record;
    }

    private function postPhase7(PostingCommand $command): PostingBatch
    {
        app(Phase7EventScope::class)->prepareCommand($this, $command);

        return app(AccountingPostingService::class)->post($command);
    }

    private function reversePhase7(PostingBatch $batch, User $actor, ?string $reason, string $date): PostingBatch
    {
        app(Phase7EventScope::class)->prepareReversal($this, $batch, $date);

        return app(AccountingReversalService::class)->reverse($batch, $actor, $reason, $date);
    }
}
