<?php

declare(strict_types=1);

namespace Tests\Feature\Mobile;

use App\Application\Platform\SupportViewContext;
use App\Models\Company;
use App\Models\Customer;
use App\Models\MobileAccessToken;
use App\Models\MobileDevice;
use App\Models\MobileEventReceipt;
use App\Models\SalesInvoice;
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
use App\Support\Tenancy\CompanyScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PDOException;
use RuntimeException;
use Tests\Support\EnforcesDisposableDatabaseOwnership;
use Tests\TestCase;

final class MobileEventReceiptIdentityTest extends TestCase
{
    use EnforcesDisposableDatabaseOwnership;

    // Non-nested fixture strategy on owned disposable schema: no outer transaction wrapper

    private User $user;

    private Company $companyA;

    private Company $companyB;

    private Customer $customerA;

    private MobileDevice $device;

    private MobileAccessToken $token;

    private string $rawToken;

    private MobileEventReceiptManager $manager;

    protected function tearDown(): void
    {
        if (! $this->isDisposableDatabaseOwnershipAdmitted()) {
            parent::tearDown();

            return;
        }

        try {
            $this->revalidateOwnedDisposableDatabase();

            if (DB::transactionLevel() > 0) {
                DB::rollBack(0);
            }

            CompanyScope::executeWithoutScope(function () {
                if (isset($this->companyA) && isset($this->companyB)) {
                    MobileEventReceipt::whereIn('company_id', [$this->companyA->id, $this->companyB->id])->delete();
                }
            });

            app(CompanyContext::class)->clear();
        } finally {
            $this->disposableDatabaseOwnershipAdmitted = false;
            $this->admittedDatabaseName = null;
            parent::tearDown();
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertOwnedDisposableDatabase();

        $this->user = User::factory()->create([
            'email' => 'staff_receipt_'.Str::random(8).'@example.com',
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

        $this->customerA = CompanyScope::executeWithoutScope(fn () => Customer::create([
            'company_id' => $this->companyA->id,
            'name_ar' => 'عميل المقبوضات',
            'name_en' => 'Receipt Customer',
            'currency_code' => 'ILS',
            'active' => true,
            'created_by' => $this->user->id,
        ]));

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

    /**
     * @param  array{sales_invoice_id?: int|null, sales_invoice_public_id?: string|null, customer_payment_id?: int|null, customer_payment_public_id?: string|null, command_receipt_id?: int|null, terminal_canonical_result?: array<string, mixed>}  $terminalResult
     */
    private function createTestExecutor(array $terminalResult = []): FinancialExecutionAdapterInterface
    {
        return new class($terminalResult) implements FinancialExecutionAdapterInterface
        {
            /**
             * @param  array{sales_invoice_id?: int|null, sales_invoice_public_id?: string|null, customer_payment_id?: int|null, customer_payment_public_id?: string|null, command_receipt_id?: int|null, terminal_canonical_result?: array<string, mixed>}  $result
             */
            public function __construct(private array $result) {}

            public function authorize(
                Company $company,
                User $actor,
                MobileDevice $device,
                string $operation,
                ?array $payload = null
            ): void {
                $permission = $operation === 'execute' ? 'sales.invoice.post' : 'sales.invoice.view';
                $hasPerm = DB::table('model_has_permissions')
                    ->join('permissions', 'permissions.id', '=', 'model_has_permissions.permission_id')
                    ->where('model_has_permissions.model_type', User::class)
                    ->where('model_has_permissions.model_id', $actor->id)
                    ->where('model_has_permissions.company_id', $company->id)
                    ->where('permissions.name', $permission)
                    ->exists();

                if (! $hasPerm) {
                    throw new AuthorizationException("Missing required financial permission [{$permission}]");
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
                    'sales_invoice_public_id' => $this->result['sales_invoice_public_id'] ?? null,
                    'customer_payment_id' => $this->result['customer_payment_id'] ?? null,
                    'customer_payment_public_id' => $this->result['customer_payment_public_id'] ?? null,
                    'command_receipt_id' => $this->result['command_receipt_id'] ?? null,
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

        $this->manager->recordOrReplay(
            companyId: (int) $this->companyA->id,
            clientEventId: (string) Str::uuid(),
            tokenHash: $this->token->token_hash,
            eventVersion: 2,
            representation: 'v2_intent',
            canonicalBytes: $payload
        );
    }

    public function test_production_default_denies_replay_and_lookup(): void
    {
        $payload = '{"amount":"100.000000"}';
        $uuid = (string) Str::uuid();

        app(CompanyContext::class)->setCompany($this->companyA, $this->user);

        // Seed an existing receipt row in the DB directly
        MobileEventReceipt::create([
            'company_id' => $this->companyA->id,
            'client_event_id' => $uuid,
            'actor_id' => $this->user->id,
            'device_id' => $this->device->device_id,
            'event_version' => 2,
            'representation' => 'v2_intent',
            'payload_digest' => hash('sha256', $payload),
            'original_canonical_bytes' => $payload,
            'status' => 'completed',
            'terminal_canonical_result' => ['status' => 'completed', 'grand_total' => '100.000000'],
        ]);

        $manager = new MobileEventReceiptManager;

        // Replay attempt must be denied by DenyFinancialExecutionAdapter
        try {
            $manager->recordOrReplay(
                companyId: (int) $this->companyA->id,
                clientEventId: $uuid,
                tokenHash: $this->token->token_hash,
                eventVersion: 2,
                representation: 'v2_intent',
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
            'sales_invoice_public_id' => null,
            'customer_payment_id' => null,
            'customer_payment_public_id' => null,
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
        $this->assertNull($res['sales_invoice_public_id']);
        $this->assertNull($res['customer_payment_id']);
        $this->assertNull($res['customer_payment_public_id']);

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
            'terminal_canonical_result' => [
                'status' => 'completed',
                'grand_total' => '100.000000',
                'paid_amount' => '100.000000',
            ],
        ]));

        $res1 = $manager1->recordOrReplay(
            companyId: (int) $this->companyA->id,
            clientEventId: $uuid,
            tokenHash: $this->token->token_hash,
            eventVersion: 2,
            representation: 'v2_intent',
            canonicalBytes: $payload
        );

        $this->assertSame('created', $res1['outcome']);
        $this->assertDatabaseCount('mobile_event_receipts', 1);

        // Second call: exact same payload and event ID
        $manager2 = new MobileEventReceiptManager($this->createTestExecutor([
            'terminal_canonical_result' => [
                'status' => 'completed',
                'grand_total' => '999.000000', // would differ if re-executed
            ],
        ]));

        $res2 = $manager2->recordOrReplay(
            companyId: (int) $this->companyA->id,
            clientEventId: $uuid,
            tokenHash: $this->token->token_hash,
            eventVersion: 2,
            representation: 'v2_intent',
            canonicalBytes: $payload
        );

        $this->assertSame('replay', $res2['outcome']);
        $this->assertSame('100.000000', $res2['terminal_canonical_result']['grand_total']);
        $this->assertDatabaseCount('mobile_event_receipts', 1);

        // Also test lookup with verified token
        $lookup = $manager2->lookup((int) $this->companyA->id, $uuid, $this->token->token_hash);
        $this->assertNotNull($lookup);
        $this->assertSame('lookup', $lookup['outcome']);
        $this->assertSame('100.000000', $lookup['terminal_canonical_result']['grand_total']);
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
            eventVersion: 2,
            representation: 'v2_intent',
            canonicalBytes: $payloadOriginal
        );

        $this->expectException(MobileEventDuplicatePayloadException::class);
        $this->expectExceptionMessage('Event retry payload differs from original reviewed request.');

        $manager->recordOrReplay(
            companyId: (int) $this->companyA->id,
            clientEventId: $uuid,
            tokenHash: $this->token->token_hash,
            eventVersion: 2,
            representation: 'v2_intent',
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
            eventVersion: 2,
            representation: 'v2_intent',
            canonicalBytes: $payloadOriginal
        );

        // Colleague in same company
        $colleague = User::factory()->create([
            'email' => 'colleague_'.Str::random(8).'@example.com',
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
                eventVersion: 2,
                representation: 'v2_intent',
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
        $managerA = new MobileEventReceiptManager($this->createTestExecutor());
        $resA = $managerA->recordOrReplay(
            companyId: (int) $this->companyA->id,
            clientEventId: $uuid,
            tokenHash: $this->token->token_hash,
            eventVersion: 2,
            representation: 'v2_intent',
            canonicalBytes: $payload
        );

        // Company B with same clientEventId
        $managerB = new MobileEventReceiptManager($this->createTestExecutor());
        $resB = $managerB->recordOrReplay(
            companyId: (int) $this->companyB->id,
            clientEventId: $uuid,
            tokenHash: $this->token->token_hash,
            eventVersion: 2,
            representation: 'v2_intent',
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

        // Historical receipt seeded in database directly (e.g. legacy v1)
        CompanyScope::executeWithoutScope(fn () => MobileEventReceipt::create([
            'company_id' => $this->companyA->id,
            'client_event_id' => $uuid,
            'actor_id' => $this->user->id,
            'device_id' => $this->device->device_id,
            'event_version' => 1,
            'representation' => 'v1_canonical',
            'payload_digest' => hash('sha256', $payload),
            'original_canonical_bytes' => $payload,
            'status' => 'completed',
            'terminal_canonical_result' => [
                'status' => 'completed',
                'grand_total' => '100.000000',
            ],
        ]));

        // Retried under v2 with same payload
        $manager = new MobileEventReceiptManager($this->createTestExecutor());
        $res = $manager->recordOrReplay(
            companyId: (int) $this->companyA->id,
            clientEventId: $uuid,
            tokenHash: $this->token->token_hash,
            eventVersion: 2,
            representation: 'v2_intent',
            canonicalBytes: $payload
        );

        $this->assertSame('replay', $res['outcome']);
        $this->assertSame('100.000000', $res['terminal_canonical_result']['grand_total']);
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
            eventVersion: 2,
            representation: 'v2_intent',
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
            eventVersion: 2,
            representation: 'v2_intent',
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
            eventVersion: 2,
            representation: 'v2_intent',
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
            eventVersion: 2,
            representation: 'v2_intent',
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
            eventVersion: 2,
            representation: 'v2_intent',
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
            eventVersion: 2,
            representation: 'v2_intent',
            canonicalBytes: $payload
        );
    }

    public function test_legacy_v1_new_admission_is_unsupported(): void
    {
        $payload = '{"amount":"10.000000"}';
        $manager = new MobileEventReceiptManager($this->createTestExecutor());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Legacy v1 event admission is unsupported.');

        $manager->recordOrReplay(
            companyId: (int) $this->companyA->id,
            clientEventId: (string) Str::uuid(),
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

        // Unsupported version 3 (no invented v3_offline_event)
        try {
            $manager->recordOrReplay(
                companyId: (int) $this->companyA->id,
                clientEventId: (string) Str::uuid(),
                tokenHash: $this->token->token_hash,
                eventVersion: 3,
                representation: 'v2_intent',
                canonicalBytes: $payload
            );
            $this->fail('Expected InvalidArgumentException for version 3');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Unsupported event version.', $e->getMessage());
        }

        // Invented representation v3_offline_event rejected
        try {
            $manager->recordOrReplay(
                companyId: (int) $this->companyA->id,
                clientEventId: (string) Str::uuid(),
                tokenHash: $this->token->token_hash,
                eventVersion: 2,
                representation: 'v3_offline_event',
                canonicalBytes: $payload
            );
            $this->fail('Expected InvalidArgumentException for invented v3_offline_event');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Unsupported representation format.', $e->getMessage());
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
                eventVersion: 2,
                representation: 'v2_intent',
                canonicalBytes: $payload
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
            'event_version' => 2,
            'representation' => 'v2_intent',
            'payload_digest' => hash('sha256', $payload),
            'original_canonical_bytes' => $payload,
            'status' => 'completed',
            'terminal_canonical_result' => ['status' => 'completed', 'amount' => '10.000000'],
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

        // Fake executor returns non-existent or foreign invoice
        $manager = new MobileEventReceiptManager($this->createTestExecutor([
            'sales_invoice_id' => 999999,
        ]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Financial executor returned sales invoice not belonging to target company.');

        $manager->recordOrReplay(
            companyId: (int) $this->companyA->id,
            clientEventId: (string) Str::uuid(),
            tokenHash: $this->token->token_hash,
            eventVersion: 2,
            representation: 'v2_intent',
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

    public function test_auth_unknown_company_oracle_returns_identical_auth_error(): void
    {
        $payload = '{"amount":"10.000000"}';
        $invalidTokenHash = hash('sha256', 'st_m_invalid_token_hash_value');
        $manager = new MobileEventReceiptManager($this->createTestExecutor());

        // 1. With non-existent unknown company ID 999999
        $errUnknownCompany = null;
        try {
            $manager->recordOrReplay(
                companyId: 999999,
                clientEventId: (string) Str::uuid(),
                tokenHash: $invalidTokenHash,
                eventVersion: 2,
                representation: 'v2_intent',
                canonicalBytes: $payload
            );
        } catch (MobileEventAuthenticationException $e) {
            $errUnknownCompany = $e->getMessage();
        }

        // 2. With real valid company ID
        $errValidCompany = null;
        try {
            $manager->recordOrReplay(
                companyId: (int) $this->companyA->id,
                clientEventId: (string) Str::uuid(),
                tokenHash: $invalidTokenHash,
                eventVersion: 2,
                representation: 'v2_intent',
                canonicalBytes: $payload
            );
        } catch (MobileEventAuthenticationException $e) {
            $errValidCompany = $e->getMessage();
        }

        $this->assertSame('The Bearer token has been revoked or expired.', $errUnknownCompany);
        $this->assertSame($errUnknownCompany, $errValidCompany);

        // 3. Lookup: non-existent company vs valid company
        $lookupErrUnknown = null;
        try {
            $manager->lookup(999999, (string) Str::uuid(), $invalidTokenHash);
        } catch (MobileEventAuthenticationException $e) {
            $lookupErrUnknown = $e->getMessage();
        }

        $lookupErrValid = null;
        try {
            $manager->lookup((int) $this->companyA->id, (string) Str::uuid(), $invalidTokenHash);
        } catch (MobileEventAuthenticationException $e) {
            $lookupErrValid = $e->getMessage();
        }

        $this->assertSame('The Bearer token has been revoked or expired.', $lookupErrUnknown);
        $this->assertSame($lookupErrUnknown, $lookupErrValid);
    }

    public function test_lookup_authenticates_sets_guard_user_and_restores_prior_guard(): void
    {
        $payload = '{"amount":"10.000000"}';
        $uuid = (string) Str::uuid();

        CompanyScope::executeWithoutScope(fn () => MobileEventReceipt::create([
            'company_id' => $this->companyA->id,
            'client_event_id' => $uuid,
            'actor_id' => $this->user->id,
            'device_id' => $this->device->device_id,
            'event_version' => 2,
            'representation' => 'v2_intent',
            'payload_digest' => hash('sha256', $payload),
            'original_canonical_bytes' => $payload,
            'status' => 'completed',
            'terminal_canonical_result' => ['status' => 'completed', 'grand_total' => '10.000000'],
        ]));

        $guard = auth()->guard();
        $this->assertFalse($guard->hasUser());

        $observedGuardUserInExecutor = null;
        $executor = new class($observedGuardUserInExecutor) implements FinancialExecutionAdapterInterface
        {
            public function __construct(public mixed &$observedGuardUser) {}

            public function authorize(Company $c, User $a, MobileDevice $d, string $op, ?array $p = null): void
            {
                $this->observedGuardUser = auth()->guard()->user();
            }

            public function execute(Company $c, User $a, MobileDevice $d, array $p, string $s): array
            {
                return [
                    'sales_invoice_id' => null,
                    'sales_invoice_public_id' => null,
                    'customer_payment_id' => null,
                    'customer_payment_public_id' => null,
                    'command_receipt_id' => null,
                    'terminal_canonical_result' => [],
                ];
            }
        };

        $manager = new MobileEventReceiptManager($executor);
        $lookup = $manager->lookup((int) $this->companyA->id, $uuid, $this->token->token_hash);

        $this->assertNotNull($lookup);
        $this->assertNotNull($observedGuardUserInExecutor);
        $this->assertSame((int) $this->user->id, (int) $observedGuardUserInExecutor->id);

        // After lookup completes, guard hasUser is restored to false
        $this->assertFalse($guard->hasUser());
    }

    public function test_grant_revocation_respected_immediately_without_global_cache_flush(): void
    {
        $payload = '{"amount":"10.000000"}';
        $manager = new MobileEventReceiptManager($this->createTestExecutor());

        // Revoke sales.invoice.post directly from model_has_permissions in DB
        DB::table('model_has_permissions')
            ->where('model_id', $this->user->id)
            ->where('company_id', $this->companyA->id)
            ->delete();

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Missing required financial permission [sales.invoice.post]');

        $manager->recordOrReplay(
            companyId: (int) $this->companyA->id,
            clientEventId: (string) Str::uuid(),
            tokenHash: $this->token->token_hash,
            eventVersion: 2,
            representation: 'v2_intent',
            canonicalBytes: $payload
        );
    }

    public function test_public_id_mismatch_between_executor_and_database_fails_and_rolls_back(): void
    {
        // Create actual sales invoice in companyA
        $invoice = CompanyScope::executeWithoutScope(fn () => SalesInvoice::create([
            'company_id' => $this->companyA->id,
            'customer_id' => $this->customerA->id,
            'public_id' => 'INV_ACTUAL_DB_PUBLIC_ID',
            'invoice_number' => 'INV-MISMATCH-001',
            'issue_date' => '2026-11-12',
            'due_date' => '2026-11-12',
            'status' => 'draft',
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'subtotal_base' => '100.000000',
            'tax_total_base' => '0.000000',
            'total_base' => '100.000000',
            'balance_due_base' => '100.000000',
            'created_by' => $this->user->id,
        ]));

        $manager = new MobileEventReceiptManager($this->createTestExecutor([
            'sales_invoice_id' => $invoice->id,
            'sales_invoice_public_id' => 'INV_FAKE_MISMATCH_PUBLIC_ID',
        ]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Sales invoice public ID mismatch between executor and database row.');

        $manager->recordOrReplay(
            companyId: (int) $this->companyA->id,
            clientEventId: (string) Str::uuid(),
            tokenHash: $this->token->token_hash,
            eventVersion: 2,
            representation: 'v2_intent',
            canonicalBytes: '{"amount":"100.000000"}'
        );
    }

    public function test_terminal_canonical_result_rejects_unknown_keys_and_forbidden_credentials(): void
    {
        $payload = '{"amount":"10.000000"}';

        // 1. Unknown key rejected
        $managerUnknown = new MobileEventReceiptManager($this->createTestExecutor([
            'terminal_canonical_result' => [
                'status' => 'completed',
                'untrusted_arbitrary_key' => 'malicious_content',
            ],
        ]));

        try {
            $managerUnknown->recordOrReplay(
                companyId: (int) $this->companyA->id,
                clientEventId: (string) Str::uuid(),
                tokenHash: $this->token->token_hash,
                eventVersion: 2,
                representation: 'v2_intent',
                canonicalBytes: $payload
            );
            $this->fail('Expected RuntimeException for unknown key');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Terminal canonical result contains forbidden or unexpected key', $e->getMessage());
        }

        // 2. Sensitive credential in string rejected
        $managerCred = new MobileEventReceiptManager($this->createTestExecutor([
            'terminal_canonical_result' => [
                'status' => 'completed',
                'grand_total' => 'bearer_token_secret_value',
            ],
        ]));

        try {
            $managerCred->recordOrReplay(
                companyId: (int) $this->companyA->id,
                clientEventId: (string) Str::uuid(),
                tokenHash: $this->token->token_hash,
                eventVersion: 2,
                representation: 'v2_intent',
                canonicalBytes: $payload
            );
            $this->fail('Expected RuntimeException for sensitive credential');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Terminal canonical result contains forbidden sensitive credential', $e->getMessage());
        }
    }

    public function test_sessionless_context_cleared_in_finally_on_all_failures(): void
    {
        $context = app(CompanyContext::class);
        $context->setCompany($this->companyA, $this->user);
        $this->assertTrue($context->hasCompany());

        // 1. Invalid UUID
        try {
            $this->manager->recordOrReplay(
                companyId: (int) $this->companyA->id,
                clientEventId: 'not-a-uuid',
                tokenHash: $this->token->token_hash,
                eventVersion: 2,
                representation: 'v2_intent',
                canonicalBytes: '{"amount":"10.000000"}'
            );
        } catch (InvalidArgumentException) {
            // expected
        }
        $this->assertFalse($context->hasCompany());

        // 2. SupportViewContext active
        $context->setCompany($this->companyA, $this->user);
        $support = app(SupportViewContext::class);
        $support->activate(['operator_id' => 999]);

        try {
            $this->manager->recordOrReplay(
                companyId: (int) $this->companyA->id,
                clientEventId: (string) Str::uuid(),
                tokenHash: $this->token->token_hash,
                eventVersion: 2,
                representation: 'v2_intent',
                canonicalBytes: '{"amount":"10.000000"}'
            );
        } catch (MobileEventAuthenticationException) {
            // expected
        } finally {
            $support->clear();
        }
        $this->assertFalse($context->hasCompany());
    }

    public function test_payload_context_binding_mismatch_rejected_before_admission(): void
    {
        $manager = new MobileEventReceiptManager($this->createTestExecutor());
        $uuid = (string) Str::uuid();

        // Payload specifies a foreign client_event_id
        $payloadMismatchEvent = json_encode([
            'client_event_id' => (string) Str::uuid(),
            'grand_total' => '100.000000',
        ], JSON_THROW_ON_ERROR);

        $this->expectException(MobileEventForeignScopeException::class);
        $this->expectExceptionMessage('Payload client_event_id does not match event context.');

        $manager->recordOrReplay(
            companyId: (int) $this->companyA->id,
            clientEventId: $uuid,
            tokenHash: $this->token->token_hash,
            eventVersion: 2,
            representation: 'v2_intent',
            canonicalBytes: $payloadMismatchEvent
        );
    }

    public function test_original_canonical_bytes_preserves_raw_binary_and_unicode_without_normalization(): void
    {
        $arabicPayload = '{"amount":"100.000000","note":"تجربة مقبوضات عربية 🚀"}';
        $uuid = (string) Str::uuid();

        $manager = new MobileEventReceiptManager($this->createTestExecutor());
        $res = $manager->recordOrReplay(
            companyId: (int) $this->companyA->id,
            clientEventId: $uuid,
            tokenHash: $this->token->token_hash,
            eventVersion: 2,
            representation: 'v2_intent',
            canonicalBytes: $arabicPayload
        );

        $this->assertSame('created', $res['outcome']);

        $row = MobileEventReceipt::withoutGlobalScopes()->where('client_event_id', $uuid)->firstOrFail();
        $this->assertSame($arabicPayload, $row->original_canonical_bytes);
    }
}
