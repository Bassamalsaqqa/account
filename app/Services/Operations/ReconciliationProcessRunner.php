<?php

declare(strict_types=1);

namespace App\Services\Operations;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

class ReconciliationProcessRunner
{
    /**
     * Executes a domain reconciliation command in a bounded child process.
     * Enforces process timeout and actual termination via SIGTERM/SIGKILL on timeout.
     *
     * @return array{
     *     success: bool,
     *     timedOut: bool,
     *     exitCode: int|null,
     *     output: string,
     *     errorMessage: string|null
     * }
     */
    public function runDomain(string $commandName, string $companyPublicId, float $timeoutSeconds): array
    {
        return $this->executeCommand([
            PHP_BINARY,
            base_path('artisan'),
            $commandName,
            $companyPublicId,
        ], $timeoutSeconds);
    }

    /**
     * Runs a command array with bounded timeout and actual process termination.
     *
     * @param  list<string>  $command
     * @return array{
     *     success: bool,
     *     timedOut: bool,
     *     exitCode: int|null,
     *     output: string,
     *     errorMessage: string|null
     * }
     */
    public function executeCommand(array $command, float $timeoutSeconds): array
    {
        $process = new Process($command);
        $process->setTimeout($timeoutSeconds);

        try {
            $process->run();

            return [
                'success' => $process->isSuccessful(),
                'timedOut' => false,
                'exitCode' => $process->getExitCode(),
                'output' => $process->getOutput(),
                'errorMessage' => null,
            ];
        } catch (ProcessTimedOutException) {
            // Enforce actual termination on Unix (SIGTERM then SIGKILL)
            $process->stop(3.0);

            return [
                'success' => false,
                'timedOut' => true,
                'exitCode' => null,
                'output' => $process->getOutput(),
                'errorMessage' => "Domain process timed out after {$timeoutSeconds}s and was terminated.",
            ];
        } catch (Throwable $e) {
            $process->stop(1.0);

            return [
                'success' => false,
                'timedOut' => false,
                'exitCode' => $process->getExitCode() ?? 1,
                'output' => $process->getOutput(),
                'errorMessage' => ReconciliationAlertSanitizer::redactExceptionMessage($e),
            ];
        }
    }
}
