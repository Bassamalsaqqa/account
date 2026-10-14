<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Reporting;

use App\Application\Reporting\DTO\ReportPeriod;
use App\Application\Reporting\Presentation\DashboardReports;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
final class Dashboard extends Component
{
    #[Url]
    public string $preset = ReportPeriod::PRESET_THIS_MONTH;

    #[Url]
    public ?string $from = null;

    #[Url]
    public ?string $to = null;

    public function choosePeriod(string $preset): void
    {
        if (! in_array($preset, [
            ReportPeriod::PRESET_TODAY,
            ReportPeriod::PRESET_THIS_WEEK,
            ReportPeriod::PRESET_THIS_MONTH,
            ReportPeriod::PRESET_LAST_MONTH,
        ], true)) {
            return;
        }

        $this->preset = $preset;
        $this->from = null;
        $this->to = null;
        $this->resetErrorBag();
    }

    public function applyPeriod(): void
    {
        $this->resetErrorBag();
    }

    public function openAssistant(): void
    {
        $this->dispatch('ai:open-assistant');
    }

    public function render(): View
    {
        $data = [
            'activity' => [],
            'positions' => [],
            'alerts' => [],
            'today' => '',
            'period' => [
                'preset' => $this->preset,
                'start_date' => '',
                'end_date' => '',
                'timezone' => '',
            ],
            'currency' => '',
            'company_name' => '',
            'comparison' => null,
            'attention' => [],
        ];

        try {
            $company = app(CompanyContext::class)->company();
            $data = app(DashboardReports::class)->read($company, $this->preset, $this->from, $this->to, includeAdditionalPositions: false);
        } catch (InvalidArgumentException $exception) {
            $this->addError('preset', __('reports.error'));
        }

        return view('livewire.pages.reporting.dashboard', compact('data'));
    }
}
