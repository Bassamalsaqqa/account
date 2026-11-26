<?php

declare(strict_types=1);

namespace Tests\Feature\Phase9;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Livewire\FinancialShareManager;
use App\Livewire\Pages\Sales\CustomerStatementView;
use App\Models\Customer;
use App\Models\PublicShare;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Sales\PublicShareService;
use App\Support\Tenancy\CompanyContext;
use Carbon\Carbon;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Exceptions\EventHandlerDoesNotExist;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\Feature\Phase5E\Phase5ETestCase;

class CustomerStatementShareConsolidationTest extends Phase5ETestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('financial-ip:'.hash('sha256', '127.0.0.1'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return list<array{string}> */
    public static function locales(): array
    {
        return [['ar'], ['en']];
    }

    private function createCustomer(string $suffix = 'A', bool $active = true): Customer
    {
        return Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => 'عميل كشف '.$suffix,
            'name_en' => 'Statement Customer '.$suffix,
            'active' => $active,
            'created_by' => $this->owner->id,
        ]);
    }

    private function createPostedInvoice(Customer $customer, string $issueDate = '2026-03-15'): SalesInvoice
    {
        $draft = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1',
            'issue_date' => $issueDate,
            'lines' => [
                [
                    'item_description' => 'Statement item',
                    'quantity' => '2',
                    'unit_price' => '50',
                ],
            ],
        ]);

        return app(PostSalesInvoiceAction::class)->execute($draft, $this->owner);
    }

    public function test_legacy_parent_share_bar_and_dead_actions_are_removed_and_all_three_permissions_gate_manager(): void
    {
        $reflection = new ReflectionClass(CustomerStatementView::class);
        foreach (['createShareLink', 'revokeShareLink'] as $deadMethod) {
            $this->assertFalse($reflection->hasMethod($deadMethod), "Legacy method {$deadMethod} should be removed.");
        }
        foreach (['shareUrl', 'shareExpiryDays', 'sharePassword'] as $deadProperty) {
            $this->assertFalse($reflection->hasProperty($deadProperty), "Legacy property {$deadProperty} should be removed.");
        }

        $customer = $this->createCustomer();
        $this->createPostedInvoice($customer);

        $ownerView = Livewire::test(CustomerStatementView::class, ['publicId' => $customer->public_id]);
        $ownerHtml = $ownerView->html(false);
        $this->assertStringNotContainsString('createShareLink', $ownerHtml);
        $this->assertStringNotContainsString('revokeShareLink', $ownerHtml);
        $this->assertStringNotContainsString('shareExpiryDays', $ownerHtml);
        $this->assertStringNotContainsString('sharePassword', $ownerHtml);
        $this->assertStringContainsString(__('sharing.manage'), $ownerHtml);

        // Missing customers.statement.view: can view statement page, cannot see or mount share manager.
        $noCustomerStatementPerm = $this->customActor(['sales.statement.view', 'sales.document.share'], 'NoCustStmt');
        $this->activate($noCustomerStatementPerm);
        $htmlNoCustPerm = Livewire::test(CustomerStatementView::class, ['publicId' => $customer->public_id])
            ->assertStatus(200)
            ->html(false);
        $this->assertStringNotContainsString(__('sharing.manage'), $htmlNoCustPerm);
        Livewire::test(FinancialShareManager::class, [
            'subjectType' => PublicShare::SUBJECT_CUSTOMER_STATEMENT,
            'subjectId' => $customer->id,
        ])->assertForbidden();

        // Missing sales.document.share: can view statement page, cannot see or mount share manager.
        $noSharePerm = $this->customActor(['sales.statement.view', 'customers.statement.view'], 'NoSharePerm');
        $this->activate($noSharePerm);
        $htmlNoSharePerm = Livewire::test(CustomerStatementView::class, ['publicId' => $customer->public_id])
            ->assertStatus(200)
            ->html(false);
        $this->assertStringNotContainsString(__('sharing.manage'), $htmlNoSharePerm);
        Livewire::test(FinancialShareManager::class, [
            'subjectType' => PublicShare::SUBJECT_CUSTOMER_STATEMENT,
            'subjectId' => $customer->id,
        ])->assertForbidden();

        // Missing sales.statement.view: denied on both statement page and share manager.
        $noSalesStmtPerm = $this->customActor(['sales.document.share', 'customers.statement.view'], 'NoSalesStmt');
        $this->activate($noSalesStmtPerm);
        Livewire::test(CustomerStatementView::class, ['publicId' => $customer->public_id])->assertForbidden();
        Livewire::test(FinancialShareManager::class, [
            'subjectType' => PublicShare::SUBJECT_CUSTOMER_STATEMENT,
            'subjectId' => $customer->id,
        ])->assertForbidden();

        // Actor with all 3 permissions can mount and see share manager.
        $allThree = $this->customActor(['sales.document.share', 'sales.statement.view', 'customers.statement.view'], 'AllThree');
        $this->activate($allThree);
        $htmlAllThree = Livewire::test(CustomerStatementView::class, ['publicId' => $customer->public_id])
            ->assertStatus(200)
            ->html(false);
        $this->assertStringContainsString(__('sharing.manage'), $htmlAllThree);
    }

    public function test_parent_url_date_initialization_empty_query_fallback_and_scoped_event_updates(): void
    {
        $this->company->update(['timezone' => 'Asia/Hebron']);
        Carbon::setTestNow(Carbon::parse('2026-06-15 10:00:00', 'UTC'));
        $customer = $this->createCustomer();
        $this->createPostedInvoice($customer, '2026-03-15');

        // 1. Explicit URL query params initialize both parent and embedded share manager.
        $page = Livewire::withQueryParams(['from' => '2026-03-01', 'to' => '2026-03-31'])
            ->test(CustomerStatementView::class, ['publicId' => $customer->public_id])
            ->assertSet('fromDate', '2026-03-01')
            ->assertSet('toDate', '2026-03-31');

        $childSnapshot = $this->extractChildSnapshot($page->html(false), 'financial-share-manager');
        $this->assertNotNull($childSnapshot);
        $this->assertSame('2026-03-01', $childSnapshot['data']['from']);
        $this->assertSame('2026-03-31', $childSnapshot['data']['to']);
        $this->assertSame($customer->public_id, $childSnapshot['data']['subjectPublicId']);

        // 2. Empty query params ?from=&to= normalize to null and default in company timezone.
        Livewire::withQueryParams(['from' => '', 'to' => ''])
            ->test(CustomerStatementView::class, ['publicId' => $customer->public_id])
            ->assertSet('fromDate', '2026-01-01')
            ->assertSet('toDate', '2026-06-15');

        // 3. Parent date update keeps child wire:key stable (no remount) and dispatches scoped event.
        $expectedEvent = 'customer-statement-dates-updated.customer_statement.'.$customer->id;
        $page->set('fromDate', '2026-04-01')
            ->assertDispatchedTo(
                FinancialShareManager::class,
                $expectedEvent,
                subjectType: 'customer_statement',
                subjectId: $customer->id,
                publicId: $customer->public_id,
                fromDate: '2026-04-01',
                toDate: '2026-03-31',
            );
        $this->assertStringContainsString('wire:key="financial-share-'.$customer->public_id.'"', $page->html(false));

        // 4. Clearing parent fromDate normalizes '' -> null, shows range error, and dispatches null.
        $page->set('fromDate', '')
            ->assertSetStrict('fromDate', null)
            ->assertHasErrors('fromDate')
            ->assertDispatchedTo(
                FinancialShareManager::class,
                $expectedEvent,
                subjectType: 'customer_statement',
                subjectId: $customer->id,
                publicId: $customer->public_id,
                fromDate: null,
                toDate: '2026-03-31',
            );
    }

    public function test_event_scoping_ignores_mismatched_subjects_and_preserves_manager_state(): void
    {
        $customerA = $this->createCustomer('A');
        $customerB = $this->createCustomer('B');
        $invoice = $this->createPostedInvoice($customerA, '2026-03-10');

        // Invoice manager does not register statement date listener at all.
        $invoiceManager = Livewire::test(FinancialShareManager::class, [
            'subjectType' => PublicShare::SUBJECT_SALES_INVOICE,
            'subjectId' => $invoice->id,
        ]);
        try {
            $invoiceManager->dispatch(
                'customer-statement-dates-updated.customer_statement.'.$customerA->id,
                subjectType: PublicShare::SUBJECT_CUSTOMER_STATEMENT,
                subjectId: $customerA->id,
                publicId: $customerA->public_id,
                fromDate: '2026-04-01',
                toDate: '2026-04-30',
            );
            $this->fail('Invoice share manager unexpectedly handled a statement date event.');
        } catch (EventHandlerDoesNotExist) {
            $invoiceManager->assertSetStrict('from', null)->assertSetStrict('to', null);
        }

        // Customer A statement manager ignores mismatched subjectType / subjectId / publicId.
        $managerA = Livewire::test(FinancialShareManager::class, [
            'subjectType' => PublicShare::SUBJECT_CUSTOMER_STATEMENT,
            'subjectId' => $customerA->id,
            'initialFrom' => '2026-01-01',
            'initialTo' => '2026-03-31',
        ]);

        // Issue a share and hold unsaved/open state to prove sync preserves them.
        $managerA->set('password', 'statement-secret-1')
            ->call('create')
            ->assertHasNoErrors()
            ->call('loadHistory', true);

        $issuedUrl = $managerA->get('url');
        $selectedShareId = $managerA->get('selectedShareId');
        $rotatedRequestKey = $managerA->get('requestKey');
        $managerA->set('password', 'pending-next-password');

        $eventA = 'customer-statement-dates-updated.customer_statement.'.$customerA->id;

        // Mismatched subjectId (Customer B's ID)
        $managerA->dispatch(
            $eventA,
            subjectType: PublicShare::SUBJECT_CUSTOMER_STATEMENT,
            subjectId: $customerB->id,
            publicId: $customerA->public_id,
            fromDate: '2026-09-01',
            toDate: '2026-09-30',
        )->assertSet('from', '2026-01-01')->assertSet('to', '2026-03-31');

        // Mismatched publicId
        $managerA->dispatch(
            $eventA,
            subjectType: PublicShare::SUBJECT_CUSTOMER_STATEMENT,
            subjectId: $customerA->id,
            publicId: $customerB->public_id,
            fromDate: '2026-09-01',
            toDate: '2026-09-30',
        )->assertSet('from', '2026-01-01')->assertSet('to', '2026-03-31');

        // Mismatched subjectType
        $managerA->dispatch(
            $eventA,
            subjectType: PublicShare::SUBJECT_SALES_INVOICE,
            subjectId: $customerA->id,
            publicId: $customerA->public_id,
            fromDate: '2026-09-01',
            toDate: '2026-09-30',
        )->assertSet('from', '2026-01-01')->assertSet('to', '2026-03-31');

        // Exact matching statement target updates from/to while preserving requestKey, password, historyOpen, historyLoaded, url, selectedShareId.
        $managerA->dispatch(
            $eventA,
            subjectType: PublicShare::SUBJECT_CUSTOMER_STATEMENT,
            subjectId: $customerA->id,
            publicId: $customerA->public_id,
            fromDate: '2026-02-01',
            toDate: '2026-02-28',
        )
            ->assertSet('from', '2026-02-01')
            ->assertSet('to', '2026-02-28')
            ->assertSet('requestKey', $rotatedRequestKey)
            ->assertSet('password', 'pending-next-password')
            ->assertSet('historyLoaded', true)
            ->assertSet('historyOpen', true)
            ->assertSet('url', $issuedUrl)
            ->assertSet('selectedShareId', $selectedShareId);
    }

    public function test_standalone_editable_dates_empty_string_normalization_and_expiry_days_clearing(): void
    {
        $this->company->update(['timezone' => 'Asia/Hebron']);
        Carbon::setTestNow(Carbon::parse('2026-05-10 12:00:00', 'UTC'));
        $customer = $this->createCustomer();
        $this->createPostedInvoice($customer, '2026-02-15');
        $this->createPostedInvoice($customer, '2026-04-15');

        $manager = Livewire::test(FinancialShareManager::class, [
            'subjectType' => PublicShare::SUBJECT_CUSTOMER_STATEMENT,
            'subjectId' => $customer->id,
            'initialFrom' => '2026-01-01',
            'initialTo' => '2026-03-31',
        ]);

        // Standalone from/to remain editable (not #[Reactive]-locked) and create uses standalone range.
        $manager->set('from', '2026-02-01')
            ->set('to', '2026-02-28')
            ->set('password', 'standalone-range-pass')
            ->set('locale', 'en')
            ->call('create')
            ->assertHasNoErrors()
            ->assertSetStrict('password', null);

        $firstUrl = $manager->get('url');
        $this->post($firstUrl, ['password' => 'standalone-range-pass'])->assertRedirect();
        $json = $this->get($firstUrl.'?format=json')->assertOk()->json();
        $this->assertSame('2026-02-01', $json['document']['from_date']);
        $this->assertSame('2026-02-28', $json['document']['to_date']);

        // Clearing from & to to '' normalizes to null before validation and service (never literal '').
        $manager->set('from', '')
            ->set('to', '')
            ->assertSetStrict('from', null)
            ->assertSetStrict('to', null)
            ->set('password', 'cleared-dates-pass')
            ->call('create')
            ->assertHasNoErrors();

        $secondUrl = $manager->get('url');
        $this->post($secondUrl, ['password' => 'cleared-dates-pass'])->assertRedirect();
        $secondJson = $this->get($secondUrl.'?format=json')->assertOk()->json();
        $this->assertNull($secondJson['document']['from_date']);
        $this->assertSame('2026-05-10', $secondJson['document']['to_date']);

        // Clearing expiryDays to '' returns clean validation error rather than TypeError 500.
        $manager->set('expiryDays', '')
            ->assertSetStrict('expiryDays', null)
            ->set('password', 'another-pass-123')
            ->call('create')
            ->assertOk()
            ->assertHasErrors(['expiryDays']);
    }

    public function test_idempotency_retry_and_conflict_and_utc_expiry_and_password_clearing(): void
    {
        $this->company->update(['timezone' => 'Asia/Hebron']);
        $customer = $this->createCustomer();
        $this->createPostedInvoice($customer, '2026-10-10');
        Carbon::setTestNow(Carbon::parse('2026-10-10 23:59:50', 'Asia/Hebron')->utc());

        $manager = Livewire::test(FinancialShareManager::class, [
            'subjectType' => PublicShare::SUBJECT_CUSTOMER_STATEMENT,
            'subjectId' => $customer->id,
            'initialFrom' => '2026-10-01',
            'initialTo' => '2026-10-10',
        ]);

        $initialKey = $manager->get('requestKey');
        $manager->set('expiryDays', 2)
            ->set('locale', 'en')
            ->set('password', 'retry-secret-pass')
            ->call('create')
            ->assertHasNoErrors()
            ->assertSetStrict('password', null);

        $this->assertNotSame($initialKey, $manager->get('requestKey'));
        $share = PublicShare::where('company_id', $this->company->id)->firstOrFail();
        $this->assertSame($initialKey, $share->request_key);
        $this->assertSame(2 * 86400, $share->expires_at->timestamp - $share->issued_at->timestamp);

        // Retry with same requestKey + identical payload converges to same share without duplication.
        $retryManager = Livewire::test(FinancialShareManager::class, [
            'subjectType' => PublicShare::SUBJECT_CUSTOMER_STATEMENT,
            'subjectId' => $customer->id,
            'initialFrom' => '2026-10-01',
            'initialTo' => '2026-10-10',
        ]);
        $this->setLockedPropertyOnServerSideViaService($customer, $initialKey);

        // Conflicting intent with an already-used requestKey fails cleanly with share error and creates 0 new rows.
        // Simulate a client replaying the prior key via service-compatible state or a second manager hydrated before ack:
        $replay = app(PublicShareService::class)->createShare(
            $this->company,
            $this->owner,
            PublicShare::SUBJECT_CUSTOMER_STATEMENT,
            $customer->id,
            null,
            'retry-secret-pass',
            $initialKey,
            ['locale' => 'en', 'from' => '2026-10-01', 'to' => '2026-10-10'],
            2,
        );
        $this->assertSame($share->id, $replay['share']->id);
        $this->assertSame(1, PublicShare::where('company_id', $this->company->id)->count());

        // Locked requestKey cannot be tampered by client.
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $retryManager->set('requestKey', $initialKey);
    }

    private function setLockedPropertyOnServerSideViaService(Customer $customer, string $usedKey): void
    {
        // Verify FinancialShareManager refuses conflicting intent for a consumed requestKey when a conflicting call hits create().
        $component = new FinancialShareManager;
        $component->mount(PublicShare::SUBJECT_CUSTOMER_STATEMENT, $customer->id, '2026-10-01', '2026-10-20');
        $component->requestKey = $usedKey;
        $component->expiryDays = 2;
        $component->locale = 'en';
        $component->password = 'retry-secret-pass';
        $component->create();
        $this->assertTrue($component->getErrorBag()->has('share'));
        $this->assertSame(__('sharing.issuance_unavailable'), $component->getErrorBag()->first('share'));
        $this->assertSame(1, PublicShare::where('company_id', $this->company->id)->count());
    }

    public function test_inactive_customer_preserves_history_and_single_share_revoke_while_create_fails_canonically(): void
    {
        $customer = $this->createCustomer();
        $this->createPostedInvoice($customer);

        $shareOne = app(PublicShareService::class)->createShare(
            $this->company,
            $this->owner,
            PublicShare::SUBJECT_CUSTOMER_STATEMENT,
            $customer->id,
            password: 'share-one-secret',
            scope: ['locale' => 'ar', 'from' => '2026-01-01', 'to' => '2026-12-31'],
            lifetimeDays: 7,
        )['share'];

        $shareTwo = app(PublicShareService::class)->createShare(
            $this->company,
            $this->owner,
            PublicShare::SUBJECT_CUSTOMER_STATEMENT,
            $customer->id,
            password: 'share-two-secret',
            scope: ['locale' => 'ar', 'from' => '2026-01-01', 'to' => '2026-12-31'],
            lifetimeDays: 7,
        )['share'];

        // Deactivate customer after issuance.
        $customer->update(['active' => false]);

        // Parent CustomerStatementView still loads and renders FinancialShareManager for authorized user.
        $statementPage = Livewire::test(CustomerStatementView::class, ['publicId' => $customer->public_id])
            ->assertStatus(200);
        $this->assertStringContainsString(__('sharing.manage'), $statementPage->html(false));

        // FinancialShareManager shows preview refusal notice, opens history, revokes ONE share only, and refuses create.
        $manager = Livewire::test(FinancialShareManager::class, [
            'subjectType' => PublicShare::SUBJECT_CUSTOMER_STATEMENT,
            'subjectId' => $customer->id,
        ])
            ->assertViewHas('preview', null)
            ->assertViewHas('previewError', __('sharing.issuance_unavailable'));

        $manager->call('loadHistory')
            ->assertSet('historyLoaded', true)
            ->assertSet('historyOpen', true)
            ->assertSee($shareOne->public_id)
            ->assertSee($shareTwo->public_id);

        // Creating new share on inactive customer fails canonically with 0 new rows.
        $manager->set('password', 'inactive-create-pass')
            ->call('create')
            ->assertHasErrors('share')
            ->assertSet('url', null);
        $this->assertSame(2, PublicShare::where('company_id', $this->company->id)->count());

        // Revoking shareOne revokes ONLY shareOne; shareTwo remains active!
        $manager->call('revoke', $shareOne->public_id)->assertHasNoErrors();
        $this->assertFalse($shareOne->fresh()->is_active);
        $this->assertTrue($shareTwo->fresh()->is_active);
    }

    public function test_active_customer_single_share_revoke_preserves_other_selected_link_and_clears_when_revoking_selected(): void
    {
        $customer = $this->createCustomer();
        $this->createPostedInvoice($customer);

        $older = app(PublicShareService::class)->createShare(
            $this->company,
            $this->owner,
            PublicShare::SUBJECT_CUSTOMER_STATEMENT,
            $customer->id,
            password: 'older-secret-pass',
            lifetimeDays: 7,
        )['share'];

        $manager = Livewire::test(FinancialShareManager::class, [
            'subjectType' => PublicShare::SUBJECT_CUSTOMER_STATEMENT,
            'subjectId' => $customer->id,
        ])
            ->set('password', 'current-secret-pass')
            ->call('create')
            ->assertHasNoErrors();

        $currentUrl = $manager->get('url');
        $currentId = $manager->get('selectedShareId');
        $this->assertNotNull($currentUrl);

        // Revoking the older share leaves the currently displayed share active and visible.
        $manager->call('revoke', $older->public_id)
            ->assertSet('url', $currentUrl)
            ->assertSet('selectedShareId', $currentId);
        $this->assertFalse($older->fresh()->is_active);
        $this->assertTrue(PublicShare::where('public_id', $currentId)->firstOrFail()->is_active);

        // Revoking the selected share clears url/qr/selectedShareId.
        $manager->call('revoke', $currentId)
            ->assertSet('url', null)
            ->assertSet('qr', null)
            ->assertSet('selectedShareId', null);
        $this->assertFalse(PublicShare::where('public_id', $currentId)->firstOrFail()->is_active);
    }

    public function test_permission_revocation_and_cross_tenant_isolation_are_enforced_live(): void
    {
        $customer = $this->createCustomer();
        $this->createPostedInvoice($customer);

        $actor = $this->customActor(['sales.document.share', 'sales.statement.view', 'customers.statement.view'], 'ShareActor');
        $role = $actor->roles()->firstOrFail();
        $this->activate($actor);

        $manager = Livewire::test(FinancialShareManager::class, [
            'subjectType' => PublicShare::SUBJECT_CUSTOMER_STATEMENT,
            'subjectId' => $customer->id,
        ])->set('password', 'revoked-perm-pass');

        // Revoke customers.statement.view mid-session: mutations are denied 403.
        $role->revokePermissionTo('customers.statement.view');
        $manager->call('create')->assertForbidden();
        $this->assertSame(0, PublicShare::count());

        $role->givePermissionTo('customers.statement.view');
        $freshManager = Livewire::test(FinancialShareManager::class, [
            'subjectType' => PublicShare::SUBJECT_CUSTOMER_STATEMENT,
            'subjectId' => $customer->id,
        ]);
        $role->revokePermissionTo('sales.document.share');
        $freshManager->call('loadHistory')->assertForbidden();

        // Cross-tenant deny: foreign customer / foreign share cannot be mounted or mutated.
        $this->activate($this->owner);
        app(CompanyContext::class)->clear();
        $foreignOwner = User::factory()->create();
        $foreignCompany = app(CreateCompanyAction::class)->execute($foreignOwner, [
            'name_ar' => 'شركة أجنبية',
            'name_en' => 'Foreign Co',
            'base_currency_code' => 'ILS',
        ]);
        $this->actingAs($foreignOwner);
        app(CompanyContext::class)->setCompany($foreignCompany, $foreignOwner);
        $foreignCustomer = Customer::create([
            'company_id' => $foreignCompany->id,
            'name_ar' => 'عميل أجنبي',
            'name_en' => 'Foreign Customer',
            'active' => true,
            'created_by' => $foreignOwner->id,
        ]);
        $foreignShare = app(PublicShareService::class)->createShare(
            $foreignCompany,
            $foreignOwner,
            PublicShare::SUBJECT_CUSTOMER_STATEMENT,
            $foreignCustomer->id,
            password: 'foreign-secret-pass',
        )['share'];

        $this->activate($this->owner);
        Livewire::test(FinancialShareManager::class, [
            'subjectType' => PublicShare::SUBJECT_CUSTOMER_STATEMENT,
            'subjectId' => $foreignCustomer->id,
        ])->assertNotFound();

        $ownManager = Livewire::test(FinancialShareManager::class, [
            'subjectType' => PublicShare::SUBJECT_CUSTOMER_STATEMENT,
            'subjectId' => $customer->id,
        ]);
        $ownManager->call('revoke', $foreignShare->public_id)->assertNotFound();
        $this->assertTrue(PublicShare::withoutGlobalScopes()->findOrFail($foreignShare->id)->is_active);
    }

    #[DataProvider('locales')]
    public function test_unique_ids_single_history_rpc_open_state_dedup_errors_and_locale_parity(string $locale): void
    {
        app()->setLocale($locale);
        $this->owner->update(['locale' => $locale]);
        $this->activate($this->owner->fresh());

        $customer = $this->createCustomer();
        $invoice = $this->createPostedInvoice($customer);

        // Full page HTTP render via route verifies layout + CustomerStatementView + FinancialShareManager together.
        $httpHtml = $this->get(route('customers.statement', [
            'publicId' => $customer->public_id,
            'from' => '2026-01-01',
            'to' => '2026-12-31',
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'"', $httpHtml);
        $this->assertAllElementIdsUnique($httpHtml);

        // Render two different FinancialShareManager instances together (statement + invoice) and verify zero ID collisions.
        $statementManager = Livewire::test(FinancialShareManager::class, [
            'subjectType' => PublicShare::SUBJECT_CUSTOMER_STATEMENT,
            'subjectId' => $customer->id,
            'initialFrom' => '2026-01-01',
            'initialTo' => '2026-12-31',
        ]);
        $invoiceManager = Livewire::test(FinancialShareManager::class, [
            'subjectType' => PublicShare::SUBJECT_SALES_INVOICE,
            'subjectId' => $invoice->id,
        ]);

        $combinedHtml = $statementManager->html(false)."\n".$invoiceManager->html(false);
        $this->assertAllElementIdsUnique($combinedHtml);

        // Single history RPC: <summary> has no wire:click="loadHistory"; only @toggle triggers loadHistory once.
        $initialStatementHtml = $statementManager->html(false);
        $this->assertStringNotContainsString('wire:click="loadHistory"', $initialStatementHtml);
        $this->assertStringContainsString('$wire.loadHistory()', $initialStatementHtml);
        $this->assertStringNotContainsString('sharing.no_shares', $initialStatementHtml);
        $this->assertStringContainsString(__('sharing.no_shares', [], $locale), $initialStatementHtml);

        // Opening history sets historyOpen = true and outputs <details ... open> across morphs.
        $statementManager->call('loadHistory')
            ->assertSet('historyLoaded', true)
            ->assertSet('historyOpen', true);
        $openedXpath = $this->xpath($statementManager->html(false));
        $detailsNode = $openedXpath->query('//details')->item(0);
        $this->assertInstanceOf(DOMElement::class, $detailsNode);
        $this->assertTrue($detailsNode->hasAttribute('open'));

        // Parent event update while open preserves open state in server HTML.
        $statementManager->dispatch(
            'customer-statement-dates-updated.customer_statement.'.$customer->id,
            subjectType: PublicShare::SUBJECT_CUSTOMER_STATEMENT,
            subjectId: $customer->id,
            publicId: $customer->public_id,
            fromDate: '2026-02-01',
            toDate: '2026-12-31',
        );
        $afterSyncXpath = $this->xpath($statementManager->html(false));
        $afterSyncDetails = $afterSyncXpath->query('//details')->item(0);
        $this->assertInstanceOf(DOMElement::class, $afterSyncDetails);
        $this->assertTrue($afterSyncDetails->hasAttribute('open'));

        // Validation error appears ONCE adjacent to field (not duplicated at bottom), with aria-invalid & aria-describedby.
        $statementManager->set('password', 'short')
            ->call('create')
            ->assertHasErrors(['password']);
        $errorBagMessage = $statementManager->errors()->first('password');
        $errorHtml = $statementManager->html(false);
        $this->assertSame(1, substr_count($errorHtml, e($errorBagMessage)));

        $errorXpath = $this->xpath($errorHtml);
        $passwordInput = $errorXpath->query('//input[@autocomplete="new-password"]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $passwordInput);
        $this->assertSame('true', $passwordInput->getAttribute('aria-invalid'));
        $describedBy = $passwordInput->getAttribute('aria-describedby');
        $this->assertNotSame('', $describedBy);
        $this->assertNotNull($errorXpath->query('//*[@id="'.$describedBy.'"]')->item(0));
        $passwordId = $passwordInput->getAttribute('id');
        $this->assertNotNull($errorXpath->query('//label[@for="'.$passwordId.'"]')->item(0));
    }

    /** @return array<string, mixed>|null */
    private function extractChildSnapshot(string $html, string $componentName): ?array
    {
        if (! preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches)) {
            return null;
        }
        foreach ($matches[1] as $encoded) {
            $decoded = json_decode(htmlspecialchars_decode($encoded, ENT_QUOTES), true);
            if (is_array($decoded) && ($decoded['memo']['name'] ?? null) === $componentName) {
                return $decoded;
            }
        }

        return null;
    }

    private function assertAllElementIdsUnique(string $html): void
    {
        $xpath = $this->xpath($html);
        $seen = [];
        foreach ($xpath->query('//*[@id]') as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }
            $id = $element->getAttribute('id');
            $this->assertArrayNotHasKey($id, $seen, "Duplicate HTML id [{$id}] found.");
            $seen[$id] = true;
        }
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();

        return new DOMXPath($dom);
    }
}
