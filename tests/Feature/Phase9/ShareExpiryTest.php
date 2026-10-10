<?php

declare(strict_types=1);

namespace Tests\Feature\Phase9;

use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Models\Customer;
use App\Models\PublicShare;
use App\Models\SalesInvoice;
use App\Services\Sales\IssuedFinancialShares;
use App\Services\Sales\PublicShareService;
use App\Services\Sales\ShareExpiry;
use Carbon\Carbon;
use DateTimeZone;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\Feature\Phase5E\Phase5ETestCase;

class ShareExpiryTest extends Phase5ETestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('financial-ip:'.hash('sha256', '127.0.0.1'));
        RateLimiter::clear('catalog-ip:'.hash('sha256', '127.0.0.1'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function expiry(): ShareExpiry
    {
        return app(ShareExpiry::class);
    }

    private function invoice(): SalesInvoice
    {
        $customer = Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => 'عميل الصلاحية',
            'name_en' => 'Expiry Customer',
            'active' => true,
            'created_by' => $this->owner->id,
        ]);

        $draft = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-10-01',
            'lines' => [
                [
                    'item_description' => 'بند الفاتورة',
                    'quantity' => '1',
                    'unit_price' => '100',
                ],
            ],
        ]);

        return app(PostSalesInvoiceAction::class)->execute($draft, $this->owner);
    }

    public function test_catalog_date_rejects_past_date_and_accepts_today_in_company_timezone(): void
    {
        $helper = $this->expiry();

        // 1. In Asia/Hebron (Palestine, UTC+03:00 during summer)
        $nowHebron = Carbon::parse('2026-10-15 14:00:00', 'Asia/Hebron');

        // Past date is rejected
        try {
            $helper->catalogDate('2026-10-14', 'Asia/Hebron', $nowHebron);
            $this->fail('Past date accepted in Asia/Hebron.');
        } catch (InvalidArgumentException $e) {
            $this->assertTrue(true);
        }

        // Today is allowed: returns start of following local day in UTC
        $resTodayHebron = $helper->catalogDate('2026-10-15', 'Asia/Hebron', $nowHebron);
        $this->assertNotNull($resTodayHebron);
        $this->assertSame('UTC', $resTodayHebron->getTimezone()->getName());
        // 2026-10-16 00:00:00 in Asia/Hebron (+03:00) is 2026-10-15 21:00:00 UTC
        $expectedHebronUtc = Carbon::parse('2026-10-16 00:00:00', 'Asia/Hebron')->setTimezone('UTC');
        $this->assertSame($expectedHebronUtc->toIso8601String(), $resTodayHebron->toIso8601String());

        // Future date is allowed
        $resFutureHebron = $helper->catalogDate('2026-10-25', 'Asia/Hebron', $nowHebron);
        $this->assertNotNull($resFutureHebron);
        $expectedFutureUtc = Carbon::parse('2026-10-26 00:00:00', 'Asia/Hebron')->setTimezone('UTC');
        $this->assertSame($expectedFutureUtc->toIso8601String(), $resFutureHebron->toIso8601String());

        // 2. In America/New_York (EDT, UTC-04:00)
        $nowNy = Carbon::parse('2026-10-15 14:00:00', 'America/New_York');

        // Past date is rejected
        try {
            $helper->catalogDate('2026-10-14', 'America/New_York', $nowNy);
            $this->fail('Past date accepted in America/New_York.');
        } catch (InvalidArgumentException $e) {
            $this->assertTrue(true);
        }

        // Today is allowed
        $resTodayNy = $helper->catalogDate('2026-10-15', 'America/New_York', $nowNy);
        $this->assertNotNull($resTodayNy);
        // 2026-10-16 00:00:00 EDT (-04:00) is 2026-10-16 04:00:00 UTC
        $expectedNyUtc = Carbon::parse('2026-10-16 00:00:00', 'America/New_York')->setTimezone('UTC');
        $this->assertSame($expectedNyUtc->toIso8601String(), $resTodayNy->toIso8601String());

        // 3. In Pacific/Honolulu (HST, UTC-10:00)
        $nowHnl = Carbon::parse('2026-10-15 14:00:00', 'Pacific/Honolulu');
        $resTodayHnl = $helper->catalogDate('2026-10-15', 'Pacific/Honolulu', $nowHnl);
        $this->assertNotNull($resTodayHnl);
        // 2026-10-16 00:00:00 HST (-10:00) is 2026-10-16 10:00:00 UTC
        $expectedHnlUtc = Carbon::parse('2026-10-16 00:00:00', 'Pacific/Honolulu')->setTimezone('UTC');
        $this->assertSame($expectedHnlUtc->toIso8601String(), $resTodayHnl->toIso8601String());

        // 4. Strict Y-m-d format validation
        foreach (['2026-02-30', '15-10-2026', '2026/10/15', 'invalid-date', ''] as $badDate) {
            try {
                $helper->catalogDate($badDate, 'Asia/Hebron', $nowHebron);
                $this->fail("Malformed date '{$badDate}' accepted.");
            } catch (InvalidArgumentException|ValidationException $e) {
                $this->assertTrue(true);
            }
        }

        // 5. Null date input returns null
        $this->assertNull($helper->catalogDate(null, 'Asia/Hebron', $nowHebron));
    }

    public function test_catalog_date_boundary_near_midnight_and_entire_selected_day_validity(): void
    {
        $helper = $this->expiry();

        // Simulate near-midnight local time: 23:59:50 in Asia/Hebron
        $nearMidnightHebron = Carbon::parse('2026-10-15 23:59:50', 'Asia/Hebron');
        $expiresUtc = $helper->catalogDate('2026-10-15', 'Asia/Hebron', $nearMidnightHebron);

        $this->assertNotNull($expiresUtc);

        // Simulated grant expiring at this computed instant
        $grant = new PublicShare([
            'is_active' => true,
            'expires_at' => $expiresUtc,
            'revoked_at' => null,
        ]);

        // At 23:59:59 local time (20:59:59 UTC), current time is strictly before expires_at
        Carbon::setTestNow(Carbon::parse('2026-10-15 23:59:59', 'Asia/Hebron'));
        $this->assertFalse($grant->isExpired());
        $this->assertTrue($grant->isValid());

        // At 00:00:00 of the following day (21:00:00 UTC), boundary is reached: grant is expired!
        Carbon::setTestNow(Carbon::parse('2026-10-16 00:00:00', 'Asia/Hebron'));
        $this->assertTrue($grant->isExpired());
        $this->assertFalse($grant->isValid());

        // Repeat for America/New_York
        $nearMidnightNy = Carbon::parse('2026-10-15 23:59:50', 'America/New_York');
        $expiresNyUtc = $helper->catalogDate('2026-10-15', 'America/New_York', $nearMidnightNy);

        $grantNy = new PublicShare([
            'is_active' => true,
            'expires_at' => $expiresNyUtc,
            'revoked_at' => null,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-10-15 23:59:59', 'America/New_York'));
        $this->assertFalse($grantNy->isExpired());
        $this->assertTrue($grantNy->isValid());

        Carbon::setTestNow(Carbon::parse('2026-10-16 00:00:00', 'America/New_York'));
        $this->assertTrue($grantNy->isExpired());
        $this->assertFalse($grantNy->isValid());

        Carbon::setTestNow();
    }

    public function test_catalog_date_and_financial_lifetime_across_dynamic_dst_transitions(): void
    {
        $helper = $this->expiry();

        // 1. America/New_York Spring Forward (March 8, 2026: 23-hour day)
        $springDayNy = '2026-03-08';
        $nowSpring = Carbon::parse('2026-03-08 10:00:00', 'America/New_York');
        $resSpring = $helper->catalogDate($springDayNy, 'America/New_York', $nowSpring);
        // Start of following day is 2026-03-09 00:00:00 EDT (UTC-4) = 2026-03-09 04:00:00 UTC
        $expectedSpringUtc = Carbon::parse('2026-03-09 00:00:00', 'America/New_York')->setTimezone('UTC');
        $this->assertSame($expectedSpringUtc->toIso8601String(), $resSpring?->toIso8601String());

        // 2. America/New_York Fall Back (November 1, 2026: 25-hour day)
        $fallDayNy = '2026-11-01';
        $nowFall = Carbon::parse('2026-11-01 10:00:00', 'America/New_York');
        $resFall = $helper->catalogDate($fallDayNy, 'America/New_York', $nowFall);
        // Start of following day is 2026-11-02 00:00:00 EST (UTC-5) = 2026-11-02 05:00:00 UTC
        $expectedFallUtc = Carbon::parse('2026-11-02 00:00:00', 'America/New_York')->setTimezone('UTC');
        $this->assertSame($expectedFallUtc->toIso8601String(), $resFall?->toIso8601String());

        // 3. Dynamic transition lookup using DateTimeZone
        $tz = new DateTimeZone('America/New_York');
        $transitions = $tz->getTransitions(
            (int) Carbon::parse('2026-01-01')->timestamp,
            (int) Carbon::parse('2026-12-31')->timestamp
        );
        $this->assertNotEmpty($transitions);

        // 4. Financial lifetime: exact N * 86400 seconds in UTC across DST boundaries
        $issuanceSpring = Carbon::parse('2026-03-06 12:00:00', 'UTC');
        $lifetimeSpring = $helper->financialLifetime(7, false, $issuanceSpring);
        $this->assertSame(7 * 86400, ($lifetimeSpring->timestamp - $issuanceSpring->timestamp));

        $issuanceFall = Carbon::parse('2026-10-30 12:00:00', 'UTC');
        $lifetimeFall = $helper->financialLifetime(7, false, $issuanceFall);
        $this->assertSame(7 * 86400, ($lifetimeFall->timestamp - $issuanceFall->timestamp));
    }

    public function test_financial_lifetime_enforces_bounds_min_max_and_defaults(): void
    {
        $helper = $this->expiry();
        $base = Carbon::parse('2026-10-15 10:00:00', 'UTC');

        // Statement: min 1, max 30
        $this->assertSame(86400, ($helper->financialLifetime(1, true, $base)->timestamp - $base->timestamp));
        $this->assertSame(7 * 86400, ($helper->financialLifetime(7, true, $base)->timestamp - $base->timestamp));
        $this->assertSame(30 * 86400, ($helper->financialLifetime(30, true, $base)->timestamp - $base->timestamp));

        foreach ([0, -1, 31, 100] as $badStatementDays) {
            try {
                $helper->financialLifetime($badStatementDays, true, $base);
                $this->fail("Statement lifetime {$badStatementDays} days was accepted.");
            } catch (InvalidArgumentException $e) {
                $this->assertTrue(true);
            }
        }

        // Other documents: min 1, max 365
        $this->assertSame(86400, ($helper->financialLifetime(1, false, $base)->timestamp - $base->timestamp));
        $this->assertSame(30 * 86400, ($helper->financialLifetime(30, false, $base)->timestamp - $base->timestamp));
        $this->assertSame(365 * 86400, ($helper->financialLifetime(365, false, $base)->timestamp - $base->timestamp));

        foreach ([0, -1, 366, 1000] as $badOtherDays) {
            try {
                $helper->financialLifetime($badOtherDays, false, $base);
                $this->fail("Other document lifetime {$badOtherDays} days was accepted.");
            } catch (InvalidArgumentException $e) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_catalog_label_differentiates_catalog_v2_minus_one_second_from_legacy_catalog_v1(): void
    {
        $helper = $this->expiry();

        // 2026-10-16 00:00:00 in Asia/Hebron (+03:00) = 2026-10-15 21:00:00 UTC
        $expiryUtc = Carbon::parse('2026-10-15 21:00:00', 'UTC');

        // catalog_v2: uses local instant minus 1 second date
        $shareV2 = new PublicShare([
            'access_profile' => 'catalog_v2',
            'expires_at' => $expiryUtc,
        ]);
        $this->assertSame('2026-10-15', $helper->catalogLabel($shareV2, 'Asia/Hebron'));

        // legacy catalog_v1: displays actual expiry local day (no minus 1 sec)
        $shareV1 = new PublicShare([
            'access_profile' => 'catalog_v1',
            'expires_at' => $expiryUtc,
        ]);
        $this->assertSame('2026-10-16', $helper->catalogLabel($shareV1, 'Asia/Hebron'));

        // Null expires_at returns null
        $shareNull = new PublicShare([
            'access_profile' => 'catalog_v2',
            'expires_at' => null,
        ]);
        $this->assertNull($helper->catalogLabel($shareNull, 'Asia/Hebron'));
    }

    public function test_display_instant_returns_truthful_local_iso8601_timestamp(): void
    {
        $helper = $this->expiry();

        $instantUtc = Carbon::parse('2026-10-15 21:00:00', 'UTC');

        // In Asia/Hebron (+03:00)
        $this->assertSame('2026-10-16T00:00:00+03:00', $helper->displayInstant($instantUtc, 'Asia/Hebron'));

        // In America/New_York (-04:00)
        $this->assertSame('2026-10-15T17:00:00-04:00', $helper->displayInstant($instantUtc, 'America/New_York'));

        // Null returns null
        $this->assertNull($helper->displayInstant(null, 'Asia/Hebron'));
    }

    public function test_financial_create_share_retains_absolute_expiry_and_supports_lifetime_days_parameter(): void
    {
        $invoice = $this->invoice();
        $service = app(PublicShareService::class);

        // 1. Passing optional lifetimeDays parameter
        $resCustom = $service->createShare(
            $this->company,
            $this->owner,
            PublicShare::SUBJECT_SALES_INVOICE,
            $invoice->id,
            expiresAt: null,
            password: null,
            requestKey: 'test-req-lifetime-14',
            scope: [],
            lifetimeDays: 14
        );

        $shareCustom = $resCustom['share']->fresh();
        $this->assertNotNull($shareCustom->issued_at);
        $this->assertNotNull($shareCustom->expires_at);
        $this->assertSame(14 * 86400, ($shareCustom->expires_at->timestamp - $shareCustom->issued_at->timestamp));

        // 2. Default lifetime for invoice when both null: 30 days
        $resDefault = $service->createShare(
            $this->company,
            $this->owner,
            PublicShare::SUBJECT_SALES_INVOICE,
            $invoice->id,
            expiresAt: null,
            password: null,
            requestKey: 'test-req-default-30',
            scope: []
        );
        $shareDefault = $resDefault['share']->fresh();
        $this->assertSame(30 * 86400, ($shareDefault->expires_at->timestamp - $shareDefault->issued_at->timestamp));

        // 3. Absolute expiry still respected if provided
        $absoluteTarget = Carbon::now()->addDays(5);
        $resAbsolute = $service->createShare(
            $this->company,
            $this->owner,
            PublicShare::SUBJECT_SALES_INVOICE,
            $invoice->id,
            expiresAt: $absoluteTarget,
            password: null,
            requestKey: 'test-req-absolute',
            scope: []
        );
        $this->assertSame(
            $absoluteTarget->toIso8601String(),
            $resAbsolute['share']->fresh()->expires_at?->toIso8601String()
        );

        // 4. Idempotent retry returns original without now-drift
        $originalExpiry = $shareCustom->expires_at->toIso8601String();
        $this->travel(10)->minutes();
        $retry = $service->createShare(
            $this->company,
            $this->owner,
            PublicShare::SUBJECT_SALES_INVOICE,
            $invoice->id,
            expiresAt: null,
            password: null,
            requestKey: 'test-req-lifetime-14',
            scope: [],
            lifetimeDays: 14
        );
        $this->assertSame($shareCustom->id, $retry['share']->id);
        $this->assertSame($originalExpiry, $retry['share']->fresh()->expires_at?->toIso8601String());
        $this->travelBack();
    }

    public function test_genuine_legacy_profile_null_resolves_with_unchanged_timestamp(): void
    {
        $invoice = $this->invoice();
        $rawTimestamp = Carbon::parse('2026-10-25 15:30:45', 'UTC');
        $rawToken = str_repeat('m', 40);

        // Genuine legacy financial share record with NULL access_profile
        $share = PublicShare::create([
            'company_id' => $this->company->id,
            'subject_type' => PublicShare::SUBJECT_SALES_INVOICE,
            'subject_id' => $invoice->id,
            'token_lookup_hash' => hash('sha256', $rawToken),
            'encrypted_token' => Crypt::encryptString($rawToken),
            'is_active' => true,
            'expires_at' => $rawTimestamp,
            'password_hash' => Hash::make('legacy-secret'),
            'access_profile' => null,
            'request_key' => 'legacy-req-01',
            'issued_at' => Carbon::parse('2026-10-01 10:00:00', 'UTC'),
        ]);

        $service = app(PublicShareService::class);

        // Missing password returns password_required
        $missing = $service->resolvePublicShare($rawToken, null);
        $this->assertSame('password_required', $missing['status']);

        // Incorrect password returns password_required with incorrect error
        $wrong = $service->resolvePublicShare($rawToken, 'wrong-password');
        $this->assertSame('password_required', $wrong['status']);
        $this->assertSame('incorrect', $wrong['error'] ?? null);

        // Correct password resolves document data
        $resolved = $service->resolvePublicShare($rawToken, 'legacy-secret');
        $this->assertSame('success', $resolved['status']);
        $this->assertNotEmpty($resolved['data']);

        // resolveShare also resolves successfully
        $shareModel = $service->resolveShare($rawToken, 'legacy-secret');
        $this->assertSame($share->id, $shareModel->id);

        // Genuine legacy timestamp and access_profile remain completely unchanged in DB
        $fresh = $share->fresh();
        $this->assertNull($fresh->access_profile);
        $this->assertSame($rawTimestamp->toIso8601String(), $fresh->expires_at?->toIso8601String());
    }

    public function test_real_financial_service_enforces_statement_and_invoice_defaults_and_bounds(): void
    {
        $invoice = $this->invoice();
        $customer = Customer::where('company_id', $this->company->id)->whereKey($invoice->customer_id)->firstOrFail();
        $service = app(PublicShareService::class);

        $before = $this->economicFingerprint();

        // 1. Customer statement requires a password
        try {
            $service->createShare(
                $this->company,
                $this->owner,
                PublicShare::SUBJECT_CUSTOMER_STATEMENT,
                $customer->id,
                expiresAt: null,
                password: null,
                requestKey: 'stmt-no-pass-req'
            );
            $this->fail('Statement without password was accepted.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('password', strtolower($e->getMessage()));
        }

        // 2. Customer statement default lifetime is 7 days (exact 7 * 86400s)
        $stmtDefault = $service->createShare(
            $this->company,
            $this->owner,
            PublicShare::SUBJECT_CUSTOMER_STATEMENT,
            $customer->id,
            expiresAt: null,
            password: 'statement-password-123',
            requestKey: 'stmt-default-req'
        );
        $this->assertSame(7 * 86400, $stmtDefault['share']->expires_at->getTimestamp() - $stmtDefault['share']->issued_at->getTimestamp());

        // 3. Customer statement lifetime bound: 30 days allowed, 31 days rejected, 0 days rejected
        $stmt30 = $service->createShare(
            $this->company,
            $this->owner,
            PublicShare::SUBJECT_CUSTOMER_STATEMENT,
            $customer->id,
            expiresAt: null,
            password: 'statement-password-123',
            requestKey: 'stmt-30-req',
            lifetimeDays: 30
        );
        $this->assertSame(30 * 86400, $stmt30['share']->expires_at->getTimestamp() - $stmt30['share']->issued_at->getTimestamp());

        try {
            $service->createShare(
                $this->company,
                $this->owner,
                PublicShare::SUBJECT_CUSTOMER_STATEMENT,
                $customer->id,
                expiresAt: null,
                password: 'statement-password-123',
                requestKey: 'stmt-31-req',
                lifetimeDays: 31
            );
            $this->fail('Statement lifetime 31 days was accepted.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('lifetime', strtolower($e->getMessage()));
        }

        try {
            $service->createShare(
                $this->company,
                $this->owner,
                PublicShare::SUBJECT_CUSTOMER_STATEMENT,
                $customer->id,
                expiresAt: null,
                password: 'statement-password-123',
                requestKey: 'stmt-0-req',
                lifetimeDays: 0
            );
            $this->fail('Statement lifetime 0 days was accepted.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('lifetime', strtolower($e->getMessage()));
        }

        // 4. Sales invoice default lifetime is 30 days (exact 30 * 86400s)
        $invDefault = $service->createShare(
            $this->company,
            $this->owner,
            PublicShare::SUBJECT_SALES_INVOICE,
            $invoice->id,
            expiresAt: null,
            password: null,
            requestKey: 'inv-default-req'
        );
        $this->assertSame(30 * 86400, $invDefault['share']->expires_at->getTimestamp() - $invDefault['share']->issued_at->getTimestamp());

        // 5. Sales invoice lifetime bound: 365 days allowed, 366 days rejected, 0 days rejected
        $inv365 = $service->createShare(
            $this->company,
            $this->owner,
            PublicShare::SUBJECT_SALES_INVOICE,
            $invoice->id,
            expiresAt: null,
            password: null,
            requestKey: 'inv-365-req',
            lifetimeDays: 365
        );
        $this->assertSame(365 * 86400, $inv365['share']->expires_at->getTimestamp() - $inv365['share']->issued_at->getTimestamp());

        try {
            $service->createShare(
                $this->company,
                $this->owner,
                PublicShare::SUBJECT_SALES_INVOICE,
                $invoice->id,
                expiresAt: null,
                password: null,
                requestKey: 'inv-366-req',
                lifetimeDays: 366
            );
            $this->fail('Invoice lifetime 366 days was accepted.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('lifetime', strtolower($e->getMessage()));
        }

        try {
            $service->createShare(
                $this->company,
                $this->owner,
                PublicShare::SUBJECT_SALES_INVOICE,
                $invoice->id,
                expiresAt: null,
                password: null,
                requestKey: 'inv-0-req',
                lifetimeDays: 0
            );
            $this->fail('Invoice lifetime 0 days was accepted.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('lifetime', strtolower($e->getMessage()));
        }

        // Zero economic writes beyond fixtures
        $this->assertSame($before, $this->economicFingerprint());
    }

    public function test_financial_service_near_midnight_exact_one_day_elapsed_boundary(): void
    {
        $invoice = $this->invoice();
        $service = app(PublicShareService::class);

        // Issue 30 seconds before midnight UTC
        $issuance = Carbon::parse('2026-10-15 23:59:30', 'UTC');
        Carbon::setTestNow($issuance);

        try {
            $before = $this->economicFingerprint();

            $created = $service->createShare(
                $this->company,
                $this->owner,
                PublicShare::SUBJECT_SALES_INVOICE,
                $invoice->id,
                expiresAt: null,
                password: null,
                requestKey: 'inv-near-midnight-1d',
                lifetimeDays: 1
            );

            $share = $created['share'];
            $rawToken = $created['raw_token'];

            // 1 day = exactly 86,400 seconds: 2026-10-16 23:59:30 UTC
            $expectedExpiry = $issuance->copy()->addSeconds(86400);
            $this->assertSame($expectedExpiry->toIso8601String(), $share->expires_at->toIso8601String());

            // 1 second before expiration: 86,399 seconds elapsed
            Carbon::setTestNow($issuance->copy()->addSeconds(86399));
            $this->assertFalse($share->fresh()->isExpired());
            $this->assertTrue($share->fresh()->isValid());
            $this->assertSame($share->id, app(IssuedFinancialShares::class)->valid($share->fresh())->id);
            $this->get($created['url'])->assertOk();

            // Exact boundary: 86,400 seconds elapsed -> expired
            Carbon::setTestNow($issuance->copy()->addSeconds(86400));
            $this->assertTrue($share->fresh()->isExpired());
            $this->assertFalse($share->fresh()->isValid());
            $boundaryRes = $service->resolvePublicShare($rawToken);
            $this->assertSame('expired', $boundaryRes['status']);
            $this->get($created['url'])->assertNotFound();

            // 1 second after boundary: 86,401 seconds elapsed -> expired
            Carbon::setTestNow($issuance->copy()->addSeconds(86401));
            $this->assertTrue($share->fresh()->isExpired());
            $this->assertFalse($share->fresh()->isValid());
            $afterRes = $service->resolvePublicShare($rawToken);
            $this->assertSame('expired', $afterRes['status']);
            $this->get($created['url'])->assertNotFound();

            // Ensure no economic writes occurred during share issuance and resolutions
            $this->assertSame($before, $this->economicFingerprint());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_expired_past_grant_denies_access_and_is_not_silently_renewed(): void
    {
        $invoice = $this->invoice();
        $service = app(PublicShareService::class);
        $raw = str_repeat('k', 40);

        // Share that expired 2 hours ago
        $expiredGrant = PublicShare::create([
            'company_id' => $this->company->id,
            'subject_type' => PublicShare::SUBJECT_SALES_INVOICE,
            'subject_id' => $invoice->id,
            'token_lookup_hash' => hash('sha256', $raw),
            'encrypted_token' => Crypt::encryptString($raw),
            'is_active' => true,
            'expires_at' => now()->subHours(2),
            'access_profile' => 'deliberate_v1',
            'request_key' => 'expired-test-key-01',
            'issued_at' => now()->subDays(30),
        ]);

        $this->assertTrue($expiredGrant->isExpired());
        $this->assertFalse($expiredGrant->isValid());

        // resolveShare strictly rejects with exception
        try {
            $service->resolveShare($raw);
            $this->fail('resolveShare accepted an expired grant.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('expired', strtolower($e->getMessage()));
        }

        // resolvePublicShare returns 'expired' status
        $statusResult = $service->resolvePublicShare($raw);
        $this->assertSame('expired', $statusResult['status']);

        // Verify that expired grant in database was NOT silently renewed or modified
        $fresh = $expiredGrant->fresh();
        $this->assertTrue($fresh->isExpired());
        $this->assertSame(
            $expiredGrant->expires_at->toIso8601String(),
            $fresh->expires_at?->toIso8601String()
        );
    }

    /** @return array<string,string> */
    private function economicFingerprint(): array
    {
        $result = [];
        foreach (['posting_batches', 'posting_lines', 'stock_movements', 'sales_invoices', 'sales_invoice_lines', 'customer_payments', 'customer_payment_allocations', 'document_sequences'] as $table) {
            $result[$table] = hash('sha256', DB::table($table)->where('company_id', $this->company->id)->orderBy('id')->get()->toJson());
        }

        return $result;
    }
}
