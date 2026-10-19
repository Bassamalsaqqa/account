@php
    $twoFactorUser = auth()->user();
    $twoFactorEnabled = $twoFactorUser?->hasEnabledTwoFactorAuthentication() ?? false;
    $twoFactorPending = ! $twoFactorEnabled && ! is_null($twoFactorUser?->two_factor_secret);
    $initialTwoFactorStatus = $twoFactorEnabled ? 'enabled' : ($twoFactorPending ? 'pending' : 'disabled');
@endphp

<section x-data="profileTwoFactor({
    initialStatus: @js($initialTwoFactorStatus),
    routes: {
        passwordStatus: @js(route('password.confirmation')),
        passwordConfirm: @js(route('password.confirm.store')),
        enable: @js(route('two-factor.enable')),
        disable: @js(route('two-factor.disable')),
        qrCode: @js(route('two-factor.qr-code')),
        secretKey: @js(route('two-factor.secret-key')),
        confirm: @js(route('two-factor.confirm')),
        recoveryCodes: @js(route('two-factor.recovery-codes')),
        regenerateRecoveryCodes: @js(route('two-factor.regenerate-recovery-codes')),
    },
    translations: {
        invalid_code: @js(__('profile_security.invalid_code')),
        code_required: @js(__('profile_security.code_required')),
        password_required: @js(__('profile_security.password_required')),
        invalid_password: @js(__('profile_security.invalid_password')),
        enabled_success: @js(__('profile_security.enabled_success')),
        disabled_success: @js(__('profile_security.disabled_success')),
        codes_regenerated: @js(__('profile_security.codes_regenerated')),
        setup_cancelled: @js(__('profile_security.setup_cancelled')),
        generic_error: @js(__('profile_security.generic_error')),
        request_uncertain: @js(__('profile_security.request_uncertain')),
    }
})" class="space-y-6" aria-labelledby="two-factor-title">
    <div x-cloak x-show="uncertain" role="alert" class="rounded-control border border-warning bg-warning-bg p-4 text-sm space-y-3">
        <p>{{ __('profile_security.request_uncertain') }}</p>
        <button type="button" @click="window.location.reload()" class="min-h-11 px-3 rounded-control border border-border bg-white font-semibold focus-visible:ring-2 focus-visible:ring-primary">{{ __('profile_security.reload_status') }}</button>
    </div>

    <header class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
            <div class="flex items-center gap-2">
                <h2 id="two-factor-title" class="text-lg font-bold text-text-primary">
                    {{ __('profile_security.title') }}
                </h2>
                <template x-if="status === 'enabled'">
                    <x-badge variant="success">{{ __('profile_security.status_enabled') }}</x-badge>
                </template>
                <template x-if="status === 'pending'">
                    <x-badge variant="warn">{{ __('profile_security.status_pending') }}</x-badge>
                </template>
                <template x-if="status === 'disabled'">
                    <x-badge variant="default">{{ __('profile_security.status_disabled') }}</x-badge>
                </template>
            </div>
            <p class="mt-1 text-sm text-text-secondary">
                {{ __('profile_security.description') }}
            </p>
        </div>
    </header>

    {{-- Feedback Alerts --}}
    <div x-cloak x-show="success" x-transition class="rounded-control border border-success/30 bg-success-bg p-4 text-xs font-semibold text-success flex items-start gap-2.5" aria-live="polite">
        <x-icon name="check-circle" class="w-4 h-4 shrink-0 mt-0.5" />
        <span class="flex-1" x-text="success"></span>
        <button type="button" @click="success = null" class="text-success hover:opacity-75" aria-label="{{ __('app.clear_search') }}">
            <x-icon name="close" class="w-3.5 h-3.5" />
        </button>
    </div>

    <div x-cloak x-show="error" x-transition class="rounded-control border border-danger/30 bg-danger-bg p-4 text-xs font-semibold text-danger flex items-start gap-2.5" role="alert">
        <x-icon name="close" class="w-4 h-4 shrink-0 mt-0.5" />
        <span class="flex-1" x-text="error"></span>
        <button type="button" @click="error = null" class="text-danger hover:opacity-75" aria-label="{{ __('app.clear_search') }}">
            <x-icon name="close" class="w-3.5 h-3.5" />
        </button>
    </div>

    {{-- State 1: Disabled --}}
    <div x-show="status === 'disabled'" class="space-y-4">
        <p class="text-sm text-text-secondary leading-relaxed">
            {{ __('profile_security.status_disabled_desc') }}
        </p>

        <div>
            <x-primary-button type="button" @click="enableTwoFactor" x-bind:disabled="loading || setupLoading || uncertain" class="min-h-11">
                <span x-show="!(loading && loadingAction === 'enabling')">{{ __('profile_security.enable_action') }}</span>
                <span x-show="loading && loadingAction === 'enabling'">{{ __('profile_security.processing') }}</span>
            </x-primary-button>
        </div>
    </div>

    {{-- State 2: Pending Confirmation --}}
    <div x-cloak x-show="status === 'pending'" class="space-y-6">
        <div class="rounded-card border border-warning/30 bg-warning-bg p-4 text-xs text-text-primary space-y-1">
            <div class="font-bold text-warning flex items-center gap-1.5">
                <x-icon name="shield" class="w-4 h-4 text-warning" />
                <span>{{ __('profile_security.status_pending') }}</span>
            </div>
            <p class="text-text-secondary">
                {{ __('profile_security.status_pending_desc') }}
            </p>
        </div>

        <button type="button" @click="loadSetupData()" x-bind:disabled="loading || setupLoading || uncertain" class="min-h-11 px-3 rounded-control border border-border bg-white text-sm font-semibold focus-visible:ring-2 focus-visible:ring-primary">{{ __('profile_security.show_setup') }}</button>
        <div class="grid gap-6 md:grid-cols-2 items-start">
            {{-- QR Code & Manual Key --}}
            <div class="space-y-4">
                <div class="border border-border rounded-card p-4 bg-surface-soft/40 space-y-3">
                    <h3 class="text-xs font-bold text-text-primary uppercase tracking-wider">
                        {{ __('profile_security.qr_code_title') }}
                    </h3>
                    <div class="flex items-center justify-center p-3 bg-white rounded-lg border border-border inline-block shadow-xs max-w-[216px]" x-html="qrSvg" aria-label="{{ __('profile_security.qr_code_title') }}"></div>

                    <div x-show="secretKey" class="space-y-1 pt-2">
                        <span class="text-xs font-semibold text-text-muted block">
                            {{ __('profile_security.manual_key_title') }}
                        </span>
                        <div class="flex flex-wrap items-center gap-2">
                            <code class="px-2.5 py-1 rounded bg-white border border-border text-xs font-mono text-text-primary select-all tracking-wider" dir="ltr" x-text="secretKey"></code>
                            <button type="button" @click="copyToClipboard(secretKey, 'key')" class="min-h-11 rounded-control border border-border text-xs font-bold text-primary hover:bg-surface-soft px-3 py-2 cursor-pointer focus-visible:ring-2 focus-visible:ring-primary">
                                <span x-show="copyFeedback !== 'key'">{{ __('profile_security.copy_key') }}</span>
                                <span x-show="copyFeedback === 'key'" class="text-success font-bold">{{ __('profile_security.key_copied') }}</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            {{-- 6-digit TOTP Confirmation Code Form --}}
            <div class="border border-border rounded-card p-4 sm:p-5 bg-white space-y-4">
                <form @submit.prevent="confirmTwoFactor" class="space-y-4">
                    <div>
                        <x-input-label for="totp_confirmation_code" :value="__('profile_security.confirmation_code_label')" />
                        <x-text-input id="totp_confirmation_code"
                                      x-model="confirmationCode"
                                      type="text"
                                      inputmode="numeric"
                                      autocomplete="one-time-code"
                                      maxlength="7"
                                      placeholder="{{ __('profile_security.confirmation_code_placeholder') }}"
                                      class="mt-1 block w-full max-w-xs text-center font-mono text-xl tracking-widest"
                                      required />
                        <p class="mt-1.5 text-xs text-text-muted">
                            {{ __('profile_security.confirmation_code_help') }}
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-3 pt-2">
                        <x-primary-button type="submit" x-bind:disabled="loading || setupLoading || uncertain" class="min-h-11">
                            <span x-show="!(loading && loadingAction === 'confirming')">{{ __('profile_security.confirm_action') }}</span>
                            <span x-show="loading && loadingAction === 'confirming'">{{ __('profile_security.processing') }}</span>
                        </x-primary-button>

                        <x-secondary-button type="button" @click="cancelPending" x-bind:disabled="loading || setupLoading || uncertain" class="min-h-11">
                            {{ __('profile_security.cancel_setup_action') }}
                        </x-secondary-button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- State 3: Active & Confirmed --}}
    <div x-cloak x-show="status === 'enabled'" class="space-y-6">
        <p class="text-sm text-text-secondary leading-relaxed">
            {{ __('profile_security.status_enabled_desc') }}
        </p>

        {{-- Recovery Codes Management --}}
        <div class="border border-border rounded-card p-4 sm:p-5 bg-white space-y-4">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div>
                    <h3 class="text-sm font-bold text-text-primary">
                        {{ __('profile_security.recovery_codes_title') }}
                    </h3>
                    <p class="mt-0.5 text-xs text-text-muted">
                        {{ __('profile_security.recovery_codes_description') }}
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <x-secondary-button type="button" @click="toggleRecoveryCodes" x-bind:disabled="loading || setupLoading || uncertain" class="min-h-11 text-xs">
                        <span x-show="!showingRecoveryCodes">{{ __('profile_security.show_recovery_codes') }}</span>
                        <span x-show="showingRecoveryCodes">{{ __('profile_security.hide_recovery_codes') }}</span>
                    </x-secondary-button>

                    <x-secondary-button type="button" @click="showRegenerateModal = true" x-bind:disabled="loading || setupLoading || uncertain" class="min-h-11 text-xs">
                        {{ __('profile_security.regenerate_recovery_codes') }}
                    </x-secondary-button>
                </div>
            </div>

            {{-- Ephemeral Recovery Codes Display --}}
            <div x-cloak x-show="showingRecoveryCodes && recoveryCodes.length > 0" x-transition class="mt-4 p-4 rounded-lg bg-surface-soft/60 border border-border space-y-3">
                <p class="text-xs font-semibold text-danger">
                    {{ __('profile_security.recovery_codes_warning') }}
                </p>

                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 font-mono text-xs select-all" dir="ltr">
                    <template x-for="code in recoveryCodes" :key="code">
                        <div class="p-2.5 bg-white rounded border border-border text-center text-text-primary font-bold shadow-xs tracking-wider" x-text="code"></div>
                    </template>
                </div>

                <div class="flex flex-wrap items-center gap-3 pt-2">
                    <button type="button" @click="copyToClipboard(recoveryCodes.join('\n'), 'codes')" class="min-h-11 px-3 rounded-control border border-border bg-white text-xs font-bold text-text-primary hover:bg-surface-soft flex items-center gap-1.5 transition-colors cursor-pointer">
                        <span x-show="copyFeedback !== 'codes'">{{ __('profile_security.copy_codes') }}</span>
                        <span x-show="copyFeedback === 'codes'" class="text-success font-bold">{{ __('profile_security.codes_copied') }}</span>
                    </button>

                    <button type="button" @click="downloadRecoveryCodes" class="min-h-11 px-3 rounded-control border border-border bg-white text-xs font-semibold text-text-secondary hover:text-text-primary hover:bg-surface-soft flex items-center gap-1.5 transition-colors cursor-pointer">
                        {{ __('profile_security.download_codes') }}
                    </button>
                </div>
            </div>
        </div>

        {{-- Disable 2FA Action --}}
        <div class="pt-4 border-t border-border flex items-center justify-between">
            <x-danger-button type="button" @click="showDisableModal = true" x-bind:disabled="loading || setupLoading || uncertain" class="min-h-11">
                {{ __('profile_security.disable_action') }}
            </x-danger-button>
        </div>
    </div>

    {{-- Modal 1: Password Confirmation Reauthentication (HTTP 423) --}}
    <div x-cloak x-show="showPasswordModal"
         class="fixed inset-0 z-50 overflow-y-auto px-4 py-6 sm:px-0"
         role="dialog"
         aria-modal="true"
         aria-labelledby="confirm-password-modal-title"
         x-trap.inert.noscroll="showPasswordModal"
         @keydown.escape.window="if(showPasswordModal) { $event.preventDefault(); $event.stopPropagation(); cancelPasswordConfirm(); }">
        <div class="fixed inset-0 bg-slate-900/50 backdrop-blur-xs transition-opacity" @click="cancelPasswordConfirm"></div>

        <div class="relative mb-6 bg-white rounded-card overflow-hidden shadow-panel border border-border max-w-md mx-auto my-8 p-6 z-10 space-y-4">
            <div class="flex items-center gap-2">
                <x-icon name="lock" class="w-5 h-5 text-primary shrink-0" />
                <h3 id="confirm-password-modal-title" class="text-base font-bold text-text-primary">
                    {{ __('profile_security.confirm_password_title') }}
                </h3>
            </div>

            <p class="text-xs text-text-secondary leading-relaxed">
                {{ __('profile_security.confirm_password_desc') }}
            </p>

            <form @submit.prevent="submitPasswordConfirm" class="space-y-4">
                <div>
                    <x-input-label for="reauth_password_input" :value="__('profile_security.password_label')" class="sr-only" />
                    <x-text-input id="reauth_password_input"
                                  x-ref="passwordConfirmInput"
                                  x-model="confirmPasswordInput"
                                  type="password"
                                  placeholder="{{ __('profile_security.password_placeholder') }}"
                                  class="w-full"
                                  autocomplete="current-password"
                                  required />
                    <template x-if="confirmPasswordError">
                        <p class="mt-1.5 text-xs font-semibold text-danger" x-text="confirmPasswordError"></p>
                    </template>
                </div>

                <div class="flex justify-end gap-3 pt-2">
                    <x-secondary-button type="button" @click="cancelPasswordConfirm" x-bind:disabled="confirmingPassword" class="min-h-11">
                        {{ __('profile_security.cancel_button') }}
                    </x-secondary-button>

                    <x-primary-button type="submit" x-bind:disabled="confirmingPassword" class="min-h-11">
                        <span x-show="!confirmingPassword">{{ __('profile_security.confirm_button') }}</span>
                        <span x-show="confirmingPassword">{{ __('profile_security.processing') }}</span>
                    </x-primary-button>
                </div>
            </form>
        </div>
    </div>

    {{-- Modal 2: Confirm Disable 2FA --}}
    <div x-cloak x-show="showDisableModal"
         class="fixed inset-0 z-50 overflow-y-auto px-4 py-6 sm:px-0"
         role="dialog"
         aria-modal="true"
         aria-labelledby="confirm-disable-modal-title"
         x-trap.inert.noscroll="showDisableModal && !showPasswordModal"
         @keydown.escape.window="if(showDisableModal && !loading) { $event.preventDefault(); $event.stopPropagation(); showDisableModal = false; }">
        <div class="fixed inset-0 bg-slate-900/50 backdrop-blur-xs transition-opacity" @click="showDisableModal = false"></div>

        <div class="relative mb-6 bg-white rounded-card overflow-hidden shadow-panel border border-border max-w-md mx-auto my-8 p-6 z-10 space-y-4">
            <h3 id="confirm-disable-modal-title" class="text-base font-bold text-text-primary">
                {{ __('profile_security.disable_modal_title') }}
            </h3>

            <p class="text-xs text-text-secondary leading-relaxed">
                {{ __('profile_security.disable_modal_desc') }}
            </p>

            <div class="flex justify-end gap-3 pt-2">
                <x-secondary-button type="button" @click="showDisableModal = false" x-bind:disabled="loading || setupLoading || uncertain" class="min-h-11">
                    {{ __('profile_security.cancel_button') }}
                </x-secondary-button>

                <x-danger-button type="button" @click="disableTwoFactor" x-bind:disabled="loading || setupLoading || uncertain" class="min-h-11">
                    <span x-show="!(loading && loadingAction === 'disabling')">{{ __('profile_security.confirm_disable_button') }}</span>
                    <span x-show="loading && loadingAction === 'disabling'">{{ __('profile_security.processing') }}</span>
                </x-danger-button>
            </div>
        </div>
    </div>

    {{-- Modal 3: Confirm Regenerate Recovery Codes --}}
    <div x-cloak x-show="showRegenerateModal"
         class="fixed inset-0 z-50 overflow-y-auto px-4 py-6 sm:px-0"
         role="dialog"
         aria-modal="true"
         aria-labelledby="confirm-regenerate-modal-title"
         x-trap.inert.noscroll="showRegenerateModal && !showPasswordModal"
         @keydown.escape.window="if(showRegenerateModal && !loading) { $event.preventDefault(); $event.stopPropagation(); showRegenerateModal = false; }">
        <div class="fixed inset-0 bg-slate-900/50 backdrop-blur-xs transition-opacity" @click="showRegenerateModal = false"></div>

        <div class="relative mb-6 bg-white rounded-card overflow-hidden shadow-panel border border-border max-w-md mx-auto my-8 p-6 z-10 space-y-4">
            <h3 id="confirm-regenerate-modal-title" class="text-base font-bold text-text-primary">
                {{ __('profile_security.regenerate_modal_title') }}
            </h3>

            <p class="text-xs text-text-secondary leading-relaxed">
                {{ __('profile_security.regenerate_modal_desc') }}
            </p>

            <div class="flex justify-end gap-3 pt-2">
                <x-secondary-button type="button" @click="showRegenerateModal = false" x-bind:disabled="loading || setupLoading || uncertain" class="min-h-11">
                    {{ __('profile_security.cancel_button') }}
                </x-secondary-button>

                <x-primary-button type="button" @click="regenerateRecoveryCodes" x-bind:disabled="loading || setupLoading || uncertain" class="min-h-11">
                    <span x-show="!(loading && loadingAction === 'regenerating')">{{ __('profile_security.confirm_regenerate_button') }}</span>
                    <span x-show="loading && loadingAction === 'regenerating'">{{ __('profile_security.processing') }}</span>
                </x-primary-button>
            </div>
        </div>
    </div>
</section>
