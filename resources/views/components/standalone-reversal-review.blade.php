@if($this->reversalReview)
<x-dialog-shell :title="__('standalone.title')" close-action="closeReversalReview" save-target="confirmReversalReview" max-width="max-w-2xl">
    <p class="text-sm text-text-secondary">{{ __('standalone.effect_'.$this->reversalReview['kind']) }}</p>
    @error('review')<p role="alert" class="text-sm text-danger">{{ $message }}</p>@enderror
    @foreach($this->reversalReview['rows'] as $row)
        <dl class="grid grid-cols-2 gap-2 rounded-control border border-border bg-surface-soft p-3 text-sm">
            @foreach($row as $field => $value)
                <dt class="text-text-secondary">{{ __('standalone.fields.'.$field) }}</dt>
                <dd class="text-end break-words"><bdi dir="ltr" class="tabular-nums">{{ in_array($field, ['amount','net_payable','from_amount','to_amount','allocated_amount','payment_currency_amount','allocated_base']) ? \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($value) : $value }}</bdi></dd>
            @endforeach
        </dl>
    @endforeach
    @if($this->reversalReview['blocked'])
        <p role="alert" class="text-sm text-danger">{{ __('standalone.blocked') }}</p>
        @if($this->reversalReview['recovery'])
            <a class="touch-action text-primary underline" href="{{ $this->reversalReview['recovery'] }}">{{ __('money.check') }}</a>
        @endif
    @else
        <form wire:submit="confirmReversalReview" class="space-y-4" x-data @submit="$nextTick(() => $el.querySelector('[aria-invalid=true]')?.focus())">
            @if(in_array($this->reversalReview['kind'], ['expense','advance','salary','salary_payment']))
                <label class="block text-sm space-y-1" for="review-date">{{ __('money.date') }}</label>
                <x-text-input id="review-date" type="date" wire:model="reversalDate" required />
                @error('reversalDate')<span x-init="$nextTick(() => document.getElementById('review-date')?.focus())"></span><p role="alert" class="text-sm text-danger">{{ $message }}</p>@enderror
            @endif
            <label class="block text-sm space-y-1" for="review-reason">{{ __('money.reason') }}</label>
            <x-text-input id="review-reason" maxlength="{{ in_array($this->reversalReview['kind'], ['customer','vendor']) ? 255 : 500 }}" wire:model="{{ $this->reversalReview['kind'] === 'transfer' ? 'reason' : 'reversalReason' }}" />
            @foreach(['reason','reversalReason','reversal','transfer'] as $field)
                @error($field)<span x-init="$nextTick(() => document.getElementById('review-reason')?.focus())"></span><p role="alert" class="text-sm text-danger">{{ $message }}</p>@enderror
            @endforeach
            <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="confirmReversalReview" class="min-h-11">{{ __('standalone.confirm') }}</x-primary-button>
        </form>
    @endif
</x-dialog-shell>
@endif
