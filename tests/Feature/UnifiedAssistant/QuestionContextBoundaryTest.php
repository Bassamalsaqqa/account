<?php

declare(strict_types=1);

namespace Tests\Feature\UnifiedAssistant;

use App\Actions\Company\CreateCompanyAction;
use App\Application\AI\AiGovernance;
use App\Application\AI\AssistantQuestionContext;
use App\Application\AI\AssistantReportCatalog;
use App\Application\AI\AssistantUnavailable;
use App\Application\Reporting\Exceptions\InvalidReportFilterException;
use App\Models\AiCompanyPolicy;
use App\Models\CompanyUser;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\Support\SalesCorrectionFixtures;
use Tests\TestCase;
use Throwable;

class QuestionContextBoundaryTest extends TestCase
{
    use RefreshDatabase;
    use SalesCorrectionFixtures;

    private User $reader;

    private Role $role;

    private AiCompanyPolicy $policy;

    private AssistantQuestionContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->salesFixtures();
        config([
            'ai_assistant.enabled' => true,
            'ai_assistant.questions.enabled' => true,
            'ai_assistant.provider_approved' => false,
            'ai_assistant.provider' => 'none',
        ]);
        Http::preventStrayRequests();

        $this->policy = app(AiGovernance::class)->updatePolicy($this->company, $this->owner, [
            'enabled' => true,
            'personal_credentials_enabled' => false,
            'follow_up_enabled' => true,
            'workflow_mode' => 'guided',
            'speech_method' => 'disabled',
            'settings' => [
                'allowed_connections' => [],
                'text_connection' => null,
                'speech_connection' => null,
                'fallback' => [
                    'enabled' => false,
                    'text_connection' => null,
                    'speech_connection' => null,
                ],
                'privacy' => [
                    'operator' => 'Test Operator',
                    'retention' => '30 days',
                    'policy_url' => 'https://example.com/privacy',
                ],
                'pricing' => [],
                'limits' => [
                    'request_micros' => 1000,
                    'daily_company_micros' => 100000,
                    'monthly_company_micros' => 1000000,
                    'daily_user_micros' => 100000,
                    'monthly_user_micros' => 1000000,
                    'daily_company_requests' => 100,
                    'daily_user_requests' => 50,
                ],
                'retention_days' => 30,
                'questions' => [
                    'enabled' => true,
                    'allowed_reports' => AssistantReportCatalog::KEYS,
                ],
            ],
        ]);

        $this->reader = User::factory()->create();
        CompanyUser::create([
            'company_id' => $this->company->id,
            'user_id' => $this->reader->id,
            'status' => 'active',
            'is_owner' => false,
        ]);

        $this->role = Role::create([
            'company_id' => $this->company->id,
            'name' => 'Question Context Reader',
            'guard_name' => 'web',
        ]);
        $this->role->syncPermissions([
            'ai.assistant.use',
            'reports.sales.view',
            'sales.invoice.view',
            'customers.view',
        ]);
        $this->reader->assignRole($this->role);

        $this->actingAs($this->reader);
        app(CompanyContext::class)->setCompany($this->company, $this->reader);

