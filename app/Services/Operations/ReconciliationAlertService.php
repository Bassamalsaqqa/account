<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Mail\ReconciliationAlertMail;
use App\Services\Operations\DTO\ReconciliationRunResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class ReconciliationAlertService
{
    /**
     * Dispatches a heartbeat notification for a clean zero-violation run.
     */
    public function sendHeartbeat(ReconciliationRunResult $result): void
    {
        if (! (bool) config('reconciliation.alerts.enabled', false)) {
            return;
        }

        if (! (bool) config('reconciliation.alerts.heartbeat_enabled', true)) {
            return;
        }

        $payload = [
            'event' => 'RECONCILIATION_HEARTBEAT',
            'status' => 'HEALTHY',
            'duration_seconds' => $result->durationSeconds,
            'companies_audited' => $result->companiesAudited,
            'domain_summary' => $result->domainSummary,
            'findings_count' => 0,
            'timestamp' => now()->toIso8601String(),
        ];

        $this->dispatch('Reconciliation Heartbeat: All 6 domains healthy', $payload, 'info');
    }

    /**
     * Dispatches an alert when nonzero findings / integrity violations are detected.
     */
    public function sendNonzeroAlert(ReconciliationRunResult $result): void
    {
        if (! (bool) config('reconciliation.alerts.enabled', false)) {
            // Still log locally as warning for auditability even if external alert is disabled
            Log::warning('Reconciliation integrity violations detected during audit.', [
                'event' => 'RECONCILIATION_NONZERO_FINDINGS',
                'companies_audited' => $result->companiesAudited,
                'findings_count' => $result->totalViolations(),
                'findings' => $result->findings,
            ]);

            return;
        }

        $payload = [
            'event' => 'RECONCILIATION_NONZERO_FINDINGS',
            'status' => 'VIOLATIONS_DETECTED',
            'duration_seconds' => $result->durationSeconds,
            'companies_audited' => $result->companiesAudited,
            'domain_summary' => $result->domainSummary,
            'findings_count' => $result->totalViolations(),
            'findings' => $result->findings,
            'timestamp' => now()->toIso8601String(),
        ];

        $this->dispatch('ALERT: Reconciliation integrity violations detected across domains', $payload, 'warning');
    }

    /**
     * Dispatches an alert when reconciliation run encounters an unhandled execution failure.
     */
    public function sendFailureAlert(Throwable $e, ?ReconciliationRunResult $result = null): void
    {
        $sanitizedError = [
            'exception_class' => get_class($e),
            'message' => 'Operational reconciliation execution failed: '.get_class($e),
            'code' => $e->getCode(),
        ];

        $payload = [
            'event' => 'RECONCILIATION_EXECUTION_FAILURE',
            'status' => 'FAILURE',
            'duration_seconds' => $result !== null ? $result->durationSeconds : 0.0,
            'companies_audited' => $result !== null ? $result->companiesAudited : 0,
            'error' => $sanitizedError,
            'timestamp' => now()->toIso8601String(),
        ];

        // Always log failure locally
        Log::error('Operational reconciliation execution failed.', $payload);

        if (! (bool) config('reconciliation.alerts.enabled', false)) {
            return;
        }

        $this->dispatch('ALERT: Reconciliation execution failure on host', $payload, 'error');
    }

    /**
     * Dispatches the alert to the configured channel.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function dispatch(string $subject, array $payload, string $level): void
    {
        $channel = (string) config('reconciliation.alerts.channel', 'log');

        if ($channel === 'null') {
            return;
        }

        // Always write to operational log
        match ($level) {
            'info' => Log::info($subject, $payload),
            'warning' => Log::warning($subject, $payload),
            'error' => Log::error($subject, $payload),
            default => Log::notice($subject, $payload),
        };

        if ($channel === 'mail') {
            $recipient = config('reconciliation.alerts.recipient');
            if (empty($recipient) || ! is_string($recipient)) {
                Log::notice('Reconciliation email alert skipped: RECONCILIATION_ALERT_RECIPIENT is not configured.');

                return;
            }

            try {
                Mail::to($recipient)->send(new ReconciliationAlertMail($subject, $payload));
            } catch (Throwable $mailException) {
                Log::error('Failed to dispatch reconciliation alert email to recipient.', [
                    'recipient' => '[CONFIGURED_RECIPIENT]',
                    'error' => get_class($mailException),
                ]);
            }
        }
    }
}
