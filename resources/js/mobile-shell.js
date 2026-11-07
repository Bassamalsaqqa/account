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
            return typeof this.openGroups[key] === 'boolean' ? this.openGroups[key] : true;
        },
        toggleGroup(key, isRouteActive) {
            this.openGroups[key] = !this.isGroupOpen(key, isRouteActive);
            write('accounting.sidebar.open', JSON.stringify(this.openGroups));
        },
    };
}

export function quickAddMenu(browser = window, page = document) {
    let onNav;
    return {
        init() {
            onNav = () => this.close(false);
            page.addEventListener('livewire:navigating', onNav);
            browser.addEventListener('popstate', onNav);
            if (typeof this.$cleanup === 'function') {
                this.$cleanup(() => this.destroy());
            }
        },
        destroy() {
            if (onNav) {
                page.removeEventListener('livewire:navigating', onNav);
                browser.removeEventListener('popstate', onNav);
            }
        },
        isOpen() {
            return this.$el ? this.$el.hasAttribute('open') : false;
        },
        close(returnFocus = true) {
            if (this.isOpen()) {
                this.$el.removeAttribute('open');
                if (returnFocus) {
                    this.$refs.trigger?.focus?.();
                }
            }
        },
        handleKeydown(event) {
            if (event.key === 'Escape' && this.isOpen()) {
                event.preventDefault();
                event.stopPropagation();
                this.close(true);
            }
        },
    };
}

export function userMenu(browser = window, page = document) {
    let onNav;
    return {
        openUserMenu: false,
        init() {
            onNav = () => this.close(false);
            page.addEventListener('livewire:navigating', onNav);
            browser.addEventListener('popstate', onNav);
            if (typeof this.$cleanup === 'function') {
                this.$cleanup(() => this.destroy());
            }
        },
        destroy() {
            if (onNav) {
                page.removeEventListener('livewire:navigating', onNav);
                browser.removeEventListener('popstate', onNav);
            }
        },
        toggle() {
            this.openUserMenu = !this.openUserMenu;
        },
        close(returnFocus = true) {
            if (this.openUserMenu) {
                this.openUserMenu = false;
                if (returnFocus) {
                    this.$refs.userMenuTrigger?.focus?.();
                }
            }
        },
        handleKeydown(event) {
            if (event.key === 'Escape' && this.openUserMenu) {
                event.preventDefault();
                event.stopPropagation();
                this.close(true);
            }
        },
    };
}

export function dropdownMenu(browser = window, page = document) {
    let onNav;
    return {
        open: false,
        init() {
            onNav = () => this.close(false);
            page.addEventListener('livewire:navigating', onNav);
            browser.addEventListener('popstate', onNav);
            if (typeof this.$cleanup === 'function') {
                this.$cleanup(() => this.destroy());
            }
        },
        destroy() {
            if (onNav) {
                page.removeEventListener('livewire:navigating', onNav);
                browser.removeEventListener('popstate', onNav);
            }
        },
        toggle() {
            this.open = !this.open;
        },
        close(returnFocus = true) {
            if (this.open) {
                this.open = false;
                if (returnFocus) {
                    const focusable = this.$refs.trigger?.querySelector?.('button, a, [tabindex]') || this.$refs.trigger;
                    focusable?.focus?.();
                }
            }
        },
        handleKeydown(event) {
            if (event.key === 'Escape' && this.open) {
                event.preventDefault();
                event.stopPropagation();
                this.close(true);
            }
        },
    };
}

if (typeof window !== 'undefined') {
    const register = () => {
        window.Alpine.data('accountingMobileShell', mobileShell);
        window.Alpine.data('accountingSidebarNavigation', sidebarNavigation);
        window.Alpine.data('accountingSettingsNavigation', () => navigationScrollMemory('accounting.settings.scroll'));
        window.Alpine.data('accountingQuickAdd', quickAddMenu);
        window.Alpine.data('accountingUserMenu', userMenu);
        window.Alpine.data('accountingDropdown', dropdownMenu);
    };
    if (window.Alpine) register();
    else document.addEventListener('alpine:init', register, { once: true });
}
