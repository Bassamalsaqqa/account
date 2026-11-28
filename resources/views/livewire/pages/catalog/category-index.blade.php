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
            <button id="category-add-trigger" wire:loading.attr="disabled" type="button" wire:click="openCreateModal"
                    class="touch-action bg-primary hover:bg-primary-hover text-white text-sm font-bold shadow-button transition-colors">
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
        <div class="hidden lg:block overflow-x-auto">
            <table class="data-table w-full text-xs text-right rtl:text-right ltr:text-left">
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
                                    <bdi dir="ltr" class="text-xs text-text-muted block">{{ $cat->name_en }}</bdi>
                                @endif
                            </td>
                            <td class="p-3.5 text-text-secondary">
                                {{ $cat->parent?->name_ar ?? '—' }}
                            </td>
                            <td class="p-3.5 text-center font-mono text-text-secondary">
                                {{ $cat->sort_order }}
                            </td>
                            <td class="p-3.5 text-center font-mono font-bold text-text-primary">
                                {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayQuantity($cat->products->count()) }}
                            </td>
                            <td class="p-3.5 text-center">
                                @if ($cat->active)
                                    <x-badge variant="success">{{ __('inventory.active') }}</x-badge>
                                @else
                                    <x-badge>{{ __('inventory.inactive') }}</x-badge>
                                @endif
                            </td>
                            @if ($canManage)
                                <td class="table-actions p-3.5 text-center">
                                    <div class="flex items-center justify-center gap-2">
                                        <button id="category-edit-desktop-{{ $cat->id }}" wire:loading.attr="disabled" type="button" wire:click="openEditModal({{ $cat->id }})"
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
<div class="lg:hidden divide-y divide-border">
            @forelse ($categories as $cat)
                <x-compact-mobile-row wire:key="category-mobile-{{ $cat->id }}" :has-details="true">
                    <x-slot:primary>
                        <span class="font-bold text-sm text-text-primary truncate">{{ $cat->name_ar }}</span>
                        <span class="shrink-0">
                            @if ($cat->active)
                                <x-badge variant="success">{{ __('inventory.active') }}</x-badge>
                            @else
                                <x-badge>{{ __('inventory.inactive') }}</x-badge>
                            @endif
                        </span>
                    </x-slot:primary>

                    <x-slot:subtitle>
                        @if ($cat->name_en)<bdi dir="ltr">{{ $cat->name_en }}</bdi> &middot; @endif
                        @if ($cat->parent){{ $cat->parent->name_ar }} &middot; @endif
                        {{ __('inventory.products_count') }}: <bdi dir="ltr" class="font-mono">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayQuantity($cat->products->count()) }}</bdi>
                    </x-slot:subtitle>

                    <x-slot:details>
                        <dl class="grid grid-cols-2 gap-2">
                            <div>
                                <dt class="text-text-secondary">{{ __('inventory.parent_category') }}</dt>
                                <dd class="mt-0.5 text-text-primary">{{ $cat->parent?->name_ar ?? '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-text-secondary">{{ __('inventory.sort_order') }}</dt>
                                <dd class="mt-0.5 font-mono text-text-primary">{{ $cat->sort_order }}</dd>
                            </div>
                        </dl>
                        @if ($canManage)
                            <div class="flex flex-wrap items-center justify-end gap-2">
                                <button id="category-edit-mobile-{{ $cat->id }}" wire:loading.attr="disabled" type="button" wire:click="openEditModal({{ $cat->id }})"
                                        class="touch-action text-primary hover:underline font-bold text-xs">
                                    {{ __('inventory.edit') }}
                                </button>
                                <button wire:loading.attr="disabled" type="button" wire:click="deleteCategory({{ $cat->id }})"
                                        onclick="confirm('{{ __('inventory.confirm_delete_category') }}') || event.stopImmediatePropagation()"
                                        class="touch-action text-danger hover:underline text-xs">
                                    {{ __('inventory.delete') }}
                                </button>
                            </div>
                        @endif
                    </x-slot:details>
                </x-compact-mobile-row>
            @empty
                <p class="p-6 text-center text-sm text-text-secondary">{{ __('inventory.no_categories_defined') }}</p>
            @endforelse
        </div>

    <div class="p-4"><x-table-pagination :paginator="$categories" /></div>
    </x-card>

    <!-- Create/Edit Modal -->
    @if ($showModal)
        <x-dialog-shell :title="$editingId ? __('inventory.edit_category') : __('inventory.add_category')" title-id="category-modal-title" close-action="$set('showModal', false)" save-target="save" max-width="max-w-md">
            <div class="space-y-3 text-sm">
                <div>
                    <label for="category-name-ar" class="block font-bold text-text-secondary mb-1">{{ __('inventory.category_name_ar') }} *</label>
                    <input id="category-name-ar"
                           type="text"
                           wire:model="name_ar"
                           dir="rtl"
                           aria-invalid="{{ $errors->has('name_ar') ? 'true' : 'false' }}"
                           @if ($errors->has('name_ar')) aria-describedby="category-name-ar-error" @endif
                           class="min-h-11 lg:min-h-9.5 w-full px-3 rounded-control border border-border-control bg-canvas text-base lg:text-sm @error('name_ar') border-danger @enderror" />
                    @error('name_ar') <span id="category-name-ar-error" role="alert" class="text-danger text-xs">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label for="category-name-en" class="block font-bold text-text-secondary mb-1">{{ __('inventory.category_name_en') }}</label>
                    <input id="category-name-en"
                           type="text"
                           wire:model="name_en"
                           dir="ltr"
                           aria-invalid="{{ $errors->has('name_en') ? 'true' : 'false' }}"
                           @if ($errors->has('name_en')) aria-describedby="category-name-en-error" @endif
                           class="min-h-11 lg:min-h-9.5 w-full px-3 rounded-control border border-border-control bg-canvas text-base lg:text-sm @error('name_en') border-danger @enderror" />
                    @error('name_en') <span id="category-name-en-error" role="alert" class="text-danger text-xs">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label for="category-parent-id" class="block font-bold text-text-secondary mb-1">{{ __('inventory.parent_category') }}</label>
                    <select id="category-parent-id"
                            wire:model="parent_id"
                            aria-invalid="{{ $errors->has('parent_id') ? 'true' : 'false' }}"
                            @if ($errors->has('parent_id')) aria-describedby="category-parent-id-error" @endif
                            class="min-h-11 lg:min-h-9.5 w-full px-3 rounded-control border border-border-control bg-canvas text-base lg:text-sm">
                        <option value="">{{ __('inventory.none_root') }}</option>
                        @foreach ($parentOptions as $c)
                            @if ($editingId !== $c->id)
                                <option value="{{ $c->id }}">{{ $c->name_ar }}</option>
                            @endif
                        @endforeach
                    </select>
                    @error('parent_id') <span id="category-parent-id-error" role="alert" class="text-danger text-xs">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label for="category-sort-order" class="block font-bold text-text-secondary mb-1">{{ __('inventory.sort_order') }}</label>
                    <input id="category-sort-order"
                           inputmode="numeric"
                           type="number"
                           wire:model="sort_order"
                           dir="ltr"
                           aria-invalid="{{ $errors->has('sort_order') ? 'true' : 'false' }}"
                           @if ($errors->has('sort_order')) aria-describedby="category-sort-order-error" @endif
                           class="min-h-11 lg:min-h-9.5 w-full px-3 rounded-control border border-border-control bg-canvas text-base lg:text-sm" />
                    @error('sort_order') <span id="category-sort-order-error" role="alert" class="text-danger text-xs">{{ $message }}</span> @enderror
                </div>
                <div class="pt-1">
                    <label for="category-active" class="inline-flex min-h-11 items-center gap-2 cursor-pointer select-none">
                        <input id="category-active" type="checkbox" wire:model="active" class="size-5 rounded border-border text-primary accent-primary focus:ring-primary" />
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
