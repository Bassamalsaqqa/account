<?php

declare(strict_types=1);

namespace App\Livewire\AI;

use App\Application\AI\AiGovernance;
use App\Application\AI\AiUsageSummary;
use App\Application\AI\AssistantReportCatalog;
use App\Application\AI\AssistantReportPolicySettings;
use App\Application\AI\AssistantUnavailable;
use App\Application\AI\DeepSeekQuickConnect;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use SensitiveParameter;

#[Layout('layouts.app')]
final class AiSettings extends Component
{
    #[Locked]
    public int $companyId;

    #[Locked]
    public string $sessionHash;

    #[Locked]
    public ?int $reportPolicyRevision = null;

    /** @var list<string> */
    public array $reportAllowlist = [];

    #[Locked]
    public ?string $reportPolicyMessage = null;

    public function mount(): void
    {
        $context = app(CompanyContext::class);
        abort_unless(auth()->check() && $context->hasCompany(), 403);
        $this->companyId = (int) $context->company()->id;
        $this->sessionHash = hash('sha256', session()->getId());
        if (app(DeepSeekQuickConnect::class)->isOwner($context->company(), auth()->user())) {
            $this->refreshReportPolicy();
        }
    }

    public function refreshReportPolicy(): void
    {
        $this->authorizeReportSettings();
        try {
            $state = app(AssistantReportPolicySettings::class)->read(app(CompanyContext::class)->company(), auth()->user());
            $this->reportPolicyRevision = $state['revision'] ?? null;
            $this->reportAllowlist = $state['allowed_reports'] ?? [];
            $this->reportPolicyMessage = null;
        } catch (AssistantUnavailable) {
            $this->reportPolicyRevision = null;
            $this->reportAllowlist = [];
            $this->reportPolicyMessage = __('assistant_policy.invalid');
        }
    }

    public function saveReportPolicy(#[SensitiveParameter] string $password = ''): void
    {
        $this->authorizeReportSettings();
        try {
            if ($this->reportPolicyRevision === null) {
                throw new AssistantUnavailable('policy_invalid');
            }
            $policy = app(AssistantReportPolicySettings::class)->update(app(CompanyContext::class)->company(),
                auth()->user(), $this->reportAllowlist, $this->reportPolicyRevision, $password);
            $this->reportPolicyRevision = (int) $policy->revision;
            $this->reportPolicyMessage = __('assistant_policy.saved');
        } catch (AuthorizationException) {
            $this->reportPolicyMessage = __('assistant_policy.authorization_failed');
        } catch (ValidationException) {
            $this->reportPolicyMessage = __('assistant_policy.invalid');
        } catch (AssistantUnavailable $e) {
            $this->reportPolicyMessage = __($e->diagnosticReason() === 'proposal_stale'
                ? 'assistant_policy.stale' : 'assistant_policy.invalid');
        }
    }

    private function authorizeReportSettings(): void
    {
        $context = app(CompanyContext::class);
        abort_unless(auth()->check() && $context->hasCompany() && (int) $context->company()->id === $this->companyId
            && hash_equals($this->sessionHash, hash('sha256', session()->getId()))
            && app(DeepSeekQuickConnect::class)->isOwner($context->company(), auth()->user()), 403);
    }

    #[On('ai:usage-changed')]
    public function refreshUsage(): void {}

    public function render(): View
    {
        $context = app(CompanyContext::class);
        abort_unless(auth()->check() && $context->hasCompany(), 403);
        $company = $context->company();
        abort_unless((int) $company->id === $this->companyId
            && hash_equals($this->sessionHash, hash('sha256', session()->getId())), 403);
        $actor = auth()->user();
        app(AiGovernance::class)->authorizeCapability($company, $actor, 'ai.settings.manage');
        $isOwner = app(DeepSeekQuickConnect::class)->isOwner($company, $actor);
        $usage = app(AiUsageSummary::class)->read($company, $actor, true);
        $reportOptions = [];
        if ($isOwner) {
            foreach (AssistantReportCatalog::KEYS as $key) {
                $definition = app(AssistantReportCatalog::class)->definition($key);
                $reportOptions[$key] = $definition[app()->getLocale() === 'ar' ? 'name_ar' : 'name_en'];
            }
        }

        return view('livewire.ai.settings', compact('isOwner', 'usage', 'reportOptions'));
    }
}
