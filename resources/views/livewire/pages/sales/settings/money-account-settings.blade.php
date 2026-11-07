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

            <button type="button"
                    wire:click="newAccount"
                    class="h-10 min-h-[40px] px-4 rounded-control bg-primary text-white text-sm sm:text-sm font-bold hover:bg-primary-hover transition-colors shadow-xs cursor-pointer inline-flex items-center justify-center" wire:loading.attr="disabled" wire:target="newAccount">
                {{ __('sales.add_account') }}
            </button>
        </div>

    @if (session()->has('success'))
        <div class="p-3 bg-success-bg border border-success/30 rounded-control text-success text-xs font-bold">
            {{ session('success') }}
        </div>
    @endif

    <div class="bg-surface rounded-card border border-border shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="data-table w-full text-start text-xs border-collapse">
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
                        <tr>
                            <td class="py-3 px-4 font-bold text-text-primary uppercase text-xs">
                                {{ __('sales.money_type_'.$acc->account_type) }}
                            </td>
                            <td class="py-3 px-4 font-bold">
                                {{ $acc->displayName() }}
                            </td>
                            <td class="py-3 px-4 font-mono font-bold">
                                {{ $acc->currency_code }}
                            </td>
                            <td class="py-3 px-4 text-text-secondary font-mono">
                                {{ $acc->bank_name ? $acc->bank_name . ' (' . ($acc->account_number ?? $acc->iban) . ')' : '—' }}
                            </td>
                            <td class="py-3 px-4 text-text-muted">
                                {{ $acc->ledgerAccount?->code }} - {{ $acc->ledgerAccount?->displayName() }}
                            </td>
                            <td class="py-3 px-4">
                                @if ($acc->is_active)
                                    <span class="px-2 py-0.5 rounded-full text-xs font-bold bg-success-bg text-success">{{ __('sales.active') }}</span>
                                @else
                                    <span class="px-2 py-0.5 rounded-full text-xs font-bold bg-slate-100 text-slate-600">{{ __('sales.inactive') }}</span>
                                @endif
                            </td>
                            <td class="table-actions py-3 px-4 text-end">
                                <button type="button"
                                        wire:click="editAccount({{ $acc->id }})"
                                        class="px-2.5 py-1 rounded-control bg-surface-soft hover:bg-slate-200 text-text-primary text-xs font-bold transition-colors" wire:loading.attr="disabled" wire:target="editAccount">
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
        <div class="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-surface rounded-card border border-border shadow-xl max-w-md w-full p-6 space-y-4">
                <h3 class="font-bold text-base text-text-primary">
                    {{ $editingAccountId ? __('sales.edit_money_account') : __('sales.new_money_account') }}
                </h3>

                <form wire:submit="save" class="space-y-3 text-xs">
                    <div>
                        <label class="block font-bold text-text-primary mb-1">{{ __('sales.type') }} <span class="text-danger">*</span></label>
                        <select wire:model="account_type" @disabled($editingAccountId !== null) class="w-full h-8 px-2 rounded-control border border-border">
                            <option value="cash">{{ __('sales.cash') }}</option>
                            <option value="bank">{{ __('sales.bank') }}</option>
                        </select>
                    </div>

                    <div>
                        <label class="block font-bold text-text-primary mb-1">{{ __('sales.name_ar') }} <span class="text-danger">*</span></label>
                        <input type="text" wire:model="name_ar" required class="w-full h-8 px-2 rounded-control border border-border" />
                        @error('name_ar') <span class="text-danger text-xs block mt-0.5">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block font-bold text-text-primary mb-1">{{ __('sales.name_en') }}</label>
                        <input type="text" wire:model="name_en" class="w-full h-8 px-2 rounded-control border border-border" />
                    </div>

                    <div>
                        <label class="block font-bold text-text-primary mb-1">{{ __('sales.currency') }} <span class="text-danger">*</span></label>
                        <select wire:model="currency_code" @disabled($editingAccountId !== null) class="w-full h-8 px-2 rounded-control border border-border">
                            @foreach ($currencies as $curr)
                                <option value="{{ $curr->currency_code }}">{{ $curr->currency_code }}</option>
                            @endforeach
                        </select>
                    </div>

                    @if ($account_type === 'bank')
                        <div>
                            <label class="block font-bold text-text-primary mb-1">{{ __('sales.bank_name') }}</label>
                            <input type="text" wire:model="bank_name" class="w-full h-8 px-2 rounded-control border border-border" />
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block font-bold text-text-primary mb-1">{{ __('sales.account_number') }}</label>
                                <input type="text" wire:model="account_number" dir="ltr" class="w-full h-8 px-2 rounded-control border border-border font-mono" />
                            </div>
                            <div>
                                <label class="block font-bold text-text-primary mb-1">IBAN</label>
                                <input type="text" wire:model="iban" dir="ltr" class="w-full h-8 px-2 rounded-control border border-border font-mono" />
                            </div>
                        </div>
                    @endif

                    <div class="flex items-center gap-2 pt-2">
                        <input type="checkbox" wire:model="is_active" id="accActive" class="rounded" />
                        <label for="accActive" class="font-bold text-text-primary">{{ __('sales.active') }}</label>
                    </div>

                    <div class="flex justify-end gap-2 pt-3 border-t border-border">
                        <button type="button" wire:click="$set('showFormModal', false)" class="px-3 py-1.5 rounded-control border border-border text-sm font-bold">
                            {{ __('sales.cancel') }}
                        </button>
                        <button type="submit" class="px-4 py-1.5 rounded-control bg-primary text-white text-sm font-bold">
                            {{ __('sales.save') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
    </div>
</x-settings-shell>
