<?php

namespace App\View\Components;

use App\Support\Tenancy\CompanyContext;
use Illuminate\View\Component;
use Illuminate\View\View;

class AppLayout extends Component
{
    /**
     * Get the view / contents that represents the component.
     */
    public function render(): View
    {
        $context = app(CompanyContext::class);
        $activeCompany = $context->hasCompany() ? $context->company() : null;
        $englishEnabled = $activeCompany ? $activeCompany->isLanguageEnabled('en') : true;

        return view('layouts.app', [
            'activeCompany' => $activeCompany,
            'englishEnabled' => $englishEnabled,
        ]);
    }
}
