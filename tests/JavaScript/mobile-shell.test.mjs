import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mobileShell, sidebarNavigation, quickAddMenu, userMenu, dropdownMenu } from '../../resources/js/mobile-shell.js';

test('sidebar navigation restores the bottom scroll position and saves before leaving', () => {
    const stored = new Map([['accounting.sidebar.scroll', '890']]);
    const nav = sidebarNavigation({ sessionStorage: {
        getItem: key => stored.get(key), setItem: (key, value) => stored.set(key, value),
    } });
    nav.$el = { scrollTop: 0 };
    nav.restoreScroll();
    assert.equal(nav.$el.scrollTop, 890);
    nav.$el.scrollTop = 940;
    nav.saveScroll();
    assert.equal(stored.get('accounting.sidebar.scroll'), '940');
});

test('sidebar remains usable with blocked or malformed storage and in collapsed mode', () => {
    const nav = sidebarNavigation({ get sessionStorage() { throw new Error('blocked'); } });
    nav.mobileLayout = false;
    nav.sidebarCollapsed = false;
    assert.equal(nav.isGroupOpen('sales', false), true);
    nav.toggleGroup('sales', false);
    assert.equal(nav.isGroupOpen('sales', false), false);
    nav.sidebarCollapsed = true;
    assert.equal(nav.isGroupOpen('sales', false), true);
    nav.sidebarCollapsed = false;
    assert.equal(nav.isGroupOpen('sales', true), false);
    nav.toggleGroup('sales', true);
    assert.equal(nav.isGroupOpen('sales', true), true);
    const corrupt = sidebarNavigation({ sessionStorage: { getItem: () => 'broken' } });
    assert.deepEqual(corrupt.openGroups, {});
});

test('mobileShell: defaults to false when localStorage is empty', () => {
    const fakeStorage = new Map();
    const fakeBrowser = {
        innerWidth: 1200,
        localStorage: {
            getItem: (k) => fakeStorage.get(k) ?? null,
            setItem: (k, v) => fakeStorage.set(k, String(v)),
        },
        addEventListener: () => {},
        removeEventListener: () => {},
    };
    const fakeDoc = {
        documentElement: { classList: { toggle: () => {}, remove: () => {} } },
        addEventListener: () => {},
        removeEventListener: () => {},
    };

    const shell = mobileShell(fakeBrowser, fakeDoc);
    assert.equal(shell.sidebarCollapsed, false);
    assert.equal(shell.mobileLayout, false);
});

test('mobileShell: loads sidebarCollapsed true from localStorage', () => {
    const fakeStorage = new Map([['accounting.sidebar.collapsed', 'true']]);
    const fakeBrowser = {
        innerWidth: 1200,
        localStorage: {
            getItem: (k) => fakeStorage.get(k) ?? null,
            setItem: (k, v) => fakeStorage.set(k, String(v)),
        },
        addEventListener: () => {},
        removeEventListener: () => {},
    };
    const fakeDoc = {
        documentElement: { classList: { toggle: () => {}, remove: () => {} } },
        addEventListener: () => {},
        removeEventListener: () => {},
    };

    const shell = mobileShell(fakeBrowser, fakeDoc);
    assert.equal(shell.sidebarCollapsed, true);
});

test('mobileShell: toggleSidebarCollapse toggles and persists state', () => {
    const fakeStorage = new Map();
    const fakeBrowser = {
        innerWidth: 1200,
        localStorage: {
            getItem: (k) => fakeStorage.get(k) ?? null,
            setItem: (k, v) => fakeStorage.set(k, String(v)),
        },
        addEventListener: () => {},
        removeEventListener: () => {},
    };
    const fakeDoc = {
        documentElement: { classList: { toggle: () => {}, remove: () => {} } },
        addEventListener: () => {},
        removeEventListener: () => {},
    };

    const shell = mobileShell(fakeBrowser, fakeDoc);
    assert.equal(shell.sidebarCollapsed, false);

    shell.toggleSidebarCollapse();
    assert.equal(shell.sidebarCollapsed, true);
    assert.equal(fakeStorage.get('accounting.sidebar.collapsed'), 'true');

    shell.toggleSidebarCollapse();
    assert.equal(shell.sidebarCollapsed, false);
    assert.equal(fakeStorage.get('accounting.sidebar.collapsed'), 'false');
});

test('mobileShell: gracefully handles corrupted localStorage JSON or storage errors', () => {
    const throwingBrowser = {
        innerWidth: 1200,
        localStorage: {
            getItem: () => '{invalid json}',
            setItem: () => { throw new Error('QuotaExceeded'); },
        },
        addEventListener: () => {},
        removeEventListener: () => {},
    };
    const fakeDoc = {
        documentElement: { classList: { toggle: () => {}, remove: () => {} } },
        addEventListener: () => {},
        removeEventListener: () => {},
    };

    const shell = mobileShell(throwingBrowser, fakeDoc);
    assert.equal(shell.sidebarCollapsed, false);
    assert.doesNotThrow(() => shell.toggleSidebarCollapse());
    assert.equal(shell.sidebarCollapsed, true);
});

