<?php

declare(strict_types=1);

namespace App\Actions\Money;

use App\Models\Check;
use App\Models\Company;
use App\Models\User;
use App\Services\Money\CheckCreation;
use App\Services\Money\MoneyActorGuard;
use App\Services\Money\MoneyEventOwner;
use App\Services\Money\MoneyEventScope;
use App\Services\Sales\ReceiptRequestValues;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class IssueCheckAction implements MoneyEventOwner
{
    private ?MoneyEventScope $activeScope = null;

    public function ownsScope(MoneyEventScope $scope): bool
    {
        return $this->activeScope === $scope;
    }

    /** @param array<string, mixed> $data */
    public function execute(Company $company, User $actor, array $data): Check
    {
        $key = ReceiptRequestValues::key($data['idempotency_key']);
        $intent = app(CheckCreation::class)->intent($company, $actor, $data, 'outgoing');

        return DB::transaction(function () use ($company, $actor, $intent, $key): Check {
            $company = Company::lockForUpdate()->findOrFail($company->id);
            if ((int) auth()->id() !== (int) $actor->id) {
                throw new AuthorizationException('Actor mismatch.');
            }
            app(MoneyActorGuard::class)->authorize((int) $company->id, 'money.check.outgoing.manage');
            app(MoneyActorGuard::class)->authorize((int) $company->id, 'money.vendor_payment.create');
            app(MoneyActorGuard::class)->authorize((int) $company->id, 'purchasing.cost.view');
            if (($existing = app(CheckCreation::class)->existing($intent, $key)) !== null) {
                return $existing;
            }
            $this->activeScope = $scope = app(MoneyEventScope::class);
            try {
                return $scope->within($this, (int) $company->id, $actor, fn ($capability) => app(CheckCreation::class)->record($company, $actor, $intent, $key, $capability));
            } finally {
                $this->activeScope = null;
            }
        });
    }
}
