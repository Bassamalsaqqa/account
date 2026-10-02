<div class="max-w-4xl mx-auto space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-text-primary">
                {{ __('sales.settings_taxes') }}
            </h1>
            <p class="text-xs text-text-secondary mt-1">
                {{ __('sales.settings_taxes_desc') }}
            </p>
        </div>

        <div class="flex items-center gap-2">
            <button type="button"
                    wire:click="newTaxRate"
                    class="px-3.5 py-1.5 rounded-control bg-primary text-white text-xs font-bold hover:bg-primary-hover transition-colors shadow-xs">
                {{ __('sales.add_tax_rate') }}
            </button>
            <a href="{{ route('settings.index') }}"
               class="px-3 py-1.5 rounded-control bg-surface-soft text-text-secondary hover:text-text-primary text-xs font-bold transition-colors">
                {{ __('sales.back') }}
            </a>
        </div>
    </div>

    @if (session()->has('success'))
        <div class="p-3 bg-success-bg border border-success/30 rounded-control text-success text-xs font-bold">
            {{ session('success') }}
        </div>
    @endif

    <div class="bg-white rounded-card border border-border shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-start text-xs border-collapse">
                <thead>
                    <tr class="border-b border-border bg-surface-soft text-text-muted font-bold text-[11px] uppercase">
                        <th class="py-3 px-4 text-start">{{ __('sales.settings_code') }}</th>
                        <th class="py-3 px-4 text-start">{{ __('sales.name') }}</th>
                        <th class="py-3 px-4 text-start">{{ __('sales.rate_percent') }}</th>
                        <th class="py-3 px-4 text-start">{{ __('sales.type') }}</th>
                        <th class="py-3 px-4 text-start">{{ __('sales.sales_tax_account') }}</th>
                        <th class="py-3 px-4 text-start">{{ __('sales.status') }}</th>
                        <th class="py-3 px-4 text-end">{{ __('sales.action') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($taxRates as $tax)
                        <tr>
                            <td class="py-3 px-4 font-mono font-bold text-text-primary">
                                {{ $tax->code }}
                            </td>
                            <td class="py-3 px-4 font-bold">
                                {{ $tax->displayName() }}
                            </td>
                            <td class="py-3 px-4 font-mono font-bold" dir="ltr">
                                {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format((string) $tax->rate, 'ILS') }}%
                            </td>
                            <td class="py-3 px-4 text-text-secondary capitalize">
                                {{ __('sales.' . $tax->calculation) }}
                            </td>
                            <td class="py-3 px-4 text-text-muted">
                                {{ $tax->salesTaxAccount?->code }} - {{ $tax->salesTaxAccount?->displayName() }}
                            </td>
                            <td class="py-3 px-4">
                                @if ($tax->active)
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-success-bg text-success">{{ __('sales.active') }}</span>
                                @else
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-100 text-slate-600">{{ __('sales.inactive') }}</span>
                                @endif
                            </td>
                            <td class="py-3 px-4 text-end">
                                <button type="button"
                                        wire:click="editTaxRate({{ $tax->id }})"
                                        class="px-2.5 py-1 rounded-control bg-surface-soft hover:bg-slate-200 text-text-primary text-xs font-bold transition-colors">
                                    {{ __('sales.edit') }}
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-6 text-center text-text-muted">
                                {{ __('sales.no_tax_rates') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal Form -->
    @if ($showFormModal)
        <div class="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-white rounded-card border border-border shadow-xl max-w-md w-full p-6 space-y-4">
                <h3 class="font-bold text-base text-text-primary">
                    {{ $editingTaxId ? __('sales.edit_tax_rate') : __('sales.new_tax_rate') }}
                </h3>

                <form wire:submit="save" class="space-y-3 text-xs">
                    <div>
                        <label class="block font-bold text-text-primary mb-1">{{ __('sales.settings_code') }} <span class="text-danger">*</span></label>
                        <input type="text" wire:model="code" placeholder="VAT16" required class="w-full h-8 px-2 rounded-control border border-border uppercase font-mono" />
                        @error('code') <span class="text-danger text-[11px] block mt-0.5">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block font-bold text-text-primary mb-1">{{ __('sales.name_ar') }} <span class="text-danger">*</span></label>
                        <input type="text" wire:model="name_ar" required class="w-full h-8 px-2 rounded-control border border-border" />
                        @error('name_ar') <span class="text-danger text-[11px] block mt-0.5">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block font-bold text-text-primary mb-1">{{ __('sales.name_en') }}</label>
                        <input type="text" wire:model="name_en" class="w-full h-8 px-2 rounded-control border border-border" />
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block font-bold text-text-primary mb-1">{{ __('sales.rate_percent') }} <span class="text-danger">*</span></label>
                            <input type="number" step="0.000001" min="0" max="100" wire:model="rate" required dir="ltr" class="w-full h-8 px-2 rounded-control border border-border font-mono" />
                            @error('rate') <span class="text-danger text-[11px] block mt-0.5">{{ $message }}</span> @enderror
                        </div>

                        <div>
                            <label class="block font-bold text-text-primary mb-1">{{ __('sales.calculation') }} <span class="text-danger">*</span></label>
                            <select wire:model="calculation" class="w-full h-8 px-2 rounded-control border border-border">
                                <option value="exclusive">{{ __('sales.exclusive') }}</option>
                                <option value="inclusive">{{ __('sales.inclusive') }}</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="block font-bold text-text-primary mb-1">{{ __('sales.sales_tax_account') }} <span class="text-danger">*</span></label>
                        <select wire:model="sales_tax_account_id" required class="w-full h-8 px-2 rounded-control border border-border">
                            <option value="">{{ __('sales.select_tax_account') }}</option>
                            @foreach ($accounts as $acc)
                                <option value="{{ $acc->id }}">{{ $acc->code }} - {{ $acc->displayName() }}</option>
                            @endforeach
                        </select>
                        @error('sales_tax_account_id') <span class="text-danger text-[11px] block mt-0.5">{{ $message }}</span> @enderror
                    </div>

                    <div class="flex items-center gap-2 pt-2">
                        <input type="checkbox" wire:model="active" id="taxActive" class="rounded" />
                        <label for="taxActive" class="font-bold text-text-primary">{{ __('sales.active') }}</label>
                    </div>

                    <div class="flex justify-end gap-2 pt-3 border-t border-border">
                        <button type="button" wire:click="$set('showFormModal', false)" class="px-3 py-1.5 rounded-control border border-border text-xs font-bold">
                            {{ __('sales.cancel') }}
                        </button>
                        <button type="submit" class="px-4 py-1.5 rounded-control bg-primary text-white text-xs font-bold">
                            {{ __('sales.save') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
