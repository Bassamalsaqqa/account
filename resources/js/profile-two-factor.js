// Accessible responsive self-service Two-Factor Authentication client module.
// Strictly ephemeral: secrets & recovery codes are kept only in transient memory and purged on hide/destroy/navigation.
// Authorized secrets render only in this transient UI, never in Livewire snapshots, URLs or logs.

export function profileTwoFactor(config = {}) {
    const routes = config.routes || {};
    const translations = config.translations || {};
    let generation = 0;
    let reauthentication = null;
    const controllers = new Map();
    const cancelled = () => new DOMException('Operation cancelled', 'AbortError');

    return {
        // Lifecycle status: 'disabled' | 'pending' | 'enabled'
        status: config.initialStatus || 'disabled',
        loading: false,
        setupLoading: false,
        uncertain: false,
        loadingAction: null,
        error: null,
        success: null,
        copyFeedback: null,

        // Ephemeral secrets (purged on close/unload/destroy/disable)
        qrSvg: null,
        secretKey: null,
        confirmationCode: '',
        recoveryCodes: [],
        showingRecoveryCodes: false,

        // Password confirmation (HTTP 423 reauthentication)
        showPasswordModal: false,
        confirmPasswordInput: '',
        confirmPasswordError: null,
        confirmingPassword: false,
        pendingAction: null,

        // Modals
        showDisableModal: false,
        showRegenerateModal: false,

        // Listeners cleanup
        _cleanupListeners: null,

        init() {
            const purge = () => this.clearSecrets();
            window.addEventListener('beforeunload', purge);
            window.addEventListener('pagehide', purge);
            const background = () => { if (document.hidden) purge(); };
            document.addEventListener('visibilitychange', background);
            document.addEventListener('livewire:navigating', purge);

            this._cleanupListeners = () => {
                window.removeEventListener('beforeunload', purge);
                window.removeEventListener('pagehide', purge);
                document.removeEventListener('visibilitychange', background);
                document.removeEventListener('livewire:navigating', purge);
            };

            // If user arrives while enrollment is pending, automatically load QR and setup key
            if (this.status === 'pending') {
                this.loadSetupData();
            }
        },

        destroy() {
            if (typeof this._cleanupListeners === 'function') {
                this._cleanupListeners();
            }
            this.clearSecrets();
        },

        clearSecrets() {
            generation++;
            for (const [controller, method] of controllers) {
                if (method !== 'GET') this.uncertain = true;
                controller.abort();
            }
            controllers.clear();
            reauthentication?.reject(cancelled());
            reauthentication = null;
            this.showPasswordModal = false;
            this.showDisableModal = false;
            this.showRegenerateModal = false;
            this.loading = false;
            this.setupLoading = false;
            this.confirmingPassword = false;
            this.qrSvg = null;
            this.secretKey = null;
            this.recoveryCodes = [];
            this.showingRecoveryCodes = false;
            this.confirmPasswordInput = '';
            this.confirmationCode = '';
            this.pendingAction = null;
        },

        getCsrfToken() {
            return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        },

        async request(url, options = {}) {
            const version = generation;
            const controller = new AbortController();
            controllers.set(controller, options.method || 'GET');
            const timeout = setTimeout(() => controller.abort(), 20000);
            let response;
            try {
                response = await fetch(url, {
                    method: options.method || 'GET', credentials: 'same-origin', cache: 'no-store',
                    signal: controller.signal,
                    headers: {
                        Accept: 'application/json', 'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': this.getCsrfToken(),
                    },
                    body: options.body ? JSON.stringify(options.body) : undefined,
                });
                if (version !== generation) throw cancelled();
                if (response.status === 423 && !options.confirmedRetry) {
                    // This request was rejected before mutation; waiting for reauthentication
                    // must not be mistaken for an in-flight financial/security operation.
                    controllers.delete(controller);
                    clearTimeout(timeout);
                    if (!reauthentication) {
                        let resolve, reject;
                        const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
                        reauthentication = { promise, resolve, reject };
                        this.showPasswordModal = true;
                        this.confirmPasswordError = null;
                        this.$nextTick(() => this.$refs?.passwordConfirmInput?.focus());
                    }
                    await reauthentication.promise;
                    if (version !== generation) throw cancelled();
                    return await this.request(url, {...options, confirmedRetry: true});
                }
                const json = response.headers.get('content-type')?.includes('application/json');
                const data = json ? await response.json() : null;
                if (version !== generation) throw cancelled();
                if (!response.ok) {
                    const error = new Error(data?.errors?.password?.[0] || data?.message || translations.generic_error);
                    error.status = response.status;
                    error.errors = data?.errors || {};
                    throw error;
                }
                return data;
            } catch (error) {
                if (version !== generation) throw cancelled();
                if ((!response || response.ok || response.status >= 500) && (options.method || 'GET') !== 'GET') {
                    this.uncertain = true;
                    // A response failure may follow a committed Fortify write.
                    // Previous codes/setup material can no longer be trusted.
                    this.clearSecrets();
                    throw new Error(translations.request_uncertain || translations.generic_error);
                }
                if (error.name === 'AbortError') throw new Error(translations.generic_error);
                throw error;
            } finally {
                clearTimeout(timeout);
                controllers.delete(controller);
            }
        },

        async submitPasswordConfirm() {
            if (this.confirmingPassword || !reauthentication) return;
            if (!this.confirmPasswordInput) {
                this.confirmPasswordError = translations.password_required;
                return;
            }
            this.confirmingPassword = true;
            this.confirmPasswordError = null;
            try {
                await this.request(routes.passwordConfirm, {
                    method: 'POST', body: {password: this.confirmPasswordInput}, confirmedRetry: true,
                });
                this.showPasswordModal = false;
                this.confirmPasswordInput = '';
                const waiting = reauthentication;
                reauthentication = null;
                waiting?.resolve();
            } catch (error) {
                if (error.name !== 'AbortError') this.confirmPasswordError = error.message;
            } finally { this.confirmingPassword = false; }
        },

        cancelPasswordConfirm() { this.clearSecrets(); },

        async enableTwoFactor() {
            if (this.loading || this.uncertain) return;
            this.resetFeedback();
            this.loading = true;
            this.loadingAction = 'enabling';

            try {
                await this.request(routes.enable, { method: 'POST' });
                this.status = 'pending';
                await this.loadSetupData();
            } catch (err) {
                if (err && err.name !== 'AbortError' && err.message) {
                    this.error = err.message;
                }
            } finally {
                this.loading = false;
                this.loadingAction = null;
            }
        },

        async loadSetupData() {
            if (this.setupLoading || this.uncertain) return;
            this.setupLoading = true;
            const version = generation;
            try {
                const qrData = await this.request(routes.qrCode);
                const secretData = await this.request(routes.secretKey);
                if (version !== generation) throw cancelled();

                if (qrData && qrData.svg) {
                    this.qrSvg = qrData.svg;
                }
                if (secretData && secretData.secretKey) {
                    this.secretKey = secretData.secretKey;
                }
            } catch (err) {
                if (err && err.name !== 'AbortError' && err.message) {
                    this.error = err.message;
                }
            } finally { this.setupLoading = false; }
        },

        async confirmTwoFactor() {
            if (this.loading || this.uncertain) return;
            this.resetFeedback();
            const cleanCode = (this.confirmationCode || '').replace(/\s+/g, '');
            if (!cleanCode) {
                this.error = translations.code_required || 'Confirmation code is required';
                return;
            }

            this.loading = true;
            this.loadingAction = 'confirming';

            try {
                await this.request(routes.confirm, {
                    method: 'POST',
                    body: { code: cleanCode },
                });

                this.status = 'enabled';
                this.qrSvg = null;
                this.secretKey = null;
                this.confirmationCode = '';
                this.success = translations.enabled_success || 'Two-factor authentication enabled successfully.';

                // Immediately retrieve and display recovery codes once
                await this.fetchRecoveryCodes();
            } catch (err) {
                if (err.status === 422 && err.errors?.code) {
                    this.error = err.errors.code[0];
                } else if (err.name !== 'AbortError' && err.message) {
                    this.error = err.message;
                }
            } finally {
                this.loading = false;
                this.loadingAction = null;
            }
        },

        async cancelPending() {
            if (this.loading || this.uncertain) return;
            this.resetFeedback();
            this.loading = true;
            this.loadingAction = 'cancelling';

            try {
                await this.request(routes.disable, { method: 'DELETE' });
                this.status = 'disabled';
                this.clearSecrets();
                this.success = translations.setup_cancelled || 'Setup cancelled.';
            } catch (err) {
                if (err && err.name !== 'AbortError' && err.message) {
                    this.error = err.message;
                }
            } finally {
                this.loading = false;
                this.loadingAction = null;
            }
        },

        async toggleRecoveryCodes() {
            if (this.loading) return;
            if (this.showingRecoveryCodes) {
                this.recoveryCodes = [];
                this.showingRecoveryCodes = false;
                return;
            }
            await this.fetchRecoveryCodes();
        },

        async fetchRecoveryCodes() {
            const version = generation;
            this.resetFeedback();
            this.loading = true;
            this.loadingAction = 'loadingCodes';

            try {
                const codes = await this.request(routes.recoveryCodes);
                if (version !== generation) throw cancelled();
                if (Array.isArray(codes)) {
                    this.recoveryCodes = codes;
                    this.showingRecoveryCodes = true;
                }
            } catch (err) {
                if (err && err.name !== 'AbortError' && err.message) {
                    this.error = err.message;
                }
            } finally {
                this.loading = false;
                this.loadingAction = null;
            }
        },

        async regenerateRecoveryCodes() {
            if (this.loading || this.uncertain) return;
            this.showRegenerateModal = false;
            this.resetFeedback();
            this.loading = true;
            this.loadingAction = 'regenerating';

            try {
                await this.request(routes.regenerateRecoveryCodes, { method: 'POST' });
                this.showRegenerateModal = false;
                await this.fetchRecoveryCodes();
                this.success = translations.codes_regenerated || 'Recovery codes regenerated successfully.';
            } catch (err) {
                if (err && err.name !== 'AbortError' && err.message) {
                    this.error = err.message;
                }
            } finally {
                this.loading = false;
                this.loadingAction = null;
            }
        },

        async disableTwoFactor() {
            if (this.loading || this.uncertain) return;
            this.showDisableModal = false;
            this.resetFeedback();
            this.loading = true;
            this.loadingAction = 'disabling';

            try {
                await this.request(routes.disable, { method: 'DELETE' });
                this.status = 'disabled';
                this.showDisableModal = false;
                this.clearSecrets();
                this.success = translations.disabled_success || 'Two-factor authentication disabled.';
            } catch (err) {
                if (err && err.name !== 'AbortError' && err.message) {
                    this.error = err.message;
                }
            } finally {
                this.loading = false;
                this.loadingAction = null;
            }
        },

        resetFeedback() {
            this.error = null;
            this.success = null;
            this.copyFeedback = null;
        },

        async copyToClipboard(text, type) {
            if (!text) return;
            try {
                if (navigator?.clipboard?.writeText) {
                    await navigator.clipboard.writeText(text);
                } else {
                    const el = document.createElement('textarea');
                    el.value = text;
                    el.style.position = 'fixed';
                    el.style.opacity = '0';
                    document.body.appendChild(el);
                    el.select();
                    document.execCommand('copy');
                    document.body.removeChild(el);
                }
                this.copyFeedback = type;
                setTimeout(() => {
                    if (this.copyFeedback === type) {
                        this.copyFeedback = null;
                    }
                }, 3000);
            } catch (e) {
                // Clipboard write permission denied or unavailable
            }
        },

        downloadRecoveryCodes() {
            if (!this.recoveryCodes.length) return;
            const content = this.recoveryCodes.join('\r\n');
            const blob = new Blob([content], { type: 'text/plain;charset=utf-8' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'two-factor-recovery-codes.txt';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            setTimeout(() => URL.revokeObjectURL(url), 1000);
        },
    };
}

if (typeof window !== 'undefined') {
    window.profileTwoFactor = profileTwoFactor;
    const register = () => window.Alpine?.data('profileTwoFactor', profileTwoFactor);
    if (window.Alpine) register();
    else document.addEventListener('alpine:init', register, { once: true });
}
