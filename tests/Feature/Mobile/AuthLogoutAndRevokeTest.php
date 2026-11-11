<?php

declare(strict_types=1);

namespace Tests\Feature\Mobile;

use App\Models\Company;
use App\Models\MobileAccessToken;
use App\Models\MobileDevice;
use App\Models\User;
use App\Services\Tenancy\CompanyRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AuthLogoutAndRevokeTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private User $admin;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mobile.enabled' => true]);

        $this->company = Company::factory()->create([
            'base_currency_code' => 'ILS',
            'status' => 'active',
        ]);

        app(CompanyRoleService::class)->ensurePermissionsExist();
        app(CompanyRoleService::class)->seedCompanyRoles($this->company);
        setPermissionsTeamId($this->company->id);

        $this->cashier = User::factory()->create([
            'password' => Hash::make('password123'),
        ]);
        $this->cashier->companies()->attach($this->company->id, ['status' => 'active', 'is_owner' => false]);
        $this->cashier->assignRole('Cashier');

        $this->admin = User::factory()->create([
            'password' => Hash::make('password123'),
        ]);
        $this->admin->companies()->attach($this->company->id, ['status' => 'active', 'is_owner' => true]);
        $this->admin->assignRole('Administrator');
    }

    private function issueToken(User $user, string $deviceId): string
    {
        $raw = 'st_m_'.bin2hex(random_bytes(32));
        MobileDevice::firstOrCreate(['device_id' => $deviceId], ['device_name' => 'Tablet', 'user_id' => $user->id]);

        MobileAccessToken::create([
            'token_hash' => hash('sha256', $raw),
            'device_id' => $deviceId,
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'password_epoch' => 'epoch-'.substr(hash('sha256', (string) $user->password), 0, 16),
            'expires_at' => now()->addDays(7),
        ]);

        return $raw;
    }

    public function test_logout_revokes_token(): void
    {
        $deviceId = (string) Str::uuid();
        $token = $this->issueToken($this->cashier, $deviceId);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/mobile/v1/auth/logout', [
                'device_id' => $deviceId,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'logged_out');

        // Subsequent call with revoked token returns 401
        $subsequent = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/mobile/v1/auth/logout', [
                'device_id' => $deviceId,
            ]);
        $subsequent->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_device_revoke_own_device_succeeds(): void
    {
        $deviceId = (string) Str::uuid();
        $token = $this->issueToken($this->cashier, $deviceId);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/mobile/v1/devices/{$deviceId}/revoke", [
                'reason' => 'Lost tablet reported by cashier',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('device_id', $deviceId)
            ->assertJsonPath('status', 'revoked');

        $device = MobileDevice::where('device_id', $deviceId)->first();
        $this->assertTrue($device->isRevoked());
        $this->assertSame('Lost tablet reported by cashier', $device->revoked_reason);

        // All tokens for this device are revoked
        $tokenRecord = MobileAccessToken::where('device_id', $deviceId)->first();
        $this->assertNotNull($tokenRecord->revoked_at);
    }

    public function test_unprivileged_cashier_cannot_revoke_another_device(): void
    {
        $cashierDeviceId = (string) Str::uuid();
        $otherDeviceId = (string) Str::uuid();

        $token = $this->issueToken($this->cashier, $cashierDeviceId);
        MobileDevice::create(['device_id' => $otherDeviceId, 'device_name' => 'Other Tablet']);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/mobile/v1/devices/{$otherDeviceId}/revoke", [
                'reason' => 'Cashier attempting unauthorized device revocation',
            ]);

        $response->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_admin_cannot_revoke_foreign_device(): void
    {
        $adminDeviceId = (string) Str::uuid();
        $cashierDeviceId = (string) Str::uuid();

        $adminToken = $this->issueToken($this->admin, $adminDeviceId);
        $this->issueToken($this->cashier, $cashierDeviceId);

        $response = $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->postJson("/api/mobile/v1/devices/{$cashierDeviceId}/revoke", [
                'reason' => 'Administrator terminated compromised device',
            ]);

        $response->assertForbidden();
        $this->assertNull(MobileDevice::where('device_id', $cashierDeviceId)->firstOrFail()->revoked_at);
    }
}
