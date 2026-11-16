<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

final class SearchableComboboxComponentRenderTest extends TestCase
{
    public function test_combobox_renders_accessible_aria_attributes(): void
    {
        $html = Blade::render(
            '<x-searchable-combobox
                id="test-customer-selector"
                type="customer"
                context="sales"
                placeholder="ابحث عن العميل..."
                aria-label="العميل"
                :options="[
                    [\'id\' => \'1\', \'label\' => \'شركة الأمل\', \'sublabel\' => \'CUST-001\'],
                    [\'id\' => \'2\', \'label\' => \'مؤسسة النور\', \'sublabel\' => \'CUST-002\']
                ]"
            />'
        );

        // Combobox input accessibility
        $this->assertStringContainsString('role="combobox"', $html);
        $this->assertStringContainsString('aria-autocomplete="list"', $html);
        $this->assertStringContainsString(':aria-expanded="open.toString()"', $html);
        $this->assertStringContainsString(':aria-controls="listboxId"', $html);
        $this->assertStringContainsString(':aria-activedescendant="activeId"', $html);

        // Listbox popup accessibility
        $this->assertStringContainsString('role="listbox"', $html);
        $this->assertStringContainsString(':id="listboxId"', $html);
        $this->assertStringContainsString('role="option"', $html);
    }

    public function test_combobox_ensures_44px_touch_targets_for_mobile(): void
    {
        $html = Blade::render(
            '<x-searchable-combobox
                id="touch-target-check"
                type="product"
                context="sales"
                aria-label="المنتج"
                :options="[
                    [\'id\' => \'10\', \'label\' => \'صنف رقم 10\', \'sublabel\' => \'SKU-10\']
                ]"
            />'
        );

        // min-h-[44px] corresponds to 44px touch targets
        $this->assertStringContainsString('min-h-[44px]', $html);
    }

    public function test_combobox_renders_bidi_isolation_tag_for_sku_and_codes(): void
    {
        $html = Blade::render(
            '<x-searchable-combobox
                id="bidi-check"
                type="product"
                context="sales"
                aria-label="المنتج"
                :options="[
                    [\'id\' => \'10\', \'label\' => \'مشروب غازي\', \'sublabel\' => \'COLA-CAN-330ML\']
                ]"
            />'
        );

        // Secondary text such as SKU or code must be wrapped in <bdi dir="ltr"> to prevent Arabic punctuation inversion
        $this->assertStringContainsString('<bdi dir="ltr"', $html);
    }

    public function test_combobox_includes_clear_button_and_loading_states(): void
    {
        $html = Blade::render(
            '<x-searchable-combobox
                id="clear-btn-check"
                type="customer"
                context="sales"
                selected-id="12"
                selected-label="عميل محدد مسبقا"
                aria-label="العميل"
                :options="[]"
            />'
        );

        // Clear action button
        $this->assertStringContainsString('@click.stop="clear()"', $html);
        // Loading indicator
        $this->assertStringContainsString('x-show="searching"', $html);
        // Empty state announcement
        $this->assertStringContainsString('role="status"', $html);
    }
}
