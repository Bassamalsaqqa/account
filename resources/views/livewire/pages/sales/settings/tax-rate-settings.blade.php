<x-settings-shell active-section="taxes">
    <div class="space-y-6">
        <!-- Sub-Header / Action Bar -->
        <div class="flex items-center justify-between gap-4 pb-3 border-b border-border">
            <div>
                <h2 class="text-base font-extrabold text-text-primary">
                    {{ __('sales.settings_taxes') }}
                </h2>
                <p class="text-xs text-text-secondary mt-0.5">
                    {{ __('sales.settings_taxes_desc') }}
                </p>
            </div>

            <button id="tax-rate-add-trigger"
                    type="button"
                    wire:click="newTaxRate"
                    class="min-h-11 lg:min-h-9.5 px-4 rounded-control bg-primary text-white text-sm font-bold hover:bg-primary-hover transition-colors shadow-xs cursor-pointer inline-flex items-center justify-center" wire:loading.attr="disabled" wire:target="newTaxRate">
                {{ __('sales.add_tax_rate') }}
            </button>
        </div>

        @if (session()->has('success'))
            <div role="status" class="p-3 bg-success-bg border border-success/30 rounded-control text-success text-xs font-bold">
                {{ session('success') }}
            </div>
        @endif

        <div class="bg-surface rounded-card border border-border shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="data-table data-table--responsive w-full text-start text-xs border-collapse">
                <thead>
                    <tr class="border-b border-border bg-surface-soft text-text-muted font-bold text-xs uppercase">
                        <th class="py-3 px-4 text-start">{{ __('sales.settings_code') }}</th>
                        <th class="py-3 px-4 text-start">{{ __('sales.name') }}</th>
                        <th class="py-3 px-4 text-start">{{ __('sales.rate_percent') }}</th>
                        <th class="py-3 px-4 text-start">{{ __('sales.type') }}</th>
                        <th class="py-3 px-4 text-start">{{ __('purchasing.sales_tax_account') }}</th>
                        <th class="py-3 px-4 text-start">{{ __('purchasing.purchase_tax_account') }}</th>
                        <th class="py-3 px-4 text-start">{{ __('sales.status') }}</th>
                        <th class="py-3 px-4 text-end">{{ __('sales.action') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($taxRates as $tax)
                        <tr wire:key="tax-row-{{ $tax->id }}" x-data="{ expanded: false }" :data-expanded="expanded ? 'true' : 'false'">
                            <td class="td-secondary py-3 px-4 font-mono font-bold text-text-primary" data-label="{{ __('sales.settings_code') }}">
                                {{ $tax->code }}
                            </td>
                            <td class="td-primary py-3 px-4 font-bold">
                                <div class="truncate text-text-primary">{{ $tax->displayName() }}</div>
                                <div class="lg:hidden font-normal text-xs text-text-secondary truncate mt-0.5">
                                    <bdi dir="ltr" class="font-mono">{{ $tax->code }}</bdi> &middot; <bdi dir="ltr" class="font-mono">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney((string) $tax->rate, 'ILS') }}%</bdi> &middot; {{ __('sales.' . $tax->calculation) }}
                                </div>
                            </td>
                            <td class="td-secondary py-3 px-4 font-mono font-bold" data-label="{{ __('sales.rate_percent') }}" dir="ltr">
                                {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney((string) $tax->rate, 'ILS') }}%
                            </td>
                            <td class="td-secondary py-3 px-4 text-text-secondary capitalize" data-label="{{ __('sales.type') }}">
                                {{ __('sales.' . $tax->calculation) }}
                            </td>
                            <td class="td-secondary py-3 px-4 text-text-muted" data-label="{{ __('purchasing.sales_tax_account') }}">
                                {{ $tax->salesTaxAccount?->code }} - {{ $tax->salesTaxAccount?->displayName() }}
                            </td>
                            <td class="td-secondary py-3 px-4 text-text-secondary" data-label="{{ __('purchasing.purchase_tax_account') }}">
                                {{ $tax->purchaseTaxAccount?->displayName() ?? __('purchasing.not_configured') }}
                            </td>
                            <td class="td-end py-3 px-4">
                                <div class="inline-flex items-center gap-2">
                                    @if ($tax->active)
                                        <span class="px-2 py-0.5 rounded-full text-xs font-bold bg-success-bg text-success">{{ __('sales.active') }}</span>
                                    @else
                                        <span class="px-2 py-0.5 rounded-full text-xs font-bold bg-slate-100 text-slate-600">{{ __('sales.inactive') }}</span>
                                    @endif
                                    <x-row-details-toggle />
                                </div>
                            </td>
                            <td class="table-actions py-3 px-4 text-end">
                                <button id="tax-rate-edit-trigger-{{ $tax->id }}"
                                        type="button"
                                        wire:click="editTaxRate({{ $tax->id }})"
                                        class="touch-action px-2.5 py-1 rounded-control bg-surface-soft hover:bg-surface-muted text-text-primary text-xs font-bold transition-colors" wire:loading.attr="disabled" wire:target="editTaxRate({{ $tax->id }})">
                                    {{ __('sales.edit') }}
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="py-6 text-center text-text-muted">
                                {{ __('sales.no_tax_rates') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    <div class="p-4"><x-table-pagination :paginator="$taxRates" /></div>
    </div>

    <!-- Modal Form -->
    @if ($showFormModal)
        <x-dialog-shell :title="$editingTaxId ? __('sales.edit_tax_rate') : __('sales.new_tax_rate')" title-id="tax-rate-modal-title" close-action="$set('showFormModal', false)" save-target="save" max-width="max-w-md">
            <form id="tax-rate-form" wire:submit="save" class="space-y-3 text-sm">
                <div>
                    <label for="tax-code" class="block font-bold text-text-primary mb-1">{{ __('sales.settings_code') }} <span class="text-danger">*</span></label>
                    <input id="tax-code"
                           type="text"
                           wire:model="code"
                           dir="ltr"
                           placeholder="VAT16"
                           required
                           aria-invalid="{{ $errors->has('code') ? 'true' : 'false' }}"
                           @if ($errors->has('code')) aria-describedby="tax-code-error" @endif
                           class="w-full min-h-11 lg:min-h-9.5 px-2.5 rounded-control border border-border text-sm uppercase font-mono" />
                    @error('code') <span id="tax-code-error" role="alert" class="text-danger text-xs block mt-0.5">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label for="tax-name-ar" class="block font-bold text-text-primary mb-1">{{ __('sales.name_ar') }} <span class="text-danger">*</span></label>
                    <input id="tax-name-ar"
                           type="text"
                           wire:model="name_ar"
                           dir="rtl"
                           required
                           aria-invalid="{{ $errors->has('name_ar') ? 'true' : 'false' }}"
                           @if ($errors->has('name_ar')) aria-describedby="tax-name-ar-error" @endif
                           class="w-full min-h-11 lg:min-h-9.5 px-3 rounded-control border border-border-control bg-canvas text-base lg:text-sm focus:border-primary focus:ring-1 focus:ring-primary outline-hidden" />
                    @error('name_ar') <span id="tax-name-ar-error" role="alert" class="text-danger text-xs block mt-0.5">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label for="tax-name-en" class="block font-bold text-text-primary mb-1">{{ __('sales.name_en') }}</label>
                    <input id="tax-name-en"
                           type="text"
                           wire:model="name_en"
                           dir="ltr"
                           aria-invalid="{{ $errors->has('name_en') ? 'true' : 'false' }}"
                           @if ($errors->has('name_en')) aria-describedby="tax-name-en-error" @endif
                           class="w-full min-h-11 lg:min-h-9.5 px-3 rounded-control border border-border-control bg-canvas text-base lg:text-sm focus:border-primary focus:ring-1 focus:ring-primary outline-hidden" />
                    @error('name_en') <span id="tax-name-en-error" role="alert" class="text-danger text-xs block mt-0.5">{{ $message }}</span> @enderror
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label for="tax-rate" class="block font-bold text-text-primary mb-1">{{ __('sales.rate_percent') }} <span class="text-danger">*</span></label>
                        <input id="tax-rate"
                               type="number"
                               inputmode="decimal"
                               step="0.000001"
                               min="0"
                               max="100"
                               wire:model="rate"
                               required
                               dir="ltr"
                               aria-invalid="{{ $errors->has('rate') ? 'true' : 'false' }}"
                               @if ($errors->has('rate')) aria-describedby="tax-rate-error" @endif
                               class="w-full min-h-11 lg:min-h-9.5 px-3 rounded-control border border-border-control bg-canvas text-base lg:text-sm font-mono tabular-nums focus:border-primary focus:ring-1 focus:ring-primary outline-hidden" />
                        @error('rate') <span id="tax-rate-error" role="alert" class="text-danger text-xs block mt-0.5">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label for="tax-calculation" class="block font-bold text-text-primary mb-1">{{ __('sales.calculation') }} <span class="text-danger">*</span></label>
                        <select id="tax-calculation"
                                wire:model="calculation"
                                aria-invalid="{{ $errors->has('calculation') ? 'true' : 'false' }}"
                                @if ($errors->has('calculation')) aria-describedby="tax-calculation-error" @endif
                                class="w-full min-h-11 lg:min-h-9.5 px-3 rounded-control border border-border-control bg-canvas text-base lg:text-sm focus:border-primary focus:ring-1 focus:ring-primary outline-hidden">
                            <option value="exclusive">{{ __('sales.exclusive') }}</option>
                            <option value="inclusive">{{ __('sales.inclusive') }}</option>
                        </select>
                        @error('calculation') <span id="tax-calculation-error" role="alert" class="text-danger text-xs block mt-0.5">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div>
                    <label for="tax-sales-account" class="block font-bold text-text-primary mb-1">{{ __('purchasing.sales_tax_account') }} <span class="text-danger">*</span></label>
                    <select id="tax-sales-account"
                            wire:model="sales_tax_account_id"
                            required
                            aria-invalid="{{ $errors->has('sales_tax_account_id') ? 'true' : 'false' }}"
                            @if ($errors->has('sales_tax_account_id')) aria-describedby="tax-sales-account-error" @endif
                            class="w-full min-h-11 lg:min-h-9.5 px-3 rounded-control border border-border-control bg-canvas text-base lg:text-sm focus:border-primary focus:ring-1 focus:ring-primary outline-hidden">
                        <option value="">{{ __('sales.select_tax_account') }}</option>
                        @foreach ($accounts as $acc)
                            <option value="{{ $acc->id }}">{{ $acc->code }} - {{ $acc->displayName() }}</option>
                        @endforeach
                    </select>
                    @error('sales_tax_account_id') <span id="tax-sales-account-error" role="alert" class="text-danger text-xs block mt-0.5">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label for="purchaseTaxAccount" class="block font-bold text-text-primary mb-1">{{ __('purchasing.purchase_tax_account') }}</label>
                    <select id="purchaseTaxAccount"
                            wire:model="purchase_tax_account_id"
                            aria-invalid="{{ $errors->has('purchase_tax_account_id') ? 'true' : 'false' }}"
                            @if ($errors->has('purchase_tax_account_id')) aria-describedby="tax-purchase-account-error" @endif
                            class="w-full min-h-11 lg:min-h-9.5 px-3 rounded-control border border-border-control bg-canvas text-base lg:text-sm focus:border-primary focus:ring-1 focus:ring-primary outline-hidden">
                        <option value="">{{ __('purchasing.not_configured') }}</option>
                        @foreach ($purchaseAccounts as $account)
                            <option value="{{ $account->id }}">{{ $account->code }} — {{ $account->displayName() }}</option>
                        @endforeach
                    </select>
                    <p class="text-text-secondary text-xs mt-1">{{ __('purchasing.purchase_tax_account_hint') }}</p>
                    @error('purchase_tax_account_id') <span id="tax-purchase-account-error" role="alert" class="text-danger text-xs block mt-0.5">{{ $message }}</span> @enderror
                </div>

                <div class="pt-1">
                    <label for="taxActive" class="inline-flex min-h-11 items-center gap-2 font-bold text-text-primary cursor-pointer">
                        <input type="checkbox" wire:model="active" id="taxActive" class="size-5 rounded text-primary accent-primary focus:ring-primary" />
                        <span>{{ __('sales.active') }}</span>
                    </label>
                </div>
            </form>
            <x-slot:footer>
                <x-secondary-button type="button" wire:click="$set('showFormModal', false)" wire:loading.attr="disabled">
                    {{ __('sales.cancel') }}
                </x-secondary-button>
                <x-primary-button type="submit" form="tax-rate-form" wire:loading.attr="disabled" wire:target="save">
                    {{ __('sales.save') }}
                </x-primary-button>
            </x-slot:footer>
        </x-dialog-shell>
    @endif
    </div>
</x-settings-shell>
