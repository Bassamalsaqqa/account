<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Application\Reporting\DTO\ReportFilters;
use App\Application\Reporting\Security\ReportingGuard;
use App\Models\AiCompanyPolicy;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Short-lived, encrypted-at-rest metadata context for assistant report follow-ups.
 * Stores metadata only (report_key + safe filters), strictly bounded by company,
 * actor, session, and policy revision. 20-minute TTL. Payload capped at 4096 bytes.
 * Rechecks fresh permissions on reading; no raw report replay.
 */
final class AssistantQuestionContext
{
    public const int TTL_SECONDS = 1200; // 20 minutes

    public const int MAX_PAYLOAD_BYTES = 4096;

    /**
     * @param  array<string, string>  $filters
     */
    public function store(Company $company, User $actor, string $sessionId, string $reportKey, array $filters): void
    {
        if ($sessionId === '' || trim($sessionId) === '' || strlen($sessionId) > 255) {
            return;
        }

        if (! in_array($reportKey, AssistantReportCatalog::KEYS, true)) {
            return;
        }

        $guard = app(ReportingGuard::class);
        $persistedCompany = $guard->company($company, $actor);

        app(AssistantReportCatalog::class)->authorize($persistedCompany, $actor, $reportKey);

        $plan = AssistantReportPlan::fromArray([
            'version' => 1,
            'type' => 'report_query',
            'report_key' => $reportKey,
            'filters' => $filters,
            'display' => 'summary',
        ]);

        ReportFilters::fromArray($persistedCompany, $plan->filters);

        $policy = AiCompanyPolicy::where('company_id', $persistedCompany->id)->first();
        if ($policy === null || ! $policy->enabled) {
            return;
        }

        $payload = [
            'company_id' => (int) $persistedCompany->id,
            'user_id' => (int) $actor->id,
            'session_hash' => hash('sha256', $sessionId),
            'policy_revision' => (int) $policy->revision,
            'report_key' => $reportKey,
            'filters' => $plan->filters,
            'expires_at' => now()->addSeconds(self::TTL_SECONDS)->timestamp,
        ];

        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        if (strlen($json) > self::MAX_PAYLOAD_BYTES) {
            return;
        }

        $ciphertext = Crypt::encryptString($json);
        Cache::put($this->cacheKey($persistedCompany, $actor, $sessionId), $ciphertext, self::TTL_SECONDS);
    }

    /**
     * @return array{report_key: string, filters: array<string, string>}|null
     */
    public function read(Company $company, User $actor, string $sessionId): ?array
    {
        if ($sessionId === '' || trim($sessionId) === '' || strlen($sessionId) > 255) {
            return null;
        }

        $key = $this->cacheKey($company, $actor, $sessionId);
        $ciphertext = Cache::get($key);
        if (! is_string($ciphertext) || $ciphertext === '') {
            return null;
        }

        try {
            if (strlen($ciphertext) > 8192) {
                throw new \UnexpectedValueException('Context exceeds the encrypted byte limit.');
            }
            $rawJson = Crypt::decryptString($ciphertext);
            if (strlen($rawJson) > self::MAX_PAYLOAD_BYTES) {
                throw new \UnexpectedValueException('Context exceeds the metadata byte limit.');
            }
            $data = json_decode($rawJson, true, 16, JSON_THROW_ON_ERROR);
            if (! is_array($data)) {
                Cache::forget($key);

                return null;
            }

            $dataKeys = array_keys($data);
            sort($dataKeys);
            if ($dataKeys !== ['company_id', 'expires_at', 'filters', 'policy_revision', 'report_key', 'session_hash', 'user_id']) {
                Cache::forget($key);

                return null;
            }

            if (! is_int($data['company_id']) || $data['company_id'] !== (int) $company->id
                || ! is_int($data['user_id']) || $data['user_id'] !== (int) $actor->id
                || ! is_string($data['session_hash']) || ! hash_equals($data['session_hash'], hash('sha256', $sessionId))
                || ! is_int($data['expires_at']) || $data['expires_at'] <= now()->timestamp
                || $data['expires_at'] > now()->addSeconds(self::TTL_SECONDS)->timestamp
                || ! is_int($data['policy_revision'])
                || ! is_string($data['report_key']) || ! in_array($data['report_key'], AssistantReportCatalog::KEYS, true)
                || ! is_array($data['filters'])) {
                Cache::forget($key);

                return null;
            }

            $policy = AiCompanyPolicy::where('company_id', $company->id)->first();
            if ($policy === null || ! $policy->enabled || (int) $policy->revision !== $data['policy_revision']) {
                Cache::forget($key);

                return null;
            }

            $persistedCompany = app(ReportingGuard::class)->company($company, $actor);
            app(AssistantReportCatalog::class)->authorize($persistedCompany, $actor, $data['report_key']);

            $plan = AssistantReportPlan::fromArray([
                'version' => 1,
                'type' => 'report_query',
                'report_key' => $data['report_key'],
                'filters' => $data['filters'],
                'display' => 'summary',
            ]);

            ReportFilters::fromArray($persistedCompany, $plan->filters);

            return [
                'report_key' => $data['report_key'],
                'filters' => $plan->filters,
            ];
        } catch (Throwable) {
            Cache::forget($key);

            return null;
        }
    }

    public function clear(Company $company, User $actor, string $sessionId): void
    {
        if ($sessionId !== '') {
            Cache::forget($this->cacheKey($company, $actor, $sessionId));
        }
    }

    public function cacheKey(Company $company, User $actor, string $sessionId): string
    {
        return 'ai_q_ctx:'.hash('sha256', (string) $company->id.':'.(string) $actor->id.':'.$sessionId);
    }
}
