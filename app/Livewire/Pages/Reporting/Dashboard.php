<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Reporting;

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
    public string $preset = 'this_month';

    #[Url]
    public ?string $from = null;

    #[Url]
    public ?string $to = null;

    public function applyPeriod(): void
    {
        $this->resetErrorBag();
    }

    public function render(): View
    {
        $data = ['activity' => [], 'positions' => [], 'alerts' => [], 'today' => ''];
        try {
            $data = app(DashboardReports::class)->read(app(CompanyContext::class)->company(), $this->preset, $this->from, $this->to);
        } catch (InvalidArgumentException $exception) {
            $this->addError('preset', __('reports.error'));
        }

        return view('livewire.pages.reporting.dashboard', compact('data'));
    }
}
