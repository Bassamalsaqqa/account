<?php

namespace Tests\Feature\Phase1;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Company\UpdateCompanyLocalizationAction;
use App\Livewire\Pages\SettingsIndex;
use App\Models\Company;
use App\Models\CompanyDocumentSettings;
use App\Models\CompanyLanguage;
use App\Models\CompanyUser;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Drawer\Utils;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CompanyLanguagePolicyTest extends TestCase
{
    use RefreshDatabase;

    protected CreateCompanyAction $createCompany;

    protected CompanyContext $context;

    protected User $owner;

    protected Company $companyA;

    protected Company $companyB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createCompany = app(CreateCompanyAction::class);
        $this->context = app(CompanyContext::class);

        // Owner with preference 'en'
        $this->owner = User::factory()->create(['locale' => 'en']);

        // Company A: English enabled, default 'en'
        $this->companyA = $this->createCompany->execute($this->owner, [
            'name_ar' => 'شركة أ',
            'name_en' => 'Company A',
            'base_currency_code' => 'ILS',
            'default_locale' => 'en',
        ]);

        // Company B: English will be disabled
        $otherOwner = User::factory()->create();
        $this->companyB = $this->createCompany->execute($otherOwner, [
            'name_ar' => 'شركة ب',
            'name_en' => 'Company B',
            'base_currency_code' => 'USD',
            'default_locale' => 'ar',
        ]);

        // Add $this->owner to Company B as active member and Owner role
        CompanyUser::create([
            'company_id' => $this->companyB->id,
            'user_id' => $this->owner->id,
            'status' => 'active',
            'is_owner' => true,
            'joined_at' => now(),
        ]);
        setPermissionsTeamId($this->companyB->id);
        $ownerRoleB = Role::where('company_id', $this->companyB->id)->where('name', 'Owner')->firstOrFail();
        $this->owner->assignRole($ownerRoleB);

        // Disable English in Company B
        $this->context->setCompany($this->companyB, $otherOwner);
        app(UpdateCompanyLocalizationAction::class)->execute(
            $this->companyB,
            'ar',
            'Asia/Hebron',
            false,
            $otherOwner
        );
        $this->context->clear();
    }

    public function test_arabic_is_always_enabled_and_english_can_be_disabled(): void
    {
        $this->assertTrue($this->companyA->isLanguageEnabled('ar'));
        $this->assertTrue($this->companyA->isLanguageEnabled('en'));

        $this->assertTrue($this->companyB->isLanguageEnabled('ar'));
        $this->assertFalse($this->companyB->isLanguageEnabled('en'));

        // Verify company_languages records
        $this->context->setCompany($this->companyB, $this->owner);
        $arLang = CompanyLanguage::where('locale', 'ar')->firstOrFail();
        $enLang = CompanyLanguage::where('locale', 'en')->firstOrFail();

        $this->assertTrue($arLang->enabled);
        $this->assertFalse($enLang->enabled);
    }

    public function test_disabling_english_when_default_normalizes_company_and_document_defaults_to_arabic(): void
    {
        $this->context->setCompany($this->companyA, $this->owner);

        // Company A starts with default 'en'
        $this->assertSame('en', $this->companyA->default_locale);
        $docSettings = CompanyDocumentSettings::where('company_id', $this->companyA->id)->firstOrFail();
        $docSettings->update(['default_document_locale' => 'en']);
        $this->assertSame('en', $docSettings->fresh()->default_document_locale);

        // Disable English
        $action = app(UpdateCompanyLocalizationAction::class);
        $action->execute($this->companyA, 'en', 'Asia/Hebron', false, $this->owner);

        $this->companyA->refresh();
        $this->assertSame('ar', $this->companyA->default_locale);

        // Document settings normalized to 'ar'
        $this->assertSame('ar', $docSettings->fresh()->default_document_locale);

        // Exactly one default in CompanyLanguage
        $this->context->setCompany($this->companyA, $this->owner);
        $defaultLanguages = CompanyLanguage::where('is_default', true)->get();
        $this->assertCount(1, $defaultLanguages);
        $this->assertSame('ar', $defaultLanguages->first()->locale);
        $this->assertTrue($defaultLanguages->first()->enabled);
    }

    public function test_disabling_english_does_not_overwrite_user_stored_locale_preference(): void
    {
        $this->assertSame('en', $this->owner->locale);

        // Switch to Company B where English is disabled
        $response = $this->actingAs($this->owner)
            ->withSession(['active_company_id' => $this->companyB->id])
            ->get(route('settings.index'));

        $response->assertOk();

        // Stored user locale preference must NOT be overwritten
        $this->assertSame('en', $this->owner->fresh()->locale);
    }

    public function test_user_sees_english_in_company_enabling_it_and_arabic_in_company_disabling_it_and_english_on_return(): void
    {
        // 1. Visit Company A (English enabled) -> User sees English LTR
        $responseA = $this->actingAs($this->owner)
            ->withSession(['active_company_id' => $this->companyA->id])
            ->get(route('settings.index'));

        $responseA->assertOk();
        $responseA->assertSee('dir="ltr"', false);
        $this->assertSame('en', app()->getLocale());

        // 2. Switch to Company B (English disabled) -> User sees Arabic RTL
        $switchResponse = $this->actingAs($this->owner)
            ->withSession(['active_company_id' => $this->companyA->id])
            ->post(route('company.switch'), [
                'public_id' => $this->companyB->public_id,
            ]);

        $switchResponse->assertRedirect(route('settings.index'));

        $responseB = $this->actingAs($this->owner)
            ->withSession(['active_company_id' => $this->companyB->id])
            ->get(route('settings.index'));

        $responseB->assertOk();
        $responseB->assertSee('dir="rtl"', false);
        $this->assertSame('ar', app()->getLocale());
        $this->assertSame('en', $this->owner->fresh()->locale);

        // 3. Switch back to Company A -> User sees English again
        $switchBackResponse = $this->actingAs($this->owner)
            ->withSession(['active_company_id' => $this->companyB->id])
            ->post(route('company.switch'), [
                'public_id' => $this->companyA->public_id,
            ]);

        $switchBackResponse->assertRedirect(route('settings.index'));

        $responseReturnA = $this->actingAs($this->owner)
            ->withSession(['active_company_id' => $this->companyA->id])
            ->get(route('settings.index'));

        $responseReturnA->assertOk();
        $responseReturnA->assertSee('dir="ltr"', false);
        $this->assertSame('en', app()->getLocale());
    }

    public function test_guest_zero_company_and_selection_states_follow_user_or_session_locale_normally(): void
    {
        // Guest follows session locale
        $guestResponse = $this->withSession(['locale' => 'en'])->get('/login');
        $guestResponse->assertOk();
        $this->assertSame('en', app()->getLocale());

        // User without company follows user preference
        $zeroCompanyUser = User::factory()->create(['locale' => 'en']);
        $setupResponse = $this->actingAs($zeroCompanyUser)->get(route('companies.setup'));
        $setupResponse->assertOk();
        $this->assertSame('en', app()->getLocale());
    }

    public function test_post_locale_switch_refuses_disabled_english(): void
    {
        // When in Company B (where English is disabled), posting 'en' must fail
        $response = $this->actingAs($this->owner)
            ->withSession(['active_company_id' => $this->companyB->id])
            ->post(route('locale.switch'), [
                'locale' => 'en',
            ]);

        $response->assertSessionHasErrors('locale');
        $this->assertNotSame('en', session('locale'));
    }

    public function test_post_locale_switch_allows_arabic(): void
    {
        $response = $this->actingAs($this->owner)
            ->withSession(['active_company_id' => $this->companyB->id])
            ->post(route('locale.switch'), [
                'locale' => 'ar',
            ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame('ar', session('locale'));
    }

    public function test_post_locale_switch_remains_usable_without_active_company(): void
    {
        $this->context->clear();

        // Without active company, English is allowed
        $responseEn = $this->post(route('locale.switch'), [
            'locale' => 'en',
        ]);
        $responseEn->assertSessionHasNoErrors();
        $this->assertSame('en', session('locale'));

        // Arabic is also allowed
        $responseAr = $this->post(route('locale.switch'), [
            'locale' => 'ar',
        ]);
        $responseAr->assertSessionHasNoErrors();
        $this->assertSame('ar', session('locale'));
    }

    public function test_no_state_changing_get_locale_route_exists(): void
    {
        $response = $this->get('/locale?locale=en');
        $this->assertTrue(in_array($response->status(), [404, 405], true));
    }

    public function test_livewire_settings_saves_english_enabled_toggle_and_normalizes_default(): void
    {
        $this->context->setCompany($this->companyA, $this->owner);

        Livewire::actingAs($this->owner)
            ->test(SettingsIndex::class)
            ->set('english_enabled', false)
            ->call('saveLocalization')
            ->assertHasNoErrors()
            ->assertSet('default_locale', 'ar');

        $this->companyA->refresh();
        $this->assertFalse($this->companyA->isLanguageEnabled('en'));
        $this->assertSame('ar', $this->companyA->default_locale);
    }

    public function test_tenant_isolation_of_language_settings_between_companies(): void
    {
        // Company A has English enabled
        $this->assertTrue($this->companyA->isLanguageEnabled('en'));

        // Company B has English disabled
        $this->assertFalse($this->companyB->isLanguageEnabled('en'));

        // Modifying Company A does not affect Company B
        $this->context->setCompany($this->companyA, $this->owner);
        app(UpdateCompanyLocalizationAction::class)->execute(
            $this->companyA,
            'ar',
            'Asia/Jerusalem',
            false,
            $this->owner
        );

        $this->assertFalse($this->companyA->fresh()->isLanguageEnabled('en'));
        $this->assertFalse($this->companyB->fresh()->isLanguageEnabled('en'));
    }

    public function test_real_livewire_request_resolves_company_before_effective_locale(): void
    {
        // 1. Real HTTP Livewire GET and update request for Company A (English enabled)
        $responseA = $this->actingAs($this->owner)
            ->withSession(['active_company_id' => $this->companyA->id])
            ->get(route('settings.index'));

        $responseA->assertOk();
        $this->assertSame('en', app()->getLocale());
        $snapshotA = Utils::extractAttributeDataFromHtml($responseA->getContent(), 'wire:snapshot');

        $postA = $this->actingAs($this->owner)
            ->withSession(['active_company_id' => $this->companyA->id])
            ->withHeaders(['X-Livewire' => 'true'])
            ->postJson(route('default-livewire.update'), [
                '_token' => csrf_token(),
                'components' => [
                    [
                        'snapshot' => json_encode($snapshotA),
                        'updates' => [],
                        'calls' => [],
                    ],
                ],
            ]);

        $postA->assertOk();
        $this->assertSame('en', app()->getLocale());

        // 2. Real HTTP Livewire GET and update request for Company B (English disabled)
        $responseB = $this->actingAs($this->owner)
            ->withSession(['active_company_id' => $this->companyB->id])
            ->get(route('settings.index'));

        $responseB->assertOk();
        $this->assertSame('ar', app()->getLocale());
        $snapshotB = Utils::extractAttributeDataFromHtml($responseB->getContent(), 'wire:snapshot');

        $postB = $this->actingAs($this->owner)
            ->withSession(['active_company_id' => $this->companyB->id])
            ->withHeaders(['X-Livewire' => 'true'])
            ->postJson(route('default-livewire.update'), [
                '_token' => csrf_token(),
                'components' => [
                    [
                        'snapshot' => json_encode($snapshotB),
                        'updates' => [],
                        'calls' => [],
                    ],
                ],
            ]);

        $postB->assertOk();
        $this->assertSame('ar', app()->getLocale());
        $this->assertSame('en', $this->owner->fresh()->locale);
    }

    public function test_app_shell_hides_english_switch_for_english_disabled_company_and_shows_on_return(): void
    {
        // 1. Visit under Company B (English disabled)
        // User sees Arabic RTL, and the shell does NOT offer a switch to English
        $responseB = $this->actingAs($this->owner)
            ->withSession(['active_company_id' => $this->companyB->id])
            ->get(route('settings.index'));

        $responseB->assertOk();
        $responseB->assertDontSee('name="locale" value="en"', false);
        $responseB->assertDontSee('<span class="font-bold">EN</span>', false);

        // 2. Switch to Company A (English enabled, default 'en')
        // User sees English LTR, and the shell offers a switch to Arabic
        $responseA = $this->actingAs($this->owner)
            ->withSession(['active_company_id' => $this->companyA->id])
            ->get(route('settings.index'));

        $responseA->assertOk();
        $responseA->assertSee('name="locale" value="ar"', false);
        $responseA->assertSee('<span class="font-bold">عربي</span>', false);

        // Switch locale in Company A to Arabic, verify English switch appears in Company A
        $responseAInAr = $this->actingAs($this->owner)
            ->withSession(['active_company_id' => $this->companyA->id, 'locale' => 'ar'])
            ->get(route('settings.index'));

        $responseAInAr->assertOk();
        $responseAInAr->assertSee('name="locale" value="en"', false);
        $responseAInAr->assertSee('<span class="font-bold">EN</span>', false);

        // 3. Return to Company B (English disabled)
        // Verify English switch is absent again
        $responseReturnB = $this->actingAs($this->owner)
            ->withSession(['active_company_id' => $this->companyB->id])
            ->get(route('settings.index'));

        $responseReturnB->assertOk();
        $responseReturnB->assertDontSee('name="locale" value="en"', false);
        $responseReturnB->assertDontSee('<span class="font-bold">EN</span>', false);

        // 4. Guest / zero-company flow retains normal language switcher in guest layout
        auth()->logout();
        $guestResponse = $this->get(route('login'));
        $guestResponse->assertOk();
        $guestResponse->assertSee(route('locale.switch'));
        $guestResponse->assertSee('name="locale"', false);
    }
}
