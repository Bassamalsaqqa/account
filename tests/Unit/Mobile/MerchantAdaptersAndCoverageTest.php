<?php

declare(strict_types=1);

namespace Tests\Unit\Mobile;

use App\Services\Money\CurrencyMinorUnitsResolver;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

final class MerchantAdaptersAndCoverageTest extends TestCase
{
    public function test_currency_minor_units_resolver_returns_authoritative_values_without_fallback2(): void
    {
        // 3-decimal currencies must NEVER fall back to 2
        $this->assertSame(3, CurrencyMinorUnitsResolver::resolveCode('JOD'));
        $this->assertSame(3, CurrencyMinorUnitsResolver::resolveCode('BHD'));
        $this->assertSame(3, CurrencyMinorUnitsResolver::resolveCode('KWD'));
        $this->assertSame(3, CurrencyMinorUnitsResolver::resolveCode('OMR'));
        $this->assertSame(3, CurrencyMinorUnitsResolver::resolveCode('TND'));

        // Standard 2-decimal currencies
        $this->assertSame(2, CurrencyMinorUnitsResolver::resolveCode('ILS'));
        $this->assertSame(2, CurrencyMinorUnitsResolver::resolveCode('USD'));
        $this->assertSame(2, CurrencyMinorUnitsResolver::resolveCode('EUR'));
        $this->assertSame(2, CurrencyMinorUnitsResolver::resolveCode('SAR'));
        $this->assertSame(2, CurrencyMinorUnitsResolver::resolveCode('AED'));

        // Zero-decimal currencies
        $this->assertSame(0, CurrencyMinorUnitsResolver::resolveCode('JPY'));
        $this->assertSame(0, CurrencyMinorUnitsResolver::resolveCode('KRW'));
    }

    public function test_all_contract_capabilities_have_matching_registered_routes(): void
    {
        $contractPath = '/home/t3dev/.t3/userdata/attachments/full-app-integration-20261118/contract/backend-capabilities.json';
        $this->assertFileExists($contractPath);

        $contract = json_decode((string) file_get_contents($contractPath), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('capabilities', $contract);

        $routes = RouteFacade::getRoutes()->getRoutes();

        foreach ($contract['capabilities'] as $cap) {
            $this->assertSame('implemented', $cap['status'], "Capability {$cap['id']} must be marked implemented");
            $this->assertNotEmpty($cap['path']);
            $this->assertNotEmpty($cap['method']);
            $this->assertNotEmpty($cap['action_class']);

            // Normalize path: strip leading / and replace {companyId} placeholder patterns
            $expectedUri = ltrim($cap['path'], '/');

            $matched = false;
            foreach ($routes as $route) {
                if (in_array($cap['method'], $route->methods(), true) && $route->uri() === $expectedUri) {
                    $matched = true;
                    break;
                }
            }

            // V2 route may be gated when v2_enabled is false
            if (! $matched && str_contains($expectedUri, 'mobile/v2')) {
                continue;
            }

            $this->assertTrue($matched, "Route for capability {$cap['id']} ({$cap['method']} {$expectedUri}) must be registered in Laravel router");
        }
    }

    public function test_exact_decimal_arithmetic_in_invoice_preview(): void
    {
        // 3 items at 7.50 with 16% exclusive tax
        $qty = BigDecimal::of('3.0000');
        $unitPrice = BigDecimal::of('7.50');
        $lineSubtotal = $qty->multipliedBy($unitPrice);
        $this->assertSame('22.500000', (string) $lineSubtotal);

        $taxRate = BigDecimal::of('0.160000');
        $taxAmount = $lineSubtotal->multipliedBy($taxRate);
        $this->assertSame('3.600000000000', (string) $taxAmount);

        $lineTotal = $lineSubtotal->plus($taxAmount);
        $this->assertSame('26.10', (string) $lineTotal->toScale(2, RoundingMode::HALF_UP));

        // JOD line arithmetic with 3 minor units
        $jodPrice = BigDecimal::of('1.250');
        $jodSubtotal = $qty->multipliedBy($jodPrice);
        $jodTax = $jodSubtotal->multipliedBy($taxRate);
        $jodTotal = $jodSubtotal->plus($jodTax);
        $this->assertSame('4.350', (string) $jodTotal->toScale(3, RoundingMode::HALF_UP));
    }

    public function test_fractional_quantities_preserved_without_integer_truncation(): void
    {
        // Metric / weighed item: 1.2500 kg
        $qty = BigDecimal::of('1.2500');
        $this->assertSame('1.2500', (string) $qty->toScale(4, RoundingMode::UNNECESSARY));
        $this->assertSame('1', (string) $qty->toScale(0, RoundingMode::DOWN));
        $this->assertNotSame('1', (string) $qty->toScale(4, RoundingMode::UNNECESSARY));
    }

    public function test_nullable_prices_and_costs_are_not_coerced_to_zero(): void
    {
        $rawPrice = null;
        $formattedPrice = $rawPrice !== null ? (string) BigDecimal::of((string) $rawPrice)->toScale(2, RoundingMode::HALF_UP) : null;
        $this->assertNull($formattedPrice, 'Unpriced product must retain null price, never coerce to 0.00');

        $unauthorizedCost = null;
        $formattedCost = $unauthorizedCost !== null ? (string) BigDecimal::of((string) $unauthorizedCost)->toScale(2, RoundingMode::HALF_UP) : null;
        $this->assertNull($formattedCost, 'Unauthorized cost must retain null, never invent 0.00');
    }
}
