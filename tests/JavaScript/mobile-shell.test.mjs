import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mobileShell, sidebarNavigation } from '../../resources/js/mobile-shell.js';

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
