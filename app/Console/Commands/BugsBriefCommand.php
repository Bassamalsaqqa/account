<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Diagnostics\PlatformBugReportService;
use App\Application\Platform\PlatformAudit;
use App\Models\BugReport;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

final class BugsBriefCommand extends Command
{
    protected $signature = 'bugs:brief {ref? : Bug report ULID public reference} {--open : List bounded open bug reports without untrusted note text} {--limit=20 : Maximum open reports to display (1-50)}';

    protected $description = 'Export a sanitized, delimited agent brief for a bug report or list open reports';

    public function handle(PlatformBugReportService $service): int
    {
        $open = (bool) $this->option('open');
        $ref = $this->argument('ref');

        if ($open || $ref === null) {
            $limit = max(1, min(50, (int) $this->option('limit')));
            $rows = BugReport::query()
                ->whereIn('status', [BugReport::STATUS_NEW, BugReport::STATUS_TRIAGED])
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit($limit)
                ->get(['public_ref', 'status', 'category', 'source', 'route_name', 'release_version', 'created_at']);

            if ($rows->isEmpty()) {
                $this->info('No open bug reports found.');

                return self::SUCCESS;
            }

            foreach ($rows as $row) {
                app(PlatformAudit::class)->record('bug_report.viewed', null, null, ['bug_report_ref' => $row->public_ref, 'channel' => 'cli']);
            }

            $this->table(
                ['Ref', 'Status', 'Category', 'Source', 'Route', 'Release', 'Created (UTC)'],
                $rows->map(static fn (BugReport $r): array => [
                    $r->public_ref,
                    $r->status,
                    $r->category,
                    $r->source,
                    $r->route_name ?? '—',
                    $r->release_version ?? '—',
                    $r->created_at?->utc()->format('Y-m-d H:i:s') ?? '—',
                ])->all()
            );

            return self::SUCCESS;
        }

        $refStr = trim((string) $ref);
        if (! Str::isUlid($refStr) || ! BugReport::query()->where('public_ref', $refStr)->exists()) {
            $this->error('Bug report not found.');

            return self::FAILURE;
        }

        $this->line($service->exportBrief($refStr, null, 'cli'));

        return self::SUCCESS;
    }
}
