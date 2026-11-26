<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-extrabold text-text-primary flex items-center gap-2.5">
                <x-icon name="truck" class="w-6 h-6 text-primary" />
                <span>{{ __('inventory.warehouses') }}</span>
            </h1>
            <p class="text-xs text-text-secondary mt-1">{{ __('inventory.warehouse_subtitle') }}</p>
        </div>

        @if ($canManage)
            <button id="warehouse-add-trigger" wire:loading.attr="disabled" type="button" wire:click="openCreateModal"
                    class="touch-action bg-primary hover:bg-primary-hover text-white text-sm font-bold shadow-button transition-colors">
                <x-icon name="plus" class="w-4 h-4" />
                <span>{{ __('inventory.add_warehouse') }}</span>
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
                        <th class="p-3.5">{{ __('inventory.warehouse_code') }}</th>
                        <th class="p-3.5">{{ __('inventory.warehouse') }}</th>
                        <th class="p-3.5">{{ __('inventory.address') }}</th>
                        <th class="p-3.5 text-center">{{ __('inventory.is_default_short') }}</th>
                        <th class="p-3.5 text-center">{{ __('inventory.status') }}</th>
                        @if ($canManage)
                            <th class="p-3.5 text-center w-24"></th>
                        @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($warehouses as $wh)
                        <tr class="hover:bg-surface-soft/60 transition-colors">
                            <td class="p-3.5 font-mono font-bold text-text-primary" dir="ltr">
                                {{ $wh->code }}
                            </td>
                            <td class="p-3.5">
                                <span class="font-bold text-text-primary block">{{ $wh->name_ar }}</span>
                                @if ($wh->name_en)
                                    <bdi dir="ltr" class="text-xs text-text-muted block">{{ $wh->name_en }}</bdi>
                                @endif
                            </td>
                            <td class="p-3.5 text-text-secondary max-w-xs truncate">
                                {{ $wh->address_ar ?? $wh->address_en ?? '—' }}
                            </td>
                            <td class="p-3.5 text-center">
                                @if ($wh->is_default)
                                    <x-badge variant="info">{{ __('inventory.is_default_short') }}</x-badge>
                                @else
                                    <span class="text-text-muted">—</span>
                                @endif
                            </td>
                            <td class="p-3.5 text-center">
                                @if ($wh->active)
                                    <x-badge variant="success">{{ __('inventory.active') }}</x-badge>
                                @else
                                    <x-badge>{{ __('inventory.inactive') }}</x-badge>
                                @endif
                            </td>
                            @if ($canManage)
                                <td class="table-actions p-3.5 text-center">
                                    <div class="flex items-center justify-center gap-2">
                                        <button id="warehouse-edit-desktop-{{ $wh->id }}" wire:loading.attr="disabled" type="button" wire:click="openEditModal({{ $wh->id }})"
                                                class="touch-action text-primary hover:underline font-bold text-xs">
                                            {{ __('inventory.edit') }}
                                        </button>
                                        @if (! $wh->is_default)
                                            <button wire:loading.attr="disabled" type="button" wire:click="deleteWarehouse({{ $wh->id }})"
                                                    onclick="confirm('{{ __('inventory.confirm_delete_warehouse') }}') || event.stopImmediatePropagation()"
                                                    class="touch-action text-danger hover:underline text-xs">
                                                {{ __('inventory.delete') }}
                                            </button>
                                        @endif
                                    </div>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $canManage ? 6 : 5 }}" class="p-6 text-center text-text-muted">
                                {{ __('inventory.no_warehouses_registered') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
<div class="lg:hidden divide-y divide-border">
                    @forelse ($warehouses as $wh)
                        <article class="p-4">
                            <dl class="grid grid-cols-2 gap-x-3 gap-y-4">
                            <div class="min-w-0 space-y-1 col-span-2">
                                <dt class="text-xs font-medium text-text-secondary">{{ __('inventory.warehouse_code') }}</dt>
                                <dd dir="ltr" class="break-words text-sm tabular-nums text-text-primary">{{ $wh->code }}</dd>
                            </div>
                            <div class="min-w-0 space-y-1">
                                <dt class="text-xs font-medium text-text-secondary">{{ __('inventory.warehouse') }}</dt>
                                <dd class="break-words text-sm tabular-nums text-text-primary"><span class="font-bold text-text-primary block">{{ $wh->name_ar }}</span>
                                @if ($wh->name_en)
                                    <bdi dir="ltr" class="text-xs text-text-muted block">{{ $wh->name_en }}</bdi>
                                @endif</dd>
                            </div>
                            <div class="min-w-0 space-y-1">
                                <dt class="text-xs font-medium text-text-secondary">{{ __('inventory.address') }}</dt>
                                <dd class="break-words text-sm tabular-nums text-text-primary">{{ $wh->address_ar ?? $wh->address_en ?? '—' }}</dd>
                            </div>
                            <div class="min-w-0 space-y-1">
                                <dt class="text-xs font-medium text-text-secondary">{{ __('inventory.is_default_short') }}</dt>
                                <dd class="break-words text-sm tabular-nums text-text-primary">@if ($wh->is_default)
                                    <x-badge variant="info">{{ __('inventory.is_default_short') }}</x-badge>
                                @else
                                    <span class="text-text-muted">—</span>
                                @endif</dd>
                            </div>
                            <div class="min-w-0 space-y-1">
                                <dt class="text-xs font-medium text-text-secondary">{{ __('inventory.status') }}</dt>
                                <dd class="break-words text-sm tabular-nums text-text-primary">@if ($wh->active)
                                    <x-badge variant="success">{{ __('inventory.active') }}</x-badge>
                                @else
                                    <x-badge>{{ __('inventory.inactive') }}</x-badge>
                                @endif</dd>
                            </div>
                            @if ($canManage)
                                <div class="min-w-0 space-y-1 col-span-2">
                                <dt class="text-xs font-medium text-text-secondary">{{ __('refinement_lists.actions') }}</dt>
                                <dd class="break-words text-sm tabular-nums text-text-primary"><div class="flex items-center justify-center gap-2">
                                        <button id="warehouse-edit-mobile-{{ $wh->id }}" wire:loading.attr="disabled" type="button" wire:click="openEditModal({{ $wh->id }})"
                                                class="touch-action text-primary hover:underline font-bold text-xs">
                                            {{ __('inventory.edit') }}
                                        </button>
                                        @if (! $wh->is_default)
                                            <button wire:loading.attr="disabled" type="button" wire:click="deleteWarehouse({{ $wh->id }})"
                                                    onclick="confirm('{{ __('inventory.confirm_delete_warehouse') }}') || event.stopImmediatePropagation()"
                                                    class="touch-action text-danger hover:underline text-xs">
                                                {{ __('inventory.delete') }}
                                            </button>
                                        @endif
                                    </div></dd>
                            </div>
                            @endif
                        </dl>
                        </article>
                    @empty
                        <article class="p-4">
                            <dl class="grid grid-cols-2 gap-x-3 gap-y-4">
                            <div class="col-span-2 py-4 text-center text-sm text-text-secondary">
                                {{ __('inventory.no_warehouses_registered') }}
                            </div>
                        </dl>
                        </article>
                    @endforelse
                </div>

    <div class="p-4"><x-table-pagination :paginator="$warehouses" /></div>
    </x-card>

    <!-- Create/Edit Modal -->
    @if ($showModal)
        <x-dialog-shell :title="$editingId ? __('inventory.edit_warehouse') : __('inventory.add_warehouse')" title-id="warehouse-modal-title" close-action="$set('showModal', false)" save-target="save" max-width="max-w-md">
            <div class="space-y-3 text-sm">
                <div>
                    <label for="warehouse-code" class="block font-bold text-text-secondary mb-1">{{ __('inventory.warehouse_code') }} *</label>
                    <input id="warehouse-code"
                           type="text"
                           wire:model="code"
                           dir="ltr"
                           placeholder="{{ __('inventory.warehouse_code_placeholder') }}"
                           aria-invalid="{{ $errors->has('code') ? 'true' : 'false' }}"
                           @if ($errors->has('code')) aria-describedby="warehouse-code-error" @endif
                           class="min-h-11 w-full h-11 px-2.5 rounded-control border border-border text-sm font-mono @error('code') border-danger @enderror" />
                    @error('code') <span id="warehouse-code-error" role="alert" class="text-danger text-xs">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label for="warehouse-name-ar" class="block font-bold text-text-secondary mb-1">{{ __('inventory.warehouse_name_ar') }} *</label>
                    <input id="warehouse-name-ar"
                           type="text"
                           wire:model="name_ar"
                           dir="rtl"
                           aria-invalid="{{ $errors->has('name_ar') ? 'true' : 'false' }}"
                           @if ($errors->has('name_ar')) aria-describedby="warehouse-name-ar-error" @endif
                           class="min-h-11 w-full h-11 px-2.5 rounded-control border border-border text-sm @error('name_ar') border-danger @enderror" />
                    @error('name_ar') <span id="warehouse-name-ar-error" role="alert" class="text-danger text-xs">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label for="warehouse-name-en" class="block font-bold text-text-secondary mb-1">{{ __('inventory.warehouse_name_en') }}</label>
                    <input id="warehouse-name-en"
                           type="text"
                           wire:model="name_en"
                           dir="ltr"
                           aria-invalid="{{ $errors->has('name_en') ? 'true' : 'false' }}"
                           @if ($errors->has('name_en')) aria-describedby="warehouse-name-en-error" @endif
                           class="min-h-11 w-full h-11 px-2.5 rounded-control border border-border text-sm @error('name_en') border-danger @enderror" />
                    @error('name_en') <span id="warehouse-name-en-error" role="alert" class="text-danger text-xs">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label for="warehouse-address-ar" class="block font-bold text-text-secondary mb-1">{{ __('inventory.address') }} ({{ __('inventory.name_ar') }})</label>
                    <textarea id="warehouse-address-ar"
                              wire:model="address_ar"
                              dir="rtl"
                              rows="2"
                              aria-invalid="{{ $errors->has('address_ar') ? 'true' : 'false' }}"
                              @if ($errors->has('address_ar')) aria-describedby="warehouse-address-ar-error" @endif
                              class="w-full p-2.5 rounded-control border border-border text-sm"></textarea>
                    @error('address_ar') <span id="warehouse-address-ar-error" role="alert" class="text-danger text-xs">{{ $message }}</span> @enderror
                </div>
                <div class="p-3 rounded-control bg-surface-soft border border-border space-y-2">
                    <label for="warehouse-is-default" class="inline-flex min-h-11 items-center gap-2 cursor-pointer select-none">
                        <input id="warehouse-is-default" type="checkbox" wire:model="is_default" class="size-5 rounded border-border text-primary accent-primary focus:ring-primary" />
                        <span class="font-bold text-text-primary">{{ __('inventory.is_default') }}</span>
                    </label>
                    <p class="text-xs text-text-muted ps-7">{{ __('inventory.default_warehouse_hint') }}</p>
                </div>
                <div class="pt-1">
                    <label for="warehouse-active" class="inline-flex min-h-11 items-center gap-2 cursor-pointer select-none">
                        <input id="warehouse-active" type="checkbox" wire:model="active" class="size-5 rounded border-border text-primary accent-primary focus:ring-primary" />
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
