<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Models\AiCompanyPolicy;
use App\Models\AiProviderConnection;
use App\Models\AiUserPreference;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Sales\SalesActorGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use SensitiveParameter;
use Throwable;

final class AiGovernance
{
    public function authorizeCapability(Company $company, User $actor, string $permission): void
    {
        DB::transaction(fn () => app(SalesActorGuard::class)->lockAndAuthorize((int) $company->id, $actor, $permission));
    }

    public function authorizeUse(Company $company, User $actor, string $intent, bool $speech = false): AiCompanyPolicy
    {
        if (config('ai_assistant.enabled') !== true
            || in_array((string) $company->id, config('ai_assistant.disabled_company_ids', []), true)
            || ($speech && config('ai_assistant.speech_enabled') !== true)) {
            throw new AssistantUnavailable('disabled');
        }

        return DB::transaction(function () use ($company, $actor, $intent, $speech): AiCompanyPolicy {
            $this->authorizeCapability($company, $actor, 'ai.assistant.use');
            if (! in_array($intent, ['sales', 'purchase', 'auto'], true)) {
                throw new AssistantUnavailable('intent_unsupported');
            }
            $policy = AiCompanyPolicy::where('company_id', $company->id)->lockForUpdate()->first();
            if ($policy === null || ! $policy->enabled) {
                throw new AssistantUnavailable('disabled');
            }
            $enabledIntents = $policy->settings['allowed_intents'] ?? ['sales', 'purchase'];
            if ($intent !== 'auto' && ! in_array($intent, $enabledIntents, true)) {
                throw new AuthorizationException('This AI workflow is disabled by company policy.');
            }
            if ($intent === 'auto' && $this->allowedIntents($company, $actor) === []) {
                throw new AuthorizationException('At least one permitted canonical AI workflow is required.');
            }
            if ($intent !== 'auto') {
                $permissions = $intent === 'sales'
                    ? ['ai.sales.use', 'sales.invoice.create', 'sales.invoice.view']
                    : ['ai.purchases.use', 'purchasing.purchase.create', 'purchasing.purchase.view', 'purchasing.cost.view'];
                foreach ($permissions as $permission) {
                    $this->authorizeCapability($company, $actor, $permission);
                }
            }
            if ($speech && ! in_array(app(AiSpeechMethods::class)->effective($policy, $actor), ['cloud', 'local_if_supported'], true)) {
                throw new AssistantUnavailable('disabled');
            }

            return $policy;
        });
    }

    public function authorizeLauncher(Company $company, User $actor, string $context): AiCompanyPolicy
    {
        $policy = $this->authorizeUse($company, $actor, $context);
        if ($context === 'auto' && ! ($policy->settings['global_button_enabled'] ?? true)) {
            throw new AssistantUnavailable('disabled');
        }

        return $policy;
    }

    /** @return list<string> */
    public function allowedIntents(Company $company, User $actor): array
    {
        $policy = AiCompanyPolicy::where('company_id', $company->id)->first();
        if ($policy === null || ! $policy->enabled) {
            return [];
        }
        $enabledIntents = $policy->settings['allowed_intents'] ?? ['sales', 'purchase'];
        $allowed = [];
        foreach ([
            'sales' => ['ai.assistant.use', 'ai.sales.use', 'sales.invoice.create', 'sales.invoice.view'],
            'purchase' => ['ai.assistant.use', 'ai.purchases.use', 'purchasing.purchase.create', 'purchasing.purchase.view', 'purchasing.cost.view'],
        ] as $intent => $permissions) {
            if (! in_array($intent, $enabledIntents, true)) {
                continue;
            }
            try {
                DB::transaction(function () use ($company, $actor, $permissions): void {
                    foreach ($permissions as $permission) {
                        $this->authorizeCapability($company, $actor, $permission);
                    }
                });
                $allowed[] = $intent;
            } catch (AuthorizationException) {
                // No candidate, cost or credential data accompanies a denied capability.
            }
        }

        return $allowed;
    }