        $this->context = new AssistantQuestionContext;
    }

    public function test_encrypted_bounded_metadata_only_stored_with_20_min_ttl(): void
    {
        $sessionId = 'session-meta-test-1';
        $this->context->store($this->company, $this->reader, $sessionId, 'sales.summary', [
            'preset' => 'this_month',
            'currency_code' => 'ILS',
        ]);

        $key = $this->context->cacheKey($this->company, $this->reader, $sessionId);
        $ciphertext = Cache::get($key);

        $this->assertIsString($ciphertext);
        $this->assertNotEmpty($ciphertext);
        $this->assertStringNotContainsString('sales.summary', $ciphertext);

        $rawJson = Crypt::decryptString($ciphertext);
        $this->assertLessThanOrEqual(AssistantQuestionContext::MAX_PAYLOAD_BYTES, strlen($rawJson));

        $payload = json_decode($rawJson, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);

        $expectedKeys = ['company_id', 'expires_at', 'filters', 'policy_revision', 'report_key', 'session_hash', 'user_id'];
        $actualKeys = array_keys($payload);
        sort($actualKeys);
        $this->assertSame($expectedKeys, $actualKeys);

        $this->assertSame((int) $this->company->id, $payload['company_id']);
        $this->assertSame((int) $this->reader->id, $payload['user_id']);
        $this->assertSame(hash('sha256', $sessionId), $payload['session_hash']);
        $this->assertSame((int) $this->policy->revision, $payload['policy_revision']);
        $this->assertSame('sales.summary', $payload['report_key']);
        $this->assertSame(['preset' => 'this_month', 'currency_code' => 'ILS'], $payload['filters']);
        $this->assertGreaterThan(now()->timestamp, $payload['expires_at']);
        $this->assertLessThanOrEqual(now()->addSeconds(AssistantQuestionContext::TTL_SECONDS)->timestamp, $payload['expires_at']);

        $read = $this->context->read($this->company, $this->reader, $sessionId);
        $this->assertSame([
            'report_key' => 'sales.summary',
            'filters' => ['preset' => 'this_month', 'currency_code' => 'ILS'],
        ], $read);
    }

    public function test_read_validates_matching_session_company_and_actor(): void
    {
        $sessionId = 'session-match-test';
        $this->context->store($this->company, $this->reader, $sessionId, 'sales.summary', [
            'preset' => 'this_month',
        ]);

        $this->assertNotNull($this->context->read($this->company, $this->reader, $sessionId));

        // Wrong session returns null and does not clear the valid session
        $this->assertNull($this->context->read($this->company, $this->reader, 'wrong-session-id'));
        $this->assertNull($this->context->read($this->company, $this->reader, ''));
        $this->assertNotNull($this->context->read($this->company, $this->reader, $sessionId));

        // Different user cannot read
        $otherUser = User::factory()->create();
        $this->assertNull($this->context->read($this->company, $otherUser, $sessionId));

        // Foreign company cannot read
        app(CompanyContext::class)->clear();
        $foreign = app(CreateCompanyAction::class)->execute(User::factory()->create(), [
            'name_ar' => 'شركة أجنبية',
            'base_currency_code' => 'ILS',
        ]);
        app(CompanyContext::class)->setCompany($this->company, $this->reader);
        $this->actingAs($this->reader);

        $this->assertNull($this->context->read($foreign, $this->reader, $sessionId));
    }

    public function test_policy_revision_change_invalidates_context_and_purges_cache(): void
    {
        $sessionId = 'session-rev-change';
        $this->context->store($this->company, $this->reader, $sessionId, 'sales.summary', [
            'preset' => 'this_month',
        ]);
        $this->assertNotNull($this->context->read($this->company, $this->reader, $sessionId));

        $this->policy->increment('revision');

        $this->assertNull($this->context->read($this->company, $this->reader, $sessionId));
        $this->assertNull(Cache::get($this->context->cacheKey($this->company, $this->reader, $sessionId)));
    }

    public function test_policy_revocation_or_disable_invalidates_context_and_purges_cache(): void
    {
        $sessionId = 'session-disable-test';
        $this->context->store($this->company, $this->reader, $sessionId, 'sales.summary', [
            'preset' => 'this_month',
        ]);
        $this->assertNotNull($this->context->read($this->company, $this->reader, $sessionId));

        $this->policy->update(['enabled' => false]);

        $this->assertNull($this->context->read($this->company, $this->reader, $sessionId));
        $this->assertNull(Cache::get($this->context->cacheKey($this->company, $this->reader, $sessionId)));
    }

    public function test_questions_disabled_in_policy_invalidates_context_and_purges_cache(): void
    {
        $sessionId = 'session-q-disable';
        $this->context->store($this->company, $this->reader, $sessionId, 'sales.summary', [
            'preset' => 'this_month',
        ]);
        $this->assertNotNull($this->context->read($this->company, $this->reader, $sessionId));

        $settings = $this->policy->settings;
        $settings['questions']['enabled'] = false;
        $this->policy->update(['settings' => $settings]);

        $this->assertNull($this->context->read($this->company, $this->reader, $sessionId));
        $this->assertNull(Cache::get($this->context->cacheKey($this->company, $this->reader, $sessionId)));
    }

    public function test_revoked_membership_fails_closed_and_purges_cache(): void
    {
        $sessionId = 'session-revoked-member';
        $this->context->store($this->company, $this->reader, $sessionId, 'sales.summary', [
            'preset' => 'this_month',
        ]);
        $this->assertNotNull($this->context->read($this->company, $this->reader, $sessionId));

        CompanyUser::where('company_id', $this->company->id)
            ->where('user_id', $this->reader->id)
            ->update(['status' => 'inactive']);

        $this->assertNull($this->context->read($this->company, $this->reader, $sessionId));
        $this->assertNull(Cache::get($this->context->cacheKey($this->company, $this->reader, $sessionId)));
    }

    public function test_role_downgrade_fails_closed_and_purges_cache(): void
    {
        $sessionId = 'session-role-downgrade';
        $this->context->store($this->company, $this->reader, $sessionId, 'sales.summary', [
            'preset' => 'this_month',
        ]);
        $this->assertNotNull($this->context->read($this->company, $this->reader, $sessionId));

        // Remove sales report permissions
        $this->role->syncPermissions(['ai.assistant.use']);

        $this->assertNull($this->context->read($this->company, $this->reader, $sessionId));
        $this->assertNull(Cache::get($this->context->cacheKey($this->company, $this->reader, $sessionId)));
    }

    public function test_hostile_or_unsupported_filters_rejected_without_cache_write(): void
    {
        // 1. Hostile entity ID filter
        $key1 = $this->context->cacheKey($this->company, $this->reader, 'session-hostile-1');
        try {
            $this->context->store($this->company, $this->reader, 'session-hostile-1', 'sales.summary', [
                'preset' => 'this_month',
                'customer_id' => 1,
            ]);
            $this->fail('Hostile customer_id filter must be rejected.');
        } catch (Throwable $e) {
            $this->assertTrue(
                $e instanceof AssistantUnavailable || $e instanceof InvalidReportFilterException,
                'Unexpected exception: '.$e::class
            );
        }
        $this->assertNull(Cache::get($key1));
        $this->assertNull($this->context->read($this->company, $this->reader, 'session-hostile-1'));

        // 2. Arbitrary injection key
        $key2 = $this->context->cacheKey($this->company, $this->reader, 'session-hostile-2');
        try {
            $this->context->store($this->company, $this->reader, 'session-hostile-2', 'sales.summary', [
                'preset' => 'this_month',
                'sql' => 'SELECT * FROM users',
            ]);
            $this->fail('Hostile SQL injection filter must be rejected.');
        } catch (Throwable $e) {
            $this->assertTrue(
                $e instanceof AssistantUnavailable || $e instanceof InvalidReportFilterException,
                'Unexpected exception: '.$e::class
            );
        }
        $this->assertNull(Cache::get($key2));
        $this->assertNull($this->context->read($this->company, $this->reader, 'session-hostile-2'));

        // 3. Unconfigured company currency
        $key3 = $this->context->cacheKey($this->company, $this->reader, 'session-hostile-3');
        try {
            $this->context->store($this->company, $this->reader, 'session-hostile-3', 'sales.summary', [
                'preset' => 'this_month',
                'currency_code' => 'XYZ',
            ]);
            $this->fail('Unconfigured currency filter must be rejected.');
        } catch (Throwable $e) {
            $this->assertTrue(
                $e instanceof AssistantUnavailable || $e instanceof InvalidReportFilterException,
                'Unexpected exception: '.$e::class
            );
        }
        $this->assertNull(Cache::get($key3));
        $this->assertNull($this->context->read($this->company, $this->reader, 'session-hostile-3'));
    }

    public function test_current_report_historical_preset_rejected_no_store(): void
    {
        $key = $this->context->cacheKey($this->company, $this->reader, 'session-current-preset');
        try {
            $this->context->store($this->company, $this->reader, 'session-current-preset', 'customers.balances', [
                'preset' => 'this_month',
            ]);
            $this->fail('Historical preset on current balance report must be rejected.');
        } catch (Throwable $e) {
            $this->assertInstanceOf(AssistantUnavailable::class, $e);
            $this->assertSame('question_invalid', $e->reason);
        }
        $this->assertNull(Cache::get($key));
        $this->assertNull($this->context->read($this->company, $this->reader, 'session-current-preset'));
    }

    public function test_missing_or_invalid_session_does_not_store(): void
    {
        $this->context->store($this->company, $this->reader, '', 'sales.summary', ['preset' => 'this_month']);
        $this->assertNull($this->context->read($this->company, $this->reader, ''));

        $this->context->store($this->company, $this->reader, '   ', 'sales.summary', ['preset' => 'this_month']);
        $this->assertNull($this->context->read($this->company, $this->reader, '   '));
    }

    public function test_malformed_ciphertext_purged_on_read(): void
    {
        $sessionId = 'session-malformed-cipher';
        $key = $this->context->cacheKey($this->company, $this->reader, $sessionId);

        Cache::put($key, 'not-a-valid-ciphertext-payload', 1200);

        $this->assertNull($this->context->read($this->company, $this->reader, $sessionId));
        $this->assertNull(Cache::get($key));
    }

    public function test_corrupted_payload_keys_purged_on_read(): void
    {
        $sessionId = 'session-corrupt-keys';
        $key = $this->context->cacheKey($this->company, $this->reader, $sessionId);

        // Tampered payload missing required keys or containing foreign keys
        $tamperedPayload = [
            'company_id' => (int) $this->company->id,
            'user_id' => (int) $this->reader->id,
            'session_hash' => hash('sha256', $sessionId),
            'raw_results' => ['injected' => true],
        ];
        Cache::put($key, Crypt::encryptString(json_encode($tamperedPayload, JSON_THROW_ON_ERROR)), 1200);

        $this->assertNull($this->context->read($this->company, $this->reader, $sessionId));
        $this->assertNull(Cache::get($key));
    }

    public function test_expired_context_returns_null_and_purges_cache(): void
    {
        $sessionId = 'session-expiry-test';
        $this->context->store($this->company, $this->reader, $sessionId, 'sales.summary', [
            'preset' => 'this_month',
        ]);
        $key = $this->context->cacheKey($this->company, $this->reader, $sessionId);
        $this->assertNotNull(Cache::get($key));

        $this->travel(1201)->seconds();

        $this->assertNull($this->context->read($this->company, $this->reader, $sessionId));
        $this->assertNull(Cache::get($key));
    }

    public function test_clear_removes_context(): void
    {
        $sessionId = 'session-clear-test';
        $this->context->store($this->company, $this->reader, $sessionId, 'sales.summary', [
            'preset' => 'this_month',
        ]);
        $this->assertNotNull($this->context->read($this->company, $this->reader, $sessionId));

        $this->context->clear($this->company, $this->reader, $sessionId);

        $this->assertNull($this->context->read($this->company, $this->reader, $sessionId));
        $this->assertNull(Cache::get($this->context->cacheKey($this->company, $this->reader, $sessionId)));
    }

    public function test_governance_policy_revision_change_invalidates_context(): void
    {
        $sessionId = 'session-gov-test';
        $this->context->store($this->company, $this->reader, $sessionId, 'sales.summary', [
            'preset' => 'this_month',
        ]);
        $this->assertNotNull($this->context->read($this->company, $this->reader, $sessionId));

        $this->actingAs($this->owner);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $settings = $this->policy->settings;
        $settings['questions'] = ['enabled' => true, 'allowed_reports' => AssistantReportCatalog::KEYS];
        app(AiGovernance::class)->updatePolicy($this->company, $this->owner, [
            'enabled' => true,
            'personal_credentials_enabled' => false,
            'follow_up_enabled' => true,
            'workflow_mode' => 'guided',
            'speech_method' => 'disabled',
            'settings' => $settings,
        ]);
        $this->actingAs($this->reader);
        app(CompanyContext::class)->setCompany($this->company, $this->reader);

        $this->assertNull($this->context->read($this->company, $this->reader, $sessionId));
        $this->assertNull(Cache::get($this->context->cacheKey($this->company, $this->reader, $sessionId)));
    }
}
