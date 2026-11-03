// Viewport geometry affects presentation only; transaction values stay on Livewire.
export function mobileShell(browser = window, page = document) {
    let viewportChanged;
    let focusChanged;
    return {
        mobileMenuOpen: false,
        mobileLayout: browser.innerWidth < 1024,
        sidebarCollapsed: (() => {
            try {
                return browser.localStorage?.getItem('accounting.sidebar.collapsed') === 'true';
            } catch {
                return false;
            }
        })(),
        toggleSidebarCollapse() {
            this.sidebarCollapsed = !this.sidebarCollapsed;
            page.documentElement.setAttribute?.('data-sidebar-collapsed', String(this.sidebarCollapsed));
            try {
                browser.localStorage?.setItem('accounting.sidebar.collapsed', JSON.stringify(this.sidebarCollapsed));
            } catch {}
        },
        init() {
            page.documentElement.setAttribute?.('data-sidebar-collapsed', String(this.sidebarCollapsed));
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

export function navigationScrollMemory(key, browser = window) {
    return {
        restoreScroll() {
            try {
                const saved = Number(browser.sessionStorage?.getItem(key) ?? '0');
                if (this.$el && Number.isFinite(saved) && saved >= 0) this.$el.scrollTop = saved;
            } catch {}
        },
        saveScroll() {
            try {
                if (this.$el) browser.sessionStorage?.setItem(key, String(this.$el.scrollTop));
            } catch {}
        },
    };
}

export function sidebarNavigation(browser = window) {
    const read = (key, fallback) => {
        try { return browser.sessionStorage?.getItem(key) ?? fallback; }
        catch { return fallback; }
    };
    const write = (key, value) => {
        try { browser.sessionStorage?.setItem(key, value); } catch {}
    };
    let openGroups = {};
    try {
        const parsed = JSON.parse(read('accounting.sidebar.open', '{}'));
        if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) openGroups = parsed;
    } catch {}
    return {
        ...navigationScrollMemory('accounting.sidebar.scroll', browser),
        openGroups,
        isGroupOpen(key, isRouteActive) {
            if (!this.mobileLayout && this.sidebarCollapsed) return true;
            return isRouteActive || this.openGroups[key] !== false;
        },
        toggleGroup(key, isRouteActive) {
            this.openGroups[key] = !this.isGroupOpen(key, isRouteActive);
            write('accounting.sidebar.open', JSON.stringify(this.openGroups));
        },
    };
}

if (typeof window !== 'undefined') {
    const register = () => {
        window.Alpine.data('accountingMobileShell', mobileShell);
        window.Alpine.data('accountingSidebarNavigation', sidebarNavigation);
        window.Alpine.data('accountingSettingsNavigation', () => navigationScrollMemory('accounting.settings.scroll'));
    };
    if (window.Alpine) register();
    else document.addEventListener('alpine:init', register, { once: true });
}
