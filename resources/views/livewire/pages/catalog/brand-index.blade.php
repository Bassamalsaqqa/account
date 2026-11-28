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

        <button id="brand-add-trigger" wire:loading.attr="disabled" type="button" wire:click="openCreateModal"
                class="touch-action bg-primary hover:bg-primary-hover text-white text-sm font-bold shadow-button transition-colors">
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
                   class="min-h-11 w-full h-11 pl-9 pr-3 rounded-control border border-border bg-surface text-sm text-text-primary placeholder:text-text-muted focus:outline-none focus:border-primary focus:ring-2 focus:ring-focus-ring" />
            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-text-muted rtl:left-auto rtl:right-0 rtl:pl-0 rtl:pr-3">
                <x-icon name="search" class="w-4 h-4" />
            </div>
        </div>
    </x-card>

    <!-- Brands Table -->
    <x-card padding="p-0">
        <div class="hidden lg:block overflow-x-auto">
            <table class="data-table w-full text-xs text-right rtl:text-right ltr:text-left">
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
                                    <bdi dir="ltr" class="text-xs text-text-muted block">{{ $brand->name_en }}</bdi>
                                @endif
                            </td>
                            <td class="p-3.5 text-center font-mono font-bold text-text-primary">
                                {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayQuantity($brand->products_count) }}
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
                                        class="touch-action gap-1 border text-xs font-semibold transition-colors {{ $brand->active ? 'border-border text-text-secondary hover:bg-surface-soft hover:text-danger' : 'border-success/40 bg-success-bg text-success hover:bg-success/20' }}">
                                    @if ($brand->active)
                                        <span>{{ __('inventory.deactivate') }}</span>
                                    @else
                                        <span>{{ __('inventory.activate') }}</span>
                                    @endif
                                </button>
                            </td>
                            <td class="table-actions p-3.5 text-center">
                                <button id="brand-edit-desktop-{{ $brand->id }}" wire:loading.attr="disabled" type="button" wire:click="openEditModal({{ $brand->id }})"
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
<div class="lg:hidden divide-y divide-border">
            @forelse ($brands as $brand)
                <x-compact-mobile-row wire:key="brand-mobile-{{ $brand->id }}">
                    <x-slot:primary>
                        <span class="font-bold text-sm text-text-primary truncate">{{ $brand->name_ar }}</span>
                        <span class="shrink-0">
                            @if ($brand->active)
                                <x-badge variant="success">{{ __('inventory.active') }}</x-badge>
                            @else
                                <x-badge>{{ __('inventory.inactive') }}</x-badge>
                            @endif
                        </span>
                    </x-slot:primary>

                    <x-slot:subtitle>
                        @if ($brand->name_en)<bdi dir="ltr">{{ $brand->name_en }}</bdi> &middot; @endif
                        {{ __('inventory.products_count') }}: <bdi dir="ltr" class="font-mono">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayQuantity($brand->products_count) }}</bdi>
                    </x-slot:subtitle>

                    <x-slot:details>
                        <div class="flex flex-wrap items-center justify-end gap-2">
                            <button wire:loading.attr="disabled" type="button" wire:click="toggleActive({{ $brand->id }})"
                                    class="touch-action gap-1 border text-xs font-semibold transition-colors {{ $brand->active ? 'border-border text-text-secondary hover:bg-surface-soft hover:text-danger' : 'border-success/40 bg-success-bg text-success hover:bg-success/20' }}">
                                {{ $brand->active ? __('inventory.deactivate') : __('inventory.activate') }}
                            </button>
                            <button id="brand-edit-mobile-{{ $brand->id }}" wire:loading.attr="disabled" type="button" wire:click="openEditModal({{ $brand->id }})"
                                    class="touch-action text-primary hover:underline font-bold text-xs">
                                {{ __('inventory.edit') }}
                            </button>
                        </div>
                    </x-slot:details>
                </x-compact-mobile-row>
            @empty
                <p class="p-6 text-center text-sm text-text-secondary">{{ __('inventory.no_brands_defined') }}</p>
            @endforelse
        </div>


        @if ($brands->count() > 0 || $brands->currentPage() > 1)
            <div class="p-3.5 border-t border-border">
                <x-table-pagination :paginator="$brands" />
            </div>
        @endif
    </x-card>

    <!-- Create/Edit Modal -->
    @if ($showModal)
        <x-dialog-shell :title="$editingId ? __('inventory.edit_brand') : __('inventory.add_brand')" title-id="brand-modal-title" close-action="closeModal" save-target="save" max-width="max-w-md">
            <div class="space-y-3 text-sm">
                <div>
                    <label for="brand-name-ar" class="block font-bold text-text-secondary mb-1">
                        {{ __('inventory.brand_name_ar') }} <span class="text-danger">*</span>
                    </label>
                    <input id="brand-name-ar" type="text" wire:model="name_ar" dir="rtl"
                           aria-invalid="{{ $errors->has('name_ar') ? 'true' : 'false' }}"
                           @if ($errors->has('name_ar')) aria-describedby="brand-name-ar-error" @endif
                           class="min-h-11 lg:min-h-9.5 w-full px-3 rounded-control border border-border-control bg-canvas text-base lg:text-sm focus:outline-none focus:border-primary @error('name_ar') border-danger @enderror" />
                    @error('name_ar')
                        <span role="alert" id="brand-name-ar-error" class="text-danger text-xs">{{ $message }}</span>
                    @enderror
                </div>
                <div>
                    <label for="brand-name-en" class="block font-bold text-text-secondary mb-1">
                        {{ __('inventory.brand_name_en') }}
                    </label>
                    <input id="brand-name-en" type="text" wire:model="name_en" dir="ltr"
                           aria-invalid="{{ $errors->has('name_en') ? 'true' : 'false' }}"
                           @if ($errors->has('name_en')) aria-describedby="brand-name-en-error" @endif
                           class="min-h-11 lg:min-h-9.5 w-full px-3 rounded-control border border-border-control bg-canvas text-base lg:text-sm focus:outline-none focus:border-primary @error('name_en') border-danger @enderror" />
                    @error('name_en')
                        <span role="alert" id="brand-name-en-error" class="text-danger text-xs">{{ $message }}</span>
                    @enderror
                </div>
                <div class="pt-1">
                    <label for="brand-active" class="inline-flex min-h-11 items-center gap-2 cursor-pointer select-none">
                        <input id="brand-active" type="checkbox" wire:model="active" class="size-5 rounded border-border text-primary accent-primary focus:ring-primary" />
                        <span class="font-bold text-text-primary">{{ __('inventory.active') }}</span>
                    </label>
                </div>
            </div>
            <x-slot:footer>
                <x-secondary-button type="button" wire:click="closeModal" wire:loading.attr="disabled">
                    {{ __('inventory.cancel') }}
                </x-secondary-button>
                <x-primary-button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save">
                    {{ __('inventory.save') }}
                </x-primary-button>
            </x-slot:footer>
        </x-dialog-shell>
    @endif
</div>
