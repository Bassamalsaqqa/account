// Viewport geometry affects presentation only; transaction values stay on Livewire.
export function mobileShell(browser = window, page = document) {
    let viewportChanged;
    let focusChanged;
    return {
        mobileMenuOpen: false,
        mobileLayout: browser.innerWidth < 1024,
        init() {
            viewportChanged = () => {
                this.mobileLayout = browser.innerWidth < 1024;
                const editing = page.activeElement?.matches('input, textarea, select, [contenteditable="true"]');
                const viewport = browser.visualViewport;
                const keyboard = this.mobileLayout && editing && viewport && browser.innerHeight - viewport.height > 150;
                page.documentElement.classList.toggle('mobile-keyboard-open', Boolean(keyboard));
                if (!this.mobileLayout) this.mobileMenuOpen = false;
            };
            focusChanged = () => browser.requestAnimationFrame(viewportChanged);
            browser.addEventListener('resize', viewportChanged);
            browser.visualViewport?.addEventListener('resize', viewportChanged);
            page.addEventListener('focusin', focusChanged);
            page.addEventListener('focusout', focusChanged);
            viewportChanged();
        },
        destroy() {
            browser.removeEventListener('resize', viewportChanged);
            browser.visualViewport?.removeEventListener('resize', viewportChanged);
            page.removeEventListener('focusin', focusChanged);
            page.removeEventListener('focusout', focusChanged);
            page.documentElement.classList.remove('mobile-keyboard-open');
        },
    };
}

if (typeof window !== 'undefined') {
    const register = () => window.Alpine.data('accountingMobileShell', mobileShell);
    if (window.Alpine) register();
    else document.addEventListener('alpine:init', register, { once: true });
}
