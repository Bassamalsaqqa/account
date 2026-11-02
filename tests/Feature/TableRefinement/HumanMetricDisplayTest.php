<?php

declare(strict_types=1);

namespace Tests\Feature\TableRefinement;

use App\Application\Reporting\Presentation\ReportPresenter;
use Tests\TestCase;

final class HumanMetricDisplayTest extends TestCase
{
    public function test_discount_totals_remain_money_and_export_values_remain_exact(): void
    {
        $presenter = new ReportPresenter;
        $raw = ['discounts_base' => '25.000000', 'total_discount_base' => '10.125000', 'discount_lines_count' => '2', 'products_count' => '3'];
        $totals = $presenter->totals($raw, 'ILS');
        $this->assertSame(['ILS', 'ILS', '', ''], array_column($totals, 'currency'));
        $this->assertSame(array_values($raw), array_column($totals, 'value'));
        $columns = array_map(fn (string $key): array => ['key' => $key, 'label' => $key, 'type' => 'decimal'], array_keys($raw));
        $this->assertSame($raw, $presenter->exportRow($raw, $columns));
        $this->assertSame('25.00', $presenter->displayValue($raw, $columns[0]));
        $this->assertSame('10.13', $presenter->displayValue($raw, $columns[1]));
        $this->assertSame('2', $presenter->displayValue($raw, $columns[2]));
    }

    public function test_known_metrics_are_formatted_without_rounding_identifiers_or_quantities(): void
    {
        $presenter = new ReportPresenter;
        foreach ([
            ['amount', '9007199254740993.125000', '9,007,199,254,740,993.13'],
            ['discounts_base', '25.000000', '25.00'],
            ['ILS', '280.000000', '280.00'],
            ['quantity_base', '1.250000', '1.25'],
            ['quantity', '0.000001', '0.000001'],
            ['discount_lines_count', '2.000000', '2'],
            ['exchange_rate', '3.6250000000', '3.625'],
            ['product_id', '00001234', '00001234'],
            ['barcode', '000012340000', '000012340000'],
            ['document_number', '000001', '000001'],
            ['unknown_metric', '1.234567', '1.234567'],
            ['status', 'posted', 'posted'],
        ] as [$key, $value, $expected]) {
            $this->assertSame($expected, $presenter->displayMetric($key, $value), $key);
        }
        $this->assertNull($presenter->displayMetric('amount', null));
    }

    public function test_synthetic_assistant_values_render_both_locales_without_changing_raw_payload(): void
    {
        $values = ['amount' => '280.000000', 'quantity' => '1.250000', 'products_count' => '2.000000', 'barcode' => '000012340000', 'balances' => ['ILS' => '1250.500000']];
        $original = $values;
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $html = view('livewire.ai.partials.turn-values', compact('values'))->render();
            foreach (['280.00', '1.25', '000012340000', 'ILS 1,250.50'] as $display) {
                $this->assertStringContainsString($display, $html);
            }
            foreach (['280.000000', '1.250000', '2.000000', '1250.500000'] as $unformatted) {
                $this->assertStringNotContainsString($unformatted, $html);
            }
        }
        $this->assertSame($original, $values);
    }
}
