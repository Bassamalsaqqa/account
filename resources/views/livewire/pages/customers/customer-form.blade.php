<div class="max-w-4xl mx-auto space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-text-primary">
                {{ $isEditing ? __('sales.edit_customer') : __('sales.new_customer') }}
            </h1>
            <p class="text-xs text-text-secondary mt-1">
                {{ $isEditing ? $customer->displayName() : __('sales.customer_details') }}
            </p>
        </div>

        <a href="{{ $isEditing ? route('customers.show', $customer->public_id) : route('customers.index') }}"
           class="px-3 py-1.5 rounded-control bg-surface-soft text-text-secondary hover:text-text-primary text-xs font-bold transition-colors">
            {{ __('sales.back') }}
        </a>
    </div>

    <!-- Form Card -->
    <form wire:submit="save" class="bg-white rounded-card border border-border shadow-xs p-6 space-y-6">
        <!-- Basic Info Section -->
        <div>
            <h2 class="text-sm font-bold text-text-primary pb-2 border-b border-border">
                {{ __('sales.customer_details') }}
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                <!-- Name AR -->
                <div>
                    <label class="block text-xs font-bold text-text-primary mb-1">
                        {{ __('sales.name_ar') }} <span class="text-danger">*</span>
                    </label>
                    <input type="text"
                           wire:model="name_ar"
                           required
                           class="w-full h-9 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden" />
                    @error('name_ar') <span class="text-danger text-[11px] mt-1 block">{{ $message }}</span> @enderror
                </div>

                <!-- Name EN -->
                <div>
                    <label class="block text-xs font-bold text-text-primary mb-1">
                        {{ __('sales.name_en') }}
                    </label>
                    <input type="text"
                           wire:model="name_en"
                           class="w-full h-9 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden" />
                    @error('name_en') <span class="text-danger text-[11px] mt-1 block">{{ $message }}</span> @enderror
                </div>

                <!-- Business Name -->
                <div>
                    <label class="block text-xs font-bold text-text-primary mb-1">
                        {{ __('sales.business_name_ar') }}
                    </label>
                    <input type="text"
                           wire:model="business_name"
                           class="w-full h-9 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden" />
                    @error('business_name') <span class="text-danger text-[11px] mt-1 block">{{ $message }}</span> @enderror
                </div>

                <!-- Business Name EN -->
                <div>
                    <label class="block text-xs font-bold text-text-primary mb-1">
                        {{ __('sales.business_name_en') }}
                    </label>
                    <input type="text"
                           wire:model="business_name_en"
                           class="w-full h-9 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden" />
                    @error('business_name_en') <span class="text-danger text-[11px] mt-1 block">{{ $message }}</span> @enderror
                </div>

                <!-- Code -->
                <div>
                    <label class="block text-xs font-bold text-text-primary mb-1">
                        {{ __('sales.code') }}
                    </label>
                    <input type="text"
                           wire:model="code"
                           placeholder="CUST-001"
                           class="w-full h-9 px-3 rounded-control border border-border bg-canvas text-xs font-mono text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden" />
                    @error('code') <span class="text-danger text-[11px] mt-1 block">{{ $message }}</span> @enderror
                </div>

                <!-- Status -->
                <div class="flex items-center gap-3 pt-6">
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" wire:model="active" class="sr-only peer">
                        <div class="w-9 h-5 bg-slate-200 peer-focus:outline-hidden rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-primary"></div>
                        <span class="mr-3 ml-3 text-xs font-bold text-text-primary">{{ __('sales.active') }}</span>
                    </label>
                </div>
            </div>
        </div>

        <!-- Contact & Address Section -->
        <div>
            <h2 class="text-sm font-bold text-text-primary pb-2 border-b border-border">
                {{ __('sales.phone') }} / {{ __('sales.whatsapp') }} / {{ __('sales.email') }}
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
                <div>
                    <label class="block text-xs font-bold text-text-primary mb-1">
                        {{ __('sales.phone') }}
                    </label>
                    <input type="text"
                           wire:model="phone"
                           dir="ltr"
                           class="w-full h-9 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden text-start" />
                    @error('phone') <span class="text-danger text-[11px] mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-bold text-text-primary mb-1">
                        {{ __('sales.whatsapp') }}
                    </label>
                    <input type="text"
                           wire:model="whatsapp"
                           dir="ltr"
                           class="w-full h-9 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden text-start" />
                    @error('whatsapp') <span class="text-danger text-[11px] mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-bold text-text-primary mb-1">
                        {{ __('sales.email') }}
                    </label>
                    <input type="email"
                           wire:model="email"
                           dir="ltr"
                           class="w-full h-9 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden text-start" />
                    @error('email') <span class="text-danger text-[11px] mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div class="md:col-span-3 grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-text-primary mb-1">
                            {{ __('sales.address_ar') }}
                        </label>
                        <textarea wire:model="address_line_1_ar"
                                  rows="2"
                                  class="w-full p-2.5 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden"></textarea>
                        @error('address_line_1_ar') <span class="text-danger text-[11px] mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-text-primary mb-1">
                            {{ __('sales.address_en') }}
                        </label>
                        <textarea wire:model="address_line_1_en"
                                  rows="2"
                                  class="w-full p-2.5 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden"></textarea>
                        @error('address_line_1_en') <span class="text-danger text-[11px] mt-1 block">{{ $message }}</span> @enderror
                    </div>
                </div>
            </div>
        </div>

        <!-- Preferences & Financials Section -->
        <div>
            <h2 class="text-sm font-bold text-text-primary pb-2 border-b border-border">
                {{ __('sales.default_currency') }} & {{ __('sales.credit_limit') }} ({{ $baseCurrency }})
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
                <div>
                    <label class="block text-xs font-bold text-text-primary mb-1">
                        {{ __('sales.default_currency') }} <span class="text-danger">*</span>
                    </label>
                    <select wire:model="default_currency_code"
                            required
                            class="w-full h-9 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden">
                        @foreach ($currencies as $curr)
                            <option value="{{ $curr->currency_code }}">{{ $curr->currency_code }}</option>
                        @endforeach
                    </select>
                    @error('default_currency_code') <span class="text-danger text-[11px] mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-bold text-text-primary mb-1">
                        {{ __('sales.preferred_locale') }} <span class="text-danger">*</span>
                    </label>
                    <select wire:model="preferred_locale"
                            required
                            class="w-full h-9 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden">
                        <option value="ar">العربية (Arabic)</option>
                        <option value="en">English (الإنجليزية)</option>
                    </select>
                    @error('preferred_locale') <span class="text-danger text-[11px] mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-bold text-text-primary mb-1">
                        {{ __('sales.credit_limit') }} ({{ $baseCurrency }})
                    </label>
                    <input type="number"
                           step="0.01"
                           wire:model="credit_limit"
                           dir="ltr"
                           placeholder="0.00"
                           class="w-full h-9 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden text-start font-mono" />
                    @error('credit_limit') <span class="text-danger text-[11px] mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div class="md:col-span-3">
                    <label class="block text-xs font-bold text-text-primary mb-1">
                        {{ __('sales.notes') }}
                    </label>
                    <textarea wire:model="notes"
                              rows="3"
                              class="w-full p-2.5 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden"></textarea>
                    @error('notes') <span class="text-danger text-[11px] mt-1 block">{{ $message }}</span> @enderror
                </div>
            </div>
        </div>

        <!-- Buttons -->
        <div class="flex items-center justify-end gap-3 pt-4 border-t border-border">
            <a href="{{ $isEditing ? route('customers.show', $customer->public_id) : route('customers.index') }}"
               class="px-4 py-2 rounded-control border border-border text-xs font-bold text-text-secondary hover:bg-surface-soft transition-colors">
                {{ __('sales.cancel') }}
            </a>
            <button type="submit"
                    class="px-5 py-2 rounded-control bg-primary text-white text-xs font-bold hover:bg-primary-hover transition-colors shadow-sm cursor-pointer">
                {{ __('sales.confirm') }}
            </button>
        </div>
    </form>
</div>
