import './bootstrap';
// Unsaved-form corrections are text-only and require an explicit action.
import './transaction-recorder';
import './assistant-memory';
import './assistant-browser-voice';
import './pwa';
import './financial-share-controls';

import './mobile-shell';

import './document-operations';
import './camera-scanner';
import './global-search';
import './barcode-entry';
import './product-search-picker';
import './profile-two-factor';
import './company-data-download';

import './money-display';
import './table-navigation';
import { isUpgradeResponse } from './maintenance-status';

// Graceful 503 Maintenance Handling for Livewire
if (typeof window !== 'undefined') {
    document.addEventListener('livewire:init', () => {
        if (window.Livewire && window.Livewire.hook) {
            window.Livewire.hook('request', ({ fail }) => {
                fail(({ status, content, preventDefault }) => {
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
