<?php

declare(strict_types=1);

namespace Tests\Feature\Mobile;

use App\Models\Company;
use App\Models\MobileAccessToken;
use App\Models\MobileDevice;
use App\Models\MobileEventReceipt;
use App\Models\User;
use App\Services\Tenancy\CompanyRoleService;
use App\Support\Mobile\Receipt\DenyFinancialExecutionAdapter;
use App\Support\Mobile\Receipt\Exceptions\FinancialExecutionDeniedException;
use App\Support\Mobile\Receipt\Exceptions\MobileEventAuthenticationException;
use App\Support\Mobile\Receipt\Exceptions\MobileEventDuplicatePayloadException;
use App\Support\Mobile\Receipt\Exceptions\MobileEventForeignScopeException;
use App\Support\Mobile\Receipt\FinancialExecutionAdapterInterface;
use App\Support\Mobile\Receipt\MobileEventReceiptManager;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\Support\DisposableMariaDbSchema;
use Tests\TestCase;

final class MobileEventReceiptIdentityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $companyA;

    private Company $companyB;

    private MobileDevice $device;

    private MobileAccessToken $token;

    private string $rawToken;

    private MobileEventReceiptManager $manager;

    protected function beforeRefreshingDatabase(): void
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException('MobileEventReceiptIdentityTest refused: APP_ENV is not testing.');
        }

        DisposableMariaDbSchema::testSettings();
        DisposableMariaDbSchema::assertPrimarySchema((string) config('database.connections.mysql.database'));

        $port = (int) config('database.connections.mysql.port');
        if ($port === 3353) {
            throw new RuntimeException('Forbidden: MobileEventReceiptIdentityTest cannot run on persistent 3353 database.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'email' => 'staff_receipt@example.com',
            'password' => Hash::make('ReceiptSecured!Pass123'),
        ]);

        $this->companyA = Company::factory()->create([
            'name_ar' => 'شركة المقبوضات أ',
            'name_en' => 'Receipt Co A',
            'base_currency_code' => 'ILS',
            'status' => 'active',
        ]);

        $this->companyB = Company::factory()->create([
            'name_ar' => 'شركة المقبوضات ب',
            'name_en' => 'Receipt Co B',
            'base_currency_code' => 'USD',
            'status' => 'active',
        ]);

        app(CompanyRoleService::class)->ensurePermissionsExist();
        app(CompanyRoleService::class)->seedCompanyRoles($this->companyA);
        app(CompanyRoleService::class)->seedCompanyRoles($this->companyB);

        $this->user->companies()->attach($this->companyA->id, ['status' => 'active', 'is_owner' => false]);
        $this->user->companies()->attach($this->companyB->id, ['status' => 'active', 'is_owner' => false]);

        setPermissionsTeamId($this->companyA->id);
        $this->user->assignRole('Cashier');

        $deviceId = (string) Str::uuid();
        $this->device = MobileDevice::create([
            'device_id' => $deviceId,
            'device_name' => 'Test Mobile Register',
            'user_id' => $this->user->id,
        ]);

        $this->rawToken = 'st_m_'.bin2hex(random_bytes(32));
        $this->token = MobileAccessToken::create([
            'token_hash' => hash('sha256', $this->rawToken),
            'device_id' => $deviceId,
            'user_id' => $this->user->id,
            'company_id' => $this->companyA->id,
            'password_epoch' => 'epoch-'.substr(hash('sha256', (string) $this->user->password), 0, 16),
            'expires_at' => now()->addDays(7),
        ]);

        $this->manager = new MobileEventReceiptManager;
    }

    private function createTestExecutor(array $terminalResult = []): FinancialExecutionAdapterInterface
    {
        return new class($terminalResult) implements FinancialExecutionAdapterInterface
        {
            public function __construct(private array $result) {}

            public function execute(
                Company $company,
                User $actor,
                MobileDevice $device,
                array $payload,
                string $idempotencySuffix
            ): array {
                return [
                    'sales_invoice_id' => $this->result['sales_invoice_id'] ?? null,
                    'sales_invoice_public_id' => $this->result['sales_invoice_public_id'] ?? 'INV_01HXYZTEST000000000001',
                    'customer_payment_id' => $this->result['customer_payment_id'] ?? null,
                    'customer_payment_public_id' => $this->result['customer_payment_public_id'] ?? 'PAY_01HXYZTEST000000000001',
                    'command_receipt_id' => null,
                    'terminal_canonical_result' => $this->result['terminal_canonical_result'] ?? [
                        'status' => 'completed',
                        'grand_total' => '150.000000',
                        'paid_amount' => '150.000000',
                    ],
                ];
            }
        };
    }

    public function test_production_default_denies_financial_execution(): void
    {
        $payload = json_encode(['amount' => '100.000000', 'client_id' => 'cli-1'], JSON_THROW_ON_ERROR);

        $this->expectException(FinancialExecutionDeniedException::class);
        $this->expectExceptionMessage('Financial authority is unresolved on Mobile V2');

        // Without passing custom executor, manager uses default DenyFinancialExecutionAdapter
        $this->manager->recordOrReplay(
            companyId: (int) $this->companyA->id,
            clientEventId: 'evt-deny-001',
            tokenHash: $this->token->token_hash,
            eventVersion: 1,
            representation: 'v1_canonical',
            canonicalBytes: $payload,
            executor: new DenyFinancialExecutionAdapter
        );

        $this->assertDatabaseCount('mobile_event_receipts', 0);
    }

    public function test_successful_receipt_creation_stores_all_provenance_and_terminal_result(): void
    {
        $payload = json_encode([
            'grand_total' => '250.000000',
            'lines' => [['product_id' => 1, 'quantity' => '2.000000', 'unit_price' => '125.000000']],
        ], JSON_THROW_ON_ERROR);

        $res = $this->manager->recordOrReplay(
            companyId: (int) $this->companyA->id,
            clientEventId: 'evt-create-001',
            tokenHash: $this->token->token_hash,
            eventVersion: 2,
            representation: 'v2_intent',
            canonicalBytes: $payload,
            executor: $this->createTestExecutor([
                'sales_invoice_id' => null,
                'sales_invoice_public_id' => 'INV_505PUBLICID',
                'customer_payment_id' => null,
                'customer_payment_public_id' => 'PAY_606PUBLICID',
            ])
        );

        $this->assertSame('created', $res['outcome']);
        $this->assertSame('completed', $res['status']);
        $this->assertNull($res['sales_invoice_id']);
        $this->assertSame('INV_505PUBLICID', $res['sales_invoice_public_id']);
        $this->assertNull($res['customer_payment_id']);
        $this->assertSame('PAY_606PUBLICID', $res['customer_payment_public_id']);

        $this->assertDatabaseCount('mobile_event_receipts', 1);
        $row = MobileEventReceipt::firstOrFail();
        $this->assertSame((int) $this->companyA->id, (int) $row->company_id);
        $this->assertSame('evt-create-001', $row->client_event_id);
        $this->assertSame((int) $this->user->id, (int) $row->actor_id);
        $this->assertSame($this->device->device_id, $row->device_id);
        $this->assertSame(2, $row->event_version);
        $this->assertSame('v2_intent', $row->representation);
        $this->assertSame(hash('sha256', $payload), $row->payload_digest);
        $this->assertSame($payload, $row->original_canonical_bytes);
    }

    public function test_idempotent_loss_ack_returns_original_terminal_result_without_regeneration(): void
    {
        $payload = json_encode(['grand_total' => '100.000000'], JSON_THROW_ON_ERROR);

        $res1 = $this->manager->recordOrReplay(
            companyId: (int) $this->companyA->id,
            clientEventId: 'evt-replay-001',
            tokenHash: $this->token->token_hash,
            eventVersion: 1,
            representation: 'v1_canonical',
            canonicalBytes: $payload,
            executor: $this->createTestExecutor([
                'sales_invoice_id' => null,
                'sales_invoice_public_id' => 'INV_111ORIGINAL',
            ])
        );

        $this->assertSame('created', $res1['outcome']);
        $this->assertDatabaseCount('mobile_event_receipts', 1);

        // Second call: exact same payload and event ID
        $res2 = $this->manager->recordOrReplay(
            companyId: (int) $this->companyA->id,
            clientEventId: 'evt-replay-001',
            tokenHash: $this->token->token_hash,
            eventVersion: 1,
            representation: 'v1_canonical',
            canonicalBytes: $payload,
            executor: $this->createTestExecutor([
                'sales_invoice_id' => null,
                'sales_invoice_public_id' => 'INV_999DIFFERENT', // would differ if executed
            ])
        );

        $this->assertSame('replay', $res2['outcome']);
        $this->assertNull($res2['sales_invoice_id']);
        $this->assertSame('INV_111ORIGINAL', $res2['sales_invoice_public_id']);
        $this->assertDatabaseCount('mobile_event_receipts', 1);
    }

    public function test_altered_payload_for_same_client_event_id_throws_409_conflict(): void
    {
        $payloadOriginal = json_encode(['grand_total' => '100.000000'], JSON_THROW_ON_ERROR);
        $payloadAltered = json_encode(['grand_total' => '200.000000'], JSON_THROW_ON_ERROR);

        $this->manager->recordOrReplay(
            companyId: (int) $this->companyA->id,
            clientEventId: 'evt-conflict-001',
            tokenHash: $this->token->token_hash,
            eventVersion: 1,
            representation: 'v1_canonical',
            canonicalBytes: $payloadOriginal,
            executor: $this->createTestExecutor()
        );

        $this->expectException(MobileEventDuplicatePayloadException::class);
        $this->expectExceptionMessage('Event retry payload differs from original reviewed request.');

        $this->manager->recordOrReplay(
            companyId: (int) $this->companyA->id,
            clientEventId: 'evt-conflict-001',
            tokenHash: $this->token->token_hash,
            eventVersion: 1,
            representation: 'v1_canonical',
            canonicalBytes: $payloadAltered,
            executor: $this->createTestExecutor()
        );
    }

    public function test_scope_checked_first_before_digest_comparison_without_digest_oracle(): void
    {
        $payloadOriginal = json_encode(['grand_total' => '100.000000'], JSON_THROW_ON_ERROR);
        $payloadAltered = json_encode(['grand_total' => '999.000000'], JSON_THROW_ON_ERROR);

        // Created by User 1, Device 1
        $this->manager->recordOrReplay(
            companyId: (int) $this->companyA->id,
            clientEventId: 'evt-foreign-001',
            tokenHash: $this->token->token_hash,
            eventVersion: 1,
            representation: 'v1_canonical',
            canonicalBytes: $payloadOriginal,
            executor: $this->createTestExecutor()
        );

        // Colleague in same company
        $colleague = User::factory()->create([
            'password' => Hash::make('ColleaguePass123'),
        ]);
        $this->companyA->users()->attach($colleague->id, ['status' => 'active', 'is_owner' => false]);
        setPermissionsTeamId($this->companyA->id);
        $colleague->assignRole('Cashier');

        $foreignDevice = MobileDevice::create([
            'device_id' => (string) Str::uuid(),
            'device_name' => 'Colleague Device',
            'user_id' => $colleague->id,
        ]);
        $foreignTokenRaw = 'st_m_foreign_'.bin2hex(random_bytes(16));
        $foreignToken = MobileAccessToken::create([
            'token_hash' => hash('sha256', $foreignTokenRaw),
            'device_id' => $foreignDevice->device_id,
            'user_id' => $colleague->id,
            'company_id' => $this->companyA->id,
            'password_epoch' => 'epoch-'.substr(hash('sha256', (string) $colleague->password), 0, 16),
            'expires_at' => now()->addDays(7),
        ]);

        // Attempt replay with colleague: even with altered payload, it MUST fail with ForeignScopeException, NOT 409
        try {
            $this->manager->recordOrReplay(
                companyId: (int) $this->companyA->id,
                clientEventId: 'evt-foreign-001',
                tokenHash: $foreignToken->token_hash,
                eventVersion: 1,
                representation: 'v1_canonical',
                canonicalBytes: $payloadAltered,
                executor: $this->createTestExecutor()
            );
            $this->fail('Expected MobileEventForeignScopeException');
        } catch (MobileEventForeignScopeException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('Event scope does not match authorized actor and device context.', $e->getMessage());
        }

        // Also test lookup(): foreign scope fails
        $this->expectException(MobileEventForeignScopeException::class);
        $this->manager->lookup((int) $this->companyA->id, 'evt-foreign-001', (int) $colleague->id, $foreignDevice->device_id);
    }

    public function test_same_client_event_id_across_different_companies_is_independent(): void
    {
        $payload = json_encode(['grand_total' => '100.000000'], JSON_THROW_ON_ERROR);

        // Company A
        $resA = $this->manager->recordOrReplay(
            companyId: (int) $this->companyA->id,
            clientEventId: 'evt-shared-id-001',
            tokenHash: $this->token->token_hash,
            eventVersion: 1,
            representation: 'v1_canonical',
            canonicalBytes: $payload,
            executor: $this->createTestExecutor(['sales_invoice_public_id' => 'INV_A'])
        );

        // Company B with same clientEventId
        $resB = $this->manager->recordOrReplay(
            companyId: (int) $this->companyB->id,
            clientEventId: 'evt-shared-id-001',
            tokenHash: $this->token->token_hash,
            eventVersion: 1,
            representation: 'v1_canonical',
            canonicalBytes: $payload,
            executor: $this->createTestExecutor(['sales_invoice_public_id' => 'INV_B'])
        );

        $this->assertSame('created', $resA['outcome']);
        $this->assertSame('created', $resB['outcome']);
        $this->assertDatabaseCount('mobile_event_receipts', 2);
    }

    public function test_cross_version_collision_converges_when_payload_digest_matches(): void
    {
        $payload = json_encode(['grand_total' => '100.000000'], JSON_THROW_ON_ERROR);

        // Created under v1
        $res1 = $this->manager->recordOrReplay(
            companyId: (int) $this->companyA->id,
            clientEventId: 'evt-cross-version-001',
            tokenHash: $this->token->token_hash,
            eventVersion: 1,
            representation: 'v1_canonical',
            canonicalBytes: $payload,
            executor: $this->createTestExecutor(['sales_invoice_public_id' => 'INV_V1'])
        );

        $this->assertSame('created', $res1['outcome']);

        // Retried under v2 with same payload
        $res2 = $this->manager->recordOrReplay(
            companyId: (int) $this->companyA->id,
            clientEventId: 'evt-cross-version-001',
            tokenHash: $this->token->token_hash,
            eventVersion: 2,
            representation: 'v2_intent',
            canonicalBytes: $payload,
            executor: $this->createTestExecutor(['sales_invoice_public_id' => 'INV_V2'])
        );

        $this->assertSame('replay', $res2['outcome']);
        $this->assertSame('INV_V1', $res2['sales_invoice_public_id']);
    }

    public function test_revoked_token_fails_linearized_check_inside_transaction(): void
    {
        $payload = json_encode(['grand_total' => '100.000000'], JSON_THROW_ON_ERROR);
        $this->token->update(['revoked_at' => now()]);

        $this->expectException(MobileEventAuthenticationException::class);
        $this->expectExceptionMessage('The Bearer token has been revoked or expired.');

        $this->manager->recordOrReplay(
            companyId: (int) $this->companyA->id,
            clientEventId: 'evt-auth-revoked',
            tokenHash: $this->token->token_hash,
            eventVersion: 1,
            representation: 'v1_canonical',
            canonicalBytes: $payload,
            executor: $this->createTestExecutor()
        );
    }

    public function test_revoked_device_fails_linearized_check_inside_transaction(): void
    {
        $payload = json_encode(['grand_total' => '100.000000'], JSON_THROW_ON_ERROR);
        $this->device->update(['revoked_at' => now(), 'revoked_reason' => 'Lost device']);

        $this->expectException(MobileEventAuthenticationException::class);
        $this->expectExceptionMessage('Device has been revoked or does not match token owner.');

        $this->manager->recordOrReplay(
            companyId: (int) $this->companyA->id,
            clientEventId: 'evt-dev-revoked',
            tokenHash: $this->token->token_hash,
            eventVersion: 1,
            representation: 'v1_canonical',
            canonicalBytes: $payload,
            executor: $this->createTestExecutor()
        );
    }

    public function test_password_epoch_change_fails_linearized_check_inside_transaction(): void
    {
        $payload = json_encode(['grand_total' => '100.000000'], JSON_THROW_ON_ERROR);
        // User changes password
        $this->user->update(['password' => Hash::make('NewRevisedPassword123!')]);

        $this->expectException(MobileEventAuthenticationException::class);
        $this->expectExceptionMessage('User credentials have changed since token establishment.');

        $this->manager->recordOrReplay(
            companyId: (int) $this->companyA->id,
            clientEventId: 'evt-pwd-changed',
            tokenHash: $this->token->token_hash,
            eventVersion: 1,
            representation: 'v1_canonical',
            canonicalBytes: $payload,
            executor: $this->createTestExecutor()
        );

        $this->token->refresh();
        $this->assertNotNull($this->token->revoked_at);
    }

    public function test_removed_company_membership_fails_scope_inside_transaction(): void
    {
        $payload = json_encode(['grand_total' => '100.000000'], JSON_THROW_ON_ERROR);
        DB::table('company_user')
            ->where('company_id', $this->companyA->id)
            ->where('user_id', $this->user->id)
            ->update(['status' => 'inactive']);

        $this->expectException(MobileEventForeignScopeException::class);
        $this->expectExceptionMessage('User does not possess active authorized membership');

        $this->manager->recordOrReplay(
            companyId: (int) $this->companyA->id,
            clientEventId: 'evt-inactive-member',
            tokenHash: $this->token->token_hash,
            eventVersion: 1,
            representation: 'v1_canonical',
            canonicalBytes: $payload,
            executor: $this->createTestExecutor()
        );
    }

    public function test_missing_permission_fails_authorization_inside_transaction(): void
    {
        $payload = json_encode(['grand_total' => '100.000000'], JSON_THROW_ON_ERROR);

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Missing required financial permission [sales.invoice.post]');

        $this->manager->recordOrReplay(
            companyId: (int) $this->companyA->id,
            clientEventId: 'evt-missing-perm',
            tokenHash: $this->token->token_hash,
            eventVersion: 1,
            representation: 'v1_canonical',
            canonicalBytes: $payload,
            executor: $this->createTestExecutor(),
            requiredPermissions: ['sales.invoice.post']
        );
    }

    public function test_validate_economic_payload_rejects_duplicate_keys_and_preserves_lexemes(): void
    {
        $validJson = '{"amount":"1.000000","rate":"3.5000000000"}';
        $parsed = $this->manager->validateEconomicPayload($validJson);
        $this->assertSame('1.000000', $parsed['amount']);
        $this->assertSame('3.5000000000', $parsed['rate']);

        // Duplicate JSON keys rejected
        $duplicateKeyJson = '{"amount":"1.000000","amount":"2.000000"}';
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate JSON key detected');
        $this->manager->validateEconomicPayload($duplicateKeyJson);
    }
}
