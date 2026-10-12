<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Infrastructure\AI\DeepSeekSdkAdapter;
use App\Models\AiCompanyPolicy;
use App\Models\AiProviderConnection;
use App\Models\AssistantRequest;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
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
        if ($id === null) {
            return null;
        }

        return AiProviderConnection::where('company_id', $company->id)->where('public_id', $id)
            ->whereNull('owner_user_id')->where('provider', 'deepseek')->where('model', 'deepseek-flash')
            ->where('region', 'global')->where('capability', 'text_structured')->first();
    }

    public function isAdvancedPolicy(Company $company): bool
    {
        $policy = AiCompanyPolicy::where('company_id', $company->id)->first();
        if ($policy === null) {
            return false;
        }

        return $policy->personal_credentials_enabled || ($policy->settings['fallback']['enabled'] ?? false)
            || ($policy->settings['speech_connection'] ?? null) !== null
            || count($policy->settings['allowed_connections'] ?? []) > 1
            || $this->findExistingConnection($company) === null;
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
        if ($policy !== null && $connection !== null) {
            $disclosure['operator'] = $policy->settings['privacy']['operator'];
            $disclosure['retention'] = $policy->settings['privacy']['retention'];
            $disclosure['privacy_url'] = $policy->settings['privacy']['policy_url'];
            $pricing = $policy->settings['pricing'][$connection->public_id] ?? [];
            if ($pricing !== []) {
                $disclosure['pricing'] = $pricing + [
                    'input_display' => (string) BigDecimal::of($pricing['input_micros_per_million'])->dividedBy('1000000', 6)->strippedOfTrailingZeros(),
                    'output_display' => (string) BigDecimal::of($pricing['output_micros_per_million'])->dividedBy('1000000', 6)->strippedOfTrailingZeros(),
                ];
            }
        }

        return $disclosure;
    }

    /**
     * One mounted request key claims one attempt under the existing company lock.
     * Concurrent/replayed requests never dispatch again, including after a timeout.
     *
     * @return array{status: string, reason: ?string}
     */
    public function testConnection(Company $company, User $actor, #[SensitiveParameter] string $key,
        #[SensitiveParameter] string $password, bool $consent, string $requestKey,
        int $expectedPolicyRevision, int $expectedConnectionRevision): array
    {
        $request = null;
        try {
            [$request, $connection, $created] = DB::transaction(function () use ($company, $actor, $key, $password, $consent, $requestKey, $expectedPolicyRevision, $expectedConnectionRevision): array {
                $this->authorize($company, $actor);
                $existing = AssistantRequest::where('company_id', $company->id)->where('user_id', $actor->id)
                    ->where('request_key', $requestKey)->lockForUpdate()->first();
                if ($existing !== null) {
                    throw new AssistantUnavailable('request_processed');
                }
                if (! $consent) {
                    throw new AssistantUnavailable('consent');
                }
                $policy = AiCompanyPolicy::where('company_id', $company->id)->lockForUpdate()->first();
                $connection = $this->findExistingConnection($company);
                if (($policy === null ? 0 : (int) $policy->revision) !== $expectedPolicyRevision
                    || ($connection === null ? 0 : $connection->revision) !== $expectedConnectionRevision) {
                    throw new AssistantUnavailable('proposal_stale');
                }
                if ($this->isAdvancedPolicy($company)) {
                    throw new AssistantUnavailable('advanced_policy');
                }
                $governance = app(AiGovernance::class);
                if ($key !== '') {
                    $connection = $governance->provision($company, $actor, [
                        'provider' => 'deepseek', 'model' => 'deepseek-flash', 'region' => 'global',
                        'capability' => 'text_structured', 'credential_source' => 'company_byok',
                    ], $key, $password, $connection?->public_id);
                }
                if ($connection === null || $connection->revoked_at !== null) {
                    throw new AssistantUnavailable('credential_invalid');
                }
                if ($policy === null) {
                    $policy = $governance->updatePolicy($company, $actor, [
                        'enabled' => true, 'personal_credentials_enabled' => false, 'follow_up_enabled' => true,
                        'workflow_mode' => 'seamless', 'speech_method' => 'disabled', 'settings' => [
                            'allowed_connections' => [$connection->public_id], 'text_connection' => $connection->public_id,
                            'speech_connection' => null, 'fallback' => ['enabled' => false, 'text_connection' => null, 'speech_connection' => null],
                            'privacy' => DeepSeekDefaults::privacy(), 'pricing' => [$connection->public_id => DeepSeekDefaults::pricing()],
                            'limits' => DeepSeekDefaults::safeLimits(), 'retention_days' => 30,
                            'global_button_enabled' => true, 'allowed_intents' => ['sales', 'purchase'],
                            'questions' => ['enabled' => true, 'allowed_reports' => AssistantReportCatalog::KEYS],
                        ],
                    ], 0);
                }
                // Never rewrite existing limits, prices, allowed capabilities or governance.
                $governance->recordConsent($company, $actor, $connection->public_id, $governance->consentFingerprint($policy, $connection));
                if (($policy->settings['questions']['enabled'] ?? false) === true
                    && app(AssistantReportCatalog::class)->permittedKeys($company, $actor) !== []) {
                    $governance->recordConsent($company, $actor, $connection->public_id, $governance->consentFingerprint($policy, $connection, 'question'), 'question');
                }
                $reservation = app(AiRequestBudget::class)->reserve($company, $actor, $requestKey, $requestKey,
                    'intent', 'auto', hash('sha256', 'deepseek-connection-test-v1'), [$connection]);
                app(AiRequestBudget::class)->beginAttempt($company, $actor, $reservation['request'], $connection);
                $connection->update(['verification_status' => 'configured_unverified']);

                return [$reservation['request'], $connection, $reservation['created']];
            });
            if (! $created) {
                throw new AssistantUnavailable('request_processed');
            }
            $grant = app(AiGovernance::class)->dispatchConnection($company, $actor, $connection->public_id, 'auto', 'text_structured', $connection->revision);
            $usage = app(DeepSeekSdkAdapter::class)->testConnection($grant['credential']);
            DB::transaction(function () use ($company, $actor, $request, $connection, $usage): void {
                $this->authorize($company, $actor);
                $grant = app(AiGovernance::class)->dispatchConnection($company, $actor, $connection->public_id, 'auto', 'text_structured', $connection->revision);
                $fresh = AssistantRequest::whereKey($request->id)->where('company_id', $company->id)->where('user_id', $actor->id)->lockForUpdate()->firstOrFail();
                if ($fresh->status !== 'pending' || (int) $fresh->attempt_count !== 1
                    || (int) $grant['policy']->revision !== (int) $fresh->policy_revision) {
                    throw new AssistantUnavailable('proposal_stale');
                }
                app(AiRequestBudget::class)->recordUsage($company, $actor, $fresh, $usage);
                $fresh->update(['status' => 'completed', 'cost_status' => 'unknown']);
                $grant['connection']->update(['verification_status' => 'verified']);
            });

            return ['status' => 'verified', 'reason' => null];
        } catch (AuthorizationException $e) {
            if ($request !== null) {
                AssistantRequest::whereKey($request->id)->where('company_id', $company->id)->where('user_id', $actor->id)
                    ->where('status', 'pending')->update(['status' => 'failed', 'failure_code' => 'unauthorized']);
            }
            throw $e;
        } catch (Throwable $e) {
            $reason = $e instanceof AssistantUnavailable ? $e->diagnosticReason() : 'unavailable';
            if ($request !== null) {
                AssistantRequest::whereKey($request->id)->where('company_id', $company->id)->where('user_id', $actor->id)
                    ->where('status', 'pending')->update(['status' => 'failed', 'failure_code' => $reason]);
            }

            return ['status' => 'failed', 'reason' => $reason];
        }
    }

    public function canStartUsing(Company $company, User $actor): bool
    {
        $this->authorize($company, $actor);
        $connection = $this->findExistingConnection($company);
        if ($connection === null || $connection->verification_status !== 'verified') {
            return false;
        }
        try {
            app(AiGovernance::class)->dispatchConnection($company, $actor, $connection->public_id, 'auto', 'text_structured', $connection->revision);

            return true;
        } catch (AssistantUnavailable) {
            return false;
        }
    }
}
