<?php

namespace Tests\Feature\Phase1;

use App\Models\Company;
use App\Models\CompanyCurrency;
use App\Models\User;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BootstrapCompanyCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_zero_company_user_bootstraps_company_safely(): void
    {
        $user = User::factory()->create([
            'email' => 'owner@example.com',
            'locale' => 'ar',
        ]);

        $this->assertNull($user->last_active_company_id);
        $this->assertCount(0, $user->companies);

        $this->artisan('app:bootstrap-company', [
            '--email' => 'owner@example.com',
            '--name-ar' => 'شركة التاجر الصغير',
            '--name-en' => 'Small Trader Co.',
            '--base-currency' => 'ILS',
            '--timezone' => 'Asia/Hebron',
        ])
            ->expectsOutputToContain('Company successfully bootstrapped')
            ->assertSuccessful();

        $user->refresh();
        $this->assertCount(1, $user->companies);

        /** @var Company $company */
        $company = $user->companies->first();
        $this->assertSame('شركة التاجر الصغير', $company->name_ar);
        $this->assertSame('Small Trader Co.', $company->name_en);
        $this->assertSame('ILS', $company->base_currency_code);
        $this->assertSame('Asia/Hebron', $company->timezone);
        $this->assertSame($company->id, $user->last_active_company_id);

        // Verify base currency
        $base = CompanyScope::executeWithoutScope(fn () => CompanyCurrency::where('company_id', $company->id)->where('is_base', true)->first());
        $this->assertNotNull($base);
        $this->assertSame('ILS', $base->currency_code);
    }

    public function test_duplicate_bootstrap_safely_reuses_and_does_not_duplicate_records(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.com']);

        // First bootstrap
        $this->artisan('app:bootstrap-company', [
            '--email' => 'owner@example.com',
            '--name-ar' => 'شركة التاجر الصغير',
            '--name-en' => 'Small Trader Co.',
            '--base-currency' => 'ILS',
        ])->assertSuccessful();

        $this->assertDatabaseCount('companies', 1);

        // Second duplicate bootstrap
        $this->artisan('app:bootstrap-company', [
            '--email' => 'owner@example.com',
            '--name-ar' => 'شركة التاجر الصغير',
            '--name-en' => 'Small Trader Co.',
            '--base-currency' => 'ILS',
        ])
            ->expectsOutputToContain('Reusing existing bootstrap')
            ->assertSuccessful();

        $this->assertDatabaseCount('companies', 1);

        // Third bootstrap with a DIFFERENT company name for same user
        $this->artisan('app:bootstrap-company', [
            '--email' => 'owner@example.com',
            '--name-ar' => 'اسم شركة مختلف تماما',
            '--base-currency' => 'ILS',
        ])
            ->expectsOutputToContain('Reusing existing bootstrap')
            ->assertSuccessful();

        // Must still have exactly 1 company (safe-first-bootstrap requirement)
        $this->assertDatabaseCount('companies', 1);
    }

    public function test_bootstrap_fails_for_non_existent_user(): void
    {
        $this->artisan('app:bootstrap-company', [
            '--email' => 'nonexistent@example.com',
            '--name-ar' => 'شركة جديدة',
        ])
            ->expectsOutputToContain('does not exist')
            ->assertFailed();
    }

    public function test_bootstrap_fails_for_invalid_currency(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.com']);

        $this->artisan('app:bootstrap-company', [
            '--email' => 'owner@example.com',
            '--name-ar' => 'شركة جديدة',
            '--base-currency' => 'EUR',
        ])->assertFailed();
    }
}
