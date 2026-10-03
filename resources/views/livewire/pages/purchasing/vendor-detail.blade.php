<div class="max-w-4xl mx-auto space-y-5">
    <header class="flex flex-col sm:flex-row justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-text-primary break-words">{{ $vendor->displayName() }}</h1>
            <p class="text-xs text-text-secondary mt-1">{{ __('purchasing.vendor_details') }} · {{ __('purchasing.' . $vendor->status) }}</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('vendors.index') }}" class="inline-flex items-center h-11 px-3 text-sm text-primary">{{ __('purchasing.back') }}</a>
            @if ($canManage)<a href="{{ route('vendors.edit', $vendor->public_id) }}" class="inline-flex items-center h-11 px-4 rounded-control bg-primary text-white text-sm font-bold">{{ __('purchasing.edit_vendor_button') }}</a>@endif
        </div>
    </header>
    @if (session()->has('success'))<p role="status" class="p-3 rounded-control bg-success-bg text-success text-sm">{{ session('success') }}</p>@endif
    <section class="bg-white border border-border rounded-card p-4 sm:p-5">
        <dl class="grid sm:grid-cols-2 gap-5 text-sm">
            @foreach (['name_ar' => 'vendor_name_ar', 'name_en' => 'vendor_name_en', 'business_name_ar' => 'business_name_ar', 'business_name_en' => 'business_name_en', 'code' => 'vendor_code', 'tax_number' => 'tax_number', 'phone' => 'phone', 'whatsapp' => 'whatsapp', 'email' => 'email', 'address_ar' => 'address_ar', 'address_en' => 'address_en', 'city_ar' => 'city_ar', 'city_en' => 'city_en', 'postal_code' => 'postal_code', 'country_code' => 'country_code'] as $field => $label)
                <div class="min-w-0"><dt class="text-xs text-text-secondary mb-1">{{ __('purchasing.' . $label) }}</dt><dd class="break-words whitespace-pre-line"><bdi>{{ $vendor->{$field} ?? '—' }}</bdi></dd></div>
            @endforeach
            <div><dt class="text-xs text-text-secondary mb-1">{{ __('purchasing.preferred_locale') }}</dt><dd>{{ $vendor->preferred_locale ? __('purchasing.language_' . $vendor->preferred_locale) : __('purchasing.company_default') }}</dd></div>
            <div><dt class="text-xs text-text-secondary mb-1">{{ __('purchasing.default_currency') }}</dt><dd>{{ $vendor->default_currency_code ?? __('purchasing.company_default') }}</dd></div>
        </dl>
    </section>
    <section class="bg-white border border-border rounded-card p-4 sm:p-5">
        <h2 class="text-sm font-bold mb-3">{{ __('purchasing.notes') }}</h2>
        <p class="text-sm text-text-secondary break-words whitespace-pre-line">{{ $vendor->notes ?? __('purchasing.no_notes') }}</p>
    </section>
</div>
