<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-extrabold text-text-primary flex items-center gap-2.5">
                <x-icon name="dashboard" class="w-6 h-6 text-primary" />
                <span>{{ __('inventory.categories') }}</span>
            </h1>
            <p class="text-xs text-text-secondary mt-1">{{ __('inventory.category_subtitle') }}</p>
        </div>

        @if ($canManage)
            <button type="button" wire:click="openCreateModal"
                    class="inline-flex items-center gap-2 px-4 py-2 bg-primary hover:bg-primary-hover text-white text-xs font-bold rounded-control shadow-button transition-colors">
                <x-icon name="plus" class="w-4 h-4" />
                <span>{{ __('inventory.add_category') }}</span>
            </button>
        @endif
    </div>

    @if ($successMessage)
        <div class="p-3.5 rounded-control bg-success-bg border border-success/30 text-success text-xs font-semibold flex items-center justify-between">
            <span>{{ $successMessage }}</span>
            <button type="button" wire:click="$set('successMessage', null)" class="text-success hover:opacity-75">&times;</button>
        </div>
    @endif
    @if ($errorMessage)
        <div class="p-3.5 rounded-control bg-danger-bg border border-danger/30 text-danger text-xs font-semibold flex items-center justify-between">
            <span>{{ $errorMessage }}</span>
            <button type="button" wire:click="$set('errorMessage', null)" class="text-danger hover:opacity-75">&times;</button>
        </div>
    @endif

    <x-card padding="p-0">
        <div class="overflow-x-auto">
            <table class="w-full text-xs text-right rtl:text-right ltr:text-left">
                <thead class="bg-surface-soft border-b border-border text-text-secondary font-bold">
                    <tr>
                        <th class="p-3.5">{{ __('inventory.category_name') }}</th>
                        <th class="p-3.5">{{ __('inventory.parent_category') }}</th>
                        <th class="p-3.5 text-center">{{ __('inventory.sort_order') }}</th>
                        <th class="p-3.5 text-center">{{ __('inventory.products_count') }}</th>
                        <th class="p-3.5 text-center">{{ __('inventory.status') }}</th>
                        @if ($canManage)
                            <th class="p-3.5 text-center w-24"></th>
                        @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($categories as $cat)
                        <tr class="hover:bg-surface-soft/60 transition-colors">
                            <td class="p-3.5">
                                <span class="font-bold text-text-primary block">{{ $cat->name_ar }}</span>
                                @if ($cat->name_en)
                                    <span class="text-[11px] text-text-muted dir-ltr block">{{ $cat->name_en }}</span>
                                @endif
                            </td>
                            <td class="p-3.5 text-text-secondary">
                                {{ $cat->parent?->name_ar ?? '—' }}
                            </td>
                            <td class="p-3.5 text-center font-mono text-text-secondary">
                                {{ $cat->sort_order }}
                            </td>
                            <td class="p-3.5 text-center font-mono font-bold text-text-primary">
                                {{ $cat->products->count() }}
                            </td>
                            <td class="p-3.5 text-center">
                                @if ($cat->active)
                                    <x-badge variant="success">{{ __('inventory.active') }}</x-badge>
                                @else
                                    <x-badge>{{ __('inventory.inactive') }}</x-badge>
                                @endif
                            </td>
                            @if ($canManage)
                                <td class="p-3.5 text-center">
                                    <div class="flex items-center justify-center gap-2">
                                        <button type="button" wire:click="openEditModal({{ $cat->id }})"
                                                class="text-primary hover:underline font-bold text-xs">
                                            {{ __('inventory.edit') }}
                                        </button>
                                        <button type="button" wire:click="deleteCategory({{ $cat->id }})"
                                                onclick="confirm('{{ __('inventory.confirm_delete_category') }}') || event.stopImmediatePropagation()"
                                                class="text-danger hover:underline text-xs">
                                            {{ __('inventory.delete') }}
                                        </button>
                                    </div>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $canManage ? 6 : 5 }}" class="p-6 text-center text-text-muted">
                                {{ __('inventory.no_categories_defined') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>

    <!-- Create/Edit Modal -->
    @if ($showModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 backdrop-blur-xs p-4">
            <div class="bg-white rounded-card border border-border shadow-xl w-full max-w-md p-6 space-y-4">
                <div class="flex items-center justify-between border-b border-border pb-3">
                    <h3 class="font-extrabold text-sm text-text-primary">
                        {{ $editingId ? __('inventory.edit_category') : __('inventory.add_category') }}
                    </h3>
                    <button type="button" wire:click="$set('showModal', false)" class="text-text-muted hover:text-text-primary text-base font-bold">&times;</button>
                </div>

                <div class="space-y-3 text-xs">
                    <div>
                        <label class="block font-bold text-text-secondary mb-1">{{ __('inventory.category_name_ar') }} *</label>
                        <input type="text" wire:model="name_ar" dir="rtl" class="w-full h-8 px-2.5 rounded-control border border-border text-xs" />
                        @error('name_ar') <span class="text-danger text-[10px]">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block font-bold text-text-secondary mb-1">{{ __('inventory.category_name_en') }}</label>
                        <input type="text" wire:model="name_en" dir="ltr" class="w-full h-8 px-2.5 rounded-control border border-border text-xs" />
                        @error('name_en') <span class="text-danger text-[10px]">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block font-bold text-text-secondary mb-1">{{ __('inventory.parent_category') }}</label>
                        <select wire:model="parent_id" class="w-full h-8 px-2.5 rounded-control border border-border text-xs">
                            <option value="">{{ __('inventory.none_root') }}</option>
                            @foreach ($categories as $c)
                                @if ($editingId !== $c->id)
                                    <option value="{{ $c->id }}">{{ $c->name_ar }}</option>
                                @endif
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block font-bold text-text-secondary mb-1">{{ __('inventory.sort_order') }}</label>
                        <input type="number" wire:model="sort_order" dir="ltr" class="w-full h-8 px-2.5 rounded-control border border-border text-xs" />
                    </div>
                    <div class="pt-2">
                        <label class="flex items-center gap-2 cursor-pointer select-none">
                            <input type="checkbox" wire:model="active" class="rounded border-border text-primary w-4 h-4" />
                            <span class="font-bold text-text-primary">{{ __('inventory.active') }}</span>
                        </label>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                    <button type="button" wire:click="$set('showModal', false)"
                            class="px-3.5 py-1.5 rounded-control border border-border text-text-secondary hover:bg-surface-soft text-xs font-semibold">
                        {{ __('inventory.cancel') }}
                    </button>
                    <button type="button" wire:click="save"
                            class="px-4 py-1.5 rounded-control bg-primary text-white hover:bg-primary-hover text-xs font-bold shadow-button">
                        {{ __('inventory.save') }}
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
