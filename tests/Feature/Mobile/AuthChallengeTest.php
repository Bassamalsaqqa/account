<?php

declare(strict_types=1);

namespace Tests\Feature\Mobile;

use App\Models\MobileAuthChallenge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AuthChallengeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mobile.enabled' => true]);
        RateLimiter::clear('mobile_v1:ip_overall:127.0.0.1');
        RateLimiter::clear('mobile_challenge:127.0.0.1');
    }

    public function test_challenge_endpoint_returns_404_when_mobile_api_disabled(): void
    {
        config(['mobile.enabled' => false]);

        $response = $this->postJson('/api/mobile/v1/auth/challenge', [
            'device_id' => (string) Str::uuid(),
            'app_version' => '1.0.0',
            'platform' => 'android',
        ]);

        $response->assertStatus(404)
            ->assertHeader('Cache-Control', 'must-revalidate, no-cache, no-store, private')
            ->assertJsonPath('error.code', 'MOBILE_API_DISABLED');
    }

    public function test_challenge_generates_valid_challenge_matching_schema(): void
    {
        $deviceId = '8e2b8bc6-f80c-4034-8c88-59368d1f2e10';
        $now = Carbon::parse('2026-11-10T12:00:00Z');
        Carbon::setTestNow($now);

        $response = $this->postJson('/api/mobile/v1/auth/challenge', [
            'device_id' => $deviceId,
            'app_version' => '1.0.0-rc1',
            'platform' => 'android',
        ]);

        $response->assertStatus(200)
            ->assertHeader('Cache-Control', 'must-revalidate, no-cache, no-store, private');

        $data = $response->json();
        $this->assertArrayHasKey('challenge_id', $data);
        $this->assertArrayHasKey('nonce', $data);
        $this->assertArrayHasKey('expires_at', $data);

        // UUID format
        $this->assertMatchesRegularExpression('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $data['challenge_id']);
        // Nonce between 32 and 128 chars
        $this->assertGreaterThanOrEqual(32, strlen($data['nonce']));
        $this->assertLessThanOrEqual(128, strlen($data['nonce']));
        // Expires in 5 minutes
        $this->assertSame('2026-11-10T12:05:00Z', $data['expires_at']);

        // Assert record created in database with hashed nonce
        $record = MobileAuthChallenge::where('challenge_id', $data['challenge_id'])->first();
        $this->assertNotNull($record);
        $this->assertSame($deviceId, $record->device_id);
        $this->assertTrue($record->verifyNonce($data['nonce']));
        $this->assertFalse($record->verifyNonce('incorrect-nonce'));
    }

    public function test_challenge_validation_rejects_invalid_payloads(): void
    {
        // 1. Missing required fields
        $response = $this->postJson('/api/mobile/v1/auth/challenge', [
            'platform' => 'android',
        ]);
        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        // 2. Invalid UUID
        $response = $this->postJson('/api/mobile/v1/auth/challenge', [
            'device_id' => 'not-a-uuid',
            'app_version' => '1.0.0',
            'platform' => 'android',
        ]);
        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        // 3. Invalid platform enum
        $response = $this->postJson('/api/mobile/v1/auth/challenge', [
            'device_id' => (string) Str::uuid(),
            'app_version' => '1.0.0',
            'platform' => 'windows',
        ]);
        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        // 4. Unexpected additional properties
        $response = $this->postJson('/api/mobile/v1/auth/challenge', [
            'device_id' => (string) Str::uuid(),
            'app_version' => '1.0.0',
            'platform' => 'ios',
            'unexpected_field' => 'forbidden',
        ]);
        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_challenge_rate_limiting(): void
    {
        config(['mobile.rate_limits.challenges_per_minute' => 3]);
        $deviceId = (string) Str::uuid();

        for ($i = 0; $i < 3; $i++) {
            $resp = $this->postJson('/api/mobile/v1/auth/challenge', [
                'device_id' => $deviceId,
                'app_version' => '1.0.0',
                'platform' => 'android',
            ]);
            $resp->assertStatus(200);
        }

        // 4th request exceeds rate limit
        $resp = $this->postJson('/api/mobile/v1/auth/challenge', [
            'device_id' => $deviceId,
            'app_version' => '1.0.0',
            'platform' => 'android',
        ]);
        $resp->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('error.code', 'TOO_MANY_REQUESTS');
    }
}
