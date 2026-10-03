<div class="max-w-2xl mx-auto space-y-5">
    <header class="flex items-center justify-between gap-3">
        <h1 class="text-xl sm:text-2xl font-bold text-text-primary">{{ __('purchasing.purchase_settings') }}</h1>
        <a href="{{ route('settings.index') }}" class="py-3 text-sm text-primary">{{ __('purchasing.back') }}</a>
    </header>
    @if (session()->has('success'))<p role="status" class="p-3 rounded-control bg-success-bg text-success text-sm">{{ session('success') }}</p>@endif
    <form wire:submit="save" class="bg-white border border-border rounded-card p-4 sm:p-5 space-y-5">
        <div>
            <label for="paymentTerms" class="block text-sm font-bold mb-2">{{ __('purchasing.default_payment_terms_days') }}</label>
            <input id="paymentTerms" type="number" min="0" max="3650" step="1" wire:model="default_payment_terms_days" dir="ltr" class="w-full h-11 px-3 border border-border rounded-control text-sm" />
            <p class="text-xs text-text-secondary mt-2">{{ __('purchasing.payment_terms_hint') }}</p>
            @error('default_payment_terms_days')<p role="alert" class="text-xs text-danger">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="receivingWarehouse" class="block text-sm font-bold mb-2">{{ __('purchasing.default_receiving_warehouse') }}</label>
            <select id="receivingWarehouse" wire:model="default_receiving_warehouse_id" class="w-full h-11 px-3 border border-border rounded-control text-sm">
                <option value="">{{ __('purchasing.no_default_warehouse') }}</option>
                @foreach ($warehouses as $warehouse)<option value="{{ $warehouse->id }}">{{ $warehouse->displayName() }}</option>@endforeach
            </select>
            @error('default_receiving_warehouse_id')<p role="alert" class="text-xs text-danger">{{ $message }}</p>@enderror
        </div>
        <label class="flex items-start gap-3 py-2">
            <input type="checkbox" wire:model="warn_duplicate_vendor_invoice" class="mt-1 rounded-control border-border" />
            <span class="text-sm font-bold">{{ __('purchasing.warn_duplicate_vendor_invoice') }}</span>
        </label>
        @error('warn_duplicate_vendor_invoice')<p role="alert" class="text-xs text-danger">{{ $message }}</p>@enderror
        <div class="flex justify-end border-t border-border pt-4"><button type="submit" wire:loading.attr="disabled" class="h-11 px-4 rounded-control bg-primary text-white text-sm font-bold hover:bg-primary-hover disabled:opacity-50">{{ __('purchasing.save_settings') }}</button></div>
    </form>
</div>
