@extends('layouts.platform')

@section('breadcrumb')
    <span class="text-border-strong">/</span>
    <a href="{{ route('platform.bug-reports.index') }}" class="hover:text-primary transition-colors text-text-secondary font-semibold">{{ __('platform.bug_reports') }}</a>
    <span class="text-border-strong">/</span>
    <span class="text-text-primary font-mono text-xs font-semibold" dir="ltr">{{ $report->public_ref }}</span>
@endsection

@section('content')
@php
    $platformPermissions = request()->attributes->get('platform_permissions', []);
    $canManage = in_array('support.bug_reports.manage', $platformPermissions, true);
    $badgeVariant = match ($report->status) {
        'fixed' => 'success',
        'triaged' => 'info',
        'duplicate' => 'warning',
        'closed' => 'draft',
        default => 'alert',
    };
    $ctx = is_array($report->sanitized_context) ? $report->sanitized_context : [];
    $serverCtx = is_array($ctx['server'] ?? null) ? $ctx['server'] : [];
    $clientHint = is_array($ctx['client_hint_unverified'] ?? null) ? $ctx['client_hint_unverified'] : [];
@endphp

<div class="space-y-6">
    <div>
        <a href="{{ route('platform.bug-reports.index') }}" class="inline-flex min-h-11 items-center gap-1.5 text-sm font-semibold text-text-secondary hover:text-primary">
            <x-icon name="chevron" class="w-4 h-4 rtl:rotate-180" />
            <span>{{ __('platform.bug_reports') }}</span>
        </a>
    </div>

    <section class="rounded-card border border-border bg-surface p-6 shadow-panel space-y-5">
        <div class="flex flex-wrap items-start justify-between gap-4 border-b border-border pb-4">
            <div>
                <h1 class="font-mono text-lg sm:text-xl font-bold text-text-primary" dir="ltr">{{ $report->public_ref }}</h1>
                <p class="mt-1 text-xs text-text-muted font-mono" dir="ltr">{{ $report->created_at?->utc()->format('Y-m-d H:i:s') }} UTC · {{ $report->source }} · {{ $report->locale }}</p>
            </div>
            <x-badge :variant="$badgeVariant">{{ __('diagnostics.statuses.'.$report->status) }}</x-badge>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <div class="space-y-3">
                <h2 class="text-sm font-bold text-text-primary">{{ __('platform.bug_report_server_scope') }}</h2>
                <dl class="rounded-control border border-border bg-surface-soft/60 p-4 space-y-2 text-xs">
                    <div class="flex justify-between gap-2">
                        <dt class="text-text-muted">{{ __('platform.bug_report_category') }}</dt>
                        <dd class="font-semibold text-text-primary">{{ __('diagnostics.categories.'.$report->category) }}</dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-text-muted">{{ __('platform.bug_report_company') }}</dt>
                        <dd class="font-mono text-text-primary" dir="ltr">{{ $companyPublicId ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-text-muted">{{ __('platform.bug_report_impersonated') }}</dt>
                        <dd class="font-mono text-text-primary" dir="ltr">{{ $report->is_impersonated ? 'true' : 'false' }}</dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-text-muted">{{ __('platform.bug_report_release') }}</dt>
                        <dd class="font-mono text-text-primary break-all" dir="ltr">{{ $report->release_version ?? 'null' }} / {{ $report->release_source_sha ?? 'null' }}</dd>
                    </div>


                    @if($duplicateOfRef)
                        <div class="flex justify-between gap-2">
                            <dt class="text-text-muted">{{ __('platform.bug_report_duplicate_of') }}</dt>
                            <dd><a href="{{ route('platform.bug-reports.show', $duplicateOfRef) }}" class="font-mono text-primary underline" dir="ltr">{{ $duplicateOfRef }}</a></dd>
                        </div>
                    @endif
                </dl>
            </div>

            <div class="space-y-3">
                <h2 class="text-sm font-bold text-text-primary">{{ __('platform.bug_report_client_hints') }}</h2>
                <dl class="rounded-control border border-border bg-surface-soft/60 p-4 space-y-2 text-xs">
                    <div class="flex justify-between gap-2">
                        <dt class="text-text-muted">{{ __('platform.bug_report_request_id') }}</dt>
                        <dd class="font-mono text-text-primary break-all" dir="ltr">{{ $report->request_id ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-text-muted">{{ __('platform.bug_report_failed_request_id') }}</dt>
                        <dd class="font-mono text-text-primary break-all" dir="ltr">{{ $report->failed_request_id ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-text-muted">{{ __('platform.bug_report_route') }}</dt>
                        <dd class="font-mono text-text-primary" dir="ltr">{{ $report->route_name ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-text-muted">Template</dt>
                        <dd class="font-mono text-text-secondary" dir="ltr">{{ $report->screen_path ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-text-muted">Viewport</dt>
                        <dd class="font-mono text-text-secondary" dir="ltr">{{ $clientHint['viewport'] ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-text-muted">Observed Status</dt>
                        <dd class="font-mono text-text-secondary" dir="ltr">{{ $clientHint['failed_status'] ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-text-muted">Diagnostics Included</dt>
                        <dd class="font-mono text-text-secondary" dir="ltr">{{ $report->include_diagnostics ? 'true' : 'false' }}</dd>
                    </div>
                </dl>
            </div>
        </div>

        <div class="rounded-control border border-warning/30 bg-warning-bg/70 p-4 space-y-2">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-sm font-bold text-warning">{{ __('platform.bug_report_note_heading') }}</h2>
                <span class="text-xs font-medium text-warning">{{ __('platform.bug_report_note_warning') }}</span>
            </div>
            <p class="whitespace-pre-wrap break-words rounded-control border border-border bg-surface p-3.5 text-sm text-text-primary">{{ $report->note }}</p>
        </div>
    </section>

    <section class="rounded-card border border-border bg-surface p-6 shadow-panel space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-base font-bold text-text-primary">{{ __('platform.bug_report_brief_heading') }}</h2>
            <div class="flex flex-wrap items-center gap-2">
                <button type="button"
                        id="copy-agent-brief-btn"
                        data-brief-url="{{ route('platform.bug-reports.brief', $report->public_ref) }}"
                        data-copied-label="{{ __('diagnostics.copied') }}"
                        class="min-h-11 inline-flex items-center justify-center rounded-control border border-border bg-surface px-4 text-sm font-semibold text-text-primary hover:bg-surface-soft cursor-pointer">
                    {{ __('platform.bug_report_brief_copy') }}
                </button>
                <a href="{{ route('platform.bug-reports.brief', $report->public_ref) }}"
                   class="min-h-11 inline-flex items-center justify-center rounded-control bg-primary px-4 text-sm font-semibold text-white hover:bg-primary-hover">
                    {{ __('platform.bug_report_brief_download') }}
                </a>
            </div>
        </div>
        <pre id="agent-brief-preview" dir="ltr" class="overflow-x-auto rounded-control border border-border bg-surface-soft p-4 text-xs font-mono text-text-primary leading-relaxed">{{ $brief }}</pre>
    </section>

    @if($canManage)
        <section class="rounded-card border border-border bg-surface p-6 shadow-panel space-y-4">
            <h2 class="text-base font-bold text-text-primary">{{ __('platform.bug_report_triage_heading') }}</h2>

            @if($errors->any())
                <div role="alert" class="rounded-control border border-danger/25 bg-danger-bg p-3.5 text-sm text-danger">
                    <ul class="list-disc ps-5 space-y-1">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('platform.bug-reports.triage', $report->public_ref) }}" class="grid gap-4 sm:grid-cols-2">
                @csrf
                <div>
                    <label for="triage-status" class="block text-sm font-semibold text-text-primary mb-1.5">{{ __('platform.bug_report_status') }}</label>
                    <select id="triage-status" name="status" class="min-h-11 w-full rounded-control border border-border bg-surface px-3 text-sm text-text-primary">
                        @foreach($statuses as $statusOption)
                            <option value="{{ $statusOption }}" @selected(old('status', $report->status) === $statusOption)>{{ __('diagnostics.statuses.'.$statusOption) }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="triage-dup" class="block text-sm font-semibold text-text-primary mb-1.5">{{ __('platform.bug_report_duplicate_of') }}</label>
                    <input id="triage-dup" name="duplicate_of_ref" value="{{ old('duplicate_of_ref', $duplicateOfRef ?? '') }}" maxlength="26" dir="ltr"
                           class="min-h-11 w-full rounded-control border border-border bg-surface px-3 font-mono text-sm text-text-primary">
                </div>

                <div class="sm:col-span-2">
                    <label for="triage-sha" class="block text-sm font-semibold text-text-primary mb-1.5">{{ __('platform.bug_report_fixed_sha') }}</label>
                    <input id="triage-sha" name="fixed_release_sha" value="{{ old('fixed_release_sha', $report->fixed_release_sha ?? '') }}" maxlength="40" dir="ltr"
                           class="min-h-11 w-full rounded-control border border-border bg-surface px-3 font-mono text-sm text-text-primary">
                </div>

                <div>
                    <label for="triage-note" class="block text-sm font-semibold text-text-primary mb-1.5">{{ __('platform.bug_report_triage_note') }}</label>
                    <textarea id="triage-note" name="triage_note" rows="3" maxlength="1000"
                              class="w-full rounded-control border border-border bg-surface p-3 text-sm text-text-primary">{{ old('triage_note', $report->triage_note ?? '') }}</textarea>
                </div>

                <div>
                    <label for="triage-evidence" class="block text-sm font-semibold text-text-primary mb-1.5">{{ __('platform.bug_report_resolution_evidence') }}</label>
                    <textarea id="triage-evidence" name="resolution_evidence" rows="3" maxlength="1000"
                              class="w-full rounded-control border border-border bg-surface p-3 text-sm text-text-primary">{{ old('resolution_evidence', $report->resolution_evidence ?? '') }}</textarea>
                </div>

                <div class="sm:col-span-2">
                    <label class="inline-flex items-center gap-2.5 min-h-11 cursor-pointer">
                        <input type="checkbox" name="verified" value="1" @checked(old('verified', $report->verified_at !== null)) class="h-4 w-4 rounded border-border-strong text-primary">
                        <span class="text-sm font-semibold text-text-primary">{{ __('platform.bug_report_verified_confirm') }}</span>
                    </label>
                </div>

                <div class="sm:col-span-2">
                    <button type="submit" class="min-h-11 inline-flex items-center justify-center rounded-control bg-primary px-6 text-sm font-semibold text-white hover:bg-primary-hover cursor-pointer">
                        {{ __('platform.bug_report_save_triage') }}
                    </button>
                </div>
            </form>
        </section>
    @endif
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const btn = document.getElementById('copy-agent-brief-btn');
    if (!btn) return;
    btn.addEventListener('click', async function () {
        const url = btn.getAttribute('data-brief-url');
        if (!url) return;
        try {
            const res = await fetch(url, { credentials: 'same-origin', cache: 'no-store' });
            if (!res.ok) return;
            const text = await res.text();
            await navigator.clipboard.writeText(text);
            const prev = btn.textContent;
            btn.textContent = btn.getAttribute('data-copied-label') || 'Copied';
            setTimeout(() => { btn.textContent = prev; }, 1500);
        } catch {}
    });
});
</script>
@endsection