    /** @param array<string, mixed> $input */
    public function updatePolicy(Company $company, User $actor, array $input, ?int $expectedRevision = null): AiCompanyPolicy
    {
        if (array_diff(array_keys($input), ['enabled', 'personal_credentials_enabled', 'follow_up_enabled', 'workflow_mode', 'speech_method', 'settings']) !== []) {
            throw new AssistantUnavailable('policy_invalid');
        }
        $values = Validator::make($input, [
            'enabled' => ['required', 'boolean'], 'personal_credentials_enabled' => ['required', 'boolean'],
            'follow_up_enabled' => ['required', 'boolean'], 'workflow_mode' => ['required', 'in:seamless,guided'],
            'speech_method' => ['required', 'in:disabled,cloud,local_only,local_if_supported'],
            'settings' => ['required', 'array'],
        ])->validate();
        $values['settings'] = $this->validateSettings($values['settings']);

        return DB::transaction(function () use ($company, $actor, $values, $expectedRevision): AiCompanyPolicy {
            $this->authorizeCapability($company, $actor, 'ai.settings.manage');
            $policy = AiCompanyPolicy::where('company_id', $company->id)->lockForUpdate()->first()
                ?? new AiCompanyPolicy(['company_id' => $company->id, 'revision' => 0]);
            if ($expectedRevision !== null && (int) $policy->revision !== $expectedRevision) {
                throw new AssistantUnavailable('proposal_stale');
            }
            if ((bool) $values['enabled'] !== (bool) $policy->enabled
                || (bool) $values['personal_credentials_enabled'] !== (bool) $policy->personal_credentials_enabled) {
                $this->requireOwner($company, $actor);
            }
            $settings = $values['settings'];
            foreach ($settings['allowed_connections'] as $id) {
                if (! AiProviderConnection::where('company_id', $company->id)->whereNull('owner_user_id')->where('public_id', $id)->exists()) {
                    throw new AssistantUnavailable('policy_invalid');
                }
            }
            foreach (['text_connection' => 'text_structured', 'speech_connection' => 'audio_transcription'] as $field => $capability) {
                foreach ([$settings[$field] ?? null, $settings['fallback'][$field] ?? null] as $id) {
                    if ($id !== null && (! in_array($id, $settings['allowed_connections'], true)
                        || ! AiProviderConnection::where('company_id', $company->id)->whereNull('owner_user_id')
                            ->where('public_id', $id)->where('capability', $capability)->exists())) {
                        throw new AssistantUnavailable('policy_invalid');
                    }
                }
            }
            foreach (array_keys($settings['pricing']) as $id) {
                if (! in_array($id, [...$settings['allowed_connections'], ...($settings['allowed_personal_routes'] ?? [])], true)) {
                    throw new AssistantUnavailable('policy_invalid');
                }
            }
            $policy->fill($values);
            $policy->revision = (int) $policy->revision + 1;
            $policy->save();
            app(AuditService::class)->log((int) $company->id, 'ai.policy.updated', 'AI company policy updated.', (int) $actor->id, $policy, meta: ['revision' => $policy->revision, 'enabled' => $policy->enabled]);

            return $policy;
        });
    }

