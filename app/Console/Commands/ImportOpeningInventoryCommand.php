<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Platform\SupportViewSession;
use App\Models\Company;
use App\Models\User;
use App\Services\Inventory\OpeningInventoryImport;
use App\Services\Inventory\OpeningInventoryWorkbook;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Throwable;

final class ImportOpeningInventoryCommand extends Command
{
    protected $signature = 'inventory:opening-import {workbook} {plan}
        {--company= : Exact existing company public ID}
        {--source-sha= : Exact original XLSX SHA-256}
        {--plan-sha= : Exact reviewed structured plan SHA-256}
        {--operator= : Actual authorized tenant member or explicitly granted system administrator email}
        {--as-owner : Explicit trusted-host system-admin delegation; no customer password}
        {--dry-run : Explicit read-only mode (the default)}
        {--apply : Separately approved production operation; requires interactive confirmation}';

    protected $description = 'Validate or atomically apply a reviewed opening stocktake through canonical services';

    public function handle(OpeningInventoryImport $import): int
    {
        $context = app(CompanyContext::class);
        if ($context->hasCompany() || Auth::check() || ($this->option('apply') && $this->option('dry-run'))) {
            $this->error('Requires a fresh CLI context and exactly one operation mode.');

            return self::FAILURE;
        }
        try {
            $planPath = (string) $this->argument('plan');
            if (! is_file($planPath) || filesize($planPath) > 2000000) {
                $this->error('A bounded private reviewed plan is required.');

                return self::FAILURE;
            }
            $plan = json_decode((string) file_get_contents($planPath), true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($plan)) {
                $this->error('Invalid structured plan.');

                return self::FAILURE;
            }
            $company = Company::where('public_id', (string) $this->option('company'))->sole();
            // An arbitrary --actor ID is not authentication or delegated authority.
            if (! $this->input->isInteractive()) {
                $this->error('Interactive authentication by the actual authorized tenant operator is required.');

                return self::FAILURE;
            }
            if ($this->option('as-owner')) {
                $operator = User::where('email', (string) $this->option('operator'))->sole();
                $sourceSha = (string) $this->option('source-sha');
                $planSha = (string) $this->option('plan-sha');
                app(OpeningInventoryWorkbook::class)->read((string) $this->argument('workbook'), $sourceSha);
                if (! hash_equals($import->hash($plan), $planSha)) {
                    throw new \InvalidArgumentException('Reviewed plan checksum mismatch.');
                }
                $this->line('Trusted host operator: '.$operator->email.'; target company: '.$company->public_id.'; source: '.$sourceSha);
                if ($this->ask('Type the exact approved plan SHA-256 to authorize system-admin execution') !== $planSha) {
                    return self::FAILURE;
                }
                $result = app(SupportViewSession::class)->runFromHost($operator, $company,
                    'Owner-authorized opening inventory; exact source and plan confirmed by trusted host operator', (bool) $this->option('apply'),
                    fn (User $effective): array => $import->run((string) $this->argument('workbook'), $sourceSha, $plan, $planSha, $company, $effective, (bool) $this->option('apply')));
                $this->line(json_encode($result, JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }
            $credentials = ['email' => (string) $this->option('operator'), 'password' => (string) $this->secret('Actual operator password')];
            $provider = Auth::getProvider();
            $authenticated = $provider->retrieveByCredentials($credentials);
            $valid = $authenticated !== null && $provider->validateCredentials($authenticated, $credentials);
            unset($credentials);
            if (! $valid || ! $authenticated instanceof User) {
                $this->error('Interactive authentication by the actual authorized tenant operator is required.');

                return self::FAILURE;
            }
            // No password rehash, Login event, session insertion or synthetic attribution in dry-run.
            // An enrolled second factor must not be bypassed by this one-off CLI entrypoint.
            if ($authenticated->two_factor_secret !== null) {
                $this->error('This CLI does not bypass an enrolled second factor; use a separately reviewed authenticated execution workflow.');

                return self::FAILURE;
            }
            $actor = $authenticated;
            Auth::setUser($actor);
            $context->setCompany($company, $actor);
            $sourceSha = (string) $this->option('source-sha');
            $planSha = (string) $this->option('plan-sha');
            if ($this->option('apply')) {
                $source = app(OpeningInventoryWorkbook::class)->read((string) $this->argument('workbook'), $sourceSha);
                $this->line('Company: '.$company->public_id.'; source rows: '.count($source['rows']).'; supplied quantity: '.$source['quantity'].'; supplied value (before exclusions): '.$source['value']);
                $this->warn('APPLY requires separate Owner approval of this exact company, source and plan. Existing stock will not be overwritten.');
                if ($this->ask('Type the exact approved plan SHA-256 to APPLY') !== $planSha) {
                    $this->error('Apply confirmation mismatch.');

                    return self::FAILURE;
                }
            }
            $result = $import->run((string) $this->argument('workbook'), $sourceSha, $plan, $planSha, $company, $actor, (bool) $this->option('apply'));
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            // Never serialize workbook content, SQL bindings, credentials or other tenant records.
            $message = $exception instanceof \InvalidArgumentException ? $exception->getMessage() : 'Inspect the private reviewed plan and focused diagnostic tests.';
            $this->error('Import refused ('.class_basename($exception).'): '.$message.' No partial import is committed.');

            return self::FAILURE;
        } finally {
            $context->clear();
            Auth::forgetUser();
        }
    }
}
