<?php

declare(strict_types=1);

namespace App\Livewire\AI;

use App\Application\AI\AssistantUnavailable;
use App\Application\AI\DeepSeekQuickConnect;
use App\Models\AiCompanyPolicy;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use SensitiveParameter;

final class DeepSeekSetup extends Component
{
    #[Locked]
    public int $companyId;

    #[Locked]
    public string $sessionHash;

    #[Locked]
    public string $requestKey;

    #[Locked]
    public string $refreshRequestKey;

    #[Locked]
    public int $policyRevision;

    #[Locked]
    public int $connectionRevision;

    #[Locked]
    public string $status = 'idle';

    #[Locked]
    public ?string $message = null;

    public function mount(): void
    {
        $context = app(CompanyContext::class);
        abort_unless($context->hasCompany() && auth()->check(), 403);
        $this->companyId = (int) $context->company()->id;
        $this->sessionHash = hash('sha256', session()->getId());
        $this->requestKey = (string) Str::uuid();
        $this->refreshRequestKey = (string) Str::uuid();
        $this->authorizeSetup();
        $this->refreshRevisions();
    }

    private function authorizeSetup(): void
    {
        $context = app(CompanyContext::class);
        abort_unless(auth()->check() && $context->hasCompany() && (int) $context->company()->id === $this->companyId
            && hash_equals($this->sessionHash, hash('sha256', session()->getId()))
            && app(DeepSeekQuickConnect::class)->isOwner($context->company(), auth()->user()), 403);
    }

    private function refreshRevisions(): void
    {
        $company = app(CompanyContext::class)->company();
        $this->policyRevision = (int) (AiCompanyPolicy::where('company_id', $company->id)->value('revision') ?? 0);
        $connection = app(DeepSeekQuickConnect::class)->findExistingConnection($company);
        $this->connectionRevision = $connection === null ? 0 : $connection->revision;
    }

    public function testConnection(#[SensitiveParameter] string $key = '', #[SensitiveParameter] string $password = '', bool $consent = true): void
    {
        $this->authorizeSetup();
        $result = app(DeepSeekQuickConnect::class)->testConnection(app(CompanyContext::class)->company(), auth()->user(),
            $key, $password, $consent, $this->requestKey, $this->policyRevision, $this->connectionRevision);
        $this->dispatch('ai:usage-changed');
        $this->status = $result['status'];
        $reason = $result['reason'] ?? ($result['status'] === 'verified' ? 'key_valid_funds_available' : 'unavailable');
        $this->message = __('ai_product.result_'.$reason);
        if ($this->message === 'ai_product.result_'.$reason) {
            $this->message = __('ai_product.result_unavailable');
        }
        $this->refreshRevisions();
    }

    /** Read-only refresh uses existing scoped consent; it cannot configure or replace a key. */
    public function refreshHealth(): void
    {
        $this->authorizeSetup();
        $result = app(DeepSeekQuickConnect::class)->refreshHealth(app(CompanyContext::class)->company(), auth()->user(),
            $this->refreshRequestKey, $this->policyRevision, $this->connectionRevision);
        $this->status = $result['status'];
        $reason = $result['reason'] ?? 'unavailable';
        $this->message = __('ai_product.result_'.$reason);
        if ($this->message === 'ai_product.result_'.$reason) {
            $this->message = __('ai_product.result_unavailable');
        }
        $this->refreshRequestKey = (string) Str::uuid();
        $this->refreshRevisions();
    }

    /** A new provisioning attempt always requires a separate explicit user action. */
    public function allowAnotherTest(): void
    {
        $this->authorizeSetup();
        $this->requestKey = (string) Str::uuid();
        $this->status = 'idle';
        $this->message = null;
        $this->refreshRevisions();
    }

    public function useDeepSeekOnly(#[SensitiveParameter] string $password = ''): void
    {
        $this->authorizeSetup();
        try {
            app(DeepSeekQuickConnect::class)->transition(app(CompanyContext::class)->company(), auth()->user(),
                $password, $this->policyRevision, $this->connectionRevision);
            $this->message = __('ai_product.transition_done');
            $this->refreshRevisions();
        } catch (AssistantUnavailable $e) {
            $this->message = __('ai_product.result_'.$e->diagnosticReason());
        }
    }

    public function enableCompany(#[SensitiveParameter] string $password = ''): void
    {
        $this->authorizeSetup();
        try {
            app(DeepSeekQuickConnect::class)->enableCompany(app(CompanyContext::class)->company(), auth()->user(),
                $password, $this->policyRevision);
            $this->refreshRevisions();
            $this->message = __('ai_product.enabled_done');
            $this->redirect(route('settings.ai'));
        } catch (AssistantUnavailable $e) {
            $this->message = __('ai_product.result_'.$e->diagnosticReason());
            $this->refreshRevisions();
        }
    }

    public function disableCompany(#[SensitiveParameter] string $password = ''): void
    {
        $this->authorizeSetup();
        try {
            app(DeepSeekQuickConnect::class)->disableCompany(
                app(CompanyContext::class)->company(),
                auth()->user(),
                $password,
                $this->policyRevision
            );
            $this->refreshRevisions();
            $this->message = __('ai_product.disabled_done');
            $this->redirect(route('settings.ai'));
        } catch (AssistantUnavailable $e) {
            $this->message = __('ai_product.result_'.$e->diagnosticReason());
            $this->refreshRevisions();
        }
    }

    public function startUsing(): void
    {
        $this->authorizeSetup();
        if (! app(DeepSeekQuickConnect::class)->canStartUsing(app(CompanyContext::class)->company(), auth()->user())) {
            $this->message = __('ai_product.result_unavailable');

            return;
        }
        // Preserve the existing event for an already-mounted launcher; the reload also covers first setup.
        $this->dispatch('ai:open-assistant');
        $this->redirect(route('settings.ai', ['open_assistant' => 1]));
    }

    public function render(): View
    {
        $this->authorizeSetup();
        $company = app(CompanyContext::class)->company();
        $service = app(DeepSeekQuickConnect::class);
        $policy = AiCompanyPolicy::where('company_id', $company->id)->first();

        return view('livewire.ai.deep-seek-setup', [
            'ownerAuthorized' => true,
            'connectionStatus' => $service->getConnectionStatus($company),
            'disclosure' => $service->disclosure($company),
            'needsTransition' => $service->needsTransition($company),
            'latestHealth' => $service->latestHealth($company),
            'canStartUsing' => $service->canStartUsing($company, auth()->user()),
            'companyDisabled' => $policy !== null && ! $policy->enabled,
            'hasPolicy' => $policy !== null,
            'globallyDisabled' => config('ai_assistant.enabled') !== true || in_array((string) $company->id, config('ai_assistant.disabled_company_ids', []), true),
        ]);
    }
}
