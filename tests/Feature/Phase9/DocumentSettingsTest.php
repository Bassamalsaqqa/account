<?php

declare(strict_types=1);

namespace Tests\Feature\Phase9;

use App\Actions\Company\CreateCompanyAction;
use App\Domain\Sales\Documents\DocumentData;
use App\Livewire\Pages\Sales\Settings\DocumentSettingsForm;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\CompanyDocumentSettings;
use App\Models\CompanyUser;
use App\Models\User;
use App\Services\Sales\DocumentPresentation;
use App\Services\Sales\DocumentSettingsService;
use App\Support\Tenancy\CompanyContext;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DocumentSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected User $ownerA;

    protected Company $companyA;

    protected User $ownerB;

    protected Company $companyB;

    protected CompanyContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->context = app(CompanyContext::class);

        // Ensure permissions exist in test database
        Permission::findOrCreate('settings.company.view', 'web');
        Permission::findOrCreate('settings.documents.manage', 'web');

        $this->ownerA = User::factory()->create(['name' => 'Owner A', 'email' => 'owner_a@example.com']);
        $this->companyA = app(CreateCompanyAction::class)->execute($this->ownerA, [
            'name_ar' => 'شركة أ',
            'name_en' => 'Company A',
            'base_currency_code' => 'ILS',
            'default_locale' => 'ar',
            'timezone' => 'Asia/Hebron',
        ]);

        $this->ownerB = User::factory()->create(['name' => 'Owner B', 'email' => 'owner_b@example.com']);
        $this->companyB = app(CreateCompanyAction::class)->execute($this->ownerB, [
            'name_ar' => 'شركة ب',
            'name_en' => 'Company B',
            'base_currency_code' => 'USD',
            'default_locale' => 'en',
            'timezone' => 'Asia/Hebron',
        ]);

        // Default context to company A
        $this->context->setCompany($this->companyA, $this->ownerA);
    }

    private function createMemberWithPermissions(Company $company, array $permissions): User
    {
        $user = User::factory()->create();
        CompanyUser::create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'status' => 'active',
            'is_owner' => false,
            'joined_at' => now(),
        ]);

        setPermissionsTeamId($company->id);
        $roleName = 'CustomRole_'.uniqid();
        $role = Role::create([
            'name' => $roleName,
            'guard_name' => 'web',
            'company_id' => $company->id,
        ]);

        if (! empty($permissions)) {
            $role->givePermissionTo($permissions);
        }

        $user->assignRole($role);

        return $user;
    }

    public function test_authorization_requires_authenticated_actor(): void
    {
        Livewire::test(DocumentSettingsForm::class)->assertForbidden();
    }

    public function test_permission_intersection_both_required_on_mount_and_save(): void
    {
        // 1. Actor with NO permissions -> 403
        $actorNoPerms = $this->createMemberWithPermissions($this->companyA, []);
        $this->context->setCompany($this->companyA, $actorNoPerms);
        $this->actingAs($actorNoPerms);

        Livewire::test(DocumentSettingsForm::class)->assertStatus(403);

        // 2. Actor with ONLY settings.company.view -> 403
        $actorViewOnly = $this->createMemberWithPermissions($this->companyA, ['settings.company.view']);
        $this->context->setCompany($this->companyA, $actorViewOnly);
        $this->actingAs($actorViewOnly);

        Livewire::test(DocumentSettingsForm::class)->assertStatus(403);

        // 3. Actor with ONLY settings.documents.manage -> 403
        $actorManageOnly = $this->createMemberWithPermissions($this->companyA, ['settings.documents.manage']);
        $this->context->setCompany($this->companyA, $actorManageOnly);
        $this->actingAs($actorManageOnly);

        Livewire::test(DocumentSettingsForm::class)->assertStatus(403);

        // 4. Actor with BOTH permissions -> 200 and can save
        $actorBoth = $this->createMemberWithPermissions($this->companyA, [
            'settings.company.view',
            'settings.documents.manage',
        ]);
        $this->context->setCompany($this->companyA, $actorBoth);
        $this->actingAs($actorBoth);

        $test = Livewire::test(DocumentSettingsForm::class)
            ->assertStatus(200)
            ->set('default_document_locale', 'en')
            ->set('show_logo', false)
            ->set('show_qr_by_default', true)
            ->set('invoice_footer_ar', 'تذييل الفاتورة المعتمد')
            ->call('save')
            ->assertHasNoErrors()
            ->assertStatus(200);

        $this->assertSame('en', $this->companyA->fresh()->documentSettings->default_document_locale);
        $this->assertFalse((bool) $this->companyA->fresh()->documentSettings->show_logo);
        $this->assertTrue((bool) $this->companyA->fresh()->documentSettings->show_qr_by_default);
        $this->assertSame('تذييل الفاتورة المعتمد', $this->companyA->fresh()->documentSettings->invoice_footer_ar);
    }

    public function test_revoked_permission_after_mount_denies_save(): void
    {
        $actor = $this->createMemberWithPermissions($this->companyA, [
            'settings.company.view',
            'settings.documents.manage',
        ]);
        $this->context->setCompany($this->companyA, $actor);
        $this->actingAs($actor);

        $component = Livewire::test(DocumentSettingsForm::class)->assertStatus(200);

        // Revoke manage permission
        setPermissionsTeamId($this->companyA->id);
        $actor->roles()->first()->revokePermissionTo('settings.documents.manage');

        // Calling save must abort with 403
        $component->call('save')->assertStatus(403);
    }

    public function test_deactivated_membership_after_mount_denies_save(): void
    {
        $actor = $this->createMemberWithPermissions($this->companyA, [
            'settings.company.view',
            'settings.documents.manage',
        ]);
        $this->context->setCompany($this->companyA, $actor);
        $this->actingAs($actor);

        $component = Livewire::test(DocumentSettingsForm::class)->assertStatus(200);

        // Deactivate company user membership
        DB::table('company_user')
            ->where('company_id', $this->companyA->id)
            ->where('user_id', $actor->id)
            ->update(['status' => 'inactive']);

        $component->call('save')->assertStatus(403);
    }

    public function test_foreign_company_tampering_is_prevented(): void
    {
        $actor = $this->createMemberWithPermissions($this->companyA, [
            'settings.company.view',
            'settings.documents.manage',
        ]);
        $this->context->setCompany($this->companyA, $actor);
        $this->actingAs($actor);

        $component = Livewire::test(DocumentSettingsForm::class)->assertStatus(200);

        // Attacker switches active company context to company B
        CompanyUser::create(['company_id' => $this->companyB->id, 'user_id' => $actor->id, 'status' => 'active', 'is_owner' => false, 'joined_at' => now()]);
        $this->context->setCompany($this->companyB, $actor);

        // Component save must abort with 403 due to company mismatch with locked settingsCompanyId
        $component->call('save')->assertStatus(403);

        // Direct service call across company boundary throws exception
        $this->expectException(AuthorizationException::class);
        app(DocumentSettingsService::class)->save($this->companyA, $actor, [
            'default_document_locale' => 'ar',
            'show_logo' => true,
            'show_qr_by_default' => false,
            'show_product_images_on_quotes' => false,
        ]);
    }

    public function test_bounded_input_validation(): void
    {
        $actor = $this->createMemberWithPermissions($this->companyA, [
            'settings.company.view',
            'settings.documents.manage',
        ]);
        $this->context->setCompany($this->companyA, $actor);
        $this->actingAs($actor);

        $component = Livewire::test(DocumentSettingsForm::class)->assertStatus(200);

        // 1. Invalid locale
        $component->set('default_document_locale', 'fr')
            ->call('save')
            ->assertHasErrors(['default_document_locale']);

        // 2. Over-length footer (> 2000 chars)
        $component->set('default_document_locale', 'ar')
            ->set('invoice_footer_ar', str_repeat('أ', 2001))
            ->call('save')
            ->assertHasErrors(['invoice_footer_ar']);

        // 3. Over-length quotation terms (> 5000 chars)
        $component->set('invoice_footer_ar', 'Valid footer')
            ->set('quotation_terms_ar', str_repeat('ش', 5001))
            ->call('save')
            ->assertHasErrors(['quotation_terms_ar']);
    }

    public function test_html_tags_are_sanitized_in_footer_and_terms(): void
    {
        $actor = $this->createMemberWithPermissions($this->companyA, [
            'settings.company.view',
            'settings.documents.manage',
        ]);
        $this->context->setCompany($this->companyA, $actor);
        $this->actingAs($actor);

        Livewire::test(DocumentSettingsForm::class)
            ->set('default_document_locale', 'ar')
            ->set('invoice_footer_ar', '<script>alert("xss")</script><b>تذييل الفاتورة</b>')
            ->set('quotation_terms_en', '<style>body{color:red;}</style>Standard Terms Only')
            ->call('save')
            ->assertHasNoErrors();

        $settings = $this->companyA->fresh()->documentSettings;
        $this->assertSame('alert("xss")تذييل الفاتورة', $settings->invoice_footer_ar);
        $this->assertSame('body{color:red;}Standard Terms Only', $settings->quotation_terms_en);
        $this->assertStringNotContainsString('<script>', $settings->invoice_footer_ar);
        $this->assertStringNotContainsString('<b>', $settings->invoice_footer_ar);
    }

    public function test_preserving_other_company_rows(): void
    {
        // Company A settings
        $actorA = $this->createMemberWithPermissions($this->companyA, [
            'settings.company.view',
            'settings.documents.manage',
        ]);
        $this->context->setCompany($this->companyA, $actorA);
        $this->actingAs($actorA);

        // Update Company A settings
        Livewire::test(DocumentSettingsForm::class)
            ->set('default_document_locale', 'en')
            ->set('show_logo', false)
            ->set('invoice_footer_ar', 'Company A Unique Footer')
            ->call('save')
            ->assertHasNoErrors();

        // Check Company B settings were not modified
        $settingsB = CompanyScope::executeWithoutScope(fn () => CompanyDocumentSettings::where('company_id', $this->companyB->id)->firstOrFail());
        $this->assertSame('en', $settingsB->default_document_locale); // default from setup
        $this->assertTrue((bool) $settingsB->show_logo);
        $this->assertNull($settingsB->invoice_footer_ar);

        // Verify Company A has updated settings
        $settingsA = $this->companyA->fresh()->documentSettings;
        $this->assertSame('en', $settingsA->default_document_locale);
        $this->assertFalse((bool) $settingsA->show_logo);
        $this->assertSame('Company A Unique Footer', $settingsA->invoice_footer_ar);
    }

    public function test_audit_event_logged_with_safe_fields_only(): void
    {
        $actor = $this->createMemberWithPermissions($this->companyA, [
            'settings.company.view',
            'settings.documents.manage',
        ]);
        $this->context->setCompany($this->companyA, $actor);
        $this->actingAs($actor);

        Livewire::test(DocumentSettingsForm::class)
            ->set('default_document_locale', 'en')
            ->set('show_logo', true)
            ->set('show_qr_by_default', true)
            ->set('invoice_footer_ar', 'تذييل مدقق')
            ->call('save')
            ->assertHasNoErrors();

        $audit = AuditEvent::where('company_id', $this->companyA->id)
            ->where('event_key', 'settings.documents_updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame($actor->id, $audit->actor_user_id);
        $this->assertIsArray($audit->after_json);
        $this->assertArrayHasKey('default_document_locale', $audit->after_json);
        $this->assertArrayHasKey('show_logo', $audit->after_json);
        $this->assertArrayHasKey('show_qr_by_default', $audit->after_json);
        $this->assertArrayHasKey('invoice_footer_ar', $audit->after_json);
        $this->assertSame('en', $audit->after_json['default_document_locale']);
        $this->assertSame('تذييل مدقق', $audit->after_json['invoice_footer_ar']);
    }

    public function test_pdf_document_blade_renders_cleanly_with_presentation_and_dual_currency(): void
    {
        // 1. Standard Invoice with presentation options
        $docData = new DocumentData(
            type: 'sales_invoice',
            locale: 'ar',
            company: [
                'name' => 'شركة التاجر الصغير',
                'phone' => '0599123456',
                'email' => 'info@example.com',
                'tax_number' => '123456789',
                'address' => 'رام الله، فلسطين',
            ],
            customer: [
                'name' => 'زبون تجريبي',
                'phone' => '0599000000',
                'email' => 'client@example.com',
                'tax_number' => '987654321',
            ],
            document: [
                'number' => 'INV-2026-0001',
                'status' => 'posted',
                'issue_date' => '2026-10-09',
                'currency_code' => 'ILS',
                'subtotal' => '100.000000',
                'tax_total' => '16.000000',
                'grand_total' => '116.000000',
                'terms' => 'شروط سداد خلال 30 يوم',
            ],
            lines: [
                [
                    'item_description' => 'بند مبيعات اختباري',
                    'quantity' => '2',
                    'unit_name' => 'قطعة',
                    'sku' => 'SKU-001',
                    'unit_price' => '50.000000',
                    'total' => '100.000000',
                ],
            ],
            presentation: [
                'logo' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
                'footer' => 'شكراً لتعاملكم معنا',
                'show_qr' => true,
            ]
        );

        $html = view('pdf.document', ['data' => $docData, 'qrDataUri' => 'data:image/png;base64,qr-sample'])->render();

        $this->assertStringContainsString('شركة التاجر الصغير', $html);
        $this->assertStringContainsString('INV-2026-0001', $html);
        $this->assertStringContainsString('زبون تجريبي', $html);
        $this->assertStringContainsString('بند مبيعات اختباري', $html);
        $this->assertStringContainsString('116.00', $html);
        $this->assertStringContainsString('شكراً لتعاملكم معنا', $html);
        $this->assertStringContainsString('data:image/png;base64,iVBORw0K', $html);
        $this->assertStringContainsString('data:image/png;base64,qr-sample', $html);

        // 2. Customer Receipt with dual-currency legs and separate later applications
        $receiptData = new DocumentData(
            type: 'customer_payment',
            locale: 'ar',
            company: ['name' => 'شركة التاجر الصغير'],
            customer: ['name' => 'العميل'],
            document: [
                'number' => 'RCT-2026-0001',
                'status' => 'posted',
                'issue_date' => '2026-10-09',
                'currency_code' => 'USD',
                'grand_total' => '100.000000',
                'base_currency_code' => 'ILS',
                'exchange_rate' => '3.5000000000',
                'amount_base' => '350.000000',
            ],
            lines: [
                [
                    'item_description' => 'INV-001',
                    'currency_code' => 'ILS',
                    'unit_price' => '350.000000',
                    'total' => '350.000000',
                    'payment_currency_code' => 'USD',
                    'payment_currency_amount' => '100.000000',
                    'base_currency_code' => 'ILS',
                    'settlement_base_value' => '350.000000',
                ],
                [
                    'item_description' => 'INV-LATER-002',
                    'currency_code' => 'ILS',
                    'total' => '70.000000',
                    'payment_currency_code' => 'USD',
                    'payment_currency_amount' => '20.000000',
                    'is_later_application' => true,
                ],
            ]
        );

        $receiptHtml = view('pdf.document', ['data' => $receiptData, 'qrDataUri' => null])->render();

        $this->assertStringContainsString('RCT-2026-0001', $receiptHtml);
        $this->assertStringContainsString('INV-001', $receiptHtml);
        $this->assertStringContainsString('350.00', $receiptHtml); // Invoice principal in ILS
        $this->assertStringContainsString('100.00', $receiptHtml); // Payment applied in USD
        $this->assertStringContainsString('INV-LATER-002', $receiptHtml);
        // Later applications must be separated into their own distinct section
        $this->assertStringContainsString('later-applications-section', $receiptHtml);
    }

    public function test_logo_upload_is_bounded_and_company_owned(): void
    {
        Storage::fake('public');
        $this->actingAs($this->ownerA);
        Livewire::test(DocumentSettingsForm::class)
            ->set('logo', UploadedFile::fake()->image('logo.png', 120, 80))
            ->call('save')->assertHasNoErrors();
        $path = $this->companyA->fresh()->logo_path;
        $this->assertStringStartsWith('companies/'.$this->companyA->id.'/', $path);
        $this->assertStringStartsWith('data:image/png;base64,', app(DocumentPresentation::class)->forCompany($this->companyA->id, 'ar')['logo']);
        Livewire::test(DocumentSettingsForm::class)
            ->set('logo', UploadedFile::fake()->create('private.pdf', 20, 'application/pdf'))
            ->call('save')->assertHasErrors(['logo']);
        $this->assertSame($path, $this->companyA->fresh()->logo_path);
    }
}
