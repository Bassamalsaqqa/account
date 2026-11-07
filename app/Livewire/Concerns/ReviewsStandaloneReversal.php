<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Actions\Purchasing\ReverseVendorPaymentAction;
use App\Actions\Sales\ReverseCustomerPaymentAction;
use App\Models\Company;
use App\Services\Reviews\StandaloneReversalReview;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;

trait ReviewsStandaloneReversal
{
    /** @var array<string, mixed> */
    #[Locked]
    public array $reversalReview = [];

    public function closeReversalReview(): void
    {
        $this->reversalReview = [];
    }

    public function openReversalReview(string $kind = '', int $id = 0): void
    {
        $this->resetErrorBag();
        $this->reversalReview = DB::transaction(fn () => $this->buildReversalReview($kind, $id));
    }

    /** @return array<string, mixed> */
    private function buildReversalReview(string $kind, int $id, bool $lock = true): array
    {
        $companyId = (int) app(CompanyContext::class)->companyId();
        abort_unless($companyId === $this->pageCompanyId, 403);
        if ($lock) {
            Company::whereKey($companyId)->lockForUpdate()->firstOrFail();
        }

        return app(StandaloneReversalReview::class)->build($this, $kind, $id, $companyId, $lock);
    }

    public function renderingReviewsStandaloneReversal(): void
    {
        if ($this->reversalReview === []) {
            return;
        }
        try {
            $fresh = $this->buildReversalReview($this->reversalReview['kind'], $this->reversalReview['id'], false);
            $this->reversalReview['rows'] = $fresh['rows'];
            $this->reversalReview['recovery'] = $fresh['recovery'];
        } catch (AuthorizationException) {
            $this->closeReversalReview();
        }
    }

    public function confirmReversalReview(): void
    {
        abort_if($this->reversalReview === [], 403);
        $this->resetErrorBag();
        DB::transaction(function (): void {
            $plan = $this->reversalReview;
            $fresh = $this->buildReversalReview($plan['kind'], $plan['id']);
            if (Arr::only($fresh, ['actor', 'kind', 'id', 'method', 'digest', 'blocked']) !== Arr::only($plan, ['actor', 'kind', 'id', 'method', 'digest', 'blocked'])) {
                $this->reversalReview = $fresh;
                $this->addError('review', __('standalone.stale'));

                return;
            }
            if ($plan['blocked']) {
                $this->addError('review', __('standalone.blocked'));

                return;
            }
            $method = 'executeReviewed'.$plan['method'];
            $args = match ($plan['kind']) {
                'advance' => [$plan['id']], 'salary' => [$plan['id']], 'salary_payment' => [$plan['id']],
                'customer' => [app(ReverseCustomerPaymentAction::class), app(CompanyContext::class)],
                'vendor' => [app(ReverseVendorPaymentAction::class), app(CompanyContext::class)],
                default => [],
            };
            $this->$method(...$args);
            if ($this->getErrorBag()->isEmpty()) {
                $this->reversalReview = [];
            }
        });
    }
}
