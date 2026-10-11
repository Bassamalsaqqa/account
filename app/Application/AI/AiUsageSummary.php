<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Models\AssistantRequest;
use App\Models\Company;
use App\Models\User;
use Brick\Math\BigDecimal;

final class AiUsageSummary
{
    /** Aggregate operational metadata only. No transcripts, key owners or accounting values.
     * @return list<array<string, mixed>>
     */
    public function read(Company $company, User $actor, bool $companyScope = false): array
    {
        app(AiGovernance::class)->authorizeCapability($company, $actor, $companyScope ? 'ai.settings.manage' : 'ai.assistant.use');
        $result = [];
        foreach (['daily' => now('UTC')->startOfDay(), 'monthly' => now('UTC')->startOfMonth()] as $period => $start) {
            $scopes = $companyScope ? ['company', 'user'] : ['user'];
            foreach ($scopes as $scope) {
                $query = AssistantRequest::where('company_id', $company->id)->where('created_at', '>=', $start);
                if ($scope === 'user') {
                    $query->where('user_id', $actor->id);
                }
                $reportedCount = (clone $query)->whereNotNull('reported_cost_micros')->count();
                $result[] = ['period' => $period, 'scope' => $scope, 'requests' => (clone $query)->count(),
                    'estimated' => $this->usd((string) (clone $query)->sum('estimated_cost_micros')),
                    'reserved' => $this->usd((string) (clone $query)->sum('reserved_cost_micros')),
                    'reported' => $reportedCount === 0 ? null : $this->usd((string) (clone $query)->sum('reported_cost_micros')),
                    'unknown_requests' => (clone $query)->where('cost_status', 'unknown')->count(),
                    'input_units' => (int) (clone $query)->sum('input_units'), 'output_units' => (int) (clone $query)->sum('output_units'),
                    'audio_seconds' => (int) (clone $query)->sum('audio_seconds')];
            }
        }

        return $result;
    }

    private function usd(string $micros): string
    {
        return (string) BigDecimal::of($micros)->dividedBy('1000000', 6);
    }
}
