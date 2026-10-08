<div class="max-w-4xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <div class="flex items-center gap-3">
            <a href="{{ route('expenses.index') }}" class="inline-flex items-center justify-center w-8 h-8 rounded-control border border-border bg-white text-text-secondary hover:bg-surface-soft">
                &larr;
            </a>
            <div>
                <h1 class="text-2xl font-bold">{{ __('expenses.categories') }}</h1>
                <p class="text-sm text-text-muted">{{ __('expenses.expenses') }}</p>
            </div>
        </div>
    </div>

    @if(session()->has('success'))
        <div class="rounded-control border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">
            {{ session('success') }}
        </div>
    @endif

    <!-- Add Category Form -->
    <form wire:submit.prevent="createCategory" class="rounded-card border border-border bg-white p-6 space-y-4">
        <h2 class="font-bold text-base text-text-primary">{{ __('expenses.create_category') }}</h2>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="block text-xs font-semibold text-text-secondary mb-1">{{ __('expenses.name_ar') }} *</label>
                <input type="text" wire:model="nameAr" class="w-full rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary" required>
                @error('nameAr') <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="block text-xs font-semibold text-text-secondary mb-1">{{ __('expenses.name_en') }}</label>
                <input type="text" wire:model="nameEn" class="w-full rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary">
                @error('nameEn') <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="block text-xs font-semibold text-text-secondary mb-1">{{ __('expenses.code') }} *</label>
                <input type="text" wire:model="code" placeholder="utilities / maintenance" class="w-full rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary" required>
                @error('code') <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="block text-xs font-semibold text-text-secondary mb-1">{{ __('expenses.ledger_account') }} *</label>
                <select wire:model="ledgerAccountId" class="w-full rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary" required>
                    @foreach($accounts as $acc)
                        <option value="{{ $acc->id }}">{{ $acc->code }} - {{ $acc->displayName() }}</option>
                    @endforeach
                </select>
                @error('ledgerAccountId') <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
            </div>
        </div>

        <div class="flex items-center justify-between pt-3">
            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" wire:model="active" class="rounded text-primary focus:ring-primary">
                <span class="text-sm font-semibold text-text-secondary">{{ __('expenses.active') }}</span>
            </label>
            <button type="submit" class="inline-flex justify-center items-center rounded-control bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover focus:ring-2 focus:ring-primary">
                {{ __('expenses.save') }}
            </button>
        </div>
    </form>

    <!-- Categories List -->
    <div class="rounded-card border border-border bg-white overflow-hidden shadow-sm">
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-start">
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
                        <tr class="hover:bg-surface-soft/60">
                            <td class="px-4 py-3 font-semibold text-text-secondary">{{ $cat->code }}</td>
                            <td class="px-4 py-3 font-medium">{{ $cat->name_ar }}</td>
                            <td class="px-4 py-3 text-text-secondary">{{ $cat->name_en ?: '-' }}</td>
                            <td class="px-4 py-3 text-text-secondary">
                                {{ $cat->ledgerAccount ? $cat->ledgerAccount->code . ' - ' . $cat->ledgerAccount->displayName() : '-' }}
                            </td>
                            <td class="px-4 py-3">
                                @if($cat->active)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-emerald-100 text-emerald-800">
                                        {{ __('expenses.active') }}
                                    </span>
                                @else
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-slate-100 text-slate-700">
                                        {{ __('expenses.inactive') }}
                                    </span>
                                @endif
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
    </div>
</div>
