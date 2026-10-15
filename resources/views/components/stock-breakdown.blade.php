@props(['parts'])
@if(count($parts) > 1 || (isset($parts[0]) && $parts[0]['name'] !== ''))
    <span class="block text-xs text-text-secondary mt-1">
        @foreach($parts as $part)
            @if(!$loop->first)<span aria-hidden="true"> + </span>@endif
            <bdi dir="ltr" class="tabular-nums">{{ $part['quantity'] }}</bdi> {{ $part['name'] }}
        @endforeach
    </span>
@endif
