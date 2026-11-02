<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Support\TablePageSize;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

trait HasTablePageSize
{
    #[Url(as: 'per_page', except: 50)]
    public mixed $perPage = TablePageSize::DEFAULT;

    protected function tablePageSize(): int
    {
        return TablePageSize::resolve($this->perPage);
    }

    /**
     * Page already-authorized derived rows only, after complete balances/totals are calculated.
     *
     * @template TKey of array-key
     * @template TValue
     *
     * @param  array<TKey, TValue>|Collection<TKey, TValue>  $rows
     * @return LengthAwarePaginator<int, TValue>
     */
    protected function paginateTableRows(array|Collection $rows, string $pageName): LengthAwarePaginator
    {
        $rows = collect($rows)->values();
        $size = $this->tablePageSize();
        $page = max(1, $this->getPage($pageName));

        return new LengthAwarePaginator($rows->forPage($page, $size)->values(), $rows->count(), $size, $page, ['pageName' => $pageName, 'path' => request()->url()]);
    }

    public function updatedPerPage(): void
    {
        $this->perPage = $this->tablePageSize();
        foreach (array_keys($this->paginators ?: ['page' => 1]) as $pageName) {
            $this->resetPage($pageName);
        }
    }
}
