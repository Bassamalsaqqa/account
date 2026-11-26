<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Diagnostics\PlatformBugReportService;
use App\Models\BugReport;
use App\Support\TablePageSize;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class PlatformBugReportController extends Controller
{
    public function index(Request $request, PlatformBugReportService $service): View
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:'.implode(',', BugReport::STATUSES)],
            'category' => ['nullable', 'in:'.implode(',', BugReport::CATEGORIES)],
            'release' => ['nullable', 'string', 'max:64'],
            'company' => ['nullable', 'string', 'max:32'],
            'q' => ['nullable', 'string', 'max:64'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $reports = $service->list($validated, TablePageSize::fromRequest());

        return view('platform.bug-reports.index', [
            'reports' => $reports,
            'filters' => $validated,
            'statuses' => BugReport::STATUSES,
            'categories' => BugReport::CATEGORIES,
        ]);
    }

    public function show(Request $request, string $publicRef, PlatformBugReportService $service): View
    {
        $details = $service->viewDetail($publicRef, (int) $request->user()->id);

        return view('platform.bug-reports.show', $details + [
            'statuses' => BugReport::STATUSES,
        ]);
    }

    public function brief(Request $request, string $publicRef, PlatformBugReportService $service): Response
    {
        $brief = $service->exportBrief($publicRef, (int) $request->user()->id, 'web');
        $safeFilename = 'brief-'.preg_replace('/[^A-Za-z0-9_-]/', '', $publicRef).'.txt';

        return response($brief, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$safeFilename.'"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function triage(Request $request, string $publicRef, PlatformBugReportService $service): RedirectResponse
    {
        $service->updateTriage($publicRef, $request->only([
            'status',
            'duplicate_of_ref',
            'triage_note',
            'fixed_release_sha',
            'resolution_evidence',
            'verified',
        ]));

        return redirect()
            ->route('platform.bug-reports.show', $publicRef)
            ->with('platform_success', __('diagnostics.triage_updated'));
    }
}
