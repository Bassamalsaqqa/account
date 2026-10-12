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

        $settings = $policy->settings ?? [];
        $ids = array_values(array_unique(array_filter([
            ...($settings['allowed_connections'] ?? []), $settings['text_connection'] ?? null,
        ])));
        if ($policy->personal_credentials_enabled || ($settings['allowed_personal_routes'] ?? []) !== []
            || ($settings['fallback']['enabled'] ?? false)
            || ($settings['fallback']['text_connection'] ?? null) !== null
            || ($settings['fallback']['speech_connection'] ?? null) !== null
            || ($settings['speech_connection'] ?? null) !== null || count($ids) > 1) {
            return true;
        }

        // Empty, unselected and revoked DeepSeek routes are repairable, not advanced.
        return $ids !== [] && AiProviderConnection::where('company_id', $company->id)->where('public_id', $ids[0])
            ->where(fn ($query) => $query->whereNotNull('owner_user_id')->orWhere('provider', '!=', 'deepseek')
                ->orWhere('model', '!=', 'deepseek-flash')->orWhere('region', '!=', 'global')
                ->orWhere('capability', '!=', 'text_structured'))->exists();
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
        if ($policy !== null) {
            $disclosure['operator'] = $policy->settings['privacy']['operator'] ?? $disclosure['operator'];
            $disclosure['retention'] = $policy->settings['privacy']['retention'] ?? $disclosure['retention'];
            $disclosure['privacy_url'] = $policy->settings['privacy']['policy_url'] ?? $disclosure['privacy_url'];
            $id = $policy->settings['text_connection'] ?? ($policy->settings['allowed_connections'][0] ?? null);
            $pricing = $id === null ? [] : ($policy->settings['pricing'][$id] ?? []);
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
                $priorRouteId = $policy?->settings['text_connection'] ?? ($policy?->settings['allowed_connections'][0] ?? null);
                $repair = $policy !== null && (! $policy->enabled || $connection === null || $connection->revoked_at !== null
                    || ! in_array($connection->public_id, $policy->settings['allowed_connections'] ?? [], true));
                $governance = app(AiGovernance::class);
                if ($key !== '') {
                    $connection = $governance->provision($company, $actor, [
                        'provider' => 'deepseek', 'model' => 'deepseek-flash', 'region' => 'global',
                        'capability' => 'text_structured', 'credential_source' => 'company_byok',
                    ], $key, $password, $connection?->revoked_at === null ? $connection?->public_id : null);
                }
                if ($connection === null || $connection->revoked_at !== null) {
                    throw new AssistantUnavailable('credential_invalid');
                }
                if ($policy === null || $repair) {
                    if ($key === '') {
                        $governance->validateConnection($company, $actor, $connection->public_id, $password);
                    }
                    $settings = [
                        'allowed_connections' => [], 'text_connection' => null,
                        'speech_connection' => null, 'fallback' => ['enabled' => false, 'text_connection' => null, 'speech_connection' => null],
                        'privacy' => DeepSeekDefaults::privacy(), 'pricing' => [],
                        'limits' => DeepSeekDefaults::safeLimits(), 'retention_days' => 30,
                        'global_button_enabled' => true, 'allowed_intents' => ['sales', 'purchase'],
                        'questions' => ['enabled' => $policy === null, 'allowed_reports' => $policy === null ? AssistantReportCatalog::KEYS : []],
                    ];
                    $settings = array_replace($settings, $policy === null ? [] : ($policy->settings ?? []));
                    $price = $priorRouteId === null ? null : ($settings['pricing'][$priorRouteId] ?? null);
                    $settings['allowed_connections'] = [$connection->public_id];
                    $settings['text_connection'] = $connection->public_id;
                    $settings['pricing'] = [$connection->public_id => $price ?? DeepSeekDefaults::pricing()];
                    $policy = $governance->updatePolicy($company, $actor, [
                        'enabled' => true, 'personal_credentials_enabled' => false, 'follow_up_enabled' => $policy === null ? true : $policy->follow_up_enabled,
                        'workflow_mode' => $policy === null ? 'seamless' : $policy->workflow_mode, 'speech_method' => $policy === null ? 'disabled' : $policy->speech_method,
                        'settings' => $settings,
                    ], $expectedPolicyRevision);
                }
                // Only the explicitly requested simple route/enablement changes; custom governance survives.
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
