@php
    $locale = app()->getLocale();
    $isRtl = $locale === 'ar';
    $hasOptionalDetails = filled($headers['description_ar'] ?? null)
        || filled($headers['description_en'] ?? null)
        || (($headers['locale'] ?? 'ar') !== 'ar');
@endphp

<div class="space-y-6">
    <!-- Breadcrumb & Workspace Identity -->
    <div class="space-y-4">
        <nav class="flex flex-wrap items-center gap-1.5 text-xs text-text-muted font-medium" aria-label="{{ __('catalogs.catalogs') }}">
            <a href="{{ route('dashboard') }}" class="hover:text-primary transition-colors">{{ __('settings.breadcrumb_dashboard') }}</a>
            <span class="text-text-muted/60" aria-hidden="true">/</span>
            <a href="{{ route('products.index') }}" class="hover:text-primary transition-colors">{{ __('inventory.products') }}</a>
            <span class="text-text-muted/60" aria-hidden="true">/</span>
            <span class="text-primary font-bold" aria-current="page">{{ __('catalogs.catalogs') }}</span>
        </nav>

        <!-- Session & Error Banners -->
        @if(session('success'))
            <div role="status" class="p-3.5 rounded-card bg-success-bg border border-success/25 text-success text-sm font-bold flex items-center gap-2.5 shadow-xs">
                <x-icon name="check-circle" class="w-4.5 h-4.5 text-success shrink-0" />
                <span>{{ session('success') }}</span>
            </div>
        @endif

        @if($errors->any())
            <div role="alert" class="p-3.5 rounded-card bg-danger-bg border border-danger/25 text-danger text-sm shadow-xs space-y-1">
                <div class="flex items-center gap-2 font-bold">
                    <x-icon name="warning" class="w-4.5 h-4.5 text-danger shrink-0" />
                    <span>{{ __('catalogs.unexpected_error') }}</span>
                </div>
                <ul class="list-disc list-inside text-xs space-y-1">
                    @foreach($errors->all() as $err)
                        <li>{{ $err }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <!-- Main Header & Action Bar -->
        <div class="bg-surface rounded-card shadow-panel border border-border p-4 sm:p-5">
            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                <div class="min-w-0 space-y-1.5">
                    <div class="flex items-center gap-2.5 flex-wrap">
                        <h1 class="text-xl md:text-2xl font-bold text-text-primary tracking-tight break-words">
                            {{ $headers['name_ar'] ?: ($headers['name_en'] ?: __('catalogs.new_catalog')) }}
                        </h1>

                        @php
                            $statusVariant = match ($status) {
                                'active' => 'success',
                                'paused' => 'warning',
                                'revoked' => 'danger',
                                default => 'neutral',
                            };
                        @endphp
                        <x-badge :variant="$statusVariant">
                            {{ __('catalogs.status_' . $status) }}
                        </x-badge>

                        @if($hasUnsavedChanges)
                            <x-badge variant="warning">
                                {{ __('catalogs.unsaved_changes') }}
                            </x-badge>
                        @endif
                    </div>

                    <div class="flex items-center gap-x-4 gap-y-1 text-xs text-text-muted flex-wrap">
                        @if($draftRevision !== null)
                            <span>{{ __('catalogs.draft_revision', ['rev' => $draftRevision]) }}</span>
                        @endif
                        <span>
                            @if($publishedRevision > 0)
                                {{ __('catalogs.published_revision', ['rev' => $publishedRevision]) }}
                            @else
                                {{ __('catalogs.never_published') }}
                            @endif
                        </span>
                        @if($publishedAt)
                            <span>{{ __('catalogs.published_at', ['date' => \Carbon\CarbonImmutable::parse($publishedAt)->timezone(app(\App\Support\Tenancy\CompanyContext::class)->company()->timezone)->format('Y-m-d H:i')]) }}</span>
                        @endif
                    </div>
                </div>

                <!-- Header Action Buttons -->
                <div class="flex items-center gap-2 flex-wrap">
                    <button
                        type="button"
                        wire:click="saveDraft"
                        wire:loading.attr="disabled"
                        @disabled(! $canManage)
                        class="min-h-11 lg:min-h-9.5 px-4 py-2 bg-surface border border-border-control rounded-control text-sm font-bold text-text-primary hover:bg-surface-soft disabled:opacity-50 disabled:cursor-not-allowed shadow-xs transition-colors cursor-pointer"
                    >
                        <span wire:loading.remove wire:target="saveDraft">{{ __('catalogs.save_draft') }}</span>
                        <span wire:loading wire:target="saveDraft">{{ __('catalogs.saving') }}</span>
                    </button>

                    <button
                        type="button"
                        id="catalog-preview-trigger" wire:click="previewPublication"
                        wire:loading.attr="disabled"
                        @disabled(! $canPublish || $catalogId === null || empty($items))
                        class="min-h-11 lg:min-h-9.5 px-4 py-2 bg-surface border border-primary/40 rounded-control text-sm font-bold text-primary hover:bg-primary-50 disabled:opacity-50 disabled:cursor-not-allowed transition-colors cursor-pointer"
                    >
                        <span wire:loading.remove wire:target="previewPublication">{{ __('catalogs.preview') }}</span>
                        <span wire:loading wire:target="previewPublication">{{ __('catalogs.preview') }}...</span>
                    </button>

                    <button
                        type="button"
                        wire:click="publish"
                        wire:loading.attr="disabled"
                        @disabled(! $canPublish || $catalogId === null || $previewHash === null || $previewRevision !== $draftRevision)
                        class="min-h-11 lg:min-h-9.5 px-4 py-2 bg-primary rounded-control text-sm font-bold text-white hover:bg-primary-hover disabled:opacity-50 disabled:cursor-not-allowed shadow-xs transition-colors cursor-pointer"
                    >
                        <span wire:loading.remove wire:target="publish">{{ __('catalogs.publish') }}</span>
                        <span wire:loading wire:target="publish">{{ __('catalogs.publish') }}...</span>
                    </button>

                    @if($catalogId !== null && $publishedRevision > 0)
                        @if($status === 'active')
                            <button
                                type="button"
                                wire:click="manageState('paused')"
                                @disabled(! $canRevoke)
                                wire:loading.attr="disabled" wire:target="manageState"
                                class="min-h-11 lg:min-h-9.5 px-3.5 py-2 bg-warning-bg text-warning border border-warning/25 rounded-control text-sm font-bold hover:bg-warning-bg/80 disabled:opacity-50 cursor-pointer">
                                {{ __('catalogs.pause') }}
                            </button>
                        @elseif($status === 'paused')
                            <button
                                type="button"
                                wire:click="manageState('active')"
                                @disabled(! $canPublish)
                                wire:loading.attr="disabled" wire:target="manageState"
                                class="min-h-11 lg:min-h-9.5 px-3.5 py-2 bg-success-bg text-success border border-success/25 rounded-control text-sm font-bold hover:bg-success-bg/80 disabled:opacity-50 cursor-pointer">
                                {{ __('catalogs.resume') }}
                            </button>
                        @endif

                        @if($status !== 'revoked')
                            <button
                                type="button"
                                wire:click="manageState('revoked')"
                                wire:confirm="{{ __('catalogs.revoke_confirm_warning') }}"
                                @disabled(! $canRevoke)
                                wire:loading.attr="disabled" wire:target="manageState"
                                class="min-h-11 lg:min-h-9.5 px-3.5 py-2 bg-danger-bg text-danger border border-danger/25 rounded-control text-sm font-bold hover:bg-danger-bg/80 disabled:opacity-50 cursor-pointer">
                                {{ __('catalogs.revoke') }}
                            </button>
                        @endif
                    @endif

                    @if($catalogId !== null && $publishedRevision > 0 && in_array($status, ['active', 'revoked'], true))
                        <button
                            type="button"
                            id="catalog-new-link-trigger-0" wire:click="openNewLink"
                            @disabled(! $canShare || ! $canPublish)
                            wire:loading.attr="disabled" wire:target="openNewLink"
                            class="min-h-11 lg:min-h-9.5 px-4 py-2 bg-surface border border-border-control hover:bg-surface-soft text-text-primary rounded-control text-sm font-bold shadow-xs transition-colors disabled:opacity-50 cursor-pointer">
                            {{ __('catalogs.new_link') }}
                        </button>
                    @endif

                    @if($catalogId !== null && $status === 'active')
                        <button
                            type="button"
                            id="catalog-share-trigger" wire:click="createOrRecoverLink"
                            @disabled(! $canShare)
                            wire:loading.attr="disabled" wire:target="createOrRecoverLink"
                            class="min-h-11 lg:min-h-9.5 px-4 py-2 bg-primary-50 border border-primary/30 text-primary rounded-control text-sm font-bold hover:bg-primary-100/60 disabled:opacity-50 transition-colors cursor-pointer">
                            {{ __('catalogs.share_link') }}
                        </button>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <!-- Main Grid Layout: Saved Catalogs List + Composer Details -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
        <!-- Saved Catalogs Switcher (Top collapsible on mobile, Start rail on desktop) -->
        <aside class="lg:col-span-4 lg:sticky lg:top-20" x-data="{ openCatalogList: @js($catalogId === null) }">
            <div class="bg-surface rounded-card shadow-panel border border-border p-4 space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-2 pb-3 border-b border-border">
                    <div class="flex items-center gap-2 min-w-0">
                        <h2 class="font-bold text-text-primary text-sm">{{ __('catalogs.catalogs') }}</h2>
                        <button type="button"
                                @click="openCatalogList = !openCatalogList"
                                :aria-expanded="openCatalogList.toString()"
                                aria-controls="catalog-saved-list"
                                class="lg:hidden min-h-11 min-w-11 inline-flex items-center justify-center rounded-control border border-border text-text-secondary hover:bg-surface-soft cursor-pointer"
                                aria-label="{{ __('catalogs.catalogs') }}">
                            <x-icon name="expand" class="w-4 h-4" />
                        </button>
                    </div>
                    <button
                        type="button"
                        wire:click="createCatalog"
                        @disabled(! $canManage)
                        wire:loading.attr="disabled" wire:target="createCatalog"
                        class="min-h-11 lg:min-h-9.5 inline-flex items-center gap-1 px-2.5 py-1.5 rounded-control text-sm text-primary hover:bg-primary-50 font-bold disabled:opacity-50 cursor-pointer shrink-0">
                        <x-icon name="plus" class="w-4 h-4" />
                        <span>{{ __('catalogs.new_catalog') }}</span>
                    </button>
                </div>

                <div id="catalog-saved-list" class="space-y-2" :class="openCatalogList ? 'block' : 'hidden lg:block'">
                    @forelse($sidebarCatalogs as $cat)
                        @php
                            $isCurrentCatalog = $publicId === $cat->public_id;
                        @endphp
                        <button type="button"
                                wire:key="sidebar-catalog-{{ $cat->public_id }}"
                                wire:click="loadCatalog('{{ $cat->public_id }}')"
                                aria-current="{{ $isCurrentCatalog ? 'true' : 'false' }}"
                                wire:loading.attr="disabled" wire:target="loadCatalog"
                                class="w-full min-h-11 text-start p-3 rounded-control border text-sm cursor-pointer transition-colors {{ $isCurrentCatalog ? 'border-primary bg-primary-50 text-text-primary' : 'border-border bg-surface hover:bg-surface-soft text-text-primary' }}">
                            <span class="block font-bold truncate">
                                {{ $cat->name_ar ?: ($cat->name_en ?: __('catalogs.title')) }}
                            </span>
                            <span class="flex items-center justify-between text-xs text-text-muted mt-1 gap-2">
                                <span>{{ __('catalogs.status_' . $cat->status) }}</span>
                                <bdi dir="ltr" class="font-mono num">r{{ $cat->published_revision }}</bdi>
                            </span>
                        </button>
                    @empty
                        <div class="text-sm text-text-muted py-4 text-center">
                            {{ __('catalogs.no_catalogs') }}
                        </div>
                    @endforelse

                    @if ($sidebarCatalogs->count() > 0 || $sidebarCatalogs->currentPage() > 1)
                        <div class="pt-3 border-t border-border">
                            <x-table-pagination :paginator="$sidebarCatalogs" />
                        </div>
                    @endif
                </div>
            </div>
        </aside>

        <!-- Composer Main Content Area -->
        <div class="lg:col-span-8 min-w-0 space-y-6">
            <!-- Section 1: Catalog Details & Display Options -->
            <section class="bg-surface rounded-card shadow-panel border border-border p-4 sm:p-5 space-y-4">
                <h2 class="text-base font-bold text-text-primary pb-2.5 border-b border-border">
                    {{ __('catalogs.details') }}
                </h2>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="catalog-name-ar" class="block text-sm font-semibold text-text-secondary mb-1">
                            {{ __('catalogs.name_ar') }} <span class="text-danger">*</span>
                        </label>
                        <input id="catalog-name-ar"
                               type="text"
                               wire:model.live.debounce.400ms="headers.name_ar"
                               @disabled(! $canManage)
                               maxlength="160"
                               aria-invalid="{{ $errors->has('headers.name_ar') ? 'true' : 'false' }}"
                               @if ($errors->has('headers.name_ar')) aria-describedby="catalog-name-ar-error" @endif
                               class="w-full min-h-11 lg:min-h-9.5 px-3 py-2 text-sm rounded-control border border-border-control bg-surface text-text-primary focus:border-primary focus:ring-1 focus:ring-primary/20 disabled:bg-surface-soft">
                        @error('headers.name_ar') <p role="alert" id="catalog-name-ar-error" class="text-xs font-semibold text-danger mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="catalog-name-en" class="block text-sm font-semibold text-text-secondary mb-1">
                            {{ __('catalogs.name_en') }}
                        </label>
                        <input id="catalog-name-en"
                               type="text"
                               dir="ltr"
                               wire:model.live.debounce.400ms="headers.name_en"
                               @disabled(! $canManage)
                               maxlength="160"
                               aria-invalid="{{ $errors->has('headers.name_en') ? 'true' : 'false' }}"
                               @if ($errors->has('headers.name_en')) aria-describedby="catalog-name-en-error" @endif
                               class="w-full min-h-11 lg:min-h-9.5 px-3 py-2 text-sm rounded-control border border-border-control bg-surface text-text-primary focus:border-primary focus:ring-1 focus:ring-primary/20 disabled:bg-surface-soft">
                        @error('headers.name_en') <p role="alert" id="catalog-name-en-error" class="text-xs font-semibold text-danger mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>

                <!-- Optional Descriptions & Default Language -->
                <details class="group border-t border-border pt-3" @if($hasOptionalDetails) open @endif>
                    <summary class="list-none [&::-webkit-details-marker]:hidden min-h-11 lg:min-h-9.5 w-full flex items-center justify-between gap-2 cursor-pointer text-sm font-semibold text-text-secondary hover:text-text-primary select-none">
                        <span>{{ __('catalogs.optional_details') }}</span>
                        <x-icon name="expand" class="w-4 h-4 shrink-0 text-text-muted transition-transform duration-150 group-open:rotate-180" />
                    </summary>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-3">
                        <div>
                            <label for="catalog-desc-ar" class="block text-sm font-semibold text-text-secondary mb-1">
                                {{ __('catalogs.description_ar') }}
                            </label>
                            <textarea
                                id="catalog-desc-ar"
                                wire:model.live.debounce.400ms="headers.description_ar"
                                @disabled(! $canManage)
                                maxlength="2000"
                                rows="2"
                                class="w-full px-3 py-2 text-sm rounded-control border border-border-control bg-surface text-text-primary focus:border-primary focus:ring-1 focus:ring-primary/20 disabled:bg-surface-soft"
                            ></textarea>
                        </div>

                        <div>
                            <label for="catalog-desc-en" class="block text-sm font-semibold text-text-secondary mb-1">
                                {{ __('catalogs.description_en') }}
                            </label>
                            <textarea
                                id="catalog-desc-en"
                                dir="ltr"
                                wire:model.live.debounce.400ms="headers.description_en"
                                @disabled(! $canManage)
                                maxlength="2000"
                                rows="2"
                                class="w-full px-3 py-2 text-sm rounded-control border border-border-control bg-surface text-text-primary focus:border-primary focus:ring-1 focus:ring-primary/20 disabled:bg-surface-soft"
                            ></textarea>
                        </div>

                        <div>
                            <label for="catalog-locale" class="block text-sm font-semibold text-text-secondary mb-1">
                                {{ __('catalogs.default_locale') }}
                            </label>
                            <select
                                id="catalog-locale"
                                wire:model.live="headers.locale"
                                @disabled(! $canManage)
                                class="w-full min-h-11 lg:min-h-9.5 px-3 py-2 text-sm rounded-control border border-border-control bg-surface text-text-primary focus:border-primary focus:ring-1 focus:ring-primary/20 disabled:bg-surface-soft"
                            >
                                <option value="ar">{{ __('catalogs.locale_ar') }}</option>
                                <option value="en">{{ __('catalogs.locale_en') }}</option>
                            </select>
                        </div>
                    </div>
                </details>

                <!-- Display Options -->
                <div class="pt-4 border-t border-border space-y-3">
                    <h3 class="text-xs font-bold text-text-secondary">{{ __('catalogs.display_options') }}</h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3">
                        <label for="catalog-show-images" class="min-h-11 lg:min-h-9.5 flex items-center gap-2.5 text-sm font-medium text-text-primary cursor-pointer">
                            <x-checkbox id="catalog-show-images" wire:model.live="headers.show_images" :disabled="! $canManage" />
                            <span>{{ __('catalogs.show_images') }}</span>
                        </label>

                        <label for="catalog-show-sku" class="min-h-11 lg:min-h-9.5 flex items-center gap-2.5 text-sm font-medium text-text-primary cursor-pointer">
                            <x-checkbox id="catalog-show-sku" wire:model.live="headers.show_sku" :disabled="! $canManage" />
                            <span>{{ __('catalogs.show_sku') }}</span>
                        </label>

                        <label for="catalog-show-description" class="min-h-11 lg:min-h-9.5 flex items-center gap-2.5 text-sm font-medium text-text-primary cursor-pointer">
                            <x-checkbox id="catalog-show-description" wire:model.live="headers.show_description" :disabled="! $canManage" />
                            <span>{{ __('catalogs.show_description') }}</span>
                        </label>

                        @if($canShowPrices)
                            <label for="catalog-show-prices" class="min-h-11 lg:min-h-9.5 flex items-center gap-2.5 text-sm font-semibold text-primary cursor-pointer">
                                <x-checkbox id="catalog-show-prices" wire:model.live="headers.show_prices" :disabled="! $canManage" />
                                <span>{{ __('catalogs.show_prices') }}</span>
                            </label>
                        @endif
                    </div>
                </div>

                @if($headers['show_prices'] && $canShowPrices)
                    <div class="p-4 rounded-card bg-surface-soft border border-border space-y-3">
                        <h3 class="text-xs font-bold text-text-primary">{{ __('catalogs.pricing_settings') }}</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label for="catalog-currency" class="block text-sm font-semibold text-text-secondary mb-1">
                                    {{ __('catalogs.currency') }} <span class="text-danger">*</span>
                                </label>
                                <select
                                    id="catalog-currency"
                                    wire:model.live="headers.currency_code"
                                    @disabled(! $canManage)
                                    class="w-full min-h-11 lg:min-h-9.5 px-3 py-2 text-sm rounded-control border border-border-control bg-surface text-text-primary focus:border-primary focus:ring-1 focus:ring-primary/20 disabled:bg-surface-soft"
                                >
                                    <option value="">-- {{ __('catalogs.select_currency') }} --</option>
                                    @foreach($currencies as $curr)
                                        <option value="{{ $curr }}">{{ $curr }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div>
                                <label for="catalog-tax-basis" class="block text-sm font-semibold text-text-secondary mb-1">
                                    {{ __('catalogs.tax_basis') }} <span class="text-danger">*</span>
                                </label>
                                <input
                                    id="catalog-tax-basis"
                                    type="text"
                                    wire:model.live.debounce.400ms="headers.tax_basis"
                                    @disabled(! $canManage)
                                    placeholder="{{ __('catalogs.tax_basis_placeholder') }}"
                                    maxlength="160"
                                    class="w-full min-h-11 lg:min-h-9.5 px-3 py-2 text-sm rounded-control border border-border-control bg-surface text-text-primary focus:border-primary focus:ring-1 focus:ring-primary/20 disabled:bg-surface-soft"
                                >
                            </div>
                        </div>
                    </div>
                @endif

                <!-- Marketing Media Notice (Contextual Disclosure) -->
                <div class="p-3.5 rounded-control bg-surface-soft border border-border text-xs text-text-secondary flex items-start gap-2.5" role="note">
                    <x-icon name="help" class="w-4 h-4 text-info shrink-0 mt-0.5" />
                    <div class="min-w-0 space-y-0.5">
                        <h3 class="font-bold text-text-primary">{{ __('catalogs.media_notice_title') }}</h3>
                        <p class="leading-relaxed">{{ __('catalogs.media_notice_text') }}</p>
                    </div>
                </div>
            </section>

            <!-- Section 2: Selected Catalog Products -->
            <section class="bg-surface rounded-card shadow-panel border border-border p-4 sm:p-5 space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-2 pb-3 border-b border-border">
                    <div class="space-y-0.5">
                        <h2 class="text-base font-bold text-text-primary">{{ __('catalogs.selected_products') }}</h2>
                        <p class="text-xs text-text-muted">
                            {{ __('catalogs.selected_count', ['count' => count($items), 'max' => 250]) }}
                        </p>
                    </div>
                </div>

                @if(empty($items))
                    <div class="text-center py-8 px-4 border-2 border-dashed border-border rounded-card bg-surface-soft/40">
                        <x-icon name="box" class="mx-auto h-9 w-9 text-text-muted" />
                        <p class="mt-2 text-sm text-text-secondary">{{ __('catalogs.no_products_selected') }}</p>
                    </div>
                @else
                    <div class="space-y-3">
                        @foreach($items as $idx => $item)
                            @php
                                $meta = $selectedProductsMeta[$item['product_id']] ?? null;
                                $units = $selectableUnitsByProduct[$item['product_id']] ?? [];
                                $images = $selectableImagesByProduct[$item['product_id']] ?? [];
                                $rowKey = $item['product_id'];
                            @endphp

                            <div wire:key="catalog-item-{{ $rowKey }}" class="p-4 rounded-card border border-border bg-surface-soft/50 space-y-3">
                                <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-3">
                                    <div class="flex items-start gap-3 min-w-0">
                                        <span class="text-xs font-bold text-text-secondary bg-surface border border-border rounded-control px-2 py-1 shrink-0">
                                            <bdi dir="ltr" class="num">#{{ $idx + 1 }}</bdi>
                                        </span>
                                        <div class="min-w-0">
                                            <div class="font-bold text-sm text-text-primary break-words">
                                                {{ $meta ? \App\Services\Sales\DocumentDescription::choose(null, $meta['name_ar'] ?? null, $meta['name_en'] ?? null, app()->getLocale()) : ('Product #' . $item['product_id']) }}
                                                @if(!empty($meta['name_ar']) && !empty($meta['name_en']))
                                                    <span class="text-xs font-normal text-text-muted">({{ app()->getLocale() === 'en' ? $meta['name_ar'] : $meta['name_en'] }})</span>
                                                @endif
                                            </div>
                                            @if(!empty($meta['sku']))
                                                <div class="text-xs font-mono text-text-muted mt-0.5">SKU: <bdi dir="ltr">{{ $meta['sku'] }}</bdi></div>
                                            @endif
                                        </div>
                                    </div>

                                    <div class="flex items-center gap-1.5 self-end sm:self-auto shrink-0">
                                        <button
                                            type="button"
                                            wire:click="moveItemUp({{ $idx }})"
                                            @disabled($idx === 0 || ! $canManage)
                                            title="{{ __('catalogs.move_up') }}"
                                            aria-label="{{ __('catalogs.move_up') }} #{{ $idx + 1 }}"
                                            wire:loading.attr="disabled" wire:target="moveItemUp"
                                            class="min-h-11 min-w-11 lg:min-h-9.5 lg:min-w-9.5 inline-flex items-center justify-center rounded-control bg-surface border border-border-control text-text-secondary hover:bg-surface-soft disabled:opacity-35 disabled:cursor-not-allowed cursor-pointer">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7" /></svg>
                                        </button>

                                        <button
                                            type="button"
                                            wire:click="moveItemDown({{ $idx }})"
                                            @disabled($idx === count($items) - 1 || ! $canManage)
                                            title="{{ __('catalogs.move_down') }}"
                                            aria-label="{{ __('catalogs.move_down') }} #{{ $idx + 1 }}"
                                            wire:loading.attr="disabled" wire:target="moveItemDown"
                                            class="min-h-11 min-w-11 lg:min-h-9.5 lg:min-w-9.5 inline-flex items-center justify-center rounded-control bg-surface border border-border-control text-text-secondary hover:bg-surface-soft disabled:opacity-35 disabled:cursor-not-allowed cursor-pointer">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" /></svg>
                                        </button>

                                        <button
                                            type="button"
                                            wire:click="removeItem({{ $idx }})"
                                            @disabled(! $canManage)
                                            title="{{ __('catalogs.remove') }}"
                                            aria-label="{{ __('catalogs.remove') }} #{{ $idx + 1 }}"
                                            wire:loading.attr="disabled" wire:target="removeItem"
                                            class="min-h-11 min-w-11 lg:min-h-9.5 lg:min-w-9.5 inline-flex items-center justify-center rounded-control bg-danger-bg border border-danger/25 text-danger hover:bg-danger-bg/80 disabled:opacity-35 disabled:cursor-not-allowed cursor-pointer">
                                            <x-icon name="trash" class="w-4 h-4" />
                                        </button>
                                    </div>
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3 pt-3 border-t border-border">
                                    <div>
                                        <label for="catalog-item-{{ $rowKey }}-unit" class="block text-xs font-semibold text-text-secondary mb-1">{{ __('catalogs.unit') }}</label>
                                        <select id="catalog-item-{{ $rowKey }}-unit"
                                            wire:model.live="items.{{ $idx }}.unit_id"
                                            @disabled(! $canManage)
                                            class="w-full min-h-11 lg:min-h-9.5 px-2.5 py-1.5 text-sm rounded-control border border-border-control bg-surface text-text-primary focus:border-primary focus:ring-1 focus:ring-primary/20">
                                            @foreach($units as $u)
                                                <option value="{{ $u['id'] }}">
                                                    {{ $u['name_ar'] }} @if($u['name_en']) ({{ $u['name_en'] }}) @endif
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>

                                    <div>
                                        <label for="catalog-item-{{ $rowKey }}-image" class="block text-xs font-semibold text-text-secondary mb-1">{{ __('catalogs.image') }}</label>
                                        <select id="catalog-item-{{ $rowKey }}-image"
                                            wire:model.live="items.{{ $idx }}.image_id"
                                            @disabled(! $canManage)
                                            class="w-full min-h-11 lg:min-h-9.5 px-2.5 py-1.5 text-sm rounded-control border border-border-control bg-surface text-text-primary focus:border-primary focus:ring-1 focus:ring-primary/20">
                                            <option value="">{{ __('catalogs.no_image') }}</option>
                                            @foreach($images as $img)
                                                <option value="{{ $img['id'] }}">
                                                    {{ __('catalogs.image') }} {{ $loop->iteration }}
                                                </option>
                                            @endforeach
                                        </select>
                                        @foreach($images as $img)
                                            @if((int) $item['image_id'] === $img['id'])
                                                <img src="{{ $img['thumbnail'] }}" alt="{{ $meta['name_'.$locale] ?? $meta['name_ar'] ?? '' }}" class="mt-2 h-16 w-16 rounded-control border border-border object-contain bg-surface" loading="lazy">
                                            @endif
                                        @endforeach
                                    </div>

                                    @if($headers['show_prices'] && $canShowPrices)
                                        <div>
                                            <label for="catalog-item-{{ $rowKey }}-price" class="block text-xs font-semibold text-text-secondary mb-1">
                                                {{ __('catalogs.custom_price') }} ({{ $headers['currency_code'] ?? '' }})
                                            </label>
                                            <input id="catalog-item-{{ $rowKey }}-price"
                                                type="text"
                                                inputmode="decimal"
                                                dir="ltr"
                                                wire:model.live.debounce.400ms="items.{{ $idx }}.custom_price"
                                                @disabled(! $canManage)
                                                placeholder="{{ __('catalogs.price_on_request_short') }}"
                                                class="w-full min-h-11 lg:min-h-9.5 px-2.5 py-1.5 rounded-control border border-border-control bg-surface text-text-primary focus:border-primary focus:ring-1 focus:ring-primary/20 font-mono text-sm num">
                                        </div>
                                    @endif

                                    <div>
                                        <label for="catalog-item-{{ $rowKey }}-name-ar" class="block text-xs font-semibold text-text-secondary mb-1">{{ __('catalogs.custom_name_ar') }}</label>
                                        <input id="catalog-item-{{ $rowKey }}-name-ar"
                                            type="text"
                                            wire:model.live.debounce.400ms="items.{{ $idx }}.name_ar"
                                            @disabled(! $canManage)
                                            placeholder="{{ __('catalogs.default_from_product') }}"
                                            class="w-full min-h-11 lg:min-h-9.5 px-2.5 py-1.5 text-sm rounded-control border border-border-control bg-surface text-text-primary focus:border-primary focus:ring-1 focus:ring-primary/20">
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>

            <!-- Section 3: Add Products Picker (Bounded Paginated) -->
            <section class="bg-surface rounded-card shadow-panel border border-border p-4 sm:p-5 space-y-4">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 pb-3 border-b border-border">
                    <h2 class="text-base font-bold text-text-primary">{{ __('catalogs.products') }}</h2>

                    <div class="w-full sm:w-72">
                        <label for="catalog-product-search" class="sr-only">{{ __('catalogs.search_products') }}</label>
                        <input id="catalog-product-search"
                            type="text"
                            wire:model.live.debounce.300ms="productSearch"
                            placeholder="{{ __('catalogs.search_products') }}"
                            class="w-full min-h-11 lg:min-h-9.5 px-3 py-2 text-sm rounded-control border border-border-control bg-surface text-text-primary focus:border-primary focus:ring-1 focus:ring-primary/20">
                    </div>
                </div>

                <!-- Single Responsive Products Picker Grid -->
                <div class="border border-border rounded-control overflow-hidden">
                    <div class="hidden sm:grid sm:grid-cols-12 gap-3 px-4 py-2.5 bg-surface-soft border-b border-border text-xs font-bold text-text-muted">
                        <div class="sm:col-span-3">SKU</div>
                        <div class="sm:col-span-6">{{ __('catalogs.products') }}</div>
                        <div class="sm:col-span-3 text-end">{{ __('catalogs.manage') }}</div>
                    </div>

                    @php
                        $currentIds = array_column($items, 'product_id');
                    @endphp

                    <div class="divide-y divide-border">
                        @forelse($availableProducts as $prod)
                            @php
                                $isAdded = in_array((int) $prod->id, $currentIds, true);
                            @endphp
                            <div wire:key="catalog-avail-{{ $prod->id }}" class="grid grid-cols-1 sm:grid-cols-12 gap-2.5 sm:gap-3 p-3.5 sm:px-4 sm:py-2.5 items-center hover:bg-surface-soft/50 transition-colors">
                                <div class="sm:col-span-3 min-w-0">
                                    <bdi dir="ltr" class="font-mono text-xs text-text-muted break-all">{{ $prod->sku ?: '—' }}</bdi>
                                </div>
                                <div class="sm:col-span-6 min-w-0 font-semibold text-sm text-text-primary break-words">
                                    {{ $prod->name_ar }}
                                    @if($prod->name_en)
                                        <span class="text-text-muted font-normal text-xs">({{ $prod->name_en }})</span>
                                    @endif
                                </div>
                                <div class="sm:col-span-3 flex justify-start sm:justify-end">
                                    <button
                                        type="button"
                                        wire:click="addProduct({{ $prod->id }})"
                                        wire:loading.attr="disabled" wire:target="addProduct"
                                        @disabled($isAdded || count($items) >= 250 || ! $canManage)
                                        class="w-full sm:w-auto min-h-11 lg:min-h-9.5 px-3.5 py-1.5 rounded-control text-xs font-bold border transition-colors cursor-pointer {{ $isAdded ? 'bg-surface-soft border-border text-text-muted cursor-not-allowed' : 'bg-primary-50 border-primary/25 text-primary hover:bg-primary-100/60' }}">
                                        {{ $isAdded ? __('catalogs.already_added') : __('catalogs.add_product') }}
                                    </button>
                                </div>
                            </div>
                        @empty
                            <div class="py-8 px-4 text-center text-sm text-text-muted">
                                {{ trim($productSearch) !== '' ? __('catalogs.no_matching_products') : __('catalogs.no_products_selected') }}
                            </div>
                        @endforelse
                    </div>
                </div>

                @if ($availableProducts->count() > 0 || $availableProducts->currentPage() > 1)
                    <div class="pt-3 border-t border-border">
                        <x-table-pagination :paginator="$availableProducts" />
                    </div>
                @endif
            </section>
        </div>
    </div>

    <!-- Modal: Share Link & Distribution -->
    @if($showShareModal)
        <x-dialog-shell :title="__('catalogs.share_title')" title-id="catalog-share-title" max-width="max-w-lg" close-action="$set('showShareModal', false)">
            @error('general')<p role="alert" class="text-sm text-danger font-semibold">{{ $message }}</p>@enderror
            @error('share')<p role="alert" class="text-sm text-danger font-semibold">{{ $message }}</p>@enderror
            @error('linkAccess')<p role="alert" class="text-sm text-danger font-semibold">{{ $message }}</p>@enderror
            @error('sharePassword')<p id="catalog-share-password-error" role="alert" class="text-sm text-danger font-semibold">{{ $message }}</p>@enderror
            @error('shareExpires')<p id="catalog-share-expires-error" role="alert" class="text-sm text-danger font-semibold">{{ $message }}</p>@enderror

            @if($editingLinkAccess)
                <div class="space-y-4">
                    <div class="flex items-center justify-between pb-2 border-b border-border gap-2">
                        <h4 class="text-sm font-bold text-text-primary">{{ __('catalogs.edit_link_access') }}</h4>
                        @if($url)
                            <button type="button" wire:click="closeLinkAccess" wire:loading.attr="disabled" wire:target="closeLinkAccess"
                                    class="min-h-11 lg:min-h-9.5 inline-flex items-center gap-1.5 text-sm text-primary hover:underline font-bold px-2 cursor-pointer">
                                <x-icon name="chevron" class="w-4 h-4 rtl:rotate-180" />
                                <span>{{ __('catalogs.back_to_link') }}</span>
                            </button>
                        @endif
                    </div>

                    <div class="p-3.5 bg-surface-soft border border-border rounded-control text-xs space-y-2 text-text-secondary">
                        <div class="font-bold text-text-primary">{{ __('catalogs.current_link_status') }}</div>
                        <div class="flex justify-between items-center gap-2">
                            <span>{{ __('catalogs.current_expiry') }}:</span>
                            <span class="font-semibold text-text-primary">
                                @if($currentExpiresAt)
                                    <bdi dir="ltr">{{ $currentExpiresAt }}</bdi> ({{ $companyTimezone }})
                                @elseif($currentExpires)
                                    <bdi dir="ltr">{{ $currentExpires }}</bdi> ({{ $companyTimezone }})
                                @else
                                    {{ __('catalogs.no_expiry') }}
                                @endif
                            </span>
                        </div>
                        @if($legacyExpiry)
                            <div class="p-2.5 rounded-control bg-warning-bg text-warning border border-warning/25 text-xs leading-relaxed">
                                {{ __('catalogs.legacy_expiry_notice', ['instant' => $currentExpiresAt ?: $currentExpires, 'timezone' => $companyTimezone]) }}
                            </div>
                        @endif
                        <div class="flex justify-between items-center gap-2">
                            <span>{{ __('catalogs.password_protection') }}:</span>
                            <span class="font-semibold {{ $hasPassword ? 'text-success' : 'text-text-secondary' }}">
                                {{ $hasPassword ? __('catalogs.status_password_protected') : __('catalogs.status_no_password') }}
                            </span>
                        </div>
                    </div>

                    <div class="p-3 bg-warning-bg border border-warning/25 rounded-control text-xs text-warning space-y-1">
                        <p>{{ __('catalogs.link_access_help_password') }}</p>
                        <p>{{ __('catalogs.link_access_help_expiry') }}</p>
                    </div>

                    <div>
                        <label for="link-access-password" class="block text-sm font-semibold text-text-secondary mb-1">
                            {{ __('catalogs.new_password_label') }}
                        </label>
                        <input id="link-access-password"
                            type="password"
                            dir="ltr"
                            wire:model="linkAccessPassword"
                            minlength="8"
                            maxlength="128"
                            autocomplete="new-password"
                            placeholder="{{ __('catalogs.password_placeholder_keep') }}"
                            aria-invalid="{{ $errors->has('linkAccessPassword') ? 'true' : 'false' }}"
                            @if ($errors->has('linkAccessPassword')) aria-describedby="link-access-password-error" @endif
                            class="w-full min-h-11 lg:min-h-9.5 px-3 py-2 text-sm rounded-control border border-border-control bg-surface text-text-primary focus:border-primary focus:ring-1 focus:ring-primary/20">
                        @error('linkAccessPassword') <p id="link-access-password-error" role="alert" class="text-xs font-semibold text-danger mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="link-access-expires" class="block text-sm font-semibold text-text-secondary mb-1">
                            {{ __('catalogs.expiry_date') }}
                        </label>
                        <input id="link-access-expires"
                            type="date"
                            dir="ltr"
                            wire:model="linkAccessExpires"
                            aria-invalid="{{ $errors->has('linkAccessExpires') ? 'true' : 'false' }}"
                            @if ($errors->has('linkAccessExpires')) aria-describedby="link-access-expires-error" @endif
                            class="w-full min-h-11 lg:min-h-9.5 px-3 py-2 text-sm rounded-control border border-border-control bg-surface text-text-primary focus:border-primary focus:ring-1 focus:ring-primary/20">
                        <p class="text-xs text-text-muted mt-1">
                            {{ __('catalogs.expiry_company_tz_hint', ['timezone' => $companyTimezone]) }}
                        </p>
                        @error('linkAccessExpires') <p id="link-access-expires-error" role="alert" class="text-xs font-semibold text-danger mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div class="flex items-center gap-2 pt-2">
                        <button
                            type="button"
                            wire:click="updateLinkAccess"
                            wire:loading.attr="disabled"
                            class="flex-1 min-h-11 lg:min-h-9.5 bg-primary hover:bg-primary-hover text-white font-bold text-sm rounded-control flex items-center justify-center transition-colors disabled:opacity-50 cursor-pointer">
                            <span wire:loading.remove wire:target="updateLinkAccess">{{ __('catalogs.save_link_access') }}</span>
                            <span wire:loading wire:target="updateLinkAccess">{{ __('catalogs.saving') }}</span>
                        </button>
                        @if($url)
                            <button
                                type="button"
                                wire:click="closeLinkAccess"
                                wire:loading.attr="disabled" wire:target="closeLinkAccess"
                                class="min-h-11 lg:min-h-9.5 px-4 py-2 bg-surface border border-border-control hover:bg-surface-soft text-text-primary rounded-control text-sm font-bold transition-colors cursor-pointer">
                                {{ __('catalogs.cancel') }}
                            </button>
                        @else
                            <button
                                type="button"
                                wire:click="$set('showShareModal', false)"
                                class="min-h-11 lg:min-h-9.5 px-4 py-2 bg-surface border border-border-control hover:bg-surface-soft text-text-primary rounded-control text-sm font-bold transition-colors cursor-pointer">
                                {{ __('catalogs.close') }}
                            </button>
                        @endif
                    </div>
                </div>
            @elseif($url && $browserShareUrl !== null && hash_equals($browserShareUrl, $url))
                <div class="space-y-4">
                    <div class="p-3 bg-surface-soft border border-border rounded-control text-xs break-all font-mono select-all text-text-primary" dir="ltr">
                        {{ $url }}
                    </div>

                    <div class="grid grid-cols-2 gap-2" x-data="{ copied: false, copyFailed: false }">
                        <button
                            type="button"
                            @click="copyFailed=false; navigator.clipboard.writeText(@js($url)).then(() => { copied=true; setTimeout(() => copied=false, 2000) }).catch(() => copyFailed=true)"
                            class="min-h-11 lg:min-h-9.5 px-3 py-2 bg-surface border border-border-control hover:bg-surface-soft text-text-primary rounded-control text-xs font-bold flex items-center justify-center gap-1.5 cursor-pointer">
                            <span x-show="!copied">{{ __('catalogs.copy_link') }}</span>
                            <span x-show="copied" role="status" class="text-success font-bold">{{ __('catalogs.link_copied') }}</span>
                        </button>

                        <button
                            type="button"
                            id="catalog-qr-trigger" wire:click="showQr"
                            wire:loading.attr="disabled" wire:target="showQr"
                            class="min-h-11 lg:min-h-9.5 px-3 py-2 bg-surface border border-border-control hover:bg-surface-soft text-text-primary rounded-control text-sm font-bold flex items-center justify-center gap-1.5 cursor-pointer">
                            {{ __('catalogs.show_qr') }}
                        </button>

                        <a
                            href="https://api.whatsapp.com/send?text={{ rawurlencode(__('catalogs.public_catalog_message') . ' ' . $browserShareUrl) }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="touch-action min-h-11 lg:min-h-9.5 px-3 py-2 bg-success-bg hover:bg-success-bg/80 text-success border border-success/25 rounded-control text-sm font-bold flex items-center justify-center gap-1.5">
                            {{ __('catalogs.share_whatsapp') }}
                        </a>

                        <a
                            href="mailto:?subject={{ rawurlencode(__('catalogs.public_catalog_message')) }}&body={{ rawurlencode($browserShareUrl) }}"
                            class="touch-action min-h-11 lg:min-h-9.5 px-3 py-2 bg-primary-50 hover:bg-primary-100/60 text-primary border border-primary/25 rounded-control text-sm font-bold flex items-center justify-center gap-1.5">
                            {{ __('catalogs.share_email') }}
                        </a>
                        <p x-show="copyFailed" role="alert" class="col-span-2 text-sm text-danger">{{ __('catalogs.unexpected_error') }}</p>
                    </div>

                    <div x-data x-show="typeof navigator.share === 'function'">
                        <button
                            type="button"
                            @click="navigator.share({title: @js(__('catalogs.public_catalog_message')), url: @js($browserShareUrl)}).catch(() => {})"
                            class="w-full min-h-11 lg:min-h-9.5 px-3 py-2 bg-primary-50 hover:bg-primary-100/60 text-primary border border-primary/25 rounded-control text-xs font-bold flex items-center justify-center gap-1.5 cursor-pointer">
                            {{ __('catalogs.native_share') }}
                        </button>
                    </div>

                    <p class="text-xs text-text-secondary">{{ __('catalogs.whatsapp_composer_notice') }}</p>

                    <div class="space-y-2 pt-2 border-t border-border">
                        <button
                            type="button"
                            wire:click="openLinkAccess"
                            wire:loading.attr="disabled" wire:target="openLinkAccess"
                            class="w-full min-h-11 lg:min-h-9.5 px-3 py-2 bg-surface hover:bg-surface-soft text-text-primary border border-border-control rounded-control text-sm font-bold flex items-center justify-center gap-1.5 transition-colors cursor-pointer">
                            {{ __('catalogs.edit_link_access') }}
                        </button>

                        <button
                            type="button"
                            id="catalog-new-link-trigger-1" wire:click="openNewLink"
                            wire:loading.attr="disabled" wire:target="openNewLink"
                            class="w-full min-h-11 lg:min-h-9.5 px-3 py-2 bg-danger-bg hover:bg-danger-bg/80 text-danger border border-danger/25 rounded-control text-sm font-bold flex items-center justify-center gap-1.5 transition-colors cursor-pointer">
                            {{ __('catalogs.issue_new_link') }}
                        </button>
                    </div>

                    <p class="text-xs text-text-muted">
                        {{ __('catalogs.recovering_existing') }}
                    </p>
                </div>
            @elseif($hasExistingShare)
                <div class="space-y-4">
                    <div class="p-3 bg-warning-bg border border-warning/25 rounded-control text-xs text-warning">
                        {{ __('catalogs.link_expired_notice') }}
                    </div>

                    <button
                        type="button"
                        wire:click="openLinkAccess"
                        wire:loading.attr="disabled" wire:target="openLinkAccess"
                        class="w-full min-h-11 lg:min-h-9.5 bg-primary hover:bg-primary-hover text-white font-bold text-sm rounded-control flex items-center justify-center transition-colors cursor-pointer">
                        {{ __('catalogs.renew_link_access') }}
                    </button>

                    <button
                        type="button"
                        id="catalog-new-link-trigger-2" wire:click="openNewLink"
                        wire:loading.attr="disabled" wire:target="openNewLink"
                        class="w-full min-h-11 lg:min-h-9.5 bg-surface hover:bg-surface-soft text-text-primary border border-border-control font-bold text-sm rounded-control flex items-center justify-center transition-colors cursor-pointer">
                        {{ __('catalogs.issue_new_link') }}
                    </button>
                </div>
            @else
                <div class="space-y-4">
                    <div class="p-3 bg-warning-bg border border-warning/25 rounded-control text-xs text-warning">
                        {{ __('catalogs.security_settings') }}
                    </div>

                    <div>
                        <label for="catalog-share-password" class="block text-sm font-semibold text-text-secondary mb-1">
                            {{ __('catalogs.password_optional') }}
                        </label>
                        <input id="catalog-share-password"
                            type="password"
                            dir="ltr"
                            wire:model="sharePassword"
                            minlength="8"
                            maxlength="128"
                            autocomplete="new-password"
                            aria-invalid="{{ $errors->has('sharePassword') ? 'true' : 'false' }}"
                            @if ($errors->has('sharePassword')) aria-describedby="catalog-share-password-error" @endif
                            class="w-full min-h-11 lg:min-h-9.5 px-3 py-2 text-sm rounded-control border border-border-control bg-surface text-text-primary">
                    </div>

                    <div>
                        <label for="catalog-share-expires" class="block text-sm font-semibold text-text-secondary mb-1">
                            {{ __('catalogs.expiry_date') }}
                        </label>
                        <input id="catalog-share-expires"
                            type="date"
                            dir="ltr"
                            wire:model="shareExpires"
                            aria-invalid="{{ $errors->has('shareExpires') ? 'true' : 'false' }}"
                            @if ($errors->has('shareExpires')) aria-describedby="catalog-share-expires-error" @endif
                            class="w-full min-h-11 lg:min-h-9.5 px-3 py-2 text-sm rounded-control border border-border-control bg-surface text-text-primary">
                        <p class="text-xs text-text-muted mt-1">
                            {{ __('catalogs.expiry_company_tz_hint', ['timezone' => $companyTimezone]) }}
                        </p>
                    </div>

                    <button
                        type="button"
                        id="catalog-share-trigger" wire:click="createOrRecoverLink"
                        wire:loading.attr="disabled" wire:target="createOrRecoverLink"
                        class="w-full min-h-11 lg:min-h-9.5 bg-primary text-white font-bold text-sm rounded-control hover:bg-primary-hover cursor-pointer">
                        {{ __('catalogs.create_link') }}
                    </button>
                </div>
            @endif
        </x-dialog-shell>
    @endif

    <!-- Modal: Issue New Public Link (Terminal Retirement Action) -->
    @if($showNewLinkModal)
        <x-dialog-shell :title="__('catalogs.new_link_title')" title-id="catalog-new-link-title" max-width="max-w-lg" close-action="closeNewLink">
            @error('newLink')<p role="alert" class="text-sm text-danger font-bold">{{ $message }}</p>@enderror

            <div class="p-3.5 rounded-control bg-danger-bg border border-danger/25 text-danger text-sm flex items-start gap-2.5">
                <x-icon name="warning" class="w-4.5 h-4.5 text-danger shrink-0 mt-0.5" />
                <div>
                    <h4 class="font-bold text-danger">{{ __('catalogs.terminal_warning_title') }}</h4>
                    <p class="mt-1 text-xs leading-relaxed">{{ __('catalogs.new_link_terminal_warning') }}</p>
                </div>
            </div>

            <div class="space-y-4">
                <div>
                    <label for="new-link-password" class="block text-sm font-semibold text-text-secondary mb-1">
                        {{ __('catalogs.password_optional') }}
                    </label>
                    <input id="new-link-password"
                        type="password"
                        dir="ltr"
                        wire:model="newLinkPassword"
                        minlength="8"
                        maxlength="128"
                        autocomplete="new-password"
                        placeholder="{{ __('catalogs.new_password_placeholder') }}"
                        aria-invalid="{{ $errors->has('newLinkPassword') ? 'true' : 'false' }}"
                        @if ($errors->has('newLinkPassword')) aria-describedby="new-link-password-error" @endif
                        class="w-full min-h-11 lg:min-h-9.5 px-3 py-2 text-sm rounded-control border border-border-control bg-surface text-text-primary focus:border-primary focus:ring-1 focus:ring-primary/20">
                    @error('newLinkPassword') <p id="new-link-password-error" role="alert" class="text-xs font-semibold text-danger mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="new-link-expires" class="block text-sm font-semibold text-text-secondary mb-1">
                        {{ __('catalogs.expiry_date') }}
                    </label>
                    <input id="new-link-expires"
                        type="date"
                        dir="ltr"
                        wire:model="newLinkExpires"
                        aria-invalid="{{ $errors->has('newLinkExpires') ? 'true' : 'false' }}"
                        @if ($errors->has('newLinkExpires')) aria-describedby="new-link-expires-error" @endif
                        class="w-full min-h-11 lg:min-h-9.5 px-3 py-2 text-sm rounded-control border border-border-control bg-surface text-text-primary focus:border-primary focus:ring-1 focus:ring-primary/20">
                    <p class="text-xs text-text-muted mt-1">
                        {{ __('catalogs.expiry_company_tz_hint', ['timezone' => $companyTimezone]) }}
                    </p>
                    @error('newLinkExpires') <p id="new-link-expires-error" role="alert" class="text-xs font-semibold text-danger mt-1">{{ $message }}</p> @enderror
                </div>

                <div class="flex items-center gap-3 pt-3 border-t border-border">
                    <button
                        type="button"
                        wire:click="issueNewLink"
                        wire:loading.attr="disabled"
                        class="flex-1 min-h-11 lg:min-h-9.5 bg-danger hover:bg-danger-hover text-white font-bold text-sm rounded-control flex items-center justify-center transition-colors disabled:opacity-50 shadow-xs cursor-pointer">
                        <span wire:loading.remove wire:target="issueNewLink">{{ __('catalogs.new_link_confirm') }}</span>
                        <span wire:loading wire:target="issueNewLink">{{ __('catalogs.saving') }}</span>
                    </button>

                    <button
                        type="button"
                        wire:click="closeNewLink"
                        wire:loading.attr="disabled" wire:target="closeNewLink"
                        class="min-h-11 lg:min-h-9.5 px-4 py-2 bg-surface border border-border-control hover:bg-surface-soft text-text-primary rounded-control text-sm font-bold transition-colors cursor-pointer">
                        {{ __('catalogs.cancel') }}
                    </button>
                </div>
            </div>
        </x-dialog-shell>
    @endif

    <!-- Modal: QR Code Display -->
    @if($showQrModal && $qr)
        <x-dialog-shell :title="__('catalogs.qr_code')" title-id="catalog-qr-title" max-width="max-w-sm" close-action="$set('showQrModal', false)">
            <div class="text-center space-y-4">
                <div class="p-4 bg-surface-soft border border-border rounded-card inline-block">
                    <img src="{{ $qr }}" alt="{{ __('catalogs.qr_code') }}" class="w-48 h-48 mx-auto" />
                </div>
                <p class="text-xs text-text-muted">{{ __('catalogs.scan_qr_instruction') }}</p>
            </div>
            <x-slot:footer>
                <button
                    type="button"
                    wire:click="$set('showQrModal', false)"
                    class="w-full min-h-11 lg:min-h-9.5 bg-surface border border-border-control hover:bg-surface-soft text-text-primary rounded-control text-sm font-bold cursor-pointer">
                    {{ __('catalogs.close') }}
                </button>
            </x-slot:footer>
        </x-dialog-shell>
    @endif

    <!-- Modal: Publication Preview -->
    @if($showPreviewModal && $previewPayload)
        <x-dialog-shell :title="__('catalogs.preview') . ' — ' . __('catalogs.revision') . ' ' . $previewRevision" title-id="catalog-preview-title" max-width="max-w-3xl" close-action="$set('showPreviewModal', false)">
            <div class="max-h-[55vh] overflow-y-auto space-y-4 pe-1">
                <div class="p-4 bg-surface-soft rounded-control border border-border space-y-1">
                    <h4 class="font-bold text-base text-text-primary">{{ $previewPayload['name_ar'] ?? '' }}</h4>
                    @if(!empty($previewPayload['name_en']))
                        <div class="text-xs text-text-muted" dir="ltr">{{ $previewPayload['name_en'] }}</div>
                    @endif
                    @if(!empty($previewPayload['description_ar']))
                        <p class="text-xs text-text-secondary mt-2">{{ $previewPayload['description_ar'] }}</p>
                    @endif
                    @if(!empty($previewPayload['currency_code']))
                        <div class="pt-1 text-xs font-semibold text-primary">
                            {{ __('catalogs.currency') }}: <bdi dir="ltr">{{ $previewPayload['currency_code'] }}</bdi> | {{ __('catalogs.tax_basis') }}: {{ $previewPayload['tax_basis'] ?? '' }}
                        </div>
                    @endif
                </div>

                <div class="border border-border rounded-control overflow-hidden">
                    <table class="data-table w-full text-xs text-start">
                        <thead class="bg-surface-soft text-text-secondary font-bold border-b border-border">
                            <tr>
                                <th class="py-2 px-3 text-start">#</th>
                                <th class="py-2 px-3 text-start">{{ __('catalogs.products') }}</th>
                                <th class="py-2 px-3 text-start">{{ __('catalogs.unit') }}</th>
                                @if(isset($previewPayload['currency_code']))
                                    <th class="py-2 px-3 text-end">{{ __('catalogs.custom_price') }}</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach($previewPayload['items'] ?? [] as $pIdx => $pItem)
                                <tr>
                                    <td class="py-2 px-3 text-text-muted"><bdi dir="ltr" class="num">{{ $pIdx + 1 }}</bdi></td>
                                    <td class="py-2 px-3 font-medium text-text-primary">
                                        @if($previewImages[$pIdx] ?? null)
                                            <img src="{{ $previewImages[$pIdx] }}" alt="{{ $pItem['name_'.$locale] ?? $pItem['name_ar'] }}" class="mb-2 h-16 w-16 rounded-control border border-border object-contain bg-surface" loading="lazy">
                                        @endif
                                        {{ $pItem['name_ar'] }}
                                        @if(!empty($pItem['name_en']))
                                            <span class="text-text-muted font-normal">({{ $pItem['name_en'] }})</span>
                                        @endif
                                        @if(!empty($pItem['sku']))
                                            <div class="font-mono text-text-muted text-xs">SKU: <bdi dir="ltr">{{ $pItem['sku'] }}</bdi></div>
                                        @endif
                                    </td>
                                    <td class="py-2 px-3 text-text-secondary">{{ $pItem['unit_ar'] }}</td>
                                    @if(isset($previewPayload['currency_code']))
                                        <td class="py-2 px-3 text-end font-bold text-text-primary">
                                            <bdi dir="ltr" class="font-mono num">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($pItem['price'] ?? __('catalogs.price_on_request_short')) }}</bdi>
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            @if($errors->has('general'))<p role="alert" class="text-sm font-semibold text-danger">{{ $errors->first('general') }}</p>@endif
            @if(isset($previewPayload['currency_code']))
                <label for="catalog-price-ack" class="flex min-h-11 items-start gap-3 rounded-control border border-warning/25 bg-warning-bg p-3 cursor-pointer">
                    <x-checkbox id="catalog-price-ack" wire:model.live="priceAcknowledged" class="mt-0.5" />
                    <span class="text-sm text-text-primary"><strong class="block">{{ __('catalogs.price_ack_title') }}</strong>{{ __('catalogs.price_ack_label') }}</span>
                </label>
            @endif

            <x-slot:footer>
                <button
                    type="button"
                    wire:click="$set('showPreviewModal', false)"
                    class="min-h-11 lg:min-h-9.5 px-4 py-2 border border-border-control bg-surface rounded-control text-sm font-bold text-text-primary hover:bg-surface-soft cursor-pointer">
                    {{ __('catalogs.close') }}
                </button>

                <button
                    type="button"
                    wire:click="publish"
                    wire:loading.attr="disabled"
                    @disabled(! $canPublish)
                    class="min-h-11 lg:min-h-9.5 px-5 py-2 bg-primary text-white rounded-control text-sm font-bold hover:bg-primary-hover shadow-xs disabled:opacity-50 cursor-pointer">
                    {{ __('catalogs.publish') }}
                </button>
            </x-slot:footer>
        </x-dialog-shell>
    @endif
</div>
