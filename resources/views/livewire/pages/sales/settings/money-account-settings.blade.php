<x-settings-shell active-section="money_accounts">
    <div class="space-y-6">
        <!-- Sub-Header / Action Bar -->
        <div class="flex items-center justify-between gap-4 pb-3 border-b border-border">
            <div>
                <h2 class="text-base font-extrabold text-text-primary">
                    {{ __('sales.settings_money_accounts') }}
                </h2>
                <p class="text-xs text-text-secondary mt-0.5">
                    {{ __('sales.settings_money_accounts_desc') }}
                </p>
            </div>

            <button id="money-account-add-trigger"
                    type="button"
                    wire:click="newAccount"
                    class="min-h-11 lg:min-h-9.5 px-4 rounded-control bg-primary text-white text-sm font-bold hover:bg-primary-hover transition-colors shadow-xs cursor-pointer inline-flex items-center justify-center" wire:loading.attr="disabled" wire:target="newAccount">
                {{ __('sales.add_account') }}
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
                        <th class="py-3 px-4 text-start">{{ __('sales.type') }}</th>
                        <th class="py-3 px-4 text-start">{{ __('sales.name') }}</th>
                        <th class="py-3 px-4 text-start">{{ __('sales.currency') }}</th>
                        <th class="py-3 px-4 text-start">{{ __('sales.bank_details') }} / IBAN</th>
                        <th class="py-3 px-4 text-start">{{ __('sales.ledger_account') }}</th>
                        <th class="py-3 px-4 text-start">{{ __('sales.status') }}</th>
                        <th class="py-3 px-4 text-end">{{ __('sales.action') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($accounts as $acc)
                        <tr wire:key="money-acc-row-{{ $acc->id }}" x-data="{ expanded: false }" :data-expanded="expanded ? 'true' : 'false'">
                            <td class="td-secondary py-3 px-4 font-bold text-text-primary uppercase text-xs" data-label="{{ __('sales.type') }}">
                                {{ __('sales.money_type_'.$acc->account_type) }}
                            </td>
                            <td class="td-primary py-3 px-4 font-bold">
                                <div class="truncate text-text-primary">{{ $acc->displayName() }}</div>
                                <div class="lg:hidden font-normal text-xs text-text-secondary truncate mt-0.5">
                                    {{ __('sales.money_type_'.$acc->account_type) }} &middot; <bdi dir="ltr" class="font-mono">{{ $acc->currency_code }}</bdi>
                                </div>
                            </td>
                            <td class="td-secondary py-3 px-4 font-mono font-bold" data-label="{{ __('sales.currency') }}">
                                {{ $acc->currency_code }}
                            </td>
                            <td class="td-secondary py-3 px-4 text-text-secondary font-mono" data-label="{{ __('sales.bank_details') }}">
                                {{ $acc->bank_name ? $acc->bank_name . ' (' . ($acc->account_number ?? $acc->iban) . ')' : '—' }}
                            </td>
                            <td class="td-secondary py-3 px-4 text-text-muted" data-label="{{ __('sales.ledger_account') }}">
                                {{ $acc->ledgerAccount?->code }} - {{ $acc->ledgerAccount?->displayName() }}
                            </td>
                            <td class="td-end py-3 px-4">
                                <div class="inline-flex items-center gap-2">
                                    @if ($acc->is_active)
                                        <span class="px-2 py-0.5 rounded-full text-xs font-bold bg-success-bg text-success">{{ __('sales.active') }}</span>
                                    @else
                                        <span class="px-2 py-0.5 rounded-full text-xs font-bold bg-slate-100 text-slate-600">{{ __('sales.inactive') }}</span>
                                    @endif
                                    <x-row-details-toggle />
                                </div>
                            </td>
                            <td class="table-actions py-3 px-4 text-end">
                                <button id="money-account-edit-trigger-{{ $acc->id }}"
                                        type="button"
                                        wire:click="editAccount({{ $acc->id }})"
                                        class="touch-action px-2.5 py-1 rounded-control bg-surface-soft hover:bg-surface-muted text-text-primary text-xs font-bold transition-colors" wire:loading.attr="disabled" wire:target="editAccount({{ $acc->id }})">
                                    {{ __('sales.edit') }}
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-6 text-center text-text-muted">
                                {{ __('sales.no_money_accounts') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    <div class="p-4"><x-table-pagination :paginator="$accounts" /></div>
    </div>

    <!-- Modal Form -->
    @if ($showFormModal)
        <x-dialog-shell :title="$editingAccountId ? __('sales.edit_money_account') : __('sales.new_money_account')" title-id="money-account-modal-title" close-action="$set('showFormModal', false)" save-target="save" max-width="max-w-md">
            <form id="money-account-form" wire:submit="save" class="space-y-3 text-sm">
                <div>
                    <label for="money-account-type" class="block font-bold text-text-primary mb-1">{{ __('sales.type') }} <span class="text-danger">*</span></label>
                    <select id="money-account-type"
                            wire:model="account_type"
                            @disabled($editingAccountId !== null)
                            aria-invalid="{{ $errors->has('account_type') ? 'true' : 'false' }}"
                            @if ($errors->has('account_type')) aria-describedby="money-account-type-error" @endif
                            class="w-full min-h-11 lg:min-h-9.5 px-2.5 rounded-control border border-border text-sm">
                        <option value="cash">{{ __('sales.cash') }}</option>
                        <option value="bank">{{ __('sales.bank') }}</option>
                    </select>
                    @error('account_type') <span id="money-account-type-error" role="alert" class="text-danger text-xs block mt-0.5">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label for="money-account-name-ar" class="block font-bold text-text-primary mb-1">{{ __('sales.name_ar') }} <span class="text-danger">*</span></label>
                    <input id="money-account-name-ar"
                           type="text"
                           wire:model="name_ar"
                           dir="rtl"
                           required
                           aria-invalid="{{ $errors->has('name_ar') ? 'true' : 'false' }}"
                           @if ($errors->has('name_ar')) aria-describedby="money-account-name-ar-error" @endif
                           class="w-full min-h-11 lg:min-h-9.5 px-2.5 rounded-control border border-border text-sm" />
                    @error('name_ar') <span id="money-account-name-ar-error" role="alert" class="text-danger text-xs block mt-0.5">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label for="money-account-name-en" class="block font-bold text-text-primary mb-1">{{ __('sales.name_en') }}</label>
                    <input id="money-account-name-en"
                           type="text"
                           wire:model="name_en"
                           dir="ltr"
                           aria-invalid="{{ $errors->has('name_en') ? 'true' : 'false' }}"
                           @if ($errors->has('name_en')) aria-describedby="money-account-name-en-error" @endif
                           class="w-full min-h-11 lg:min-h-9.5 px-2.5 rounded-control border border-border text-sm" />
                    @error('name_en') <span id="money-account-name-en-error" role="alert" class="text-danger text-xs block mt-0.5">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label for="money-account-currency" class="block font-bold text-text-primary mb-1">{{ __('sales.currency') }} <span class="text-danger">*</span></label>
                    <select id="money-account-currency"
                            wire:model="currency_code"
                            @disabled($editingAccountId !== null)
                            aria-invalid="{{ $errors->has('currency_code') ? 'true' : 'false' }}"
                            @if ($errors->has('currency_code')) aria-describedby="money-account-currency-error" @endif
                            class="w-full min-h-11 lg:min-h-9.5 px-2.5 rounded-control border border-border text-sm">
                        @foreach ($currencies as $curr)
                            <option value="{{ $curr->currency_code }}">{{ $curr->currency_code }}</option>
                        @endforeach
                    </select>
                    @error('currency_code') <span id="money-account-currency-error" role="alert" class="text-danger text-xs block mt-0.5">{{ $message }}</span> @enderror
                </div>

                @if ($account_type === 'bank')
                    <div>
                        <label for="money-account-bank-name" class="block font-bold text-text-primary mb-1">{{ __('sales.bank_name') }}</label>
                        <input id="money-account-bank-name"
                               type="text"
                               wire:model="bank_name"
                               aria-invalid="{{ $errors->has('bank_name') ? 'true' : 'false' }}"
                               @if ($errors->has('bank_name')) aria-describedby="money-account-bank-name-error" @endif
                               class="w-full min-h-11 lg:min-h-9.5 px-2.5 rounded-control border border-border text-sm" />
                        @error('bank_name') <span id="money-account-bank-name-error" role="alert" class="text-danger text-xs block mt-0.5">{{ $message }}</span> @enderror
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label for="money-account-number" class="block font-bold text-text-primary mb-1">{{ __('sales.account_number') }}</label>
                            <input id="money-account-number"
                                   type="text"
                                   wire:model="account_number"
                                   dir="ltr"
                                   aria-invalid="{{ $errors->has('account_number') ? 'true' : 'false' }}"
                                   @if ($errors->has('account_number')) aria-describedby="money-account-number-error" @endif
                                   class="w-full min-h-11 lg:min-h-9.5 px-2.5 rounded-control border border-border text-sm font-mono" />
                            @error('account_number') <span id="money-account-number-error" role="alert" class="text-danger text-xs block mt-0.5">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label for="money-account-iban" class="block font-bold text-text-primary mb-1">IBAN</label>
                            <input id="money-account-iban"
                                   type="text"
                                   wire:model="iban"
                                   dir="ltr"
                                   aria-invalid="{{ $errors->has('iban') ? 'true' : 'false' }}"
                                   @if ($errors->has('iban')) aria-describedby="money-account-iban-error" @endif
                                   class="w-full min-h-11 lg:min-h-9.5 px-2.5 rounded-control border border-border text-sm font-mono" />
                            @error('iban') <span id="money-account-iban-error" role="alert" class="text-danger text-xs block mt-0.5">{{ $message }}</span> @enderror
                        </div>
                    </div>
                @endif

                <div class="pt-1">
                    <label for="accActive" class="inline-flex min-h-11 items-center gap-2 font-bold text-text-primary cursor-pointer">
                        <input type="checkbox" wire:model="is_active" id="accActive" class="size-5 rounded text-primary accent-primary focus:ring-primary" />
                        <span>{{ __('sales.active') }}</span>
                    </label>
                </div>
            </form>
            <x-slot:footer>
                <x-secondary-button type="button" wire:click="$set('showFormModal', false)" wire:loading.attr="disabled">
                    {{ __('sales.cancel') }}
                </x-secondary-button>
                <x-primary-button type="submit" form="money-account-form" wire:loading.attr="disabled" wire:target="save">
                    {{ __('sales.save') }}
                </x-primary-button>
            </x-slot:footer>
        </x-dialog-shell>
    @endif
    </div>
</x-settings-shell>
