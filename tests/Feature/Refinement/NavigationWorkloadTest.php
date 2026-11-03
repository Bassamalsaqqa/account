<?php

declare(strict_types=1);

namespace Tests\Feature\Refinement;

use App\Application\AI\AiGovernance;
use App\Models\AiCompanyPolicy;
use App\Models\CompanyUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SalesCorrectionFixtures;
use Tests\TestCase;

final class NavigationWorkloadTest extends TestCase
{
    use RefreshDatabase;
    use SalesCorrectionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->salesFixtures();
        AiCompanyPolicy::create(['company_id' => $this->company->id, 'enabled' => true,
            'settings' => ['allowed_intents' => ['sales', 'purchase']]]);
    }

    public function test_launcher_intent_enumeration_uses_bounded_fresh_reads_without_row_locks(): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $this->assertSame(['sales', 'purchase'], app(AiGovernance::class)->allowedIntents($this->company, $this->owner));
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
        fwrite(STDOUT, 'Launcher intent enumeration queries: '.count($queries).PHP_EOL);
        $this->assertLessThanOrEqual(4, count($queries));
        foreach ($queries as $query) {
            $this->assertStringNotContainsString('for update', strtolower($query['query']));
        }
    }

    public function test_launcher_enumeration_does_not_cache_membership_or_policy(): void
    {
        $governance = app(AiGovernance::class);
        $this->assertSame(['sales', 'purchase'], $governance->allowedIntents($this->company, $this->owner));
        AiCompanyPolicy::where('company_id', $this->company->id)->update(['settings' => ['allowed_intents' => ['sales']]]);
        $this->assertSame(['sales'], $governance->allowedIntents($this->company, $this->owner));
        CompanyUser::where('company_id', $this->company->id)->where('user_id', $this->owner->id)->update(['status' => 'inactive']);
        $this->assertSame([], $governance->allowedIntents($this->company, $this->owner));
    }

    public function test_launcher_enumeration_observes_permission_revocation_after_a_warm_read(): void
    {
        $governance = app(AiGovernance::class);
        $this->assertSame(['sales', 'purchase'], $governance->allowedIntents($this->company, $this->owner));
        $role = $this->owner->roles()->firstOrFail();
        $permission = DB::table('permissions')->where('name', 'ai.sales.use')->where('guard_name', 'web')->sole();
        DB::table('role_has_permissions')->where('role_id', $role->id)->where('permission_id', $permission->id)->delete();
        $this->assertSame(['purchase'], $governance->allowedIntents($this->company, $this->owner));
    }
}
