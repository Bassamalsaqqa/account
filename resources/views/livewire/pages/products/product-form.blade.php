<div class="space-y-6 max-w-5xl mx-auto">
    <!-- Header -->
    <div class="flex items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <a href="{{ route('products.index') }}"
               class="p-2 rounded-control border border-border bg-white text-text-secondary hover:text-text-primary hover:bg-surface-soft transition-colors">
                <x-icon name="arrow-right" class="w-4 h-4 rtl:rotate-0 ltr:rotate-180" />
            </a>
            <div>
                <h1 class="text-xl font-extrabold text-text-primary">
                    {{ $isEditing ? __('inventory.edit_product') . ': ' . ($product && app()->getLocale() === 'en' && $product->name_en ? $product->name_en : $product?->name_ar) : __('inventory.add_product') }}
                </h1>
                <p class="text-xs text-text-secondary mt-0.5">
                    {{ $isEditing ? __('inventory.product_details') : __('inventory.add_product_subtitle') }}
                </p>
            </div>
        </div>

        <button type="button"
                wire:click="save"
                wire:loading.attr="disabled"
                class="inline-flex items-center gap-2 px-5 py-2.5 bg-primary hover:bg-primary-hover text-white text-xs font-bold rounded-control shadow-button transition-colors disabled:opacity-50">
            <span wire:loading.remove wire:target="save">{{ __('inventory.save_data') }}</span>
            <span wire:loading wire:target="save">{{ __('inventory.saving') }}</span>
        </button>
    </div>

    <!-- Messages -->
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

    <!-- Main Catalog Details Card -->
    <x-card padding="p-6">
        <h2 class="text-sm font-extrabold text-text-primary border-b border-border pb-3 mb-5 flex items-center gap-2">
            <x-icon name="box" class="w-4 h-4 text-primary" />
            <span>{{ __('inventory.basic_info') }}</span>
        </h2>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <!-- Arabic Name -->
            <div class="space-y-1">
                <label class="block text-xs font-bold text-text-secondary">
                    {{ __('inventory.name_ar') }} <span class="text-danger">*</span>
                </label>
                <input type="text"
                       wire:model="name_ar"
                       dir="rtl"
                       class="w-full h-9 px-3 rounded-control border border-border bg-white text-xs text-text-primary focus:outline-none focus:border-primary focus:ring-2 focus:ring-focus-ring @error('name_ar') border-danger @enderror" />
                @error('name_ar') <span class="text-[11px] text-danger">{{ $message }}</span> @enderror
            </div>

            <!-- English Name -->
            <div class="space-y-1">
                <label class="block text-xs font-bold text-text-secondary">
                    {{ __('inventory.name_en') }}
                </label>
                <input type="text"
                       wire:model="name_en"
                       dir="ltr"
                       class="w-full h-9 px-3 rounded-control border border-border bg-white text-xs text-text-primary focus:outline-none focus:border-primary focus:ring-2 focus:ring-focus-ring @error('name_en') border-danger @enderror" />
                @error('name_en') <span class="text-[11px] text-danger">{{ $message }}</span> @enderror
            </div>

            <!-- SKU -->
            <div class="space-y-1">
                <label class="block text-xs font-bold text-text-secondary">
                    {{ __('inventory.sku') }}
                </label>
                <input type="text"
                       wire:model="sku"
                       dir="ltr"
                       placeholder="{{ __('inventory.sku_placeholder') }}"
                       class="w-full h-9 px-3 rounded-control border border-border bg-white text-xs font-mono text-text-primary focus:outline-none focus:border-primary focus:ring-2 focus:ring-focus-ring @error('sku') border-danger @enderror" />
                @error('sku') <span class="text-[11px] text-danger">{{ $message }}</span> @enderror
            </div>

            <!-- Product Type -->
            <div class="space-y-1">
                <label class="block text-xs font-bold text-text-secondary">
                    {{ __('inventory.product_type') }} <span class="text-danger">*</span>
                </label>
                <select wire:model="product_type"
                        class="w-full h-9 px-3 rounded-control border border-border bg-white text-xs text-text-primary focus:outline-none focus:border-primary focus:ring-2 focus:ring-focus-ring">
                    <option value="{{ \App\Models\Product::TYPE_STOCK }}">{{ __('inventory.type_stock') }}</option>
                    <option value="{{ \App\Models\Product::TYPE_NON_STOCK }}">{{ __('inventory.type_non_stock') }}</option>
                    <option value="{{ \App\Models\Product::TYPE_SERVICE }}">{{ __('inventory.type_service') }}</option>
                </select>
                @error('product_type') <span class="text-[11px] text-danger">{{ $message }}</span> @enderror
            </div>

            <!-- Category -->
            <div class="space-y-1">
                <label class="block text-xs font-bold text-text-secondary">
                    {{ __('inventory.category') }}
                </label>
                <select wire:model="category_id"
                        class="w-full h-9 px-3 rounded-control border border-border bg-white text-xs text-text-primary focus:outline-none focus:border-primary focus:ring-2 focus:ring-focus-ring">
                    <option value="">{{ __('inventory.select_category') }}</option>
                    @foreach ($categories as $c)
                        <option value="{{ $c->id }}">{{ $c->name_ar }}</option>
                    @endforeach
                </select>
                @error('category_id') <span class="text-[11px] text-danger">{{ $message }}</span> @enderror
            </div>

            <!-- Brand -->
            <div class="space-y-1">
                <label class="block text-xs font-bold text-text-secondary">
                    {{ __('inventory.brand') }}
                </label>
                <select wire:model="brand_id"
                        class="w-full h-9 px-3 rounded-control border border-border bg-white text-xs text-text-primary focus:outline-none focus:border-primary focus:ring-2 focus:ring-focus-ring">
                    <option value="">{{ __('inventory.select_brand') }}</option>
                    @foreach ($brands as $b)
                        <option value="{{ $b->id }}">{{ $b->name_ar }}</option>
                    @endforeach
                </select>
                @error('brand_id') <span class="text-[11px] text-danger">{{ $message }}</span> @enderror
            </div>

            <!-- Base Unit -->
            <div class="space-y-1">
                <label class="block text-xs font-bold text-text-secondary">
                    {{ __('inventory.base_unit') }} <span class="text-danger">*</span>
                </label>
                <select wire:model="base_unit_id"
                        @disabled($hasMovements)
                        class="w-full h-9 px-3 rounded-control border border-border bg-white text-xs text-text-primary focus:outline-none focus:border-primary focus:ring-2 focus:ring-focus-ring disabled:bg-surface-soft disabled:text-text-muted">
                    <option value="">{{ __('inventory.select_base_unit') }}</option>
                    @foreach ($units as $u)
                        <option value="{{ $u->id }}">{{ $u->name_ar }} ({{ $u->symbol_ar ?? $u->code }})</option>
                    @endforeach
                </select>
                @if ($hasMovements)
                    <span class="text-[10px] text-text-muted">{{ __('inventory.cannot_change_base_unit_notice') }}</span>
                @endif
                @error('base_unit_id') <span class="text-[11px] text-danger">{{ $message }}</span> @enderror
            </div>

            <!-- Status Checkbox -->
            <div class="space-y-1 flex items-center pt-5">
                <label class="flex items-center gap-2 cursor-pointer select-none">
                    <input type="checkbox" wire:model="active" class="rounded border-border text-primary focus:ring-primary w-4 h-4" />
                    <span class="text-xs font-bold text-text-primary">{{ __('inventory.active') }}</span>
                </label>
            </div>
        </div>

        <!-- Tracking & Pricing Section -->
        <h2 class="text-sm font-extrabold text-text-primary border-b border-border pb-3 mb-5 mt-8 flex items-center gap-2">
            <x-icon name="check" class="w-4 h-4 text-primary" />
            <span>{{ __('inventory.pricing_and_policies') }}</span>
        </h2>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <!-- Track Stock -->
            <div class="p-3.5 rounded-control border border-border bg-surface-soft/60 space-y-1">
                <label class="flex items-center gap-2 cursor-pointer select-none">
                    <input type="checkbox" wire:model="track_stock" class="rounded border-border text-primary focus:ring-primary w-4 h-4" />
                    <span class="text-xs font-bold text-text-primary">{{ __('inventory.track_stock') }}</span>
                </label>
                <p class="text-[11px] text-text-muted pr-6">{{ __('inventory.track_stock_hint') }}</p>
            </div>

            <!-- Track Expiry -->
            <div class="p-3.5 rounded-control border border-border bg-surface-soft/60 space-y-1">
                <label class="flex items-center gap-2 cursor-pointer select-none">
                    <input type="checkbox" wire:model="track_expiry" class="rounded border-border text-primary focus:ring-primary w-4 h-4" />
                    <span class="text-xs font-bold text-text-primary">{{ __('inventory.track_expiry') }}</span>
                </label>
                <p class="text-[11px] text-text-muted pr-6">{{ __('inventory.track_expiry_hint') }}</p>
            </div>

            <!-- Minimum Stock -->
            <div class="space-y-1">
                <label class="block text-xs font-bold text-text-secondary">
                    {{ __('inventory.minimum_stock') }}
                </label>
                <input type="number"
                       step="any"
                       wire:model="minimum_stock"
                       dir="ltr"
                       placeholder="0"
                       class="w-full h-9 px-3 rounded-control border border-border bg-white text-xs font-mono text-text-primary focus:outline-none focus:border-primary focus:ring-2 focus:ring-focus-ring @error('minimum_stock') border-danger @enderror" />
                @error('minimum_stock') <span class="text-[11px] text-danger">{{ $message }}</span> @enderror
            </div>

            <!-- Suggested Sale Price -->
            <div class="space-y-1">
                <label class="block text-xs font-bold text-text-secondary">
                    {{ __('inventory.suggested_sale_price') }} ({{ $company->base_currency_code }})
                </label>
                <input type="number"
                       step="any"
                       wire:model="suggested_sale_price"
                       dir="ltr"
                       placeholder="0.00"
                       class="w-full h-9 px-3 rounded-control border border-border bg-white text-xs font-mono text-text-primary focus:outline-none focus:border-primary focus:ring-2 focus:ring-focus-ring @error('suggested_sale_price') border-danger @enderror" />
                <span class="text-[10px] text-text-muted">{{ __('inventory.suggested_sale_price_hint') }}</span>
                @error('suggested_sale_price') <span class="text-[11px] text-danger">{{ $message }}</span> @enderror
            </div>

            <!-- Suggested Purchase Cost (GATED by canViewCost) -->
            @if ($canViewCost)
                <div class="space-y-1">
                    <label class="block text-xs font-bold text-text-secondary">
                        {{ __('inventory.suggested_purchase_cost') }} ({{ $company->base_currency_code }})
                    </label>
                    <input type="number"
                           step="any"
                           wire:model="suggested_purchase_cost"
                           dir="ltr"
                           placeholder="0.00"
                           class="w-full h-9 px-3 rounded-control border border-border bg-white text-xs font-mono text-text-primary focus:outline-none focus:border-primary focus:ring-2 focus:ring-focus-ring @error('suggested_purchase_cost') border-danger @enderror" />
                    <span class="text-[10px] text-text-muted">{{ __('inventory.suggested_purchase_cost_hint') }}</span>
                    @error('suggested_purchase_cost') <span class="text-[11px] text-danger">{{ $message }}</span> @enderror
                </div>
            @endif
        </div>
    </x-card>

    @if ($isEditing && $product)
        <!-- Alternate Units Management -->
        <x-card padding="p-6">
            <h2 class="text-sm font-extrabold text-text-primary border-b border-border pb-3 mb-4 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <x-icon name="dashboard" class="w-4 h-4 text-primary" />
                    <span>{{ __('inventory.alternate_units') }}</span>
                </div>
            </h2>

            <div class="space-y-4">
                <!-- Add Alternate Unit Form -->
                <div class="p-4 rounded-control bg-surface-soft border border-border grid grid-cols-1 sm:grid-cols-4 gap-3 items-end">
                    <div>
                        <label class="block text-xs font-bold text-text-secondary mb-1">{{ __('inventory.alternate_unit') }}</label>
                        <select wire:model="new_alt_unit_id"
                                class="w-full h-8 px-2 rounded-control border border-border bg-white text-xs text-text-primary">
                            <option value="">{{ __('inventory.select_unit') }}</option>
                            @foreach ($units as $u)
                                @if ($u->id !== $product->base_unit_id)
                                    <option value="{{ $u->id }}">{{ app()->getLocale() === 'en' ? ($u->name_en ?: $u->name_ar) : $u->name_ar }}</option>
                                @endif
                            @endforeach
                        </select>
                        @error('new_alt_unit_id') <span class="text-[10px] text-danger">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-text-secondary mb-1">{{ __('inventory.conversion_factor') }}</label>
                        <input type="number" step="any" wire:model="new_alt_conversion" dir="ltr" placeholder="{{ __('inventory.example_conversion') }}"
                               class="w-full h-8 px-2 rounded-control border border-border bg-white text-xs font-mono" />
                        @error('new_alt_conversion') <span class="text-[10px] text-danger">{{ $message }}</span> @enderror
                    </div>
                    <div class="flex items-center gap-3 pb-1">
                        <label class="flex items-center gap-1.5 text-xs text-text-secondary cursor-pointer">
                            <input type="checkbox" wire:model="new_alt_sell" class="rounded border-border text-primary w-3.5 h-3.5" />
                            <span>{{ __('inventory.default_sale_unit') }}</span>
                        </label>
                        <label class="flex items-center gap-1.5 text-xs text-text-secondary cursor-pointer">
                            <input type="checkbox" wire:model="new_alt_purchase" class="rounded border-border text-primary w-3.5 h-3.5" />
                            <span>{{ __('inventory.default_purchase_unit') }}</span>
                        </label>
                    </div>
                    <div>
                        <button type="button" wire:click="addAlternateUnit"
                                class="w-full h-8 px-3 rounded-control bg-primary text-white text-xs font-bold hover:bg-primary-hover transition-colors">
                            {{ __('inventory.add_alternate_unit') }}
                        </button>
                    </div>
                </div>

                <!-- Existing Alternate Units Table -->
                @if ($productUnits->isNotEmpty())
                    <div class="overflow-x-auto">
                        <table class="w-full text-xs text-right rtl:text-right ltr:text-left">
                            <thead class="bg-surface-soft border-b border-border text-text-secondary font-bold">
                                <tr>
                                    <th class="p-2.5">{{ __('inventory.unit') }}</th>
                                    <th class="p-2.5">{{ __('inventory.conversion_factor_to_base') }} (→ {{ app()->getLocale() === 'en' ? ($product->baseUnit?->name_en ?: $product->baseUnit?->name_ar) : $product->baseUnit?->name_ar }})</th>
                                    <th class="p-2.5 text-center">{{ __('inventory.default_sale_unit') }}</th>
                                    <th class="p-2.5 text-center">{{ __('inventory.default_purchase_unit') }}</th>
                                    <th class="p-2.5 text-center w-16"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                @foreach ($productUnits as $pu)
                                    <tr>
                                        <td class="p-2.5 font-bold text-text-primary">
                                            <div class="flex items-center gap-1.5">
                                                <span>{{ app()->getLocale() === 'en' ? ($pu->unit?->name_en ?: $pu->unit?->name_ar) : $pu->unit?->name_ar }}</span>
                                                @if ($pu->is_base)
                                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold bg-primary/10 text-primary">{{ __('inventory.base_unit_badge') }}</span>
                                                @endif
                                            </div>
                                        </td>
                                        <td class="p-2.5 font-mono dir-ltr">
                                            @if ($pu->is_base)
                                                1.000000 ({{ __('inventory.base_unit_badge') }})
                                            @else
                                                1 {{ app()->getLocale() === 'en' ? ($pu->unit?->name_en ?: $pu->unit?->name_ar) : $pu->unit?->name_ar }} = {{ $pu->conversion_to_base }} {{ app()->getLocale() === 'en' ? ($product->baseUnit?->name_en ?: $product->baseUnit?->name_ar) : $product->baseUnit?->name_ar }}
                                            @endif
                                        </td>
                                        <td class="p-2.5 text-center">
                                            @if ($pu->is_default_sale)
                                                <span class="text-success font-bold">&#10003;</span>
                                            @else
                                                <span class="text-text-muted">&times;</span>
                                            @endif
                                        </td>
                                        <td class="p-2.5 text-center">
                                            @if ($pu->is_default_purchase)
                                                <span class="text-success font-bold">&#10003;</span>
                                            @else
                                                <span class="text-text-muted">&times;</span>
                                            @endif
                                        </td>
                                        <td class="p-2.5 text-center">
                                            @if ($pu->is_base)
                                                <span class="text-text-muted">-</span>
                                            @else
                                                <button type="button" wire:click="removeAlternateUnit({{ $pu->id }})"
                                                        class="text-danger hover:underline text-xs">
                                                    {{ __('inventory.delete') }}
                                                </button>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </x-card>

        <!-- Barcodes Management -->
        <x-card padding="p-6">
            <h2 class="text-sm font-extrabold text-text-primary border-b border-border pb-3 mb-4 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <x-icon name="receipt" class="w-4 h-4 text-primary" />
                    <span>{{ __('inventory.barcodes') }}</span>
                </div>
            </h2>

            <div class="space-y-4">
                <!-- Add Barcode Form -->
                <div class="p-4 rounded-control bg-surface-soft border border-border grid grid-cols-1 sm:grid-cols-4 gap-3 items-end">
                    <div>
                        <label class="block text-xs font-bold text-text-secondary mb-1">{{ __('inventory.barcode') }}</label>
                        <input type="text" wire:model="new_barcode" dir="ltr" placeholder="{{ __('inventory.scan_or_type_barcode') }}"
                               class="w-full h-8 px-2 rounded-control border border-border bg-white text-xs font-mono" />
                        @error('new_barcode') <span class="text-[10px] text-danger">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-text-secondary mb-1">{{ __('inventory.associated_unit') }}</label>
                        <select wire:model="new_barcode_unit_id"
                                class="w-full h-8 px-2 rounded-control border border-border bg-white text-xs text-text-primary">
                            <option value="">{{ __('inventory.base_unit') }} ({{ app()->getLocale() === 'en' ? ($product->baseUnit?->name_en ?: $product->baseUnit?->name_ar) : $product->baseUnit?->name_ar }})</option>
                            @foreach ($productUnits as $pu)
                                <option value="{{ $pu->unit_id }}">{{ app()->getLocale() === 'en' ? ($pu->unit?->name_en ?: $pu->unit?->name_ar) : $pu->unit?->name_ar }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex items-center gap-2 pb-1">
                        <label class="flex items-center gap-1.5 text-xs text-text-secondary cursor-pointer">
                            <input type="checkbox" wire:model="new_barcode_primary" class="rounded border-border text-primary w-3.5 h-3.5" />
                            <span>{{ __('inventory.primary_barcode') }}</span>
                        </label>
                    </div>
                    <div>
                        <button type="button" wire:click="addBarcode"
                                class="w-full h-8 px-3 rounded-control bg-primary text-white text-xs font-bold hover:bg-primary-hover transition-colors">
                            {{ __('inventory.add_barcode') }}
                        </button>
                    </div>
                </div>

                <!-- Existing Barcodes Table -->
                @if ($productBarcodes->isNotEmpty())
                    <div class="overflow-x-auto">
                        <table class="w-full text-xs text-right rtl:text-right ltr:text-left">
                            <thead class="bg-surface-soft border-b border-border text-text-secondary font-bold">
                                <tr>
                                    <th class="p-2.5">{{ __('inventory.barcode') }}</th>
                                    <th class="p-2.5">{{ __('inventory.unit') }}</th>
                                    <th class="p-2.5 text-center">{{ __('inventory.primary') }}</th>
                                    <th class="p-2.5 text-center w-16"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                @foreach ($productBarcodes as $pb)
                                    <tr>
                                        <td class="p-2.5 font-mono font-bold dir-ltr text-text-primary">{{ $pb->barcode }}</td>
                                        <td class="p-2.5 text-text-secondary">{{ app()->getLocale() === 'en' ? ($pb->unit?->name_en ?: $pb->unit?->name_ar ?? $product->baseUnit?->name_en ?: $product->baseUnit?->name_ar) : ($pb->unit?->name_ar ?? $product->baseUnit?->name_ar) }}</td>
                                        <td class="p-2.5 text-center">
                                            @if ($pb->is_primary)
                                                <x-badge variant="success">{{ __('inventory.primary') }}</x-badge>
                                            @endif
                                        </td>
                                        <td class="p-2.5 text-center">
                                            <button type="button" wire:click="removeBarcode({{ $pb->id }})"
                                                    class="text-danger hover:underline text-xs">
                                                {{ __('inventory.delete') }}
                                            </button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </x-card>

        <!-- Images Gallery & Upload -->
        <x-card padding="p-6">
            <h2 class="text-sm font-extrabold text-text-primary border-b border-border pb-3 mb-4 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <x-icon name="camera" class="w-4 h-4 text-primary" />
                    <span>{{ __('inventory.images') }}</span>
                </div>
            </h2>

            <div class="space-y-4">
                <!-- Upload input -->
                <div class="p-4 rounded-control border-2 border-dashed border-border bg-surface-soft/40 flex flex-col sm:flex-row items-center justify-between gap-4">
                    <div>
                        <p class="text-xs font-bold text-text-primary">{{ __('inventory.add_product_images') }}</p>
                        <p class="text-[11px] text-text-muted mt-0.5">{{ __('inventory.accepted_image_formats_hint') }}</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <input type="file" wire:model="new_image" accept="image/jpeg,image/png,image/webp" class="text-xs text-text-secondary file:mr-2 file:py-1.5 file:px-3 file:rounded-control file:border-0 file:text-xs file:font-semibold file:bg-primary-50 file:text-primary hover:file:bg-primary-100" />
                        <button type="button" wire:click="uploadImage" wire:loading.attr="disabled"
                                class="px-3 py-1.5 rounded-control bg-primary text-white text-xs font-bold hover:bg-primary-hover transition-colors disabled:opacity-50">
                            {{ __('inventory.upload_image') }}
                        </button>
                    </div>
                </div>
                @error('new_image') <span class="text-[11px] text-danger">{{ $message }}</span> @enderror

                <!-- Images Grid -->
                @if ($productImages->isNotEmpty())
                    <div class="grid grid-cols-2 sm:grid-cols-4 md:grid-cols-6 gap-3 pt-2">
                        @foreach ($productImages as $img)
                            <div class="relative group rounded-control border border-border overflow-hidden bg-surface-soft">
                                <img src="{{ asset('storage/' . $img->thumbnail_path) }}" alt="{{ $product->name_ar }}" class="w-full h-24 object-cover" />
                                <div class="absolute inset-0 bg-slate-900/60 opacity-0 group-hover:opacity-100 transition-opacity flex flex-col items-center justify-center gap-1.5 p-1 text-[10px]">
                                    @if (! $img->is_primary)
                                        <button type="button" wire:click="setPrimaryImage({{ $img->id }})" class="text-white hover:underline">
                                            {{ __('inventory.set_as_primary') }}
                                        </button>
                                    @else
                                        <span class="text-success font-bold">{{ __('inventory.primary') }}</span>
                                    @endif
                                    <button type="button" wire:click="deleteImage({{ $img->id }})" class="text-danger hover:underline">
                                        {{ __('inventory.delete') }}
                                    </button>
                                </div>
                                @if ($img->is_primary)
                                    <div class="absolute top-1 right-1">
                                        <x-badge variant="success">{{ __('inventory.primary') }}</x-badge>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </x-card>
    @endif
</div>
