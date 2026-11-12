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
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PDOException;
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
        $this->user->givePermissionTo('sales.invoice.post');
        $this->user->givePermissionTo('sales.invoice.view');
        $this->user->givePermissionTo('money.receipt.view');

        setPermissionsTeamId($this->companyB->id);
        $this->user->assignRole('Cashier');
        $this->user->givePermissionTo('sales.invoice.post');
        $this->user->givePermissionTo('sales.invoice.view');
        $this->user->givePermissionTo('money.receipt.view');

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

            public function authorize(
                Company $company,
                User $actor,
                MobileDevice $device,
                string $operation,
                ?array $payload = null
            ): void {
                if ($operation === 'execute') {
                    if (! $actor->hasPermissionTo('sales.invoice.post')) {
                        throw new AuthorizationException('Missing required financial permission [sales.invoice.post]');
                    }
                } elseif (in_array($operation, ['replay', 'lookup'], true)) {
                    if (! $actor->hasPermissionTo('sales.invoice.view')) {
                        throw new AuthorizationException('Missing required read permission [sales.invoice.view]');
                    }
                }
            }

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
        $payload = '{"amount":"100.000000","client_id":"cli-1"}';

        $this->expectException(FinancialExecutionDeniedException::class);
        $this->expectExceptionMessage('Financial authority is unresolved on Mobile V2');

        // Without passing custom executor, manager uses default DenyFinancialExecutionAdapter
        $manager = new MobileEventReceiptManager;
        $manager->recordOrReplay(
            companyId: (int) $this->companyA->id,
            clientEventId: (string) Str::uuid(),
            tokenHash: $this->token->token_hash,
            eventVersion: 1,
            representation: 'v1_canonical',
            canonicalBytes: $payload
        );

        $this->assertDatabaseCount('mobile_event_receipts', 0);
    }

    public function test_production_default_denies_replay_and_lookup_of_existing_receipt(): void
    {
        $payload = '{"amount":"100.000000"}';
        $uuid = (string) Str::uuid();
        app(CompanyContext::class)->setCompany($this->companyA, $this->user);

        // Seed an existing receipt row in the DB
        MobileEventReceipt::create([
            'company_id' => $this->companyA->id,
            'client_event_id' => $uuid,
            'actor_id' => $this->user->id,
            'device_id' => $this->device->device_id,
            'event_version' => 1,
            'representation' => 'v1_canonical',
            'payload_digest' => hash('sha256', $payload),
            'original_canonical_bytes' => $payload,
            'status' => 'completed',
            'terminal_canonical_result' => ['grand_total' => '100.000000'],
        ]);

        $manager = new MobileEventReceiptManager;

        // Replay attempt must be denied by DenyFinancialExecutionAdapter
        try {
            $manager->recordOrReplay(
                companyId: (int) $this->companyA->id,
                clientEventId: $uuid,
                tokenHash: $this->token->token_hash,
                eventVersion: 1,
                representation: 'v1_canonical',
                canonicalBytes: $payload
            );
            $this->fail('Expected FinancialExecutionDeniedException on replay');
        } catch (FinancialExecutionDeniedException $e) {
            $this->assertStringContainsString('Financial authority is unresolved on Mobile V2', $e->getMessage());
        }

        // Lookup attempt must also be denied by DenyFinancialExecutionAdapter
        try {
            $manager->lookup((int) $this->companyA->id, $uuid, $this->token->token_hash);
            $this->fail('Expected FinancialExecutionDeniedException on lookup');
        } catch (FinancialExecutionDeniedException $e) {
            $this->assertStringContainsString('Financial authority is unresolved on Mobile V2', $e->getMessage());
        }
    }

    public function test_successful_receipt_creation_stores_all_provenance_and_terminal_result(): void
    {
        $payload = '{"grand_total":"250.000000","lines":[{"product_id":1,"quantity":"2.000000","unit_price":"125.000000"}]}';
        $uuid = (string) Str::uuid();

        $manager = new MobileEventReceiptManager($this->createTestExecutor([
            'sales_invoice_id' => null,
            'sales_invoice_public_id' => 'INV_505PUBLICID',
            'customer_payment_id' => null,
            'customer_payment_public_id' => 'PAY_606PUBLICID',
        ]));

        $res = $manager->recordOrReplay(
            companyId: (int) $this->companyA->id,
            clientEventId: $uuid,
            tokenHash: $this->token->token_hash,
            eventVersion: 2,
            representation: 'v2_intent',
            canonicalBytes: $payload
        );

        $this->assertSame('created', $res['outcome']);
        $this->assertSame('completed', $res['status']);
        $this->assertNull($res['sales_invoice_id']);
        $this->assertSame('INV_505PUBLICID', $res['sales_invoice_public_id']);
        $this->assertNull($res['customer_payment_id']);
        $this->assertSame('PAY_606PUBLICID', $res['customer_payment_public_id']);

        $this->assertDatabaseCount('mobile_event_receipts', 1);
        $row = MobileEventReceipt::withoutGlobalScopes()->firstOrFail();
        $this->assertSame((int) $this->companyA->id, (int) $row->company_id);
        $this->assertSame($uuid, $row->client_event_id);
        $this->assertSame((int) $this->user->id, (int) $row->actor_id);
        $this->assertSame($this->device->device_id, $row->device_id);
        $this->assertSame(2, $row->event_version);
        $this->assertSame('v2_intent', $row->representation);
        $this->assertSame(hash('sha256', $payload), $row->payload_digest);
        $this->assertSame($payload, $row->original_canonical_bytes);
    }

    public function test_idempotent_loss_ack_returns_original_terminal_result_without_regeneration(): void
    {
        $payload = '{"grand_total":"100.000000"}';
        $uuid = (string) Str::uuid();

        $manager1 = new MobileEventReceiptManager($this->createTestExecutor([
            'sales_invoice_id' => null,
            'sales_invoice_public_id' => 'INV_111ORIGINAL',
        ]));

        $res1 = $manager1->recordOrReplay(
            companyId: (int) $this->companyA->id,
            clientEventId: $uuid,
            tokenHash: $this->token->token_hash,
            eventVersion: 1,
            representation: 'v1_canonical',
            canonicalBytes: $payload
        );

        $this->assertSame('created', $res1['outcome']);
        $this->assertDatabaseCount('mobile_event_receipts', 1);

        // Second call: exact same payload and event ID
        $manager2 = new MobileEventReceiptManager($this->createTestExecutor([
            'sales_invoice_id' => null,
            'sales_invoice_public_id' => 'INV_999DIFFERENT', // would differ if executed
        ]));

        $res2 = $manager2->recordOrReplay(
            companyId: (int) $this->companyA->id,
            clientEventId: $uuid,
            tokenHash: $this->token->token_hash,
            eventVersion: 1,
            representation: 'v1_canonical',
            canonicalBytes: $payload
        );

        $this->assertSame('replay', $res2['outcome']);
        $this->assertNull($res2['sales_invoice_id']);
        $this->assertSame('INV_111ORIGINAL', $res2['sales_invoice_public_id']);
        $this->assertDatabaseCount('mobile_event_receipts', 1);

        // Also test lookup with verified token
        $lookup = $manager2->lookup((int) $this->companyA->id, $uuid, $this->token->token_hash);
        $this->assertNotNull($lookup);
        $this->assertSame('lookup', $lookup['outcome']);
        $this->assertSame('INV_111ORIGINAL', $lookup['sales_invoice_public_id']);
    }

    public function test_altered_payload_for_same_client_event_id_throws_409_conflict(): void
    {
        $payloadOriginal = '{"grand_total":"100.000000"}';
        $payloadAltered = '{"grand_total":"200.000000"}';
        $uuid = (string) Str::uuid();

        $manager = new MobileEventReceiptManager($this->createTestExecutor());

        $manager->recordOrReplay(
            companyId: (int) $this->companyA->id,
            clientEventId: $uuid,
            tokenHash: $this->token->token_hash,
            eventVersion: 1,
            representation: 'v1_canonical',
            canonicalBytes: $payloadOriginal
        );

        $this->expectException(MobileEventDuplicatePayloadException::class);
        $this->expectExceptionMessage('Event retry payload differs from original reviewed request.');

        $manager->recordOrReplay(
            companyId: (int) $this->companyA->id,
            clientEventId: $uuid,
            tokenHash: $this->token->token_hash,
            eventVersion: 1,
            representation: 'v1_canonical',
            canonicalBytes: $payloadAltered
        );
    }

    public function test_scope_checked_first_before_digest_comparison_without_digest_oracle(): void
    {
        $payloadOriginal = '{"grand_total":"100.000000"}';
        $payloadAltered = '{"grand_total":"999.000000"}';
        $uuid = (string) Str::uuid();

        $manager = new MobileEventReceiptManager($this->createTestExecutor());

        // Created by User 1, Device 1
        $manager->recordOrReplay(
            companyId: (int) $this->companyA->id,
            clientEventId: $uuid,
            tokenHash: $this->token->token_hash,
            eventVersion: 1,
            representation: 'v1_canonical',
            canonicalBytes: $payloadOriginal
        );

        // Colleague in same company
        $colleague = User::factory()->create([
            'password' => Hash::make('ColleaguePass123'),
        ]);
        $this->companyA->users()->attach($colleague->id, ['status' => 'active', 'is_owner' => false]);
        setPermissionsTeamId($this->companyA->id);
        $colleague->assignRole('Cashier');
        $colleague->givePermissionTo('sales.invoice.post');
        $colleague->givePermissionTo('sales.invoice.view');

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
            $manager->recordOrReplay(
                companyId: (int) $this->companyA->id,
                clientEventId: $uuid,
                tokenHash: $foreignToken->token_hash,
                eventVersion: 1,
                representation: 'v1_canonical',
                canonicalBytes: $payloadAltered
            );
            $this->fail('Expected MobileEventForeignScopeException');
        } catch (MobileEventForeignScopeException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('Event scope does not match authorized actor and device context.', $e->getMessage());
        }

        // Also test lookup(): foreign scope fails
        $this->expectException(MobileEventForeignScopeException::class);
        $manager->lookup((int) $this->companyA->id, $uuid, $foreignToken->token_hash);
    }

    public function test_same_client_event_id_across_different_companies_is_independent(): void
    {
        $payload = '{"grand_total":"100.000000"}';
        $uuid = (string) Str::uuid();

        // Company A
        $managerA = new MobileEventReceiptManager($this->createTestExecutor(['sales_invoice_public_id' => 'INV_A']));
        $resA = $managerA->recordOrReplay(
            companyId: (int) $this->companyA->id,
            clientEventId: $uuid,
            tokenHash: $this->token->token_hash,
            eventVersion: 1,
            representation: 'v1_canonical',
            canonicalBytes: $payload
        );

        // Company B with same clientEventId
        $managerB = new MobileEventReceiptManager($this->createTestExecutor(['sales_invoice_public_id' => 'INV_B']));
        $resB = $managerB->recordOrReplay(
            companyId: (int) $this->companyB->id,
            clientEventId: $uuid,
            tokenHash: $this->token->token_hash,
            eventVersion: 1,
            representation: 'v1_canonical',
            canonicalBytes: $payload
        );

        $this->assertSame('created', $resA['outcome']);
        $this->assertSame('created', $resB['outcome']);
        $this->assertDatabaseCount('mobile_event_receipts', 2);
    }

    public function test_cross_version_collision_converges_when_payload_digest_matches(): void
    {
        $payload = '{"grand_total":"100.000000"}';
        $uuid = (string) Str::uuid();

        // Created under v1
        $manager1 = new MobileEventReceiptManager($this->createTestExecutor(['sales_invoice_public_id' => 'INV_V1']));
        $res1 = $manager1->recordOrReplay(
            companyId: (int) $this->companyA->id,
            clientEventId: $uuid,
            tokenHash: $this->token->token_hash,
            eventVersion: 1,
            representation: 'v1_canonical',
            canonicalBytes: $payload
        );

        $this->assertSame('created', $res1['outcome']);

        // Retried under v2 with same payload
        $manager2 = new MobileEventReceiptManager($this->createTestExecutor(['sales_invoice_public_id' => 'INV_V2']));
        $res2 = $manager2->recordOrReplay(
            companyId: (int) $this->companyA->id,
            clientEventId: $uuid,
            tokenHash: $this->token->token_hash,
            eventVersion: 2,
            representation: 'v2_intent',
            canonicalBytes: $payload
        );

        $this->assertSame('replay', $res2['outcome']);
        $this->assertSame('INV_V1', $res2['sales_invoice_public_id']);
    }

    public function test_revoked_token_fails_linearized_check_inside_transaction(): void
    {
        $payload = '{"grand_total":"100.000000"}';
        $this->token->update(['revoked_at' => now()]);

        $this->expectException(MobileEventAuthenticationException::class);
        $this->expectExceptionMessage('The Bearer token has been revoked or expired.');

        $manager = new MobileEventReceiptManager($this->createTestExecutor());
        $manager->recordOrReplay(
            companyId: (int) $this->companyA->id,
            clientEventId: (string) Str::uuid(),
            tokenHash: $this->token->token_hash,
            eventVersion: 1,
            representation: 'v1_canonical',
            canonicalBytes: $payload
        );
    }

    public function test_revoked_device_fails_linearized_check_inside_transaction(): void
    {
        $payload = '{"grand_total":"100.000000"}';
        $this->device->update(['revoked_at' => now(), 'revoked_reason' => 'Lost device']);

        $this->expectException(MobileEventAuthenticationException::class);
        $this->expectExceptionMessage('Device has been revoked or does not match token owner.');

        $manager = new MobileEventReceiptManager($this->createTestExecutor());
        $manager->recordOrReplay(
            companyId: (int) $this->companyA->id,
            clientEventId: (string) Str::uuid(),
            tokenHash: $this->token->token_hash,
            eventVersion: 1,
            representation: 'v1_canonical',
            canonicalBytes: $payload
        );
    }

    public function test_password_epoch_change_fails_linearized_check_inside_transaction(): void
    {
        $payload = '{"grand_total":"100.000000"}';
        $this->user->update(['password' => Hash::make('NewRevisedPassword123!')]);

        $this->expectException(MobileEventAuthenticationException::class);
        $this->expectExceptionMessage('User credentials have changed since token establishment.');

        $manager = new MobileEventReceiptManager($this->createTestExecutor());
        $manager->recordOrReplay(
            companyId: (int) $this->companyA->id,
            clientEventId: (string) Str::uuid(),
            tokenHash: $this->token->token_hash,
            eventVersion: 1,
            representation: 'v1_canonical',
            canonicalBytes: $payload
        );

        $this->token->refresh();
        $this->assertNotNull($this->token->revoked_at);
    }

    public function test_removed_company_membership_fails_scope_inside_transaction(): void
    {
        $payload = '{"grand_total":"100.000000"}';
        DB::table('company_user')
            ->where('company_id', $this->companyA->id)
            ->where('user_id', $this->user->id)
            ->update(['status' => 'inactive']);

        $this->expectException(MobileEventForeignScopeException::class);
        $this->expectExceptionMessage('User does not possess active authorized membership');

        $manager = new MobileEventReceiptManager($this->createTestExecutor());
        $manager->recordOrReplay(
            companyId: (int) $this->companyA->id,
            clientEventId: (string) Str::uuid(),
            tokenHash: $this->token->token_hash,
            eventVersion: 1,
            representation: 'v1_canonical',
            canonicalBytes: $payload
        );
    }

    public function test_missing_permission_fails_authorization_inside_transaction(): void
    {
        $payload = '{"grand_total":"100.000000"}';
        setPermissionsTeamId($this->companyA->id);
        $this->user->removeRole('Cashier');
        $this->user->revokePermissionTo('sales.invoice.post');

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Missing required financial permission [sales.invoice.post]');

        $manager = new MobileEventReceiptManager($this->createTestExecutor());
        $manager->recordOrReplay(
            companyId: (int) $this->companyA->id,
            clientEventId: (string) Str::uuid(),
            tokenHash: $this->token->token_hash,
            eventVersion: 1,
            representation: 'v1_canonical',
            canonicalBytes: $payload
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
        $this->expectExceptionMessage('Invalid canonical JSON payload structure.');
        $this->manager->validateEconomicPayload($duplicateKeyJson);
    }

    public function test_validate_economic_payload_rejects_non_canonical_whitespace_and_floats(): void
    {
        // Uncanonical whitespace rejected
        $whitespaceJson = '{"amount": "1.000000"}';
        try {
            $this->manager->validateEconomicPayload($whitespaceJson);
            $this->fail('Expected InvalidArgumentException for non-canonical whitespace');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Payload bytes are not in Small Trader Canonical JSON', $e->getMessage());
        }

        // Floats rejected
        $floatJson = '{"amount":100.5}';
        try {
            $this->manager->validateEconomicPayload($floatJson);
            $this->fail('Expected InvalidArgumentException for float');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Invalid canonical JSON payload structure.', $e->getMessage());
        }

        // Non-object shape rejected
        $arrayJson = '["item1","item2"]';
        try {
            $this->manager->validateEconomicPayload($arrayJson);
            $this->fail('Expected InvalidArgumentException for array shape');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Economic payload must be a JSON object.', $e->getMessage());
        }
    }

    public function test_client_event_id_must_be_valid_uuid(): void
    {
        $payload = '{"amount":"10.000000"}';
        $manager = new MobileEventReceiptManager($this->createTestExecutor());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Client event ID must be a valid UUID string.');

        $manager->recordOrReplay(
            companyId: (int) $this->companyA->id,
            clientEventId: 'not-a-valid-uuid',
            tokenHash: $this->token->token_hash,
            eventVersion: 1,
            representation: 'v1_canonical',
            canonicalBytes: $payload
        );
    }

    public function test_unsupported_version_and_representation_rejected(): void
    {
        $payload = '{"amount":"10.000000"}';
        $manager = new MobileEventReceiptManager($this->createTestExecutor());

        // Unsupported version
        try {
            $manager->recordOrReplay(
                companyId: (int) $this->companyA->id,
                clientEventId: (string) Str::uuid(),
                tokenHash: $this->token->token_hash,
                eventVersion: 99,
                representation: 'v1_canonical',
                canonicalBytes: $payload
            );
            $this->fail('Expected InvalidArgumentException for version 99');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Unsupported event version.', $e->getMessage());
        }

        // Mismatched representation
        try {
            $manager->recordOrReplay(
                companyId: (int) $this->companyA->id,
                clientEventId: (string) Str::uuid(),
                tokenHash: $this->token->token_hash,
                eventVersion: 1,
                representation: 'v2_intent',
                canonicalBytes: $payload
            );
            $this->fail('Expected InvalidArgumentException for mismatched representation');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Representation does not match event version.', $e->getMessage());
        }
    }

    public function test_root_transaction_level_guard_rejects_nested_transaction(): void
    {
        $payload = '{"amount":"10.000000"}';
        $manager = new MobileEventReceiptManager($this->createTestExecutor());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Mobile event receipt manager requires root database transaction level.');

        DB::transaction(function () use ($manager, $payload) {
            $manager->recordOrReplay(
                companyId: (int) $this->companyA->id,
                clientEventId: (string) Str::uuid(),
                tokenHash: $this->token->token_hash,
                eventVersion: 1,
                representation: 'v1_canonical',
                canonicalBytes: $payload,
                requireRootTransaction: true
            );
        });
    }

    public function test_model_immutability_guards_prevent_update_and_delete(): void
    {
        app(CompanyContext::class)->setCompany($this->companyA, $this->user);
        $payload = '{"amount":"10.000000"}';
        $receipt = MobileEventReceipt::create([
            'company_id' => $this->companyA->id,
            'client_event_id' => (string) Str::uuid(),
            'actor_id' => $this->user->id,
            'device_id' => $this->device->device_id,
            'event_version' => 1,
            'representation' => 'v1_canonical',
            'payload_digest' => hash('sha256', $payload),
            'original_canonical_bytes' => $payload,
            'status' => 'completed',
            'terminal_canonical_result' => ['amount' => '10.000000'],
        ]);

        // Attempt update
        try {
            $receipt->update(['status' => 'tampered']);
            $this->fail('Expected RuntimeException on model update');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Mobile event receipts are immutable and cannot be updated once created.', $e->getMessage());
        }

        // Attempt delete
        try {
            $receipt->delete();
            $this->fail('Expected RuntimeException on model delete');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Mobile event receipts are immutable and cannot be deleted.', $e->getMessage());
        }
    }

    public function test_entity_ownership_verification_rejects_foreign_company_invoice(): void
    {
        $payload = '{"amount":"10.000000"}';

        // Fake executor returns sales invoice from company B while operating under company A
        $manager = new MobileEventReceiptManager($this->createTestExecutor([
            'sales_invoice_id' => 999999, // non-existent or foreign
        ]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Financial executor returned sales invoice not belonging to target company.');

        $manager->recordOrReplay(
            companyId: (int) $this->companyA->id,
            clientEventId: (string) Str::uuid(),
            tokenHash: $this->token->token_hash,
            eventVersion: 1,
            representation: 'v1_canonical',
            canonicalBytes: $payload
        );
    }

    public function test_duplicate_constraint_and_deadlock_error_checks(): void
    {
        // Unrelated duplicate constraint (e.g. users_email_unique) must NOT match
        $unrelatedException = new QueryException(
            'mysql',
            'INSERT INTO users (email) VALUES ("x")',
            [],
            new PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'x' for key 'users_email_unique'", 23000)
        );
        $unrelatedException->errorInfo = ['23000', 1062, "Duplicate entry 'x' for key 'users_email_unique'"];

        $this->assertFalse(MobileEventReceiptManager::isDuplicateConstraintError($unrelatedException));

        // Exact mobile_event_receipts constraint MUST match
        $receiptDuplicate = new QueryException(
            'mysql',
            'INSERT INTO mobile_event_receipts ...',
            [],
            new PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '1-uuid' for key 'mobile_event_receipts_company_client_event_unique'", 23000)
        );
        $receiptDuplicate->errorInfo = ['23000', 1062, "Duplicate entry '1-uuid' for key 'mobile_event_receipts_company_client_event_unique'"];

        $this->assertTrue(MobileEventReceiptManager::isDuplicateConstraintError($receiptDuplicate));

        // Deadlock check: 1213 matches, arbitrary message '1213' does NOT match
        $deadlockException = new QueryException(
            'mysql',
            'SELECT ...',
            [],
            new PDOException('Deadlock found', 40001)
        );
        $deadlockException->errorInfo = ['40001', 1213, 'Deadlock found'];
        $this->assertTrue(MobileEventReceiptManager::isDeadlockError($deadlockException));

        $arbitraryMsg = new \Exception('Value 1213 is too high');
        $this->assertFalse(MobileEventReceiptManager::isDeadlockError($arbitraryMsg));
    }
}
