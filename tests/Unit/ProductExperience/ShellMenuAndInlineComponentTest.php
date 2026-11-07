<?php

declare(strict_types=1);

namespace Tests\Unit\ProductExperience;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

final class ShellMenuAndInlineComponentTest extends TestCase
{
    public function test_root_route_redirects_to_dashboard(): void
    {
        $response = $this->get('/');
        $response->assertRedirect(route('dashboard'));
    }

    public function test_fortify_home_is_configured_to_dashboard(): void
    {
        $this->assertSame('/dashboard', config('fortify.home'));
    }

    public function test_unauthenticated_request_to_dashboard_redirects_to_login_with_intended_destination(): void
    {
        $response = $this->get('/dashboard');
        $response->assertRedirect(route('login'));
        $this->assertSame(route('dashboard'), session('url.intended'));
    }

    public function test_inline_panel_blade_component_renders_neutral_tone(): void
    {
        $html = Blade::render('<x-inline-panel><span>Content</span></x-inline-panel>');

        $this->assertStringContainsString('app-inline-panel', $html);
        $this->assertStringContainsString('bg-surface-soft', $html);
        $this->assertStringContainsString('border-border', $html);
        $this->assertStringContainsString('shadow-panel', $html);
        $this->assertStringContainsString('rounded-card', $html);
        $this->assertStringContainsString('Content', $html);
    }

    public function test_inline_panel_blade_component_renders_payment_tone(): void
    {
        $html = Blade::render('<x-inline-panel tone="payment" title="Payment Details"><span>Fields</span></x-inline-panel>');

        $this->assertStringContainsString('app-inline-panel', $html);
        $this->assertStringContainsString('bg-primary-50/60', $html);
        $this->assertStringContainsString('border-primary/25', $html);
        $this->assertStringContainsString('Payment Details', $html);
        $this->assertStringContainsString('Fields', $html);
    }

    public function test_inline_panel_renders_fieldset_with_legend(): void
    {
        $html = Blade::render('<x-inline-panel tag="fieldset" legend="Settlement" tone="neutral"><span>Form</span></x-inline-panel>');

        $this->assertStringContainsString('<fieldset', $html);
        $this->assertStringContainsString('</fieldset>', $html);
        $this->assertStringContainsString('<legend', $html);
        $this->assertStringContainsString('Settlement', $html);
    }

    public function test_shared_dropdown_component_binds_accounting_dropdown_contract(): void
    {
        $html = Blade::render('<x-dropdown><x-slot:trigger><button>Open</button></x-slot:trigger><x-slot:content><a>Link</a></x-slot:content></x-dropdown>');

        $this->assertStringContainsString('x-data="accountingDropdown"', $html);
        $this->assertStringContainsString('@click.outside="close(false)"', $html);
        $this->assertStringContainsString('@keydown="handleKeydown($event)"', $html);
    }
}
