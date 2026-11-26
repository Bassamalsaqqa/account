<?php

declare(strict_types=1);

namespace App\Application\Diagnostics;

use App\Application\Platform\PlatformAccess;
use App\Application\Platform\PlatformAudit;
use App\Models\BugReport;
use App\Support\TablePageSize;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PlatformBugReportService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, object>
     */
    public function list(array $filters, int $perPage): LengthAwarePaginator
    {
        app(PlatformAccess::class)->authorizeRead('support.bug_reports.view');

        $status = is_string($filters['status'] ?? null) ? trim($filters['status']) : '';
        $category = is_string($filters['category'] ?? null) ? trim($filters['category']) : '';
        $release = is_string($filters['release'] ?? null) ? trim($filters['release']) : '';
        $companyPublicId = is_string($filters['company'] ?? null) ? trim($filters['company']) : '';
        $query = is_string($filters['q'] ?? null) ? trim($filters['q']) : '';
        $from = is_string($filters['from'] ?? null) ? trim($filters['from']) : '';
        $to = is_string($filters['to'] ?? null) ? trim($filters['to']) : '';

        return DB::table('bug_reports')
            ->leftJoin('companies', 'companies.id', '=', 'bug_reports.company_id')
            ->select([
                'bug_reports.id',
                'bug_reports.public_ref',
                'bug_reports.status',
                'bug_reports.category',
                'bug_reports.source',
                'bug_reports.locale',
                'bug_reports.route_name',
                'bug_reports.request_id',
                'bug_reports.failed_request_id',
                'bug_reports.release_version',
                'bug_reports.release_source_sha',
                'bug_reports.fixed_release_sha',
                'bug_reports.is_impersonated',
                'bug_reports.include_diagnostics',
                'bug_reports.created_at',
                'companies.public_id as company_public_id',
                'companies.name_ar as company_name_ar',
                'companies.name_en as company_name_en',
            ])
            ->when(in_array($status, BugReport::STATUSES, true), fn ($q) => $q->where('bug_reports.status', $status))
            ->when(in_array($category, BugReport::CATEGORIES, true), fn ($q) => $q->where('bug_reports.category', $category))
            ->when($release !== '', fn ($q) => $q->where(function ($sub) use ($release): void {
                $sub->where('bug_reports.release_version', $release)
                    ->orWhere('bug_reports.release_source_sha', strtolower($release));
            }))
            ->when($companyPublicId !== '', fn ($q) => $q->where('companies.public_id', $companyPublicId))
            ->when($query !== '', fn ($q) => $q->where(function ($sub) use ($query): void {
                $sub->where('bug_reports.public_ref', $query)
                    ->orWhere('bug_reports.request_id', $query)
                    ->orWhere('bug_reports.failed_request_id', $query);
            }))
            ->when(preg_match('/^\d{4}-\d{2}-\d{2}$/D', $from) === 1, fn ($q) => $q->where('bug_reports.created_at', '>=', $from.' 00:00:00'))
            ->when(preg_match('/^\d{4}-\d{2}-\d{2}$/D', $to) === 1, fn ($q) => $q->where('bug_reports.created_at', '<=', $to.' 23:59:59'))
            ->orderByDesc('bug_reports.created_at')
            ->orderByDesc('bug_reports.id')
            ->paginate(TablePageSize::resolve($perPage))
            ->withQueryString();
    }

    /** @return array{report: BugReport, companyPublicId: ?string, companyNameAr: ?string, companyNameEn: ?string, duplicateOfRef: ?string, brief: string} */
    public function viewDetail(string $publicRef, int $actorId): array
    {
        app(PlatformAccess::class)->authorizeRead('support.bug_reports.view');

        if (! Str::isUlid($publicRef)) {
            abort(404);
        }

        $report = BugReport::query()->where('public_ref', $publicRef)->first();
        if ($report === null) {
            abort(404);
        }

        app(PlatformAudit::class)->record('bug_report.viewed', $actorId, $report->company_id, [
            'bug_report_ref' => $report->public_ref,
            'channel' => 'web',
        ]);

        return $this->hydrateDetails($report);
    }

    public function exportBrief(string $publicRef, ?int $actorId, string $channel): string
    {
        abort_unless(in_array($channel, ['web', 'cli'], true), 403);
        abort_if($channel === 'cli' && ! app()->runningInConsole(), 403);
        if ($channel === 'web') {
            app(PlatformAccess::class)->authorizeRead('support.bug_reports.view');
        }

        if (! Str::isUlid($publicRef)) {
            abort(404);
        }

        $report = BugReport::query()->where('public_ref', $publicRef)->first();
        if ($report === null) {
            abort(404);
        }

        app(PlatformAudit::class)->record('bug_report.brief_exported', $actorId, $report->company_id, [
            'bug_report_ref' => $report->public_ref,
            'channel' => $channel,
        ]);

        return AgentBriefFormatter::format($report);
    }

    /** @param array<string, mixed> $input */
    public function updateTriage(string $publicRef, array $input): BugReport
    {
        app(PlatformAccess::class)->authorize('support.bug_reports.manage');

        if (! Str::isUlid($publicRef)) {
            abort(404);
        }

        $status = $input['status'] ?? null;
        if (! is_string($status) || ! in_array($status, BugReport::STATUSES, true)) {
            throw ValidationException::withMessages(['status' => [__('diagnostics.invalid_triage_status')]]);
        }

        $triageNote = $this->cleanOptionalText($input['triage_note'] ?? null, 'triage_note');
        $resolutionEvidence = $this->cleanOptionalText($input['resolution_evidence'] ?? null, 'resolution_evidence');
        $duplicateOfRef = is_string($input['duplicate_of_ref'] ?? null) ? trim($input['duplicate_of_ref']) : '';
        $fixedSha = is_string($input['fixed_release_sha'] ?? null) ? strtolower(trim($input['fixed_release_sha'])) : '';
        $verified = filter_var($input['verified'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;

        if ($status === BugReport::STATUS_DUPLICATE) {
            if ($duplicateOfRef === '' || ! Str::isUlid($duplicateOfRef)) {
                throw ValidationException::withMessages(['duplicate_of_ref' => [__('diagnostics.duplicate_target_required')]]);
            }
            if (strcasecmp($duplicateOfRef, $publicRef) === 0) {
                throw ValidationException::withMessages(['duplicate_of_ref' => [__('diagnostics.duplicate_cycle_detected')]]);
            }
        }

        if ($status === BugReport::STATUS_FIXED) {
            if (preg_match('/^[0-9a-f]{40}$/D', $fixedSha) !== 1) {
                throw ValidationException::withMessages(['fixed_release_sha' => [__('diagnostics.fixed_sha_required')]]);
            }
            if ($resolutionEvidence === null || mb_strlen($resolutionEvidence, 'UTF-8') < 10) {
                throw ValidationException::withMessages(['resolution_evidence' => [__('diagnostics.resolution_evidence_required')]]);
            }
            if (! $verified) {
                throw ValidationException::withMessages(['verified' => [__('diagnostics.fixed_verification_required')]]);
            }
        }

        if ($status === BugReport::STATUS_CLOSED && ($resolutionEvidence === null || mb_strlen($resolutionEvidence, 'UTF-8') < 5)) {
            throw ValidationException::withMessages(['resolution_evidence' => [__('diagnostics.resolution_evidence_required')]]);
        }

        return DB::transaction(function () use (
            $publicRef,
            $status,
            $triageNote,
            $resolutionEvidence,
            $duplicateOfRef,
            $fixedSha,
        ): BugReport {
            // Re-verify and lock fresh grant status inside the transaction.
            $grant = app(PlatformAccess::class)->authorize('support.bug_reports.manage', lock: true);
            $operatorId = (int) $grant->user_id;

            $report = BugReport::query()->where('public_ref', $publicRef)->lockForUpdate()->first();
            if ($report === null) {
                abort(404);
            }

            $fromStatus = $report->status;
            $duplicateTargetId = null;
            $resolvedDuplicateRef = null;

            if ($status === BugReport::STATUS_DUPLICATE) {
                $target = BugReport::query()->where('public_ref', $duplicateOfRef)->lockForUpdate()->first();
                if ($target === null) {
                    throw ValidationException::withMessages(['duplicate_of_ref' => [__('diagnostics.duplicate_target_missing')]]);
                }

                $this->assertNoDuplicateCycle($report->id, $target);
                $duplicateTargetId = $target->id;
                $resolvedDuplicateRef = $target->public_ref;
            }

            $updates = [
                'status' => $status,
                'duplicate_of_id' => $duplicateTargetId,
                'triage_note' => $triageNote,
                'triaged_by_user_id' => $operatorId,
                'triaged_at' => now(),
            ];

            if ($status === BugReport::STATUS_FIXED) {
                $updates['fixed_release_sha'] = $fixedSha;
                $updates['resolution_evidence'] = $resolutionEvidence;
                $updates['verified_by_user_id'] = $operatorId;
                $updates['verified_at'] = now();
                $updates['resolved_at'] = $report->resolved_at ?? now();
            } elseif ($status === BugReport::STATUS_CLOSED) {
                $updates['resolution_evidence'] = $resolutionEvidence;
                $updates['resolved_at'] = $report->resolved_at ?? now();
            } else {
                $updates['fixed_release_sha'] = null;
                $updates['resolution_evidence'] = $resolutionEvidence;
                $updates['verified_by_user_id'] = null;
                $updates['verified_at'] = null;
                $updates['resolved_at'] = null;
            }

            $report->forceFill($updates)->save();

            app(PlatformAudit::class)->record('bug_report.status_updated', $operatorId, $report->company_id, array_filter([
                'bug_report_ref' => $report->public_ref,
                'from_status' => $fromStatus,
                'to_status' => $report->status,
                'duplicate_of_ref' => $resolvedDuplicateRef,
                'fixed_release_sha' => $report->fixed_release_sha,
                'channel' => 'web',
            ], static fn (mixed $v): bool => $v !== null));

            return $report->fresh() ?? $report;
        });
    }

    private function assertNoDuplicateCycle(int $sourceId, BugReport $target): void
    {
        if ($target->id === $sourceId) {
            throw ValidationException::withMessages(['duplicate_of_ref' => [__('diagnostics.duplicate_cycle_detected')]]);
        }

        $visited = [$sourceId => true, $target->id => true];
        $cursorId = $target->duplicate_of_id;
        $hops = 0;

        while ($cursorId !== null) {
            if (isset($visited[$cursorId]) || ++$hops > 25) {
                throw ValidationException::withMessages(['duplicate_of_ref' => [__('diagnostics.duplicate_cycle_detected')]]);
            }
            $visited[$cursorId] = true;
            $next = BugReport::query()->whereKey($cursorId)->value('duplicate_of_id');
            $cursorId = $next !== null ? (int) $next : null;
        }
    }

    private function cleanOptionalText(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw ValidationException::withMessages([$field => [__('diagnostics.invalid_payload')]]);
        }

        $trimmed = str_replace(["\r\n", "\r"], "\n", trim($value));
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $trimmed) === 1 || mb_strlen($trimmed, 'UTF-8') > 1000) {
            throw ValidationException::withMessages([$field => [__('diagnostics.triage_text_invalid')]]);
        }

        return $trimmed;
    }

    /** @return array{report: BugReport, companyPublicId: ?string, companyNameAr: ?string, companyNameEn: ?string, duplicateOfRef: ?string, brief: string} */
    private function hydrateDetails(BugReport $report): array
    {
        $company = $report->company_id !== null
            ? DB::table('companies')->select(['public_id', 'name_ar', 'name_en'])->where('id', $report->company_id)->first()
            : null;
        $duplicateOfRef = $report->duplicate_of_id !== null
            ? (string) (BugReport::query()->whereKey($report->duplicate_of_id)->value('public_ref') ?? '')
            : null;

        return [
            'report' => $report,
            'companyPublicId' => $company !== null ? (string) $company->public_id : null,
            'companyNameAr' => $company !== null ? (string) $company->name_ar : null,
            'companyNameEn' => $company !== null && $company->name_en !== null ? (string) $company->name_en : null,
            'duplicateOfRef' => $duplicateOfRef !== '' ? $duplicateOfRef : null,
            'brief' => AgentBriefFormatter::format($report),
        ];
    }
}
