<div class="min-w-0 space-y-6">
@include('livewire.pages.money.nav')
<h1 class="text-2xl font-bold">{{ $type ? __('money.'.$type) : __('money.title') }}</h1>
<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
@forelse($accounts as $account)
<a class="rounded-card border border-border bg-white p-4 sm:p-6 space-y-3 focus:ring-2 focus:ring-primary" href="{{ route('money.accounts.show', $account['public_id']) }}">
    <div class="flex gap-3 items-start justify-between">
<h2 class="font-semibold break-words">{{ $account['name'] }}</h2>
<span class="text-xs text-text-secondary">{{ __('money.'.$account['account_type']) }}</span>
</div>
    <p class="text-2xl font-semibold tabular-nums" dir="ltr">{{ $account['balance_currency'] ?? __('money.unavailable') }} {{ $account['currency_code'] }}</p>
    <p class="text-xs text-text-secondary">{{ __('money.base_value') }}: <bdi>{{ $account['balance_base'] }} {{ $account['base_currency_code'] }}</bdi>
</p>
    @if(!$account['currency_balance_available'])<p class="text-xs text-amber-800">{{ __('money.native_unavailable') }}</p>@endif
    @if(!$account['is_active'])<span class="text-xs text-text-muted">{{ __('money.inactive') }}</span>@endif
</a>
@empty<p class="text-text-secondary">{{ __('money.no_accounts') }}</p>
@endforelse
</div>
@if($checkSummary !== [])<section class="rounded-card border border-border bg-white p-4 sm:p-6 space-y-3">
<h2 class="font-bold">{{ __('money.outstanding_checks') }}</h2>
@foreach($checkSummary as $summary)<p>{{ __('money.'.$summary['direction']) }} · {{ $summary['count'] }} · <bdi>{{ $summary['total'] }} {{ $summary['currency_code'] }}</bdi>
</p>@endforeach</section>@endif
@if($dueSoon->isNotEmpty())<section class="rounded-card border border-border bg-white p-4 sm:p-6 space-y-3">
<h2 class="font-bold">{{ __('money.due_soon') }}</h2>
@foreach($dueSoon as $check)<a href="{{ route('money.checks.show', $check->public_id) }}" class="flex flex-wrap justify-between gap-3 border-b border-border py-2">
<bdi>{{ $check->check_number }}</bdi>
<span>{{ $check->due_date->toDateString() }}</span>
<bdi>{{ $check->amount }} {{ $check->currency_code }}</bdi>
</a>@endforeach</section>@endif
<section class="rounded-card border border-border bg-white p-4 sm:p-6 space-y-3">
<h2 class="font-bold">{{ __('money.recent_activity') }}</h2>
@forelse($activity as $movement)
<article class="grid sm:grid-cols-3 gap-2 border-b border-border py-2 text-sm">
<div><a class="text-primary" href="{{ route('money.accounts.show', $movement->account_public_id) }}">{{ app()->getLocale() === 'en' ? ($movement->name_en ?: $movement->name_ar) : $movement->name_ar }}</a><p class="text-xs text-text-muted">{{ $movement->posting_date }}</p></div>
<p class="break-words">{{ $movement->description }} @if(isset($sourceLinks[$movement->id]))<a class="text-primary" href="{{ $sourceLinks[$movement->id] }}">{{ __('money.source') }}</a>@endif</p>
<p dir="ltr">{{ $movement->transaction_amount ?? '—' }} {{ $movement->transaction_currency_code }}<span class="block text-xs">{{ __('money.base_value') }}: {{ $movement->debit_base }} / {{ $movement->credit_base }} {{ $company->base_currency_code }}</span></p>
</article>
@empty<p class="text-sm text-text-muted">{{ __('money.empty') }}</p>@endforelse
</section>
</div>
