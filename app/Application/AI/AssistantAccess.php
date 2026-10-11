<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Models\Company;
use App\Models\User;
use App\Services\Sales\SalesActorGuard;
use Illuminate\Support\Facades\DB;

final class AssistantAccess
{
    public function available(?int $companyId = null): bool
    {
        return config('ai_assistant.enabled') === true
            && config('ai_assistant.provider_approved') === true
            && config('ai_assistant.provider') === 'gateway'
            && ($companyId === null || ! in_array((string) $companyId, config('ai_assistant.disabled_company_ids', []), true));
    }

    public function authorize(Company $company, User $actor, bool $speech = false): void
    {
        if (! $this->available((int) $company->id) || ($speech && config('ai_assistant.speech_enabled') !== true)) {
            throw new AssistantUnavailable('disabled');
        }

        DB::transaction(function () use ($company, $actor): void {
            app(SalesActorGuard::class)->lockAndAuthorize((int) $company->id, $actor, 'sales.invoice.create');
            app(SalesActorGuard::class)->lockAndAuthorize((int) $company->id, $actor, 'sales.invoice.view');
        });
    }
}
