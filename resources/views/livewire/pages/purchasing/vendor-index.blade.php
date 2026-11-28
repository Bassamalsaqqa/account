<div class="space-y-5">
    <header class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-text-primary">{{ __('purchasing.vendors') }}</h1>
            <p class="text-xs text-text-secondary mt-1">{{ __('purchasing.vendor_directory_hint') }}</p>
        </div>
        @if ($canManage)
            <a href="{{ route('vendors.create') }}" class="touch-action h-11 bg-primary text-white text-sm font-bold hover:bg-primary-hover">
                <x-icon name="plus" class="w-4 h-4" /> {{ __('purchasing.add_vendor') }}
            </a>
        @endif
    </header>
    <x-refinement-filters :active-count="(int) ($statusFilter !== 'all')">
        <x-slot:search>
            <input aria-label="{{ __('purchasing.search_vendors') }}" id="vendorSearch" type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('purchasing.search_vendors') }}" class="min-h-11 w-full min-w-0 rounded-control border border-border bg-surface px-3 py-2 text-sm focus:border-primary focus:ring-primary" />
        </x-slot:search>
        <x-slot:statuses>
        <button type="button" wire:click="$set('statusFilter', 'all')" wire:loading.attr="disabled" wire:target="statusFilter" aria-pressed="{{ $statusFilter === 'all' ? 'true' : 'false' }}" class="touch-action shrink-0 border text-sm font-semibold {{ $statusFilter === 'all' ? 'border-primary bg-primary text-white' : 'border-border bg-surface text-text-secondary' }}">{{ __('purchasing.all_vendors') }}</button>
        <button type="button" wire:click="$set('statusFilter', 'active')" wire:loading.attr="disabled" wire:target="statusFilter" aria-pressed="{{ $statusFilter === 'active' ? 'true' : 'false' }}" class="touch-action shrink-0 border text-sm font-semibold {{ $statusFilter === 'active' ? 'border-primary bg-primary text-white' : 'border-border bg-surface text-text-secondary' }}">{{ __('purchasing.active_vendors') }}</button>
        <button type="button" wire:click="$set('statusFilter', 'inactive')" wire:loading.attr="disabled" wire:target="statusFilter" aria-pressed="{{ $statusFilter === 'inactive' ? 'true' : 'false' }}" class="touch-action shrink-0 border text-sm font-semibold {{ $statusFilter === 'inactive' ? 'border-primary bg-primary text-white' : 'border-border bg-surface text-text-secondary' }}">{{ __('purchasing.inactive_vendors') }}</button>
        </x-slot:statuses>
        <select wire:model.live="statusFilter" aria-label="{{ __('purchasing.status') }}" class="min-h-11 h-11 px-3 rounded-control border border-border text-sm">
            <option value="all">{{ __('purchasing.all_vendors') }}</option>
            <option value="active">{{ __('purchasing.active_vendors') }}</option>
            <option value="inactive">{{ __('purchasing.inactive_vendors') }}</option>
        </select>
    </x-refinement-filters>
    <section class="bg-surface border border-border rounded-card overflow-hidden">
        <div class="hidden lg:block overflow-x-auto">
            <table class="data-table w-full text-xs text-start">
                <thead class="bg-surface-soft text-text-secondary border-b border-border">
                    <tr>
                        @foreach (['vendor_code', 'vendor', 'phone', 'default_currency', 'status'] as $label)
                            <th class="px-4 py-3 text-start">{{ __('purchasing.' . $label) }}</th>
                        @endforeach
                        <th class="px-4 py-3 text-end">{{ __('purchasing.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($vendors as $vendor)
                        <tr wire:key="vendor-row-{{ $vendor->public_id }}" class="hover:bg-surface-soft">
                            <td class="px-4 py-4 font-mono"><bdi>{{ $vendor->code ?? '—' }}</bdi></td>
                            <td class="px-4 py-4">
                                <a href="{{ route('vendors.show', $vendor->public_id) }}" class="touch-action font-bold text-text-primary hover:text-primary">{{ $vendor->displayName() }}</a>
                                <p class="text-text-secondary mt-1">{{ app()->getLocale() === 'en' ? ($vendor->business_name_en ?? $vendor->business_name_ar) : $vendor->business_name_ar }}</p>
                            </td>
                            <td class="px-4 py-4"><bdi>{{ $vendor->phone ?? $vendor->whatsapp ?? '—' }}</bdi></td>
                            <td class="px-4 py-4"><bdi>{{ $vendor->default_currency_code ?? '—' }}</bdi></td>
                            <td class="px-4 py-4"><span class="{{ $vendor->active ? 'text-success' : 'text-text-secondary' }} font-bold">{{ __('purchasing.' . $vendor->status) }}</span></td>
                            <td class="table-actions px-4 py-4 text-end">
                                <div class="row-action-group justify-end" role="group" aria-label="{{ __('purchasing.actions') }}">
                                    <x-row-action data-row-link :href="route('vendors.show', $vendor->public_id)" :label="__('purchasing.vendor_details')" icon="eye" />
                                    @if ($vendor->active && $canCreatePurchase)
                                        <x-row-action :href="route('purchases.create', ['vendor_id' => $vendor->id])" :label="__('mobile.new_purchase')" icon="plus" tone="primary" />
                                    @endif
                                    @if ($vendor->active && $canCreatePayment)
                                        <x-row-action :href="route('vendor-payments.create', ['vendor_id' => $vendor->id])" :label="__('mobile.record_vendor_payment')" icon="money" />
                                    @endif
                                    @if ($canManage)
                                        <x-row-action :href="route('vendors.edit', $vendor->public_id)" :label="__('purchasing.edit_vendor')" icon="edit" tone="primary" />
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="p-8 text-center text-text-secondary">{{ __('purchasing.no_vendors_found') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="lg:hidden divide-y divide-border">
            @forelse ($vendors as $vendor)
                <x-compact-mobile-row wire:key="vendor-card-{{ $vendor->public_id }}">
                    <x-slot:primary>
                        <a href="{{ route('vendors.show', $vendor->public_id) }}" data-row-link class="touch-action font-bold text-sm text-text-primary hover:text-primary truncate">
                            {{ $vendor->displayName() }}
                        </a>
                        <span class="text-xs font-bold shrink-0 {{ $vendor->active ? 'text-success' : 'text-text-secondary' }}">
                            {{ __('purchasing.' . $vendor->status) }}
                        </span>
                    </x-slot:primary>

                    <x-slot:subtitle>
                        @if ($vendor->code)<bdi dir="ltr" class="font-mono">{{ $vendor->code }}</bdi>@endif
                        @if ($vendor->code && ($vendor->phone || $vendor->whatsapp)) &middot; @endif
                        @if ($vendor->phone || $vendor->whatsapp)<bdi dir="ltr" class="font-mono">{{ $vendor->phone ?? $vendor->whatsapp }}</bdi>@endif
                        @if (($vendor->code || $vendor->phone || $vendor->whatsapp) && $vendor->default_currency_code) &middot; @endif
                        @if ($vendor->default_currency_code)<bdi dir="ltr">{{ $vendor->default_currency_code }}</bdi>@endif
                    </x-slot:subtitle>

                    <x-slot:details>
                        <dl class="grid grid-cols-2 gap-2">
                            <div>
                                <dt class="text-text-secondary">{{ __('purchasing.vendor_code') }}</dt>
                                <dd class="mt-0.5 font-mono font-semibold text-text-primary" dir="ltr"><bdi>{{ $vendor->code ?? '—' }}</bdi></dd>
                            </div>
                            <div>
                                <dt class="text-text-secondary">{{ __('purchasing.default_currency') }}</dt>
                                <dd class="mt-0.5 font-semibold text-text-primary" dir="ltr"><bdi>{{ $vendor->default_currency_code ?? '—' }}</bdi></dd>
                            </div>
                            <div class="col-span-2">
                                <dt class="text-text-secondary">{{ __('purchasing.phone') }}</dt>
                                <dd class="mt-0.5 font-mono font-semibold text-text-primary" dir="ltr"><bdi>{{ $vendor->phone ?? $vendor->whatsapp ?? '—' }}</bdi></dd>
                            </div>
                        </dl>
                        <div class="flex justify-end">
                            <div class="row-action-group justify-end" role="group" aria-label="{{ __('purchasing.actions') }}">
                                <x-row-action data-row-link :href="route('vendors.show', $vendor->public_id)" :label="__('purchasing.vendor_details')" icon="eye" />
                                @if ($vendor->active && $canCreatePurchase)
                                    <x-row-action :href="route('purchases.create', ['vendor_id' => $vendor->id])" :label="__('mobile.new_purchase')" icon="plus" tone="primary" />
                                @endif
                                @if ($vendor->active && $canCreatePayment)
                                    <x-row-action :href="route('vendor-payments.create', ['vendor_id' => $vendor->id])" :label="__('mobile.record_vendor_payment')" icon="money" />
                                @endif
                                @if ($canManage)
                                    <x-row-action :href="route('vendors.edit', $vendor->public_id)" :label="__('purchasing.edit_vendor')" icon="edit" tone="primary" />
                                @endif
                            </div>
                        </div>
                    </x-slot:details>
                </x-compact-mobile-row>
            @empty
                <p class="p-8 text-center text-sm text-text-secondary">{{ __('purchasing.no_vendors_found') }}</p>
            @endforelse
        </div>
    </section>
    <x-table-pagination :paginator="$vendors" />
</div>
