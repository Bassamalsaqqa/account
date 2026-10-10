@php
    $isRtl = ($locale ?? 'ar') === 'ar';
    $dir = $isRtl ? 'rtl' : 'ltr';
@endphp

<div class="py-6 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6" dir="{{ $dir }}">
    <!-- Header Card -->
    <div class="bg-white shadow rounded-lg border border-gray-200 p-6">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-gray-900 tracking-tight">@lang('labels.title')</h1>
                <p class="mt-1 text-sm text-gray-500">@lang('labels.subtitle')</p>
            </div>
            <div class="flex flex-wrap items-center gap-4">
                <!-- Preset Selection -->
                <div class="flex flex-col">
                    <label for="preset-select" class="text-xs font-semibold text-gray-600 mb-1">@lang('labels.sheet_preset')</label>
                    <select id="preset-select" wire:model.live="preset" class="h-11 min-h-[44px] rounded-md border-gray-300 shadow-sm text-sm focus:border-blue-500 focus:ring-blue-500 bg-white px-3 py-2">
                        <option value="a4-3x8">@lang('labels.preset_3x8')</option>
                        <option value="a4-2x7">@lang('labels.preset_2x7')</option>
                    </select>
                </div>

                <!-- Language Selection -->
                <div class="flex flex-col">
                    <label for="locale-select" class="text-xs font-semibold text-gray-600 mb-1">@lang('labels.label_language')</label>
                    <select id="locale-select" wire:model.live="locale" class="h-11 min-h-[44px] rounded-md border-gray-300 shadow-sm text-sm focus:border-blue-500 focus:ring-blue-500 bg-white px-3 py-2">
                        <option value="ar">@lang('labels.arabic')</option>
                        <option value="en">@lang('labels.english')</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Summary & Actions Bar -->
        <div class="mt-6 pt-4 border-t border-gray-200 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="flex items-center gap-6 text-sm">
                <div>
                    <span class="text-gray-500">@lang('labels.total_distinct'):</span>
                    <span class="font-bold text-gray-900 mx-1"><bdi class="tabular-nums" dir="ltr">{{ $this->distinctBarcodesCount() }} / 100</bdi></span>
                </div>
                <div>
                    <span class="text-gray-500">@lang('labels.total_labels'):</span>
                    <span class="font-bold {{ $this->totalLabelsCount() > 500 ? 'text-red-600' : 'text-blue-600' }} mx-1">
                        <bdi class="tabular-nums" dir="ltr">{{ $this->totalLabelsCount() }} / 500</bdi>
                    </span>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <button
                    type="button"
                    wire:click="preview"
                    wire:loading.attr="disabled"
                    @disabled(empty($quantities))
                    class="min-h-[44px] px-5 py-2.5 bg-blue-50 hover:bg-blue-100 text-blue-700 font-medium text-sm rounded-md border border-blue-200 shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed transition"
                >
                    <span wire:loading.remove wire:target="preview">@lang('labels.preview')</span>
                    <span wire:loading wire:target="preview">@lang('labels.loading')</span>
                </button>

                <button
                    type="button"
                    wire:click="downloadPdf"
                    wire:loading.attr="disabled"
                    @disabled(empty($quantities))
                    class="min-h-[44px] px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium text-sm rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed transition"
                >
                    <span wire:loading.remove wire:target="downloadPdf">@lang('labels.download_pdf')</span>
                    <span wire:loading wire:target="downloadPdf">@lang('labels.loading')</span>
                </button>
            </div>
        </div>

        @foreach($errors->all() as $error)<p role="alert" aria-live="assertive" class="text-sm text-red-700">{{ $error }}</p>@endforeach
        @if($errorMessage)
            <div class="mt-4 p-3 bg-red-50 border border-red-200 text-red-700 text-sm rounded-md" role="alert" aria-live="assertive">
                {{ $errorMessage }}
            </div>
        @endif
    </div>

    <!-- Main Grid: Available (Search) vs Selected (Quantities) -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
        <!-- Left/Start Column: Search & Available Barcodes (7 cols) -->
        <div class="lg:col-span-7 bg-white shadow rounded-lg border border-gray-200 p-6 flex flex-col">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
                <h2 class="text-lg font-semibold text-gray-900">@lang('labels.available_barcodes')</h2>
                <div class="w-full sm:w-72">
                    <label for="search-input" class="sr-only">@lang('labels.search_placeholder')</label>
                    <input
                        type="text"
                        id="search-input"
                        wire:model.live.debounce.300ms="search"
                        placeholder="@lang('labels.search_placeholder')"
                        class="w-full h-11 min-h-[44px] rounded-md border-gray-300 shadow-sm text-sm focus:border-blue-500 focus:ring-blue-500 px-3 py-2"
                    />
                </div>
            </div>

            <!-- Table of Available Barcodes -->
            <div class="overflow-x-auto flex-1">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead>
                        <tr class="bg-gray-50 text-gray-600 text-xs uppercase font-medium">
                            <th scope="col" class="py-3 px-3 text-start">@lang('labels.sku')</th>
                            <th scope="col" class="py-3 px-3 text-start">@lang('labels.barcode')</th>
                            <th scope="col" class="py-3 px-3 text-start">@lang('labels.unit')</th>
                            <th scope="col" class="py-3 px-3 text-end">@lang('labels.actions')</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
                        @forelse($availableBarcodes as $b)
                            @php
                                $p = $b->product;
                                $u = $b->unit;
                                $isSelected = isset($quantities[$b->id]);
                            @endphp
                            <tr class="hover:bg-gray-50 transition">
                                <td class="py-3 px-3 font-medium text-gray-900">
                                    <div><bdi dir="ltr">{{ $p?->sku }}</bdi></div>
                                    <div class="text-xs text-gray-500">{{ $locale === 'ar' ? ($p?->name_ar ?: $p?->name_en) : ($p?->name_en ?: $p?->name_ar) }}</div>
                                </td>
                                <td class="py-3 px-3">
                                    <div class="font-mono text-xs text-gray-800" dir="ltr">{{ $b->barcode }}</div>
                                    <div class="text-xs text-gray-400 uppercase">{{ $b->type ?: 'standard' }}</div>
                                </td>
                                <td class="py-3 px-3 text-gray-600">
                                    {{ $u ? ($locale === 'ar' ? ($u->name_ar ?: $u->name_en) : ($u->name_en ?: $u->name_ar)) : ($locale === 'ar' ? 'الأساسية' : 'Base') }}
                                </td>
                                <td class="py-3 px-3 text-end">
                                    @if($isSelected)
                                        <button
                                            type="button"
                                            wire:click="removeBarcode({{ $b->id }})"
                                            wire:loading.attr="disabled"
                                            class="min-h-[44px] min-w-[44px] inline-flex items-center justify-center px-3 py-1.5 text-xs font-medium text-red-600 hover:text-red-800 bg-red-50 hover:bg-red-100 rounded-md transition disabled:opacity-50"
                                            aria-label="@lang('labels.remove') {{ $b->barcode }}"
                                        >
                                            @lang('labels.remove')
                                        </button>
                                    @else
                                        <button
                                            type="button"
                                            wire:click="selectBarcode({{ $b->id }})"
                                            wire:loading.attr="disabled"
                                            class="min-h-[44px] min-w-[44px] inline-flex items-center justify-center px-3 py-1.5 text-xs font-medium text-blue-700 hover:text-blue-900 bg-blue-50 hover:bg-blue-100 rounded-md transition disabled:opacity-50"
                                            aria-label="@lang('labels.select') {{ $b->barcode }}"
                                        >
                                            + @lang('labels.select')
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="py-8 text-center text-gray-400 text-sm">
                                    @lang('labels.empty_search')
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4 pt-3 border-t border-gray-100">
                {{ $availableBarcodes->links() }}
            </div>
        </div>

        <!-- Right/End Column: Selected Barcodes & Quantities (5 cols) -->
        <div class="lg:col-span-5 bg-white shadow rounded-lg border border-gray-200 p-6 flex flex-col">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">@lang('labels.selected_barcodes')</h2>

            <div class="overflow-y-auto max-h-[550px] flex-1">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead>
                        <tr class="bg-gray-50 text-gray-600 text-xs uppercase font-medium">
                            <th scope="col" class="py-3 px-3 text-start">@lang('labels.barcode')</th>
                            <th scope="col" class="py-3 px-3 text-center">@lang('labels.quantity')</th>
                            <th scope="col" class="py-3 px-3 text-end">@lang('labels.actions')</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
                        @forelse($selectedBarcodes as $sb)
                            @php
                                $sp = $sb->product;
                                $su = $sb->unit;
                                $qty = $quantities[$sb->id] ?? 1;
                            @endphp
                            <tr class="hover:bg-gray-50 transition">
                                <td class="py-3 px-3">
                                    <div class="font-medium text-gray-900 text-xs"><bdi dir="ltr">{{ $sp?->sku }}</bdi></div>
                                    <div class="font-mono text-xs text-gray-700" dir="ltr">{{ $sb->barcode }}</div>
                                    <div class="text-xs text-gray-500">
                                        {{ $su ? ($locale === 'ar' ? ($su->name_ar ?: $su->name_en) : ($su->name_en ?: $su->name_ar)) : ($locale === 'ar' ? 'الأساسية' : 'Base') }}
                                    </div>
                                </td>
                                <td class="py-3 px-3 text-center">
                                    <label for="qty-{{ $sb->id }}" class="sr-only">@lang('labels.quantity') ({{ $sb->barcode }})</label>
                                    <input
                                        type="number"
                                        id="qty-{{ $sb->id }}"
                                        min="1"
                                        max="500"
                                        inputmode="numeric"
                                        dir="ltr"
                                        value="{{ $qty }}"
                                        wire:change="updateQuantity({{ $sb->id }}, $event.target.value)"
                                        class="w-20 h-11 min-h-[44px] text-center rounded-md border-gray-300 shadow-sm text-sm focus:border-blue-500 focus:ring-blue-500 px-2 py-1 mx-auto"
                                    />
                                </td>
                                <td class="py-3 px-3 text-end">
                                    <button
                                        type="button"
                                        wire:click="removeBarcode({{ $sb->id }})"
                                        wire:loading.attr="disabled"
                                        class="min-h-[44px] min-w-[44px] inline-flex items-center justify-center p-2 text-red-600 hover:text-red-800 hover:bg-red-50 rounded-md transition disabled:opacity-50"
                                        aria-label="@lang('labels.remove') {{ $sb->barcode }}"
                                    >
                                        <svg class="w-5 h-5" aria-hidden="true" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                        </svg>
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="py-8 text-center text-gray-400 text-sm">
                                    @lang('labels.empty_selected')
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Preview Modal -->
    @if($showPreviewModal)
        <div x-data x-trap.inert.noscroll="true" @keydown.escape.window="$wire.closePreview()" class="fixed inset-0 z-50 overflow-y-auto bg-gray-900 bg-opacity-75 flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-labelledby="preview-title">
            <div class="bg-white rounded-lg shadow-xl max-w-5xl w-full max-h-[90vh] flex flex-col overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between bg-gray-50">
                    <h3 id="preview-title" class="text-lg font-semibold text-gray-900">@lang('labels.preview_modal_title')</h3>
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            onclick="document.getElementById('labels-preview-frame')?.contentWindow?.print();"
                            class="min-h-[44px] px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium text-sm rounded-md shadow-sm transition"
                        >
                            @lang('labels.print')
                        </button>
                        <button
                            type="button"
                            wire:click="closePreview"
                            class="min-h-[44px] px-4 py-2 bg-gray-200 hover:bg-gray-300 text-gray-800 font-medium text-sm rounded-md transition"
                        >
                            @lang('labels.close')
                        </button>
                    </div>
                </div>

                <div class="flex-1 overflow-auto bg-gray-100 p-4">
                    <iframe
                        id="labels-preview-frame"
                        srcdoc="{{ $previewHtml }}"
                        class="w-full min-h-[350px] sm:min-h-[600px] h-full border border-gray-300 bg-white rounded shadow-inner"
                        title="@lang('labels.preview_modal_title')"
                    ></iframe>
                </div>
            </div>
        </div>
    @endif
</div>
