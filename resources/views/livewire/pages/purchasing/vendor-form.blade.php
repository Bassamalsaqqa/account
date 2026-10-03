<div class="max-w-4xl mx-auto space-y-5">
    <header class="flex items-center justify-between gap-3">
        <h1 class="text-xl sm:text-2xl font-bold text-text-primary">{{ __($vendorId ? 'purchasing.edit_vendor' : 'purchasing.create_vendor') }}</h1>
        @if ($canView)<a href="{{ route('vendors.index') }}" class="text-sm text-primary px-3 py-3">{{ __('purchasing.back') }}</a>@endif
    </header>
    @if (session()->has('success'))<p role="status" class="p-3 rounded-control bg-success-bg text-success text-sm">{{ session('success') }}</p>@endif
    <form wire:submit="save" class="space-y-4">
        <fieldset class="p-4 sm:p-5 bg-white border border-border rounded-card">
            <legend class="px-2 text-sm font-bold">{{ __('purchasing.identity_section') }}</legend>
            <div class="grid sm:grid-cols-2 gap-4">
                <x-purchasing.field name="name_ar" :label="__('purchasing.vendor_name_ar')" required direction="rtl" />
                <x-purchasing.field name="name_en" :label="__('purchasing.vendor_name_en')" direction="ltr" />
                <x-purchasing.field name="business_name_ar" :label="__('purchasing.business_name_ar')" direction="rtl" />
                <x-purchasing.field name="business_name_en" :label="__('purchasing.business_name_en')" direction="ltr" />
                <x-purchasing.field name="code" :label="__('purchasing.vendor_code')" direction="ltr" :maxlength="64" />
                <x-purchasing.field name="tax_number" :label="__('purchasing.tax_number')" direction="ltr" :maxlength="64" />
            </div>
        </fieldset>
        <fieldset class="p-4 sm:p-5 bg-white border border-border rounded-card">
            <legend class="px-2 text-sm font-bold">{{ __('purchasing.contact_section') }}</legend>
            <div class="grid sm:grid-cols-2 gap-4">
                <x-purchasing.field name="phone" :label="__('purchasing.phone')" type="tel" direction="ltr" :maxlength="64" />
                <x-purchasing.field name="whatsapp" :label="__('purchasing.whatsapp')" type="tel" direction="ltr" :maxlength="64" />
                <x-purchasing.field name="email" :label="__('purchasing.email')" type="email" direction="ltr" />
            </div>
        </fieldset>
        <fieldset class="p-4 sm:p-5 bg-white border border-border rounded-card">
            <legend class="px-2 text-sm font-bold">{{ __('purchasing.address_section') }}</legend>
            <div class="grid sm:grid-cols-2 gap-4">
                <x-purchasing.field name="address_ar" :label="__('purchasing.address_ar')" type="textarea" direction="rtl" :maxlength="4000" />
                <x-purchasing.field name="address_en" :label="__('purchasing.address_en')" type="textarea" direction="ltr" :maxlength="4000" />
                <x-purchasing.field name="city_ar" :label="__('purchasing.city_ar')" direction="rtl" :maxlength="100" />
                <x-purchasing.field name="city_en" :label="__('purchasing.city_en')" direction="ltr" :maxlength="100" />
                <x-purchasing.field name="postal_code" :label="__('purchasing.postal_code')" direction="ltr" :maxlength="32" />
                <x-purchasing.field name="country_code" :label="__('purchasing.country_code')" direction="ltr" :maxlength="2" />
            </div>
        </fieldset>
        <fieldset class="p-4 sm:p-5 bg-white border border-border rounded-card">
            <legend class="px-2 text-sm font-bold">{{ __('purchasing.preferences_section') }}</legend>
            <div class="grid sm:grid-cols-2 gap-4">
                <div>
                    <label for="vendorLocale" class="block text-xs font-bold mb-1.5">{{ __('purchasing.preferred_locale') }}</label>
                    <select id="vendorLocale" wire:model="preferred_locale" class="w-full h-11 px-3 border border-border rounded-control text-sm">
                        <option value="">{{ __('purchasing.company_default') }}</option>
                        @foreach ($locales as $language)<option value="{{ $language->locale }}">{{ __('purchasing.language_' . $language->locale) }}</option>@endforeach
                    </select>
                    @error('preferred_locale')<p role="alert" class="text-xs text-danger">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="vendorCurrency" class="block text-xs font-bold mb-1.5">{{ __('purchasing.default_currency') }}</label>
                    <select id="vendorCurrency" wire:model="default_currency_code" class="w-full h-11 px-3 border border-border rounded-control text-sm">
                        <option value="">{{ __('purchasing.company_default') }}</option>
                        @foreach ($currencies as $currency)<option value="{{ $currency->currency_code }}">{{ $currency->currency_code }}</option>@endforeach
                    </select>
                    @error('default_currency_code')<p role="alert" class="text-xs text-danger">{{ $message }}</p>@enderror
                </div>
                <div class="sm:col-span-2"><x-purchasing.field name="notes" :label="__('purchasing.notes')" type="textarea" :maxlength="4000" /></div>
            </div>
            <label class="inline-flex items-center gap-2 text-sm mt-3 min-h-11"><input type="checkbox" wire:model="active" class="rounded-control border-border" />{{ __('purchasing.active') }}</label>
            @error('active')<p class="text-xs text-danger">{{ $message }}</p>@enderror
        </fieldset>
        <div class="flex justify-end">
            <button type="submit" wire:loading.attr="disabled" class="h-11 px-5 rounded-control bg-primary text-white text-sm font-bold hover:bg-primary-hover disabled:opacity-50">{{ __('purchasing.save_vendor') }}</button>
        </div>
    </form>
</div>
