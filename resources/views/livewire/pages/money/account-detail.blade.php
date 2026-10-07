<div class="min-w-0 space-y-6">
@include('livewire.pages.money.nav')
<h1 class="text-2xl font-bold">{{ $account->displayName() }}</h1>
<section class="rounded-card border border-border bg-white p-4 sm:p-6 flex flex-wrap justify-between gap-4">
<div>
<p class="text-xs text-text-secondary">{{ __('money.balance') }}</p>
<p class="text-xl font-bold" dir="ltr">{{ $balance['balance_currency'] ?? __('money.unavailable') }} {{ $account->currency_code }}</p>
</div>
<div>
<p class="text-xs text-text-secondary">{{ __('money.base_value') }}</p>
<bdi>{{ $balance['balance_base'] }} {{ $balance['base_currency_code'] }}</bdi>
</div>
</section>
<section class="rounded-card border border-border bg-white p-4 sm:p-6 space-y-4">
<h2 class="font-bold">{{ __('money.movements') }}</h2>
@forelse($movements as $movement)<article class="grid gap-2 border-b border-border py-3 sm:grid-cols-3">
<div>
<p class="text-sm font-semibold break-words">{{ $movement->description ?: __('money.movement') }}</p>
<p class="text-xs text-text-secondary">{{ $movement->posting_date }}</p>@if(isset($sourceLinks[$movement->id]))<a class="text-sm text-primary" href="{{ $sourceLinks[$movement->id] }}">{{ __('money.source') }}</a>@endif</div>
<div class="text-sm">
<p>{{ __('money.in') }}: <bdi>{{ $movement->debit_base }}</bdi>
</p>
<p>{{ __('money.out') }}: <bdi>{{ $movement->credit_base }}</bdi>
</p>
</div>
<div class="text-sm">
<p>{{ __('money.running_base') }}: <bdi>{{ $movement->running_base }} {{ $balance['base_currency_code'] }}</bdi>
</p>
<p>{{ __('money.transaction') }}: <bdi>{{ $movement->transaction_amount ?? '—' }} {{ $movement->transaction_currency_code }}</bdi>
</p>
</div>
<p class="text-xs">{{ __('money.running_currency') }}: <bdi>{{ $account->currency_code === $balance['base_currency_code'] ? $movement->running_base : ($movement->unknown_currency_lines == 0 ? $movement->known_running_currency : __('money.unavailable')) }} {{ $account->currency_code }}</bdi>
</p>
</article>@empty<p class="text-text-secondary">{{ __('money.empty') }}</p>
@endforelse
{{ $movements->links() }}</section>
</div>
