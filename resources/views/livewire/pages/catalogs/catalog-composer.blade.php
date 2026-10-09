@php
    $locale = app()->getLocale();
    $isRtl = $locale === 'ar';
@endphp

<div class="py-6 px-4 sm:px-6 lg:px-8 max-w-7xl mx-auto" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
    <!-- Session Messages -->
    @if(session('success'))
        <div class="mb-4 p-4 rounded-md bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm flex items-center justify-between">
            <div class="flex items-center gap-2">
                <svg class="w-5 h-5 text-emerald-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
                <span>{{ session('success') }}</span>
            </div>
        </div>
    @endif

    @if($errors->any())
        <div role="alert" class="mb-4 p-4 rounded-md bg-rose-50 border border-rose-200 text-rose-800 text-sm">
            <div class="flex items-center gap-2 font-medium mb-1">
                <svg class="w-5 h-5 text-rose-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
                <span>{{ __('catalogs.unexpected_error') }}</span>
            </div>
            <ul class="list-disc list-inside text-xs space-y-1">
                @foreach($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <!-- Option A Marketing Media Notice Banner -->
    <div class="mb-6 p-4 rounded-lg bg-blue-50 border border-blue-200 text-blue-900 text-sm">
        <div class="flex items-start gap-3">
            <div class="p-1 rounded bg-blue-100 text-blue-700 shrink-0 mt-0.5">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </div>
            <div>
                <h2 class="font-semibold text-blue-950">{{ __('catalogs.media_notice_title') }}</h2>
                <p class="mt-1 text-xs sm:text-sm text-blue-800 leading-relaxed">{{ __('catalogs.media_notice_text') }}</p>
            </div>
        </div>
    </div>

    <!-- Main Header & Action Bar -->
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5 mb-6">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
            <div>
                <div class="flex items-center gap-3 flex-wrap">
                    <h1 class="text-xl sm:text-2xl font-bold text-slate-900">
                        {{ $headers['name_ar'] ?: ($headers['name_en'] ?: __('catalogs.new_catalog')) }}
                    </h1>

                    @php
                        $statusClasses = [
                            'draft' => 'bg-slate-100 text-slate-700 border-slate-200',
                            'active' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                            'paused' => 'bg-amber-50 text-amber-700 border-amber-200',
                            'revoked' => 'bg-rose-50 text-rose-700 border-rose-200',
                        ];
                    @endphp
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold border {{ $statusClasses[$status] ?? 'bg-slate-100 text-slate-700' }}">
                        {{ __('catalogs.status_' . $status) }}
                    </span>

                    @if($hasUnsavedChanges)
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-800">
                            {{ __('catalogs.unsaved_changes') }}
                        </span>
                    @endif
                </div>

                <div class="flex items-center gap-4 mt-2 text-xs text-slate-500 flex-wrap">
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
                        <span>{{ __('catalogs.published_at', ['date' => $publishedAt]) }}</span>
                    @endif
                </div>
            </div>

            <!-- Header Action Buttons -->
            <div class="flex items-center gap-2 flex-wrap">
                <!-- Save Draft -->
                <button
                    type="button"
                    wire:click="saveDraft"
                    wire:loading.attr="disabled"
                    @disabled(! $canManage)
                    class="min-h-[44px] px-4 py-2 bg-white border border-slate-300 rounded-lg text-sm font-medium text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:opacity-50 disabled:cursor-not-allowed shadow-sm transition-colors"
                >
                    <span wire:loading.remove wire:target="saveDraft">{{ __('catalogs.save_draft') }}</span>
                    <span wire:loading wire:target="saveDraft" class="flex items-center gap-1">
                        <svg class="animate-spin h-4 w-4 text-slate-600" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                        {{ __('catalogs.save_draft') }}...
                    </span>
                </button>

                <!-- Preview -->
                <button
                    type="button"
                    wire:click="previewPublication"
                    wire:loading.attr="disabled"
                    @disabled(! $canPublish || $catalogId === null || empty($items))
                    class="min-h-[44px] px-4 py-2 bg-blue-50 border border-blue-200 rounded-lg text-sm font-medium text-blue-700 hover:bg-blue-100 focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                >
                    <span wire:loading.remove wire:target="previewPublication">{{ __('catalogs.preview') }}</span>
                    <span wire:loading wire:target="previewPublication" class="flex items-center gap-1">
                        <svg class="animate-spin h-4 w-4 text-blue-600" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                        {{ __('catalogs.preview') }}...
                    </span>
                </button>

                <!-- Publish -->
                <button
                    type="button"
                    wire:click="publish"
                    wire:loading.attr="disabled"
                    @disabled(! $canPublish || $catalogId === null || $previewHash === null || $previewRevision !== $draftRevision)
                    class="min-h-[44px] px-4 py-2 bg-blue-600 rounded-lg text-sm font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:opacity-50 disabled:cursor-not-allowed shadow-sm transition-colors"
                >
                    <span wire:loading.remove wire:target="publish">{{ __('catalogs.publish') }}</span>
                    <span wire:loading wire:target="publish" class="flex items-center gap-1">
                        <svg class="animate-spin h-4 w-4 text-white" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                        {{ __('catalogs.publish') }}...
                    </span>
                </button>

                <!-- Lifecycle state buttons -->
                @if($catalogId !== null && $publishedRevision > 0)
                    @if($status === 'active')
                        <button
                            type="button"
                            wire:click="manageState('paused')"
                            @disabled(! $canRevoke)
                            class="min-h-[44px] px-3 py-2 bg-amber-50 text-amber-700 border border-amber-200 rounded-lg text-sm hover:bg-amber-100 disabled:opacity-50"
                        >
                            {{ __('catalogs.pause') }}
                        </button>
                    @elseif($status === 'paused')
                        <button
                            type="button"
                            wire:click="manageState('active')"
                            @disabled(! $canPublish)
                            class="min-h-[44px] px-3 py-2 bg-emerald-50 text-emerald-700 border border-emerald-200 rounded-lg text-sm hover:bg-emerald-100 disabled:opacity-50"
                        >
                            {{ __('catalogs.resume') }}
                        </button>
                    @endif

                    @if($status !== 'revoked')
                        <button
                            type="button"
                            wire:click="manageState('revoked')"
                            @disabled(! $canRevoke)
                            class="min-h-[44px] px-3 py-2 bg-rose-50 text-rose-700 border border-rose-200 rounded-lg text-sm hover:bg-rose-100 disabled:opacity-50"
                        >
                            {{ __('catalogs.revoke') }}
                        </button>
                    @endif
                @endif

                <!-- Share Link Button -->
                @if($catalogId !== null && $status === 'active')
                    <button
                        type="button"
                        wire:click="createOrRecoverLink"
                        @disabled(! $canShare)
                        class="min-h-[44px] px-4 py-2 bg-indigo-50 border border-indigo-200 text-indigo-700 rounded-lg text-sm font-medium hover:bg-indigo-100 focus:outline-none focus:ring-2 focus:ring-indigo-500 disabled:opacity-50 transition-colors"
                    >
                        {{ __('catalogs.share_link') }}
                    </button>
                @endif
            </div>
        </div>
    </div>

    <!-- Main Grid Layout: Sidebar Catalogs (Left/Right) + Composer Details (Center) -->
    <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
        <!-- Sidebar Catalogs List -->
        <div class="lg:col-span-1 order-2 lg:order-1">
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
                <div class="flex items-center justify-between pb-3 border-b border-slate-200 mb-3">
                    <h2 class="font-semibold text-slate-800 text-sm">{{ __('catalogs.catalogs') }}</h2>
                    <button
                        type="button"
                        wire:click="createCatalog"
                        @disabled(! $canManage)
                        class="text-xs text-blue-600 hover:text-blue-800 font-medium py-1 px-2 rounded hover:bg-blue-50 disabled:opacity-50"
                    >
                        + {{ __('catalogs.new_catalog') }}
                    </button>
                </div>

                <div class="space-y-2">
                    @forelse($sidebarCatalogs as $cat)
                        <div
                            wire:click="loadCatalog('{{ $cat->public_id }}')"
                            class="p-3 rounded-lg border text-sm cursor-pointer transition-colors {{ $publicId === $cat->public_id ? 'border-blue-500 bg-blue-50/50' : 'border-slate-200 hover:bg-slate-50' }}"
                        >
                            <div class="font-medium text-slate-900 truncate">
                                {{ $cat->name_ar ?: ($cat->name_en ?: __('catalogs.title')) }}
                            </div>
                            <div class="flex items-center justify-between text-xs text-slate-500 mt-1">
                                <span class="capitalize">{{ __('catalogs.status_' . $cat->status) }}</span>
                                <span>r{{ $cat->published_revision }}</span>
                            </div>
                        </div>
                    @empty
                        <div class="text-xs text-slate-400 py-4 text-center">
                            {{ __('catalogs.no_catalogs') }}
                        </div>
                    @endforelse
                </div>

                @if($sidebarCatalogs->hasPages())
                    <div class="mt-4 pt-3 border-t border-slate-100">
                        {{ $sidebarCatalogs->links() }}
                    </div>
                @endif
            </div>
        </div>

        <!-- Composer Main Content Area -->
        <div class="lg:col-span-3 order-1 lg:order-2 space-y-6">
            <!-- Section 1: Catalog Details -->
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
                <h2 class="text-base font-bold text-slate-900 mb-4 pb-2 border-b border-slate-100">
                    {{ __('catalogs.details') }}
                </h2>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- Arabic Name (Required) -->
                    <div>
                        <label for="catalog-name-ar" class="block text-xs font-semibold text-slate-700 mb-1">
                            {{ __('catalogs.name_ar') }} <span class="text-rose-500">*</span>
                        </label>
                        <input
                            id="catalog-name-ar"
                            type="text"
                            wire:model.live.debounce.400ms="headers.name_ar"
                            @disabled(! $canManage)
                            maxlength="160"
                            class="w-full min-h-[44px] px-3 py-2 text-sm rounded-lg border border-slate-300 focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-slate-100"
                        >
                        @error('headers.name_ar') <span class="text-xs text-rose-600 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <!-- English Name -->
                    <div>
                        <label for="catalog-name-en" class="block text-xs font-semibold text-slate-700 mb-1">
                            {{ __('catalogs.name_en') }}
                        </label>
                        <input
                            id="catalog-name-en"
                            type="text"
                            wire:model.live.debounce.400ms="headers.name_en"
                            @disabled(! $canManage)
                            maxlength="160"
                            class="w-full min-h-[44px] px-3 py-2 text-sm rounded-lg border border-slate-300 focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-slate-100"
                        >
                        @error('headers.name_en') <span class="text-xs text-rose-600 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <!-- Arabic Description -->
                    <div>
                        <label for="catalog-desc-ar" class="block text-xs font-semibold text-slate-700 mb-1">
                            {{ __('catalogs.description_ar') }}
                        </label>
                        <textarea
                            id="catalog-desc-ar"
                            wire:model.live.debounce.400ms="headers.description_ar"
                            @disabled(! $canManage)
                            maxlength="2000"
                            rows="2"
                            class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-slate-100"
                        ></textarea>
                    </div>

                    <!-- English Description -->
                    <div>
                        <label for="catalog-desc-en" class="block text-xs font-semibold text-slate-700 mb-1">
                            {{ __('catalogs.description_en') }}
                        </label>
                        <textarea
                            id="catalog-desc-en"
                            wire:model.live.debounce.400ms="headers.description_en"
                            @disabled(! $canManage)
                            maxlength="2000"
                            rows="2"
                            class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-slate-100"
                        ></textarea>
                    </div>

                    <!-- Default Locale -->
                    <div>
                        <label for="catalog-locale" class="block text-xs font-semibold text-slate-700 mb-1">
                            {{ __('catalogs.default_locale') }}
                        </label>
                        <select
                            id="catalog-locale"
                            wire:model.live="headers.locale"
                            @disabled(! $canManage)
                            class="w-full min-h-[44px] px-3 py-2 text-sm rounded-lg border border-slate-300 focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-slate-100"
                        >
                            <option value="ar">{{ __('catalogs.locale_ar') }}</option>
                            <option value="en">{{ __('catalogs.locale_en') }}</option>
                        </select>
                    </div>
                </div>

                <!-- Display Options Toggles -->
                <div class="mt-5 pt-4 border-t border-slate-100">
                    <h3 class="text-xs font-semibold text-slate-700 mb-3">{{ __('catalogs.display_options') }}</h3>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                        <label class="flex items-center gap-2 text-xs font-medium text-slate-700 cursor-pointer">
                            <input
                                type="checkbox"
                                wire:model.live="headers.show_images"
                                @disabled(! $canManage)
                                class="w-4 h-4 text-blue-600 rounded border-slate-300 focus:ring-blue-500"
                            >
                            <span>{{ __('catalogs.show_images') }}</span>
                        </label>

                        <label class="flex items-center gap-2 text-xs font-medium text-slate-700 cursor-pointer">
                            <input
                                type="checkbox"
                                wire:model.live="headers.show_sku"
                                @disabled(! $canManage)
                                class="w-4 h-4 text-blue-600 rounded border-slate-300 focus:ring-blue-500"
                            >
                            <span>{{ __('catalogs.show_sku') }}</span>
                        </label>

                        <label class="flex items-center gap-2 text-xs font-medium text-slate-700 cursor-pointer">
                            <input
                                type="checkbox"
                                wire:model.live="headers.show_description"
                                @disabled(! $canManage)
                                class="w-4 h-4 text-blue-600 rounded border-slate-300 focus:ring-blue-500"
                            >
                            <span>{{ __('catalogs.show_description') }}</span>
                        </label>

                        @if($canShowPrices)
                            <label class="flex items-center gap-2 text-xs font-medium text-slate-700 cursor-pointer">
                                <input
                                    type="checkbox"
                                    wire:model.live="headers.show_prices"
                                    @disabled(! $canManage)
                                    class="w-4 h-4 text-blue-600 rounded border-slate-300 focus:ring-blue-500"
                                >
                                <span class="font-semibold text-blue-900">{{ __('catalogs.show_prices') }}</span>
                            </label>
                        @endif
                    </div>
                </div>

                <!-- Price Configuration (Only when show_prices is checked and permitted) -->
                @if($headers['show_prices'] && $canShowPrices)
                    <div class="mt-5 p-4 rounded-lg bg-slate-50 border border-slate-200 space-y-4">
                        <h3 class="text-xs font-bold text-slate-800 uppercase tracking-wider">{{ __('catalogs.pricing_settings') }}</h3>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label for="catalog-currency" class="block text-xs font-semibold text-slate-700 mb-1">
                                    {{ __('catalogs.currency') }} <span class="text-rose-500">*</span>
                                </label>
                                <select
                                    id="catalog-currency"
                                    wire:model.live="headers.currency_code"
                                    @disabled(! $canManage)
                                    class="w-full min-h-[44px] px-3 py-2 text-sm rounded-lg border border-slate-300 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                >
                                    <option value="">-- {{ __('catalogs.select_currency') }} --</option>
                                    @foreach($currencies as $curr)
                                        <option value="{{ $curr }}">{{ $curr }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div>
                                <label for="catalog-tax-basis" class="block text-xs font-semibold text-slate-700 mb-1">
                                    {{ __('catalogs.tax_basis') }} <span class="text-rose-500">*</span>
                                </label>
                                <input
                                    id="catalog-tax-basis"
                                    type="text"
                                    wire:model.live.debounce.400ms="headers.tax_basis"
                                    @disabled(! $canManage)
                                    placeholder="{{ __('catalogs.tax_basis_placeholder') }}"
                                    maxlength="160"
                                    class="w-full min-h-[44px] px-3 py-2 text-sm rounded-lg border border-slate-300 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                >
                            </div>
                        </div>

                    </div>
                @endif
            </div>

            <!-- Section 2: Selected Catalog Products -->
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
                <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
                    <div>
                        <h2 class="text-base font-bold text-slate-900">{{ __('catalogs.selected_products') }}</h2>
                        <p class="text-xs text-slate-500">
                            {{ __('catalogs.selected_count', ['count' => count($items), 'max' => 250]) }}
                        </p>
                    </div>
                </div>

                @if(empty($items))
                    <div class="text-center py-10 px-4 border-2 border-dashed border-slate-200 rounded-xl">
                        <svg class="mx-auto h-10 w-10 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                        </svg>
                        <p class="mt-2 text-sm text-slate-500">{{ __('catalogs.no_products_selected') }}</p>
                    </div>
                @else
                    <div class="space-y-4">
                        @foreach($items as $idx => $item)
                            @php
                                $meta = $selectedProductsMeta[$item['product_id']] ?? null;
                                $units = $selectableUnitsByProduct[$item['product_id']] ?? [];
                                $images = $selectableImagesByProduct[$item['product_id']] ?? [];
                            @endphp

                            <div class="p-4 rounded-xl border border-slate-200 bg-slate-50/50 hover:bg-slate-50 transition-colors">
                                <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-3">
                                    <div class="flex items-start gap-3">
                                        <div class="text-xs font-bold text-slate-400 bg-slate-200 rounded px-2 py-1 shrink-0">
                                            #{{ $idx + 1 }}
                                        </div>
                                        <div>
                                            <div class="font-bold text-sm text-slate-900">
                                                {{ $meta['name_ar'] ?? ('Product #' . $item['product_id']) }}
                                                @if(!empty($meta['name_en']))
                                                    <span class="text-xs font-normal text-slate-500">({{ $meta['name_en'] }})</span>
                                                @endif
                                            </div>
                                            @if(!empty($meta['sku']))
                                                <div class="text-xs font-mono text-slate-500 mt-0.5">SKU: {{ $meta['sku'] }}</div>
                                            @endif
                                        </div>
                                    </div>

                                    <!-- Reorder & Remove Controls -->
                                    <div class="flex items-center gap-1 self-end sm:self-auto">
                                        <button
                                            type="button"
                                            wire:click="moveItemUp({{ $idx }})"
                                            @disabled($idx === 0 || ! $canManage)
                                            title="{{ __('catalogs.move_up') }}"
                                            class="min-h-[44px] min-w-[44px] inline-flex items-center justify-center p-2 rounded-lg bg-white border border-slate-200 text-slate-600 hover:bg-slate-100 disabled:opacity-30 disabled:cursor-not-allowed focus:outline-none focus:ring-2 focus:ring-blue-500"
                                        >
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7" /></svg>
                                        </button>

                                        <button
                                            type="button"
                                            wire:click="moveItemDown({{ $idx }})"
                                            @disabled($idx === count($items) - 1 || ! $canManage)
                                            title="{{ __('catalogs.move_down') }}"
                                            class="min-h-[44px] min-w-[44px] inline-flex items-center justify-center p-2 rounded-lg bg-white border border-slate-200 text-slate-600 hover:bg-slate-100 disabled:opacity-30 disabled:cursor-not-allowed focus:outline-none focus:ring-2 focus:ring-blue-500"
                                        >
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" /></svg>
                                        </button>

                                        <button
                                            type="button"
                                            wire:click="removeItem({{ $idx }})"
                                            @disabled(! $canManage)
                                            title="{{ __('catalogs.remove') }}"
                                            class="min-h-[44px] min-w-[44px] inline-flex items-center justify-center p-2 rounded-lg bg-rose-50 border border-rose-200 text-rose-600 hover:bg-rose-100 disabled:opacity-30 disabled:cursor-not-allowed focus:outline-none focus:ring-2 focus:ring-rose-500"
                                        >
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                                        </button>
                                    </div>
                                </div>

                                <!-- Item Configuration Grid -->
                                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3 mt-3 pt-3 border-t border-slate-200 text-xs">
                                    <!-- Unit Choice -->
                                    <div>
                                        <label class="block font-medium text-slate-600 mb-1">{{ __('catalogs.unit') }}</label>
                                        <select
                                            wire:model.live="items.{{ $idx }}.unit_id"
                                            @disabled(! $canManage)
                                            class="w-full min-h-[44px] px-2.5 py-1.5 rounded-lg border border-slate-300 bg-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                                        >
                                            @foreach($units as $u)
                                                <option value="{{ $u['id'] }}">
                                                    {{ $u['name_ar'] }} @if($u['name_en']) ({{ $u['name_en'] }}) @endif
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>

                                    <!-- Image Choice -->
                                    <div>
                                        <label class="block font-medium text-slate-600 mb-1">{{ __('catalogs.image') }}</label>
                                        <select
                                            wire:model.live="items.{{ $idx }}.image_id"
                                            @disabled(! $canManage)
                                            class="w-full min-h-[44px] px-2.5 py-1.5 rounded-lg border border-slate-300 bg-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                                        >
                                            <option value="">{{ __('catalogs.no_image') }}</option>
                                            @foreach($images as $img)
                                                <option value="{{ $img['id'] }}">
                                                    {{ __('catalogs.image') }} {{ $loop->iteration }}
                                                </option>
                                            @endforeach
                                        </select>
                                        @foreach($images as $img)
                                            @if((int) $item['image_id'] === $img['id'])
                                                <img src="{{ $img['thumbnail'] }}" alt="{{ $meta['name_'.$locale] ?? $meta['name_ar'] ?? '' }}" class="mt-2 h-20 w-20 rounded-lg object-contain" loading="lazy">
                                            @endif
                                        @endforeach
                                    </div>

                                    <!-- Custom Price (Only if show_prices is active) -->
                                    @if($headers['show_prices'] && $canShowPrices)
                                        <div>
                                            <label class="block font-medium text-slate-600 mb-1">
                                                {{ __('catalogs.custom_price') }} ({{ $headers['currency_code'] ?? '' }})
                                            </label>
                                            <input
                                                type="text"
                                                wire:model.live.debounce.400ms="items.{{ $idx }}.custom_price"
                                                @disabled(! $canManage)
                                                placeholder="{{ __('catalogs.price_on_request_short') }}"
                                                class="w-full min-h-[44px] px-2.5 py-1.5 rounded-lg border border-slate-300 bg-white focus:outline-none focus:ring-2 focus:ring-blue-500 font-mono text-xs"
                                            >
                                        </div>
                                    @endif

                                    <!-- Custom Display Name (Arabic) -->
                                    <div>
                                        <label class="block font-medium text-slate-600 mb-1">{{ __('catalogs.custom_name_ar') }}</label>
                                        <input
                                            type="text"
                                            wire:model.live.debounce.400ms="items.{{ $idx }}.name_ar"
                                            @disabled(! $canManage)
                                            placeholder="{{ __('catalogs.default_from_product') }}"
                                            class="w-full min-h-[44px] px-2.5 py-1.5 rounded-lg border border-slate-300 bg-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                                        >
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <!-- Section 3: Add Products Picker (Scoped Paginated) -->
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4 pb-3 border-b border-slate-100">
                    <h2 class="text-base font-bold text-slate-900">{{ __('catalogs.products') }}</h2>

                    <!-- Search Input -->
                    <div class="w-full sm:w-72">
                        <input
                            type="text"
                            wire:model.live.debounce.300ms="productSearch"
                            placeholder="{{ __('catalogs.search_products') }}"
                            class="w-full min-h-[44px] px-3 py-2 text-xs rounded-lg border border-slate-300 focus:outline-none focus:ring-2 focus:ring-blue-500"
                        >
                    </div>
                </div>

                <!-- Products Table / List -->
                <div class="overflow-x-auto">
                    <table class="w-full text-xs text-start">
                        <thead class="bg-slate-50 text-slate-600 uppercase font-semibold border-y border-slate-200">
                            <tr>
                                <th class="py-2.5 px-3 text-start">SKU</th>
                                <th class="py-2.5 px-3 text-start">{{ __('catalogs.products') }}</th>
                                <th class="py-2.5 px-3 text-end">{{ __('catalogs.manage') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @php
                                $currentIds = array_column($items, 'product_id');
                            @endphp

                            @forelse($availableProducts as $prod)
                                @php
                                    $isAdded = in_array((int) $prod->id, $currentIds, true);
                                @endphp
                                <tr class="hover:bg-slate-50/80 transition-colors">
                                    <td class="py-2.5 px-3 font-mono text-slate-500">{{ $prod->sku }}</td>
                                    <td class="py-2.5 px-3 font-medium text-slate-900">
                                        {{ $prod->name_ar }}
                                        @if($prod->name_en)
                                            <span class="text-slate-400 font-normal">({{ $prod->name_en }})</span>
                                        @endif
                                    </td>
                                    <td class="py-2.5 px-3 text-end">
                                        <button
                                            type="button"
                                            wire:click="addProduct({{ $prod->id }})"
                                            @disabled($isAdded || count($items) >= 250 || ! $canManage)
                                            class="min-h-[44px] px-3 py-1.5 rounded-lg text-xs font-medium transition-colors {{ $isAdded ? 'bg-slate-100 text-slate-400 cursor-not-allowed' : 'bg-blue-50 text-blue-700 hover:bg-blue-100' }}"
                                        >
                                            {{ $isAdded ? __('catalogs.already_added') : __('catalogs.add_product') }}
                                        </button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="py-6 text-center text-slate-400">
                                        {{ __('catalogs.no_products_selected') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if($availableProducts->hasPages())
                    <div class="mt-4 pt-3 border-t border-slate-100">
                        {{ $availableProducts->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>

    <!-- Modal: Share Link & Distribution -->
    @if($showShareModal)
        <div x-data x-trap.inert.noscroll="true" @keydown.escape.window="$wire.set('showShareModal', false)" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/50 backdrop-blur-sm" role="dialog" aria-modal="true" aria-labelledby="catalog-share-title">
            <div class="bg-white rounded-xl shadow-xl border border-slate-200 max-w-lg w-full p-6 text-slate-900">
                <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
                    <h3 id="catalog-share-title" class="text-base font-bold text-slate-900">{{ __('catalogs.share_title') }}</h3>
                    <button type="button" aria-label="{{ __('catalogs.close') }}" wire:click="$set('showShareModal', false)" class="min-h-[44px] min-w-[44px] text-slate-400 hover:text-slate-600 p-1">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                    </button>
                </div>

                @error('general')<p role="alert" class="mb-3 text-sm text-rose-700">{{ $message }}</p>@enderror
                @error('share')<p role="alert" class="mb-3 text-sm text-rose-700">{{ $message }}</p>@enderror
                @error('linkAccess')<p role="alert" class="mb-3 text-sm text-rose-700">{{ $message }}</p>@enderror
                @if($errors->has('sharePassword') || $errors->has('shareExpires'))
                    <p role="alert" class="mb-3 text-sm text-rose-700">{{ $errors->first('sharePassword') ?: $errors->first('shareExpires') }}</p>
                @endif

                @if($editingLinkAccess)
                    <!-- Edit Link Access Form -->
                    <div class="space-y-4">
                        <div class="flex items-center justify-between pb-2 border-b border-slate-100">
                            <h4 class="text-sm font-semibold text-slate-800">{{ __('catalogs.edit_link_access') }}</h4>
                            @if($url)
                                <button type="button" wire:click="closeLinkAccess" class="text-xs text-indigo-600 hover:text-indigo-800 font-medium min-h-[44px] px-2 flex items-center">
                                    &larr; {{ __('catalogs.back_to_link') }}
                                </button>
                            @endif
                        </div>

                        <!-- Truthful Current Status Card (no secrets) -->
                        <div class="p-3 bg-slate-50 border border-slate-200 rounded-lg text-xs space-y-1.5 text-slate-700">
                            <div class="font-semibold text-slate-800">{{ __('catalogs.current_link_status') }}</div>
                            <div class="flex justify-between items-center">
                                <span class="text-slate-500">{{ __('catalogs.current_expiry') }}:</span>
                                <span class="font-medium text-slate-800">{{ $currentExpires ?: __('catalogs.no_expiry') }}</span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-slate-500">{{ __('catalogs.password_protection') }}:</span>
                                <span class="font-medium {{ $hasPassword ? 'text-emerald-700' : 'text-slate-600' }}">
                                    {{ $hasPassword ? __('catalogs.status_password_protected') : __('catalogs.status_no_password') }}
                                </span>
                            </div>
                        </div>

                        <!-- Explanatory Notice -->
                        <div class="p-3 bg-amber-50 border border-amber-200 rounded-lg text-xs text-amber-900 space-y-1">
                            <p>{{ __('catalogs.link_access_help_password') }}</p>
                            <p>{{ __('catalogs.link_access_help_expiry') }}</p>
                        </div>

                        <!-- Password input -->
                        <div>
                            <label for="link-access-password" class="block text-xs font-semibold text-slate-700 mb-1">
                                {{ __('catalogs.new_password_label') }}
                            </label>
                            <input
                                id="link-access-password"
                                type="password"
                                wire:model="linkAccessPassword"
                                minlength="8"
                                maxlength="128"
                                placeholder="{{ __('catalogs.password_placeholder_keep') }}"
                                class="w-full min-h-[44px] px-3 py-2 text-sm rounded-lg border border-slate-300 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                            >
                            @error('linkAccessPassword') <p role="alert" class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                        </div>

                        <!-- Expiry input -->
                        <div>
                            <label for="link-access-expires" class="block text-xs font-semibold text-slate-700 mb-1">
                                {{ __('catalogs.expiry_date') }}
                            </label>
                            <input
                                id="link-access-expires"
                                type="date"
                                wire:model="linkAccessExpires"
                                class="w-full min-h-[44px] px-3 py-2 text-sm rounded-lg border border-slate-300 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                            >
                            @error('linkAccessExpires') <p role="alert" class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                        </div>

                        <!-- Action Buttons -->
                        <div class="flex items-center gap-2 pt-2">
                            <button
                                type="button"
                                wire:click="updateLinkAccess"
                                wire:loading.attr="disabled"
                                class="flex-1 min-h-[44px] bg-indigo-600 hover:bg-indigo-700 text-white font-medium text-sm rounded-lg flex items-center justify-center transition-colors disabled:opacity-50"
                            >
                                <span wire:loading.remove wire:target="updateLinkAccess">{{ __('catalogs.save_link_access') }}</span>
                                <span wire:loading wire:target="updateLinkAccess">{{ __('catalogs.saving') }}</span>
                            </button>
                            @if($url)
                                <button
                                    type="button"
                                    wire:click="closeLinkAccess"
                                    class="min-h-[44px] px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg text-sm font-medium transition-colors"
                                >
                                    {{ __('catalogs.cancel') }}
                                </button>
                            @else
                                <button
                                    type="button"
                                    wire:click="$set('showShareModal', false)"
                                    class="min-h-[44px] px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg text-sm font-medium transition-colors"
                                >
                                    {{ __('catalogs.close') }}
                                </button>
                            @endif
                        </div>
                    </div>
                @elseif($url)
                    <div class="space-y-4">
                        <div class="p-3 bg-slate-50 border border-slate-200 rounded-lg text-xs break-all font-mono select-all text-slate-800">
                            {{ $url }}
                        </div>

                        <!-- Actions Grid -->
                        <div class="grid grid-cols-2 gap-2" x-data="{ copied: false, copyFailed: false }">
                            <button
                                type="button"
                                @click="copyFailed=false; navigator.clipboard.writeText(@js($url)).then(() => { copied=true; setTimeout(() => copied=false, 2000) }).catch(() => copyFailed=true)"
                                class="min-h-[44px] px-3 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg text-xs font-medium flex items-center justify-center gap-1.5"
                            >
                                <span x-show="!copied">{{ __('catalogs.copy_link') }}</span>
                                <span x-show="copied" role="status" class="text-emerald-600 font-bold">{{ __('catalogs.link_copied') }}</span>
                            </button>

                            <button
                                type="button"
                                wire:click="showQr"
                                class="min-h-[44px] px-3 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg text-xs font-medium flex items-center justify-center gap-1.5"
                            >
                                {{ __('catalogs.show_qr') }}
                            </button>

                            <!-- WhatsApp Composition -->
                            <a
                                href="https://api.whatsapp.com/send?text={{ rawurlencode(($headers['name_ar'] ?: 'Catalog') . ' ' . $url) }}"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="min-h-[44px] px-3 py-2 bg-emerald-50 hover:bg-emerald-100 text-emerald-700 border border-emerald-200 rounded-lg text-xs font-medium flex items-center justify-center gap-1.5"
                            >
                                {{ __('catalogs.share_whatsapp') }}
                            </a>

                            <!-- Mailto Composition -->
                            <a
                                href="mailto:?subject={{ rawurlencode($headers['name_ar'] ?: 'Catalog') }}&body={{ rawurlencode($url) }}"
                                class="min-h-[44px] px-3 py-2 bg-blue-50 hover:bg-blue-100 text-blue-700 border border-blue-200 rounded-lg text-xs font-medium flex items-center justify-center gap-1.5"
                            >
                                {{ __('catalogs.share_email') }}
                            </a>
                            <p x-show="copyFailed" role="alert" class="col-span-2 text-sm text-rose-700">{{ __('catalogs.unexpected_error') }}</p>
                        </div>

                        <!-- Native Share if supported -->
                        <div x-data x-show="typeof navigator.share === 'function'">
                            <button
                                type="button"
                                @click="navigator.share({title: @js($headers['name_'.$locale] ?: $headers['name_ar']), url: @js($url)}).catch(() => {})"
                                class="w-full min-h-[44px] px-3 py-2 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 border border-indigo-200 rounded-lg text-xs font-medium flex items-center justify-center gap-1.5 mt-2"
                            >
                                {{ __('catalogs.native_share') }}
                            </button>
                        </div>

                        <!-- Edit Link Access Action -->
                        <button
                            type="button"
                            wire:click="openLinkAccess"
                            class="w-full min-h-[44px] px-3 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-300 rounded-lg text-xs font-medium flex items-center justify-center gap-1.5 mt-2 transition-colors"
                        >
                            {{ __('catalogs.edit_link_access') }}
                        </button>

                        <p class="text-xs text-slate-500 mt-2">
                            {{ __('catalogs.recovering_existing') }}
                        </p>
                    </div>
                @elseif($hasExistingShare)
                    <!-- Expired or Unavailable Link with Renewal Action -->
                    <div class="space-y-4">
                        <div class="p-3 bg-amber-50 border border-amber-200 rounded-lg text-xs text-amber-900">
                            {{ __('catalogs.link_expired_notice') }}
                        </div>

                        <button
                            type="button"
                            wire:click="openLinkAccess"
                            class="w-full min-h-[44px] bg-indigo-600 hover:bg-indigo-700 text-white font-medium text-sm rounded-lg flex items-center justify-center transition-colors"
                        >
                            {{ __('catalogs.renew_link_access') }}
                        </button>
                    </div>
                @else
                    <!-- Initial Link Creation Form -->
                    <div class="space-y-4">
                        <div class="p-3 bg-amber-50 border border-amber-200 rounded-lg text-xs text-amber-900">
                            {{ __('catalogs.security_settings') }}
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">
                                {{ __('catalogs.password_optional') }}
                            </label>
                            <input
                                type="password"
                                wire:model="sharePassword"
                                minlength="8"
                                maxlength="128"
                                class="w-full min-h-[44px] px-3 py-2 text-sm rounded-lg border border-slate-300"
                            >
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">
                                {{ __('catalogs.expiry_date') }}
                            </label>
                            <input
                                type="date"
                                wire:model="shareExpires"
                                class="w-full min-h-[44px] px-3 py-2 text-sm rounded-lg border border-slate-300"
                            >
                        </div>

                        <button
                            type="button"
                            wire:click="createOrRecoverLink"
                            class="w-full min-h-[44px] bg-blue-600 text-white font-medium text-sm rounded-lg hover:bg-blue-700"
                        >
                            {{ __('catalogs.create_link') }}
                        </button>
                    </div>
                @endif
            </div>
        </div>
    @endif

    <!-- Modal: QR Code Display -->
    @if($showQrModal && $qr)
        <div x-data x-trap.inert.noscroll="true" @keydown.escape.window="$wire.set('showQrModal', false)" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/50 backdrop-blur-sm" role="dialog" aria-modal="true" aria-labelledby="catalog-qr-title">
            <div class="bg-white rounded-xl shadow-xl border border-slate-200 max-w-sm w-full p-6 text-center">
                <div class="flex items-center justify-between pb-2 border-b border-slate-100 mb-4">
                    <h3 id="catalog-qr-title" class="text-base font-bold text-slate-900">{{ __('catalogs.qr_code') }}</h3>
                    <button type="button" aria-label="{{ __('catalogs.close') }}" wire:click="$set('showQrModal', false)" class="min-h-[44px] min-w-[44px] text-slate-400 hover:text-slate-600 p-1">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                    </button>
                </div>

                <div class="p-4 bg-slate-50 border border-slate-200 rounded-xl inline-block mb-3">
                    <img src="{{ $qr }}" alt="QR Code" class="w-48 h-48 mx-auto" />
                </div>

                <p class="text-xs text-slate-500 mb-4">{{ __('catalogs.scan_qr_instruction') }}</p>

                <button
                    type="button"
                    wire:click="$set('showQrModal', false)"
                    class="w-full min-h-[44px] bg-slate-100 text-slate-700 rounded-lg text-sm font-medium hover:bg-slate-200"
                >
                    {{ __('catalogs.close') }}
                </button>
            </div>
        </div>
    @endif

    <!-- Modal: Publication Preview -->
    @if($showPreviewModal && $previewPayload)
        <div x-data x-trap.inert.noscroll="true" @keydown.escape.window="$wire.set('showPreviewModal', false)" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/50 backdrop-blur-sm overflow-y-auto" role="dialog" aria-modal="true" aria-labelledby="catalog-preview-title">
            <div class="bg-white rounded-xl shadow-xl border border-slate-200 max-w-3xl w-full p-6 my-8">
                <div class="flex items-center justify-between pb-3 border-b border-slate-200 mb-4">
                    <div>
                        <h3 id="catalog-preview-title" class="text-lg font-bold text-slate-900">{{ __('catalogs.preview') }}</h3>
                        <p class="text-xs text-slate-500">
                            {{ __('catalogs.revision') }} {{ $previewRevision }}
                        </p>
                    </div>
                    <button type="button" aria-label="{{ __('catalogs.close') }}" wire:click="$set('showPreviewModal', false)" class="min-h-[44px] min-w-[44px] text-slate-400 hover:text-slate-600 p-1">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                    </button>
                </div>

                <div class="max-h-[60vh] overflow-y-auto space-y-4 pr-1">
                    <div class="p-4 bg-slate-50 rounded-lg border border-slate-200">
                        <h4 class="font-bold text-base text-slate-900">{{ $previewPayload['name_ar'] ?? '' }}</h4>
                        @if(!empty($previewPayload['name_en']))
                            <div class="text-xs text-slate-500">{{ $previewPayload['name_en'] }}</div>
                        @endif
                        @if(!empty($previewPayload['description_ar']))
                            <p class="text-xs text-slate-600 mt-2">{{ $previewPayload['description_ar'] }}</p>
                        @endif
                        @if(!empty($previewPayload['currency_code']))
                            <div class="mt-2 text-xs font-semibold text-blue-700">
                                {{ __('catalogs.currency') }}: {{ $previewPayload['currency_code'] }} | {{ __('catalogs.tax_basis') }}: {{ $previewPayload['tax_basis'] ?? '' }}
                            </div>
                        @endif
                    </div>

                    <div class="border border-slate-200 rounded-lg overflow-hidden">
                        <table class="w-full text-xs text-start">
                            <thead class="bg-slate-100 text-slate-700 font-semibold border-b border-slate-200">
                                <tr>
                                    <th class="py-2 px-3 text-start">#</th>
                                    <th class="py-2 px-3 text-start">{{ __('catalogs.products') }}</th>
                                    <th class="py-2 px-3 text-start">{{ __('catalogs.unit') }}</th>
                                    @if(isset($previewPayload['currency_code']))
                                        <th class="py-2 px-3 text-end">{{ __('catalogs.custom_price') }}</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach($previewPayload['items'] ?? [] as $pIdx => $pItem)
                                    <tr>
                                        <td class="py-2 px-3 text-slate-400">{{ $pIdx + 1 }}</td>
                                        <td class="py-2 px-3 font-medium text-slate-900">
                                            @if($previewImages[$pIdx] ?? null)
                                                <img src="{{ $previewImages[$pIdx] }}" alt="{{ $pItem['name_'.$locale] ?? $pItem['name_ar'] }}" class="mb-2 h-20 w-20 rounded-lg object-contain" loading="lazy">
                                            @endif
                                            {{ $pItem['name_ar'] }}
                                            @if(!empty($pItem['name_en']))
                                                <span class="text-slate-400 font-normal">({{ $pItem['name_en'] }})</span>
                                            @endif
                                            @if(!empty($pItem['sku']))
                                                <div class="font-mono text-slate-400 text-[10px]">SKU: {{ $pItem['sku'] }}</div>
                                            @endif
                                        </td>
                                        <td class="py-2 px-3 text-slate-600">{{ $pItem['unit_ar'] }}</td>
                                        @if(isset($previewPayload['currency_code']))
                                            <td class="py-2 px-3 text-end font-bold text-slate-900">
                                                {{ $pItem['price'] ?? __('catalogs.price_on_request_short') }}
                                            </td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                @if($errors->has('general'))<p role="alert" class="mt-4 text-sm text-rose-700">{{ $errors->first('general') }}</p>@endif
                @if(isset($previewPayload['currency_code']))
                    <label class="mt-4 flex min-h-[44px] items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 p-3">
                        <input type="checkbox" wire:model.live="priceAcknowledged" class="mt-0.5 h-5 w-5 rounded border-slate-300">
                        <span class="text-sm"><strong class="block">{{ __('catalogs.price_ack_title') }}</strong>{{ __('catalogs.price_ack_label') }}</span>
                    </label>
                @endif
                <div class="mt-6 pt-4 border-t border-slate-200 flex items-center justify-end gap-3">
                    <button
                        type="button"
                        wire:click="$set('showPreviewModal', false)"
                        class="min-h-[44px] px-4 py-2 border border-slate-300 rounded-lg text-sm text-slate-700 hover:bg-slate-50"
                    >
                        {{ __('catalogs.close') }}
                    </button>

                    <button
                        type="button"
                        wire:click="publish"
                        wire:loading.attr="disabled"
                        @disabled(! $canPublish)
                        class="min-h-[44px] px-5 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 shadow-sm"
                    >
                        {{ __('catalogs.publish') }}
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
