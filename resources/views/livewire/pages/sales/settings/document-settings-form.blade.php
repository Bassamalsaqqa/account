<div class="max-w-4xl mx-auto space-y-6 px-4 py-6 sm:px-6">
    <div class="rounded-card border border-border bg-white p-4">
        <label for="document-logo" class="block text-sm font-bold">{{ __('documents.logo_upload') }}</label>
        <input id="document-logo" type="file" wire:model="logo" accept="image/png,image/jpeg,image/webp" class="mt-2 block min-h-11 max-w-full text-sm">
        <p class="mt-1 text-xs text-text-secondary">{{ __('documents.logo_help') }}</p>
        @error('logo')<p role="alert" class="text-sm text-red-700">{{ $message }}</p>@enderror
    </div>
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
               class="inline-flex items-center justify-center h-10 px-4 rounded-control border border-border bg-white text-xs sm:text-sm font-semibold text-text-secondary hover:text-text-primary hover:bg-slate-50 transition">
                {{ __('documents.back_to_settings') }}
            </a>
        </div>
    </div>

    {{-- Session Success Banner --}}
    @if (session()->has('success'))
        <div role="status" class="p-4 rounded-card bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm flex items-center gap-3 shadow-xs">
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
        <section class="bg-white border border-border rounded-card p-5 sm:p-6 shadow-xs space-y-4">
            <div class="border-b border-border pb-3">
                <h2 class="text-sm font-bold text-text-primary">{{ __('documents.default_locale') }}</h2>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-1">
                <label class="flex items-center gap-3 p-3.5 rounded-control border border-border cursor-pointer transition hover:bg-slate-50 has-checked:border-primary has-checked:bg-primary-50/40">
                    <input type="radio" value="ar" wire:model="default_document_locale" class="text-primary focus:ring-primary h-4 w-4" />
                    <div>
                        <span class="block text-sm font-bold text-text-primary">{{ __('documents.locale_ar') }}</span>
                    </div>
                </label>
                <label class="flex items-center gap-3 p-3.5 rounded-control border border-border cursor-pointer transition hover:bg-slate-50 has-checked:border-primary has-checked:bg-primary-50/40">
                    <input type="radio" value="en" wire:model="default_document_locale" class="text-primary focus:ring-primary h-4 w-4" />
                    <div>
                        <span class="block text-sm font-bold text-text-primary">{{ __('documents.locale_en') }}</span>
                    </div>
                </label>
            </div>
            @error('default_document_locale')
                <p role="alert" class="text-xs text-red-600 mt-1 font-medium">{{ $message }}</p>
            @enderror
        </section>

        {{-- Section 2: Display & QR Options --}}
        <section class="bg-white border border-border rounded-card p-5 sm:p-6 shadow-xs space-y-4">
            <div class="border-b border-border pb-3">
                <h2 class="text-sm font-bold text-text-primary">{{ __('documents.display_options') }}</h2>
            </div>
            <div class="space-y-3 pt-1">
                {{-- Show Logo --}}
                <label class="flex items-start gap-3.5 p-3.5 rounded-control border border-border cursor-pointer transition hover:bg-slate-50 has-checked:bg-slate-50/60">
                    <input type="checkbox" wire:model="show_logo" class="mt-1 text-primary focus:ring-primary rounded h-4 w-4" />
                    <div class="min-w-0">
                        <span class="block text-sm font-bold text-text-primary">{{ __('documents.show_logo') }}</span>
                        <span class="block text-xs text-text-secondary mt-0.5">{{ __('documents.show_logo_help') }}</span>
                    </div>
                </label>
                @error('show_logo')
                    <p role="alert" class="text-xs text-red-600 font-medium">{{ $message }}</p>
                @enderror

                {{-- Show QR by default --}}
                <label class="flex items-start gap-3.5 p-3.5 rounded-control border border-border cursor-pointer transition hover:bg-slate-50 has-checked:bg-slate-50/60">
                    <input type="checkbox" wire:model="show_qr_by_default" class="mt-1 text-primary focus:ring-primary rounded h-4 w-4" />
                    <div class="min-w-0">
                        <span class="block text-sm font-bold text-text-primary">{{ __('documents.show_qr_by_default') }}</span>
                        <span class="block text-xs text-text-secondary mt-0.5">{{ __('documents.show_qr_by_default_help') }}</span>
                    </div>
                </label>
                @error('show_qr_by_default')
                    <p role="alert" class="text-xs text-red-600 font-medium">{{ $message }}</p>
                @enderror

                {{-- Show product images on quotes --}}
                <label class="flex items-start gap-3.5 p-3.5 rounded-control border border-border cursor-pointer transition hover:bg-slate-50 has-checked:bg-slate-50/60">
                    <input type="checkbox" wire:model="show_product_images_on_quotes" class="mt-1 text-primary focus:ring-primary rounded h-4 w-4" />
                    <div class="min-w-0">
                        <span class="block text-sm font-bold text-text-primary">{{ __('documents.show_product_images_on_quotes') }}</span>
                        <span class="block text-xs text-text-secondary mt-0.5">{{ __('documents.show_product_images_on_quotes_help') }}</span>
                    </div>
                </label>
                @error('show_product_images_on_quotes')
                    <p role="alert" class="text-xs text-red-600 font-medium">{{ $message }}</p>
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
                <p class="text-xs text-text-secondary">{{ __('documents.invoice_footer_help') }}</p>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="invoice_footer_ar" class="block text-xs font-bold text-text-primary mb-1.5">
                            {{ __('documents.invoice_footer_ar') }}
                        </label>
                        <textarea id="invoice_footer_ar"
                                  rows="3"
                                  maxlength="2000"
                                  dir="rtl"
                                  wire:model="invoice_footer_ar"
                                  class="w-full px-3 py-2 text-xs sm:text-sm border border-border rounded-control focus:border-primary focus:ring-1 focus:ring-primary placeholder-slate-400"></textarea>
                        @error('invoice_footer_ar')
                            <p role="alert" class="text-xs text-red-600 mt-1 font-medium">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label for="invoice_footer_en" class="block text-xs font-bold text-text-primary mb-1.5">
                            {{ __('documents.invoice_footer_en') }}
                        </label>
                        <textarea id="invoice_footer_en"
                                  rows="3"
                                  maxlength="2000"
                                  dir="ltr"
                                  wire:model="invoice_footer_en"
                                  class="w-full px-3 py-2 text-xs sm:text-sm border border-border rounded-control focus:border-primary focus:ring-1 focus:ring-primary placeholder-slate-400"></textarea>
                        @error('invoice_footer_en')
                            <p role="alert" class="text-xs text-red-600 mt-1 font-medium">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </div>

            {{-- Quotation Terms --}}
            <div class="border-t border-border pt-4 space-y-3">
                <p class="text-xs text-text-secondary">{{ __('documents.quotation_terms_help') }}</p>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="quotation_terms_ar" class="block text-xs font-bold text-text-primary mb-1.5">
                            {{ __('documents.quotation_terms_ar') }}
                        </label>
                        <textarea id="quotation_terms_ar"
                                  rows="4"
                                  maxlength="5000"
                                  dir="rtl"
                                  wire:model="quotation_terms_ar"
                                  class="w-full px-3 py-2 text-xs sm:text-sm border border-border rounded-control focus:border-primary focus:ring-1 focus:ring-primary placeholder-slate-400"></textarea>
                        @error('quotation_terms_ar')
                            <p role="alert" class="text-xs text-red-600 mt-1 font-medium">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label for="quotation_terms_en" class="block text-xs font-bold text-text-primary mb-1.5">
                            {{ __('documents.quotation_terms_en') }}
                        </label>
                        <textarea id="quotation_terms_en"
                                  rows="4"
                                  maxlength="5000"
                                  dir="ltr"
                                  wire:model="quotation_terms_en"
                                  class="w-full px-3 py-2 text-xs sm:text-sm border border-border rounded-control focus:border-primary focus:ring-1 focus:ring-primary placeholder-slate-400"></textarea>
                        @error('quotation_terms_en')
                            <p role="alert" class="text-xs text-red-600 mt-1 font-medium">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </div>
        </section>

        {{-- Actions Bar --}}
        <div class="flex items-center justify-end gap-3 pt-2">
            <button type="submit"
                    wire:loading.attr="disabled"
                    class="h-10 px-6 rounded-control bg-primary hover:bg-primary-hover text-white text-xs sm:text-sm font-bold flex items-center gap-2 shadow-xs transition disabled:opacity-50">
                <span wire:loading wire:target="save" class="inline-block w-4 h-4 border-2 border-white/30 border-t-white rounded-full animate-spin"></span>
                <span>{{ __('documents.save') }}</span>
            </button>
        </div>
    </form>
</div>
