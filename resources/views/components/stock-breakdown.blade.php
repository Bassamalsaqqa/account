@props(['parts', 'inline' => false])
@if(count($parts) > 1 || (isset($parts[0]) && $parts[0]['name'] !== ''))
    <span class="{{ $inline ? 'inline ms-2' : 'block mt-1' }} text-xs text-text-secondary">
        @foreach($parts as $part)
            @if(!$loop->first)<span aria-hidden="true"> + </span>@endif
            <bdi dir="ltr" class="tabular-nums">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayQuantity($part['quantity']) }}</bdi> {{ $part['name'] }}
        @endforeach
    </span>
@endif
