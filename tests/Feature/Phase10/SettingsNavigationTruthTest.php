<?php

declare(strict_types=1);

namespace Tests\Feature\Phase10;

use App\Actions\Company\CreateCompanyAction;
use App\Livewire\Pages\Catalogs\CatalogComposer;
use App\Livewire\Pages\Sales\Settings\DocumentSettingsForm;
use App\Livewire\Pages\SettingsIndex;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;
use App\Services\Tenancy\CompanyRoleService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SettingsNavigationTruthTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Company $company;

    protected CompanyContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        app(CompanyRoleService::class)->ensurePermissionsExist();

        $this->context = app(CompanyContext::class);
        $this->owner = User::factory()->create([
            'name' => 'Owner User',
            'email' => 'owner@example.com',
            'locale' => 'ar',
        ]);

        $this->company = app(CreateCompanyAction::class)->execute($this->owner, [
            'name_ar' => 'شركة التاجر التجريبية',
            'name_en' => 'Small Trader Demo Co.',
            'base_currency_code' => 'ILS',
            'default_locale' => 'ar',
            'timezone' => 'Asia/Hebron',
        ]);

        $this->context->setCompany($this->company, $this->owner);
    }

    protected function createMemberWithPermissions(array $permissions, string $roleName = 'CustomRole'): User
    {
        $user = User::factory()->create([
            'last_active_company_id' => $this->company->id,
            'locale' => 'ar',
        ]);

        CompanyUser::create([
            'company_id' => $this->company->id,
            'user_id' => $user->id,
            'status' => 'active',
            'is_owner' => false,
            'joined_at' => now(),
        ]);

        setPermissionsTeamId($this->company->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->id);

        $role = Role::firstOrCreate([
            'name' => $roleName.'_'.uniqid(),
            'guard_name' => 'web',
            'company_id' => $this->company->id,
        ]);

        if (! empty($permissions)) {
            $role->givePermissionTo($permissions);
        }

        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    protected function createViewer(): User
    {
        $user = User::factory()->create([
            'last_active_company_id' => $this->company->id,
            'locale' => 'ar',
        ]);

        CompanyUser::create([
            'company_id' => $this->company->id,
            'user_id' => $user->id,
            'status' => 'active',
            'is_owner' => false,
            'joined_at' => now(),
        ]);

        setPermissionsTeamId($this->company->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->id);

        $role = Role::where('company_id', $this->company->id)->where('name', 'Viewer')->first();
        if ($role !== null) {
            $user->assignRole($role);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    public function test_owner_sees_active_print_and_sharing_navigation_links(): void
    {
        $response = $this->actingAs($this->owner)->get(route('settings.index'));

        $response->assertOk();
        $response->assertSee(route('settings.documents'));
        $response->assertSee(route('catalogs.index'));
        $response->assertSee(__('settings.card_print_title'));
        $response->assertSee(__('settings.card_print_desc'));
        $response->assertSee(__('settings.card_print_future_note'));
        $response->assertSee(__('settings.card_share_title'));
        $response->assertSee(__('settings.card_share_desc'));
        $response->assertSee(__('settings.card_share_future_note'));

        Livewire::actingAs($this->owner)
            ->test(SettingsIndex::class)
            ->assertSeeHtml(route('settings.documents'))
            ->assertSeeHtml(route('catalogs.index'))
            ->assertSee(__('settings.card_print_future_note'))
            ->assertSee(__('settings.card_share_future_note'));
    }

    public function test_owner_can_navigate_to_document_settings_and_catalogs(): void
    {
        $docResponse = $this->actingAs($this->owner)->get(route('settings.documents'));
        $docResponse->assertOk();

        Livewire::actingAs($this->owner)
            ->test(DocumentSettingsForm::class)
            ->assertOk();

        $catalogResponse = $this->actingAs($this->owner)->get(route('catalogs.index'));
        $catalogResponse->assertOk();

        Livewire::actingAs($this->owner)
            ->test(CatalogComposer::class)
            ->assertOk();
    }

    public function test_viewer_sees_restricted_cards_and_cannot_access_routes(): void
    {
        $viewer = $this->createViewer();
        $this->context->setCompany($this->company, $viewer);

        $response = $this->actingAs($viewer)->get(route('settings.index'));

        $response->assertOk();
        $response->assertDontSee(route('settings.documents'));
        $response->assertDontSee(route('catalogs.index'));
        $response->assertSee(__('settings.restricted_card_notice'));

        $this->actingAs($viewer)->get(route('settings.documents'))->assertForbidden();
        $this->actingAs($viewer)->get(route('catalogs.index'))->assertForbidden();

        Livewire::actingAs($viewer)
            ->test(DocumentSettingsForm::class)
            ->assertForbidden();

        Livewire::actingAs($viewer)
            ->test(CatalogComposer::class)
            ->assertForbidden();
    }

    public function test_user_with_delegated_document_permissions_can_access_print_card(): void
    {
        $docAdmin = $this->createMemberWithPermissions([
            'settings.company.view',
            'settings.documents.manage',
        ]);
        $this->context->setCompany($this->company, $docAdmin);

        $response = $this->actingAs($docAdmin)->get(route('settings.index'));

        $response->assertOk();
        $response->assertSee(route('settings.documents'));
        $response->assertDontSee(route('catalogs.index'));

        $this->actingAs($docAdmin)->get(route('settings.documents'))->assertOk();
        $this->actingAs($docAdmin)->get(route('catalogs.index'))->assertForbidden();
    }

    public function test_user_with_delegated_catalog_permissions_can_access_share_card(): void
    {
        $catalogManager = $this->createMemberWithPermissions([
            'settings.company.view',
            'catalogs.view',
            'inventory.stock.view',
        ]);
        $this->context->setCompany($this->company, $catalogManager);

        $response = $this->actingAs($catalogManager)->get(route('settings.index'));

        $response->assertOk();
        $response->assertSee(route('catalogs.index'));
        $response->assertDontSee(route('settings.documents'));

        $this->actingAs($catalogManager)->get(route('catalogs.index'))->assertOk();
        $this->actingAs($catalogManager)->get(route('settings.documents'))->assertForbidden();
    }

    public function test_user_with_only_manage_without_company_view_is_restricted(): void
    {
        $manageOnlyUser = $this->createMemberWithPermissions([
            'settings.documents.manage',
        ]);
        $this->context->setCompany($this->company, $manageOnlyUser);

        // Without settings.company.view, access to settings.index is forbidden by CompanyPolicy
        $this->actingAs($manageOnlyUser)->get(route('settings.index'))->assertForbidden();

        // And direct access to settings.documents is also forbidden
        $this->actingAs($manageOnlyUser)->get(route('settings.documents'))->assertForbidden();
    }

    public function test_catalog_reader_without_product_selection_authority_has_no_dead_navigation_link(): void
    {
        $reader = $this->createMemberWithPermissions(['settings.company.view', 'catalogs.view']);
        $this->context->setCompany($this->company, $reader);

        $this->actingAs($reader)->get(route('settings.index'))->assertOk()->assertDontSee(route('catalogs.index'));
        $this->actingAs($reader)->get(route('catalogs.index'))->assertForbidden();
    }

    public function test_product_manager_catalog_reader_can_use_the_existing_selection_policy_alternative(): void
    {
        $reader = $this->createMemberWithPermissions(['settings.company.view', 'catalogs.view', 'inventory.product.manage']);
        $this->context->setCompany($this->company, $reader);

        $this->actingAs($reader)->get(route('settings.index'))->assertOk()->assertSee(route('catalogs.index'));
        $this->actingAs($reader)->get(route('catalogs.index'))->assertOk();
    }

    public function test_bilingual_navigation_copy_truth_in_arabic_and_english(): void
    {
        // Arabic locale
        app()->setLocale('ar');
        session(['locale' => 'ar']);
        $arResponse = $this->actingAs($this->owner)->get(route('settings.index'));
        $arResponse->assertOk();
        $arResponse->assertSee(__('settings.card_print_title', [], 'ar'));
        $arResponse->assertSee(__('settings.card_print_desc', [], 'ar'));
        $arResponse->assertSee(__('settings.card_print_future_note', [], 'ar'));
        $arResponse->assertSee(__('settings.card_share_title', [], 'ar'));
        $arResponse->assertSee(__('settings.card_share_desc', [], 'ar'));
        $arResponse->assertSee(__('settings.card_share_future_note', [], 'ar'));

        // English locale
        app()->setLocale('en');
        session(['locale' => 'en']);
        $enResponse = $this->actingAs($this->owner)->get(route('settings.index'));
        $enResponse->assertOk();
        $enResponse->assertSee(__('settings.card_print_title', [], 'en'));
        $enResponse->assertSee(__('settings.card_print_desc', [], 'en'));
        $enResponse->assertSee(__('settings.card_print_future_note', [], 'en'));
        $enResponse->assertSee(__('settings.card_share_title', [], 'en'));
        $enResponse->assertSee(__('settings.card_share_desc', [], 'en'));
        $enResponse->assertSee(__('settings.card_share_future_note', [], 'en'));
    }
}
