<?php

declare(strict_types=1);

namespace Tests\Feature\ReleaseB1Workflow;

use Tests\TestCase;

class BasicSmokeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:6CUe2hhgD04d5Aic4q6P5q8n3mQ/v3Z9Dk4e2vW+1o8=']);
    }

    public function test_guest_cannot_open_release_workspaces(): void
    {
        foreach (['/products/create', '/invoices/create', '/purchases/create', '/settings'] as $path) {
            $this->get($path)->assertRedirect(route('login'));
        }
    }
}
