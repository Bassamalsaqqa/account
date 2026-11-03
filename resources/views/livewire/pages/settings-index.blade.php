<div class="space-y-6" x-data="{
    searchQuery: '',
    matches(text) {
        if (!this.searchQuery.trim()) return true;
        return text.toLowerCase().includes(this.searchQuery.toLowerCase().trim());
    }
}">

    @if (session('ai_switch_success'))
        <p role="status" class="rounded-control bg-success-bg p-3 text-sm text-success">{{ session('ai_switch_success') }}</p>
    @endif

    <!-- Alerts & Feedback -->
    @if ($successMessage)
        <div class="p-3.5 rounded-card bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs font-bold flex items-center justify-between shadow-xs">
            <div class="flex items-center gap-2">
                <x-icon name="check" class="w-4 h-4 text-emerald-600" />
                <span>{{ $successMessage }}</span>
            </div>
            <button type="button" wire:click="$set('successMessage', null)" class="text-emerald-700 hover:text-emerald-900 font-extrabold text-sm">&times;</button>
        </div>
    @endif

    @if ($errorMessage)
        <div class="p-3.5 rounded-card bg-rose-50 border border-rose-200 text-rose-800 text-xs font-bold flex items-center justify-between shadow-xs">
            <div class="flex items-center gap-2 flex-wrap">
                <x-icon name="warning" class="w-4 h-4 text-rose-600 shrink-0" />
                <span>{{ $errorMessage }}</span>
                @if ($errorMessage === __('settings.password_confirmation_required'))
                    <a href="{{ route('password.confirm') }}" class="underline ms-2 font-bold text-rose-900 hover:text-rose-950">{{ __('settings.confirm_password_action') }} &rarr;</a>
                @endif
            </div>
            <button type="button" wire:click="$set('errorMessage', null)" class="text-rose-700 hover:text-rose-900 font-extrabold text-sm">&times;</button>
        </div>
    @endif

    <x-settings-shell :active-section="$activeSection" :company="$company">

            @if ($activeSection === 'identity')
                <!-- 1. Company Identity Form -->
                <div class="bg-white border border-border rounded-card p-5 sm:p-6 shadow-panel space-y-5">
                    <div class="flex items-center justify-between gap-3 pb-3 border-b border-border">
                        <div>
                            <h2 class="text-base font-extrabold text-text-primary">{{ __('settings.card_company_title') }}</h2>
                        </div>
                        <span class="text-xs font-mono text-text-muted">{{ $company?->displayName() }}</span>
                    </div>

                    <form wire:submit="saveIdentity" class="space-y-4">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-text-secondary mb-1">الاسم التجاري (عربي) *</label>
                                <input type="text" wire:model="name_ar" required
                                       class="w-full text-sm rounded-control border border-border p-2.5 focus:border-primary focus:ring-1 focus:ring-primary" />
                                @error('name_ar') <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-text-secondary mb-1">Company Name (English)</label>
                                <input type="text" wire:model="name_en" dir="ltr"
                                       class="w-full text-sm rounded-control border border-border p-2.5 focus:border-primary focus:ring-1 focus:ring-primary" />
                                @error('name_en') <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-text-secondary mb-1">الاسم القانوني / المسجل (عربي)</label>
                                <input type="text" wire:model="legal_name_ar"
                                       class="w-full text-sm rounded-control border border-border p-2.5 focus:border-primary focus:ring-1 focus:ring-primary" />
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-text-secondary mb-1">Legal Registered Name (English)</label>
                                <input type="text" wire:model="legal_name_en" dir="ltr"
                                       class="w-full text-sm rounded-control border border-border p-2.5 focus:border-primary focus:ring-1 focus:ring-primary" />
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-text-secondary mb-1">{{ __('settings.tax_number') }}</label>
                                <input type="text" wire:model="tax_number" dir="ltr"
                                       class="w-full text-sm rounded-control border border-border p-2.5 focus:border-primary focus:ring-1 focus:ring-primary" />
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-text-secondary mb-1">رقم السجل التجاري</label>
                                <input type="text" wire:model="registration_number" dir="ltr"
                                       class="w-full text-sm rounded-control border border-border p-2.5 focus:border-primary focus:ring-1 focus:ring-primary" />
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-text-secondary mb-1">رقم الهاتف</label>
                                <input type="text" wire:model="phone" dir="ltr"
                                       class="w-full text-sm rounded-control border border-border p-2.5 focus:border-primary focus:ring-1 focus:ring-primary" />
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-text-secondary mb-1">واتساب</label>
                                <input type="text" wire:model="whatsapp" dir="ltr"
                                       class="w-full text-sm rounded-control border border-border p-2.5 focus:border-primary focus:ring-1 focus:ring-primary" />
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-text-secondary mb-1">البريد الإلكتروني</label>
                                <input type="email" wire:model="email" dir="ltr"
                                       class="w-full text-sm rounded-control border border-border p-2.5 focus:border-primary focus:ring-1 focus:ring-primary" />
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-text-secondary mb-1">الموقع الإلكتروني</label>
                                <input type="url" wire:model="website" dir="ltr" placeholder="https://"
                                       class="w-full text-sm rounded-control border border-border p-2.5 focus:border-primary focus:ring-1 focus:ring-primary" />
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-text-secondary mb-1">العنوان (عربي)</label>
                            <textarea wire:model="address_ar" rows="2"
                                      class="w-full text-sm rounded-control border border-border p-2.5 focus:border-primary focus:ring-1 focus:ring-primary"></textarea>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-text-secondary mb-1">Address (English)</label>
                            <textarea wire:model="address_en" dir="ltr" rows="2"
                                      class="w-full text-sm rounded-control border border-border p-2.5 focus:border-primary focus:ring-1 focus:ring-primary"></textarea>
                        </div>

                        <div class="pt-3 border-t border-border flex justify-end">
                            <button type="submit" wire:loading.attr="disabled"
                                    class="px-4 py-2 rounded-control bg-primary text-white text-xs font-bold hover:bg-primary-hover transition-colors shadow-xs cursor-pointer">
                                <span wire:loading.remove wire:target="saveIdentity">{{ __('settings.save_changes') }}</span>
                                <span wire:loading wire:target="saveIdentity">{{ __('settings.saving') }}</span>
                            </button>
                        </div>
                    </form>
                </div>

            @elseif ($activeSection === 'localization')
                <!-- 2. Localization & Timezone Form -->
                <div class="bg-white border border-border rounded-card p-5 sm:p-6 shadow-panel max-w-2xl space-y-5">
                    <div class="flex items-center justify-between gap-3 pb-3 border-b border-border">
                        <div>
                            <h2 class="text-base font-extrabold text-text-primary">{{ __('settings.card_localization_title') }}</h2>
                        </div>
                    </div>

                    <form wire:submit="saveLocalization" class="space-y-4">
                        <div>
                            <label class="block text-xs font-bold text-text-secondary mb-2">{{ __('settings.company_languages') }}</label>
                            <div class="divide-y divide-border border border-border rounded-control p-3 bg-canvas/30 space-y-2">
                                <div class="flex items-center justify-between py-1">
                                    <div class="flex items-center gap-2">
                                        <input type="checkbox" checked disabled class="rounded border-border text-primary opacity-60 w-4 h-4" />
                                        <span class="text-xs font-bold text-text-primary">العربية (Arabic)</span>
                                    </div>
                                    <span class="text-xs font-semibold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded-full border border-emerald-200">
                                        {{ __('settings.always_enabled') }}
                                    </span>
                                </div>

                                <div class="flex items-center justify-between py-1 pt-2">
                                    <div class="flex items-center gap-2">
                                        <input type="checkbox" id="english_enabled" wire:model.live="english_enabled"
                                               class="rounded border-border text-primary focus:ring-primary w-4 h-4 cursor-pointer" />
                                        <label for="english_enabled" class="text-xs font-bold text-text-primary cursor-pointer">
                                            English (الإنجليزية)
                                        </label>
                                    </div>
                                    <span class="text-xs font-semibold {{ $english_enabled ? 'text-primary' : 'text-text-muted' }}">
                                        {{ $english_enabled ? __('settings.status_enabled') : __('settings.status_disabled') }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-text-secondary mb-1">{{ __('settings.default_company_language') }}</label>
                            <select wire:model="default_locale" class="w-full text-sm rounded-control border border-border p-2.5 bg-white">
                                <option value="ar">العربية (Arabic - RTL)</option>
                                @if ($english_enabled)
                                    <option value="en">English (LTR)</option>
                                @endif
                            </select>
                            <x-input-error :messages="$errors->get('default_locale')" class="mt-1" />
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-text-secondary mb-1">{{ __('settings.timezone') }}</label>
                            <select wire:model="timezone" class="w-full text-sm rounded-control border border-border p-2.5 bg-white font-mono text-xs">
                                <option value="Asia/Hebron">Asia/Hebron (القدس / فلسطين - GMT+2/3)</option>
                                <option value="Asia/Jerusalem">Asia/Jerusalem</option>
                                <option value="Asia/Amman">Asia/Amman (الأردن)</option>
                                <option value="Asia/Riyadh">Asia/Riyadh (السعودية)</option>
                                <option value="Asia/Dubai">Asia/Dubai (الإمارات)</option>
                                <option value="UTC">UTC (Universal Coordinated Time)</option>
                            </select>
                            <x-input-error :messages="$errors->get('timezone')" class="mt-1" />
                        </div>

                        <div class="pt-3 border-t border-border flex justify-end">
                            <button type="submit" wire:loading.attr="disabled"
                                    class="px-4 py-2 rounded-control bg-primary text-white text-xs font-bold hover:bg-primary-hover transition-colors shadow-xs cursor-pointer">
                                <span wire:loading.remove wire:target="saveLocalization">{{ __('settings.save_changes') }}</span>
                                <span wire:loading wire:target="saveLocalization">{{ __('settings.saving') }}</span>
                            </button>
                        </div>
                    </form>
                </div>

            @elseif ($activeSection === 'currencies')
                <!-- 3. Currencies Form -->
                <div class="bg-white border border-border rounded-card p-5 sm:p-6 shadow-panel max-w-2xl space-y-5">
                    <div class="flex items-center justify-between gap-3 pb-3 border-b border-border">
                        <div>
                            <h2 class="text-base font-extrabold text-text-primary">{{ __('settings.card_currencies_title') }}</h2>
                        </div>
                    </div>

                    @if ($isBaseCurrencyLocked)
                        <div class="p-3 bg-amber-50 border border-amber-200 rounded-control flex items-start gap-2.5 text-amber-800 text-xs">
                            <x-icon name="lock" class="w-4 h-4 text-amber-600 shrink-0 mt-0.5" />
                            <div>
                                <span class="font-bold">{{ __('settings.base_currency_locked_notice') }}</span>
                                <p class="text-xs text-amber-700 mt-0.5">{{ __('settings.base_currency_locked_desc') }}</p>
                            </div>
                        </div>
                    @endif

                    <form wire:submit="saveCurrencies" class="space-y-4">
                        <div class="divide-y divide-border">
                            @foreach (['ILS' => ['name' => 'الشيكل الإسرائيلي (ILS)', 'symbol' => '₪'], 'USD' => ['name' => 'الدولار الأمريكي (USD)', 'symbol' => '$'], 'JOD' => ['name' => 'الدينار الأردني (JOD)', 'symbol' => 'د.أ']] as $code => $info)
                                <div class="py-3 flex items-center justify-between gap-3">
                                    <div class="flex items-center gap-3">
                                        <input type="checkbox" id="curr_{{ $code }}"
                                               wire:model="currencies_enabled.{{ $code }}"
                                               {{ $base_currency === $code ? 'disabled checked' : '' }}
                                               class="rounded border-border text-primary focus:ring-primary w-4 h-4 cursor-pointer" />
                                        <label for="curr_{{ $code }}" class="text-xs font-bold text-text-primary cursor-pointer">
                                             {{ $info['name'] }}
                                            <span class="font-mono text-text-muted">({{ $info['symbol'] }})</span>
                                        </label>
                                    </div>

                                    <div class="flex items-center gap-2">
                                        <input type="radio" id="base_{{ $code }}" name="base_curr" value="{{ $code }}"
                                               wire:model.live="base_currency"
                                               {{ $isBaseCurrencyLocked ? 'disabled' : '' }}
                                               class="text-primary focus:ring-primary w-4 h-4 {{ $isBaseCurrencyLocked ? 'cursor-not-allowed opacity-60' : 'cursor-pointer' }}" />
                                        <label for="base_{{ $code }}" class="text-xs font-semibold text-text-secondary {{ $isBaseCurrencyLocked ? 'cursor-not-allowed opacity-60' : 'cursor-pointer' }}">
                                            {{ __('settings.status_base_currency') }}
                                        </label>
                                        @if ($isBaseCurrencyLocked && $base_currency === $code)
                                            <span class="text-xs px-1.5 py-0.5 rounded bg-slate-100 text-text-muted font-bold">
                                                {{ __('settings.base_currency_locked_badge') }}
                                            </span>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <div class="pt-3 border-t border-border flex justify-end">
                            <button type="submit" wire:loading.attr="disabled"
                                    class="px-4 py-2 rounded-control bg-primary text-white text-xs font-bold hover:bg-primary-hover transition-colors shadow-xs cursor-pointer">
                                <span wire:loading.remove wire:target="saveCurrencies">{{ __('settings.save_changes') }}</span>
                                <span wire:loading wire:target="saveCurrencies">{{ __('settings.saving') }}</span>
                            </button>
                        </div>
                    </form>
                </div>

            @elseif ($activeSection === 'security')
                <!-- 4. Security Policies Form -->
                <div class="bg-white border border-border rounded-card p-5 sm:p-6 shadow-panel max-w-2xl space-y-5">
                    <div class="flex items-center justify-between gap-3 pb-3 border-b border-border">
                        <div>
                            <h2 class="text-base font-extrabold text-text-primary">{{ __('settings.card_security_title') }}</h2>
                        </div>
                    </div>

                    <form wire:submit="saveSecurity" class="space-y-4">
                        <div class="space-y-3">
                            <p class="text-sm text-text-secondary bg-surface-soft rounded-control p-3" role="note">{{ __('settings.optional_2fa_help') }}</p>
                            <div>
                                <label for="public-share-expiry" class="block text-xs font-bold text-text-secondary mb-1">{{ __('settings.public_share_expiry_label') }}</label>
                                <input id="public-share-expiry" type="number" min="1" max="365" wire:model="public_share_default_expiry_days"
                                       @error('public_share_default_expiry_days') aria-invalid="true" aria-describedby="public-share-expiry-error" @enderror
                                       class="w-full text-sm rounded-control border border-border p-2.5 focus:border-primary focus:ring-1 focus:ring-primary num" />
                                @error('public_share_default_expiry_days')<p id="public-share-expiry-error" role="alert" class="mt-1 text-xs text-danger">{{ $message }}</p>@enderror
                            </div>
                        </div>

                        <div class="p-3.5 rounded-card bg-surface-soft border border-border/80 flex items-center justify-between gap-3 text-xs">
                            <div class="flex items-center gap-2.5">
                                <div class="w-8 h-8 rounded-control bg-white border border-border/60 text-text-secondary grid place-items-center shrink-0">
                                    <x-icon name="lock" class="w-4 h-4 text-primary" />
                                </div>
                                <div>
                                    <span class="font-bold text-text-primary block">{{ __('settings.card_security_title') }}</span>
                                    <span class="text-xs text-text-muted">{{ __('settings.link_personal_2fa') }}</span>
                                </div>
                            </div>
                            <a href="{{ route('profile') }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-control bg-white hover:bg-surface-soft border border-border text-xs font-bold text-text-primary shadow-2xs transition">
                                <span>{{ __('settings.go_to_profile_security') }}</span>
                                <x-icon name="chevron" class="w-3 h-3 rtl:rotate-180 text-text-muted" />
                            </a>
                        </div>

                        <div class="pt-3 border-t border-border flex justify-end">
                            <button type="submit" wire:loading.attr="disabled"
                                    class="px-4 py-2 rounded-control bg-primary text-white text-xs font-bold hover:bg-primary-hover transition-colors shadow-xs cursor-pointer">
                                <span wire:loading.remove wire:target="saveSecurity">{{ __('settings.save_changes') }}</span>
                                <span wire:loading wire:target="saveSecurity">{{ __('settings.saving') }}</span>
                            </button>
                        </div>
                    </form>

                    @if ($hasCompany && $canManageAi)
                        <!-- Operational AI Control -->
                        <div id="company-ai-control" class="pt-4 border-t border-border space-y-3">
                            <div class="flex items-center justify-between gap-3">
                                <div class="flex items-center gap-2.5">
                                    <div class="w-8 h-8 rounded-control bg-primary-50 text-primary grid place-items-center shrink-0">
                                        <x-icon name="sparkles" class="w-4 h-4" />
                                    </div>
                                    <div>
                                        <h3 class="text-xs font-bold text-text-primary">{{ __('settings.card_ai_title') }}</h3>
                                        <div class="text-xs text-text-muted flex items-center gap-2 mt-0.5">
                                            @if ($aiStatus === 'enabled')
                                                <span class="inline-block px-1.5 py-0.5 rounded bg-emerald-100 text-emerald-800 text-xs font-bold">
                                                    {{ __('settings.ai_status_enabled') }}
                                                </span>
                                            @elseif ($aiStatus === 'disabled')
                                                <span class="inline-block px-1.5 py-0.5 rounded bg-amber-100 text-amber-800 text-xs font-bold">
                                                    {{ __('settings.ai_status_disabled') }}
                                                </span>
                                            @elseif ($aiStatus === 'not_configured')
                                                <span class="inline-block px-1.5 py-0.5 rounded bg-slate-100 text-slate-700 text-xs font-bold">
                                                    {{ __('settings.ai_status_not_configured') }}
                                                </span>
                                            @else
                                                <span class="inline-block px-1.5 py-0.5 rounded bg-slate-100 text-slate-500 text-xs font-bold">
                                                    {{ __('settings.ai_status_globally_disabled') }}
                                                </span>
                                            @endif
                                            @if (auth()->user()->hasPermissionTo('ai.settings.manage'))
                                                <button type="button" wire:click="setSection('ai')" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-control bg-white hover:bg-surface-soft border border-border text-xs font-bold text-primary shadow-2xs transition cursor-pointer">
                                                    <span>{{ __('assistant_v2.settings_title') }}</span>
                                                    <span class="rtl:rotate-180">&rarr;</span>
                                                </button>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </div>

                            @if ($isCompanyOwner && $aiStatus !== 'globally_disabled')
                                <div class="p-3 rounded-control bg-surface-soft border border-border text-xs" x-data="{ openToggle: false, password: '', busy: false }">
                                    @if ($aiStatus === 'enabled')
                                        <div class="flex items-center justify-between">
                                            <span class="text-text-secondary">{{ __('settings.ai_switch_help') }}</span>
                                            <button type="button" @click="openToggle = !openToggle" class="min-h-9 px-3 py-1 rounded-control bg-rose-50 text-rose-700 hover:bg-rose-100 font-bold border border-rose-200 cursor-pointer">
                                                {{ __('settings.ai_switch_disable') }}
                                            </button>
                                        </div>
                                        <div x-show="openToggle" x-cloak class="mt-3 p-3 rounded-control bg-white border border-rose-200 space-y-2">
                                            <label class="block">
                                                <span class="text-xs text-rose-800 font-bold">{{ __('assistant_v2.current_password') }}</span>
                                                <input type="password" x-model="password" autocomplete="current-password" class="mt-1 block w-full rounded-control border border-border bg-white px-2.5 py-1.5 text-xs text-text-primary" />
                                            </label>
                                            <div class="flex items-center gap-2 pt-1">
                                                <button type="button" :disabled="busy || !password" @click="busy = true; const p = $wire.disableCompanyAi(password); password = ''; openToggle = false; p.finally(() => busy = false);" class="min-h-9 px-3 py-1.5 bg-rose-600 text-white rounded-control text-xs font-bold hover:bg-rose-700 disabled:opacity-50 cursor-pointer">
                                                    {{ __('settings.ai_switch_disable') }}
                                                </button>
                                                <button type="button" @click="openToggle = false; password = '';" class="min-h-9 px-2 text-text-secondary text-xs hover:text-text-primary cursor-pointer">
                                                    {{ __('inventory.cancel') }}
                                                </button>
                                            </div>
                                        </div>
                                    @elseif ($aiStatus === 'disabled')
                                        @if ($aiNeedsTransition)
                                            <a href="{{ route('settings.index', ['section' => 'ai']) }}" class="text-primary hover:underline font-bold text-xs">
                                                {{ __('ai_product.transition_action') }} &rarr;
                                            </a>
                                        @else
                                            <div class="flex items-center justify-between">
                                                <span class="text-text-secondary">{{ __('settings.ai_switch_help') }}</span>
                                                <button type="button" @click="openToggle = !openToggle" class="min-h-9 px-3 py-1 rounded-control bg-emerald-50 text-emerald-700 hover:bg-emerald-100 font-bold border border-emerald-200 cursor-pointer">
                                                    {{ __('settings.ai_switch_enable') }}
                                                </button>
                                            </div>
                                            <div x-show="openToggle" x-cloak class="mt-3 p-3 rounded-control bg-white border border-emerald-200 space-y-2">
                                                <label class="block">
                                                    <span class="text-xs text-emerald-800 font-bold">{{ __('assistant_v2.current_password') }}</span>
                                                    <input type="password" x-model="password" autocomplete="current-password" class="mt-1 block w-full rounded-control border border-border bg-white px-2.5 py-1.5 text-xs text-text-primary" />
                                                </label>
                                                <div class="flex items-center gap-2 pt-1">
                                                    <button type="button" :disabled="busy || !password" @click="busy = true; const p = $wire.enableCompanyAi(password); password = ''; openToggle = false; p.finally(() => busy = false);" class="min-h-9 px-3 py-1.5 bg-emerald-600 text-white rounded-control text-xs font-bold hover:bg-emerald-700 disabled:opacity-50 cursor-pointer">
                                                        {{ __('settings.ai_switch_enable') }}
                                                    </button>
                                                    <button type="button" @click="openToggle = false; password = '';" class="min-h-9 px-2 text-text-secondary text-xs hover:text-text-primary cursor-pointer">
                                                        {{ __('inventory.cancel') }}
                                                    </button>
                                                </div>
                                            </div>
                                        @endif
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endif

                    @if ($isCompanyOwner)
                        <!-- Company Data Export -->
                        <div class="pt-4 border-t border-border flex items-center justify-between gap-3 text-xs">
                            <div>
                                <h4 id="company-data-title" class="font-bold text-text-primary">{{ __('settings.company_data_title') }}</h4>
                                <p class="text-xs text-text-muted mt-0.5">{{ __('settings.company_data_desc') }}</p>
                            </div>
                            <button type="button" wire:click="prepareCompanyDataDownload"
                                    class="min-h-9 px-3 py-1.5 rounded-control bg-surface-soft border border-border text-xs font-bold text-text-primary hover:bg-white shadow-xs cursor-pointer shrink-0">
                                {{ __('settings.company_data_download') }}
                            </button>
                        </div>
                    @endif
                </div>

            @elseif ($activeSection === 'users')
                <!-- 5. Users Administration View -->
                <div class="space-y-6 max-w-5xl">
                    <!-- Members List Table -->
                    <div class="bg-white border border-border rounded-card p-5 sm:p-6 shadow-panel space-y-4">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <h2 class="text-base font-extrabold text-text-primary">{{ __('settings.card_users_title') }}</h2>
                            </div>
                            <span class="text-xs font-bold text-text-secondary num">
                                {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayQuantity($memberships->count()) }} {{ __('settings.usage_users') }}
                            </span>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="data-table w-full text-xs text-start">
                                <thead>
                                    <tr class="border-b border-border text-text-muted text-xs">
                                        <th class="py-2.5 px-3 font-bold text-start">{{ __('settings.user_name') }}</th>
                                        <th class="py-2.5 px-3 font-bold text-start">{{ __('settings.user_email') }}</th>
                                        <th class="py-2.5 px-3 font-bold text-start">{{ __('settings.user_role') }}</th>
                                        <th class="py-2.5 px-3 font-bold text-start">{{ __('settings.user_status') }}</th>
                                        <th class="py-2.5 px-3 font-bold text-start">{{ __('settings.joined_at') }}</th>
                                        <th class="py-2.5 px-3 font-bold text-end">{{ __('settings.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border/60">
                                    @foreach ($memberships as $m)
                                        @php
                                            $u = $m->user;
                                            $userRole = $u->roles->first();
                                        @endphp
                                        <tr class="hover:bg-surface-soft/60 transition-colors">
                                            <td class="py-3 px-3 font-bold text-text-primary">
                                                <div class="flex items-center gap-2">
                                                    <span>{{ $u->name }}</span>
                                                    @if ($m->is_owner)
                                                        <span class="px-1.5 py-0.5 rounded bg-amber-100 text-amber-800 text-xs font-bold">
                                                            {{ __('settings.role_owner') }}
                                                        </span>
                                                    @endif
                                                </div>
                                            </td>
                                            <td class="py-3 px-3 font-mono text-xs text-text-secondary">{{ $u->email }}</td>
                                            <td class="py-3 px-3">
                                                @if ($m->is_owner)
                                                    <span class="font-bold text-text-primary">{{ __('settings.role_owner') }}</span>
                                                @elseif (auth()->user()?->can('settings.users.manage'))
                                                    <select wire:change="updateUserRole({{ $m->id }}, $event.target.value)"
                                                            class="text-xs py-1 px-2 rounded border border-border bg-white font-medium">
                                                        @foreach (['Administrator', 'Manager', 'Sales', 'Purchasing', 'Warehouse', 'Cashier', 'Viewer'] as $rName)
                                                            <option value="{{ $rName }}" {{ $userRole?->name === $rName ? 'selected' : '' }}>
                                                                {{ __('settings.roles_labels.'.$rName) }}
                                                            </option>
                                                        @endforeach
                                                    </select>
                                                @else
                                                    <span class="text-xs font-medium text-text-secondary">{{ $userRole ? (\Illuminate\Support\Facades\Lang::has('settings.roles_labels.'.$userRole->name) ? __('settings.roles_labels.'.$userRole->name) : $userRole->name) : '—' }}</span>
                                                @endif
                                            </td>
                                            <td class="py-3 px-3">
                                                <x-badge :variant="$m->status === 'active' ? 'success' : 'neutral'">
                                                    {{ $m->status === 'active' ? __('settings.status_active') : __('settings.status_inactive') }}
                                                </x-badge>
                                            </td>
                                            <td class="py-3 px-3 text-xs text-text-muted num">
                                                {{ $m->joined_at ? $m->joined_at->format('Y-m-d') : '-' }}
                                            </td>
                                            <td class="table-actions py-3 px-3 text-end">
                                                @can('settings.users.manage')
                                                    @if (! $m->is_owner || $memberships->where('is_owner', true)->count() > 1)
                                                        <button type="button" wire:loading.attr="disabled" wire:target="toggleUserStatus" wire:click="toggleUserStatus({{ $m->id }})"
                                                                class="min-h-11 px-3 rounded-control border border-border text-xs font-bold text-primary hover:bg-primary-50 cursor-pointer">
                                                            {{ $m->status === 'active' ? __('settings.status_inactive') : __('settings.status_active') }}
                                                        </button>
                                                    @endif
                                                @else
                                                    <span class="text-text-muted text-xs">—</span>
                                                @endcan
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Add New User Form -->
                    @can('settings.users.manage')
                    <div class="bg-white border border-border rounded-card p-5 sm:p-6 shadow-panel space-y-4">
                        <h3 class="text-sm font-extrabold text-text-primary">{{ __('settings.add_new_user') }}</h3>
                        <form wire:submit="createUser" class="space-y-4">
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                                <div>
                                    <label class="block text-xs font-bold text-text-secondary mb-1">{{ __('settings.user_name') }} *</label>
                                    <input type="text" wire:model="new_user_name" required
                                           class="w-full text-sm rounded-control border border-border p-2.5 focus:border-primary focus:ring-1 focus:ring-primary" />
                                    @error('new_user_name') <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-text-secondary mb-1">{{ __('settings.user_email') }} *</label>
                                    <input type="email" wire:model="new_user_email" required dir="ltr"
                                           class="w-full text-sm rounded-control border border-border p-2.5 focus:border-primary focus:ring-1 focus:ring-primary" />
                                    @error('new_user_email') <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-text-secondary mb-1">{{ __('settings.user_password') }} *</label>
                                    <input type="password" wire:model="new_user_password" required dir="ltr"
                                           class="w-full text-sm rounded-control border border-border p-2.5 focus:border-primary focus:ring-1 focus:ring-primary" />
                                    @error('new_user_password') <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-text-secondary mb-1">{{ __('settings.user_role') }} *</label>
                                    <select wire:model="new_user_role" class="w-full text-sm rounded-control border border-border p-2.5 bg-white">
                                        @foreach (['Administrator', 'Manager', 'Sales', 'Purchasing', 'Warehouse', 'Cashier', 'Viewer'] as $roleOption)
                                            <option value="{{ $roleOption }}">{{ $roleOption }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-text-secondary mb-1">{{ __('settings.user_language') }}</label>
                                    <select wire:model="new_user_locale" class="w-full text-sm rounded-control border border-border p-2.5 bg-white">
                                        <option value="ar">العربية</option>
                                        <option value="en">English</option>
                                    </select>
                                </div>
                            </div>

                            <div class="pt-2 flex justify-end">
                                <button type="submit" wire:loading.attr="disabled"
                                        class="px-4 py-2 rounded-control bg-primary text-white text-xs font-bold hover:bg-primary-hover transition-colors shadow-xs cursor-pointer">
                                    <span wire:loading.remove wire:target="createUser">{{ __('settings.add_new_user') }}</span>
                                    <span wire:loading wire:target="createUser">{{ __('settings.saving') }}</span>
                                </button>
                            </div>
                        </form>
                    </div>
                    @endcan
                </div>

            @elseif ($activeSection === 'roles')
                <!-- 6. Roles & Permissions View -->
                <div class="space-y-5 max-w-5xl">
                    <div class="bg-white border border-border rounded-card p-5 sm:p-6 shadow-panel space-y-4">
                        <div class="flex items-center justify-between gap-3 pb-3 border-b border-border">
                            <div>
                                <h2 class="text-base font-extrabold text-text-primary">{{ __('settings.card_roles_title') }}</h2>
                            </div>
                        </div>

                        <!-- Role Selector Pills -->
                        <div class="flex flex-wrap gap-2 pt-2">
                            @foreach ($roles as $role)
                                <button type="button" wire:click="selectRole('{{ $role->name }}')"
                                        class="min-h-11 px-3 py-2 rounded-control text-xs font-bold transition-all cursor-pointer
                                               {{ $selectedRoleName === $role->name ? 'bg-primary text-white shadow-xs' : 'bg-surface-soft text-text-secondary hover:bg-slate-200' }}">
                                    {{ \Illuminate\Support\Facades\Lang::has('settings.roles_labels.'.$role->name) ? __('settings.roles_labels.'.$role->name) : $role->name }}
                                    @if ($role->name === 'Owner')
                                        <span class="text-xs opacity-80">({{ __('settings.role_owner') }})</span>
                                    @endif
                                </button>
                            @endforeach
                        </div>

                        @if ($selectedRoleName === 'Owner')
                            <div class="p-3.5 rounded-card bg-amber-50 border border-amber-200 text-amber-800 text-xs">
                                <b>{{ __('settings.protected_role_notice') }}</b>
                            </div>
                        @endif

                        <!-- Grouped Permissions List -->
                        <div class="mt-4 pt-4 border-t border-border space-y-4">
                            <h3 class="text-xs font-extrabold text-text-primary">
                                {{ __('settings.role_permissions_for') }} <span class="text-primary font-mono">{{ $selectedRoleName }}</span>
                            </h3>

                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                @php
                                    $groups = [
                                        __('settings.perm_group_settings') => $allPermissions->filter(fn($p) => str_starts_with($p->name, 'settings.') || str_starts_with($p->name, 'audit.')),
                                        __('settings.perm_group_sales') => $allPermissions->filter(fn($p) => str_starts_with($p->name, 'sales.')),
                                        __('settings.perm_group_purchasing') => $allPermissions->filter(fn($p) => str_starts_with($p->name, 'purchasing.')),
                                        __('settings.perm_group_inventory') => $allPermissions->filter(fn($p) => str_starts_with($p->name, 'inventory.')),
                                        __('settings.perm_group_parties') => $allPermissions->filter(fn($p) => str_starts_with($p->name, 'customers.') || str_starts_with($p->name, 'vendors.')),
                                        __('settings.perm_group_money_reports') => $allPermissions->filter(fn($p) => str_starts_with($p->name, 'money.') || str_starts_with($p->name, 'reports.')),
                                    ];
                                @endphp

                                @foreach ($groups as $groupTitle => $groupPerms)
                                    @if ($groupPerms->isNotEmpty())
                                        <div class="p-3 rounded-card bg-surface-soft/60 border border-border space-y-2">
                                            <h4 class="text-xs font-extrabold text-text-secondary uppercase tracking-wider">{{ $groupTitle }}</h4>
                                            <div class="space-y-1.5">
                                                @foreach ($groupPerms as $perm)
                                                    <label class="flex items-center justify-between gap-2 text-xs text-text-secondary cursor-pointer hover:text-text-primary">
                                                        <span class="font-mono text-xs truncate" title="{{ $perm->name }}">{{ $perm->name }}</span>
                                                        <input type="checkbox"
                                                               {{ !empty($rolePermissions[$perm->name]) ? 'checked' : '' }}
                                                               {{ $selectedRoleName === 'Owner' ? 'disabled' : '' }}
                                                               wire:loading.attr="disabled" wire:target="toggleRolePermission" wire:click="toggleRolePermission('{{ $perm->name }}')"
                                                               class="rounded border-border text-primary focus:ring-primary w-3.5 h-3.5" />
                                                    </label>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>

            @elseif ($activeSection === 'audit')
                <!-- 7. Audit Log View -->
                <div class="bg-white border border-border rounded-card p-5 sm:p-6 shadow-panel space-y-4 max-w-5xl">
                    <div class="flex items-center justify-between gap-3 pb-3 border-b border-border">
                        <div>
                            <h2 class="text-base font-extrabold text-text-primary">{{ __('settings.card_audit_title') }}</h2>
                        </div>
                    </div>

                    <form wire:submit="applyAuditFilters" class="grid grid-cols-1 sm:grid-cols-4 gap-3">
                        <label class="text-xs font-semibold">{{ __('settings.audit_from') }}<input type="date" wire:model="auditFrom" class="mt-1 w-full min-h-11 rounded-control border-border" />@error('auditFrom') <span role="alert" class="text-rose-700">{{ $message }}</span> @enderror</label>
                        <label class="text-xs font-semibold">{{ __('settings.audit_until') }}<input type="date" wire:model="auditUntil" class="mt-1 w-full min-h-11 rounded-control border-border" />@error('auditUntil') <span role="alert" class="text-rose-700">{{ $message }}</span> @enderror</label>
                        <label class="text-xs font-semibold">{{ __('settings.audit_event_prefix') }}<input type="text" wire:model="auditEvent" maxlength="80" dir="ltr" class="mt-1 w-full min-h-11 rounded-control border-border" />@error('auditEvent') <span role="alert" class="text-rose-700">{{ $message }}</span> @enderror</label>
                        <button type="submit" wire:loading.attr="disabled" class="min-h-11 self-end rounded-control border border-primary px-3 py-2 font-bold text-primary">{{ __('settings.audit_apply') }}</button>
                    </form>
                    <div class="lg:hidden space-y-3">
                        @forelse ($auditEvents as $event)
                            <article class="rounded-control border border-border p-3 space-y-2 text-sm" wire:key="audit-card-{{ $event->id }}">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <span class="font-bold text-text-primary break-words">{{ $event->actor?->name ?? __('settings.actor_system') }}</span>
                                    <time class="font-mono text-xs text-text-muted" dir="ltr">{{ $event->created_at->format('Y-m-d H:i') }}</time>
                                </div>
                                <p class="font-mono text-xs text-primary break-all" dir="ltr">{{ $event->event_key }}</p>
                                <p class="text-text-secondary break-words">{{ $event->summary }}</p>
                            </article>
                        @empty
                            <p class="py-6 text-center text-text-muted">{{ __('settings.no_audit_events') }}</p>
                        @endforelse
                    </div>
                    <div class="hidden lg:block overflow-x-auto">
                        <table class="data-table w-full text-xs text-start">
                            <thead>
                                <tr class="border-b border-border text-text-muted text-xs">
                                    <th class="py-2.5 px-3 font-bold text-start">{{ __('settings.audit_occurred_at') }}</th>
                                    <th class="py-2.5 px-3 font-bold text-start">{{ __('settings.user_name') }}</th>
                                    <th class="py-2.5 px-3 font-bold text-start">{{ __('settings.audit_event') }}</th>
                                    <th class="py-2.5 px-3 font-bold text-start">{{ __('settings.audit_details') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border/60">
                                @forelse ($auditEvents as $event)
                                    <tr class="hover:bg-surface-soft/60 transition-colors">
                                        <td class="py-3 px-3 font-mono text-xs text-text-muted whitespace-nowrap">
                                            {{ $event->created_at->format('Y-m-d H:i') }}
                                        </td>
                                        <td class="py-3 px-3 font-bold text-text-primary">
                                            {{ $event->actor?->name ?? __('settings.actor_system') }}
                                        </td>
                                        <td class="py-3 px-3 font-mono text-xs text-primary">
                                            {{ $event->event_key }}
                                        </td>
                                        <td class="py-3 px-3 text-text-secondary">
                                            {{ $event->summary }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="py-6 text-center text-text-muted">
                                            {{ __('settings.no_audit_events') }}
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <x-table-pagination :paginator="$auditEvents" />
                </div>
            @elseif ($activeSection === 'ai')
                <div class="bg-white border border-border rounded-card p-5 sm:p-6 shadow-panel space-y-6">
                    <div class="border-b border-border pb-3 flex items-center justify-between gap-3">
                        <div>
                            <h2 class="text-base font-extrabold text-text-primary">{{ __('assistant_v2.settings_title') }}</h2>
                        </div>
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold bg-primary-50 text-primary border border-primary/20">
                            <x-icon name="sparkles" class="w-3.5 h-3.5" />
                            <span>{{ __('settings.card_ai_title') }}</span>
                        </span>
                    </div>

                    @if ($hasCompany && $canManageAi)
                        <!-- AI Assistant & Master Switch Card -->
                        <div id="company-ai-control"
                             class="bg-surface-soft border border-border rounded-card p-4 sm:p-5 shadow-panel transition-all flex flex-col justify-between gap-3 group focus-visible:outline-2 focus-visible:outline-primary">
                            <div class="flex items-start justify-between gap-3 min-w-0">
                                <div class="flex items-start gap-3 min-w-0">
                                    <div class="w-9 h-9 rounded-control bg-primary-50 text-primary grid place-items-center shrink-0">
                                        <x-icon name="sparkles" class="w-5 h-5" />
                                    </div>
                                    <div class="min-w-0">
                                        <h4 class="text-base font-bold text-text-primary">
                                            {{ __('settings.card_ai_title') }}
                                        </h4>
                                        <p class="text-sm text-text-muted mt-0.5 leading-snug">
                                            {{ __('settings.card_ai_desc') }}
                                        </p>
                                        <div class="mt-2 flex items-center gap-2">
                                            @if ($aiStatus === 'enabled')
                                                <span class="inline-block px-2.5 py-1 rounded-md bg-emerald-100 text-emerald-800 text-xs font-bold">
                                                    {{ __('settings.ai_status_enabled') }}
                                                </span>
                                            @elseif ($aiStatus === 'disabled')
                                                <span class="inline-block px-2.5 py-1 rounded-md bg-amber-100 text-amber-800 text-xs font-bold">
                                                    {{ __('settings.ai_status_disabled') }}
                                                </span>
                                            @elseif ($aiStatus === 'not_configured')
                                                <span class="inline-block px-2.5 py-1 rounded-md bg-slate-100 text-slate-700 text-xs font-bold">
                                                    {{ __('settings.ai_status_not_configured') }}
                                                </span>
                                            @else
                                                <span class="inline-block px-2.5 py-1 rounded-md bg-slate-100 text-slate-500 text-xs font-bold">
                                                    {{ __('settings.ai_status_globally_disabled') }}
                                                </span>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </div>

                            @if ($isCompanyOwner && $aiStatus !== 'globally_disabled')
                                <div class="pt-2 border-t border-border/60 flex flex-wrap items-center justify-between gap-2 text-xs" x-data="{ openToggle: false, password: '', busy: false }">
                                    @if ($aiStatus === 'enabled')
                                        <button type="button" @click="openToggle = !openToggle" class="min-h-11 inline-flex items-center text-rose-600 hover:text-rose-700 font-bold text-sm underline cursor-pointer">
                                            {{ __('settings.ai_switch_disable') }}
                                        </button>
                                        <div x-show="openToggle" x-cloak class="w-full mt-2 p-2.5 rounded-control bg-rose-50 border border-rose-200 text-xs space-y-2">
                                            <p class="text-rose-900 font-semibold">{{ __('settings.ai_switch_help') }}</p>
                                            <label class="block">
                                                <span class="text-sm text-rose-800 font-bold">{{ __('assistant_v2.current_password') }}</span>
                                                <input type="password" x-model="password" autocomplete="current-password" class="mt-1 block w-full rounded border border-rose-300 bg-white px-2 py-1 text-xs text-text-primary" />
                                            </label>
                                            <div class="flex items-center gap-2">
                                                <button type="button" :disabled="busy || !password" @click="busy = true; const p = $wire.disableCompanyAi(password); password = ''; openToggle = false; p.finally(() => busy = false);" class="min-h-11 px-4 py-2 bg-rose-600 text-white rounded text-xs font-bold hover:bg-rose-700 disabled:opacity-50 cursor-pointer">
                                                    {{ __('settings.ai_switch_disable') }}
                                                </button>
                                                <button type="button" @click="openToggle = false; password = '';" class="min-h-11 px-3 text-text-secondary text-sm hover:text-text-primary cursor-pointer">
                                                    {{ __('inventory.cancel') }}
                                                </button>
                                            </div>
                                        </div>
                                    @elseif ($aiStatus === 'disabled')
                                        @if ($aiNeedsTransition)
                                            <a href="{{ route('settings.index', ['section' => 'ai']) }}" class="text-primary hover:underline font-bold text-sm">
                                                {{ __('ai_product.transition_action') }} &rarr;
                                            </a>
                                        @else
                                            <button type="button" @click="openToggle = !openToggle" class="min-h-11 inline-flex items-center text-emerald-700 hover:text-emerald-800 font-bold text-sm underline cursor-pointer">
                                                {{ __('settings.ai_switch_enable') }}
                                            </button>
                                            <div x-show="openToggle" x-cloak class="w-full mt-2 p-2.5 rounded-control bg-emerald-50 border border-emerald-200 text-xs space-y-2">
                                                <label class="block">
                                                    <span class="text-sm text-emerald-900 font-bold">{{ __('assistant_v2.current_password') }}</span>
                                                    <input type="password" x-model="password" autocomplete="current-password" class="mt-1 block w-full rounded border border-emerald-300 bg-white px-2 py-1 text-xs text-text-primary" />
                                                </label>
                                                <div class="flex items-center gap-2">
                                                    <button type="button" :disabled="busy || !password" @click="busy = true; const p = $wire.enableCompanyAi(password); password = ''; openToggle = false; p.finally(() => busy = false);" class="min-h-11 px-4 py-2 bg-emerald-700 text-white rounded text-xs font-bold hover:bg-emerald-800 disabled:opacity-50 cursor-pointer">
                                                        {{ __('settings.ai_switch_enable') }}
                                                    </button>
                                                    <button type="button" @click="openToggle = false; password = '';" class="min-h-11 px-3 text-text-secondary text-sm hover:text-text-primary cursor-pointer">
                                                        {{ __('inventory.cancel') }}
                                                    </button>
                                                </div>
                                            </div>
                                        @endif
                                    @else
                                        <a href="{{ route('settings.index', ['section' => 'ai']) }}" class="text-primary hover:underline font-bold text-sm">
                                            {{ __('ai_product.setup') }} &rarr;
                                        </a>
                                    @endif
                                </div>
                            @elseif ($aiStatus === 'globally_disabled')
                                <div class="pt-2 border-t border-border/60 text-xs text-text-muted">
                                    {{ __('settings.ai_switch_globally_unavailable_notice') }}
                                </div>
                            @endif
                        </div>
                    @endif

                    <livewire:ai.ai-settings :key="'settings-ai-pane-'.$settingsCompanyId" />
                </div>
            @elseif ($activeSection === 'documents')
                <livewire:pages.sales.settings.document-settings-form :key="'settings-documents-pane-'.$settingsCompanyId" />
            @endif

    </x-settings-shell>

</div>
