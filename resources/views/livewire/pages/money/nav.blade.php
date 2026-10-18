<nav aria-label="{{ __('money.title') }}" class="money-section-nav flex gap-2 text-sm text-primary">
    <a href="{{ route('money.overview') }}" class="touch-action">{{ __('money.overview') }}</a>
    @can('money.cash.view')<a href="{{ route('money.cash') }}" class="touch-action">{{ __('money.cash') }}</a>@endcan
    @can('money.bank.view')<a href="{{ route('money.bank') }}" class="touch-action">{{ __('money.bank') }}</a>@endcan
    @can('money.transfer.view')<a href="{{ route('money.transfers.index') }}" class="touch-action">{{ __('money.transfers') }}</a>@endcan
    @can('money.check.view')<a href="{{ route('money.checks.index') }}" class="touch-action">{{ __('money.checks') }}</a>@endcan
    @can('money.receipt.view')<a href="{{ route('payments.index') }}" class="touch-action">{{ __('money.receipts') }}</a>@endcan
    @if(app(\App\Services\Purchasing\VendorFinancialRead::class)->allows((int) app(\App\Support\Tenancy\CompanyContext::class)->companyId()))<a href="{{ route('vendor-payments.index') }}" class="touch-action">{{ __('money.vendor_payments') }}</a>@endif
</nav>
