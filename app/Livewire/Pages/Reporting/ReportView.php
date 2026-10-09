<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Reporting;

use App\Application\Reporting\DTO\ReportFilters;
use App\Application\Reporting\DTO\ReportPeriod;
use App\Application\Reporting\Exceptions\ReportingException;
use App\Application\Reporting\Presentation\ReportFilterOptions;
use App\Application\Reporting\Presentation\ReportPresenter;
use App\Application\Reporting\Presentation\ReportRegistry;
use App\Application\Reporting\Presentation\ReportSourceNavigation;
use App\Application\Reporting\Security\ReportingGuard;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
final class ReportView extends Component
{
    #[Locked]
    public string $reportKey;

    /** @var array<string,mixed> */
    #[Url]
    public array $filters = [];

    /** @var array<string,string> */
    public array $selectorSearch = [];

    public function mount(string $reportKey): void
    {
        $this->reportKey = $reportKey;
        $definition = app(ReportRegistry::class)->definition($reportKey);
        if ($this->filters === []) {
            $this->filters = $definition['current'] ? [] : ['preset' => 'this_month'];
        }
        $this->filters = array_replace($definition['defaults'], $this->filters);
        $this->normalizePeriodState();
    }

    public function updatedFiltersPreset(string $value): void
    {
        if ($value !== 'custom') {
            unset($this->filters['from'], $this->filters['to'], $this->filters['start_date'], $this->filters['end_date']);
        }
    }

    public function applyFilters(): void
    {
        $this->resetErrorBag();
        $this->normalizePeriodState();
        $this->filters['page'] = 1;
    }

    public function resetFilters(): void
    {
        $this->resetErrorBag();
        $this->selectorSearch = [];
        $definition = app(ReportRegistry::class)->definition($this->reportKey);
        $this->filters = array_replace($definition['current'] ? [] : ['preset' => 'this_month'], $definition['defaults']);
        $this->normalizePeriodState();
    }

    private function normalizePeriodState(): void
    {
        if ($this->getErrorBag()->has('filters')) {
            return;
        }

        if (isset($this->filters['period'])) {
            $periodRaw = $this->filters['period'];
            $company = app(ReportingGuard::class)->company(app(CompanyContext::class)->company());
            $period = null;

            $hasExplicitDates = false;
            if ($periodRaw instanceof ReportPeriod) {
                $period = $periodRaw;
                $hasExplicitDates = true;
            } elseif (is_array($periodRaw)) {
                $hasExplicitDates = isset($periodRaw['start_date'])
                    || isset($periodRaw['from'])
                    || isset($periodRaw['end_date'])
                    || isset($periodRaw['to']);

                try {
                    $validated = ReportFilters::fromArray($company, ['period' => $periodRaw]);
                    $period = $validated->period;
                } catch (InvalidArgumentException|ReportingException $exception) {
                    $this->addError('filters', __('reports.error'));

                    return;
                }
            } else {
                $this->addError('filters', __('reports.error'));

                return;
            }

            unset($this->filters['period'], $this->filters['start_date'], $this->filters['end_date']);

            if ($hasExplicitDates) {
                $this->filters['preset'] = ReportPeriod::PRESET_CUSTOM;
                $this->filters['from'] = $period->startDate;
                $this->filters['to'] = $period->endDate;
            } else {
                $this->filters['preset'] = $period->preset;
                unset($this->filters['from'], $this->filters['to']);
            }
        }

        if ((isset($this->filters['from']) || isset($this->filters['to'])) && empty($this->filters['preset'])) {
            $this->filters['preset'] = ReportPeriod::PRESET_CUSTOM;
        }

        $preset = $this->filters['preset'] ?? null;
        if ($preset !== null && $preset !== '' && $preset !== 'custom') {
            unset($this->filters['from'], $this->filters['to'], $this->filters['start_date'], $this->filters['end_date']);
        }
    }

    public function goToPage(int $page): void
    {
        if ($page < 1) {
            $this->addError('filters', __('reports.error'));

            return;
        }
        $this->filters['page'] = $page;
    }

    public function render(): View
    {
        $this->normalizePeriodState();
        $registry = app(ReportRegistry::class);
        $company = app(ReportingGuard::class)->company(app(CompanyContext::class)->company());
        abort_unless($registry->allows($company, $this->reportKey), 403);
        $definition = $registry->definition($this->reportKey);
        $result = null;
        $columns = [];
        $totals = [];
        $missing = false;
        $sourceLinks = [];
        foreach ($definition['required'] as $field) {
            if (! isset($this->filters[$field]) || $this->filters[$field] === '') {
                $missing = true;
            }
        }
        if (! $missing && ! $this->getErrorBag()->has('filters')) {
            try {
                $result = $registry->execute($company, $this->reportKey, $this->filters);
                $sourceLinks = app(ReportSourceNavigation::class)->forRows($company, $this->reportKey, $result);
                $columns = app(ReportPresenter::class)->columns($definition['columns'], $result);
                $totals = app(ReportPresenter::class)->totals($result->totals, (string) $company->base_currency_code);
            } catch (InvalidArgumentException|ReportingException $exception) {
                $this->addError('filters', __('reports.error'));
            }
        }

        try {
            $options = app(ReportFilterOptions::class)->forReport($company, $definition, $this->filters, $this->selectorSearch);
        } catch (InvalidArgumentException) {
            $this->addError('selectorSearch', __('reports.error'));
            $options = [];
        }
        $title = $registry->title($this->reportKey);
        $exportUrl = route('reports.export', ['reportKey' => $this->reportKey, 'filters' => $this->filters]);

        return view('livewire.pages.reporting.report', compact('result', 'columns', 'totals', 'definition', 'options', 'title', 'exportUrl', 'missing', 'company', 'sourceLinks'));
    }
}
