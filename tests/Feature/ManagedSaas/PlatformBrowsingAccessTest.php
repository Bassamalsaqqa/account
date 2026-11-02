<?php

declare(strict_types=1);

namespace Tests\Feature\ManagedSaas;

use App\Application\Platform\PlatformAccess;
use App\Application\Platform\PlatformSessionProof;
use App\Models\PlatformOperatorGrant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PlatformBrowsingAccessTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{User, PlatformOperatorGrant} */
    private function operator(): array
    {
        $user = User::factory()->create();
        $grant = new PlatformOperatorGrant;
        $grant->forceFill(['user_id' => $user->id, 'active' => true, 'permissions' => PlatformAccess::PERMISSIONS, 'revision' => 1])->save();

        return [$user, $grant];
    }

    public function test_browsing_in_both_languages_needs_no_extra_password_or_session_proof(): void
    {
        [$user] = $this->operator();
        $this->actingAs($user);
        foreach (['ar', 'en'] as $locale) {
            foreach (['/platform', '/platform/audit', '/platform/companies/create'] as $url) {
                $this->withSession(['locale' => $locale])->get($url)->assertOk();
            }
        }
        $this->assertFalse(session()->has(PlatformSessionProof::KEY));
        $this->assertDatabaseCount('companies', 0);
    }

    public function test_browsing_does_not_authorize_a_sensitive_post(): void
    {
        [$user] = $this->operator();
        $this->actingAs($user)->get('/platform')->assertOk();
        $this->postJson('/platform/companies', [])->assertStatus(423);
        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseCount('platform_provisioning_requests', 0);
        $this->assertFalse(session()->has(PlatformSessionProof::KEY));
    }

    public function test_expired_proof_does_not_interrupt_reading_but_still_blocks_writes(): void
    {
        [$user] = $this->operator();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        $this->travel(61)->minutes();
        $this->get('/platform')->assertOk();
        $this->postJson('/platform/companies', [])->assertStatus(423);
        $this->assertDatabaseCount('companies', 0);
    }

    public function test_revocation_and_missing_permissions_still_deny_read_access(): void
    {
        [$user, $grant] = $this->operator();
        $this->actingAs($user)->get('/platform')->assertOk();
        $grant->forceFill(['active' => false])->save();
        $this->get('/platform')->assertForbidden();
        $grant->forceFill(['active' => true, 'permissions' => ['audit.read']])->save();
        $this->get('/platform')->assertForbidden();
        $this->get('/platform/audit')->assertOk();
    }

    public function test_authenticated_tenant_identity_without_operator_grant_is_denied(): void
    {
        $this->actingAs(User::factory()->create())->get('/platform')->assertForbidden();
        $this->assertDatabaseCount('platform_operator_grants', 0);
    }
}
