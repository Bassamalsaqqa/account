<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Infrastructure\AI\DeepSeekBalanceHealth;
use App\Models\AiCompanyPolicy;
use App\Models\AiProviderConnection;
use App\Models\AssistantRequest;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use SensitiveParameter;
use Throwable;

final class DeepSeekQuickConnect
{
    public function isOwner(Company $company, User $actor): bool
    {
        return CompanyUser::where('company_id', $company->id)->where('user_id', $actor->id)
            ->where('status', 'active')->where('is_owner', true)->exists()
            && app(AiGovernance::class)->can($company, $actor, 'ai.settings.manage')
            && app(AiGovernance::class)->can($company, $actor, 'ai.credentials.manage');
    }

    private function authorize(Company $company, User $actor): void
    {
        app(AiGovernance::class)->authorizeCapability($company, $actor, 'ai.settings.manage');
        if (! $this->isOwner($company, $actor)) {
            throw new AuthorizationException('Protected company owner authorization is required.');
        }
    }

    public function findExistingConnection(Company $company): ?AiProviderConnection
    {
        $policy = AiCompanyPolicy::where('company_id', $company->id)->first();
        $id = $policy?->settings['text_connection'] ?? null;
        $query = AiProviderConnection::where('company_id', $company->id)->whereNull('owner_user_id')
            ->where('credential_source', DeepSeekDefaults::CREDENTIAL_SOURCE)
            ->where('provider', DeepSeekDefaults::PROVIDER)->where('model', DeepSeekDefaults::MODEL)
            ->where('region', DeepSeekDefaults::REGION)->where('capability', DeepSeekDefaults::CAPABILITY);
        if (is_string($id)) {
            $selected = (clone $query)->where('public_id', $id)->first();
            if ($selected !== null) {
                return $selected;
            }
        }
        // Reuse only an unambiguous company credential, never a personal or foreign key.
        $active = $query->whereNull('revoked_at')->get();

        return $active->count() === 1 ? $active->first() : null;
    }

    public function needsTransition(Company $company): bool
    {
        $policy = AiCompanyPolicy::where('company_id', $company->id)->first();
        if ($policy === null) {
            return false;
        }
        $connection = $this->findExistingConnection($company);
        $settings = $policy->settings ?? [];

        return $policy->personal_credentials_enabled || $policy->speech_method !== 'disabled'
            || ($settings['allowed_personal_routes'] ?? []) !== []
            || ($settings['fallback']['enabled'] ?? false)
            || ($settings['fallback']['text_connection'] ?? null) !== null
            || ($settings['fallback']['speech_connection'] ?? null) !== null
            || ($settings['speech_connection'] ?? null) !== null
            || ($settings['allowed_connections'] ?? []) !== ($connection === null || $connection->revoked_at !== null ? [] : [$connection->public_id])
            || ($settings['text_connection'] ?? null) !== ($connection?->revoked_at === null ? $connection?->public_id : null)
            || ($connection !== null && $connection->revoked_at === null
                && ($settings['pricing'][$connection->public_id] ?? null) !== DeepSeekDefaults::pricing());
    }

    /** Explicit, local-only transition. No credential deletion, dispatch, usage or consent fabrication. */
    public function transition(Company $company, User $actor, #[SensitiveParameter] string $password,
        int $expectedPolicyRevision, int $expectedConnectionRevision): void
    {
        DB::transaction(function () use ($company, $actor, $password, $expectedPolicyRevision, $expectedConnectionRevision): void {
            $this->authorize($company, $actor);
            $policy = AiCompanyPolicy::where('company_id', $company->id)->lockForUpdate()->first();
            $connection = $this->findExistingConnection($company);
            $this->assertRevisions($policy, $connection, $expectedPolicyRevision, $expectedConnectionRevision);
            if ($policy === null) {
                throw new AssistantUnavailable('provider_configuration');
            }
            app(AiGovernance::class)->confirmOwner($company, $actor, $password);
            if ($connection?->revoked_at !== null) {
                $active = AiProviderConnection::where('company_id', $company->id)->whereNull('owner_user_id')
                    ->where('credential_source', DeepSeekDefaults::CREDENTIAL_SOURCE)->whereNull('revoked_at')
                    ->where('provider', DeepSeekDefaults::PROVIDER)->where('model', DeepSeekDefaults::MODEL)
                    ->where('region', DeepSeekDefaults::REGION)->where('capability', DeepSeekDefaults::CAPABILITY)->get();
                $connection = $active->count() === 1 ? $active->first() : null;
            }
            if ($this->needsTransition($company)) {
                $this->normalize($company, $actor, $policy, $connection);
            }
        });
    }

