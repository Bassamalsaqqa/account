<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Reporting;

use App\Application\Reporting\Presentation\ReportRegistry;
use App\Application\Reporting\Security\ReportingGuard;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
final class ReportHub extends Component
{
    public function render(): View
    {
        $company = app(ReportingGuard::class)->company(app(CompanyContext::class)->company());
        $groups = [];
        foreach (app(ReportRegistry::class)->visible($company) as $key => $definition) {
            $groups[$definition['group']][] = ['key' => $key, 'title' => app(ReportRegistry::class)->title($key)];
        }

        return view('livewire.pages.reporting.hub', compact('groups'));
    }
}
