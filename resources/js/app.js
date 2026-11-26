import './bootstrap';
// Unsaved-form corrections are text-only and require an explicit action.
import './transaction-recorder';
import './assistant-memory';
import './assistant-browser-voice';
import './pwa';
import './financial-share-controls';

import './mobile-shell';

import './document-operations';
import './document-row-mutations';
import './document-row-focus';
import './numeric-select-on-focus';
import './camera-scanner';
import './global-search';
import './searchable-combobox';
import './barcode-entry';
import './profile-two-factor';
import './company-data-download';

import './money-display';
import './table-navigation';
import { isUpgradeResponse } from './maintenance-status';
import { launchProblemReporter } from './problem-reporter-launcher';

// Graceful 503 Maintenance & Diagnostic Request-ID Correlation for Livewire
if (typeof window !== 'undefined') {
    const REQ_ID_RE = /^req_[0-9a-f]{32}$/;
    const clearLastFailure = () => { window.__appLastFailedRequest = null; };
    document.addEventListener('livewire:navigating', clearLastFailure);
    window.addEventListener('pagehide', clearLastFailure);

    document.addEventListener('click', (event) => {
        const trigger = event.target?.closest?.('[data-report-problem-trigger]');
        if (!trigger) return;
        event.preventDefault();
        launchProblemReporter(trigger);
    });

    document.addEventListener('livewire:init', () => {
        if (window.Livewire && window.Livewire.hook) {
            window.Livewire.hook('request', ({ respond, fail }) => {
                let responseRequestId = null;
                respond?.(({ status, response }) => {
                    if (status >= 400) {
                        const headerId = response?.headers?.get?.('X-Request-Id');
                        responseRequestId = typeof headerId === 'string' && REQ_ID_RE.test(headerId) ? headerId : null;
                        window.__appLastFailedRequest = { requestId: responseRequestId, status };
                    } else {
                        clearLastFailure();
                    }
                });
                fail(({ status, content, preventDefault }) => {
                    if (!responseRequestId) {
                        window.__appLastFailedRequest = {
                            requestId: null,
                            status: Number.isInteger(status) ? status : null,
                        };
                    }
                    if (isUpgradeResponse(status, content)) {
                        preventDefault();
                        let banner = document.getElementById('maintenance-upgrade-banner');
                        if (!banner) {
                            banner = document.createElement('div');
                            banner.id = 'maintenance-upgrade-banner';
                            banner.setAttribute('role', 'alert');
                            banner.setAttribute('aria-live', 'assertive');
                            banner.className = 'fixed top-4 inset-x-4 max-w-lg mx-auto z-[9999] p-3.5 rounded-xl bg-amber-50 border border-amber-300 text-amber-900 shadow-lg text-xs font-bold flex items-center justify-between gap-3';
                            const isAr = document.documentElement.dir === 'rtl' || document.documentElement.lang === 'ar';
                            banner.innerHTML = `
                                <div class="flex items-center gap-2">
                                    <span class="inline-block w-2.5 h-2.5 rounded-full bg-amber-500 animate-pulse"></span>
                                    <span>${isAr ? 'التطبيق قيد التحديث. تم الاحتفاظ بمدخلاتك، يرجى المحاولة بعد قليل.' : 'App update in progress. Your input is preserved; please retry shortly.'}</span>
                                </div>
                                <button type="button" onclick="this.parentElement.remove()" class="text-amber-700 hover:text-amber-900 px-1 font-extrabold cursor-pointer">&times;</button>
                            `;
                            document.body.appendChild(banner);
                        }
                    }
                });
            });
        }
    });
}
