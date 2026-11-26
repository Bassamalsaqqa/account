<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-extrabold text-text-primary flex items-center gap-2.5">
                <x-icon name="dashboard" class="w-6 h-6 text-primary" />
                <span>{{ __('inventory.units') }}</span>
            </h1>
            <p class="text-xs text-text-secondary mt-1">{{ __('inventory.unit_subtitle') }}</p>
        </div>

        @if ($canManage)
            <button id="unit-add-trigger" wire:loading.attr="disabled" type="button" wire:click="openCreateModal"
                    class="touch-action bg-primary hover:bg-primary-hover text-white text-sm font-bold shadow-button transition-colors">
                <x-icon name="plus" class="w-4 h-4" />
                <span>{{ __('inventory.add_unit') }}</span>
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
        <div class="hidden lg:block overflow-x-auto">
            <table class="data-table w-full text-xs text-right rtl:text-right ltr:text-left">
                <thead class="bg-surface-soft border-b border-border text-text-secondary font-bold">
                    <tr>
                        <th class="p-3.5">{{ __('inventory.unit_code') }}</th>
                        <th class="p-3.5">{{ __('inventory.unit_name') }}</th>
                        <th class="p-3.5">{{ __('inventory.abbreviation') }}</th>
                        <th class="p-3.5 text-center">{{ __('inventory.allows_decimals') }}</th>
                        <th class="p-3.5 text-center">{{ __('inventory.decimal_places') }}</th>
                        <th class="p-3.5 text-center">{{ __('inventory.status') }}</th>
                        @if ($canManage)
                            <th class="p-3.5 text-center w-20"></th>
                        @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($units as $unit)
                        <tr class="hover:bg-surface-soft/60 transition-colors">
                            <td class="p-3.5 font-mono font-bold text-text-primary" dir="ltr">
                                {{ $unit->code }}
                            </td>
                            <td class="p-3.5">
                                <span class="font-bold text-text-primary block">{{ $unit->name_ar }}</span>
                                @if ($unit->name_en)
                                    <bdi dir="ltr" class="text-xs text-text-muted block">{{ $unit->name_en }}</bdi>
                                @endif
                            </td>
                            <td class="p-3.5 text-text-secondary">
                                {{ $unit->symbol_ar ?? $unit->symbol_en ?? '—' }}
                            </td>
                            <td class="p-3.5 text-center">
                                @if ($unit->allows_fraction)
                                    <span class="text-success font-bold">&#10003; {{ __('inventory.yes') }}</span>
                                 @else
                                    <span class="text-text-muted">{{ __('inventory.no') }}</span>
                                @endif
                            </td>
                            <td class="p-3.5 text-center font-mono text-text-secondary">
                                {{ $unit->decimal_places }}
                            </td>
                            <td class="p-3.5 text-center">
                                @if ($unit->active)
                                    <x-badge variant="success">{{ __('inventory.active') }}</x-badge>
                                @else
                                    <x-badge>{{ __('inventory.inactive') }}</x-badge>
                                @endif
                            </td>
                            @if ($canManage)
                                <td class="table-actions p-3.5 text-center">
                                    <button id="unit-edit-desktop-{{ $unit->id }}" wire:loading.attr="disabled" type="button" wire:click="openEditModal({{ $unit->id }})"
                                            class="touch-action text-primary hover:underline font-bold text-xs">
                                        {{ __('inventory.edit') }}
                                    </button>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $canManage ? 7 : 6 }}" class="p-6 text-center text-text-muted">
                                {{ __('inventory.no_units_registered_catalog') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
<div class="lg:hidden divide-y divide-border">
                    @forelse ($units as $unit)
                        <article class="p-4">
                            <dl class="grid grid-cols-2 gap-x-3 gap-y-4">
                            <div class="min-w-0 space-y-1 col-span-2">
                                <dt class="text-xs font-medium text-text-secondary">{{ __('inventory.unit_code') }}</dt>
                                <dd dir="ltr" class="break-words text-sm tabular-nums text-text-primary">{{ $unit->code }}</dd>
                            </div>
                            <div class="min-w-0 space-y-1">
                                <dt class="text-xs font-medium text-text-secondary">{{ __('inventory.unit_name') }}</dt>
                                <dd class="break-words text-sm tabular-nums text-text-primary"><span class="font-bold text-text-primary block">{{ $unit->name_ar }}</span>
                                @if ($unit->name_en)
                                    <bdi dir="ltr" class="text-xs text-text-muted block">{{ $unit->name_en }}</bdi>
                                @endif</dd>
                            </div>
                            <div class="min-w-0 space-y-1">
                                <dt class="text-xs font-medium text-text-secondary">{{ __('inventory.abbreviation') }}</dt>
                                <dd class="break-words text-sm tabular-nums text-text-primary">{{ $unit->symbol_ar ?? $unit->symbol_en ?? '—' }}</dd>
                            </div>
                            <div class="min-w-0 space-y-1">
                                <dt class="text-xs font-medium text-text-secondary">{{ __('inventory.allows_decimals') }}</dt>
                                <dd class="break-words text-sm tabular-nums text-text-primary">@if ($unit->allows_fraction)
                                    <span class="text-success font-bold">&#10003; {{ __('inventory.yes') }}</span>
                                 @else
                                    <span class="text-text-muted">{{ __('inventory.no') }}</span>
                                @endif</dd>
                            </div>
                            <div class="min-w-0 space-y-1">
                                <dt class="text-xs font-medium text-text-secondary">{{ __('inventory.decimal_places') }}</dt>
                                <dd class="break-words text-sm tabular-nums text-text-primary">{{ $unit->decimal_places }}</dd>
                            </div>
                            <div class="min-w-0 space-y-1">
                                <dt class="text-xs font-medium text-text-secondary">{{ __('inventory.status') }}</dt>
                                <dd class="break-words text-sm tabular-nums text-text-primary">@if ($unit->active)
                                    <x-badge variant="success">{{ __('inventory.active') }}</x-badge>
                                @else
                                    <x-badge>{{ __('inventory.inactive') }}</x-badge>
                                @endif</dd>
                            </div>
                            @if ($canManage)
                                <div class="min-w-0 space-y-1 col-span-2">
                                <dt class="text-xs font-medium text-text-secondary">{{ __('refinement_lists.actions') }}</dt>
                                <dd class="break-words text-sm tabular-nums text-text-primary"><button id="unit-edit-mobile-{{ $unit->id }}" wire:loading.attr="disabled" type="button" wire:click="openEditModal({{ $unit->id }})"
                                            class="touch-action text-primary hover:underline font-bold text-xs">
                                        {{ __('inventory.edit') }}
                                    </button></dd>
                            </div>
                            @endif
                        </dl>
                        </article>
                    @empty
                        <article class="p-4">
                            <dl class="grid grid-cols-2 gap-x-3 gap-y-4">
                            <div class="col-span-2 py-4 text-center text-sm text-text-secondary">
                                {{ __('inventory.no_units_registered_catalog') }}
                            </div>
                        </dl>
                        </article>
                    @endforelse
                </div>

    <div class="p-4"><x-table-pagination :paginator="$units" /></div>
    </x-card>

    <!-- Create/Edit Modal -->
    @if ($showModal)
        <x-dialog-shell :title="$editingId ? __('inventory.edit_unit') : __('inventory.add_unit')" title-id="unit-modal-title" close-action="$set('showModal', false)" save-target="save" max-width="max-w-md">
            @if ($errorMessage)
                <div role="alert" class="p-3 rounded-control bg-danger-bg border border-danger/30 text-danger text-xs font-semibold flex items-center justify-between">
                    <span>{{ $errorMessage }}</span>
                    <button aria-label="{{ __('inventory.close') }}" wire:loading.attr="disabled" type="button" wire:click="$set('errorMessage', null)" class="touch-action text-danger hover:opacity-75">&times;</button>
                </div>
            @endif

            <div class="space-y-3 text-sm">
                <div>
                    <label for="unit-code" class="block font-bold text-text-secondary mb-1">{{ __('inventory.unit_code_label') }} *</label>
                    <input id="unit-code"
                           type="text"
                           wire:model="code"
                           dir="ltr"
                           placeholder="{{ __('inventory.unit_code_placeholder') }}"
                           aria-invalid="{{ $errors->has('code') ? 'true' : 'false' }}"
                           @if ($errors->has('code')) aria-describedby="unit-code-error" @endif
                           class="min-h-11 w-full h-11 px-2.5 rounded-control border border-border text-sm font-mono @error('code') border-danger @enderror" />
                    @error('code') <span id="unit-code-error" role="alert" class="text-danger text-xs">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label for="unit-name-ar" class="block font-bold text-text-secondary mb-1">{{ __('inventory.unit_name_ar') }} *</label>
                    <input id="unit-name-ar"
                           type="text"
                           wire:model="name_ar"
                           dir="rtl"
                           aria-invalid="{{ $errors->has('name_ar') ? 'true' : 'false' }}"
                           @if ($errors->has('name_ar')) aria-describedby="unit-name-ar-error" @endif
                           class="min-h-11 w-full h-11 px-2.5 rounded-control border border-border text-sm @error('name_ar') border-danger @enderror" />
                    @error('name_ar') <span id="unit-name-ar-error" role="alert" class="text-danger text-xs">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label for="unit-name-en" class="block font-bold text-text-secondary mb-1">{{ __('inventory.unit_name_en') }}</label>
                    <input id="unit-name-en"
                           type="text"
                           wire:model="name_en"
                           dir="ltr"
                           aria-invalid="{{ $errors->has('name_en') ? 'true' : 'false' }}"
                           @if ($errors->has('name_en')) aria-describedby="unit-name-en-error" @endif
                           class="min-h-11 w-full h-11 px-2.5 rounded-control border border-border text-sm @error('name_en') border-danger @enderror" />
                    @error('name_en') <span id="unit-name-en-error" role="alert" class="text-danger text-xs">{{ $message }}</span> @enderror
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                    <div>
                        <label for="unit-symbol-ar" class="block font-bold text-text-secondary mb-1">{{ __('inventory.symbol_ar') }}</label>
                        <input id="unit-symbol-ar"
                               type="text"
                               wire:model="symbol_ar"
                               dir="rtl"
                               placeholder="{{ __('inventory.symbol_ar_placeholder') }}"
                               aria-invalid="{{ $errors->has('symbol_ar') ? 'true' : 'false' }}"
                               @if ($errors->has('symbol_ar')) aria-describedby="unit-symbol-ar-error" @endif
                               class="min-h-11 w-full h-11 px-2.5 rounded-control border border-border text-sm" />
                        @error('symbol_ar') <span id="unit-symbol-ar-error" role="alert" class="text-danger text-xs">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label for="unit-symbol-en" class="block font-bold text-text-secondary mb-1">{{ __('inventory.symbol_en') }}</label>
                        <input id="unit-symbol-en"
                               type="text"
                               wire:model="symbol_en"
                               dir="ltr"
                               placeholder="{{ __('inventory.symbol_en_placeholder') }}"
                               aria-invalid="{{ $errors->has('symbol_en') ? 'true' : 'false' }}"
                               @if ($errors->has('symbol_en')) aria-describedby="unit-symbol-en-error" @endif
                               class="min-h-11 w-full h-11 px-2.5 rounded-control border border-border text-sm font-mono" />
                        @error('symbol_en') <span id="unit-symbol-en-error" role="alert" class="text-danger text-xs">{{ $message }}</span> @enderror
                    </div>
                </div>
                <div class="p-3 rounded-control bg-surface-soft border border-border space-y-2">
                    <label for="unit-allow-fractions" class="inline-flex min-h-11 items-center gap-2 cursor-pointer select-none">
                        <input id="unit-allow-fractions" type="checkbox" wire:model.live="allow_fractions" class="size-5 rounded border-border text-primary accent-primary focus:ring-primary" />
                        <span class="font-bold text-text-primary">{{ __('inventory.allow_fractions') }}</span>
                    </label>
                    @if ($allow_fractions)
                        <div>
                            <label for="unit-decimal-places" class="block font-bold text-text-secondary mb-1">{{ __('inventory.decimal_places') }} (0-6)</label>
                            <input id="unit-decimal-places"
                                   inputmode="numeric"
                                   type="number"
                                   min="0"
                                   max="6"
                                   wire:model="decimal_places"
                                   dir="ltr"
                                   aria-invalid="{{ $errors->has('decimal_places') ? 'true' : 'false' }}"
                                   @if ($errors->has('decimal_places')) aria-describedby="unit-decimal-places-error" @endif
                                   class="min-h-11 w-full h-11 px-2.5 rounded-control border border-border text-sm font-mono" />
                            @error('decimal_places') <span id="unit-decimal-places-error" role="alert" class="text-danger text-xs">{{ $message }}</span> @enderror
                        </div>
                    @endif
                </div>
                <div class="pt-1">
                    <label for="unit-active" class="inline-flex min-h-11 items-center gap-2 cursor-pointer select-none">
                        <input id="unit-active" type="checkbox" wire:model="active" class="size-5 rounded border-border text-primary accent-primary focus:ring-primary" />
                        <span class="font-bold text-text-primary">{{ __('inventory.active') }}</span>
                    </label>
                </div>
            </div>
            <x-slot:footer>
                <x-secondary-button type="button" wire:click="$set('showModal', false)" wire:loading.attr="disabled">
                    {{ __('inventory.cancel') }}
                </x-secondary-button>
                <x-primary-button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save">
                    {{ __('inventory.save') }}
                </x-primary-button>
            </x-slot:footer>
        </x-dialog-shell>
    @endif
</div>