    /** @param array<string, mixed> $selection */
    public function provision(Company $company, User $actor, array $selection, #[SensitiveParameter] string $secret, #[SensitiveParameter] string $password, ?string $replaceId = null): AiProviderConnection
    {
        if (array_diff(array_keys($selection), ['provider', 'model', 'region', 'capability', 'credential_source']) !== []) {
            throw new AssistantUnavailable('provider_configuration');
        }
        $route = app(ProviderCapabilityRegistry::class)->resolve(
            (string) ($selection['provider'] ?? ''), (string) ($selection['model'] ?? ''),
            (string) ($selection['region'] ?? ''), (string) ($selection['capability'] ?? ''),
        );
        new ProviderCredential($secret);
        $source = $selection['credential_source'] ?? '';
        if (! in_array($source, ['company_byok', 'personal_byok'], true)) {
            throw new AssistantUnavailable('provider_configuration');
        }

        return DB::transaction(function () use ($company, $actor, $route, $source, $secret, $password, $replaceId): AiProviderConnection {
            $this->authorizeCredential($company, $actor, $source);
            $this->reauthenticate($actor, $password);
            $connection = $replaceId === null ? new AiProviderConnection([
                'company_id' => $company->id, 'public_id' => (string) Str::uuid(), 'revision' => 0,
                'owner_user_id' => $source === 'personal_byok' ? $actor->id : null,
                'credential_source' => $source,
            ]) : $this->findOwnedConnection($company, $actor, $replaceId, true);
            if ($connection->credential_source !== $source) {
                throw new AuthorizationException('Credential ownership cannot change.');
            }
            $connection->fill([
                'provider' => $route['provider'], 'model' => $route['model'], 'region' => $route['region'],
                'capability' => $route['capability'], 'secret_ciphertext' => Crypt::encryptString($secret),
                'revoked_at' => null, 'verification_status' => 'configured_unverified',
            ]);
            $connection->revision = (int) $connection->revision + 1;
            $connection->save();
            app(AuditService::class)->log((int) $company->id, 'ai.credential.configured', 'AI provider connection configured.', (int) $actor->id, $connection, meta: ['public_id' => $connection->public_id, 'provider' => $connection->provider, 'model' => $connection->model, 'region' => $connection->region, 'source' => $connection->credential_source, 'revision' => $connection->revision]);

            return $connection;
        });
    }

    public function revoke(Company $company, User $actor, string $id, #[SensitiveParameter] string $password): void
    {
        DB::transaction(function () use ($company, $actor, $id, $password): void {
            $connection = $this->findOwnedConnection($company, $actor, $id, true);
            $this->authorizeCredential($company, $actor, $connection->credential_source, false);
            $this->reauthenticate($actor, $password);
            // Preserve ownership/version metadata; erase ciphertext after explicit revocation.
            $connection->update(['secret_ciphertext' => '', 'revoked_at' => now(), 'revision' => (int) $connection->revision + 1,
                'verification_status' => 'revoked']);
            app(AuditService::class)->log((int) $company->id, 'ai.credential.revoked', 'AI provider connection revoked.', (int) $actor->id, $connection, meta: ['public_id' => $connection->public_id, 'revision' => $connection->revision]);
        });
    }

    /** Local configuration validation is never a billed provider connection test. */
    public function validateConnection(Company $company, User $actor, string $id, #[SensitiveParameter] string $password): string
    {
        return DB::transaction(function () use ($company, $actor, $id, $password): string {
            $connection = $this->findOwnedConnection($company, $actor, $id, true);
            $this->authorizeCredential($company, $actor, $connection->credential_source);
            $this->reauthenticate($actor, $password);
            app(ProviderCapabilityRegistry::class)->resolve($connection->provider, $connection->model, $connection->region, $connection->capability);
            if ($connection->revoked_at !== null) {
                throw new AssistantUnavailable('credential_revoked');
            }
            $this->credential($connection);

            return 'configured_unverified';
        });
    }

    /** @return list<array<string, mixed>> */
    public function visibleConnections(Company $company, User $actor): array
    {
        $this->authorizeCapability($company, $actor, 'ai.assistant.use');

        return AiProviderConnection::where('company_id', $company->id)
            ->where(fn ($query) => $query->whereNull('owner_user_id')->orWhere('owner_user_id', $actor->id))
            ->orderBy('id')->get()->map(fn (AiProviderConnection $row) => $row->only([
                'public_id', 'provider', 'model', 'region', 'capability', 'credential_source', 'revision', 'revoked_at', 'verification_status',
            ]))->all();
    }

    /** Settings operators may manage credentials without obtaining transaction permissions.
     * @return list<array<string, mixed>>
     */
    public function managedConnections(Company $company, User $actor): array
    {
        $allowed = false;
        foreach (['ai.settings.manage', 'ai.credentials.manage', 'ai.personal_credentials.manage'] as $permission) {
            try {
                $this->authorizeCapability($company, $actor, $permission);
                $allowed = true;
                break;
            } catch (AuthorizationException) {
                // Try only the explicitly delegated governance capabilities.
            }
        }
        if (! $allowed) {
            throw new AuthorizationException('AI governance permission is required.');
        }

        return AiProviderConnection::where('company_id', $company->id)
            ->where(fn ($query) => $query->whereNull('owner_user_id')->orWhere('owner_user_id', $actor->id))
            ->orderBy('id')->get()->map(fn (AiProviderConnection $row) => $row->only([
                'public_id', 'provider', 'model', 'region', 'capability', 'credential_source', 'revision', 'revoked_at', 'verification_status',
            ]))->all();
    }

    public function ownedConnection(Company $company, User $actor, string $id, bool $lock = false): AiProviderConnection
    {
        // Company-first actor lock, even for a caller that has not started a transaction.
        if (DB::transactionLevel() === 0) {
            return DB::transaction(fn () => $this->ownedConnection($company, $actor, $id, $lock));
        }
        $this->authorizeCapability($company, $actor, 'ai.assistant.use');

        return $this->findOwnedConnection($company, $actor, $id, $lock);
    }

    private function findOwnedConnection(Company $company, User $actor, string $id, bool $lock): AiProviderConnection
    {
        $query = AiProviderConnection::where('company_id', $company->id)->where('public_id', $id)
            ->where(fn ($query) => $query->whereNull('owner_user_id')->orWhere('owner_user_id', $actor->id));
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    /** @return array{connection: AiProviderConnection, policy: AiCompanyPolicy, credential: ProviderCredential} */
    public function dispatchConnection(Company $company, User $actor, string $id, string $intent, string $capability, ?int $expectedRevision = null): array
    {
        return DB::transaction(function () use ($company, $actor, $id, $intent, $capability, $expectedRevision): array {
            $policy = $this->authorizeUse($company, $actor, $intent, $capability === 'audio_transcription');
            if (config('ai_assistant.provider_approved') !== true || config('ai_assistant.provider') !== 'direct') {
                throw new AssistantUnavailable('disabled');
            }
            $connection = $this->ownedConnection($company, $actor, $id, true);
            if ($connection->revoked_at !== null || ($expectedRevision !== null && (int) $connection->revision !== $expectedRevision)
                || $connection->capability !== $capability
                || ! $this->connectionAllowed($policy, $connection)) {
                throw new AssistantUnavailable('credential_revoked');
            }
            if ($connection->credential_source === 'personal_byok' && ! $policy->personal_credentials_enabled) {
                throw new AssistantUnavailable('personal_credentials_disabled');
            }
            app(ProviderCapabilityRegistry::class)->resolve($connection->provider, $connection->model, $connection->region, $capability);
            $this->requireConsent($policy, $connection, $actor);

            return ['connection' => $connection, 'policy' => $policy, 'credential' => $this->credential($connection)];
        });
    }

    private function connectionAllowed(AiCompanyPolicy $policy, AiProviderConnection $connection): bool
    {
        if (in_array($connection->public_id, $policy->settings['allowed_connections'] ?? [], true)) {
            return true;
        }
        $route = implode('|', [$connection->provider, $connection->model, $connection->region, $connection->capability]);

        return $connection->credential_source === 'personal_byok' && $policy->personal_credentials_enabled
            && in_array($route, $policy->settings['allowed_personal_routes'] ?? [], true);
    }

    private function credential(AiProviderConnection $connection): ProviderCredential
    {
        try {
            return new ProviderCredential(Crypt::decryptString($connection->secret_ciphertext));
        } catch (Throwable) {
            throw new AssistantUnavailable('credential_invalid');
        }
    }

    public function consentFingerprint(AiCompanyPolicy $policy, AiProviderConnection $connection): string
    {
        return hash('sha256', json_encode([
            'catalog' => ProviderCapabilityRegistry::VERSION, 'company' => $policy->company_id,
            'policy_revision' => $policy->revision, 'connection' => $connection->public_id,
            'connection_revision' => $connection->revision, 'provider' => $connection->provider,
            'model' => $connection->model, 'region' => $connection->region, 'source' => $connection->credential_source,
            'privacy' => $policy->settings['privacy'] ?? [], 'fallback' => $policy->settings['fallback'] ?? [],
        ], JSON_THROW_ON_ERROR));
    }

    public function recordConsent(Company $company, User $actor, string $connectionId, string $fingerprint): void
    {
        DB::transaction(function () use ($company, $actor, $connectionId, $fingerprint): void {
            $policy = $this->authorizeUse($company, $actor, 'auto');
            $connection = $this->ownedConnection($company, $actor, $connectionId, true);
            if (! hash_equals($this->consentFingerprint($policy, $connection), $fingerprint)) {
                throw new AssistantUnavailable('consent_stale');
            }
            $preference = AiUserPreference::firstOrCreate(['company_id' => $company->id, 'user_id' => $actor->id]);
            $consents = $preference->consents ?? [];
            $consents[$connectionId] = ['fingerprint' => $fingerprint, 'recorded_at' => now('UTC')->toIso8601String()];
            $preference->update(['consents' => $consents]);
        });
    }

    public function requireConsent(AiCompanyPolicy $policy, AiProviderConnection $connection, User $actor): void
    {
        $preferences = AiUserPreference::where('company_id', $policy->company_id)->where('user_id', $actor->id)->first();
        $actual = $preferences?->consents[$connection->public_id]['fingerprint'] ?? '';
        if (! is_string($actual) || ! hash_equals($this->consentFingerprint($policy, $connection), $actual)) {
            throw new AssistantUnavailable('consent');
        }
    }

    private function authorizeCredential(Company $company, User $actor, string $source, bool $requireOptIn = true): void
    {
        $this->authorizeCapability($company, $actor, $source === 'company_byok' ? 'ai.credentials.manage' : 'ai.personal_credentials.manage');
        if ($requireOptIn && $source === 'personal_byok' && ! AiCompanyPolicy::where('company_id', $company->id)->where('personal_credentials_enabled', true)->exists()) {
            throw new AssistantUnavailable('personal_credentials_disabled');
        }
    }

    private function requireOwner(Company $company, User $actor): void
    {
        if (! CompanyUser::where('company_id', $company->id)->where('user_id', $actor->id)
            ->where('status', 'active')->where('is_owner', true)->exists()) {
            throw new AuthorizationException('Only the Company Owner can enable AI or personal credentials.');
        }
    }

    private function reauthenticate(User $actor, #[SensitiveParameter] string $password): void
    {
        $fresh = User::whereKey($actor->id)->firstOrFail();
        if (! Hash::check($password, $fresh->password)) {
            throw new AuthorizationException('Fresh password confirmation is required.');
        }
    }

    /** @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function validateSettings(array $settings): array
    {
        $allowed = ['allowed_connections', 'text_connection', 'speech_connection', 'fallback', 'privacy',
            'pricing', 'limits', 'retention_days', 'allowed_personal_routes', 'global_button_enabled', 'allowed_intents'];
        if (array_diff(array_keys($settings), $allowed) !== []) {
            throw new AssistantUnavailable('policy_invalid');
        }
        $data = Validator::make($settings, [
            'global_button_enabled' => ['sometimes', 'boolean'],
            'allowed_intents' => ['sometimes', 'array', 'list', 'max:2'],
            'allowed_intents.*' => ['string', 'in:sales,purchase', 'distinct'],
            'allowed_connections' => ['present', 'array', 'max:20'], 'allowed_connections.*' => ['uuid'],
            'allowed_personal_routes' => ['sometimes', 'array', 'max:20'],
            'allowed_personal_routes.*' => ['string', 'max:255'],
            'text_connection' => ['nullable', 'uuid'], 'speech_connection' => ['nullable', 'uuid'],
            'fallback' => ['required', 'array:enabled,text_connection,speech_connection'],
            'fallback.enabled' => ['required', 'boolean'],
            'fallback.text_connection' => ['nullable', 'uuid'], 'fallback.speech_connection' => ['nullable', 'uuid'],
            'privacy' => ['required', 'array:operator,retention,policy_url'],
            'privacy.operator' => ['required', 'string', 'max:200'],
            'privacy.retention' => ['required', 'string', 'max:500'],
            'privacy.policy_url' => ['required', 'url:https', 'max:500'],
            'pricing' => ['present', 'array', 'max:20'],
            'limits' => ['required', 'array:request_micros,daily_company_micros,monthly_company_micros,daily_user_micros,monthly_user_micros,daily_company_requests,daily_user_requests'],
            'limits.*' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'retention_days' => ['required', 'integer', 'min:1', 'max:365'],
        ])->validate();
        $data['global_button_enabled'] = (bool) ($data['global_button_enabled'] ?? true);
        $data['allowed_intents'] = $data['allowed_intents'] ?? ['sales', 'purchase'];
        foreach (['request_micros', 'daily_company_micros', 'monthly_company_micros', 'daily_user_micros', 'monthly_user_micros', 'daily_company_requests', 'daily_user_requests'] as $key) {
            if (! isset($data['limits'][$key])) {
                throw new AssistantUnavailable('policy_invalid');
            }
        }
        foreach ($data['allowed_personal_routes'] ?? [] as $route) {
            $parts = explode('|', $route);
            if (count($parts) !== 4) {
                throw new AssistantUnavailable('provider_configuration');
            }
            app(ProviderCapabilityRegistry::class)->resolve(...$parts);
        }
        foreach ($data['pricing'] as $id => $price) {
            if (! Str::isUuid((string) $id)) {
                $parts = explode('|', (string) $id);
                if (count($parts) !== 4) {
                    throw new AssistantUnavailable('policy_invalid');
                }
                app(ProviderCapabilityRegistry::class)->resolve(...$parts);
            }
            if (! is_array($price)
                || array_diff(array_keys($price), ['reference', 'checked_at', 'input_micros_per_million', 'output_micros_per_million', 'audio_micros_per_minute']) !== []) {
                throw new AssistantUnavailable('policy_invalid');
            }
            Validator::make($price, [
                'reference' => ['required', 'url:https', 'max:255'], 'checked_at' => ['required', 'date_format:Y-m-d'],
                'input_micros_per_million' => ['required', 'integer', 'min:0', 'max:1000000000'],
                'output_micros_per_million' => ['required', 'integer', 'min:0', 'max:1000000000'],
                'audio_micros_per_minute' => ['required', 'integer', 'min:0', 'max:1000000000'],
            ])->validate();
        }

        return $data;
    }
}
