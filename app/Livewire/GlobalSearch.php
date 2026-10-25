<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\CompanyUser;
use App\Models\User;
use App\Services\Search\GlobalSearch as SearchService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

class GlobalSearch extends Component
{
    public string $query = '';

    public bool $isOpen = false;

    #[Locked]
    public int $currentCompanyId = 0;

    public function mount(CompanyContext $context): void
    {
        $this->verifyContextAndSyncCompany($context);
    }

    public function hydrate(CompanyContext $context): void
    {
        $this->verifyContextAndSyncCompany($context);
    }

    public function open(): void
    {
        $context = app(CompanyContext::class);
        if (! $this->isActorAuthorized($context)) {
            $this->resetState();

            return;
        }

        $this->isOpen = true;
    }

    public function close(): void
    {
        $this->resetState();
    }

    public function render(CompanyContext $context, SearchService $searchService): View
    {
        $isAuthorized = $this->isActorAuthorized($context);
        $results = [];
        $totalResults = 0;
        $searchFailed = false;

        if ($context->hasCompany()) {
            $activeCompanyId = (int) $context->companyId();
            if ($this->currentCompanyId !== 0 && $this->currentCompanyId !== $activeCompanyId) {
                $this->resetState();
                $this->currentCompanyId = $activeCompanyId;
                $isAuthorized = $this->isActorAuthorized($context);
            }
        } else {
            $isAuthorized = false;
        }

        $trimmedQuery = trim($this->query);
        $hasSearched = mb_strlen($trimmedQuery) >= SearchService::MIN_TERM_LENGTH;

        if ($isAuthorized && $hasSearched) {
            try {
                $results = $searchService->search($this->query);
            } catch (Throwable $exception) {
                report($exception);
                $searchFailed = true;
            }
            foreach ($results as $categoryResults) {
                $totalResults += count($categoryResults);
            }
        } elseif (! $isAuthorized) {
            $this->resetState();
        }

        return view('livewire.global-search', [
            'results' => $results,
            'totalResults' => $totalResults,
            'hasSearched' => $hasSearched,
            'searchFailed' => $searchFailed,
            'isAuthorized' => $isAuthorized,
            'isRtl' => app()->getLocale() === 'ar',
        ]);
    }

    private function verifyContextAndSyncCompany(CompanyContext $context): void
    {
        if (! $context->hasCompany()) {
            $this->resetState();
            $this->currentCompanyId = 0;

            return;
        }

        $activeCompanyId = (int) $context->companyId();

        // Clear/reset stale query on company switch after hydration
        if ($this->currentCompanyId !== 0 && $this->currentCompanyId !== $activeCompanyId) {
            $this->resetState();
        }

        $this->currentCompanyId = $activeCompanyId;
        // Render and search independently verify fresh authority before reading results.
    }

    private function isActorAuthorized(CompanyContext $context): bool
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return false;
        }

        if (! $context->hasCompany()) {
            return false;
        }

        $companyId = (int) $context->companyId();

        // Must match active context user id
        $contextUser = $context->user();
        if (! $contextUser || (int) $contextUser->id !== (int) $user->id) {
            return false;
        }

        // One fresh indexed query checks membership and persisted company status.
        $isMember = CompanyUser::where('company_id', $companyId)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->whereHas('company', fn ($query) => $query->where('status', 'active'))
            ->exists();

        if (! $isMember) {
            return false;
        }

        setPermissionsTeamId($companyId);
        app(PermissionRegistrar::class)->setPermissionsTeamId($companyId);
        $user->unsetRelation('roles')->unsetRelation('permissions');

        return true;
    }

    private function resetState(): void
    {
        $this->query = '';
        $this->isOpen = false;
    }
}
