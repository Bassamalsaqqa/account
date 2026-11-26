<div class="space-y-6">

    @if (session('ai_switch_success'))
        <p role="status" class="rounded-control border border-success/25 bg-success-bg p-3.5 text-sm font-bold text-success">
            {{ session('ai_switch_success') }}
        </p>
    @endif

    <!-- Alerts & Feedback -->
    @if ($successMessage)
        <div role="status" class="p-3.5 rounded-card bg-success-bg border border-success/25 text-success text-sm font-bold flex items-center justify-between gap-3 shadow-xs">
            <div class="flex items-center gap-2 min-w-0">
                <x-icon name="check-circle" class="w-4.5 h-4.5 text-success shrink-0" />
                <span class="break-words">{{ $successMessage }}</span>
            </div>
            <button type="button" aria-label="{{ __('workflow.close') }}" wire:click="$set('successMessage', null)"
                    class="min-h-11 min-w-11 lg:min-h-9.5 lg:min-w-9.5 inline-flex items-center justify-center rounded-control text-success hover:bg-surface/60 transition-colors cursor-pointer shrink-0">
                <x-icon name="close" class="w-4 h-4" />
            </button>
        </div>
    @endif

    @if ($errorMessage)
        <div role="alert" class="p-3.5 rounded-card bg-danger-bg border border-danger/25 text-danger text-sm font-bold flex items-center justify-between gap-3 shadow-xs">
            <div class="flex items-center gap-2 flex-wrap min-w-0">
                <x-icon name="warning" class="w-4.5 h-4.5 text-danger shrink-0" />
                <span class="break-words">{{ $errorMessage }}</span>
                @if ($errorMessage === __('settings.password_confirmation_required'))
                    <a href="{{ route('password.confirm') }}" class="underline ms-1 font-bold text-danger hover:text-danger-hover inline-flex items-center gap-1">
                        <span>{{ __('settings.confirm_password_action') }}</span>
                        <span class="rtl:rotate-180" aria-hidden="true">&rarr;</span>
                    </a>
                @endif
            </div>
            <button type="button" aria-label="{{ __('workflow.close') }}" wire:click="$set('errorMessage', null)"
                    class="min-h-11 min-w-11 lg:min-h-9.5 lg:min-w-9.5 inline-flex items-center justify-center rounded-control text-danger hover:bg-surface/60 transition-colors cursor-pointer shrink-0">
                <x-icon name="close" class="w-4 h-4" />
            </button>
        </div>
    @endif

    <x-settings-shell :active-section="$activeSection" :company="$company">

            @if ($activeSection === 'identity')
                <!-- 1. Company Identity Form -->
                <div class="bg-surface border border-border rounded-card p-5 sm:p-6 shadow-panel space-y-5">
                    <div class="flex flex-wrap items-center justify-between gap-3 pb-3 border-b border-border">
                        <div>
                            <h2 class="text-base md:text-lg font-bold text-text-primary">{{ __('settings.card_company_title') }}</h2>
                        </div>
                        @if ($company)
                            <span class="text-xs font-semibold text-text-muted">{{ $company->displayName() }}</span>
                        @endif
                    </div>

                    <form wire:submit="saveIdentity" class="space-y-4">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <x-input-label for="company-name-ar">{{ __('settings.identity_name_ar') }} <span class="text-danger">*</span></x-input-label>
                                <x-text-input id="company-name-ar" type="text" wire:model="name_ar" required class="mt-1 block w-full"
                                              :aria-invalid="$errors->has('name_ar') ? 'true' : 'false'"
                                              aria-describedby="{{ $errors->has('name_ar') ? 'company-name-ar-error' : '' }}" />
                                @error('name_ar') <p id="company-name-ar-error" role="alert" class="mt-1 text-xs font-semibold text-danger">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <x-input-label for="company-name-en" :value="__('settings.identity_name_en')" />
                                <x-text-input id="company-name-en" type="text" wire:model="name_en" dir="ltr" class="mt-1 block w-full"
                                              :aria-invalid="$errors->has('name_en') ? 'true' : 'false'"
                                              aria-describedby="{{ $errors->has('name_en') ? 'company-name-en-error' : '' }}" />
                                @error('name_en') <p id="company-name-en-error" role="alert" class="mt-1 text-xs font-semibold text-danger">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <x-input-label for="company-legal-name-ar" :value="__('settings.identity_legal_name_ar')" />
                                <x-text-input id="company-legal-name-ar" type="text" wire:model="legal_name_ar" class="mt-1 block w-full"
                                              :aria-invalid="$errors->has('legal_name_ar') ? 'true' : 'false'"
                                              aria-describedby="{{ $errors->has('legal_name_ar') ? 'company-legal-name-ar-error' : '' }}" />
                                @error('legal_name_ar') <p id="company-legal-name-ar-error" role="alert" class="mt-1 text-xs font-semibold text-danger">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <x-input-label for="company-legal-name-en" :value="__('settings.identity_legal_name_en')" />
                                <x-text-input id="company-legal-name-en" type="text" wire:model="legal_name_en" dir="ltr" class="mt-1 block w-full"
                                              :aria-invalid="$errors->has('legal_name_en') ? 'true' : 'false'"
                                              aria-describedby="{{ $errors->has('legal_name_en') ? 'company-legal-name-en-error' : '' }}" />
                                @error('legal_name_en') <p id="company-legal-name-en-error" role="alert" class="mt-1 text-xs font-semibold text-danger">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <x-input-label for="company-tax-number" :value="__('settings.identity_tax_number')" />
                                <x-text-input id="company-tax-number" type="text" wire:model="tax_number" dir="ltr" class="mt-1 block w-full font-mono"
                                              :aria-invalid="$errors->has('tax_number') ? 'true' : 'false'"
                                              aria-describedby="{{ $errors->has('tax_number') ? 'company-tax-number-error' : '' }}" />
                                @error('tax_number') <p id="company-tax-number-error" role="alert" class="mt-1 text-xs font-semibold text-danger">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <x-input-label for="company-registration-number" :value="__('settings.identity_registration_number')" />
                                <x-text-input id="company-registration-number" type="text" wire:model="registration_number" dir="ltr" class="mt-1 block w-full font-mono"
                                              :aria-invalid="$errors->has('registration_number') ? 'true' : 'false'"
                                              aria-describedby="{{ $errors->has('registration_number') ? 'company-registration-number-error' : '' }}" />
                                @error('registration_number') <p id="company-registration-number-error" role="alert" class="mt-1 text-xs font-semibold text-danger">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <x-input-label for="company-phone" :value="__('settings.identity_phone')" />
                                <x-text-input id="company-phone" type="text" wire:model="phone" dir="ltr" class="mt-1 block w-full font-mono"
                                              :aria-invalid="$errors->has('phone') ? 'true' : 'false'"
                                              aria-describedby="{{ $errors->has('phone') ? 'company-phone-error' : '' }}" />
                                @error('phone') <p id="company-phone-error" role="alert" class="mt-1 text-xs font-semibold text-danger">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <x-input-label for="company-whatsapp" :value="__('settings.identity_whatsapp')" />
                                <x-text-input id="company-whatsapp" type="text" wire:model="whatsapp" dir="ltr" class="mt-1 block w-full font-mono"
                                              :aria-invalid="$errors->has('whatsapp') ? 'true' : 'false'"
                                              aria-describedby="{{ $errors->has('whatsapp') ? 'company-whatsapp-error' : '' }}" />
                                @error('whatsapp') <p id="company-whatsapp-error" role="alert" class="mt-1 text-xs font-semibold text-danger">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <x-input-label for="company-email" :value="__('settings.identity_email')" />
                                <x-text-input id="company-email" type="email" wire:model="email" dir="ltr" class="mt-1 block w-full"
                                              :aria-invalid="$errors->has('email') ? 'true' : 'false'"
                                              aria-describedby="{{ $errors->has('email') ? 'company-email-error' : '' }}" />
                                @error('email') <p id="company-email-error" role="alert" class="mt-1 text-xs font-semibold text-danger">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <x-input-label for="company-website" :value="__('settings.identity_website')" />
                                <x-text-input id="company-website" type="url" wire:model="website" dir="ltr" placeholder="https://" class="mt-1 block w-full"
                                              :aria-invalid="$errors->has('website') ? 'true' : 'false'"
                                              aria-describedby="{{ $errors->has('website') ? 'company-website-error' : '' }}" />
                                @error('website') <p id="company-website-error" role="alert" class="mt-1 text-xs font-semibold text-danger">{{ $message }}</p> @enderror
                            </div>
                        </div>

                        <div>
                            <x-input-label for="company-address-ar" :value="__('settings.identity_address_ar')" />
                            <textarea id="company-address-ar" wire:model="address_ar" rows="2"
                                      @error('address_ar') aria-invalid="true" aria-describedby="company-address-ar-error" @enderror
                                      class="mt-1 w-full text-sm rounded-control border border-border-control bg-surface px-3 py-2 text-text-primary focus:border-primary focus:ring-1 focus:ring-primary/20"></textarea>
                            @error('address_ar') <p id="company-address-ar-error" role="alert" class="mt-1 text-xs font-semibold text-danger">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <x-input-label for="company-address-en" :value="__('settings.identity_address_en')" />
                            <textarea id="company-address-en" wire:model="address_en" dir="ltr" rows="2"
                                      @error('address_en') aria-invalid="true" aria-describedby="company-address-en-error" @enderror
                                      class="mt-1 w-full text-sm rounded-control border border-border-control bg-surface px-3 py-2 text-text-primary focus:border-primary focus:ring-1 focus:ring-primary/20"></textarea>
                            @error('address_en') <p id="company-address-en-error" role="alert" class="mt-1 text-xs font-semibold text-danger">{{ $message }}</p> @enderror
                        </div>

                        <div class="pt-3 border-t border-border flex justify-end">
                            <x-primary-button wire:loading.attr="disabled" wire:target="saveIdentity">
                                <span wire:loading.remove wire:target="saveIdentity">{{ __('settings.save_changes') }}</span>
                                <span wire:loading wire:target="saveIdentity">{{ __('settings.saving') }}</span>
                            </x-primary-button>
                        </div>
                    </form>
                </div>

            @elseif ($activeSection === 'localization')
                <!-- 2. Localization & Timezone Form -->
                <div class="bg-surface border border-border rounded-card p-5 sm:p-6 shadow-panel max-w-2xl space-y-5">
                    <div class="flex items-center justify-between gap-3 pb-3 border-b border-border">
                        <h2 class="text-base md:text-lg font-bold text-text-primary">{{ __('settings.card_localization_title') }}</h2>
                    </div>

                    <form wire:submit="saveLocalization" class="space-y-4">
                        <fieldset>
                            <legend class="block text-sm font-semibold text-text-secondary mb-2">{{ __('settings.company_languages') }}</legend>
                            <div class="divide-y divide-border border border-border rounded-control px-3 bg-surface-soft/50">
                                <div class="min-h-11 flex items-center justify-between gap-3 py-2">
                                    <label for="arabic_enabled" class="flex items-center gap-2.5 text-sm font-bold text-text-primary">
                                        <x-checkbox id="arabic_enabled" checked disabled />
                                        <span>{{ __('settings.locale_ar_label') }}</span>
                                    </label>
                                    <x-badge variant="success">{{ __('settings.always_enabled') }}</x-badge>
                                </div>

                                <div class="min-h-11 flex items-center justify-between gap-3 py-2">
                                    <label for="english_enabled" class="flex items-center gap-2.5 text-sm font-bold text-text-primary cursor-pointer">
                                        <x-checkbox id="english_enabled" wire:model.live="english_enabled" class="cursor-pointer" />
                                        <span>{{ __('settings.locale_en_label') }}</span>
                                    </label>
                                    <x-badge :variant="$english_enabled ? 'info' : 'neutral'">
                                        {{ $english_enabled ? __('settings.status_enabled') : __('settings.status_disabled') }}
                                    </x-badge>
                                </div>
                            </div>
                        </fieldset>

                        <div>
                            <x-input-label for="localization-default-locale" :value="__('settings.default_company_language')" />
                            <select id="localization-default-locale" wire:model="default_locale"
                                    @error('default_locale') aria-invalid="true" aria-describedby="localization-default-locale-error" @enderror
                                    class="mt-1 w-full min-h-11 lg:min-h-9.5 text-sm rounded-control border border-border-control px-3 py-2 bg-surface text-text-primary focus:border-primary focus:ring-1 focus:ring-primary/20">
                                <option value="ar">{{ __('settings.locale_ar_option') }}</option>
                                @if ($english_enabled)
                                    <option value="en">{{ __('settings.locale_en_option') }}</option>
                                @endif
                            </select>
                            @error('default_locale') <p id="localization-default-locale-error" role="alert" class="mt-1 text-xs font-semibold text-danger">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <x-input-label for="localization-timezone" :value="__('settings.timezone')" />
                            <select id="localization-timezone" wire:model="timezone"
                                    @error('timezone') aria-invalid="true" aria-describedby="localization-timezone-error" @enderror
                                    class="mt-1 w-full min-h-11 lg:min-h-9.5 text-sm rounded-control border border-border-control px-3 py-2 bg-surface text-text-primary focus:border-primary focus:ring-1 focus:ring-primary/20">
                                <option value="Asia/Hebron">{{ __('settings.timezone_hebron') }}</option>
                                <option value="Asia/Jerusalem">{{ __('settings.timezone_jerusalem') }}</option>
                                <option value="Asia/Amman">{{ __('settings.timezone_amman') }}</option>
                                <option value="Asia/Riyadh">{{ __('settings.timezone_riyadh') }}</option>
                                <option value="Asia/Dubai">{{ __('settings.timezone_dubai') }}</option>
                                <option value="UTC">{{ __('settings.timezone_utc') }}</option>
                            </select>
                            @error('timezone') <p id="localization-timezone-error" role="alert" class="mt-1 text-xs font-semibold text-danger">{{ $message }}</p> @enderror
                        </div>

                        <div class="pt-3 border-t border-border flex justify-end">
                            <x-primary-button wire:loading.attr="disabled" wire:target="saveLocalization">
                                <span wire:loading.remove wire:target="saveLocalization">{{ __('settings.save_changes') }}</span>
                                <span wire:loading wire:target="saveLocalization">{{ __('settings.saving') }}</span>
                            </x-primary-button>
                        </div>
                    </form>
                </div>

            @elseif ($activeSection === 'currencies')
                <!-- 3. Currencies Form -->
                <div class="bg-surface border border-border rounded-card p-5 sm:p-6 shadow-panel max-w-2xl space-y-5">
                    <div class="flex items-center justify-between gap-3 pb-3 border-b border-border">
                        <h2 class="text-base md:text-lg font-bold text-text-primary">{{ __('settings.card_currencies_title') }}</h2>
                    </div>

                    @if ($isBaseCurrencyLocked)
                        <div class="p-3.5 bg-warning-bg border border-warning/25 rounded-control flex items-start gap-2.5 text-warning text-xs" role="note">
                            <x-icon name="lock" class="w-4 h-4 text-warning shrink-0 mt-0.5" />
                            <div>
                                <span class="font-bold">{{ __('settings.base_currency_locked_notice') }}</span>
                                <p class="text-xs text-warning mt-0.5">{{ __('settings.base_currency_locked_desc') }}</p>
                            </div>
                        </div>
                    @endif

                    <form wire:submit="saveCurrencies" class="space-y-4">
                        <div class="divide-y divide-border">
                            @foreach ([
                                'ILS' => ['name' => __('settings.currency_ils_name'), 'symbol' => '₪'],
                                'USD' => ['name' => __('settings.currency_usd_name'), 'symbol' => '$'],
                                'JOD' => ['name' => __('settings.currency_jod_name'), 'symbol' => 'د.أ'],
                            ] as $code => $info)
                                <div class="py-3 flex flex-wrap items-center justify-between gap-3">
                                    <label for="curr_{{ $code }}" class="min-h-11 inline-flex items-center gap-3 text-sm font-bold text-text-primary cursor-pointer">
                                        <x-checkbox id="curr_{{ $code }}"
                                                    wire:model="currencies_enabled.{{ $code }}"
                                                    :disabled="$base_currency === $code"
                                                    :checked="$base_currency === $code || ! empty($currencies_enabled[$code])" />
                                        <span>
                                            {{ $info['name'] }}
                                            <bdi dir="ltr" class="font-mono text-text-muted">({{ $info['symbol'] }})</bdi>
                                        </span>
                                    </label>

                                    <div class="flex items-center gap-2">
                                        <label for="base_{{ $code }}" class="min-h-11 inline-flex items-center gap-2 text-sm font-semibold text-text-secondary {{ $isBaseCurrencyLocked ? 'cursor-not-allowed opacity-60' : 'cursor-pointer' }}">
                                            <input type="radio" id="base_{{ $code }}" name="base_curr" value="{{ $code }}"
                                                   wire:model.live="base_currency"
                                                   {{ $isBaseCurrencyLocked ? 'disabled' : '' }}
                                                   class="size-4.5 text-primary accent-primary focus:ring-primary {{ $isBaseCurrencyLocked ? 'cursor-not-allowed' : 'cursor-pointer' }}" />
                                            <span>{{ __('settings.status_base_currency') }}</span>
                                        </label>
                                        @if ($isBaseCurrencyLocked && $base_currency === $code)
                                            <x-badge variant="neutral">{{ __('settings.base_currency_locked_badge') }}</x-badge>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <div class="pt-3 border-t border-border flex justify-end">
                            <x-primary-button wire:loading.attr="disabled" wire:target="saveCurrencies">
                                <span wire:loading.remove wire:target="saveCurrencies">{{ __('settings.save_changes') }}</span>
                                <span wire:loading wire:target="saveCurrencies">{{ __('settings.saving') }}</span>
                            </x-primary-button>
                        </div>
                    </form>
                </div>

            @elseif ($activeSection === 'security')
                <!-- 4. Security Policies Form -->
                <div class="bg-surface border border-border rounded-card p-5 sm:p-6 shadow-panel max-w-2xl space-y-5">
                    <div class="flex items-center justify-between gap-3 pb-3 border-b border-border">
                        <h2 class="text-base md:text-lg font-bold text-text-primary">{{ __('settings.card_security_title') }}</h2>
                    </div>

                    <form wire:submit="saveSecurity" class="space-y-4">
                        <div class="space-y-3">
                            <p class="text-sm text-text-secondary bg-surface-soft border border-border rounded-control p-3" role="note">{{ __('settings.optional_2fa_help') }}</p>
                            <div>
                                <x-input-label for="public-share-expiry" :value="__('settings.public_share_expiry_label')" />
                                <x-text-input id="public-share-expiry" type="number" inputmode="numeric" dir="ltr" min="1" max="365"
                                              wire:model="public_share_default_expiry_days"
                                              :aria-invalid="$errors->has('public_share_default_expiry_days') ? 'true' : 'false'"
                                              aria-describedby="{{ $errors->has('public_share_default_expiry_days') ? 'public-share-expiry-error' : '' }}"
                                              class="mt-1 block w-full num" />
                                @error('public_share_default_expiry_days')<p id="public-share-expiry-error" role="alert" class="mt-1 text-xs font-semibold text-danger">{{ $message }}</p>@enderror
                            </div>
                        </div>

                        <div class="p-3.5 rounded-card bg-surface-soft border border-border flex flex-wrap items-center justify-between gap-3">
                            <div class="flex items-center gap-2.5 min-w-0">
                                <x-icon name="lock" class="w-4.5 h-4.5 text-primary shrink-0" />
                                <div class="min-w-0">
                                    <span class="text-sm font-bold text-text-primary block">{{ __('settings.personal_security') }}</span>
                                    <span class="text-xs text-text-muted">{{ __('settings.link_personal_2fa') }}</span>
                                </div>
                            </div>
                            <a href="{{ route('profile') }}" class="touch-action min-h-11 lg:min-h-9.5 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-control bg-surface hover:bg-surface-soft border border-border-control text-sm font-bold text-text-primary transition-colors">
                                <span>{{ __('settings.go_to_profile_security') }}</span>
                                <x-icon name="chevron" class="w-3.5 h-3.5 rtl:rotate-180 text-text-muted" />
                            </a>
                        </div>

                        <div class="pt-3 border-t border-border flex justify-end">
                            <x-primary-button wire:loading.attr="disabled" wire:target="saveSecurity">
                                <span wire:loading.remove wire:target="saveSecurity">{{ __('settings.save_changes') }}</span>
                                <span wire:loading wire:target="saveSecurity">{{ __('settings.saving') }}</span>
                            </x-primary-button>
                        </div>
                    </form>
                </div>

            @elseif ($activeSection === 'data' && $isCompanyOwner && \Illuminate\Support\Facades\Route::has('settings.company-data.download'))
                <!-- 5. Company Data Export (Owner Only) -->
                <section class="bg-surface border border-border rounded-card p-5 sm:p-6 shadow-panel max-w-2xl space-y-4" aria-labelledby="company-data-title">
                    <div class="pb-3 border-b border-border">
                        <h2 id="company-data-title" class="text-base md:text-lg font-bold text-text-primary">{{ __('settings.company_data_title') }}</h2>
                    </div>

                    <p class="text-sm text-text-secondary leading-relaxed">{{ __('settings.company_data_description') }}</p>

                    @if ($passwordConfirmed)
                        <form method="POST"
                              action="{{ route('settings.company-data.download') }}"
                              x-data="companyDataDownload({
                                  preparing: @js(__('settings.company_data_preparing')),
                                  failed: @js(__('settings.company_data_failed')),
                                  started: @js(__('settings.company_data_started')),
                                  confirmRequired: @js(__('settings.password_confirmation_required'))
                              })"
                              x-on:submit.prevent="download($el)"
                              class="space-y-3">
                            @csrf
                            <div class="flex flex-wrap items-center gap-3">
                                <x-primary-button type="submit" x-bind:disabled="preparing">
                                    {{ __('settings.company_data_download') }}
                                </x-primary-button>
                                <button x-cloak x-show="needsConfirmation" type="button" wire:click="prepareCompanyDataDownload" wire:loading.attr="disabled"
                                        class="min-h-11 lg:min-h-9.5 px-4 py-2 rounded-control border border-primary text-sm font-bold text-primary hover:bg-primary-50 cursor-pointer">
                                    {{ __('settings.confirm_password_action') }}
                                </button>
                            </div>
                            <p x-cloak x-show="message" x-text="message" role="status" class="text-sm font-semibold text-success"></p>
                            <p x-cloak x-show="error" x-text="error" role="alert" class="text-sm font-semibold text-danger"></p>
                        </form>
                    @else
                        <div class="pt-1">
                            <button type="button" wire:click="prepareCompanyDataDownload" wire:loading.attr="disabled"
                                    class="min-h-11 lg:min-h-9.5 px-4 py-2 rounded-control border border-primary bg-surface text-primary text-sm font-bold hover:bg-primary-50 transition-colors cursor-pointer">
                                {{ __('settings.confirm_password_action') }}
                            </button>
                        </div>
                    @endif

                    @if (session('company_data_export_error'))
                        <p role="alert" class="text-sm font-semibold text-danger">{{ session('company_data_export_error') }}</p>
                    @endif
                </section>

            @elseif ($activeSection === 'users')
                <!-- 6. Users Administration View -->
                <div class="space-y-6 max-w-5xl">
                    <!-- Members List (Single Responsive Tree) -->
                    <div class="bg-surface border border-border rounded-card p-5 sm:p-6 shadow-panel space-y-4">
                        <div class="flex items-center justify-between gap-3">
                            <h2 class="text-base md:text-lg font-bold text-text-primary">{{ __('settings.card_users_title') }}</h2>
                            <span class="text-xs font-bold text-text-secondary num">
                                {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayQuantity($memberships->count()) }} {{ __('settings.usage_users') }}
                            </span>
                        </div>

                        <div class="border border-border rounded-control overflow-hidden">
                            <div class="hidden lg:grid lg:grid-cols-12 gap-3 px-4 py-2.5 bg-surface-soft border-b border-border text-xs font-bold text-text-muted">
                                <div class="lg:col-span-3">{{ __('settings.user_name') }}</div>
                                <div class="lg:col-span-3">{{ __('settings.user_email') }}</div>
                                <div class="lg:col-span-2">{{ __('settings.user_role') }}</div>
                                <div class="lg:col-span-1">{{ __('settings.user_status') }}</div>
                                <div class="lg:col-span-1">{{ __('settings.joined_at') }}</div>
                                <div class="lg:col-span-2 text-end">{{ __('settings.actions') }}</div>
                            </div>

                            <div class="divide-y divide-border">
                                @foreach ($memberships as $m)
                                    @php
                                        $u = $m->user;
                                        $userRole = $u->roles->first();
                                    @endphp
                                    <div wire:key="member-row-{{ $m->id }}" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-12 gap-3 p-4 lg:px-4 lg:py-3 items-center hover:bg-surface-soft/50 transition-colors">
                                        <div class="lg:col-span-3 min-w-0">
                                            <span class="text-xs font-semibold text-text-muted block lg:hidden">{{ __('settings.user_name') }}</span>
                                            <div class="flex items-center gap-2 min-w-0">
                                                <span class="font-bold text-sm text-text-primary truncate">{{ $u->name }}</span>
                                                @if ($m->is_owner)
                                                    <x-badge variant="warning">{{ __('settings.role_owner') }}</x-badge>
                                                @endif
                                            </div>
                                        </div>

                                        <div class="lg:col-span-3 min-w-0">
                                            <span class="text-xs font-semibold text-text-muted block lg:hidden">{{ __('settings.user_email') }}</span>
                                            <bdi dir="ltr" class="font-mono text-xs text-text-secondary break-all lg:truncate block">{{ $u->email }}</bdi>
                                        </div>

                                        <div class="lg:col-span-2 min-w-0">
                                            <label for="member-role-{{ $m->id }}" class="text-xs font-semibold text-text-muted block lg:hidden mb-1">{{ __('settings.user_role') }}</label>
                                            @if ($m->is_owner)
                                                <span class="text-sm font-bold text-text-primary">{{ __('settings.role_owner') }}</span>
                                            @elseif (auth()->user()?->can('settings.users.manage'))
                                                <select id="member-role-{{ $m->id }}"
                                                        aria-label="{{ __('settings.user_role') }} — {{ $u->name }}"
                                                        wire:change="updateUserRole({{ $m->id }}, $event.target.value)"
                                                        class="w-full min-h-11 lg:min-h-9.5 text-sm py-1.5 px-2.5 rounded-control border border-border-control bg-surface font-medium text-text-primary">
                                                    @foreach (['Administrator', 'Manager', 'Sales', 'Purchasing', 'Warehouse', 'Cashier', 'Viewer'] as $rName)
                                                        <option value="{{ $rName }}" {{ $userRole?->name === $rName ? 'selected' : '' }}>
                                                            {{ __('settings.roles_labels.'.$rName) }}
                                                        </option>
                                                    @endforeach
                                                </select>
                                            @else
                                                <span class="text-sm font-medium text-text-secondary">{{ $userRole ? (\Illuminate\Support\Facades\Lang::has('settings.roles_labels.'.$userRole->name) ? __('settings.roles_labels.'.$userRole->name) : $userRole->name) : '—' }}</span>
                                            @endif
                                        </div>

                                        <div class="lg:col-span-1">
                                            <span class="text-xs font-semibold text-text-muted block lg:hidden mb-1">{{ __('settings.user_status') }}</span>
                                            <x-badge :variant="$m->status === 'active' ? 'success' : 'neutral'">
                                                {{ $m->status === 'active' ? __('settings.status_active') : __('settings.status_inactive') }}
                                            </x-badge>
                                        </div>

                                        <div class="lg:col-span-1">
                                            <span class="text-xs font-semibold text-text-muted block lg:hidden">{{ __('settings.joined_at') }}</span>
                                            <bdi dir="ltr" class="font-mono text-xs text-text-muted num">
                                                {{ $m->joined_at ? $m->joined_at->format('Y-m-d') : '—' }}
                                            </bdi>
                                        </div>

                                        <div class="lg:col-span-2 flex justify-start lg:justify-end">
                                            @can('settings.users.manage')
                                                @if (! $m->is_owner || $memberships->where('is_owner', true)->count() > 1)
                                                    <button type="button" wire:loading.attr="disabled" wire:target="toggleUserStatus" wire:click="toggleUserStatus({{ $m->id }})"
                                                            class="min-h-11 lg:min-h-9.5 px-3 py-1.5 rounded-control border border-border-control bg-surface text-xs font-bold text-primary hover:bg-primary-50 transition-colors cursor-pointer">
                                                        {{ $m->status === 'active' ? __('settings.status_inactive') : __('settings.status_active') }}
                                                    </button>
                                                @else
                                                    <span class="text-text-muted text-xs">—</span>
                                                @endif
                                            @else
                                                <span class="text-text-muted text-xs">—</span>
                                            @endcan
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    <!-- Add New User Form -->
                    @can('settings.users.manage')
                        <div class="bg-surface border border-border rounded-card p-5 sm:p-6 shadow-panel space-y-4">
                            <h3 class="text-base font-bold text-text-primary">{{ __('settings.add_new_user') }}</h3>
                            <form wire:submit="createUser" class="space-y-4">
                                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                                    <div>
                                        <x-input-label for="new-user-name">{{ __('settings.user_name') }} <span class="text-danger">*</span></x-input-label>
                                        <x-text-input id="new-user-name" type="text" wire:model="new_user_name" required class="mt-1 block w-full"
                                                      :aria-invalid="$errors->has('new_user_name') ? 'true' : 'false'"
                                                      aria-describedby="{{ $errors->has('new_user_name') ? 'new-user-name-error' : '' }}" />
                                        @error('new_user_name') <p id="new-user-name-error" role="alert" class="mt-1 text-xs font-semibold text-danger">{{ $message }}</p> @enderror
                                    </div>
                                    <div>
                                        <x-input-label for="new-user-email">{{ __('settings.user_email') }} <span class="text-danger">*</span></x-input-label>
                                        <x-text-input id="new-user-email" type="email" wire:model="new_user_email" required dir="ltr" class="mt-1 block w-full"
                                                      :aria-invalid="$errors->has('new_user_email') ? 'true' : 'false'"
                                                      aria-describedby="{{ $errors->has('new_user_email') ? 'new-user-email-error' : '' }}" />
                                        @error('new_user_email') <p id="new-user-email-error" role="alert" class="mt-1 text-xs font-semibold text-danger">{{ $message }}</p> @enderror
                                    </div>
                                    <div>
                                        <x-input-label for="new-user-password">{{ __('settings.user_password') }} <span class="text-danger">*</span></x-input-label>
                                        <x-text-input id="new-user-password" type="password" wire:model="new_user_password" required dir="ltr" autocomplete="new-password" class="mt-1 block w-full"
                                                      :aria-invalid="$errors->has('new_user_password') ? 'true' : 'false'"
                                                      aria-describedby="{{ $errors->has('new_user_password') ? 'new-user-password-error' : '' }}" />
                                        @error('new_user_password') <p id="new-user-password-error" role="alert" class="mt-1 text-xs font-semibold text-danger">{{ $message }}</p> @enderror
                                    </div>
                                    <div>
                                        <x-input-label for="new-user-role">{{ __('settings.user_role') }} <span class="text-danger">*</span></x-input-label>
                                        <select id="new-user-role" wire:model="new_user_role"
                                                @error('new_user_role') aria-invalid="true" aria-describedby="new-user-role-error" @enderror
                                                class="mt-1 w-full min-h-11 lg:min-h-9.5 text-sm rounded-control border border-border-control px-3 py-2 bg-surface text-text-primary focus:border-primary focus:ring-1 focus:ring-primary/20">
                                            @foreach (['Administrator', 'Manager', 'Sales', 'Purchasing', 'Warehouse', 'Cashier', 'Viewer'] as $roleOption)
                                                <option value="{{ $roleOption }}">{{ __('settings.roles_labels.'.$roleOption) }}</option>
                                            @endforeach
                                        </select>
                                        @error('new_user_role') <p id="new-user-role-error" role="alert" class="mt-1 text-xs font-semibold text-danger">{{ $message }}</p> @enderror
                                    </div>
                                    <div>
                                        <x-input-label for="new-user-locale" :value="__('settings.user_language')" />
                                        <select id="new-user-locale" wire:model="new_user_locale"
                                                @error('new_user_locale') aria-invalid="true" aria-describedby="new-user-locale-error" @enderror
                                                class="mt-1 w-full min-h-11 lg:min-h-9.5 text-sm rounded-control border border-border-control px-3 py-2 bg-surface text-text-primary focus:border-primary focus:ring-1 focus:ring-primary/20">
                                            <option value="ar">{{ __('settings.locale_ar_label') }}</option>
                                            <option value="en">{{ __('settings.locale_en_label') }}</option>
                                        </select>
                                        @error('new_user_locale') <p id="new-user-locale-error" role="alert" class="mt-1 text-xs font-semibold text-danger">{{ $message }}</p> @enderror
                                    </div>
                                </div>

                                <div class="pt-3 border-t border-border flex justify-end">
                                    <x-primary-button wire:loading.attr="disabled" wire:target="createUser">
                                        <span wire:loading.remove wire:target="createUser">{{ __('settings.add_new_user') }}</span>
                                        <span wire:loading wire:target="createUser">{{ __('settings.saving') }}</span>
                                    </x-primary-button>
                                </div>
                            </form>
                        </div>
                    @endcan
                </div>

            @elseif ($activeSection === 'roles')
                <!-- 7. Roles & Permissions View -->
                <div class="space-y-5 max-w-5xl">
                    <div class="bg-surface border border-border rounded-card p-5 sm:p-6 shadow-panel space-y-4">
                        <div class="flex items-center justify-between gap-3 pb-3 border-b border-border">
                            <h2 class="text-base md:text-lg font-bold text-text-primary">{{ __('settings.card_roles_title') }}</h2>
                        </div>

                        <!-- Role Selector Pills -->
                        <div class="flex flex-wrap gap-2 pt-1">
                            @foreach ($roles as $role)
                                @php $isRoleSelected = $selectedRoleName === $role->name; @endphp
                                <button type="button" wire:click="selectRole('{{ $role->name }}')"
                                        aria-pressed="{{ $isRoleSelected ? 'true' : 'false' }}"
                                        class="min-h-11 lg:min-h-9.5 px-3.5 py-2 rounded-control border text-sm font-bold transition-colors cursor-pointer
                                               {{ $isRoleSelected ? 'bg-primary border-primary text-white shadow-xs' : 'bg-surface border-border-control text-text-secondary hover:bg-surface-soft hover:text-text-primary' }}">
                                    {{ \Illuminate\Support\Facades\Lang::has('settings.roles_labels.'.$role->name) ? __('settings.roles_labels.'.$role->name) : $role->name }}
                                    @if ($role->name === 'Owner')
                                        <span class="text-xs opacity-80">({{ __('settings.role_owner') }})</span>
                                    @endif
                                </button>
                            @endforeach
                        </div>

                        @if ($selectedRoleName === 'Owner')
                            <div class="p-3.5 rounded-card bg-warning-bg border border-warning/25 text-warning text-sm" role="note">
                                <strong>{{ __('settings.protected_role_notice') }}</strong>
                            </div>
                        @endif

                        <!-- Grouped Permissions List -->
                        <div class="mt-4 pt-4 border-t border-border space-y-4">
                            <h3 class="text-sm font-bold text-text-primary">
                                {{ __('settings.role_permissions_for') }}
                                <span class="text-primary">{{ $selectedRoleName ? (\Illuminate\Support\Facades\Lang::has('settings.roles_labels.'.$selectedRoleName) ? __('settings.roles_labels.'.$selectedRoleName) : $selectedRoleName) : '' }}</span>
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
                                        <div class="p-3.5 rounded-card bg-surface-soft border border-border space-y-2">
                                            <h4 class="text-xs font-bold text-text-secondary">{{ $groupTitle }}</h4>
                                            <div class="divide-y divide-border/50">
                                                @foreach ($groupPerms as $perm)
                                                    <label class="min-h-11 lg:min-h-9 flex items-center justify-between gap-2 py-1 text-sm text-text-secondary cursor-pointer hover:text-text-primary">
                                                        <bdi dir="ltr" class="font-mono text-xs truncate" title="{{ $perm->name }}">{{ $perm->name }}</bdi>
                                                        <x-checkbox
                                                            :checked="! empty($rolePermissions[$perm->name])"
                                                            :disabled="$selectedRoleName === 'Owner'"
                                                            wire:loading.attr="disabled"
                                                            wire:target="toggleRolePermission"
                                                            wire:click="toggleRolePermission('{{ $perm->name }}')" />
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
                <!-- 8. Audit Log View -->
                <div class="bg-surface border border-border rounded-card p-5 sm:p-6 shadow-panel space-y-4 max-w-5xl">
                    <div class="flex items-center justify-between gap-3 pb-3 border-b border-border">
                        <h2 class="text-base md:text-lg font-bold text-text-primary">{{ __('settings.card_audit_title') }}</h2>
                    </div>

                    <form wire:submit="applyAuditFilters" class="grid grid-cols-1 sm:grid-cols-4 gap-3 items-end">
                        <div>
                            <x-input-label for="audit-from" :value="__('settings.audit_from')" />
                            <x-text-input id="audit-from" type="date" wire:model="auditFrom" dir="ltr" class="mt-1 w-full"
                                          :aria-invalid="$errors->has('auditFrom') ? 'true' : 'false'"
                                          aria-describedby="{{ $errors->has('auditFrom') ? 'audit-from-error' : '' }}" />
                            @error('auditFrom') <p id="audit-from-error" role="alert" class="mt-1 text-xs font-semibold text-danger">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <x-input-label for="audit-until" :value="__('settings.audit_until')" />
                            <x-text-input id="audit-until" type="date" wire:model="auditUntil" dir="ltr" class="mt-1 w-full"
                                          :aria-invalid="$errors->has('auditUntil') ? 'true' : 'false'"
                                          aria-describedby="{{ $errors->has('auditUntil') ? 'audit-until-error' : '' }}" />
                            @error('auditUntil') <p id="audit-until-error" role="alert" class="mt-1 text-xs font-semibold text-danger">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <x-input-label for="audit-event" :value="__('settings.audit_event_prefix')" />
                            <x-text-input id="audit-event" type="text" wire:model="auditEvent" maxlength="80" dir="ltr" class="mt-1 w-full"
                                          :aria-invalid="$errors->has('auditEvent') ? 'true' : 'false'"
                                          aria-describedby="{{ $errors->has('auditEvent') ? 'audit-event-error' : '' }}" />
                            @error('auditEvent') <p id="audit-event-error" role="alert" class="mt-1 text-xs font-semibold text-danger">{{ $message }}</p> @enderror
                        </div>
                        <button type="submit" wire:loading.attr="disabled"
                                class="min-h-11 lg:min-h-9.5 rounded-control border border-primary bg-surface px-4 py-2 text-sm font-bold text-primary hover:bg-primary-50 transition-colors cursor-pointer">
                            {{ __('settings.audit_apply') }}
                        </button>
                    </form>

                    <div class="lg:hidden space-y-3">
                        @forelse ($auditEvents as $event)
                            <article class="rounded-control border border-border p-3.5 space-y-2 text-sm" wire:key="audit-card-{{ $event->id }}">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <span class="font-bold text-text-primary break-words">{{ $event->actor?->name ?? __('settings.actor_system') }}</span>
                                    <time class="font-mono text-xs text-text-muted num" dir="ltr">{{ $event->created_at->format('Y-m-d H:i') }}</time>
                                </div>
                                <p class="font-mono text-xs text-primary break-all" dir="ltr">{{ $event->event_key }}</p>
                                <p class="text-text-secondary break-words">{{ $event->summary }}</p>
                            </article>
                        @empty
                            <p class="py-6 text-center text-sm text-text-muted">{{ __('settings.no_audit_events') }}</p>
                        @endforelse
                    </div>

                    <div class="hidden lg:block overflow-x-auto">
                        <table class="data-table w-full text-sm text-start">
                            <thead>
                                <tr class="border-b border-border bg-surface-soft text-text-muted text-xs">
                                    <th class="py-2.5 px-3 font-bold text-start">{{ __('settings.audit_occurred_at') }}</th>
                                    <th class="py-2.5 px-3 font-bold text-start">{{ __('settings.user_name') }}</th>
                                    <th class="py-2.5 px-3 font-bold text-start">{{ __('settings.audit_event') }}</th>
                                    <th class="py-2.5 px-3 font-bold text-start">{{ __('settings.audit_details') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border/60">
                                @forelse ($auditEvents as $event)
                                    <tr wire:key="audit-row-{{ $event->id }}" class="hover:bg-surface-soft/60 transition-colors">
                                        <td class="py-3 px-3 font-mono text-xs text-text-muted whitespace-nowrap">
                                            <bdi dir="ltr" class="num">{{ $event->created_at->format('Y-m-d H:i') }}</bdi>
                                        </td>
                                        <td class="py-3 px-3 font-bold text-text-primary">
                                            {{ $event->actor?->name ?? __('settings.actor_system') }}
                                        </td>
                                        <td class="py-3 px-3 font-mono text-xs text-primary">
                                            <bdi dir="ltr">{{ $event->event_key }}</bdi>
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
                <!-- 9. AI Assistant Settings (Single Consolidated Master Switch + Provider Setup) -->
                <div class="bg-surface border border-border rounded-card p-5 sm:p-6 shadow-panel space-y-6">
                    <div class="border-b border-border pb-3 flex items-center justify-between gap-3">
                        <h2 class="text-base md:text-lg font-bold text-text-primary">{{ __('assistant_v2.settings_title') }}</h2>
                    </div>

                    @if ($hasCompany && $canManageAi)
                        <div id="company-ai-control"
                             class="bg-surface-soft border border-border rounded-card p-4 sm:p-5 space-y-3">
                            <div class="flex flex-wrap items-start justify-between gap-3 min-w-0">
                                <div class="min-w-0 space-y-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <x-icon name="sparkles" class="w-4.5 h-4.5 text-primary shrink-0" />
                                        <h3 class="text-base font-bold text-text-primary">
                                            {{ __('settings.card_ai_title') }}
                                        </h3>
                                        @if ($aiStatus === 'enabled')
                                            <x-badge variant="success">{{ __('settings.ai_status_enabled') }}</x-badge>
                                        @elseif ($aiStatus === 'disabled')
                                            <x-badge variant="warning">{{ __('settings.ai_status_disabled') }}</x-badge>
                                        @elseif ($aiStatus === 'not_configured')
                                            <x-badge variant="neutral">{{ __('settings.ai_status_not_configured') }}</x-badge>
                                        @else
                                            <x-badge variant="neutral">{{ __('settings.ai_status_globally_disabled') }}</x-badge>
                                        @endif
                                    </div>
                                    <p class="text-sm text-text-secondary leading-relaxed">
                                        {{ __('settings.card_ai_desc') }}
                                    </p>
                                </div>
                            </div>

                            @if ($isCompanyOwner && $aiStatus !== 'globally_disabled')
                                @php
                                    $isDisabling = $aiStatus === 'enabled';
                                    $isEnabling = $aiStatus === 'disabled' && ! $aiNeedsTransition;
                                @endphp
                                <div class="pt-3 border-t border-border/70 flex flex-wrap items-center justify-between gap-3 text-sm" x-data="{ openToggle: false, password: '', busy: false }">
                                    @if ($isDisabling || $isEnabling)
                                        <p class="text-xs text-text-secondary max-w-prose">{{ __('settings.ai_switch_help') }}</p>
                                        <button type="button" @click="openToggle = !openToggle"
                                                class="min-h-11 lg:min-h-9.5 px-3.5 py-1.5 rounded-control font-bold border transition-colors cursor-pointer {{ $isDisabling ? 'bg-danger-bg text-danger border-danger/25 hover:bg-danger-bg' : 'bg-success-bg text-success border-success/25 hover:bg-success-bg' }}">
                                            {{ $isDisabling ? __('settings.ai_switch_disable') : __('settings.ai_switch_enable') }}
                                        </button>

                                        <div x-show="openToggle" x-cloak class="w-full mt-2 p-3.5 rounded-control bg-surface border {{ $isDisabling ? 'border-danger/25' : 'border-success/25' }} space-y-3">
                                            <div>
                                                <label for="company-ai-password" class="block text-sm font-bold {{ $isDisabling ? 'text-danger' : 'text-success' }}">
                                                    {{ __('assistant_v2.current_password') }}
                                                </label>
                                                <input id="company-ai-password" type="password" x-model="password" autocomplete="current-password"
                                                       class="mt-1 block w-full max-w-sm min-h-11 lg:min-h-9.5 rounded-control border border-border-control bg-surface px-3 py-2 text-sm text-text-primary focus:border-primary focus:ring-1 focus:ring-primary/20" />
                                            </div>
                                            <div class="flex flex-wrap items-center gap-2">
                                                <button type="button" :disabled="busy || !password"
                                                        @click="busy = true; const p = {{ $isDisabling ? '$wire.disableCompanyAi(password)' : '$wire.enableCompanyAi(password)' }}; password = ''; openToggle = false; p.finally(() => busy = false);"
                                                        class="min-h-11 lg:min-h-9.5 px-4 py-2 text-white rounded-control text-sm font-bold disabled:opacity-50 transition-colors cursor-pointer {{ $isDisabling ? 'bg-danger hover:bg-danger-hover' : 'bg-success hover:bg-success/90' }}">
                                                    {{ $isDisabling ? __('settings.ai_switch_disable') : __('settings.ai_switch_enable') }}
                                                </button>
                                                <button type="button" @click="openToggle = false; password = '';"
                                                        class="min-h-11 lg:min-h-9.5 px-3 py-2 rounded-control border border-border text-text-secondary text-sm font-semibold hover:text-text-primary hover:bg-surface-soft transition-colors cursor-pointer">
                                                    {{ __('inventory.cancel') }}
                                                </button>
                                            </div>
                                        </div>
                                    @elseif ($aiStatus === 'disabled' && $aiNeedsTransition)
                                        <span class="text-sm font-semibold text-primary">{{ __('ai_product.transition_action') }}</span>
                                    @else
                                        <span class="text-sm text-text-secondary">{{ __('ai_product.setup_help') }}</span>
                                    @endif
                                </div>
                            @elseif ($aiStatus === 'globally_disabled')
                                <div class="pt-3 border-t border-border/70 text-xs text-text-muted">
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
