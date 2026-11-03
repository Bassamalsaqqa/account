<?php

declare(strict_types=1);

namespace App\View\Components;

use App\Models\Company;
use App\Support\Settings\SettingsNavigation;
use App\Support\Tenancy\CompanyContext;
use Illuminate\View\Component;
use Illuminate\View\View;

class SettingsShell extends Component
{
    public string $activeSection;

    public ?Company $company;

    /**
     * @var array<string, array{
     *     key: string,
     *     label: string,
     *     icon: string,
     *     url: string,
     *     is_livewire_section: bool
     * }>
     */
    public array $sections;

    public function __construct(string $activeSection = 'identity', ?Company $company = null)
    {
        $this->activeSection = $activeSection;
        $context = app(CompanyContext::class);
        $this->company = $company ?? ($context->hasCompany() ? $context->company() : null);
        $this->sections = SettingsNavigation::sections($this->company);
    }

    public function currentSectionLabel(): string
    {
        return $this->sections[$this->activeSection]['label'] ?? ucfirst($this->activeSection);
    }

    public function render(): View
    {
        return view('components.settings-shell');
    }
}
