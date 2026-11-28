@php
    $isSet = static fn ($v): bool => $v !== null && trim((string) $v) !== '';
    $hasAdvanced = $errors->hasAny([
        'code', 'email', 'whatsapp', 'preferred_locale', 'default_currency_code', 'active', 'business_name_ar', 'business_name_en', 'tax_number',
        'address_ar', 'address_en', 'city_ar', 'city_en', 'postal_code', 'country_code', 'notes',
    ]) || (
        $isSet($business_name_ar) || $isSet($business_name_en) || $isSet($tax_number) ||
        $isSet($address_ar) || $isSet($address_en) || $isSet($city_ar) ||
        $isSet($city_en) || $isSet($postal_code) || ($isSet($country_code) && $country_code !== 'PS') || $isSet($notes)
    );
@endphp

<div data-entry-form class="max-w-7xl mx-auto space-y-5" x-data="{ showAdvanced: {{ ($vendorId || $hasAdvanced) ? 'true' : 'false' }} }">
    <header class="flex items-center justify-between gap-3">
        <h1 class="text-xl sm:text-2xl font-bold text-text-primary">{{ __($vendorId ? 'purchasing.edit_vendor' : 'purchasing.create_vendor') }}</h1>
        @if ($canView)<a href="{{ route('vendors.index') }}" class="min-h-11 lg:min-h-9.5 inline-flex items-center px-3 rounded-control border border-border-control bg-surface text-sm font-semibold text-text-secondary hover:bg-surface-soft hover:text-text-primary transition-colors">{{ __('purchasing.back') }}</a>@endif
    </header>
    @if (session()->has('success'))<p role="status" class="p-3 rounded-control bg-success-bg text-success text-sm">{{ session('success') }}</p>@endif
    <form wire:submit="save"
          novalidate
          class="space-y-4">
        <fieldset class="p-4 sm:p-5 bg-surface border border-border rounded-card">
            <legend class="px-2 text-sm font-bold">{{ __('purchasing.identity_section') }}</legend>
            <p class="text-xs text-text-secondary mb-3">{{ __('purchasing.at_least_one_name_required') }}</p>
            <div class="entry-grid grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                <x-purchasing.field name="name_ar" :label="__('purchasing.vendor_name_ar')" direction="rtl" />
                <x-purchasing.field name="name_en" :label="__('purchasing.vendor_name_en')" direction="ltr" />
                <x-purchasing.field name="phone" :label="__('purchasing.phone')" type="tel" direction="ltr" :maxlength="64" />
            </div>
        </fieldset>
        <!-- Advanced Details Section -->
        @if(!$vendorId)
        <div class="pt-1">
            <x-details-toggle model="showAdvanced" controls="vendor-form-details" />
        </div>
        @endif

        <div id="vendor-form-details" x-show="showAdvanced" @if(!$vendorId) x-cloak @endif class="space-y-4">
                <div class="entry-grid grid sm:grid-cols-2 lg:grid-cols-3 gap-4">                <x-purchasing.field name="code" :label="__('purchasing.vendor_code')" direction="ltr" :maxlength="64" /></div>
        <fieldset class="p-4 sm:p-5 bg-surface border border-border rounded-card">
            <legend class="px-2 text-sm font-bold">{{ __('purchasing.contact_section') }}</legend>
            <div class="grid sm:grid-cols-2 gap-4">
                <x-purchasing.field name="whatsapp" :label="__('purchasing.whatsapp')" type="tel" direction="ltr" :maxlength="64" />
                <x-purchasing.field name="email" :label="__('purchasing.email')" type="email" direction="ltr" />
            </div>
        </fieldset>
        <fieldset class="p-4 sm:p-5 bg-surface border border-border rounded-card">
            <legend class="px-2 text-sm font-bold">{{ __('purchasing.preferences_section') }}</legend>
            <div class="grid sm:grid-cols-2 gap-4">
                <div>
                    <label for="vendorLocale" class="block text-sm font-semibold text-text-primary mb-1.5">{{ __('purchasing.preferred_locale') }}</label>
                    <select id="vendorLocale" wire:model="preferred_locale" class="w-full min-h-11 lg:min-h-9.5 px-3 border border-border-control bg-canvas rounded-control text-base lg:text-sm focus:border-primary focus:ring-1 focus:ring-primary outline-hidden">
                        <option value="">{{ __('purchasing.company_default') }}</option>
                        @foreach ($locales as $language)<option value="{{ $language->locale }}">{{ __('purchasing.language_' . $language->locale) }}</option>@endforeach
                    </select>
                    @error('preferred_locale')<p role="alert" class="text-xs text-danger">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="vendorCurrency" class="block text-sm font-semibold text-text-primary mb-1.5">{{ __('purchasing.default_currency') }}</label>
                    <select id="vendorCurrency" wire:model="default_currency_code" class="w-full min-h-11 lg:min-h-9.5 px-3 border border-border-control bg-canvas rounded-control text-base lg:text-sm focus:border-primary focus:ring-1 focus:ring-primary outline-hidden">
                        <option value="">{{ __('purchasing.company_default') }}</option>
                        @foreach ($currencies as $currency)<option value="{{ $currency->currency_code }}">{{ $currency->currency_code }}</option>@endforeach
                    </select>
                    @error('default_currency_code')<p role="alert" class="text-xs text-danger">{{ $message }}</p>@enderror
                </div>
            </div>
            <label for="vendor-active" class="inline-flex min-h-11 min-w-11 items-center gap-2 text-sm mt-3 cursor-pointer">
                <input id="vendor-active"
                       type="checkbox"
                       wire:model="active"
                       aria-invalid="{{ $errors->has('active') ? 'true' : 'false' }}"
                       @if ($errors->has('active')) aria-describedby="vendor-active-error" @endif
                       class="size-5 rounded-control border-border text-primary accent-primary focus:ring-primary" />
                <span>{{ __('purchasing.active') }}</span>
            </label>
            @error('active')<p id="vendor-active-error" role="alert" class="mt-1 text-xs text-danger">{{ $message }}</p>@enderror
        </fieldset>


                <fieldset class="p-4 sm:p-5 bg-surface border border-border rounded-card">
                    <legend class="px-2 text-sm font-bold">{{ __('purchasing.business_name_ar') }} / {{ __('purchasing.tax_number') }}</legend>
                    <div class="grid sm:grid-cols-2 gap-4">
                        <x-purchasing.field name="business_name_ar" :label="__('purchasing.business_name_ar')" direction="rtl" />
                        <x-purchasing.field name="business_name_en" :label="__('purchasing.business_name_en')" direction="ltr" />
                        <x-purchasing.field name="tax_number" :label="__('purchasing.tax_number')" direction="ltr" :maxlength="64" />
                    </div>
                </fieldset>
                <fieldset class="p-4 sm:p-5 bg-surface border border-border rounded-card">
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
                <fieldset class="p-4 sm:p-5 bg-surface border border-border rounded-card">
                    <legend class="px-2 text-sm font-bold">{{ __('purchasing.notes') }}</legend>
                    <x-purchasing.field name="notes" :label="__('purchasing.notes')" type="textarea" :maxlength="4000" />
                </fieldset>
        </div>
        <div class="flex flex-wrap items-center justify-end gap-3 pt-4 border-t border-border">
            @if ($canView)
                <a href="{{ route('vendors.index') }}" class="min-h-11 lg:min-h-9.5 inline-flex items-center justify-center px-4 rounded-control border border-border-control text-sm font-semibold text-text-secondary hover:bg-surface-soft transition-colors">{{ __('purchasing.cancel') }}</a>
            @endif
            <button type="submit" wire:loading.attr="disabled" class="min-h-11 lg:min-h-9.5 inline-flex items-center justify-center px-5 rounded-control bg-primary text-white text-sm font-bold hover:bg-primary-hover disabled:opacity-50 cursor-pointer">{{ __('purchasing.save_vendor') }}</button>
        </div>
    </form>
</div>
