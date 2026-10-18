<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-xl font-extrabold text-text-primary flex items-center gap-2.5">
                <x-icon name="box" class="w-6 h-6 text-primary" />
                <span>{{ __('inventory.brands') }}</span>
            </h1>
            <p class="text-xs text-text-secondary mt-1">{{ __('inventory.brand_subtitle') }}</p>
        </div>

        <button wire:loading.attr="disabled" type="button" wire:click="openCreateModal"
                class="touch-action bg-primary hover:bg-primary-hover text-white text-xs font-bold shadow-button transition-colors">
            <x-icon name="plus" class="w-4 h-4" />
            <span>{{ __('inventory.add_brand') }}</span>
        </button>
    </div>

    <!-- Alert Messages -->
    @if ($successMessage)
        <div class="p-3.5 rounded-control bg-success-bg border border-success/30 text-success text-xs font-semibold flex items-center justify-between">
            <span>{{ $successMessage }}</span>
            <button wire:loading.attr="disabled" type="button" wire:click="$set('successMessage', null)" aria-label="{{ __('inventory.close') }}" class="touch-action text-success hover:opacity-75">&times;</button>
        </div>
    @endif
    @if ($errorMessage)
        <div class="p-3.5 rounded-control bg-danger-bg border border-danger/30 text-danger text-xs font-semibold flex items-center justify-between">
            <span>{{ $errorMessage }}</span>
            <button wire:loading.attr="disabled" type="button" wire:click="$set('errorMessage', null)" aria-label="{{ __('inventory.close') }}" class="touch-action text-danger hover:opacity-75">&times;</button>
        </div>
    @endif

    <!-- Search Toolbar -->
    <x-card padding="p-4">
        <div class="max-w-md relative">
            <input id="brands-search" aria-label="{{ __('inventory.search_brands_placeholder') }}" type="text"
                   wire:model.live.debounce.300ms="search"
                   placeholder="{{ __('inventory.search_brands_placeholder') }}"
                   class="min-h-11 w-full h-11 pl-9 pr-3 rounded-control border border-border bg-white text-xs text-text-primary placeholder:text-text-muted focus:outline-none focus:border-primary focus:ring-2 focus:ring-focus-ring" />
            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-text-muted rtl:left-auto rtl:right-0 rtl:pl-0 rtl:pr-3">
                <x-icon name="search" class="w-4 h-4" />
            </div>
        </div>
    </x-card>

    <!-- Brands Table -->
    <x-card padding="p-0">
        <div class="hidden md:block overflow-x-auto">
            <table class="w-full text-xs text-right rtl:text-right ltr:text-left">
                <thead class="bg-surface-soft border-b border-border text-text-secondary font-bold select-none">
                    <tr>
                        <th class="p-3.5">{{ __('inventory.brand_name') }}</th>
                        <th class="p-3.5 text-center">{{ __('inventory.products_count') }}</th>
                        <th class="p-3.5 text-center">{{ __('inventory.status') }}</th>
                        <th class="p-3.5 text-center">{{ __('inventory.toggle_active') }}</th>
                        <th class="p-3.5 text-center w-20"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($brands as $brand)
                        <tr class="hover:bg-surface-soft/60 transition-colors">
                            <td class="p-3.5">
                                <span class="font-bold text-text-primary block">{{ $brand->name_ar }}</span>
                                @if ($brand->name_en)
                                    <span class="text-[11px] text-text-muted dir-ltr block">{{ $brand->name_en }}</span>
                                @endif
                            </td>
                            <td class="p-3.5 text-center font-mono font-bold text-text-primary">
                                {{ $brand->products_count }}
                            </td>
                            <td class="p-3.5 text-center">
                                @if ($brand->active)
                                    <x-badge variant="success">{{ __('inventory.active') }}</x-badge>
                                @else
                                    <x-badge>{{ __('inventory.inactive') }}</x-badge>
                                @endif
                            </td>
                            <td class="p-3.5 text-center">
                                <button wire:loading.attr="disabled" type="button" wire:click="toggleActive({{ $brand->id }})"
                                        class="touch-action gap-1 border text-[11px] font-semibold transition-colors {{ $brand->active ? 'border-border text-text-secondary hover:bg-surface-soft hover:text-danger' : 'border-success/40 bg-success-bg text-success hover:bg-success/20' }}">
                                    @if ($brand->active)
                                        <span>{{ __('inventory.deactivate') }}</span>
                                    @else
                                        <span>{{ __('inventory.activate') }}</span>
                                    @endif
                                </button>
                            </td>
                            <td class="p-3.5 text-center">
                                <button wire:loading.attr="disabled" type="button" wire:click="openEditModal({{ $brand->id }})"
                                        class="touch-action text-primary hover:underline font-bold text-xs">
                                    {{ __('inventory.edit') }}
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="p-6 text-center text-text-muted">
                                {{ __('inventory.no_brands_defined') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
<div class="md:hidden divide-y divide-border">
                    @forelse ($brands as $brand)
                        <article class="p-4">
                            <dl class="grid grid-cols-2 gap-x-3 gap-y-4">
                            <div class="min-w-0 space-y-1 col-span-2">
                                <dt class="text-xs font-medium text-text-secondary">{{ __('inventory.brand_name') }}</dt>
                                <dd class="break-words text-sm tabular-nums text-text-primary"><span class="font-bold text-text-primary block">{{ $brand->name_ar }}</span>
                                @if ($brand->name_en)
                                    <span class="text-[11px] text-text-muted dir-ltr block">{{ $brand->name_en }}</span>
                                @endif</dd>
                            </div>
                            <div class="min-w-0 space-y-1">
                                <dt class="text-xs font-medium text-text-secondary">{{ __('inventory.products_count') }}</dt>
                                <dd class="break-words text-sm tabular-nums text-text-primary">{{ $brand->products_count }}</dd>
                            </div>
                            <div class="min-w-0 space-y-1">
                                <dt class="text-xs font-medium text-text-secondary">{{ __('inventory.status') }}</dt>
                                <dd class="break-words text-sm tabular-nums text-text-primary">@if ($brand->active)
                                    <x-badge variant="success">{{ __('inventory.active') }}</x-badge>
                                @else
                                    <x-badge>{{ __('inventory.inactive') }}</x-badge>
                                @endif</dd>
                            </div>
                            <div class="min-w-0 space-y-1">
                                <dt class="text-xs font-medium text-text-secondary">{{ __('inventory.toggle_active') }}</dt>
                                <dd class="break-words text-sm tabular-nums text-text-primary"><button wire:loading.attr="disabled" type="button" wire:click="toggleActive({{ $brand->id }})"
                                        class="touch-action gap-1 border text-[11px] font-semibold transition-colors {{ $brand->active ? 'border-border text-text-secondary hover:bg-surface-soft hover:text-danger' : 'border-success/40 bg-success-bg text-success hover:bg-success/20' }}">
                                    @if ($brand->active)
                                        <span>{{ __('inventory.deactivate') }}</span>
                                    @else
                                        <span>{{ __('inventory.activate') }}</span>
                                    @endif
                                </button></dd>
                            </div>
                            <div class="min-w-0 space-y-1 col-span-2">
                                <dt class="text-xs font-medium text-text-secondary">{{ __('refinement_lists.actions') }}</dt>
                                <dd class="break-words text-sm tabular-nums text-text-primary"><button wire:loading.attr="disabled" type="button" wire:click="openEditModal({{ $brand->id }})"
                                        class="touch-action text-primary hover:underline font-bold text-xs">
                                    {{ __('inventory.edit') }}
                                </button></dd>
                            </div>
                        </dl>
                        </article>
                    @empty
                        <article class="p-4">
                            <dl class="grid grid-cols-2 gap-x-3 gap-y-4">
                            <div class="col-span-2 py-4 text-center text-sm text-text-secondary">
                                {{ __('inventory.no_brands_defined') }}
                            </div>
                        </dl>
                        </article>
                    @endforelse
                </div>


        @if ($brands->hasPages())
            <div class="p-3.5 border-t border-border">
                {{ $brands->links() }}
            </div>
        @endif
    </x-card>

    <!-- Create/Edit Modal -->
    @if ($showModal)
        <div x-data x-trap.inert.noscroll="true" @keydown.escape.window="$wire.closeModal()"
             role="dialog" aria-modal="true" aria-labelledby="brand-modal-title"
             class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 backdrop-blur-xs p-4">
            <div class="bg-white rounded-card border border-border shadow-xl w-full max-w-md max-h-[calc(100dvh-2rem)] overflow-y-auto p-6 space-y-4">
                <div class="flex items-center justify-between border-b border-border pb-3">
                    <h3 id="brand-modal-title" class="font-extrabold text-sm text-text-primary flex items-center gap-2">
                        <x-icon name="box" class="w-4 h-4 text-primary" />
                        <span>{{ $editingId ? __('inventory.edit_brand') : __('inventory.add_brand') }}</span>
                    </h3>
                    <button wire:loading.attr="disabled" type="button" aria-label="{{ __('inventory.close') }}" wire:click="closeModal"
                            class="touch-action text-text-muted hover:text-text-primary text-base font-bold">&times;</button>
                </div>

                <div class="space-y-3 text-xs">
                    <div>
                        <label for="brand-name-ar" class="block font-bold text-text-secondary mb-1">
                            {{ __('inventory.brand_name_ar') }} <span class="text-danger">*</span>
                        </label>
                        <input id="brand-name-ar" type="text" wire:model="name_ar" dir="rtl"
                               @error('name_ar') aria-invalid="true" aria-describedby="brand-name-ar-error" @enderror
                               class="min-h-11 w-full h-11 px-2.5 rounded-control border border-border text-xs focus:outline-none focus:border-primary @error('name_ar') border-danger @enderror" />
                        @error('name_ar')
                            <span role="alert" id="brand-name-ar-error" class="text-danger text-[10px]">{{ $message }}</span>
                        @enderror
                    </div>
                    <div>
                        <label for="brand-name-en" class="block font-bold text-text-secondary mb-1">
                            {{ __('inventory.brand_name_en') }}
                        </label>
                        <input id="brand-name-en" type="text" wire:model="name_en" dir="ltr"
                               @error('name_en') aria-invalid="true" aria-describedby="brand-name-en-error" @enderror
                               class="min-h-11 w-full h-11 px-2.5 rounded-control border border-border text-xs focus:outline-none focus:border-primary @error('name_en') border-danger @enderror" />
                        @error('name_en')
                            <span role="alert" id="brand-name-en-error" class="text-danger text-[10px]">{{ $message }}</span>
                        @enderror
                    </div>
                    <div class="pt-2">
                        <label for="brand-active" class="flex items-center gap-2 cursor-pointer select-none">
                            <input id="brand-active" type="checkbox" wire:model="active" class="rounded border-border text-primary focus:ring-primary w-4 h-4" />
                            <span class="font-bold text-text-primary">{{ __('inventory.active') }}</span>
                        </label>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                    <button wire:loading.attr="disabled" type="button" wire:click="closeModal"
                            class="touch-action border border-border text-text-secondary hover:bg-surface-soft text-xs font-semibold">
                        {{ __('inventory.cancel') }}
                    </button>
                    <button type="button" wire:click="save" wire:loading.attr="disabled"
                            class="touch-action bg-primary text-white hover:bg-primary-hover text-xs font-bold shadow-button">
                        {{ __('inventory.save') }}
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
