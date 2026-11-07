@props(['correctionMode', 'correctionPreview' => null])
<x-dialog-shell :title="__('corrections.'.$correctionMode)" close-action="closeCorrection" save-target="executeCorrection" max-width="max-w-lg">
    <p class="text-sm">{{ __('corrections.retained') }}</p>
    @if($errors->any())
        <ul role="alert" class="text-sm text-danger">@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>
    @endif
    @if(!$correctionPreview)
        <label class="block text-sm" for="correction-reason">{{ __('corrections.reason') }}</label>
        <x-text-input id="correction-reason" wire:model="correctionReason" :aria-invalid="$errors->has('reason') ? 'true' : 'false'" :aria-describedby="$errors->has('reason') ? 'correction-reason-error' : null" class="w-full" />
        @error('reason') <p id="correction-reason-error" role="alert" class="text-danger">{{ $message }}</p> @enderror
        <label class="block text-sm" for="correction-date">{{ __('corrections.date') }}</label>
        <x-text-input id="correction-date" type="date" wire:model="correctionDate" :aria-invalid="$errors->has('date') ? 'true' : 'false'" :aria-describedby="$errors->has('date') ? 'correction-date-error' : null" class="w-full" />
        @error('date') <p id="correction-date-error" role="alert" class="text-danger">{{ $message }}</p> @enderror
        <button type="button" wire:click="previewCorrection" wire:loading.attr="disabled" class="touch-action rounded-control bg-primary px-4 py-2 text-white">{{ __('corrections.preview') }}</button>
    @else
        <h4 wire:key="correction-plan-heading" tabindex="-1" x-init="$nextTick(() => $el.focus({ preventScroll: true }))" class="text-sm font-bold">{{ $correctionPreview['operation'] }} · <bdi dir="ltr">{{ $correctionPreview['number'] }}</bdi></h4>
        <ul class="space-y-2 text-sm">
            @foreach($correctionPreview['rows'] as $row)
                <li class="flex flex-wrap justify-between gap-2"><span>{{ $row['name'] }}</span><bdi dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayQuantity($row['quantity']) }} · {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($row['amount']) }} {{ $row['currency'] }}</bdi></li>
            @endforeach
        </ul>
        @foreach($correctionPreview['stock_rows'] ?? [] as $row)
            <p class="text-sm">{{ $row['product'] }} · {{ $row['warehouse'] }} @if($row['lot']) · <bdi dir="ltr">{{ $row['lot'] }}</bdi> @endif · {{ __('corrections.stock_inverse') }} <bdi dir="ltr" class="tabular-nums">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayQuantity($row['quantity']) }}</bdi></p>
        @endforeach
        @if(isset($correctionPreview['balance']))
            <p class="text-sm">{{ __('corrections.balance_inverse') }} · <bdi dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($correctionPreview['balance']) }} {{ $correctionPreview['currency'] }}</bdi></p>
        @endif
        @foreach($correctionPreview['payments'] ?? [] as $payment)
            <p class="text-sm">{{ __('corrections.reverse_payment') }} <bdi dir="ltr">{{ $payment['number'] }} · {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($payment['inverse']) }} {{ $payment['currency'] }}</bdi> · {{ $payment['account'] }}</p>
        @endforeach
        @foreach($correctionPreview['returns'] ?? [] as $return)
            <p class="text-sm">{{ __('corrections.reverse_return') }} <bdi dir="ltr">{{ $return['number'] }} · {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($return['amount']) }} {{ $return['currency'] }}</bdi></p>
            @foreach($return['stock_rows'] ?? [] as $row)
                <p class="text-sm">{{ $row['product'] }} · {{ $row['warehouse'] }} · <bdi dir="ltr">{{ $row['lot'] }} · {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayQuantity($row['quantity']) }}</bdi></p>
            @endforeach
        @endforeach
        @foreach($correctionPreview['blockers'] as $blocker)
            <p role="alert" class="text-sm text-danger">{{ $blocker }}</p>
        @endforeach
        @foreach($correctionPreview['links'] as $link)
            <a href="{{ $link['url'] }}" class="touch-action block text-sm underline">{{ $link['label'] }}</a>
        @endforeach
        @if(empty($correctionPreview['blockers']))
            <button type="button" wire:click="executeCorrection" wire:loading.attr="disabled" wire:target="executeCorrection" class="touch-action rounded-control bg-danger px-4 py-2 text-white">{{ __('corrections.confirm') }}</button>
        @endif
    @endif
</x-dialog-shell>
