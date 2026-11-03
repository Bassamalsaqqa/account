<div class="space-y-5">
    {{-- Session Success Banner --}}
    @if (session()->has('success'))
        <div role="status" aria-live="polite" class="p-3.5 rounded-card bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs sm:text-sm font-bold flex items-center gap-2.5 shadow-xs">
            <x-icon name="check" class="w-4 h-4 text-emerald-600 shrink-0" />
            <span>{{ session('success') }}</span>
        </div>
    @endif

    <!-- Master Two-Column Layout: Controls on Left, Live Simulator on Right -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">

        <!-- Controls Column -->
        <div class="lg:col-span-5 space-y-5">
            <form wire:submit="save" class="space-y-5">

                {{-- Section 1: Template Selection --}}
                <section class="bg-white border border-border rounded-xl p-4 sm:p-5 shadow-xs space-y-3.5" aria-labelledby="template-heading">
                    <div class="border-b border-border pb-2.5 flex items-center justify-between">
                        <div>
                            <h2 id="template-heading" class="text-sm font-extrabold text-text-primary">{{ __('documents.template_options') }}</h2>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5" role="radiogroup" aria-labelledby="template-heading">
                        @foreach ([
                            'classic' => ['label' => __('documents.template_classic'), 'icon' => 'file'],
                            'modern' => ['label' => __('documents.template_modern'), 'icon' => 'sparkles'],
                            'compact' => ['label' => __('documents.template_compact'), 'icon' => 'receipt'],
                        ] as $key => $opt)
                            <label for="template_{{ $key }}" class="flex items-center justify-between p-3 rounded-xl border cursor-pointer transition {{ $template === $key ? 'border-primary bg-primary/5 ring-1 ring-primary shadow-xs' : 'border-border bg-white hover:bg-surface-soft' }}">
                                <div class="flex items-center gap-2 font-bold text-sm text-text-primary">
                                    <x-icon :name="$opt['icon']" class="w-4 h-4 text-primary shrink-0" />
                                    <span>{{ $opt['label'] }}</span>
                                </div>
                                <input id="template_{{ $key }}" type="radio" name="template" value="{{ $key }}" wire:model.live="template" class="text-primary focus:ring-primary h-4 w-4" />
                            </label>
                        @endforeach
                    </div>
                    @error('template') <p role="alert" class="text-xs text-danger font-medium">{{ $message }}</p> @enderror
                </section>

                {{-- Section 2: Brand Accent Color --}}
                <section class="bg-white border border-border rounded-xl p-4 sm:p-5 shadow-xs space-y-3.5" aria-labelledby="color-heading">
                    <div class="border-b border-border pb-2.5">
                        <h2 id="color-heading" class="text-sm font-extrabold text-text-primary">{{ __('documents.accent_color') }}</h2>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        @foreach ([
                            '#255fd6' => ['name' => __('documents.color_blue'), 'class' => 'bg-[#255fd6]'],
                            '#0f766e' => ['name' => __('documents.color_teal'), 'class' => 'bg-[#0f766e]'],
                            '#1e293b' => ['name' => __('documents.color_slate'), 'class' => 'bg-[#1e293b]'],
                            '#7c3aed' => ['name' => __('documents.color_violet'), 'class' => 'bg-[#7c3aed]'],
                        ] as $hex => $color)
                            <label for="color_{{ substr($hex, 1) }}" class="flex items-center gap-2 p-2 rounded-lg border cursor-pointer transition {{ $accent_color === $hex ? 'border-primary ring-2 ring-primary/30 bg-surface-soft' : 'border-border hover:bg-surface-soft' }}">
                                <input id="color_{{ substr($hex, 1) }}" type="radio" name="accent_color" value="{{ $hex }}" wire:model.live="accent_color" class="sr-only" />
                                <span class="w-5 h-5 rounded-full {{ $color['class'] }} border border-white shadow-2xs shrink-0 flex items-center justify-center">
                                    @if ($accent_color === $hex)
                                        <span class="w-1.5 h-1.5 rounded-full bg-white"></span>
                                    @endif
                                </span>
                                <span class="text-xs font-bold text-text-secondary">{{ $color['name'] }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('accent_color') <p role="alert" class="text-xs text-danger font-medium">{{ $message }}</p> @enderror
                </section>

                {{-- Section 3: Default Language --}}
                <section class="bg-white border border-border rounded-xl p-4 sm:p-5 shadow-xs space-y-3.5" aria-labelledby="locale-heading">
                    <div class="border-b border-border pb-2.5">
                        <h2 id="locale-heading" class="text-sm font-extrabold text-text-primary">{{ __('documents.default_locale') }}</h2>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5" role="radiogroup" aria-labelledby="locale-heading">
                        <label for="locale_ar" class="flex items-center gap-2.5 p-3 rounded-lg border cursor-pointer transition {{ $default_document_locale === 'ar' ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'border-border hover:bg-surface-soft' }}">
                            <input id="locale_ar" type="radio" name="default_document_locale" value="ar" wire:model.live="default_document_locale" class="text-primary focus:ring-primary h-4 w-4" />
                            <div>
                                <span class="block text-xs font-bold text-text-primary" lang="ar">{{ __('documents.locale_ar') }}</span>
                            </div>
                        </label>
                        <label for="locale_en" class="flex items-center gap-2.5 p-3 rounded-lg border cursor-pointer transition {{ $default_document_locale === 'en' ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'border-border hover:bg-surface-soft' }}">
                            <input id="locale_en" type="radio" name="default_document_locale" value="en" wire:model.live="default_document_locale" class="text-primary focus:ring-primary h-4 w-4" />
                            <div>
                                <span class="block text-xs font-bold text-text-primary" lang="en">{{ __('documents.locale_en') }}</span>
                            </div>
                        </label>
                    </div>
                    @error('default_document_locale') <p role="alert" class="text-xs text-danger font-medium">{{ $message }}</p> @enderror
                </section>

                {{-- Section 4: Branding Logo & QR Code --}}
                <section class="bg-white border border-border rounded-xl p-4 sm:p-5 shadow-xs space-y-3.5" aria-labelledby="branding-heading">
                    <div class="border-b border-border pb-2.5">
                        <h2 id="branding-heading" class="text-sm font-extrabold text-text-primary">{{ __('documents.display_options') }}</h2>
                    </div>
                    <div class="space-y-3">
                        <label for="show_logo" class="flex items-start gap-3 p-3 rounded-lg border border-border cursor-pointer transition hover:bg-surface-soft">
                            <input id="show_logo" type="checkbox" wire:model.live="show_logo" class="mt-0.5 text-primary focus:ring-primary rounded h-4 w-4" />
                            <div class="min-w-0">
                                <span class="block text-sm font-bold text-text-primary">{{ __('documents.show_logo') }}</span>
                                <span class="block text-xs text-text-muted mt-0.5">{{ __('documents.show_logo_help') }}</span>
                            </div>
                        </label>

                        <div class="p-3 rounded-lg border border-border bg-surface-soft space-y-2">
                            <label for="document-logo" class="block text-sm font-bold text-text-primary">{{ __('documents.logo_upload') }}</label>
                            <input id="document-logo"
                                   type="file"
                                   wire:model="logo"
                                   accept="image/png,image/jpeg,image/webp"
                                   class="block w-full text-xs text-text-secondary file:mr-3 file:py-1.5 file:px-3 file:rounded-control file:border-0 file:text-xs file:font-semibold file:bg-primary file:text-white hover:file:bg-primary-hover file:cursor-pointer cursor-pointer">
                            <p class="text-xs text-text-muted">{{ __('documents.logo_help') }}</p>
                        </div>

                        <label for="show_qr_by_default" class="flex items-start gap-3 p-3 rounded-lg border border-border cursor-pointer transition hover:bg-surface-soft">
                            <input id="show_qr_by_default" type="checkbox" wire:model.live="show_qr_by_default" class="mt-0.5 text-primary focus:ring-primary rounded h-4 w-4" />
                            <div class="min-w-0">
                                <span class="block text-sm font-bold text-text-primary">{{ __('documents.show_qr_by_default') }}</span>
                                <span class="block text-xs text-text-muted mt-0.5">{{ __('documents.show_qr_by_default_help') }}</span>
                            </div>
                        </label>

                        <label for="show_product_images_on_quotes" class="flex items-start gap-3 p-3 rounded-lg border border-border cursor-pointer transition hover:bg-surface-soft">
                            <input id="show_product_images_on_quotes" type="checkbox" wire:model.live="show_product_images_on_quotes" class="mt-0.5 text-primary focus:ring-primary rounded h-4 w-4" />
                            <div class="min-w-0">
                                <span class="block text-sm font-bold text-text-primary">{{ __('documents.show_product_images_on_quotes') }}</span>
                                <span class="block text-xs text-text-muted mt-0.5">{{ __('documents.show_product_images_on_quotes_help') }}</span>
                            </div>
                        </label>
                    </div>
                </section>

                {{-- Section 5: Footers & Quotation Terms --}}
                <section class="bg-white border border-border rounded-xl p-4 sm:p-5 shadow-xs space-y-3.5" aria-labelledby="footer-heading">
                    <div class="border-b border-border pb-2.5">
                        <h2 id="footer-heading" class="text-sm font-extrabold text-text-primary">{{ __('documents.footer_and_terms') }}</h2>
                    </div>
                    <div class="space-y-3 text-sm">
                        <div>
                            <label for="invoice_footer_ar" class="block font-bold text-text-secondary mb-1 text-xs sm:text-sm">{{ __('documents.invoice_footer_ar') }}</label>
                            <textarea id="invoice_footer_ar" rows="2" wire:model.live.debounce.300ms="invoice_footer_ar" class="w-full rounded-control border border-border p-2.5 text-sm focus:border-primary focus:ring-1 focus:ring-primary" dir="rtl"></textarea>
                        </div>
                        <div>
                            <label for="invoice_footer_en" class="block font-bold text-text-secondary mb-1 text-xs sm:text-sm">{{ __('documents.invoice_footer_en') }}</label>
                            <textarea id="invoice_footer_en" rows="2" wire:model.live.debounce.300ms="invoice_footer_en" class="w-full rounded-control border border-border p-2.5 text-sm focus:border-primary focus:ring-1 focus:ring-primary" dir="ltr"></textarea>
                        </div>
                        <div>
                            <label for="quotation_terms_ar" class="block font-bold text-text-secondary mb-1 text-xs sm:text-sm">{{ __('documents.quotation_terms_ar') }}</label>
                            <textarea id="quotation_terms_ar" rows="2" wire:model.live.debounce.300ms="quotation_terms_ar" class="w-full rounded-control border border-border p-2.5 text-sm focus:border-primary focus:ring-1 focus:ring-primary" dir="rtl"></textarea>
                        </div>
                        <div>
                            <label for="quotation_terms_en" class="block font-bold text-text-secondary mb-1 text-xs sm:text-sm">{{ __('documents.quotation_terms_en') }}</label>
                            <textarea id="quotation_terms_en" rows="2" wire:model.live.debounce.300ms="quotation_terms_en" class="w-full rounded-control border border-border p-2.5 text-sm focus:border-primary focus:ring-1 focus:ring-primary" dir="ltr"></textarea>
                        </div>
                    </div>
                </section>

                {{-- Save Changes Button --}}
                <div class="pt-2 flex items-center justify-end gap-3">
                    <button type="submit" wire:loading.attr="disabled"
                            class="h-10 min-h-[40px] px-6 rounded-control bg-primary hover:bg-primary-hover text-white text-xs sm:text-sm font-bold shadow-xs transition cursor-pointer inline-flex items-center justify-center">
                        <span wire:loading.remove wire:target="save">{{ __('documents.save') }}</span>
                        <span wire:loading wire:target="save" class="flex items-center gap-1.5">
                            <span class="inline-block w-3.5 h-3.5 border-2 border-white/30 border-t-white rounded-full animate-spin"></span>
                            <span>{{ __('documents.saving') }}</span>
                        </span>
                    </button>
                </div>
            </form>
        </div>

        <!-- Live Sample Preview Simulator Column -->
        <div class="lg:col-span-7 space-y-4 lg:sticky lg:top-4"
             x-data="{
                 zoom: 'fit',
                 scale: 0.52,
                 containerW: 480,
                 containerH: 680,
                 baseW: 794,
                 baseH: 1123,
                 ro: null,
                 init() {
                     this.$nextTick(() => {
                         this.measure();
                         if (window.ResizeObserver) {
                             this.ro = new ResizeObserver(() => this.measure());
                             if (this.$refs.viewport) this.ro.observe(this.$refs.viewport);
                         }
                     });
                 },
                 destroy() {
                     if (this.ro) this.ro.disconnect();
                 },
                 measure() {
                     if (!this.$refs.viewport) return;
                     const rect = this.$refs.viewport.getBoundingClientRect();
                     this.containerW = Math.max(220, rect.width - 32);
                     // Available vertical height fitting full paper page on laptop/desktop
                     const availH = Math.max(480, Math.min(window.innerHeight - 220, 800));
                     this.containerH = availH - 32;
                     this.recalc();
                 },
                 recalc() {
                     if (this.zoom === 'fit') {
                         const sW = this.containerW / this.baseW;
                         const sH = this.containerH / this.baseH;
                         this.scale = Math.max(0.2, Math.min(sW, sH));
                     } else {
                         this.scale = parseFloat(this.zoom) || 0.52;
                     }
                 },
                 setZoom(z) {
                     this.zoom = z;
                     this.recalc();
                 },
                 zoomIn() {
                     const current = typeof this.zoom === 'number' ? this.zoom : this.scale;
                     this.setZoom(Math.min(1.25, Math.round((current + 0.1) * 10) / 10));
                 },
                 zoomOut() {
                     const current = typeof this.zoom === 'number' ? this.zoom : this.scale;
                     this.setZoom(Math.max(0.2, Math.round((current - 0.1) * 10) / 10));
                 },
                 get scaledW() {
                     return Math.round(this.baseW * this.scale);
                 },
                 get scaledH() {
                     return Math.round(this.baseH * this.scale);
                 }
             }"
             @resize.window.debounce.100ms="measure()">
            <div class="bg-white border border-border rounded-xl p-4 sm:p-5 shadow-panel space-y-3.5">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2.5 border-b border-border pb-3">
                    <div class="space-y-0.5">
                        <div class="flex items-center gap-2">
                            <h2 class="text-sm font-extrabold text-text-primary">{{ __('documents.live_preview') }}</h2>
                            <span class="text-xs px-2 py-0.5 rounded bg-surface-soft border border-border text-text-secondary font-bold">A4</span>
                        </div>
                        <p class="text-xs text-text-muted">{{ __('documents.sample_notice') }}</p>
                    </div>

                    {{-- Toolbar: Zoom Controls & Download --}}
                    <div class="flex items-center gap-2 flex-wrap">
                        <div class="inline-flex items-center rounded-control border border-border bg-surface-soft p-0.5 text-xs font-bold text-text-secondary">
                            <button type="button" @click="zoomOut()" title="{{ __('documents.zoom_out') }}" aria-label="{{ __('documents.zoom_out') }}" class="px-2.5 py-1 hover:text-text-primary hover:bg-white rounded-xs transition cursor-pointer">&minus;</button>
                            <button type="button" @click="setZoom('fit')" title="{{ __('documents.fit_page') }}" aria-label="{{ __('documents.fit_page') }}" class="px-2.5 py-1 text-xs hover:text-text-primary hover:bg-white rounded-xs transition cursor-pointer" :class="zoom === 'fit' ? 'bg-white text-primary shadow-2xs font-extrabold' : ''">
                                <span x-text="Math.round(scale * 100) + '%'"></span>
                            </button>
                            <button type="button" @click="zoomIn()" title="{{ __('documents.zoom_in') }}" aria-label="{{ __('documents.zoom_in') }}" class="px-2.5 py-1 hover:text-text-primary hover:bg-white rounded-xs transition cursor-pointer">&plus;</button>
                        </div>

                        <button type="button"
                                wire:click="downloadSamplePdf"
                                wire:loading.attr="disabled"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-control bg-surface-soft hover:bg-surface-muted border border-border text-xs font-bold text-text-primary shadow-2xs transition cursor-pointer">
                            <x-icon name="file" class="w-3.5 h-3.5 text-primary" />
                            <span wire:loading.remove wire:target="downloadSamplePdf">{{ __('documents.download_sample_pdf') }}</span>
                            <span wire:loading wire:target="downloadSamplePdf">{{ __('documents.saving') }}</span>
                        </button>
                    </div>
                </div>

                {{-- Document Type Tabs --}}
                <div class="flex items-center gap-1.5 p-1 rounded-control bg-surface-soft border border-border/50 text-xs font-bold" role="tablist" aria-label="{{ __('documents.preview_type') }}">
                    @foreach ([
                        'invoice' => __('documents.sales_invoice'),
                        'quotation' => __('documents.quotation'),
                        'customer_payment' => __('documents.customer_payment'),
                    ] as $typeKey => $typeLabel)
                        <button type="button"
                                role="tab"
                                :aria-selected="{{ $preview_type === $typeKey ? 'true' : 'false' }}"
                                wire:click="$set('preview_type', '{{ $typeKey }}')"
                                class="flex-1 py-1.5 px-2 rounded-control text-center transition cursor-pointer {{ $preview_type === $typeKey ? 'bg-white text-primary shadow-xs font-extrabold' : 'text-text-secondary hover:text-text-primary' }}">
                            {{ $typeLabel }}
                        </button>
                    @endforeach
                </div>

                {{-- Isolated Paper Sheet Viewport --}}
                <div x-ref="viewport"
                     class="bg-slate-100 rounded-xl border border-border p-3 sm:p-4 overflow-auto shadow-inner flex items-center justify-center min-h-[460px] max-h-[800px]">
                    <div class="relative bg-white rounded-xs shadow-md border border-slate-300 overflow-hidden shrink-0 transition-all duration-150"
                         :style="'width: ' + scaledW + 'px; height: ' + scaledH + 'px;'">
                        <iframe
                            x-ref="frame"
                            srcdoc="{{ $this->samplePreviewHtml }}"
                            sandbox="allow-same-origin"
                            title="{{ __('documents.live_preview') }}"
                            class="border-0 pointer-events-none select-none"
                            :style="'position: absolute; left: 0; top: 0; transform-origin: 0 0; width: ' + baseW + 'px; height: ' + baseH + 'px; transform: scale(' + scale + ');'">
                        </iframe>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
