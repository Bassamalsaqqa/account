<?php

declare(strict_types=1);

namespace Tests\Feature\ManagedSaas;

use App\Actions\Company\AddCompanyMemberAction;
use App\Actions\Company\CreateCompanyAction;
use App\Actions\Purchasing\EnsurePurchasingFoundationAction;
use App\Application\AI\AiGovernance;
use App\Application\AI\AssistantUnavailable;
use App\Exceptions\CompanyReassignmentException;
use App\Models\AiCompanyPolicy;
use App\Models\AiProviderConnection;
use App\Models\Check;
use App\Models\Company;
use App\Models\CompanyCurrency;
use App\Models\CompanyLanguage;
use App\Models\CompanyUser;
use App\Models\CustomerPayment;
use App\Models\DocumentSequence;
use App\Models\LedgerAccount;
use App\Models\MoneyAccount;
use App\Models\PostingBatch;
use App\Models\PostingLine;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\VendorPayment;
use App\Models\Warehouse;
use App\Services\Tenancy\CompanyRoleService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ExistingProvisioningBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(CompanyRoleService::class)->ensurePermissionsExist();
    }

    protected function tearDown(): void
    {
        setPermissionsTeamId(null);
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        app(CompanyContext::class)->clear();
        auth()->forgetUser();
        parent::tearDown();
    }

    /**
     * Test 1: Canonical provisioning creates exactly one active Owner membership,
     * roles, settings, currencies, languages, default warehouse, accounts, and money foundation,
     * while guaranteeing ZERO business posting, stock, payment, or check records exist.
     */
    public function test_existing_create_company_action_provisions_complete_foundations_without_business_postings(): void
    {
        $owner = User::factory()->create([
            'name' => 'Synthetic Owner',
            'email' => 'owner.provisioning@example.test',
        ]);

        app(CompanyContext::class)->clear();

        $company = app(CreateCompanyAction::class)->execute($owner, [
            'name_ar' => 'شركة الأساس المدار التجريبية',
            'name_en' => 'Managed Foundation Test Co.',
            'base_currency_code' => 'ILS',
            'default_locale' => 'ar',
            'timezone' => 'Asia/Hebron',
        ]);

        // Establish context and Spatie team for the new company
        $this->actingAs($owner);
        $context = app(CompanyContext::class);
        $context->setCompany($company, $owner);
        setPermissionsTeamId($company->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);

        // 1. Company identity & state
        $this->assertInstanceOf(Company::class, $company);
        $this->assertSame('active', $company->status);
        $this->assertSame('ILS', $company->base_currency_code);
        $this->assertSame('ar', $company->default_locale);
        $this->assertSame('Asia/Hebron', $company->timezone);

        // 2. Exactly one active Owner membership
        $memberships = CompanyUser::where('company_id', $company->id)->get();
        $this->assertCount(1, $memberships);
        $ownerMembership = $memberships->first();
        $this->assertSame($owner->id, $ownerMembership->user_id);
        $this->assertTrue((bool) $ownerMembership->is_owner);
        $this->assertSame('active', $ownerMembership->status);
        $this->assertNotNull($ownerMembership->joined_at);

        // 3. Spatie team roles & Owner role assignment
        $roles = Role::where('company_id', $company->id)->get();
        $this->assertCount(8, $roles);
        $this->assertEqualsCanonicalizing(
            ['Owner', 'Administrator', 'Manager', 'Sales', 'Purchasing', 'Warehouse', 'Cashier', 'Viewer'],
            $roles->pluck('name')->all()
        );
        $this->assertTrue($owner->hasRole('Owner'));

        // 4. Company settings
        $this->assertDatabaseHas('company_security_settings', [
            'company_id' => $company->id,
            'require_2fa_for_owner' => 1,
            'require_2fa_for_admin' => 0,
            'public_share_default_expiry_days' => 30,
        ]);
        $this->assertDatabaseHas('company_document_settings', [
            'company_id' => $company->id,
            'default_document_locale' => 'ar',
            'show_logo' => 1,
        ]);
        $this->assertDatabaseHas('company_inventory_settings', [
            'company_id' => $company->id,
            'allow_negative_stock' => 0,
            'default_cost_method' => 'moving_average',
        ]);

        // 5. Currencies & Languages
        $currencies = CompanyCurrency::all();
        $this->assertCount(3, $currencies);
        $this->assertSame(1, $currencies->where('is_base', true)->count());
        $this->assertSame('ILS', $currencies->where('is_base', true)->first()->currency_code);

        $languages = CompanyLanguage::all();
        $this->assertCount(2, $languages);
        $this->assertSame(1, $languages->where('is_default', true)->count());
        $this->assertSame('ar', $languages->where('is_default', true)->first()->locale);

        // 6. Warehouse, Money Foundation, Ledger Accounts & Sequences
        $this->assertSame(1, Warehouse::where('company_id', $company->id)->count());
        $this->assertSame(1, MoneyAccount::where('company_id', $company->id)->count());
        $this->assertGreaterThan(0, LedgerAccount::where('company_id', $company->id)->count());
        $this->assertGreaterThan(0, DocumentSequence::where('company_id', $company->id)->count());

        // 7. Initial audit event
        $this->assertDatabaseHas('audit_events', [
            'company_id' => $company->id,
            'event_key' => 'company.created',
            'actor_user_id' => $owner->id,
        ]);

        // 8. Economic Invariant: ZERO business posting, stock, payment, or check records
        $this->assertSame(0, PostingBatch::where('company_id', $company->id)->count());
        $this->assertSame(0, PostingLine::where('company_id', $company->id)->count());
        $this->assertSame(0, StockMovement::where('company_id', $company->id)->count());
        $this->assertSame(0, CustomerPayment::where('company_id', $company->id)->count());
        $this->assertSame(0, VendorPayment::where('company_id', $company->id)->count());
        $this->assertSame(0, Check::where('company_id', $company->id)->count());
    }

    /**
     * Test 2: New company lacks/enforces OFF AI policy.
     * Even with global AI enabled, isPresentationEligible is false and authorizeUse
     * refuses use due to absent policy before any provider dispatch.
     */
    public function test_newly_provisioned_company_enforces_off_ai_policy_and_refuses_use(): void
    {
        $owner = User::factory()->create([
            'email' => 'ai.boundary@example.test',
        ]);

        app(CompanyContext::class)->clear();

        $company = app(CreateCompanyAction::class)->execute($owner, [
            'name_ar' => 'شركة فحص الذكاء الاصطناعي',
            'base_currency_code' => 'ILS',
        ]);

        // Establish context, auth and team for actor
        $this->actingAs($owner);
        setPermissionsTeamId($company->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
        app(CompanyContext::class)->setCompany($company, $owner);

        // 1. Policy table row does not exist for new company
        $this->assertFalse(AiCompanyPolicy::where('company_id', $company->id)->exists());

        // 2. Global AI enabled, yet company presentation eligibility remains strictly false
        config(['ai_assistant.enabled' => true]);
        config(['ai_assistant.disabled_company_ids' => []]);
        Http::preventStrayRequests();
        $this->assertFalse(AiGovernance::isPresentationEligible($company));

        // 3. authorizeUse strictly rejects use before any network or provider dispatch
        try {
            app(AiGovernance::class)->authorizeUse($company, $owner, 'auto');
            $this->fail('Expected AssistantUnavailable exception was not thrown.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('disabled', $e->reason);
        }

        // 4. Verify zero provider connection rows exist
        $this->assertSame(0, AiProviderConnection::where('company_id', $company->id)->count());
        Http::assertNothingSent();
    }

    /**
     * Test 3: Failure rollback atomicity.
     * Injecting an exception during a canonical foundation action rolls back the entire
     * creation, leaving no company, membership, roles, accounts, or audit rows,
     * while completely preserving pre-existing fixture company records.
     */
    public function test_foundation_failure_rolls_back_entire_creation_and_preserves_prior_fixtures(): void
    {
        // 1. Establish pre-existing fixture company
        $fixtureOwner = User::factory()->create(['email' => 'prior.fixture.owner@example.test']);
        app(CompanyContext::class)->clear();

        $fixtureCompany = app(CreateCompanyAction::class)->execute($fixtureOwner, [
            'name_ar' => 'شركة سابقة سليمة',
            'base_currency_code' => 'USD',
        ]);

        // Global row fingerprints: scoped models intentionally return no rows without context.
        $snapshot = static function (): array {
            $result = [];
            foreach (['companies', 'company_user', 'roles', 'model_has_roles', 'ledger_accounts', 'money_accounts', 'warehouses', 'company_currencies', 'company_languages', 'company_inventory_settings', 'company_document_settings', 'company_security_settings', 'document_sequences', 'audit_events'] as $table) {
                $rows = DB::table($table)->get()->map(static fn ($row) => json_encode($row, JSON_THROW_ON_ERROR))->sort()->values()->all();
                $result[$table] = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
            }

            return $result;
        };
        $before = $snapshot();

        // 2. Inject deterministic exception into a later foundation action via container binding
        $this->app->bind(EnsurePurchasingFoundationAction::class, function () {
            return new class
            {
                public function execute(Company $company): void
                {
                    throw new RuntimeException('Deterministic purchasing foundation failure injection');
                }
            };
        });

        // 3. Attempt creation of a new company
        $attemptedOwner = User::factory()->create(['email' => 'failed.attempt.owner@example.test']);
        app(CompanyContext::class)->clear();

        try {
            app(CreateCompanyAction::class)->execute($attemptedOwner, [
                'name_ar' => 'شركة فاشلة للتراجع الذري',
                'base_currency_code' => 'ILS',
            ]);
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $e) {
            $this->assertSame('Deterministic purchasing foundation failure injection', $e->getMessage());
        } finally {
            $this->app->forgetInstance(EnsurePurchasingFoundationAction::class);
            setPermissionsTeamId(null);
            app(PermissionRegistrar::class)->setPermissionsTeamId(null);
            app(CompanyContext::class)->clear();
            auth()->forgetUser();
        }

        // 4. Assert outer transaction rolled back completely
        $this->assertFalse(Company::where('name_ar', 'شركة فاشلة للتراجع الذري')->exists());
        $this->assertSame(0, DB::table('company_user')->where('user_id', $attemptedOwner->id)->count());

        // Total counts match baseline exactly
        $this->assertSame($before, $snapshot());

        // Prior fixture company remains completely intact
        $freshFixture = $fixtureCompany->fresh();
        $this->assertNotNull($freshFixture);
        $this->assertSame('active', $freshFixture->status);
        $this->assertSame(1, DB::table('company_user')->where('company_id', $fixtureCompany->id)->where('user_id', $fixtureOwner->id)->count());
    }

    /**
     * Test 4: Active foreign company context rejects company creation.
     */
    public function test_active_foreign_company_context_rejects_company_creation(): void
    {
        $ownerA = User::factory()->create(['email' => 'owner.a@example.test']);
        app(CompanyContext::class)->clear();

        $companyA = app(CreateCompanyAction::class)->execute($ownerA, [
            'name_ar' => 'شركة السياق النشط',
            'base_currency_code' => 'ILS',
        ]);

        $context = app(CompanyContext::class);
        $context->setCompany($companyA, $ownerA);

        $ownerB = User::factory()->create(['email' => 'owner.b@example.test']);

        try {
            app(CreateCompanyAction::class)->execute($ownerB, [
                'name_ar' => 'شركة مرفوضة بسبب السياق',
                'base_currency_code' => 'ILS',
            ]);
            $this->fail('Expected CompanyReassignmentException was not thrown.');
        } catch (CompanyReassignmentException $e) {
            $this->assertStringContainsString('Cannot create a new company while an active company context', $e->getMessage());
        } finally {
            $context->clear();
        }
    }

    /**
     * Test 5: Existing staff flow rejects Owner role and existing-email identity
     * without overwriting credentials, silently linking membership, or altering roles.
     */
    public function test_staff_creation_flow_rejects_owner_role_and_existing_email_identity_without_credential_mutation(): void
    {
        $owner = User::factory()->create(['email' => 'company.owner@example.test']);
        app(CompanyContext::class)->clear();

        $company = app(CreateCompanyAction::class)->execute($owner, [
            'name_ar' => 'شركة فحص إدارة الموظفين',
            'base_currency_code' => 'ILS',
        ]);

        $this->actingAs($owner);
        $context = app(CompanyContext::class);
        $context->setCompany($company, $owner);
        setPermissionsTeamId($company->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);

        $addMemberAction = app(AddCompanyMemberAction::class);

        // 1. Rejection of Owner role via staff creation flow
        try {
            $addMemberAction->execute($company, [
                'name' => 'Attempted Owner',
                'email' => 'attempted.owner@example.test',
                'password' => 'SecurePass123!',
                'locale' => 'ar',
            ], 'Owner', $owner);
            $this->fail('Expected InvalidArgumentException for Owner role was not thrown.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString("Cannot assign role 'Owner'", $e->getMessage());
        }

        // 2. Rejection of existing-email identity without mutating credentials or linking membership
        $existingUser = User::factory()->create([
            'email' => 'preexisting.identity@example.test',
            'password' => Hash::make('OriginalSecretPass123!'),
        ]);
        $originalPasswordHash = $existingUser->password;

        try {
            $addMemberAction->execute($company, [
                'name' => 'Impersonated User',
                'email' => 'preexisting.identity@example.test',
                'password' => 'NewMaliciousSecretPass456!',
                'locale' => 'ar',
            ], 'Manager', $owner);
            $this->fail('Expected InvalidArgumentException for existing email was not thrown.');
        } catch (InvalidArgumentException $e) {
            $msg = $e->getMessage();
            $this->assertTrue(
                str_contains($msg, 'already exists') || str_contains($msg, 'مسبقاً'),
                "Expected message to indicate user already exists, got: {$msg}"
            );
        }

        // Assert credentials untouched
        $freshUser = $existingUser->fresh();
        $this->assertSame($originalPasswordHash, $freshUser->password);
        $this->assertTrue(Hash::check('OriginalSecretPass123!', $freshUser->password));

        // Assert no membership row was created for existingUser
        $this->assertFalse(CompanyUser::where('company_id', $company->id)->where('user_id', $existingUser->id)->exists());
    }

    /**
     * Test 6: Existing company Owner/team administration remains ordinary company-scoped authorization.
     */
    public function test_staff_administration_enforces_company_scoped_authorization(): void
    {
        $owner = User::factory()->create(['email' => 'auth.owner@example.test']);
        app(CompanyContext::class)->clear();

        $company = app(CreateCompanyAction::class)->execute($owner, [
            'name_ar' => 'شركة فحص الصلاحيات',
            'base_currency_code' => 'ILS',
        ]);

        $this->actingAs($owner);
        $context = app(CompanyContext::class);
        $context->setCompany($company, $owner);
        setPermissionsTeamId($company->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);

        // Add a Viewer staff member
        $viewerMembership = app(AddCompanyMemberAction::class)->execute($company, [
            'name' => 'Viewer Staff',
            'email' => 'viewer.staff@example.test',
            'password' => 'SecurePass123!',
            'locale' => 'ar',
        ], 'Viewer', $owner);

        $viewer = $viewerMembership->user;

        // Switch to Viewer actor
        $this->actingAs($viewer);
        $context->setCompany($company, $viewer);

        // Attempt to add another member as Viewer -> Blocked by authorization
        try {
            app(AddCompanyMemberAction::class)->execute($company, [
                'name' => 'Unauthorized Staff',
                'email' => 'unauthorized.staff@example.test',
                'password' => 'SecurePass123!',
                'locale' => 'ar',
            ], 'Sales', $viewer);
            $this->fail('Expected AuthorizationException was not thrown.');
        } catch (AuthorizationException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
    }
}
