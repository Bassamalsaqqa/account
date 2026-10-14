<?php

declare(strict_types=1);

namespace Tests\Feature\ProductExperience;

use App\Actions\Company\CreateCompanyAction;
use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NavigationRedirectionTest extends TestCase
{
    use RefreshDatabase;

    protected CreateCompanyAction $createAction;

    protected CompanyContext $context;

    protected User $user;

    protected Company $companyA;

    protected Company $companyB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAction = app(CreateCompanyAction::class);
        $this->context = app(CompanyContext::class);

        $this->user = User::factory()->create([
            'name' => 'Navigation Test User',
            'email' => 'nav.user@example.com',
        ]);

        $this->companyA = $this->createAction->execute($this->user, [
            'name_ar' => 'شركة أ',
            'name_en' => 'Company A',
            'base_currency_code' => 'ILS',
        ]);

        $this->companyB = $this->createAction->execute($this->user, [
            'name_ar' => 'شركة ب',
            'name_en' => 'Company B',
            'base_currency_code' => 'USD',
        ]);
    }

    public function test_root_route_redirects_to_dashboard(): void
    {
        $response = $this->get('/');

        $response->assertRedirect(route('dashboard'));
    }

    public function test_fortify_home_configuration_is_dashboard(): void
    {
        $this->assertSame('/dashboard', config('fortify.home'));
    }

    public function test_unauthenticated_user_accessing_root_is_guarded_to_login_with_intended_dashboard(): void
    {
        // Visiting root redirects to dashboard
        $rootResponse = $this->get('/');
        $rootResponse->assertRedirect(route('dashboard'));

        // Following to dashboard as guest redirects to login with url.intended
        $dashboardResponse = $this->get(route('dashboard'));
        $dashboardResponse->assertRedirect(route('login'));
        $this->assertSame(route('dashboard'), session('url.intended'));
    }

    public function test_company_switch_redirects_to_dashboard_by_default(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['active_company_id' => $this->companyA->id])
            ->post(route('company.switch'), [
                'public_id' => $this->companyB->public_id,
            ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertSame($this->companyB->id, session('active_company_id'));
        $this->assertSame($this->companyB->id, $this->user->fresh()->last_active_company_id);
    }

    public function test_company_switch_preserves_intended_url_when_present(): void
    {
        $intendedUrl = route('reports.index');

        $response = $this->actingAs($this->user)
            ->withSession([
                'active_company_id' => $this->companyA->id,
                'url.intended' => $intendedUrl,
            ])
            ->post(route('company.switch'), [
                'public_id' => $this->companyB->public_id,
            ]);

        $response->assertRedirect($intendedUrl);
        $this->assertSame($this->companyB->id, session('active_company_id'));
    }

    public function test_ensure_company_context_preserves_intended_destination_when_redirecting_to_select(): void
    {
        // User with multiple companies and no active company in session/context
        $targetUrl = route('reports.index');
        $this->user->updateQuietly(['last_active_company_id' => null]);
        $this->context->clear();
        session()->forget('active_company_id');

        $response = $this->actingAs($this->user)
            ->get($targetUrl);

        $response->assertRedirect(route('companies.select'));
        $this->assertSame($targetUrl, session('url.intended'));
    }

    public function test_unauthenticated_company_switch_is_guarded(): void
    {
        $response = $this->post(route('company.switch'), [
            'public_id' => $this->companyA->public_id,
        ]);

        $response->assertRedirect(route('login'));
    }

    public function test_unauthorized_company_switch_to_unowned_company_is_rejected(): void
    {
        $otherUser = User::factory()->create(['email' => 'other.owner@example.com']);
        $otherCompany = $this->createAction->execute($otherUser, [
            'name_ar' => 'شركة أخرى',
            'name_en' => 'Other Company',
            'base_currency_code' => 'JOD',
        ]);

        $this->actingAs($this->user)
            ->withSession(['active_company_id' => $this->companyA->id]);

        $response = $this->post(route('company.switch'), [
            'public_id' => $otherCompany->public_id,
        ]);

        $response->assertForbidden();
    }
}
