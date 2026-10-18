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
            <button wire:loading.attr="disabled" type="button" wire:click="openCreateModal"
                    class="touch-action bg-primary hover:bg-primary-hover text-white text-xs font-bold shadow-button transition-colors">
                <x-icon name="plus" class="w-4 h-4" />
                <span>{{ __('inventory.add_category') }}</span>
            </button>
        @endif
    </div>

    @if ($successMessage)
        <div class="p-3.5 rounded-control bg-success-bg border border-success/30 text-success text-xs font-semibold flex items-center justify-between">
            <span>{{ $successMessage }}</span>
            <button aria-label="{{ __('inventory.close') }}" wire:loading.attr="disabled" type="button" wire:click="$set('successMessage', null)" class="touch-action text-success hover:opacity-75">&times;</button>
        </div>
    @endif
    @if ($errorMessage)
        <div class="p-3.5 rounded-control bg-danger-bg border border-danger/30 text-danger text-xs font-semibold flex items-center justify-between">
            <span>{{ $errorMessage }}</span>
            <button aria-label="{{ __('inventory.close') }}" wire:loading.attr="disabled" type="button" wire:click="$set('errorMessage', null)" class="touch-action text-danger hover:opacity-75">&times;</button>
        </div>
    @endif

    <x-card padding="p-0">
        <div class="hidden md:block overflow-x-auto">
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
                                        <button wire:loading.attr="disabled" type="button" wire:click="openEditModal({{ $cat->id }})"
                                                class="touch-action text-primary hover:underline font-bold text-xs">
                                            {{ __('inventory.edit') }}
                                        </button>
                                        <button wire:loading.attr="disabled" type="button" wire:click="deleteCategory({{ $cat->id }})"
                                                onclick="confirm('{{ __('inventory.confirm_delete_category') }}') || event.stopImmediatePropagation()"
                                                class="touch-action text-danger hover:underline text-xs">
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
<div class="md:hidden divide-y divide-border">
                    @forelse ($categories as $cat)
                        <article class="p-4">
                            <dl class="grid grid-cols-2 gap-x-3 gap-y-4">
                            <div class="min-w-0 space-y-1 col-span-2">
                                <dt class="text-xs font-medium text-text-secondary">{{ __('inventory.category_name') }}</dt>
                                <dd class="break-words text-sm tabular-nums text-text-primary"><span class="font-bold text-text-primary block">{{ $cat->name_ar }}</span>
                                @if ($cat->name_en)
                                    <span class="text-[11px] text-text-muted dir-ltr block">{{ $cat->name_en }}</span>
                                @endif</dd>
                            </div>
                            <div class="min-w-0 space-y-1">
                                <dt class="text-xs font-medium text-text-secondary">{{ __('inventory.parent_category') }}</dt>
                                <dd class="break-words text-sm tabular-nums text-text-primary">{{ $cat->parent?->name_ar ?? '—' }}</dd>
                            </div>
                            <div class="min-w-0 space-y-1">
                                <dt class="text-xs font-medium text-text-secondary">{{ __('inventory.sort_order') }}</dt>
                                <dd class="break-words text-sm tabular-nums text-text-primary">{{ $cat->sort_order }}</dd>
                            </div>
                            <div class="min-w-0 space-y-1">
                                <dt class="text-xs font-medium text-text-secondary">{{ __('inventory.products_count') }}</dt>
                                <dd class="break-words text-sm tabular-nums text-text-primary">{{ $cat->products->count() }}</dd>
                            </div>
                            <div class="min-w-0 space-y-1">
                                <dt class="text-xs font-medium text-text-secondary">{{ __('inventory.status') }}</dt>
                                <dd class="break-words text-sm tabular-nums text-text-primary">@if ($cat->active)
                                    <x-badge variant="success">{{ __('inventory.active') }}</x-badge>
                                @else
                                    <x-badge>{{ __('inventory.inactive') }}</x-badge>
                                @endif</dd>
                            </div>
                            @if ($canManage)
                                <div class="min-w-0 space-y-1 col-span-2">
                                <dt class="text-xs font-medium text-text-secondary">{{ __('refinement_lists.actions') }}</dt>
                                <dd class="break-words text-sm tabular-nums text-text-primary"><div class="flex items-center justify-center gap-2">
                                        <button wire:loading.attr="disabled" type="button" wire:click="openEditModal({{ $cat->id }})"
                                                class="touch-action text-primary hover:underline font-bold text-xs">
                                            {{ __('inventory.edit') }}
                                        </button>
                                        <button wire:loading.attr="disabled" type="button" wire:click="deleteCategory({{ $cat->id }})"
                                                onclick="confirm('{{ __('inventory.confirm_delete_category') }}') || event.stopImmediatePropagation()"
                                                class="touch-action text-danger hover:underline text-xs">
                                            {{ __('inventory.delete') }}
                                        </button>
                                    </div></dd>
                            </div>
                            @endif
                        </dl>
                        </article>
                    @empty
                        <article class="p-4">
                            <dl class="grid grid-cols-2 gap-x-3 gap-y-4">
                            <div class="col-span-2 py-4 text-center text-sm text-text-secondary">
                                {{ __('inventory.no_categories_defined') }}
                            </div>
                        </dl>
                        </article>
                    @endforelse
                </div>

    </x-card>

    <!-- Create/Edit Modal -->
    @if ($showModal)
        <div x-data x-trap.inert.noscroll="true" @keydown.escape.window="$wire.set('showModal', false)" role="dialog" aria-modal="true" aria-labelledby="category-modal-title" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 backdrop-blur-xs p-4">
            <div class="bg-white rounded-card border border-border shadow-xl w-full max-w-md max-h-[calc(100dvh-2rem)] overflow-y-auto p-6 space-y-4">
                <div class="flex items-center justify-between border-b border-border pb-3">
                    <h3 id="category-modal-title" class="font-extrabold text-sm text-text-primary">
                        {{ $editingId ? __('inventory.edit_category') : __('inventory.add_category') }}
                    </h3>
                    <button aria-label="{{ __('inventory.close') }}" wire:loading.attr="disabled" type="button" wire:click="$set('showModal', false)" class="touch-action text-text-muted hover:text-text-primary text-base font-bold">&times;</button>
                </div>

                <div class="space-y-3 text-xs">
                    <div>
                        <label class="block font-bold text-text-secondary mb-1">{{ __('inventory.category_name_ar') }} *</label>
                        <input type="text" wire:model="name_ar" dir="rtl" class="min-h-11 w-full h-11 px-2.5 rounded-control border border-border text-xs" />
                        @error('name_ar') <span role="alert" class="text-danger text-xs">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block font-bold text-text-secondary mb-1">{{ __('inventory.category_name_en') }}</label>
                        <input type="text" wire:model="name_en" dir="ltr" class="min-h-11 w-full h-11 px-2.5 rounded-control border border-border text-xs" />
                        @error('name_en') <span role="alert" class="text-danger text-xs">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block font-bold text-text-secondary mb-1">{{ __('inventory.parent_category') }}</label>
                        <select wire:model="parent_id" class="min-h-11 w-full h-11 px-2.5 rounded-control border border-border text-xs">
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
                        <input inputmode="numeric" type="number" wire:model="sort_order" dir="ltr" class="min-h-11 w-full h-11 px-2.5 rounded-control border border-border text-xs" />
                    </div>
                    <div class="pt-2">
                        <label class="flex items-center gap-2 cursor-pointer select-none">
                            <input type="checkbox" wire:model="active" class="rounded border-border text-primary w-4 h-4" />
                            <span class="font-bold text-text-primary">{{ __('inventory.active') }}</span>
                        </label>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                    <button wire:loading.attr="disabled" type="button" wire:click="$set('showModal', false)"
                            class="touch-action border border-border text-text-secondary hover:bg-surface-soft text-xs font-semibold">
                        {{ __('inventory.cancel') }}
                    </button>
                    <button wire:loading.attr="disabled" type="button" wire:click="save"
                            class="touch-action bg-primary text-white hover:bg-primary-hover text-xs font-bold shadow-button">
                        {{ __('inventory.save') }}
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
