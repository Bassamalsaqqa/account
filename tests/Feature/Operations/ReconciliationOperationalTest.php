<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Console\Commands\PayablesReconcileCommand;
use App\Console\Commands\ReconcileAllDomainsCommand;
use App\Models\Company;
use App\Services\Operations\ReconciliationProcessRunner;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class ReconciliationOperationalTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('database.default', 'sqlite');
        Config::set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        DB::purge('sqlite');

        Schema::create('companies', function ($table): void {
            $table->id();
            $table->string('public_id')->unique();
            $table->string('name')->nullable();
            $table->string('name_ar')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('platform_company_health', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('company_id')->unique();
            $table->json('domains');
            $table->timestamp('checked_at')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

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

    public function test_reconciliation_command_fails_on_unknown_company(): void
    {
        config(['reconciliation.enabled' => true]);

        $this->artisan('reconciliation:run', [
            'companyPublicId' => '01JMUNKNOWN0000000000000000',
            '--force' => true,
        ])
            ->expectsOutputToContain('not found')
            ->assertFailed();
    }

    public function test_reconciliation_command_executes_and_displays_table_when_forced(): void
    {
        config(['reconciliation.enabled' => false]);

        Company::create(['public_id' => '01JM0000000000000000000001', 'name' => 'Acme']);

        $runnerMock = Mockery::mock(ReconciliationProcessRunner::class);
        $runnerMock->shouldReceive('runDomain')->times(6)->andReturn([
            'success' => true,
            'timedOut' => false,
            'exitCode' => 0,
            'output' => 'Healthy',
            'errorMessage' => null,
        ]);
        $this->app->instance(ReconciliationProcessRunner::class, $runnerMock);

        $this->artisan('reconciliation:run', ['--force' => true])
            ->expectsOutputToContain('Audit completed')
            ->expectsOutputToContain('All six domains are healthy across all audited companies')
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
