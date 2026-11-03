<?php

declare(strict_types=1);

namespace Tests\Feature\Phase10;

use App\Actions\Company\CreateCompanyAction;
use App\Livewire\Pages\Catalogs\CatalogComposer;
use App\Livewire\Pages\Sales\Settings\TaxRateSettings;
use App\Models\Catalog;
use App\Models\Company;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Tenancy\CompanyRoleService;
use App\Support\Settings\SettingsNavigation;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SharedSettingsShellTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected User $ownerB;

    protected Company $companyA;

    protected Company $companyB;

    protected CompanyContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        app(CompanyRoleService::class)->ensurePermissionsExist();

        $this->context = app(CompanyContext::class);

        $this->owner = User::factory()->create([
            'name' => 'مالك الشركة أ',
            'email' => 'owner_a@example.com',
            'locale' => 'ar',
        ]);

        $this->companyA = app(CreateCompanyAction::class)->execute($this->owner, [
            'name_ar' => 'شركة التجارة الأولى',
            'name_en' => 'First Trading Co.',
            'base_currency_code' => 'ILS',
            'default_locale' => 'ar',
            'timezone' => 'Asia/Hebron',
        ]);

        $this->ownerB = User::factory()->create([
            'name' => 'مالك الشركة ب',
            'email' => 'owner_b@example.com',
            'locale' => 'ar',
        ]);

        $this->companyB = app(CreateCompanyAction::class)->execute($this->ownerB, [
            'name_ar' => 'شركة التجارة الثانية',
            'name_en' => 'Second Trading Co.',
            'base_currency_code' => 'USD',
            'default_locale' => 'ar',
            'timezone' => 'Asia/Hebron',
        ]);

        $this->context->setCompany($this->companyA, $this->owner);
    }

    public function test_user_without_company_can_access_profile_and_sees_only_personal_security(): void
    {
        $soloUser = User::factory()->create([
            'name' => 'مستخدم مستقل',
            'email' => 'solo@example.com',
            'locale' => 'ar',
            'last_active_company_id' => null,
        ]);

        // Clear tenancy context completely
        $this->context->clear();

        // 1. Profile must load 200 OK without requiring company.ensure
        $response = $this->actingAs($soloUser)->get(route('profile'));
        $response->assertOk();
        $response->assertSee(__('settings.personal_security'));

        // 2. Navigation sections must not query company data or expose tenant settings
        $sections = SettingsNavigation::sections(null, $soloUser);
        $this->assertCount(1, $sections);
        $this->assertArrayHasKey('profile', $sections);
        $this->assertArrayNotHasKey('identity', $sections);
        $this->assertArrayNotHasKey('taxes', $sections);
        $this->assertArrayNotHasKey('documents', $sections);
        $this->assertArrayNotHasKey('catalogs', $sections);

        // 3. Visiting tenant settings must be forbidden/redirected
        $this->actingAs($soloUser)->get(route('settings.index'))->assertRedirect();
    }

    public function test_tax_cross_company_refusal_and_tenant_isolation(): void
    {
        // Create tax rate in Company B within Company B context
        $this->context->setCompany($this->companyB, $this->ownerB);
        $taxB = TaxRate::create([
            'company_id' => $this->companyB->id,
            'code' => 'VAT-B',
            'name_ar' => 'ضريبة الشركة ب',
            'name_en' => 'Tax Co B',
            'rate' => '16.00',
            'calculation' => 'exclusive',
            'active' => true,
        ]);
        // Switch back to Company A
        $this->context->setCompany($this->companyA, $this->owner);

        // User in Company A visits taxes
        $response = $this->actingAs($this->owner)->get(route('settings.taxes'));
        $response->assertOk();
        $response->assertDontSee('VAT-B');
        $response->assertDontSee('ضريبة الشركة ب');

        // Livewire component refuses to edit Company B's tax rate
        Livewire::actingAs($this->owner)
            ->test(TaxRateSettings::class)
            ->call('editTaxRate', $taxB->id)
            ->assertSet('editingTaxId', null)
            ->assertSet('code', '');
    }

    public function test_operational_settings_pages_have_authorized_navigation_entries(): void
    {
        $sections = SettingsNavigation::sections($this->companyA, $this->owner);

        foreach (['sequences', 'money_accounts', 'purchases'] as $key) {
            $this->assertArrayHasKey($key, $sections);
            $this->assertFalse($sections[$key]['is_livewire_section']);
            $this->assertNotSame($key, $sections[$key]['label']);
        }

        $soloUser = User::factory()->create();
        $this->assertSame(['profile'], array_keys(SettingsNavigation::sections($this->companyA, $soloUser)));
    }

    public function test_catalog_context_and_revision_preservation(): void
    {
        $catalog = Catalog::create([
            'company_id' => $this->companyA->id,
            'public_id' => 'cat-live-test-01',
            'name_ar' => 'كتالوج معتمد',
            'created_by' => $this->owner->id,
            'updated_by' => $this->owner->id,
            'status' => 'draft',
            'draft_revision' => 2,
            'published_revision' => 1,
            'published_payload' => ['currency_code' => 'ILS', 'name_ar' => 'كتالوج معتمد'],
        ]);

        // Access via catalogs route
        $response = $this->actingAs($this->owner)->get(route('catalogs.edit', ['publicId' => $catalog->public_id]));
        $response->assertOk();
        $response->assertSee(__('settings.breadcrumb_settings'));
        $response->assertSee($catalog->public_id);

        // Verify component locks to correct company and preserves revisions
        Livewire::actingAs($this->owner)
            ->test(CatalogComposer::class, ['publicId' => $catalog->public_id])
            ->assertSet('companyId', $this->companyA->id)
            ->assertSet('draftRevision', 2)
            ->assertSet('publishedRevision', 1)
            ->assertSet('publicId', 'cat-live-test-01');
    }

    public function test_settings_index_redirects_taxes_catalogs_and_profile_smoothly(): void
    {
        $this->actingAs($this->owner)
            ->get(route('settings.index', ['section' => 'taxes']))
            ->assertRedirect(route('settings.taxes'));

        $this->actingAs($this->owner)
            ->get(route('settings.index', ['section' => 'catalogs']))
            ->assertRedirect(route('catalogs.index'));

        $this->actingAs($this->owner)
            ->get(route('settings.index', ['section' => 'profile']))
            ->assertRedirect(route('profile'));
    }
}
