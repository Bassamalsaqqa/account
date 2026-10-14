<?php

declare(strict_types=1);

namespace Tests\Feature\AiV22;

use App\Actions\Company\CreateCompanyAction;
use App\Application\AI\AiGovernance;
use App\Application\AI\AssistantReportCatalog;
use App\Application\AI\AssistantTurnContext;
use App\Application\AI\AssistantUnavailable;
use App\Models\AiCompanyPolicy;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class AssistantTurnContextSecurityTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $reader;

    private Role $role;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai_assistant.enabled' => true, 'ai_assistant.questions.enabled' => true]);
        Http::preventStrayRequests();

        $owner = User::factory()->create();
        $this->company = app(CreateCompanyAction::class)->execute($owner, [
            'name_ar' => 'Synthetic company', 'name_en' => 'Synthetic company', 'base_currency_code' => 'ILS',
        ]);
        app(CompanyContext::class)->setCompany($this->company, $owner);
        AiCompanyPolicy::updateOrCreate(['company_id' => $this->company->id], [
            'enabled' => true, 'follow_up_enabled' => true,
            'settings' => ['questions' => ['enabled' => true, 'allowed_reports' => ['money.balances']]],
        ]);

        $this->reader = User::factory()->create();
        CompanyUser::create([
            'company_id' => $this->company->id, 'user_id' => $this->reader->id,
            'status' => 'active', 'is_owner' => false,
        ]);
        setPermissionsTeamId($this->company->id);
        $this->role = Role::create([
            'company_id' => $this->company->id, 'name' => 'Turn money reader', 'guard_name' => 'web',
        ]);
        $this->role->syncPermissions(['ai.assistant.use', 'reports.money.view', 'money.cash.view', 'money.bank.view']);
        $this->reader->assignRole($this->role);
        $this->actingAs($this->reader);
        app(CompanyContext::class)->setCompany($this->company, $this->reader);
    }

    public function test_revoking_cash_with_bank_retained_changes_scope_and_invalidates_context(): void
    {
        $this->assertAccountTypeDowngradeInvalidatesContext('cash', 'bank');
    }

    public function test_revoking_bank_with_cash_retained_changes_scope_and_invalidates_context(): void
    {
        $this->assertAccountTypeDowngradeInvalidatesContext('bank', 'cash');
    }

    private function assertAccountTypeDowngradeInvalidatesContext(string $revokedType, string $retainedType): void
    {
        $context = app(AssistantTurnContext::class);
        $sessionId = 'synthetic-turn-money-session';
        $proposals = [[
            'version' => 1, 'type' => 'report_query', 'report_key' => 'money.balances',
            'filters' => [], 'display' => 'summary',
        ]];
        $scope = $context->scope($this->company, $this->reader, $sessionId);
        $keys = app(AssistantReportCatalog::class)->permittedKeys($this->company, $this->reader);
        $token = $context->store($this->company, $this->reader, $sessionId, $proposals);
        $this->assertSame($proposals, $context->read($this->company, $this->reader, $sessionId, $token));

        $this->role->revokePermissionTo('money.'.$revokedType.'.view');
        $this->assertFalse(app(AiGovernance::class)->can($this->company, $this->reader, 'money.'.$revokedType.'.view'));
        $this->assertTrue(app(AiGovernance::class)->can($this->company, $this->reader, 'money.'.$retainedType.'.view'));
        $this->assertSame(['money.balances'], $keys);
        $this->assertSame($keys, app(AssistantReportCatalog::class)->permittedKeys($this->company, $this->reader));
        $this->assertNotSame($scope, $context->scope($this->company, $this->reader, $sessionId));
        Http::assertNothingSent();

        try {
            $context->read($this->company, $this->reader, $sessionId, $token);
            $this->fail('A context created with broader account authority must expire.');
        } catch (AssistantUnavailable $exception) {
            $this->assertSame('proposal_stale', $exception->reason);
        }
    }
}
