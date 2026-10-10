<?php

declare(strict_types=1);

namespace Tests\Feature\Phase9;

use App\Models\Catalog;
use App\Models\Company;
use App\Models\PublicShare;
use App\Services\Catalogs\CatalogService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Phase5E\Phase5ETestCase;

class CatalogLifecycleExpiryTest extends Phase5ETestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('catalog-ip:'.hash('sha256', '127.0.0.1'));
    }

    /**
     * Helper to create and publish a standard test catalog.
     *
     * @return array{catalog:Catalog,preview:array{revision:int,hash:string,payload:array<string,mixed>}}
     */
    private function createPublishedCatalog(bool $priced = false, ?string $price = '25.00'): array
    {
        $service = app(CatalogService::class);
        $catalog = $service->save(null, [
            'name_ar' => 'كتالوج دورة الحياة',
            'name_en' => 'Lifecycle Catalog',
            'description_ar' => 'وصف الدورة',
            'description_en' => 'Lifecycle description',
            'locale' => 'ar',
            'show_prices' => $priced,
            'show_sku' => true,
            'show_description' => true,
            'show_images' => false,
            'currency_code' => $priced ? 'ILS' : null,
            'tax_basis' => $priced ? 'Tax included' : null,
        ], [
            [
                'product_id' => $this->product->id,
                'unit_id' => $this->unit->unit_id,
                'custom_price' => $priced ? $price : null,
            ],
        ]);

        $preview = $service->preview($catalog->id);
        $service->publish($catalog->id, $preview['revision'], $preview['hash'], 'pub-lifecycle-'.$catalog->public_id);

        return ['catalog' => $catalog, 'preview' => $preview];
    }

    /**
     * Asserts that all public destinations return 404 (unavailable) for the given token/URL.
     */
    private function assertAllManagedEndpointsDenied(string $url): void
    {
        // Isolate lifecycle assertions from the separately tested public IP rate budget.
        RateLimiter::clear('catalog-ip:'.hash('sha256', '127.0.0.1'));
        foreach ([
            '',
            '?locale=ar',
            '?locale=en',
            '?format=json',
            '?locale=en&format=json',
            '?locale=ar&format=json',
            '?format=pdf',
            '?locale=en&format=pdf',
            '?locale=ar&format=pdf',
            '?format=print',
            '?locale=en&format=print',
            '?locale=ar&format=print',
        ] as $suffix) {
            $response = $this->get($url.$suffix)->assertNotFound()->assertDontSee('Food Item')->assertDontSee('Lifecycle Catalog');
            $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
            $this->head($url.$suffix)->assertNotFound()->assertContent('');
        }
        $this->post($url, ['password' => 'attempt-secret'])->assertNotFound();
    }

    /**
     * Compute financial and inventory fingerprint to ensure zero economic writes.
     *
     * @return array<string,string>
     */
    private function economicFingerprint(): array
    {
        $result = [];
        foreach ([
            'posting_batches',
            'posting_lines',
            'stock_movements',
            'sales_invoices',
            'sales_invoice_lines',
            'customer_payments',
            'customer_payment_allocations',
            'document_sequences',
            'purchases',
            'purchase_lines',
            'vendor_payments',
            'vendor_payment_allocations',
        ] as $table) {
            $result[$table] = hash('sha256', DB::table($table)->where('company_id', $this->company->id)->orderBy('id')->get()->toJson());
        }

        return $result;
    }

    public function test_pause_denies_managed_routes_and_authorized_resume_restores_same_url(): void
    {
        $setup = $this->createPublishedCatalog();
        $service = app(CatalogService::class);
        $link = $service->share($setup['catalog']->id);

        $before = $this->economicFingerprint();
        auth()->logout();

        // 1. Initial active state works
        $this->get($link['url'].'?locale=en&format=json')->assertOk()->assertJsonPath('items.0.name', 'Food Item');

        // 2. Pause: status becomes paused, public endpoints return 404
        $this->activate($this->owner);
        $service->state($setup['catalog']->id, 'paused');
        $this->assertSame('paused', $setup['catalog']->fresh()->status);
        auth()->logout();
        $this->assertAllManagedEndpointsDenied($link['url']);

        // 3. Authorized resume: status becomes active, same URL works again with identical items
        $this->activate($this->owner);
        $service->state($setup['catalog']->id, 'active');
        $this->assertSame('active', $setup['catalog']->fresh()->status);
        auth()->logout();
        $this->get($link['url'].'?locale=en&format=json')->assertOk()->assertJsonPath('items.0.name', 'Food Item');

        // Invariant: zero financial or stock writes occurred
        $this->assertSame($before, $this->economicFingerprint());
    }

    public function test_revoke_atomically_retires_active_grants_and_permanently_invalidates_old_token(): void
    {
        $setup = $this->createPublishedCatalog();
        $service = app(CatalogService::class);
        $link = $service->share($setup['catalog']->id);

        // Revoke catalog
        $service->state($setup['catalog']->id, 'revoked');
        $this->assertSame('revoked', $setup['catalog']->fresh()->status);

        // Grant is atomically retired: is_active = false, revoked_at set, revoked_by recorded
        $grant = $link['share']->fresh();
        $this->assertFalse($grant->is_active);
        $this->assertNotNull($grant->revoked_at);
        $this->assertSame($this->owner->id, $grant->revoked_by);
        $this->assertFalse($grant->isValid());

        // Old token denied on all public destinations
        auth()->logout();
        $this->assertAllManagedEndpointsDenied($link['url']);

        // Authorized resume to 'active' DOES NOT resurrect the revoked grant
        $this->activate($this->owner);
        $service->state($setup['catalog']->id, 'active');
        auth()->logout();
        $this->assertAllManagedEndpointsDenied($link['url']);

        // Republishing catalog DOES NOT reactivate the old grant
        $this->activate($this->owner);
        $preview = $service->preview($setup['catalog']->id);
        $service->publish($setup['catalog']->id, $preview['revision'], $preview['hash'], 'repub-after-revoke');
        auth()->logout();
        $this->assertAllManagedEndpointsDenied($link['url']);

        // Attempting updateLinkAccess on the retired grant fails
        $this->activate($this->owner);
        $stateHash = hash('sha256', implode('|', [
            $grant->token_lookup_hash,
            $grant->password_hash,
            $grant->expires_at?->timestamp,
            $grant->is_active ? '1' : '0',
        ]));
        $this->expectException(InvalidArgumentException::class);
        $service->updateLinkAccess($setup['catalog']->id, $stateHash, now()->addDays(5)->format('Y-m-d'));
    }

    public function test_new_link_generates_fresh_independent_token_and_atomically_retires_previous_active_grant(): void
    {
        $setup = $this->createPublishedCatalog();
        $service = app(CatalogService::class);
        $link1 = $service->share($setup['catalog']->id);

        $grant1 = $link1['share']->fresh();
        $this->assertTrue($grant1->is_active);
        $this->assertNull($grant1->revoked_at);

        // Generate explicit fresh link
        $freshResult = $service->newLink($setup['catalog']->id, 'newlink-req-key-01', 'password-fresh-1', now()->addDays(10)->format('Y-m-d'));

        $this->assertArrayHasKey('share', $freshResult);
        $this->assertArrayHasKey('url', $freshResult);

        $grant2 = $freshResult['share']->fresh();

        // Independent 40-character random token, distinct grant ID and URL
        $this->assertNotSame($grant1->id, $grant2->id);
        $this->assertNotSame($grant1->token_lookup_hash, $grant2->token_lookup_hash);
        $this->assertNotSame($link1['url'], $freshResult['url']);

        $raw2 = Crypt::decryptString($grant2->encrypted_token);
        $this->assertSame(40, strlen($raw2));
        $this->assertSame(hash('sha256', $raw2), $grant2->token_lookup_hash);

        // Old active grant was atomically retired
        $this->assertFalse($grant1->fresh()->is_active);
        $this->assertNotNull($grant1->fresh()->revoked_at);
        $this->assertSame($this->owner->id, $grant1->fresh()->revoked_by);

        // New grant is active, has access_profile catalog_v2, password hash and expiry
        $this->assertTrue($grant2->is_active);
        $this->assertNull($grant2->revoked_at);
        $this->assertSame('catalog_v2', $grant2->access_profile);
        $this->assertTrue(Hash::check('password-fresh-1', $grant2->password_hash));

        // Catalog status is active
        $this->assertSame('active', $setup['catalog']->fresh()->status);

        // Both grants exist in database (multiple historical grants preserved!)
        $this->assertSame(2, PublicShare::where('company_id', $this->company->id)->where('subject_type', 'product_catalog')->where('subject_id', $setup['catalog']->id)->count());

        // Old token denied, new token works
        auth()->logout();
        $this->assertAllManagedEndpointsDenied($link1['url']);
        $this->post($freshResult['url'], ['password' => 'password-fresh-1'])->assertRedirect();
        $this->get($freshResult['url'].'?locale=en&format=json')->assertOk()->assertJsonPath('items.0.name', 'Food Item');
    }

    public function test_new_link_allowed_on_active_or_revoked_catalog_but_rejects_paused(): void
    {
        $setup = $this->createPublishedCatalog();
        $service = app(CatalogService::class);
        $service->share($setup['catalog']->id);

        // 1. Paused catalog rejects newLink (must resume first)
        $service->state($setup['catalog']->id, 'paused');
        try {
            $service->newLink($setup['catalog']->id, 'req-paused-fail', null, null);
            $this->fail('newLink should have rejected paused catalog.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('resume', strtolower($e->getMessage()));
        }

        // 2. Resume to active allows newLink
        $service->state($setup['catalog']->id, 'active');
        $resActive = $service->newLink($setup['catalog']->id, 'req-active-success', null, null);
        $this->assertInstanceOf(PublicShare::class, $resActive['share']);

        // 3. Revoked catalog allows newLink and transitions catalog status to active
        $service->state($setup['catalog']->id, 'revoked');
        $this->assertSame('revoked', $setup['catalog']->fresh()->status);

        $resRevoked = $service->newLink($setup['catalog']->id, 'req-revoked-success', null, null);
        $this->assertInstanceOf(PublicShare::class, $resRevoked['share']);
        $this->assertSame('active', $setup['catalog']->fresh()->status);
    }

    public function test_new_link_authorization_enforces_share_and_publish_and_show_prices_permissions(): void
    {
        $setup = $this->createPublishedCatalog(true, '100.00');
        $service = app(CatalogService::class);

        // Actor with only catalogs.view
        $actorView = $this->customActor(['catalogs.view']);
        $this->activate($actorView);
        try {
            $service->newLink($setup['catalog']->id, 'actor-test-1', null, null);
            $this->fail('newLink bypassed missing share/publish permissions.');
        } catch (AuthorizationException|HttpException $e) {
            $this->assertTrue(true);
        }

        // Actor with catalogs.share and catalogs.publish but WITHOUT catalogs.show_prices for priced catalog
        $actorNoPrices = $this->customActor(['catalogs.view', 'catalogs.share', 'catalogs.publish']);
        $this->activate($actorNoPrices);
        try {
            $service->newLink($setup['catalog']->id, 'actor-test-2', null, null);
            $this->fail('newLink bypassed missing show_prices permission on priced catalog.');
        } catch (AuthorizationException|HttpException $e) {
            $this->assertTrue(true);
        }

        // Owner with full permissions succeeds
        $this->activate($this->owner);
        $res = $service->newLink($setup['catalog']->id, 'actor-test-owner', null, null);
        $this->assertInstanceOf(PublicShare::class, $res['share']);
    }

    public function test_new_link_idempotent_retry_with_same_key_and_payload_returns_original_grant(): void
    {
        $setup = $this->createPublishedCatalog();
        $service = app(CatalogService::class);
        $date = now()->addDays(7)->format('Y-m-d');

        $first = $service->newLink($setup['catalog']->id, 'idempotent-key-01', 'idempotent-pass', $date);
        $second = $service->newLink($setup['catalog']->id, 'idempotent-key-01', 'idempotent-pass', $date);

        $this->assertSame($first['share']->id, $second['share']->id);
        $this->assertSame($first['url'], $second['url']);
        $this->assertSame(
            1,
            PublicShare::where('company_id', $this->company->id)->where('request_key', 'catalog-link:idempotent-key-01')->count()
        );
    }

    public function test_new_link_retry_with_different_payload_or_retired_request_key_is_rejected(): void
    {
        $setup = $this->createPublishedCatalog();
        $service = app(CatalogService::class);
        $date = now()->addDays(7)->format('Y-m-d');

        $service->newLink($setup['catalog']->id, 'conflict-key-01', 'pass-initial', $date);

        // Different password with same requestKey fails
        try {
            $service->newLink($setup['catalog']->id, 'conflict-key-01', 'pass-different', $date);
            $this->fail('Different password accepted for existing requestKey.');
        } catch (InvalidArgumentException $e) {
            $this->assertTrue(true);
        }

        // Different expiry date with same requestKey fails
        try {
            $service->newLink($setup['catalog']->id, 'conflict-key-01', 'pass-initial', now()->addDays(14)->format('Y-m-d'));
            $this->fail('Different expiry date accepted for existing requestKey.');
        } catch (InvalidArgumentException $e) {
            $this->assertTrue(true);
        }

        // Now revoke catalog, retiring the grant associated with 'conflict-key-01'
        $service->state($setup['catalog']->id, 'revoked');

        // Retrying with the retired request key must be rejected (never mint another grant!)
        try {
            $service->newLink($setup['catalog']->id, 'conflict-key-01', 'pass-initial', $date);
            $this->fail('Retired request key accepted.');
        } catch (InvalidArgumentException $e) {
            $this->assertTrue(true);
        }
    }

    public function test_share_creates_first_grant_only_recovers_active_and_refuses_retired_history(): void
    {
        $setup = $this->createPublishedCatalog();
        $service = app(CatalogService::class);

        // 1. Initial share creates first grant
        $initial = $service->share($setup['catalog']->id);
        $this->assertInstanceOf(PublicShare::class, $initial['share']);
        $this->assertSame(1, PublicShare::where('company_id', $this->company->id)->where('subject_id', $setup['catalog']->id)->count());

        // 2. Subsequent share recovers the latest active unrevoked grant
        $recovered = $service->share($setup['catalog']->id);
        $this->assertSame($initial['share']->id, $recovered['share']->id);
        $this->assertSame($initial['url'], $recovered['url']);

        // 3. If active grant is expired, recovery still refuses silent new grant
        $initial['share']->update(['expires_at' => now()->subDay()]);
        try {
            $service->share($setup['catalog']->id);
            $this->fail('share() silently bypassed expired active grant.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('unavailable', $e->getMessage());
        }

        // 4. Revoking retires all history; share() CANNOT create after retired history
        $service->state($setup['catalog']->id, 'revoked');
        $service->state($setup['catalog']->id, 'active');

        $this->expectException(InvalidArgumentException::class);
        $service->share($setup['catalog']->id);
    }

    public function test_model_is_valid_refuses_revoked_at_even_if_is_active_corruptly_true(): void
    {
        $setup = $this->createPublishedCatalog();
        $service = app(CatalogService::class);
        $link = $service->share($setup['catalog']->id);

        $grant = $link['share'];

        // Corrupt row: is_active = true, but revoked_at is populated
        $grant->update([
            'is_active' => true,
            'revoked_at' => now(),
            'revoked_by' => $this->owner->id,
            'expires_at' => now()->addDays(5),
        ]);

        $this->assertFalse($grant->fresh()->isValid());

        // Corrupt row: is_active = true, revoked_at is null, but expired
        $grant->update([
            'is_active' => true,
            'revoked_at' => null,
            'expires_at' => now()->subSecond(),
        ]);

        $this->assertFalse($grant->fresh()->isValid());

        // Valid row: is_active = true, revoked_at is null, not expired
        $grant->update([
            'is_active' => true,
            'revoked_at' => null,
            'expires_at' => now()->addDays(5),
        ]);

        $this->assertTrue($grant->fresh()->isValid());
    }

    public function test_latest_active_grant_lookup_does_not_assume_subject_unique(): void
    {
        $setup = $this->createPublishedCatalog();
        $service = app(CatalogService::class);

        // Grant 1: created then retired
        $link1 = $service->share($setup['catalog']->id);
        $link1['share']->update(['is_active' => false, 'revoked_at' => now(), 'revoked_by' => $this->owner->id]);

        // Grant 2: created then retired
        $grant2 = PublicShare::create([
            'company_id' => $this->company->id,
            'subject_type' => 'product_catalog',
            'subject_id' => $setup['catalog']->id,
            'token_lookup_hash' => hash('sha256', 'dummy-token-2-xxxxxxxxxxxxxxxxxxxxxxxxxx'),
            'encrypted_token' => Crypt::encryptString('dummy-token-2-xxxxxxxxxxxxxxxxxxxxxxxxxx'),
            'is_active' => false,
            'revoked_at' => now(),
            'revoked_by' => $this->owner->id,
            'access_profile' => 'catalog_v2',
            'request_key' => 'req-dummy-2',
        ]);

        // Grant 3: active grant
        $raw3 = str_repeat('z', 40);
        $grant3 = PublicShare::create([
            'company_id' => $this->company->id,
            'subject_type' => 'product_catalog',
            'subject_id' => $setup['catalog']->id,
            'token_lookup_hash' => hash('sha256', $raw3),
            'encrypted_token' => Crypt::encryptString($raw3),
            'is_active' => true,
            'revoked_at' => null,
            'expires_at' => now()->addDays(3),
            'access_profile' => 'catalog_v2',
            'request_key' => 'req-dummy-3',
        ]);

        $this->assertSame(3, PublicShare::where('company_id', $this->company->id)->where('subject_id', $setup['catalog']->id)->count());

        // linkSettings finds the latest active unrevoked grant
        $settings = $service->linkSettings($setup['catalog']->id);
        $this->assertSame($grant3->public_id.':'.hash('sha256', implode('|', [$grant3->token_lookup_hash, $grant3->password_hash, $grant3->expires_at?->timestamp, '1'])), $settings['state']);

        // resolve finds the exact active grant by token lookup hash
        $resolved = $service->resolve($raw3);
        $this->assertSame($grant3->id, $resolved->id);
    }

    public function test_database_constraints_permit_multiple_historical_grants_but_enforce_request_key_and_token_hash_uniqueness(): void
    {
        $setup = $this->createPublishedCatalog();

        // 1. Two distinct grants for the same company and subject succeed
        $grantA = PublicShare::create([
            'company_id' => $this->company->id,
            'subject_type' => 'product_catalog',
            'subject_id' => $setup['catalog']->id,
            'token_lookup_hash' => hash('sha256', 'token-unique-test-a-xxxxxxxxxxxxxxxxxxxx'),
            'encrypted_token' => Crypt::encryptString('token-unique-test-a-xxxxxxxxxxxxxxxxxxxx'),
            'is_active' => false,
            'revoked_at' => now(),
            'access_profile' => 'catalog_v2',
            'request_key' => 'request-key-unique-a',
        ]);

        $grantB = PublicShare::create([
            'company_id' => $this->company->id,
            'subject_type' => 'product_catalog',
            'subject_id' => $setup['catalog']->id,
            'token_lookup_hash' => hash('sha256', 'token-unique-test-b-xxxxxxxxxxxxxxxxxxxx'),
            'encrypted_token' => Crypt::encryptString('token-unique-test-b-xxxxxxxxxxxxxxxxxxxx'),
            'is_active' => true,
            'access_profile' => 'catalog_v2',
            'request_key' => 'request-key-unique-b',
        ]);

        $this->assertNotSame($grantA->id, $grantB->id);

        // 2. Duplicate token_lookup_hash fails with QueryException (unique constraint)
        try {
            PublicShare::create([
                'company_id' => $this->company->id,
                'subject_type' => 'product_catalog',
                'subject_id' => $setup['catalog']->id,
                'token_lookup_hash' => hash('sha256', 'token-unique-test-a-xxxxxxxxxxxxxxxxxxxx'),
                'encrypted_token' => Crypt::encryptString('token-unique-test-a-xxxxxxxxxxxxxxxxxxxx'),
                'is_active' => true,
                'access_profile' => 'catalog_v2',
                'request_key' => 'request-key-unique-c',
            ]);
            $this->fail('Duplicate token_lookup_hash was accepted by database.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        // 3. Duplicate (company_id, request_key) fails with QueryException (unique constraint)
        try {
            PublicShare::create([
                'company_id' => $this->company->id,
                'subject_type' => 'product_catalog',
                'subject_id' => $setup['catalog']->id,
                'token_lookup_hash' => hash('sha256', 'token-unique-test-d-xxxxxxxxxxxxxxxxxxxx'),
                'encrypted_token' => Crypt::encryptString('token-unique-test-d-xxxxxxxxxxxxxxxxxxxx'),
                'is_active' => true,
                'access_profile' => 'catalog_v2',
                'request_key' => 'request-key-unique-a',
            ]);
            $this->fail('Duplicate (company_id, request_key) was accepted by database.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }

    public function test_publication_receipts_and_audit_events_are_preserved_across_full_lifecycle(): void
    {
        $setup = $this->createPublishedCatalog();
        $service = app(CatalogService::class);
        $link = $service->share($setup['catalog']->id);

        $initialReceiptCount = DB::table('catalog_publications')->where('catalog_id', $setup['catalog']->id)->count();
        $this->assertSame(1, $initialReceiptCount);

        // Lifecycle steps
        $service->state($setup['catalog']->id, 'paused');
        $service->state($setup['catalog']->id, 'active');
        $service->state($setup['catalog']->id, 'revoked');
        $service->newLink($setup['catalog']->id, 'req-receipt-check-1', null, null);

        // Publication receipts preserved
        $this->assertSame($initialReceiptCount, DB::table('catalog_publications')->where('catalog_id', $setup['catalog']->id)->count());

        // Audit events recorded
        $events = DB::table('audit_events')->where('company_id', $this->company->id)->pluck('event_key')->all();
        $this->assertContains('catalog.published', $events);
        $this->assertContains('catalog.paused', $events);
        $this->assertContains('catalog.active', $events);
        $this->assertContains('catalog.revoked', $events);
    }

    public function test_catalog_status_manually_revoked_retires_grant_terminally_on_state_active_or_paused(): void
    {
        $service = app(CatalogService::class);

        // 1. Transition away to 'active':
        $setup1 = $this->createPublishedCatalog();
        $link1 = $service->share($setup1['catalog']->id);
        $grant1 = $link1['share']->fresh();
        $this->assertTrue($grant1->is_active);
        $this->assertNull($grant1->revoked_at);

        // Simulate older reviewed head: catalog is marked 'revoked' in DB, but grant is still active
        DB::table('catalogs')->where('id', $setup1['catalog']->id)->update(['status' => 'revoked']);
        $this->assertSame('revoked', $setup1['catalog']->fresh()->status);
        $this->assertTrue($grant1->fresh()->is_active);

        // A deliberate transition away to 'active' must terminally retire that old grant
        $service->state($setup1['catalog']->id, 'active');
        $this->assertFalse($grant1->fresh()->is_active);
        $this->assertNotNull($grant1->fresh()->revoked_at);
        $this->assertSame($this->owner->id, $grant1->fresh()->revoked_by);
        $this->assertFalse($grant1->fresh()->isValid());
        auth()->logout();
        $this->assertAllManagedEndpointsDenied($link1['url']);
        $this->activate($this->owner);

        // 2. Transition away to 'paused':
        $setup2 = $this->createPublishedCatalog();
        $link2 = $service->share($setup2['catalog']->id);
        $grant2 = $link2['share']->fresh();
        $this->assertTrue($grant2->is_active);
        $this->assertNull($grant2->revoked_at);

        // Manually mark catalog 'revoked'
        DB::table('catalogs')->where('id', $setup2['catalog']->id)->update(['status' => 'revoked']);

        // A deliberate transition away to 'paused' must also terminally retire that old grant
        $service->state($setup2['catalog']->id, 'paused');
        $this->assertFalse($grant2->fresh()->is_active);
        $this->assertNotNull($grant2->fresh()->revoked_at);
        $this->assertSame($this->owner->id, $grant2->fresh()->revoked_by);
        $this->assertFalse($grant2->fresh()->isValid());
    }

    public function test_stale_access_state_across_deliberate_new_link_must_reject_even_with_same_date_and_password(): void
    {
        $setup = $this->createPublishedCatalog();
        $service = app(CatalogService::class);
        $date = now($this->company->timezone)->addDays(5)->format('Y-m-d');
        $password = 'stable-secret-123';

        $link1 = $service->share($setup['catalog']->id, $password, $date);
        $grant1 = $link1['share']->fresh();

        // Capture Grant 1's state in both formats:
        // New grant-bound state: "{public_id}:{sha256}"
        $grantBoundState = $service->linkSettings($setup['catalog']->id)['state'];
        $this->assertStringStartsWith($grant1->public_id.':', $grantBoundState);

        // Historical 64-character bare hash:
        $bare64State = substr($grantBoundState, strlen($grant1->public_id) + 1);
        $this->assertSame(64, strlen($bare64State));

        // Deliberately mint a New Link (retiring Grant 1 and creating Grant 2)
        $service->newLink($setup['catalog']->id, 'explicit-new-link-req', $password, $date);
        $grant2 = PublicShare::where('company_id', $this->company->id)->where('subject_id', $setup['catalog']->id)
            ->where('is_active', true)->firstOrFail();
        $this->assertNotSame($grant1->id, $grant2->id);

        // Attempting updateLinkAccess using Grant 1's grant-bound state MUST reject
        try {
            $service->updateLinkAccess($setup['catalog']->id, $grantBoundState, $date, $password);
            $this->fail('updateLinkAccess accepted stale grant-bound state after newLink.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('changed', $e->getMessage());
        }

        // Attempting updateLinkAccess using Grant 1's historical 64-char bare hash MUST also reject
        try {
            $service->updateLinkAccess($setup['catalog']->id, $bare64State, $date, $password);
            $this->fail('updateLinkAccess accepted stale 64-character hash after newLink.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('changed', $e->getMessage());
        }
    }
}
