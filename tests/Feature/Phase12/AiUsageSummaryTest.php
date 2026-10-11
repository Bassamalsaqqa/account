<?php

declare(strict_types=1);

namespace Tests\Feature\Phase12;

use App\Actions\Company\CreateCompanyAction;
use App\Application\AI\AiUsageSummary;
use App\Models\AssistantRequest;
use App\Models\CompanyUser;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\Support\Phase12\FinalAiFixtures;
use Tests\TestCase;

class AiUsageSummaryTest extends TestCase
{
    use FinalAiFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->finalAiFixtures();
        $this->travelTo(Carbon::parse('2026-10-11 12:00:00', 'UTC'));
    }

    public function test_exact_company_and_user_aggregates_exclude_foreign_tenants_and_distinguish_unknown_cost(): void
    {
        $this->record($this->owner, 10, 28);
        $older = $this->record($this->owner, 20, 30, 5);
        $older->forceFill(['created_at' => now()->subDay()])->save();
        $other = User::factory()->create();
        $this->record($other, 9000000, 9000000, 8000000);
        app(CompanyContext::class)->clear();
        $foreign = app(CreateCompanyAction::class)->execute($this->owner, ['name_ar' => 'Synthetic usage control']);
        app(CompanyContext::class)->setCompany($foreign, $this->owner);
        $this->record($this->owner, 1000000, 1000000, 1000000);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $self = app(AiUsageSummary::class)->read($this->company, $this->owner);
        $this->assertCount(2, $self);
        $this->assertSame('user', $self[0]['scope']);
        $this->assertSame(1, $self[0]['requests']);
        $this->assertSame('0.000010', $self[0]['estimated']);
        $this->assertSame('0.000028', $self[0]['reserved']);
        $this->assertNull($self[0]['reported']);
        $this->assertSame(1, $self[0]['unknown_requests']);
        $this->assertSame(7, $self[0]['input_units']);
        $this->assertSame(2, $self[0]['audio_seconds']);
        $this->assertSame(2, $self[1]['requests']);
        $this->assertSame('0.000030', $self[1]['estimated']);
        $this->assertSame('0.000005', $self[1]['reported']);
        $company = app(AiUsageSummary::class)->read($this->company, $this->owner, true);
        $this->assertCount(4, $company);
        $this->assertSame(2, $company[0]['requests']);
        $this->assertSame('9.000010', $company[0]['estimated']);
        $this->assertSame('8.000000', $company[0]['reported']);
        $this->assertSame(1, $company[1]['requests']);
        $this->assertSame('0.000010', $company[1]['estimated']);
    }

    public function test_self_usage_permission_does_not_grant_company_usage_and_membership_is_rechecked(): void
    {
        $reader = User::factory()->create();
        CompanyUser::create(['company_id' => $this->company->id, 'user_id' => $reader->id, 'status' => 'active', 'is_owner' => false]);
        $role = Role::create(['company_id' => $this->company->id, 'name' => 'Synthetic self usage', 'guard_name' => 'web']);
        $role->givePermissionTo('ai.assistant.use');
        $reader->assignRole($role);
        $this->record($reader, 1, 2);
        $this->record($this->owner, 9000000, 9000000);
        $this->actingAs($reader);
        app(CompanyContext::class)->setCompany($this->company, $reader);
        $this->assertSame('0.000001', app(AiUsageSummary::class)->read($this->company, $reader)[0]['estimated']);
        try {
            app(AiUsageSummary::class)->read($this->company, $reader, true);
            $this->fail('Self usage is not company-wide usage authorization.');
        } catch (AuthorizationException) {
            $this->assertSame(2, AssistantRequest::count());
        }
        CompanyUser::where('company_id', $this->company->id)->where('user_id', $reader->id)->update(['status' => 'inactive']);
        $this->expectException(AuthorizationException::class);
        app(AiUsageSummary::class)->read($this->company, $reader);
    }

    private function record(User $actor, int $estimate, int $reserve, ?int $reported = null): AssistantRequest
    {
        return AssistantRequest::create(['company_id' => app(CompanyContext::class)->companyId(), 'user_id' => $actor->id,
            'request_key' => (string) Str::uuid(), 'purpose' => 'intent', 'provider' => 'synthetic', 'status' => 'failed',
            'input_hash' => hash('sha256', (string) Str::uuid()), 'estimated_cost_micros' => $estimate,
            'reserved_cost_micros' => $reserve, 'reported_cost_micros' => $reported,
            'cost_status' => $reported === null ? 'unknown' : 'reported', 'input_units' => 7, 'audio_seconds' => 2]);
    }
}
