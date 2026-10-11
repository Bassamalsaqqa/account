<?php

declare(strict_types=1);

namespace Tests\Feature\Phase12;

use App\Actions\Company\UpdateRolePermissionsAction;
use App\Application\AI\AiGovernance;
use App\Application\AI\AssistantUnavailable;
use App\Models\AiProviderConnection;
use App\Models\CompanyUser;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\Support\Phase12\AssistantFixtures;
use Tests\TestCase;

class AiGovernanceTest extends TestCase
{
    use AssistantFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assistantFixtures();
        config(['ai_assistant.provider' => 'direct']);
        $this->owner->update(['password' => Hash::make('synthetic-confirm-only')]);
        Http::preventStrayRequests();
    }

    public function test_owner_catalog_does_not_automatically_grant_administrators_ai_capabilities(): void
    {
        $admin = Role::where('company_id', $this->company->id)->where('name', 'Administrator')->firstOrFail();
        $this->assertFalse($admin->hasPermissionTo('ai.settings.manage'));
        $this->assertFalse($admin->hasPermissionTo('ai.credentials.manage'));
        $this->assertFalse($admin->hasPermissionTo('ai.assistant.use'));
        $this->assertTrue($this->owner->hasPermissionTo('ai.credentials.manage'));
        $this->assertTrue($this->owner->hasPermissionTo('ai.purchases.use'));
    }

    public function test_credentials_are_encrypted_write_only_unverified_and_never_dispatched_by_validation(): void
    {
        $governance = app(AiGovernance::class);
        $connection = $governance->provision($this->company, $this->owner, $this->selection(), 'synthetic-provider-secret-no-real-account', 'synthetic-confirm-only');
        $stored = DB::table('ai_provider_connections')->where('id', $connection->id)->value('secret_ciphertext');
        $this->assertNotSame('synthetic-provider-secret-no-real-account', $stored);
        $this->assertStringNotContainsString('synthetic-provider-secret', $connection->toJson());
        $this->assertArrayNotHasKey('secret_ciphertext', $connection->toArray());
        $this->assertSame('configured_unverified', $governance->validateConnection($this->company, $this->owner, $connection->public_id, 'synthetic-confirm-only'));
        $this->assertStringNotContainsString('synthetic-provider-secret', json_encode($governance->visibleConnections($this->company, $this->owner), JSON_THROW_ON_ERROR));
        Http::assertNothingSent();
    }

    public function test_wrong_password_cannot_create_or_revoke_credentials(): void
    {
        $governance = app(AiGovernance::class);
        try {
            $governance->provision($this->company, $this->owner, $this->selection(), 'synthetic-provider-secret-only', 'wrong');
            $this->fail('Fresh password confirmation required.');
        } catch (AuthorizationException) {
            $this->assertSame(0, AiProviderConnection::count());
        }
        $connection = $governance->provision($this->company, $this->owner, $this->selection(), 'synthetic-provider-secret-only', 'synthetic-confirm-only');
        try {
            $governance->revoke($this->company, $this->owner, $connection->public_id, 'wrong');
            $this->fail('Fresh password required for revocation.');
        } catch (AuthorizationException) {
            $this->assertNull($connection->fresh()->revoked_at);
        }
        $governance->revoke($this->company, $this->owner, $connection->public_id, 'synthetic-confirm-only');
        $this->assertSame('', $connection->fresh()->secret_ciphertext);
        $this->assertSame(2, $connection->fresh()->revision);
        Http::assertNothingSent();
    }

    public function test_personal_credentials_require_owner_opt_in_and_remain_actor_scoped(): void
    {
        $governance = app(AiGovernance::class);
        $selection = [...$this->selection(), 'credential_source' => 'personal_byok'];
        try {
            $governance->provision($this->company, $this->owner, $selection, 'synthetic-private-secret-only', 'synthetic-confirm-only');
            $this->fail('Personal credentials require company opt-in.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('personal_credentials_disabled', $e->reason);
        }
        $policy = $governance->updatePolicy($this->company, $this->owner, $this->policy(true));
        $connection = $governance->provision($this->company, $this->owner, $selection, 'synthetic-private-secret-only', 'synthetic-confirm-only');
        $this->assertTrue($policy->personal_credentials_enabled);
        $this->assertSame($this->owner->id, $connection->owner_user_id);
        $this->assertSame('personal_byok', $connection->credential_source);
        Http::assertNothingSent();
    }

    public function test_generic_admin_cannot_self_delegate_protected_ai_permissions(): void
    {
        $adminRole = Role::where('company_id', $this->company->id)->where('name', 'Administrator')->firstOrFail();
        $administrator = User::factory()->create();
        CompanyUser::create(['company_id' => $this->company->id, 'user_id' => $administrator->id, 'status' => 'active', 'is_owner' => false]);
        $administrator->assignRole($adminRole);
        $this->actingAs($administrator);
        foreach (['ai.settings.manage', 'ai.credentials.manage', 'ai.personal_credentials.manage'] as $permission) {
            try {
                app(UpdateRolePermissionsAction::class)->execute($this->company, $adminRole, $permission, true, $administrator);
                $this->fail('Generic role administration must not elevate AI governance.');
            } catch (AuthorizationException) {
                $this->assertFalse($adminRole->fresh()->hasPermissionTo($permission));
            }
        }
        Http::assertNothingSent();
    }

    public function test_dispatch_requires_current_consent_and_rotation_invalidates_it(): void
    {
        $governance = app(AiGovernance::class);
        $connection = $governance->provision($this->company, $this->owner, $this->selection(), 'synthetic-provider-secret-only', 'synthetic-confirm-only');
        $values = $this->policy(false);
        $values['settings']['allowed_connections'] = [$connection->public_id];
        $policy = $governance->updatePolicy($this->company, $this->owner, $values);
        try {
            $governance->dispatchConnection($this->company, $this->owner, $connection->public_id, 'sales', 'text_structured');
            $this->fail('Explicit privacy consent required.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('consent', $e->reason);
        }
        $governance->recordConsent($this->company, $this->owner, $connection->public_id, $governance->consentFingerprint($policy, $connection));
        $grant = $governance->dispatchConnection($this->company, $this->owner, $connection->public_id, 'sales', 'text_structured', 1);
        $this->assertSame(1, $grant['connection']->revision);
        $governance->provision($this->company, $this->owner, $this->selection(), 'synthetic-replaced-secret-only', 'synthetic-confirm-only', $connection->public_id);
        try {
            $governance->dispatchConnection($this->company, $this->owner, $connection->public_id, 'sales', 'text_structured', 1);
            $this->fail('Stale credential must refuse dispatch.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('credential_revoked', $e->reason);
        }
        Http::assertNothingSent();
    }

    private function selection(): array
    {
        return ['provider' => 'deepseek', 'model' => 'deepseek-flash', 'region' => 'global',
            'capability' => 'text_structured', 'credential_source' => 'company_byok'];
    }

    private function policy(bool $personal): array
    {
        return ['enabled' => true, 'personal_credentials_enabled' => $personal, 'follow_up_enabled' => true,
            'workflow_mode' => 'seamless', 'speech_method' => 'cloud', 'settings' => [
                'allowed_connections' => [], 'fallback' => ['enabled' => false],
                'privacy' => ['operator' => 'Synthetic operator', 'retention' => 'Synthetic policy; no real dispatch',
                    'policy_url' => 'https://example.test/privacy'],
                'pricing' => [], 'limits' => [
                    'request_micros' => 1000, 'daily_company_micros' => 10000, 'monthly_company_micros' => 100000,
                    'daily_user_micros' => 5000, 'monthly_user_micros' => 50000,
                    'daily_company_requests' => 100, 'daily_user_requests' => 25,
                ], 'retention_days' => 30,
            ]];
    }
}
