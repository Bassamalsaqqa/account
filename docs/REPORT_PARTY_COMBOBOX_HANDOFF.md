# Report party combobox source handoff

Foundation: PR98 `390dd2c4a23d46c85d13fba1da965e64d144ee2d`, descended from PR87 `912aa71f557b314837e70138df22615902fc9d7b`. This branch was created from that exact commit; no PR99/96/95 changes imported.

The shared report selector covers actual registry customer_id, vendor_id and employee_id filters through the single report view callsite. Customer/vendor detail statement screens have a fixed party, not a party filter. Product, warehouse, category and money-account selectors retain the original bounded search/native-select control in report-filter-selector. Currency keeps its native select.

Lookup uses the existing permission-scoped ReportFilterOptions service, filtered to one supported field, at most 25 matches plus selected identity. Every request rechecks company membership, report permission, presentation policy and master-data permissions. No financial read model, canonical action, ReportFilters validation, export controller or permission grant changed. Committed filter and editable term are separate; only explicit option selection writes the deferred filter. Report execution/export retain canonical validation. Lookup errors preserve the committed selection.

Executed: `node --test tests/js/report-party-combobox.test.mjs` (5 passed), `git diff --check`. Markup checks are source assertions, not visual evidence.

Pending root sequential QA under the dependency/DB/build lease: PHP syntax/style/PHPStan, production build, and MariaDB integration coverage (bounded lookup query count and selected label; unsupported fields; foreign ID; limited master/report roles; revoked membership; export revalidation; hydration/reset/back navigation; unchanged balances). Existing Phase8 ReportPartySelectorTest supplies foundational DB cases but was not executed. Any added integration cases must first use the tracked disposable runner and verify its nonce-owned MariaDB 3342 guards before setup mutation; do not run the legacy test lifecycle directly against persistent data.

Browser: preview_status returned available:false. NOT VERIFIED; no browser fallback, server or host changes. Real keyboard/focus/pointer, Arabic/English responsive layout and Livewire morph/back-navigation qualification remain pending. Source-ready does not mean release-ready or Owner accepted.

No DB, migration, composer, npm, dependency install, build, production, SSH, Hostinger, deployment, merge, external agent or cleanup actions occurred. Independent root review required.

## Supervisor review correction (pending integration qualification)

The independent source review at 792a5bb did not accept the literal x-effect synchronization, template-child keyboard scrolling, formatted selected-label query and initial keyboard activation. Root replaced the reactive effect with initialization plus an explicit Livewire filter watcher; a server-derived selected/options key remounts state on changed server presentation. The watcher handles deferred-client selection reset even when the original server key is unchanged. Empty identity keeps an empty label. Keyboard scroll targets rendered role=option elements; selected display labels open an unfiltered lookup; first navigation selects a match and Enter can select a sole match.

Root executed nine pure Node tests, all passed. An initial added reset regression failed because the empty option's All label was retained; that was corrected before the passing run. These tests exercise controller/watch callbacks and source wiring, NOT real Alpine effect scheduling or Livewire DOM morphing. DB endpoint/security integration, PHPStan, build and actual AR/EN browser/hydration checks remain pending the release-audit worker's exclusive QA lease. No production or PR99 change.
