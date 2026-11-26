<div class="py-6 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
    <!-- Header Card -->
    <div class="bg-surface shadow rounded-card border border-border p-6">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-text-primary tracking-tight">@lang('labels.title')</h1>
                <p class="mt-1 text-sm text-text-secondary">@lang('labels.subtitle')</p>
            </div>
            <div class="flex flex-wrap items-center gap-4 min-w-0">
                <!-- Preset Selection -->
                <div class="flex flex-col min-w-0 w-full sm:w-auto">
                    <label for="preset-select" class="text-xs font-semibold text-text-secondary mb-1">@lang('labels.sheet_preset')</label>
                    <select id="preset-select" wire:model.live="preset" class="w-full max-w-full h-11 min-h-[44px] rounded-control border border-border shadow-xs text-sm focus:border-primary focus:ring-primary bg-surface text-text-primary px-3 py-2">
                        <option value="a4-3x8">@lang('labels.preset_3x8')</option>
                        <option value="a4-2x7">@lang('labels.preset_2x7')</option>
                    </select>
                </div>

                <!-- Language Selection -->
                <div class="flex flex-col min-w-0 w-full sm:w-auto">
                    <label for="locale-select" class="text-xs font-semibold text-text-secondary mb-1">@lang('labels.label_language')</label>
                    <select id="locale-select" wire:model.live="locale" class="w-full max-w-full h-11 min-h-[44px] rounded-control border border-border shadow-xs text-sm focus:border-primary focus:ring-primary bg-surface text-text-primary px-3 py-2">
                        <option value="ar">@lang('labels.arabic')</option>
                        <option value="en">@lang('labels.english')</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Summary & Actions Bar -->
        <div class="mt-6 pt-4 border-t border-border flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="flex items-center gap-6 text-sm">
                <div>
                    <span class="text-text-secondary">@lang('labels.total_distinct'):</span>
                    <span class="font-bold text-text-primary mx-1"><bdi class="tabular-nums" dir="ltr">{{ $this->distinctBarcodesCount() }} / 100</bdi></span>
                </div>
                <div>
                    <span class="text-text-secondary">@lang('labels.total_labels'):</span>
                    <span class="font-bold {{ $this->totalLabelsCount() > 500 ? 'text-danger' : 'text-primary' }} mx-1">
                        <bdi class="tabular-nums" dir="ltr">{{ $this->totalLabelsCount() }} / 500</bdi>
                    </span>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <button
                    id="barcode-labels-preview-trigger"
                    type="button"
                    wire:click="preview"
                    wire:loading.attr="disabled"
                    wire:target="preview"
                    @disabled(empty($quantities))
                    class="min-h-[44px] px-5 py-2.5 bg-surface-soft hover:bg-surface-muted text-primary font-semibold text-sm rounded-control border border-border shadow-xs focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed transition cursor-pointer"
                >
                    <span wire:loading.remove wire:target="preview">@lang('labels.preview')</span>
                    <span wire:loading wire:target="preview">@lang('labels.loading')</span>
                </button>

                <button
                    type="button"
                    wire:click="downloadPdf"
                    wire:loading.attr="disabled"
                    wire:target="downloadPdf"
                    @disabled(empty($quantities))
                    class="min-h-[44px] px-5 py-2.5 bg-primary hover:bg-primary-hover text-white font-semibold text-sm rounded-control shadow-button focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed transition cursor-pointer"
                >
                    <span wire:loading.remove wire:target="downloadPdf">@lang('labels.download_pdf')</span>
                    <span wire:loading wire:target="downloadPdf">@lang('labels.loading')</span>
                </button>
            </div>
        </div>

        @foreach($errors->all() as $error)<p role="alert" aria-live="assertive" class="text-sm text-danger">{{ $error }}</p>@endforeach
        @if($errorMessage)
            <div class="mt-4 p-3 bg-danger-bg border border-danger/30 text-danger text-sm rounded-control" role="alert" aria-live="assertive">
                {{ $errorMessage }}
            </div>
        @endif
    </div>

    <!-- Main Grid: Available (Search) vs Selected (Quantities) -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
        <!-- Left/Start Column: Search & Available Barcodes (7 cols) -->
        <div class="lg:col-span-7 min-w-0 bg-surface shadow rounded-card border border-border p-6 flex flex-col">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
                <h2 class="text-lg font-semibold text-text-primary">@lang('labels.available_barcodes')</h2>
                <div class="w-full sm:w-72">
                    <label for="search-input" class="sr-only">@lang('labels.search_placeholder')</label>
                    <input
                        type="text"
                        id="search-input"
                        wire:model.live.debounce.300ms="search"
                        placeholder="@lang('labels.search_placeholder')"
                        class="w-full h-11 min-h-[44px] rounded-control border border-border shadow-xs text-sm focus:border-primary focus:ring-primary px-3 py-2"
                    />
                </div>
            </div>

            <!-- Table of Available Barcodes -->
            <div class="relative overflow-hidden flex-1">
            <div class="overflow-x-auto">
                <table class="data-table min-w-full divide-y divide-border text-sm">
                    <thead>
                        <tr class="bg-surface-soft text-text-secondary text-xs uppercase font-medium">
                            <th scope="col" class="py-3 px-3 text-start">@lang('labels.sku')</th>
                            <th scope="col" class="py-3 px-3 text-start">@lang('labels.barcode')</th>
                            <th scope="col" class="py-3 px-3 text-start">@lang('labels.unit')</th>
                            <th scope="col" class="py-3 px-3 text-end">@lang('labels.actions')</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border bg-surface">
                        @forelse($availableBarcodes as $b)
                            @php
                                $p = $b->product;
                                $u = $b->unit;
                                $isSelected = isset($quantities[$b->id]);
                            @endphp
                            <tr class="hover:bg-surface-soft transition">
                                <td class="py-3 px-3 font-medium text-text-primary">
                                    <div><bdi dir="ltr">{{ $p?->sku }}</bdi></div>
                                    <div class="text-xs text-text-secondary">{{ $locale === 'ar' ? ($p?->name_ar ?: $p?->name_en) : ($p?->name_en ?: $p?->name_ar) }}</div>
                                </td>
                                <td class="py-3 px-3">
                                    <div class="font-mono text-xs text-text-primary" dir="ltr">{{ $b->barcode }}</div>
                                    <div class="text-xs text-text-muted uppercase">{{ $b->type ?: 'standard' }}</div>
                                </td>
                                <td class="py-3 px-3 text-text-secondary">
                                    {{ $u ? ($locale === 'ar' ? ($u->name_ar ?: $u->name_en) : ($u->name_en ?: $u->name_ar)) : ($locale === 'ar' ? 'الأساسية' : 'Base') }}
                                </td>
                                <td class="table-actions py-3 px-3 text-end">
                                    @if($isSelected)
                                        <button
                                            type="button"
                                            wire:click="removeBarcode({{ $b->id }})"
                                            wire:loading.attr="disabled"
                                            class="min-h-[44px] min-w-[44px] inline-flex items-center justify-center px-3 py-1.5 text-xs font-medium text-danger hover:text-danger-hover bg-danger-bg hover:bg-danger/20 rounded-control transition disabled:opacity-50"
                                            aria-label="@lang('labels.remove') {{ $b->barcode }}"
                                        >
                                            @lang('labels.remove')
                                        </button>
                                    @else
                                        <button
                                            type="button"
                                            wire:click="selectBarcode({{ $b->id }})"
                                            wire:loading.attr="disabled"
                                            class="min-h-[44px] min-w-[44px] inline-flex items-center justify-center px-3 py-1.5 text-xs font-medium text-primary hover:text-primary-hover bg-surface-soft hover:bg-surface-muted border border-border rounded-control transition disabled:opacity-50"
                                            aria-label="@lang('labels.select') {{ $b->barcode }}"
                                        >
                                            + @lang('labels.select')
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="py-8 text-center text-text-muted text-sm">
                                    @lang('labels.empty_search')
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            </div>

            <div class="mt-4 pt-3 border-t border-border">
                <x-table-pagination :paginator="$availableBarcodes" />
            </div>
        </div>

        <!-- Right/End Column: Selected Barcodes & Quantities (5 cols) -->
        <div class="lg:col-span-5 min-w-0 bg-surface shadow rounded-card border border-border p-6 flex flex-col">
            <h2 class="text-lg font-semibold text-text-primary mb-4">@lang('labels.selected_barcodes')</h2>

            <div class="relative overflow-hidden flex-1">
            <div class="overflow-x-auto overflow-y-auto max-h-[550px]">
                <table class="data-table min-w-full divide-y divide-border text-sm">
                    <thead>
                        <tr class="bg-surface-soft text-text-secondary text-xs uppercase font-medium">
                            <th scope="col" class="py-3 px-3 text-start">@lang('labels.barcode')</th>
                            <th scope="col" class="py-3 px-3 text-center">@lang('labels.quantity')</th>
                            <th scope="col" class="py-3 px-3 text-end">@lang('labels.actions')</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border bg-surface">
                        @forelse($selectedBarcodes as $sb)
                            @php
                                $sp = $sb->product;
                                $su = $sb->unit;
                                $qty = $quantities[$sb->id] ?? 1;
                            @endphp
                            <tr class="hover:bg-surface-soft transition">
                                <td class="py-3 px-3">
                                    <div class="font-medium text-text-primary text-xs"><bdi dir="ltr">{{ $sp?->sku }}</bdi></div>
                                    <div class="font-mono text-xs text-text-primary" dir="ltr">{{ $sb->barcode }}</div>
                                    <div class="text-xs text-text-secondary">
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
                                        class="w-20 h-11 min-h-[44px] text-center rounded-control border border-border shadow-xs text-sm focus:border-primary focus:ring-primary px-2 py-1 mx-auto"
                                    />
                                </td>
                                <td class="table-actions py-3 px-3 text-end">
                                    <button
                                        type="button"
                                        wire:click="removeBarcode({{ $sb->id }})"
                                        wire:loading.attr="disabled"
                                        class="min-h-[44px] min-w-[44px] inline-flex items-center justify-center p-2 text-danger hover:text-danger-hover hover:bg-danger-bg rounded-control transition disabled:opacity-50"
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
                                <td colspan="3" class="py-8 text-center text-text-muted text-sm">
                                    @lang('labels.empty_selected')
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            </div>
        </div>
    </div>

    <!-- Preview Modal -->
    @if($showPreviewModal)
        <x-dialog-shell :title="__('labels.preview_modal_title')" title-id="preview-title" close-action="closePreview" max-width="max-w-5xl">
            <div class="bg-surface-soft rounded-control p-3 border border-border">
                <iframe
                    id="labels-preview-frame"
                    srcdoc="{{ $previewHtml }}"
                    class="w-full min-h-[350px] sm:min-h-[600px] border border-border bg-white rounded-control"
                    title="@lang('labels.preview_modal_title')"
                ></iframe>
            </div>
            <x-slot:footer>
                <x-primary-button
                    type="button"
                    onclick="document.getElementById('labels-preview-frame')?.contentWindow?.print();"
                >
                    @lang('labels.print')
                </x-primary-button>
                <x-secondary-button
                    type="button"
                    wire:click="closePreview"
                    wire:loading.attr="disabled"
                    wire:target="closePreview"
                >
                    @lang('labels.close')
                </x-secondary-button>
            </x-slot:footer>
        </x-dialog-shell>
    @endif
</div>
