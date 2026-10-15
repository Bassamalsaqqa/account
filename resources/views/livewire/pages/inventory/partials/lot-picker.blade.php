<label for="inventory-lot" class="block font-bold text-text-secondary mb-1">{{ $lotLabel }} *</label>
<select id="inventory-lot" wire:model="lot_id" aria-describedby="inventory-lot-hint @if ($errors->has('lot_id')) inventory-lot-error @endif" aria-invalid="{{ $errors->has('lot_id') ? 'true' : 'false' }}" class="w-full h-11 px-2.5 rounded-control border border-border bg-white text-sm text-text-primary focus-visible:outline-2 focus-visible:outline-primary">
    <option value="">{{ __('inventory.select_lot') }}</option>
    @foreach ($availableLots as $lb)
        @php
            $expiry = $lb->lot?->expiry_date?->toDateString();
            $status = $expiry === null ? __('inventory.lot_unknown_expiry') : ($expiry < $lotReferenceDate->toDateString() ? __('inventory.lot_expired_status') : ($expiry === $lotReferenceDate->toDateString() ? __('inventory.lot_today_status') : __('inventory.lot_valid_status')));
        @endphp
        <option value="{{ $lb->lot_id }}">
            {{ $lb->lot?->lot_number ?? __('inventory.lot_prefix').$lb->lot_id }} — {{ $expiry ?? __('inventory.lot_unknown_expiry') }} — {{ $status }} — {{ __('inventory.base_available') }}: {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatQuantity((string) $lb->quantity_base) }} {{ $selectedProduct->baseUnit?->name() }}
        </option>
    @endforeach
</select>
<p id="inventory-lot-hint" class="mt-2 text-xs text-text-secondary">{{ $availableLots->isEmpty() ? __('inventory.no_available_lots') : __('inventory.lot_order_hint') }}</p>
<p class="mt-1 text-xs text-text-secondary">{{ __('inventory.lot_status_reference', ['date' => $lotReferenceDate->toDateString()]) }}</p>
@error('lot_id') <p role="alert" id="inventory-lot-error" class="mt-1 text-danger text-xs">{{ $message }}</p> @enderror
