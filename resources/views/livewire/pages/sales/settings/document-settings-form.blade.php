<div class="max-w-4xl mx-auto space-y-6 px-4 py-6 sm:px-6">
    {{-- Header & Navigation --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <nav class="flex items-center gap-2 text-xs text-text-muted mb-1.5" aria-label="Breadcrumb">
                <a href="{{ route('settings.index') }}" class="hover:text-primary transition">{{ __('settings.breadcrumb_settings') ?? __('documents.back_to_settings') }}</a>
                <span class="text-slate-300">/</span>
                <span class="text-text-primary font-medium">{{ __('documents.title') }}</span>
            </nav>
            <h1 class="text-xl sm:text-2xl font-extrabold text-text-primary tracking-tight">
                {{ __('documents.title') }}
            </h1>
            <p class="text-xs sm:text-sm text-text-secondary mt-1">
                {{ __('documents.subtitle') }}
            </p>
        </div>
        <div class="flex items-center gap-3">
            <a href="{{ route('settings.index') }}"
               class="inline-flex items-center justify-center min-h-11 px-4 rounded-control border border-border bg-white text-xs sm:text-sm font-semibold text-text-secondary hover:text-text-primary hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-primary transition">
                {{ __('documents.back_to_settings') }}
            </a>
        </div>
    </div>

    {{-- Session Success Banner --}}
    @if (session()->has('success'))
        <div role="status" aria-live="polite" class="p-4 rounded-card bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm flex items-center gap-3 shadow-xs">
            <x-icon name="check-circle" class="w-5 h-5 text-emerald-600 shrink-0" />
            <span class="font-medium">{{ session('success') }}</span>
        </div>
    @endif

    {{-- System / Branding Notice --}}
    <div class="p-4 rounded-card bg-blue-50/70 border border-blue-100 text-xs text-slate-700 leading-relaxed space-y-1.5">
        <div class="flex items-start gap-2.5">
            <x-icon name="help" class="w-4 h-4 text-primary shrink-0 mt-0.5" />
            <div class="space-y-1">
                <p class="font-bold text-slate-900">{{ __('documents.branding_notice') }}</p>
                <p class="text-text-secondary">{{ __('documents.quotation_terms_notice') }}</p>
            </div>
        </div>
    </div>

    {{-- Form --}}
    <form wire:submit="save" class="space-y-6">
        {{-- Section 1: Default Document Language --}}
        <section class="bg-white border border-border rounded-card p-5 sm:p-6 shadow-xs space-y-4" aria-labelledby="default-locale-heading">
            <div class="border-b border-border pb-3">
                <h2 id="default-locale-heading" class="text-sm font-bold text-text-primary">{{ __('documents.default_locale') }}</h2>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-1" role="radiogroup" aria-labelledby="default-locale-heading">
                <label for="default_document_locale_ar" class="flex items-center gap-3 p-3.5 min-h-11 rounded-control border border-border cursor-pointer transition hover:bg-slate-50 has-checked:border-primary has-checked:bg-primary-50/40">
                    <input id="default_document_locale_ar" type="radio" name="default_document_locale" value="ar" wire:model="default_document_locale" class="text-primary focus:ring-primary h-4 w-4" />
                    <div>
                        <span class="block text-sm font-bold text-text-primary" lang="ar">{{ __('documents.locale_ar') }}</span>
                    </div>
                </label>
                <label for="default_document_locale_en" class="flex items-center gap-3 p-3.5 min-h-11 rounded-control border border-border cursor-pointer transition hover:bg-slate-50 has-checked:border-primary has-checked:bg-primary-50/40">
                    <input id="default_document_locale_en" type="radio" name="default_document_locale" value="en" wire:model="default_document_locale" class="text-primary focus:ring-primary h-4 w-4" />
                    <div>
                        <span class="block text-sm font-bold text-text-primary" lang="en">{{ __('documents.locale_en') }}</span>
                    </div>
                </label>
            </div>
            @error('default_document_locale')
                <p role="alert" aria-live="assertive" class="text-xs text-red-600 mt-1 font-medium">{{ $message }}</p>
            @enderror
        </section>

        {{-- Section 2: Display & QR Options --}}
        <section class="bg-white border border-border rounded-card p-5 sm:p-6 shadow-xs space-y-4">
            <div class="border-b border-border pb-3">
                <h2 class="text-sm font-bold text-text-primary">{{ __('documents.display_options') }}</h2>
            </div>
            <div class="space-y-4 pt-1">
                {{-- Show Logo --}}
                <label for="show_logo" class="flex items-start gap-3.5 p-3.5 rounded-control border border-border cursor-pointer transition hover:bg-slate-50 has-checked:bg-slate-50/60">
                    <input id="show_logo" type="checkbox" wire:model="show_logo" class="mt-1 text-primary focus:ring-primary rounded h-4 w-4" />
                    <div class="min-w-0">
                        <span class="block text-sm font-bold text-text-primary">{{ __('documents.show_logo') }}</span>
                        <span class="block text-xs text-text-secondary mt-0.5">{{ __('documents.show_logo_help') }}</span>
                    </div>
                </label>
                @error('show_logo')
                    <p role="alert" aria-live="assertive" class="text-xs text-red-600 font-medium">{{ $message }}</p>
                @enderror

                {{-- Logo File Upload --}}
                <div class="p-3.5 rounded-control border border-border bg-slate-50/50 space-y-2">
                    <label for="document-logo" class="block text-sm font-bold text-text-primary">{{ __('documents.logo_upload') }}</label>
                    <input id="document-logo"
                           type="file"
                           wire:model="logo"
                           accept="image/png,image/jpeg,image/webp"
                           aria-describedby="document-logo-help"
                           class="mt-1 block min-h-11 max-w-full text-xs sm:text-sm text-text-secondary file:mr-4 file:py-2 file:px-4 file:rounded-control file:border-0 file:text-xs file:font-semibold file:bg-primary file:text-white hover:file:bg-primary-hover file:cursor-pointer cursor-pointer">
                    <p id="document-logo-help" class="text-xs text-text-secondary">{{ __('documents.logo_help') }}</p>
                    <div wire:loading wire:target="logo" role="status" aria-live="polite" class="text-xs text-primary font-medium flex items-center gap-1.5 pt-1">
                        <span class="inline-block w-3.5 h-3.5 border-2 border-primary/30 border-t-primary rounded-full animate-spin"></span>
                        <span>{{ __('documents.uploading') }}</span>
                    </div>
                    @error('logo')
                        <p role="alert" aria-live="assertive" class="text-xs text-red-600 font-medium">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Show QR by default --}}
                <label for="show_qr_by_default" class="flex items-start gap-3.5 p-3.5 rounded-control border border-border cursor-pointer transition hover:bg-slate-50 has-checked:bg-slate-50/60">
                    <input id="show_qr_by_default" type="checkbox" wire:model="show_qr_by_default" class="mt-1 text-primary focus:ring-primary rounded h-4 w-4" />
                    <div class="min-w-0">
                        <span class="block text-sm font-bold text-text-primary">{{ __('documents.show_qr_by_default') }}</span>
                        <span class="block text-xs text-text-secondary mt-0.5">{{ __('documents.show_qr_by_default_help') }}</span>
                    </div>
                </label>
                @error('show_qr_by_default')
                    <p role="alert" aria-live="assertive" class="text-xs text-red-600 font-medium">{{ $message }}</p>
                @enderror

                {{-- Show product images on quotes --}}
                <label for="show_product_images_on_quotes" class="flex items-start gap-3.5 p-3.5 rounded-control border border-border cursor-pointer transition hover:bg-slate-50 has-checked:bg-slate-50/60">
                    <input id="show_product_images_on_quotes" type="checkbox" wire:model="show_product_images_on_quotes" class="mt-1 text-primary focus:ring-primary rounded h-4 w-4" />
                    <div class="min-w-0">
                        <span class="block text-sm font-bold text-text-primary">{{ __('documents.show_product_images_on_quotes') }}</span>
                        <span class="block text-xs text-text-secondary mt-0.5">{{ __('documents.show_product_images_on_quotes_help') }}</span>
                    </div>
                </label>
                @error('show_product_images_on_quotes')
                    <p role="alert" aria-live="assertive" class="text-xs text-red-600 font-medium">{{ $message }}</p>
                @enderror
            </div>
        </section>

        {{-- Section 3: Footers & Quotation Default Terms --}}
        <section class="bg-white border border-border rounded-card p-5 sm:p-6 shadow-xs space-y-5">
            <div class="border-b border-border pb-3">
                <h2 class="text-sm font-bold text-text-primary">{{ __('documents.footer_and_terms') }}</h2>
            </div>

            {{-- Invoice Footer --}}
            <div class="space-y-3">
                <p id="invoice_footer_help" class="text-xs text-text-secondary">{{ __('documents.invoice_footer_help') }}</p>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="invoice_footer_ar" class="block text-xs font-bold text-text-primary mb-1.5" lang="ar">
                            {{ __('documents.invoice_footer_ar') }}
                        </label>
                        <textarea id="invoice_footer_ar"
                                  rows="3"
                                  maxlength="2000"
                                  dir="rtl"
                                  lang="ar"
                                  wire:model="invoice_footer_ar"
                                  aria-describedby="invoice_footer_help"
                                  @error('invoice_footer_ar') aria-invalid="true" @enderror
                                  class="w-full px-3 py-2 text-xs sm:text-sm border border-border rounded-control focus:border-primary focus:ring-1 focus:ring-primary placeholder-slate-400"></textarea>
                        @error('invoice_footer_ar')
                            <p role="alert" aria-live="assertive" class="text-xs text-red-600 mt-1 font-medium">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label for="invoice_footer_en" class="block text-xs font-bold text-text-primary mb-1.5" lang="en">
                            {{ __('documents.invoice_footer_en') }}
                        </label>
                        <textarea id="invoice_footer_en"
                                  rows="3"
                                  maxlength="2000"
                                  dir="ltr"
                                  lang="en"
                                  wire:model="invoice_footer_en"
                                  aria-describedby="invoice_footer_help"
                                  @error('invoice_footer_en') aria-invalid="true" @enderror
                                  class="w-full px-3 py-2 text-xs sm:text-sm border border-border rounded-control focus:border-primary focus:ring-1 focus:ring-primary placeholder-slate-400"></textarea>
                        @error('invoice_footer_en')
                            <p role="alert" aria-live="assertive" class="text-xs text-red-600 mt-1 font-medium">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </div>

            {{-- Quotation Terms --}}
            <div class="border-t border-border pt-4 space-y-3">
                <p id="quotation_terms_help" class="text-xs text-text-secondary">{{ __('documents.quotation_terms_help') }}</p>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="quotation_terms_ar" class="block text-xs font-bold text-text-primary mb-1.5" lang="ar">
                            {{ __('documents.quotation_terms_ar') }}
                        </label>
                        <textarea id="quotation_terms_ar"
                                  rows="4"
                                  maxlength="5000"
                                  dir="rtl"
                                  lang="ar"
                                  wire:model="quotation_terms_ar"
                                  aria-describedby="quotation_terms_help"
                                  @error('quotation_terms_ar') aria-invalid="true" @enderror
                                  class="w-full px-3 py-2 text-xs sm:text-sm border border-border rounded-control focus:border-primary focus:ring-1 focus:ring-primary placeholder-slate-400"></textarea>
                        @error('quotation_terms_ar')
                            <p role="alert" aria-live="assertive" class="text-xs text-red-600 mt-1 font-medium">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label for="quotation_terms_en" class="block text-xs font-bold text-text-primary mb-1.5" lang="en">
                            {{ __('documents.quotation_terms_en') }}
                        </label>
                        <textarea id="quotation_terms_en"
                                  rows="4"
                                  maxlength="5000"
                                  dir="ltr"
                                  lang="en"
                                  wire:model="quotation_terms_en"
                                  aria-describedby="quotation_terms_help"
                                  @error('quotation_terms_en') aria-invalid="true" @enderror
                                  class="w-full px-3 py-2 text-xs sm:text-sm border border-border rounded-control focus:border-primary focus:ring-1 focus:ring-primary placeholder-slate-400"></textarea>
                        @error('quotation_terms_en')
                            <p role="alert" aria-live="assertive" class="text-xs text-red-600 mt-1 font-medium">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </div>
        </section>

        {{-- Actions Bar --}}
        <div class="flex items-center justify-end gap-3 pt-2">
            <button type="submit"
                    wire:loading.attr="disabled"
                    class="min-h-11 px-6 rounded-control bg-primary hover:bg-primary-hover text-white text-xs sm:text-sm font-bold flex items-center justify-center gap-2 shadow-xs focus-visible:outline-2 focus-visible:outline-primary transition disabled:opacity-50">
                <span wire:loading wire:target="save" class="inline-block w-4 h-4 border-2 border-white/30 border-t-white rounded-full animate-spin"></span>
                <span wire:loading.remove wire:target="save">{{ __('documents.save') }}</span>
                <span wire:loading wire:target="save">{{ __('documents.saving') }}</span>
            </button>
        </div>
    </form>
</div>
