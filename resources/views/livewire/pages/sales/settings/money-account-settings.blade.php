<div class="max-w-4xl mx-auto space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-text-primary">
                {{ __('sales.settings_money_accounts') }}
            </h1>
            <p class="text-xs text-text-secondary mt-1">
                {{ __('sales.settings_money_accounts_desc') }}
            </p>
        </div>

        <div class="flex items-center gap-2">
            <button type="button"
                    wire:click="newAccount"
                    class="px-3.5 py-1.5 rounded-control bg-primary text-white text-xs font-bold hover:bg-primary-hover transition-colors shadow-xs">
                + Add Account
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
                        <th class="py-3 px-4 text-start">Type</th>
                        <th class="py-3 px-4 text-start">Name</th>
                        <th class="py-3 px-4 text-start">Currency</th>
                        <th class="py-3 px-4 text-start">Bank / IBAN</th>
                        <th class="py-3 px-4 text-start">GL Ledger Account</th>
                        <th class="py-3 px-4 text-start">Status</th>
                        <th class="py-3 px-4 text-end">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($accounts as $acc)
                        <tr>
                            <td class="py-3 px-4 font-bold text-text-primary uppercase text-[11px]">
                                {{ $acc->account_type }}
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
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-success-bg text-success">Active</span>
                                @else
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-100 text-slate-600">Inactive</span>
                                @endif
                            </td>
                            <td class="py-3 px-4 text-end">
                                <button type="button"
                                        wire:click="editAccount({{ $acc->id }})"
                                        class="px-2.5 py-1 rounded-control bg-surface-soft hover:bg-slate-200 text-text-primary text-xs font-bold transition-colors">
                                    Edit
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-6 text-center text-text-muted">
                                No money accounts configured.
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
                    {{ $editingAccountId ? 'Edit Money Account' : 'New Money Account' }}
                </h3>

                <form wire:submit="save" class="space-y-3 text-xs">
                    <div>
                        <label class="block font-bold text-text-primary mb-1">Type <span class="text-danger">*</span></label>
                        <select wire:model="account_type" @disabled($editingAccountId !== null) class="w-full h-8 px-2 rounded-control border border-border">
                            <option value="cash">Cash (الصندوق)</option>
                            <option value="bank">Bank (البنك)</option>
                        </select>
                    </div>

                    <div>
                        <label class="block font-bold text-text-primary mb-1">Name (Arabic) <span class="text-danger">*</span></label>
                        <input type="text" wire:model="name_ar" placeholder="صندوق المبيعات الرئيسي" required class="w-full h-8 px-2 rounded-control border border-border" />
                        @error('name_ar') <span class="text-danger text-[11px] block mt-0.5">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block font-bold text-text-primary mb-1">Name (English)</label>
                        <input type="text" wire:model="name_en" placeholder="Main Cash Register" class="w-full h-8 px-2 rounded-control border border-border" />
                    </div>

                    <div>
                        <label class="block font-bold text-text-primary mb-1">Currency <span class="text-danger">*</span></label>
                        <select wire:model="currency_code" @disabled($editingAccountId !== null) class="w-full h-8 px-2 rounded-control border border-border">
                            @foreach ($currencies as $curr)
                                <option value="{{ $curr->currency_code }}">{{ $curr->currency_code }}</option>
                            @endforeach
                        </select>
                    </div>

                    @if ($account_type === 'bank')
                        <div>
                            <label class="block font-bold text-text-primary mb-1">Bank Name</label>
                            <input type="text" wire:model="bank_name" placeholder="Bank of Palestine" class="w-full h-8 px-2 rounded-control border border-border" />
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block font-bold text-text-primary mb-1">Account Number</label>
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
                        <label for="accActive" class="font-bold text-text-primary">Active</label>
                    </div>

                    <div class="flex justify-end gap-2 pt-3 border-t border-border">
                        <button type="button" wire:click="$set('showFormModal', false)" class="px-3 py-1.5 rounded-control border border-border text-xs font-bold">
                            Cancel
                        </button>
                        <button type="submit" class="px-4 py-1.5 rounded-control bg-primary text-white text-xs font-bold">
                            Save
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
