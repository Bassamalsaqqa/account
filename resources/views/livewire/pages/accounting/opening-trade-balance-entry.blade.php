<div class="max-w-2xl mx-auto px-4 py-4 sm:px-6 space-y-4">
    {{-- Header --}}
    <div class="flex items-center justify-between border-b border-gray-200 dark:border-neutral-800 pb-3">
        <div>
            <h1 class="text-xl font-bold text-gray-900 dark:text-white">
                {{ __('accounting.opening_trade_balances_title', [], 'ar') ?: 'تسجيل الأرصدة الافتتاحية للذمم' }}
            </h1>
            <p class="text-xs text-gray-500 dark:text-neutral-400 mt-0.5">
                {{ $side === 'customer' ? 'قيد رصيد افتتاحي سابق لزبون (ذمة مدينة لنا)' : 'قيد رصيد افتتاحي سابق لمورد (ذمة دائنة علينا)' }}
            </p>
        </div>

        {{-- Side Selector Pill --}}
        <div class="inline-flex rounded-lg p-0.5 bg-gray-100 dark:bg-neutral-800">
            <button
                type="button"
                wire:click="$set('side', 'customer')"
                class="px-3 py-1.5 text-xs font-medium rounded-md transition-colors {{ $side === 'customer' ? 'bg-white dark:bg-neutral-700 text-gray-900 dark:text-white shadow-xs' : 'text-gray-500 hover:text-gray-900 dark:text-neutral-400 dark:hover:text-white' }}"
            >
                ذمة زبون (لنا)
            </button>
            <button
                type="button"
                wire:click="$set('side', 'vendor')"
                class="px-3 py-1.5 text-xs font-medium rounded-md transition-colors {{ $side === 'vendor' ? 'bg-white dark:bg-neutral-700 text-gray-900 dark:text-white shadow-xs' : 'text-gray-500 hover:text-gray-900 dark:text-neutral-400 dark:hover:text-white' }}"
            >
                ذمة مورد (علينا)
            </button>
        </div>
    </div>

    {{-- Overlap Rejection Banner --}}
    @if ($glOverlapMessage)
        <div class="p-3 bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-800 border-danger/30 rounded-lg text-sm text-red-700 dark:text-red-300 flex items-start gap-2.5">
            <svg class="w-5 h-5 text-red-500 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
            </svg>
            <div class="flex-1">
                <span class="sr-only">GL_OVERLAP_REJECTED</span>
                <p class="font-semibold">{{ $glOverlapMessage }}</p>
                <p class="text-xs text-red-600 dark:text-red-400 mt-1">
                    {{ __('accounting.gl_overlap_advice', [], 'ar') ?: 'يرجى مراجعة وتصفية قيود الأرصدة الافتتاحية المسجلة مسبقاً في الأستاذ العام لتجنب ازدواجية المطالبات.' }}
                </p>
            </div>
        </div>
    @endif

    {{-- Success & Error Messages --}}
    @if ($successMessage)
        <div class="p-3 bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800 rounded-lg text-sm text-emerald-800 dark:text-emerald-200 flex items-center gap-2">
            <svg class="w-4 h-4 text-emerald-600 dark:text-emerald-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
            </svg>
            <span>{{ $successMessage }}</span>
        </div>
    @endif

    @if ($errorMessage)
        <div class="p-3 bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-800 rounded-lg text-sm text-red-700 dark:text-red-300">
            {{ $errorMessage }}
        </div>
    @endif

    {{-- Main Form Card --}}
    <form wire:submit="postOpeningBalance" class="bg-white dark:bg-neutral-900 border border-gray-200 dark:border-neutral-800 rounded-xl p-4 sm:p-5 space-y-4 shadow-xs">
        {{-- Party Selection --}}
        <div>
            <x-input-label for="party_combobox" :value="$side === 'customer' ? 'الزبون *' : 'المورد *'" class="mb-1 text-xs font-medium" />
            <x-searchable-combobox
                id="party_combobox"
                :type="$side === 'customer' ? 'customer' : 'vendor'"
                wireModel="party_id"
                :selectedId="$party_id"
                :selectedLabel="$selectedPartyLabel"
                :placeholder="$side === 'customer' ? 'ابحث بالاسم أو رقم الهاتف...' : 'ابحث باسم المورد...'"
                required
            />
            <x-input-error :messages="$errors->get('party_id')" class="mt-1" />
        </div>

        {{-- Amount & Currency Row --}}
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            {{-- Amount Input with LTR isolation --}}
            <div class="sm:col-span-2">
                <x-input-label for="amount" value="مبلغ الرصيد *" class="mb-1 text-xs font-medium" />
                <div class="relative rounded-md shadow-xs" dir="ltr">
                    <input
                        type="text"
                        inputmode="decimal"
                        id="amount"
                        wire:model.live.debounce.300ms="amount"
                        placeholder="0.00"
                        class="block w-full rounded-md border-gray-300 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white text-base font-semibold tabular-nums px-3 py-2 focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm text-left"
                        required
                    />
                    <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3">
                        <span class="text-xs font-medium text-gray-500 dark:text-neutral-400">{{ $currency_code }}</span>
                    </div>
                </div>
                <x-input-error :messages="$errors->get('amount')" class="mt-1" />
            </div>

            {{-- Currency Selector --}}
            <div>
                <x-input-label for="currency_code" value="العملة *" class="mb-1 text-xs font-medium" />
                <select
                    id="currency_code"
                    wire:model.live="currency_code"
                    class="block w-full rounded-md border-gray-300 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white text-sm py-2 px-3 focus:border-indigo-500 focus:ring-indigo-500"
                >
                    @foreach ($currencies as $curr)
                        <option value="{{ $curr }}">{{ $curr }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('currency_code')" class="mt-1" />
            </div>
        </div>

        {{-- Foreign Currency FX & Converted Base Preview --}}
        @if (! $isBase)
            <div class="p-3 bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800/50 rounded-lg space-y-2">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-amber-800 dark:text-amber-200">
                        سعر الصرف التاريخي المجمد مقابل عملة الأساس ({{ $company->base_currency_code }})
                    </span>
                    @if ($carryingBasePreview)
                        <span class="text-xs font-semibold tabular-nums text-amber-900 dark:text-amber-100" dir="ltr">
                            ≈ {{ $carryingBasePreview }} {{ $company->base_currency_code }}
                        </span>
                    @endif
                </div>
                <div class="w-full sm:w-48" dir="ltr">
                    <input
                        type="text"
                        inputmode="decimal"
                        wire:model.live.debounce.300ms="exchange_rate"
                        placeholder="1.0000000000"
                        class="block w-full rounded-md border-gray-300 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white text-xs font-mono tabular-nums px-2.5 py-1.5 focus:border-indigo-500 focus:ring-indigo-500 text-left"
                    />
                </div>
                <x-input-error :messages="$errors->get('exchange_rate')" class="mt-0.5" />
            </div>
        @endif

        {{-- Collapsed Details Accordion --}}
        <details class="group border border-gray-200 dark:border-neutral-800 rounded-lg text-xs" {{ $original_reference || $due_date ? 'open' : '' }}>
            <summary class="cursor-pointer select-none px-3 py-2.5 font-medium text-gray-700 dark:text-neutral-300 hover:text-gray-900 dark:hover:text-white flex items-center justify-between">
                <span>تفاصيل تاريخية إضافية (اختياري)</span>
                <span class="text-gray-400 group-open:rotate-180 transition-transform">▼</span>
            </summary>
            <div class="p-3 pt-0 border-t border-gray-100 dark:border-neutral-800/60 grid grid-cols-1 sm:grid-cols-3 gap-3 mt-2">
                <div>
                    <label class="block mb-1 text-gray-600 dark:text-neutral-400">تاريخ الرصيد الافتتاحي</label>
                    <input
                        type="date"
                        wire:model="opening_date"
                        class="block w-full rounded-md border-gray-300 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white text-xs py-1.5 px-2.5 focus:border-indigo-500 focus:ring-indigo-500"
                    />
                </div>
                <div>
                    <label class="block mb-1 text-gray-600 dark:text-neutral-400">الرقم المرجعي الأصلي</label>
                    <input
                        type="text"
                        wire:model="original_reference"
                        placeholder="مثال: فاتورة قديمة #1042"
                        class="block w-full rounded-md border-gray-300 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white text-xs py-1.5 px-2.5 focus:border-indigo-500 focus:ring-indigo-500"
                    />
                </div>
                <div>
                    <label class="block mb-1 text-gray-600 dark:text-neutral-400">تاريخ الاستحقاق التعاقدي</label>
                    <input
                        type="date"
                        wire:model="due_date"
                        class="block w-full rounded-md border-gray-300 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white text-xs py-1.5 px-2.5 focus:border-indigo-500 focus:ring-indigo-500"
                    />
                </div>
            </div>
        </details>

        {{-- Additional Lines List --}}
        @if ($has_multiple_lines && count($additional_lines) > 0)
            <div class="space-y-2 border-t border-gray-100 dark:border-neutral-800 pt-3">
                <span class="text-xs font-semibold text-gray-800 dark:text-neutral-200">بنود تاريخية إضافية لنفس الطرف:</span>
                @foreach ($additional_lines as $idx => $line)
                    <div class="flex items-center gap-2 p-2 bg-gray-50 dark:bg-neutral-800/60 rounded-lg text-xs" dir="ltr">
                        <input
                            type="text"
                            inputmode="decimal"
                            wire:model="additional_lines.{{ $idx }}.amount"
                            placeholder="Amount"
                            class="w-32 rounded-md border-gray-300 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white px-2 py-1 text-left tabular-nums"
                        />
                        <span class="text-gray-500">{{ $line['currency_code'] ?? $currency_code }}</span>
                        <input
                            type="text"
                            wire:model="additional_lines.{{ $idx }}.original_reference"
                            placeholder="Ref #"
                            class="flex-1 rounded-md border-gray-300 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white px-2 py-1 text-right"
                            dir="rtl"
                        />
                        <button
                            type="button"
                            wire:click="removeAdditionalLine({{ $idx }})"
                            class="text-red-500 hover:text-red-700 p-1"
                        >
                            ✕
                        </button>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Action Buttons Row --}}
        <div class="flex items-center justify-between pt-2">
            <button
                type="button"
                wire:click="addAdditionalLine"
                class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline font-medium"
            >
                + إضافة بند تاريخي آخر
            </button>

            <button
                type="submit"
                wire:loading.attr="disabled"
                @disabled($glOverlapMessage !== null)
                class="inline-flex items-center justify-center px-5 py-2.5 min-h-[44px] bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed text-white text-sm font-semibold rounded-lg shadow-sm transition-colors focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
            >
                <span wire:loading.remove>تأكيد وقيد الرصيد</span>
                <span wire:loading class="flex items-center gap-1.5">
                    <svg class="animate-spin h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <span>جاري القيد...</span>
                </span>
            </button>
        </div>
    </form>
</div>
