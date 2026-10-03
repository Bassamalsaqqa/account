<div class="space-y-5">
    <header class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-text-primary">{{ __('purchasing.vendors') }}</h1>
            <p class="text-xs text-text-secondary mt-1">{{ __('purchasing.vendor_directory_hint') }}</p>
        </div>
        @if ($canManage)
            <a href="{{ route('vendors.create') }}" class="inline-flex h-11 items-center justify-center gap-2 px-4 rounded-control bg-primary text-white text-sm font-bold hover:bg-primary-hover">
                <x-icon name="plus" class="w-4 h-4" /> {{ __('purchasing.add_vendor') }}
            </a>
        @endif
    </header>
    <div class="bg-white border border-border rounded-card p-4 grid sm:grid-cols-[1fr_auto] gap-3">
        <div>
            <label for="vendorSearch" class="sr-only">{{ __('purchasing.search_vendors') }}</label>
            <input id="vendorSearch" type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('purchasing.search_vendors') }}" class="w-full h-11 px-3 rounded-control border border-border bg-canvas text-sm" />
        </div>
        <select wire:model.live="statusFilter" aria-label="{{ __('purchasing.status') }}" class="h-11 px-3 rounded-control border border-border text-sm">
            <option value="all">{{ __('purchasing.all_vendors') }}</option>
            <option value="active">{{ __('purchasing.active_vendors') }}</option>
            <option value="inactive">{{ __('purchasing.inactive_vendors') }}</option>
        </select>
    </div>
    <section class="bg-white border border-border rounded-card overflow-hidden">
        <div class="hidden md:block overflow-x-auto">
            <table class="w-full text-xs text-start">
                <thead class="bg-surface-soft text-text-secondary border-b border-border">
                    <tr>
                        @foreach (['vendor_code', 'vendor', 'phone', 'default_currency', 'status'] as $label)
                            <th class="px-4 py-3 text-start">{{ __('purchasing.' . $label) }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($vendors as $vendor)
                        <tr wire:key="vendor-row-{{ $vendor->public_id }}" class="hover:bg-surface-soft">
                            <td class="px-4 py-4 font-mono"><bdi>{{ $vendor->code ?? '—' }}</bdi></td>
                            <td class="px-4 py-4">
                                <a href="{{ route('vendors.show', $vendor->public_id) }}" class="font-bold text-text-primary hover:text-primary">{{ $vendor->displayName() }}</a>
                                <p class="text-text-secondary mt-1">{{ app()->getLocale() === 'en' ? ($vendor->business_name_en ?? $vendor->business_name_ar) : $vendor->business_name_ar }}</p>
                            </td>
                            <td class="px-4 py-4"><bdi>{{ $vendor->phone ?? $vendor->whatsapp ?? '—' }}</bdi></td>
                            <td class="px-4 py-4"><bdi>{{ $vendor->default_currency_code ?? '—' }}</bdi></td>
                            <td class="px-4 py-4"><span class="{{ $vendor->active ? 'text-success' : 'text-text-secondary' }} font-bold">{{ __('purchasing.' . $vendor->status) }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="p-8 text-center text-text-secondary">{{ __('purchasing.no_vendors_found') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="md:hidden divide-y divide-border">
            @forelse ($vendors as $vendor)
                <article wire:key="vendor-card-{{ $vendor->public_id }}" class="p-4 space-y-3">
                    <div class="flex justify-between items-start gap-3">
                        <a href="{{ route('vendors.show', $vendor->public_id) }}" class="font-bold text-sm text-text-primary">{{ $vendor->displayName() }}</a>
                        <span class="text-xs {{ $vendor->active ? 'text-success' : 'text-text-secondary' }}">{{ __('purchasing.' . $vendor->status) }}</span>
                    </div>
                    <dl class="grid grid-cols-2 gap-3 text-xs">
                        <div><dt class="text-text-secondary">{{ __('purchasing.vendor_code') }}</dt><dd class="mt-1"><bdi>{{ $vendor->code ?? '—' }}</bdi></dd></div>
                        <div><dt class="text-text-secondary">{{ __('purchasing.default_currency') }}</dt><dd class="mt-1"><bdi>{{ $vendor->default_currency_code ?? '—' }}</bdi></dd></div>
                        <div class="col-span-2"><dt class="text-text-secondary">{{ __('purchasing.phone') }}</dt><dd class="mt-1"><bdi>{{ $vendor->phone ?? $vendor->whatsapp ?? '—' }}</bdi></dd></div>
                    </dl>
                </article>
            @empty
                <p class="p-8 text-center text-sm text-text-secondary">{{ __('purchasing.no_vendors_found') }}</p>
            @endforelse
        </div>
    </section>
    {{ $vendors->links() }}
</div>