test('quickAddMenu: opens, closes with focus return, dismisses on escape and navigation, and cleans up listeners', () => {
    let navigatingListener = null;
    let popstateListener = null;
    const fakeDoc = {
        addEventListener: (event, handler) => {
            if (event === 'livewire:navigating') navigatingListener = handler;
        },
        removeEventListener: (event, handler) => {
            if (event === 'livewire:navigating' && navigatingListener === handler) navigatingListener = null;
        },
    };
    const fakeBrowser = {
        addEventListener: (event, handler) => {
            if (event === 'popstate') popstateListener = handler;
        },
        removeEventListener: (event, handler) => {
            if (event === 'popstate' && popstateListener === handler) popstateListener = null;
        },
    };

    let focused = false;
    const triggerEl = { focus: () => { focused = true; } };
    let hasOpenAttr = true;
    const detailsEl = {
        hasAttribute: (attr) => attr === 'open' && hasOpenAttr,
        removeAttribute: (attr) => { if (attr === 'open') hasOpenAttr = false; },
        setAttribute: (attr) => { if (attr === 'open') hasOpenAttr = true; },
    };

    const menu = quickAddMenu(fakeBrowser, fakeDoc);
    menu.$el = detailsEl;
    menu.$refs = { trigger: triggerEl };

    let cleanupCallback = null;
    menu.$cleanup = (fn) => { cleanupCallback = fn; };
    menu.init();

    assert.equal(typeof navigatingListener, 'function');
    assert.equal(typeof popstateListener, 'function');
    assert.equal(menu.isOpen(), true);

    // Escape closes and returns focus
    let prevented = false;
    let stopped = false;
    menu.handleKeydown({ key: 'Escape', preventDefault: () => { prevented = true; }, stopPropagation: () => { stopped = true; } });
    assert.equal(menu.isOpen(), false);
    assert.equal(prevented, true);
    assert.equal(stopped, true);
    assert.equal(focused, true);

    // Reopen and test navigation dismissal (does not steal focus)
    hasOpenAttr = true;
    focused = false;
    assert.equal(menu.isOpen(), true);
    navigatingListener();
    assert.equal(menu.isOpen(), false);
    assert.equal(focused, false);

    // Teardown via cleanup callback cleans up
    cleanupCallback();
    assert.equal(navigatingListener, null);
    assert.equal(popstateListener, null);
});

test('userMenu: toggles, closes with focus return, dismisses on escape and navigation, and cleans up listeners', () => {
    let navigatingListener = null;
    let popstateListener = null;
    const fakeDoc = {
        addEventListener: (event, handler) => {
            if (event === 'livewire:navigating') navigatingListener = handler;
        },
        removeEventListener: (event, handler) => {
            if (event === 'livewire:navigating' && navigatingListener === handler) navigatingListener = null;
        },
    };
    const fakeBrowser = {
        addEventListener: (event, handler) => {
            if (event === 'popstate') popstateListener = handler;
        },
        removeEventListener: (event, handler) => {
            if (event === 'popstate' && popstateListener === handler) popstateListener = null;
        },
    };

    let focused = false;
    const triggerEl = { focus: () => { focused = true; } };

    const menu = userMenu(fakeBrowser, fakeDoc);
    menu.$refs = { userMenuTrigger: triggerEl };
    menu.init();

    assert.equal(menu.openUserMenu, false);
    menu.toggle();
    assert.equal(menu.openUserMenu, true);

    // Escape closes and returns focus
    let prevented = false;
    let stopped = false;
    menu.handleKeydown({ key: 'Escape', preventDefault: () => { prevented = true; }, stopPropagation: () => { stopped = true; } });
    assert.equal(menu.openUserMenu, false);
    assert.equal(prevented, true);
    assert.equal(stopped, true);
    assert.equal(focused, true);

    // Reopen and test popstate navigation dismissal
    menu.toggle();
    focused = false;
    assert.equal(menu.openUserMenu, true);
    popstateListener();
    assert.equal(menu.openUserMenu, false);
    assert.equal(focused, false);

    menu.destroy();
    assert.equal(navigatingListener, null);
    assert.equal(popstateListener, null);
});

test('dropdownMenu: toggles, closes with focus return, dismisses on escape and navigation, and cleans up listeners', () => {
    let navigatingListener = null;
    let popstateListener = null;
    const fakeDoc = {
        addEventListener: (event, handler) => {
            if (event === 'livewire:navigating') navigatingListener = handler;
        },
        removeEventListener: (event, handler) => {
            if (event === 'livewire:navigating' && navigatingListener === handler) navigatingListener = null;
        },
    };
    const fakeBrowser = {
        addEventListener: (event, handler) => {
            if (event === 'popstate') popstateListener = handler;
        },
        removeEventListener: (event, handler) => {
            if (event === 'popstate' && popstateListener === handler) popstateListener = null;
        },
    };

    let buttonFocused = false;
    const buttonEl = { focus: () => { buttonFocused = true; } };
    const triggerEl = { querySelector: (sel) => sel.includes('button') ? buttonEl : null };

    const dropdown = dropdownMenu(fakeBrowser, fakeDoc);
    dropdown.$refs = { trigger: triggerEl };
    dropdown.init();

    assert.equal(dropdown.open, false);
    dropdown.toggle();
    assert.equal(dropdown.open, true);

    // Escape closes and returns focus
    dropdown.handleKeydown({ key: 'Escape', preventDefault: () => {}, stopPropagation: () => {} });
    assert.equal(dropdown.open, false);
    assert.equal(buttonFocused, true);

    // Navigation closes
    dropdown.toggle();
    buttonFocused = false;
    navigatingListener();
    assert.equal(dropdown.open, false);
    assert.equal(buttonFocused, false);

    dropdown.destroy();
    assert.equal(navigatingListener, null);
    assert.equal(popstateListener, null);
});
