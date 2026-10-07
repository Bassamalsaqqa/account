<nav aria-label="{{ __('money.title') }}" class="flex flex-wrap gap-3 text-sm text-primary">
    <a href="{{ route('money.overview') }}">{{ __('money.overview') }}</a>
    @can('money.cash.view')<a href="{{ route('money.cash') }}">{{ __('money.cash') }}</a>@endcan
    @can('money.bank.view')<a href="{{ route('money.bank') }}">{{ __('money.bank') }}</a>@endcan
    @can('money.transfer.view')<a href="{{ route('money.transfers.index') }}">{{ __('money.transfers') }}</a>@endcan
    @can('money.check.view')<a href="{{ route('money.checks.index') }}">{{ __('money.checks') }}</a>@endcan
    @can('money.receipt.view')<a href="{{ route('payments.index') }}">{{ __('money.receipts') }}</a>@endcan
    @if(app(\App\Services\Purchasing\VendorFinancialRead::class)->allows((int) app(\App\Support\Tenancy\CompanyContext::class)->companyId()))<a href="{{ route('vendor-payments.index') }}">{{ __('money.vendor_payments') }}</a>@endif
</nav>