    /** Disabled companies require this separate, deliberate Owner action; setup never enables them. */
    public function enableCompany(Company $company, User $actor, #[SensitiveParameter] string $password, int $expectedPolicyRevision): void
    {
        DB::transaction(function () use ($company, $actor, $password, $expectedPolicyRevision): void {
            $this->authorize($company, $actor);
            app(AiGovernance::class)->confirmOwner($company, $actor, $password);
            $policy = AiCompanyPolicy::where('company_id', $company->id)->lockForUpdate()->first();
            if ($policy === null || (int) $policy->revision !== $expectedPolicyRevision || $this->needsTransition($company)) {
                throw new AssistantUnavailable('proposal_stale');
            }
            if (! $policy->enabled) {
                app(AiGovernance::class)->updatePolicy($company, $actor, [
                    'enabled' => true, 'personal_credentials_enabled' => false, 'follow_up_enabled' => $policy->follow_up_enabled,
                    'workflow_mode' => $policy->workflow_mode, 'speech_method' => 'disabled', 'settings' => $policy->settings,
                ], $expectedPolicyRevision);
            }
        });
    }

    private function assertRevisions(?AiCompanyPolicy $policy, ?AiProviderConnection $connection, int $policyRevision, int $connectionRevision): void
    {
        if (($policy === null ? 0 : (int) $policy->revision) !== $policyRevision
            || ($connection === null ? 0 : (int) $connection->revision) !== $connectionRevision) {
            throw new AssistantUnavailable('proposal_stale');
        }
    }

    private function normalize(Company $company, User $actor, ?AiCompanyPolicy $policy, ?AiProviderConnection $connection): AiCompanyPolicy
    {
        $before = $policy === null ? [] : ($policy->settings ?? []);
        $sameProvider = $connection !== null && ($before['text_connection'] ?? null) === $connection->public_id;
        $settings = [
            'allowed_connections' => $connection === null ? [] : [$connection->public_id],
            'text_connection' => $connection?->public_id, 'speech_connection' => null,
            'fallback' => ['enabled' => false, 'text_connection' => null, 'speech_connection' => null],
            'allowed_personal_routes' => [],
            'privacy' => $sameProvider ? ($before['privacy'] ?? DeepSeekDefaults::privacy()) : DeepSeekDefaults::privacy(),
            'pricing' => $connection === null ? [] : [$connection->public_id => DeepSeekDefaults::pricing()],
            'limits' => array_replace(DeepSeekDefaults::safeLimits(), $before['limits'] ?? []),
            'retention_days' => $before['retention_days'] ?? 30,
            'global_button_enabled' => $before['global_button_enabled'] ?? true,
            'allowed_intents' => $before['allowed_intents'] ?? ['sales', 'purchase'],
            'questions' => $before['questions'] ?? ['enabled' => $policy === null, 'allowed_reports' => $policy === null ? AssistantReportCatalog::KEYS : []],
        ];
        $result = app(AiGovernance::class)->updatePolicy($company, $actor, [
            'enabled' => $policy === null ? true : $policy->enabled, 'personal_credentials_enabled' => false,
            'follow_up_enabled' => $policy === null ? true : $policy->follow_up_enabled,
            'workflow_mode' => $policy === null ? 'seamless' : $policy->workflow_mode,
            'speech_method' => 'disabled', 'settings' => $settings,
        ], $policy === null ? 0 : (int) $policy->revision);
        app(AuditService::class)->log((int) $company->id, 'ai.deepseek.transitioned', 'Company explicitly transitioned to DeepSeek.', (int) $actor->id, $result,
            meta: ['previous_revision' => $policy?->getOriginal('revision') ?? 0,
                'retired_connections' => array_values(array_filter($before['allowed_connections'] ?? [], fn ($id) => is_string($id) && Str::isUuid($id))),
                'enabled' => $result->enabled]);

        return $result;
    }

    public function getConnectionStatus(Company $company): string
    {
        $connection = $this->findExistingConnection($company);

        return $connection === null || $connection->revoked_at !== null ? 'not_configured' : $connection->verification_status;
    }

    /** @return array<string, mixed> */
    public function disclosure(Company $company): array
    {
        $disclosure = DeepSeekDefaults::disclosure();
        $policy = AiCompanyPolicy::where('company_id', $company->id)->first();
        $connection = $this->findExistingConnection($company);
        if ($policy !== null && $connection !== null && $connection->revoked_at === null
            && ($policy->settings['text_connection'] ?? null) === $connection->public_id) {
            $disclosure['operator'] = $policy->settings['privacy']['operator'] ?? $disclosure['operator'];
            $disclosure['retention'] = $policy->settings['privacy']['retention'] ?? $disclosure['retention'];
            $disclosure['privacy_url'] = $policy->settings['privacy']['policy_url'] ?? $disclosure['privacy_url'];

        }

        return $disclosure;
    }

    /**
     * One mounted request key claims one zero-model health attempt under the company lock.
     * Concurrent/replayed requests never dispatch again, including after a timeout.
     * Free health gets NO AiRequestBudget reserve/attempt/counter/no AssistantRequest row.
     *
     * @return array{status: string, reason: ?string}
     */
    public function testConnection(Company $company, User $actor, #[SensitiveParameter] string $key,
        #[SensitiveParameter] string $password, bool $consent, string $requestKey,
        int $expectedPolicyRevision, int $expectedConnectionRevision): array
    {
        return $this->checkConnection($company, $actor, $key, $password, $consent, $requestKey,
            $expectedPolicyRevision, $expectedConnectionRevision, false);
    }

    /** Read-only saved-key refresh. Never provisions keys, changes policy or grants consent.
     * @return array{status: string, reason: ?string}
     */
    public function refreshHealth(Company $company, User $actor, string $requestKey,
        int $expectedPolicyRevision, int $expectedConnectionRevision): array
    {
        return $this->checkConnection($company, $actor, '', '', false, $requestKey,
            $expectedPolicyRevision, $expectedConnectionRevision, true);
    }

    /** @return array{status: string, reason: ?string} */
    private function checkConnection(Company $company, User $actor, #[SensitiveParameter] string $key,
        #[SensitiveParameter] string $password, bool $consent, string $requestKey,
        int $expectedPolicyRevision, int $expectedConnectionRevision, bool $refresh): array
    {
        try {
            if (! Str::isUuid($requestKey)) {
                throw new AssistantUnavailable('input_invalid');
            }
            [$connection, $credential, $establishedPolicyRevision, $establishedConnectionRevision] = DB::transaction(function () use ($company, $actor, $key, $password, $consent, $requestKey, $expectedPolicyRevision, $expectedConnectionRevision, $refresh): array {
                $this->authorize($company, $actor);
                if (! $refresh) {
                    app(AiGovernance::class)->confirmOwner($company, $actor, $password);
                    if (! $consent) {
                        throw new AssistantUnavailable('consent');
                    }
                }
                $policy = AiCompanyPolicy::where('company_id', $company->id)->lockForUpdate()->first();
                $connection = $this->findExistingConnection($company);
                if (($policy === null ? 0 : (int) $policy->revision) !== $expectedPolicyRevision
                    || ($connection === null ? 0 : $connection->revision) !== $expectedConnectionRevision) {
                    throw new AssistantUnavailable('proposal_stale');
                }
                if ($this->needsTransition($company)) {
                    throw new AssistantUnavailable('transition_required');
                }
                $claimKey = 'ai:deepseek:health:claim:'.hash('sha256', $company->id.':'.$actor->id.':'.$requestKey);
                if (! Cache::add($claimKey, true, 600)) {
                    throw new AssistantUnavailable('request_processed');
                }
                $throttleKey = 'ai:deepseek:health:rate:'.hash('sha256', $company->id.':'.$actor->id);
                if (RateLimiter::tooManyAttempts($throttleKey, 6)) {
                    throw new AssistantUnavailable('health_rate_limit');
                }
                RateLimiter::hit($throttleKey, 60);
                $governance = app(AiGovernance::class);
                if (! $refresh && $key !== '') {
                    $connection = $governance->provision($company, $actor, [
                        'provider' => DeepSeekDefaults::PROVIDER, 'model' => DeepSeekDefaults::MODEL,
                        'region' => DeepSeekDefaults::REGION, 'capability' => DeepSeekDefaults::CAPABILITY,
                        'credential_source' => DeepSeekDefaults::CREDENTIAL_SOURCE,
                    ], $key, $password, $connection?->revoked_at === null ? $connection?->public_id : null);
                }
                if ($connection === null || $connection->revoked_at !== null) {
                    throw new AssistantUnavailable('credential_invalid');
                }
                if ($policy === null || ($policy->settings['text_connection'] ?? null) !== $connection->public_id
                    || ! in_array($connection->public_id, $policy->settings['allowed_connections'] ?? [], true)) {
                    if ($refresh) {
                        throw new AssistantUnavailable('proposal_stale');
                    }
                    if ($key === '') {
                        $governance->validateConnection($company, $actor, $connection->public_id, $password);
                    }
                    $policy = $this->normalize($company, $actor, $policy, $connection);
                }
                if (! $refresh) {
                    $governance->recordConsent($company, $actor, $connection->public_id,
                        $governance->consentFingerprint($policy, $connection, 'connection_test'), 'connection_test');
                    // Only the explicitly requested simple route/enablement changes; custom governance survives.
                    if ($policy->enabled && $governance->allowedIntents($company, $actor) !== []) {
                        $governance->recordConsent($company, $actor, $connection->public_id, $governance->consentFingerprint($policy, $connection));
                    }
                    if ($policy->enabled && ($policy->settings['questions']['enabled'] ?? false) === true
                        && app(AssistantReportCatalog::class)->permittedKeys($company, $actor) !== []) {
                        $governance->recordConsent($company, $actor, $connection->public_id, $governance->consentFingerprint($policy, $connection, 'question'), 'question');
                    }
                }

                $grant = $governance->dispatchConnection($company, $actor, $connection->public_id, 'connection_test', 'text_structured', $connection->revision);

                return [$connection, $grant['credential'], (int) $policy->revision, (int) $connection->revision];
            });

            // Zero-model HTTP GET https://api.deepseek.com/user/balance health check
            try {
                $health = app(DeepSeekBalanceHealth::class)->check($credential);
            } catch (AssistantUnavailable $e) {
                $health = $e->diagnosticReason();
            }
            // Fresh verification & authorization post-IO
            DB::transaction(function () use ($company, $actor, $connection, $health, $establishedPolicyRevision, $establishedConnectionRevision): void {
                $this->authorize($company, $actor);
                $freshPolicy = AiCompanyPolicy::where('company_id', $company->id)->lockForUpdate()->first();
                $freshConnection = AiProviderConnection::whereKey($connection->id)->where('company_id', $company->id)->lockForUpdate()->first();
                if ($freshConnection === null || $freshConnection->revoked_at !== null) {
                    throw new AssistantUnavailable('credential_invalid');
                }
                if (($freshPolicy === null ? 0 : (int) $freshPolicy->revision) !== $establishedPolicyRevision
                    || (int) $freshConnection->revision !== $establishedConnectionRevision) {
                    throw new AssistantUnavailable('proposal_stale');
                }
                app(AiGovernance::class)->dispatchConnection($company, $actor, $connection->public_id, 'connection_test', 'text_structured', $establishedConnectionRevision);
                if ($health === 'key_valid_funds_available') {
                    $freshConnection->update(['verification_status' => 'verified']);
                } elseif ($health === 'provider_authentication') {
                    // Authoritative key rejection differs from a transient refresh failure.
                    $freshConnection->update(['verification_status' => 'configured_unverified']);
                }
                // No credit does not invalidate a previously verified unchanged key.
                // Newly provisioned/replaced keys stay unverified until health succeeds.
                app(AuditService::class)->log((int) $company->id, 'ai.connection.health.checked',
                    'DeepSeek credential and balance health checked; no model request.', (int) $actor->id, $freshConnection,
                    after: ['health_status' => $health, 'model_tokens' => 0],
                    meta: ['connection_revision' => $establishedConnectionRevision]);
            });

            return ['status' => $health === 'key_valid_funds_available' ? 'verified' : 'failed', 'reason' => $health];
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (Throwable $e) {
            $reason = $e instanceof AssistantUnavailable ? $e->diagnosticReason() : 'unavailable';

            return ['status' => 'failed', 'reason' => $reason];
        }
    }

    public function canStartUsing(Company $company, User $actor): bool
    {
        $this->authorize($company, $actor);
        $connection = $this->findExistingConnection($company);
        if ($connection === null || $connection->revoked_at !== null || $connection->verification_status !== 'verified'
            || in_array($this->latestHealth($company)['status'] ?? null, ['no_available_credit', 'provider_credit', 'provider_authentication'], true)) {
            return false;
        }
        try {
            app(AiGovernance::class)->dispatchConnection($company, $actor, $connection->public_id,
                app(AiGovernance::class)->allowedIntents($company, $actor) === [] ? 'question' : 'auto', 'text_structured', $connection->revision);

            return true;
        } catch (AssistantUnavailable|AuthorizationException) {
            return false;
        }
    }

    /** Sanitized, revision-scoped health metadata; never exposes balances or credentials.
     * @return array{status: string, checked_at: string}|null
     */
    public function latestHealth(Company $company): ?array
    {
        $connection = $this->findExistingConnection($company);
        if ($connection === null || $connection->revoked_at !== null) {
            return null;
        }
        $event = AuditEvent::where('company_id', $company->id)->where('event_key', 'ai.connection.health.checked')
            ->where('subject_type', AiProviderConnection::class)->where('subject_id', $connection->id)
            ->where('meta_json->connection_revision', $connection->revision)->latest('id')->first();
        $payload = $event?->getAttribute('after_json');
        $status = is_array($payload) ? ($payload['health_status'] ?? null) : null;
        $checkedAt = $event?->getAttribute('created_at');
        if (! is_string($status) || ! in_array($status, ['key_valid_funds_available', 'no_available_credit',
            'provider_authentication', 'provider_credit', 'provider_format', 'provider_parameters',
            'provider_rate_limit', 'provider_outage', 'provider_timeout', 'provider_invalid'], true)
            || ! $checkedAt instanceof Carbon) {
            return null;
        }

        return ['status' => $status, 'checked_at' => $checkedAt->toIso8601String()];
    }
}
