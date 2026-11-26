<?php

declare(strict_types=1);

namespace Tests\Unit\ProductExperience;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

final class DashboardDetailSliceComponentTest extends TestCase
{
    public function test_page_heading_renders_clean_title_without_ornamental_icon_square(): void
    {
        $html = Blade::render(
            '<x-page-heading title="Dashboard" icon="box"><x-slot:description>Summary</x-slot:description><button>Action</button></x-page-heading>'
        );

        $this->assertStringContainsString('<h1', $html);
        $this->assertStringContainsString('Dashboard', $html);
        $this->assertStringContainsString('Summary', $html);
        $this->assertStringContainsString('Action', $html);
        $this->assertStringNotContainsString('size-10', $html);
        $this->assertStringNotContainsString('bg-info-bg', $html);
    }

    public function test_document_summary_cards_render_single_neutral_divided_band(): void
    {
        $html = Blade::render(
            '<x-document-summary-cards total="12180.00" paid="6000.00" remaining="5640.00" currency="ILS" due-date="2026-12-20" returned="540.00" />'
        );

        $this->assertStringContainsString('gap-px bg-border border border-border rounded-card', $html);
        $this->assertSame(4, substr_count($html, 'document-summary-card bg-surface'));
        $this->assertStringContainsString('12,180.00', $html);
        $this->assertStringContainsString('6,000.00', $html);
        $this->assertStringContainsString('5,640.00', $html);
        $this->assertStringContainsString('540.00', $html);
        $this->assertStringContainsString('2026-12-20', $html);
        $this->assertStringContainsString('dir="ltr"', $html);
    }

    public function test_document_summary_cards_preserve_cards_array_contract(): void
    {
        $cards = [
            ['label' => 'Amount', 'value' => '100.00', 'variant' => 'primary'],
            ['label' => 'Allocated', 'value' => '40.00', 'variant' => 'success'],
            ['label' => 'Payment Date', 'raw_value' => '2026-11-26', 'is_date' => true],
        ];

        $html = Blade::render('<x-document-summary-cards :cards="$cards" currency="ILS" />', ['cards' => $cards]);

        $this->assertStringContainsString('sm:grid-cols-3', $html);
        $this->assertStringContainsString('100.00', $html);
        $this->assertStringContainsString('text-primary', $html);
        $this->assertStringContainsString('40.00', $html);
        $this->assertStringContainsString('text-success', $html);
        $this->assertStringContainsString('2026-11-26', $html);
    }

    public function test_text_input_and_buttons_enforce_control_border_and_auth_preservation(): void
    {
        $input = Blade::render('<x-text-input id="code" class="tracking-widest font-mono text-lg" type="text" />');
        $secondary = Blade::render('<x-secondary-button>Cancel</x-secondary-button>');
        $danger = Blade::render('<x-danger-button>Void</x-danger-button>');

        $this->assertStringContainsString('border-border-control', $input);
        $this->assertStringContainsString('min-h-11 lg:min-h-9.5', $input);
        $this->assertStringContainsString('tracking-widest font-mono text-lg', $input);
        $this->assertStringContainsString('border-border-control', $secondary);
        $this->assertStringContainsString('cursor-pointer', $secondary);
        $this->assertStringContainsString('hover:bg-danger-hover', $danger);
        $this->assertStringContainsString('app-button', $danger);
    }

    public function test_card_and_badge_neutralize_pastel_surface_washouts(): void
    {
        $card = Blade::render('<x-card tone="info"><span>Info</span></x-card>');
        $badge = Blade::render('<x-badge variant="partially_paid">Partial</x-badge>');
        $muted = Blade::render('<x-badge variant="neutral">Open</x-badge>');

        $this->assertStringContainsString('bg-surface', $card);
        $this->assertStringNotContainsString('bg-info-bg', $card);
        $this->assertStringContainsString('bg-primary-50 text-primary', $badge);
        $this->assertStringContainsString('bg-surface-soft text-text-secondary', $muted);
    }

    public function test_dialog_shell_handles_set_close_action_and_accessible_attributes(): void
    {
        $html = Blade::render(
            '<x-dialog-shell title="Void Invoice" close-action="$set(\'showVoidModal\', false)" save-target="voidInvoice"><x-slot:footer><x-secondary-button>Cancel</x-secondary-button></x-slot:footer></x-dialog-shell>'
        );

        $this->assertStringContainsString('role="dialog"', $html);
        $this->assertStringContainsString('aria-modal="true"', $html);
        $this->assertStringContainsString("\$wire.\$set('showVoidModal', false);", $html);
        $this->assertStringNotContainsString("\$wire.\$set('showVoidModal', false)()", $html);
    }

    public function test_css_and_slice_blades_preserve_design_invariants(): void
    {
        $appCss = (string) file_get_contents(resource_path('css/app.css'));
        $dashboardCss = (string) file_get_contents(resource_path('css/business-dashboard.css'));
        $tablesCss = (string) file_get_contents(resource_path('css/data-tables.css'));
        $dashboardBlade = (string) file_get_contents(resource_path('views/livewire/pages/reporting/dashboard.blade.php'));
        $purchaseBlade = (string) file_get_contents(resource_path('views/livewire/pages/purchasing/purchase-detail.blade.php'));
        $invoiceBlade = (string) file_get_contents(resource_path('views/livewire/pages/sales/invoice-detail.blade.php'));

        $this->assertStringContainsString('--color-border-control:', $appCss);
        $this->assertStringContainsString('button:not(.assistant-icon-button), .touch-action, .app-button, .home-action', $appCss);
        $this->assertStringNotContainsString('border-block-start: 3px solid', $appCss);

        $this->assertStringContainsString('background: var(--color-surface);', $dashboardCss);
        $this->assertDoesNotMatchRegularExpression('/\.home-(total|wallet)-(sales|purchases|cash|bank|checks)\s*\{/', $dashboardCss);

        $this->assertStringNotContainsString('font-size: 11px', $tablesCss);
        $this->assertStringContainsString('.data-table thead th', $tablesCss);

        $this->assertStringNotContainsString('w-8 h-8 rounded-lg', $dashboardBlade);
        $this->assertStringContainsString('border-border-control', $dashboardBlade);

        $this->assertStringNotContainsString('bg-slate-100', $purchaseBlade);
        $this->assertStringNotContainsString("__('purchasing.all_statuses')", $purchaseBlade);
        $this->assertStringContainsString("__('purchasing.cost_restricted')", $purchaseBlade);

        $this->assertStringNotContainsString('bg-slate-100', $invoiceBlade);
        $this->assertStringNotContainsString('fixed inset-0 z-50', $invoiceBlade);
        $this->assertStringContainsString('<x-dialog-shell', $invoiceBlade);
    }
}
