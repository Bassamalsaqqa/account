@php($subscriptionNotice = app(\App\Application\Platform\TenantSubscriptionNotice::class)->current())
@if($subscriptionNotice !== null)
    <aside aria-label="{{ __('subscriptions.tenant_notice_title') }}" class="mb-5 rounded-control border border-border bg-surface-soft p-4 text-sm text-text-primary">
        <p class="font-semibold">{{ __('subscriptions.tenant_notice_title') }}</p>
        @if($subscriptionNotice['state'] === 'read_only')
            <p class="mt-1 leading-relaxed">{{ __('subscriptions.tenant_read_only') }}</p>
        @elseif($subscriptionNotice['state'] === 'expired_in_grace')
            <p class="mt-1 leading-relaxed">{{ __('subscriptions.tenant_grace', ['date' => $subscriptionNotice['read_only_from']]) }}</p>
        @else
            <p class="mt-1 leading-relaxed">{{ __('subscriptions.tenant_due', ['days' => $subscriptionNotice['days_until_renewal']]) }}</p>
        @endif
    </aside>
@endif
