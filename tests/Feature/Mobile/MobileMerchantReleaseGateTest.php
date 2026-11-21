<?php

declare(strict_types=1);

namespace Tests\Feature\Mobile;

use Tests\TestCase;

final class MobileMerchantReleaseGateTest extends TestCase
{
    public function test_existing_read_api_flag_does_not_activate_new_merchant_actions(): void
    {
        config(['mobile.enabled' => true, 'mobile.merchant_api_enabled' => false]);
        $company = '01M3WMTQQZE7P30Z3KC93N7YJ9';
        foreach (['customers', 'suppliers', 'products', 'inventory/adjustments', 'sales/invoices/draft', 'sales/invoices/preview', 'sales/returns', 'purchases', 'expenses', 'reports/shift-close'] as $path) {
            $this->postJson("/api/mobile/v1/companies/{$company}/{$path}", [])
                ->assertNotFound()->assertJsonPath('error.code', 'MOBILE_MERCHANT_DISABLED');
        }
        $this->getJson("/api/mobile/v1/companies/{$company}/home")
            ->assertNotFound()->assertJsonPath('error.code', 'MOBILE_MERCHANT_DISABLED');
        // Existing native lookup/auth surfaces retain their independent boundary.
        $this->getJson("/api/mobile/v1/companies/{$company}/products")->assertUnauthorized();
    }

    public function test_merchant_gate_is_evaluated_per_request_and_preserves_authentication(): void
    {
        config(['mobile.enabled' => true, 'mobile.merchant_api_enabled' => false]);
        $path = '/api/mobile/v1/companies/01M3WMTQQZE7P30Z3KC93N7YJ9/home';
        $this->getJson($path)->assertNotFound();
        config(['mobile.merchant_api_enabled' => true]);
        $this->getJson($path)->assertUnauthorized();
        config(['mobile.merchant_api_enabled' => false]);
        $this->getJson($path)->assertNotFound()->assertJsonPath('error.code', 'MOBILE_MERCHANT_DISABLED');
    }
}
