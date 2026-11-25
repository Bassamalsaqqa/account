<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ReconciliationAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $payload  Sanitized payload containing zero financial values or PII
     */
    public function __construct(
        public string $alertSubject,
        public array $payload,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[Reconciliation Alert] '.$this->alertSubject,
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: $this->renderHtml(),
        );
    }

    protected function renderText(): string
    {
        $lines = [
            '=== RECONCILIATION OPERATIONAL ALERT ===',
            'Subject: '.$this->alertSubject,
            'Status: '.(string) ($this->payload['status'] ?? 'UNKNOWN'),
            'Timestamp: '.(string) ($this->payload['timestamp'] ?? ''),
            'Companies Audited: '.(string) ($this->payload['companies_audited'] ?? 0),
            'Duration: '.(string) ($this->payload['duration_seconds'] ?? 0).'s',
            '',
            'Domain Summary:',
        ];

        /** @var array<string, array{healthy: bool, violation_count: int}> $domainSummary */
        $domainSummary = is_array($this->payload['domain_summary'] ?? null) ? $this->payload['domain_summary'] : [];
        foreach ($domainSummary as $domain => $data) {
            $healthy = $data['healthy'] ? 'HEALTHY' : 'UNHEALTHY';
            $count = $data['violation_count'];
            $lines[] = " - {$domain}: {$healthy} ({$count} findings)";
        }

        /** @var list<array{company_public_id: string, domain: string, category: string, status?: string, count?: int}> $findings */
        $findings = is_array($this->payload['findings'] ?? null) ? $this->payload['findings'] : [];
        if (! empty($findings)) {
            $lines[] = '';
            $lines[] = 'Allowlisted Findings (Zero raw finding or exception text):';
            foreach ($findings as $finding) {
                $comp = $finding['company_public_id'];
                $dom = $finding['domain'];
                $cat = $finding['category'];
                $status = $finding['status'] ?? 'UNHEALTHY';
                $cnt = $finding['count'] ?? 1;
                $lines[] = " - [{$comp}] [{$dom}] {$cat} (status: {$status}, count: {$cnt})";
            }
        }

        if (! empty($this->payload['error']) && is_array($this->payload['error'])) {
            $lines[] = '';
            $lines[] = 'Error: '.(string) ($this->payload['error']['message'] ?? 'Execution failure');
        }

        return implode("\n", $lines);
    }

    protected function renderHtml(): string
    {
        return '<pre style="font-family: monospace; padding: 16px; background: #f4f4f5; border-radius: 6px;">'
            .htmlspecialchars($this->renderText(), ENT_QUOTES, 'UTF-8')
            .'</pre>';
    }
}
