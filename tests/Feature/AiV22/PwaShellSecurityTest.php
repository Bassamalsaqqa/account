<?php

declare(strict_types=1);

namespace Tests\Feature\AiV22;

use Tests\TestCase;

final class PwaShellSecurityTest extends TestCase
{
    public function test_pwa_is_disabled_by_default(): void
    {
        $this->assertFalse(
            config('pwa.enabled'),
            'PWA must be disabled by default.'
        );
    }

    public function test_manifest_and_offline_shell_assets_exist_and_are_neutral(): void
    {
        $manifestPath = public_path('manifest.webmanifest');
        $this->assertFileExists($manifestPath);
        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        $this->assertIsArray($manifest);
        $this->assertSame('/', $manifest['start_url'] ?? null);
        $this->assertSame('standalone', $manifest['display'] ?? null);

        $offlinePath = public_path('offline.html');
        $this->assertFileExists($offlinePath);
        $offlineContent = (string) file_get_contents($offlinePath);
        $this->assertStringContainsString('offline', strtolower($offlineContent));
        $this->assertStringNotContainsString('csrf', strtolower($offlineContent));
        $this->assertStringNotContainsString('livewire', strtolower($offlineContent));

        $swPath = public_path('sw.js');
        $this->assertFileExists($swPath);
        $swContent = (string) file_get_contents($swPath);
        $this->assertStringContainsString('sta-pwa-', $swContent);
        $this->assertStringNotContainsString('__STA_PWA__', $swContent);
    }

    public function test_login_route_renders_pwa_head_tags_only_when_enabled(): void
    {
        config(['pwa.enabled' => false]);
        $responseDisabled = $this->get('/login');
        $responseDisabled->assertOk();
        $responseDisabled->assertDontSee('manifest.webmanifest');
        $responseDisabled->assertDontSee('pwa-enabled');

        config(['pwa.enabled' => true]);
        $responseEnabled = $this->get('/login');
        $responseEnabled->assertOk();
        $responseEnabled->assertSee('manifest.webmanifest');
        $responseEnabled->assertSee('name="pwa-enabled" content="true"', false);
    }
}
