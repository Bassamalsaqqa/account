@if ($stockAvailability !== null)
    <div class="md:col-span-2 rounded-control bg-surface-soft p-3 space-y-1">
        <p class="text-sm font-semibold">{{ __('refinement.available_stock') }}:
            <bdi class="font-mono tabular-nums">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatQuantity($stockAvailability['quantity']) }}</bdi>
            {{ $stockAvailability['unit'] }}
        </p>
        @if ($stockAvailability['packages'] !== [])
            <p class="text-sm text-text-secondary">
                @foreach ($stockAvailability['packages'] as $part)
                    <bdi class="tabular-nums">{{ $part['quantity'] }}</bdi> {{ $part['name'] }}{{ $loop->last ? '' : ' + ' }}
                @endforeach
            </p>
        @endif
        <p class="text-xs text-text-secondary">{{ __('refinement.availability_hint') }}</p>
    </div>
@endif
