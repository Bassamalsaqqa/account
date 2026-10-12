<?php

declare(strict_types=1);

namespace App\Livewire\AI;

use App\Application\AI\AiGovernance;
use App\Application\AI\AiUsageSummary;
use App\Application\AI\DeepSeekQuickConnect;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

#[Layout('layouts.app')]
final class AiSettings extends Component
{
    #[On('ai:usage-changed')]
    public function refreshUsage(): void {}

    public function render(): View
    {
        $context = app(CompanyContext::class);
        abort_unless(auth()->check() && $context->hasCompany(), 403);
        $company = $context->company();
        $actor = auth()->user();
        app(AiGovernance::class)->authorizeCapability($company, $actor, 'ai.settings.manage');
        $isOwner = app(DeepSeekQuickConnect::class)->isOwner($company, $actor);
        $usage = app(AiUsageSummary::class)->read($company, $actor, true);

        return view('livewire.ai.settings', compact('isOwner', 'usage'));
    }
}
