@php
    $mobileActor = auth()->user();
    $mobileSales = $mobileActor->can('sales.invoice.view');
    $mobileMoney = $mobileActor->hasAnyPermission(['money.cash.view', 'money.bank.view', 'money.check.view', 'money.transfer.view']);
    $mobileProducts = $mobileActor->can('viewAny', \App\Models\Product::class);
    $mobileCanScan = $mobileProducts || $mobileActor->can('sales.invoice.create') || $mobileActor->can('sales.quote.create') || ($mobileActor->can('purchasing.purchase.create') && $mobileActor->can('purchasing.cost.view'));
    $mobileOnScanPage = request()->routeIs('invoices.create', 'invoices.edit', 'quotations.create', 'quotations.edit', 'purchases.create', 'purchases.edit', 'products.index');
@endphp
<nav class="mobile-bottom-nav lg:hidden" aria-label="{{ __('mobile_shell.navigation') }}" x-data="{ scanChoices: false }">
    <a href="{{ route('dashboard') }}" @if(request()->routeIs('dashboard')) aria-current="page" @endif>
        <x-icon name="dashboard" aria-hidden="true" /><span>{{ __('mobile_shell.home') }}</span>
    </a>
    @if($mobileSales)
        <a href="{{ route('invoices.index') }}" @if(request()->routeIs('invoices.*', 'quotations.*')) aria-current="page" @endif>
            <x-icon name="receipt" aria-hidden="true" /><span>{{ __('mobile_shell.sales') }}</span>
        </a>
    @else
        <button type="button" @click="mobileMenuOpen = true; $nextTick(() => $refs.navToggle?.focus())"><x-icon name="menu" aria-hidden="true" /><span>{{ __('app.open_navigation') }}</span></button>
    @endif
    @if($mobileCanScan)
        <button type="button" class="mobile-scan-button" @click="{{ $mobileOnScanPage ? '$dispatch(\'mobile-camera-open\')' : 'scanChoices = true; $nextTick(() => $refs.scanClose.focus())' }}" aria-haspopup="dialog">
            <x-icon name="barcode" aria-hidden="true" /><span>{{ __('mobile_shell.scan') }}</span>
        </button>
    @else
        <button type="button" @click="window.dispatchEvent(new CustomEvent('open-global-search'))"><x-icon name="search" aria-hidden="true" /><span>{{ __('mobile_shell.search') }}</span></button>
    @endif
    @if($mobileMoney)
        <a href="{{ route('money.overview') }}" @if(request()->routeIs('money.*')) aria-current="page" @endif><x-icon name="bank" aria-hidden="true" /><span>{{ __('mobile_shell.money') }}</span></a>
    @else
        <a href="{{ route('profile') }}"><x-icon name="users" aria-hidden="true" /><span>{{ __('app.profile') }}</span></a>
    @endif
    <button type="button" @click="mobileMenuOpen = !mobileMenuOpen; $nextTick(() => $refs.navToggle?.focus())" :aria-expanded="mobileMenuOpen.toString()" aria-controls="app-sidebar"><x-icon name="menu" aria-hidden="true" /><span>{{ __('mobile_shell.menu') }}</span></button>

    <section x-cloak x-show="scanChoices" role="dialog" aria-modal="true" aria-labelledby="mobile-scan-destinations" x-trap.inert.noscroll="scanChoices" @keydown.escape.stop.prevent="scanChoices = false" class="mobile-scan-choices" @click.self="scanChoices = false">
        <div class="mobile-scan-choice-sheet">
            <div class="flex items-center justify-between gap-3"><h2 id="mobile-scan-destinations" class="text-lg font-bold">{{ __('mobile_shell.scan_choose') }}</h2><button x-ref="scanClose" type="button" @click="scanChoices = false" aria-label="{{ __('mobile_shell.close') }}" class="min-h-11 min-w-11"><x-icon name="close" /></button></div>
            <p class="text-sm text-text-secondary my-3">{{ __('mobile_shell.scan_help') }}</p>
            @can('sales.invoice.create')<a href="{{ route('invoices.create') }}">{{ __('mobile_shell.invoice') }}<x-icon name="receipt" /></a>@endcan
            @can('sales.quote.create')<a href="{{ route('quotations.create') }}">{{ __('mobile_shell.quotation') }}<x-icon name="quote" /></a>@endcan
            @if($mobileActor->can('purchasing.purchase.create') && $mobileActor->can('purchasing.cost.view'))<a href="{{ route('purchases.create') }}">{{ __('mobile_shell.purchase') }}<x-icon name="cart" /></a>@endif
            @if($mobileProducts)<a href="{{ route('products.index') }}">{{ __('mobile_shell.catalog') }}<x-icon name="box" /></a>@endif
        </div>
    </section>
</nav>
