<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Console\Commands\PayablesReconcileCommand;
use App\Console\Commands\ReconcileAllDomainsCommand;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class ReconciliationOperationalTest extends TestCase
{
    public function test_scheduler_preserves_ai_assistant_prune_and_registers_reconciliation(): void
    {
        /** @var Schedule $schedule */
        $schedule = app(Schedule::class);

        $events = collect($schedule->events());

        // 1. Verify ai-assistant:prune is scheduled hourly without overlapping
        $aiPruneEvent = $events->first(function ($event): bool {
            return str_contains((string) $event->command, 'ai-assistant:prune');
        });

        $this->assertNotNull($aiPruneEvent, 'ai-assistant:prune must be scheduled.');
        $this->assertSame('0 * * * *', $aiPruneEvent->expression, 'ai-assistant:prune must be hourly.');
        $this->assertTrue($aiPruneEvent->withoutOverlapping, 'ai-assistant:prune must be without overlapping.');

        // 2. Verify reconciliation:run is scheduled
        $reconcileEvent = $events->first(function ($event): bool {
            return str_contains((string) $event->command, 'reconciliation:run');
        });

        $this->assertNotNull($reconcileEvent, 'reconciliation:run must be scheduled in routes/console.php.');
        $this->assertSame('0 2 * * *', $reconcileEvent->expression, 'reconciliation:run must be scheduled daily at 02:00.');
        $this->assertTrue($reconcileEvent->withoutOverlapping, 'reconciliation:run must be without overlapping.');
    }

    public function test_reconciliation_command_refuses_when_disabled(): void
    {
        config(['reconciliation.enabled' => false]);

        $this->artisan('reconciliation:run')
            ->expectsOutputToContain('Reconciliation orchestration is disabled in configuration')
            ->assertSuccessful();
    }

    public function test_payables_reconcile_command_is_registered_with_aliases(): void
    {
        $command = app(PayablesReconcileCommand::class);

        $this->assertSame('payables:reconcile {companyPublicId? : The ULID public ID of a specific company to reconcile}', $command->getName().' '.'{companyPublicId? : The ULID public ID of a specific company to reconcile}');
        $this->assertContains('purchasing:reconcile', $command->getAliases());
    }

    public function test_reconcile_all_domains_command_has_aliases(): void
    {
        $command = app(ReconcileAllDomainsCommand::class);

        $this->assertContains('reconciliation:orchestrate', $command->getAliases());
        $this->assertContains('platform:reconcile-all', $command->getAliases());
    }
}
