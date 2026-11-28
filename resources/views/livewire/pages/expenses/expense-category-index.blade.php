<div class="max-w-4xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <div class="flex items-center gap-3">
            <a href="{{ route('expenses.index') }}" aria-label="{{ __('expenses.expenses') }}" class="touch-action border border-border bg-surface text-text-secondary hover:bg-surface-soft">
                &larr;
            </a>
            <div>
                <h1 class="text-2xl font-bold">{{ __('expenses.categories') }}</h1>
                <p class="text-sm text-text-muted">{{ __('expenses.expenses') }}</p>
            </div>
        </div>
    </div>

    @if(session()->has('success'))
        <div class="rounded-control border border-success/20 bg-success-bg p-4 text-sm text-success">
            {{ session('success') }}
        </div>
    @endif

    <!-- Add Category Form -->
    <form wire:submit.prevent="createCategory" class="rounded-card border border-border bg-surface p-6 space-y-4">
        <h2 class="font-bold text-base text-text-primary">{{ __('expenses.create_category') }}</h2>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="block text-sm font-semibold text-text-secondary mb-1">{{ __('expenses.name_ar') }} *</label>
                <input type="text" wire:model="nameAr" class="min-h-11 w-full rounded-control border border-border bg-surface px-3 py-2 text-sm focus:border-primary focus:ring-primary" required>
                @error('nameAr') <span class="text-xs text-danger">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="block text-sm font-semibold text-text-secondary mb-1">{{ __('expenses.name_en') }}</label>
                <input type="text" wire:model="nameEn" class="min-h-11 w-full rounded-control border border-border bg-surface px-3 py-2 text-sm focus:border-primary focus:ring-primary">
                @error('nameEn') <span class="text-xs text-danger">{{ $message }}</span> @enderror
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="block text-sm font-semibold text-text-secondary mb-1">{{ __('expenses.code') }} *</label>
                <input type="text" wire:model="code" placeholder="utilities / maintenance" class="min-h-11 w-full rounded-control border border-border bg-surface px-3 py-2 text-sm focus:border-primary focus:ring-primary" required>
                @error('code') <span class="text-xs text-danger">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="block text-sm font-semibold text-text-secondary mb-1">{{ __('expenses.ledger_account') }} *</label>
                <select wire:model="ledgerAccountId" class="min-h-11 w-full rounded-control border border-border bg-surface px-3 py-2 text-sm focus:border-primary focus:ring-primary" required>
                    @foreach($accounts as $acc)
                        <option value="{{ $acc->id }}">{{ $acc->code }} - {{ $acc->displayName() }}</option>
                    @endforeach
                </select>
                @error('ledgerAccountId') <span class="text-xs text-danger">{{ $message }}</span> @enderror
            </div>
        </div>

        <div class="flex items-center justify-between pt-3">
            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" wire:model="active" class="rounded text-primary focus:ring-primary">
                <span class="text-sm font-semibold text-text-secondary">{{ __('expenses.active') }}</span>
            </label>
            <button type="submit" wire:loading.attr="disabled" wire:target="createCategory" class="touch-action bg-primary text-sm font-semibold text-white hover:bg-primary-hover focus:ring-2 focus:ring-primary">
                {{ __('expenses.save') }}
            </button>
        </div>
    </form>

    <!-- Categories List -->
    <div class="rounded-card border border-border bg-surface overflow-hidden shadow-sm">
        <div class="overflow-x-auto">
            <table class="data-table data-table--responsive w-full text-sm text-start">
                <thead class="bg-surface-soft border-b border-border text-xs uppercase text-text-muted">
                    <tr>
                        <th class="px-4 py-3 text-start">{{ __('expenses.code') }}</th>
                        <th class="px-4 py-3 text-start">{{ __('expenses.name_ar') }}</th>
                        <th class="px-4 py-3 text-start">{{ __('expenses.name_en') }}</th>
                        <th class="px-4 py-3 text-start">{{ __('expenses.ledger_account') }}</th>
                        <th class="px-4 py-3 text-start">{{ __('expenses.status') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse($categories as $cat)
                        @php($ledgerLabel = $cat->ledgerAccount ? $cat->ledgerAccount->code . ' - ' . $cat->ledgerAccount->displayName() : '-')
                        <tr wire:key="expense-cat-row-{{ $cat->id }}" x-data="{ expanded: false }" :data-expanded="expanded ? 'true' : 'false'" class="hover:bg-surface-soft/60">
                            <td class="td-secondary px-4 py-3 font-semibold text-text-secondary" data-label="{{ __('expenses.code') }}" dir="ltr">{{ $cat->code }}</td>
                            <td class="td-primary px-4 py-3 font-medium">
                                <div class="truncate text-text-primary font-semibold">{{ $cat->name_ar }}</div>
                                <div class="lg:hidden font-normal text-xs text-text-secondary truncate mt-0.5">
                                    <bdi dir="ltr">{{ $cat->code }}</bdi> &middot; {{ $ledgerLabel }}
                                </div>
                            </td>
                            <td class="td-secondary px-4 py-3 text-text-secondary" data-label="{{ __('expenses.name_en') }}">{{ $cat->name_en ?: '-' }}</td>
                            <td class="td-secondary px-4 py-3 text-text-secondary" data-label="{{ __('expenses.ledger_account') }}">
                                {{ $ledgerLabel }}
                            </td>
                            <td class="td-end px-4 py-3">
                                <div class="inline-flex items-center gap-2">
                                    @if($cat->active)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-success-bg text-success">
                                            {{ __('expenses.active') }}
                                        </span>
                                    @else
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-slate-100 text-slate-700">
                                            {{ __('expenses.inactive') }}
                                        </span>
                                    @endif
                                    <x-row-details-toggle />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-8 text-center text-text-muted">
                                {{ __('expenses.empty') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($categories->count() > 0 || $categories->currentPage() > 1)
            <div class="p-4 border-t border-border"><x-table-pagination :paginator="$categories" /></div>
        @endif
    </div>
</div>
