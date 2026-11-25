<?php

declare(strict_types=1);

namespace Tests\Unit\Operations;

use Tests\TestCase;

class ReconciliationConfigTest extends TestCase
{
    public function test_reconciliation_schedule_is_disabled_by_default(): void
    {
        $this->assertFalse(
            config('reconciliation.enabled'),
            'Reconciliation scheduled orchestration must default to OFF until approved configuration by owner.'
        );
    }

    public function test_reconciliation_alerts_are_disabled_by_default(): void
    {
        $this->assertFalse(
            config('reconciliation.alerts.enabled'),
            'Reconciliation alerts must default to OFF until approved configuration by owner.'
        );

        $this->assertNull(
            config('reconciliation.alerts.recipient'),
            'Reconciliation alert recipient must default to null/empty.'
        );
    }

    public function test_shared_hosting_runtime_bounds_are_configured(): void
    {
        $this->assertSame(180, config('reconciliation.max_execution_seconds'));
        $this->assertSame(300, config('reconciliation.lock_ttl_seconds'));
    }

    public function test_subscription_enforcement_remains_off_by_default(): void
    {
        $this->assertFalse(
            config('subscriptions.enforcement_enabled'),
            'Subscription enforcement must strictly remain OFF by default.'
        );
    }
}
