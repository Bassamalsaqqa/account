<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Models\AiCompanyPolicy;
use App\Models\AiUserPreference;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class AiSpeechMethods
{
    /** @return list<string> */
    public function allowed(AiCompanyPolicy $policy): array
    {
        return match ($policy->speech_method) {
            'disabled' => [],
            'cloud' => ['cloud'],
            'local_only' => ['local_only'],
            'local_if_supported' => ['local_if_supported', 'local_only', 'cloud'],
            default => [],
        };
    }

    public function effective(AiCompanyPolicy $policy, User $actor): string
    {
        $preference = AiUserPreference::where('company_id', $policy->company_id)->where('user_id', $actor->id)->first();
        $selected = $preference?->preferences['speech_method'] ?? null;

        return is_string($selected) && in_array($selected, $this->allowed($policy), true)
            ? $selected : $policy->speech_method;
    }

    public function choose(Company $company, User $actor, string $method, string $intent = 'auto'): void
    {
        DB::transaction(function () use ($company, $actor, $method, $intent): void {
            $policy = app(AiGovernance::class)->authorizeUse($company, $actor, $intent);
            if ($method !== '' && ! in_array($method, $this->allowed($policy), true)) {
                throw new AssistantUnavailable('provider_configuration');
            }
            $preference = AiUserPreference::firstOrCreate(['company_id' => $company->id, 'user_id' => $actor->id]);
            $values = $preference->preferences ?? [];
            if ($method === '') {
                unset($values['speech_method']);
            } else {
                $values['speech_method'] = $method;
            }
            $preference->update(['preferences' => $values]);
        });
    }
}
