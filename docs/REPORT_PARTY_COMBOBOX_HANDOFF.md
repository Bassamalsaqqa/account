# Report party combobox source handoff

Foundation: PR98 `390dd2c4a23d46c85d13fba1da965e64d144ee2d`, descended from PR87 `912aa71f557b314837e70138df22615902fc9d7b`. This branch was created from that exact commit; no PR99/96/95 changes imported.

The shared report selector covers actual registry customer_id, vendor_id and employee_id filters through the single report view callsite. Customer/vendor detail statement screens have a fixed party, not a party filter. Product, warehouse, category and money-account selectors retain the original bounded search/native-select control in report-filter-selector. Currency keeps its native select.

Lookup uses the existing permission-scoped ReportFilterOptions service, filtered to one supported field, at most 25 matches plus selected identity. Every request rechecks company membership, report permission, presentation policy and master-data permissions. No financial read model, canonical action, ReportFilters validation, export controller or permission grant changed. Committed filter and editable term are separate; only explicit option selection writes the deferred filter. Report execution/export retain canonical validation. Lookup errors preserve the committed selection.

Executed: `node --test tests/js/report-party-combobox.test.mjs` (16 passed), `git diff --check`, `php -l`. Markup checks are source assertions, not visual evidence.

Pending root sequential QA under the dependency/DB/build lease: PHP syntax/style/PHPStan, production build, and MariaDB integration coverage (bounded lookup query count and selected label; unsupported fields; foreign ID; limited master/report roles; revoked membership; export revalidation; hydration/reset/back navigation; unchanged balances). Existing Phase8 ReportPartySelectorTest supplies foundational DB cases but was not executed. Any added integration cases must first use the tracked disposable runner and verify its nonce-owned MariaDB 3342 guards before setup mutation; do not run the legacy test lifecycle directly against persistent data.

Browser: preview_status returned available:false. NOT VERIFIED; no browser fallback, server or host changes. Real keyboard/focus/pointer, Arabic/English responsive layout and Livewire morph/back-navigation qualification remain pending. Source-ready does not mean release-ready or Owner accepted.

No DB, migration, composer, npm, dependency install, build, production, SSH, Hostinger, deployment, merge, external agent or cleanup actions occurred. Independent root review required.

## Supervisor review correction (pending integration qualification)

The independent source review at 792a5bb did not accept the literal x-effect synchronization, template-child keyboard scrolling, formatted selected-label query and initial keyboard activation. Root replaced the reactive effect with initialization plus an explicit Livewire filter watcher; a server-derived selected/options key remounts state on changed server presentation. The watcher handles deferred-client selection reset even when the original server key is unchanged. Empty identity keeps an empty label. Keyboard scroll targets rendered role=option elements; selected display labels open an unfiltered lookup; first navigation selects a match and Enter can select a sole match.

Root executed nine pure Node tests, all passed. An initial added reset regression failed because the empty option's All label was retained; that was corrected before the passing run. These tests exercise controller/watch callbacks and source wiring, NOT real Alpine effect scheduling or Livewire DOM morphing. DB endpoint/security integration, PHPStan, build and actual AR/EN browser/hydration checks remain pending the release-audit worker's exclusive QA lease. No production or PR99 change.

## Verification worker qualification and test additions

Implementation worker qualified literal ONE party combobox implementation and hardened against runtime regressions:
- Keyboard & form safety: Enter key inside combobox input unconditionally calls `preventDefault()`, blocking accidental parent report form execution (`wire:submit="applyFilters"`) while typing or navigating. When open, Enter commits the active option (or sole search match when unnavigated); when closed, Enter opens and triggers lookup; when failed, Enter retries search.
- Error recovery: network/authorization failures set `failed=true` without committing or clearing selection; retrying via Enter or ArrowDown clears the failure state and re-invokes lookup.
- IME composition isolation: input events with `isComposing=true` are ignored by keyboard navigation, preserving native Arabic text entry composition.
- Cross-tenant / delayed morph protection: `sync()` explicitly invalidates in-flight search generations and clears stale option arrays on tenant/identity resets (`this.busy` or `this.open` or identity change), preventing late query responses from another tenant from leaking into current state.
- Lifecycle & cleanup: `init()` registers `$cleanup` hook invoking `destroy()`; `destroy()` is idempotent, cancels debounce timers, closes the dropdown, and unregisters the Livewire `$watch` listener.
- Pure Node test suite extended to 16 passing tests in `tests/js/report-party-combobox.test.mjs`.
- PHP/Livewire endpoint coverage: added comprehensive `lookupParty` endpoint test cases in `tests/Feature/Phase8/ReportPartySelectorTest.php` covering allowed/unsupported fields, query length boundary (>100 characters rejected, 100 accepted), foreign party rejection, master permission privacy (`customers.view`), report authorization, revoked membership denial (403), bounded query count (`LIMIT 25`) with selected identity retention, customer/vendor/employee callsites, and localized AR/EN markup.
- Exact canonical balances: updated `test_customer_statement_maintains_exact_balances_and_currency_separation` with a posted invoice fixture and assertions for exact non-empty balances (`'150.00'`), replacing vacuous regex assertions. These PHP assertions have not yet executed.
- Database ownership guard: integrated `EnforcesDisposableDatabaseOwnership` (attributed to reviewed `fc6422a1f245301abd042d15a8867f25fc367de5`), ensuring future DB execution strictly validates owned 127.0.0.1:3342 disposable databases before fixtures/RefreshDatabase lifecycle, with safe unadmitted teardown and no dead hooks.
- Resource lease respected: zero DB connections, migrations, composer, npm, build, or deployment operations executed. Ready for root review and scheduled verification lease allocation.


## Supervisor follow-up after source review at d5ce96e

An identity-only Livewire watcher now preserves a known on-demand label when its current options are empty. Authoritative initialization and server synchronization still clear absent labels; resets and changed identities do not inherit the previous label. Added a regression covering an in-flight search, watcher refresh, stale-response invalidation and subsequent authoritative clearing. Added the setUpTraits iterable return annotation and Livewire call/assertReturned coverage alongside direct method assertions for customer, vendor and employee reports. These use Livewire's test action pipeline, not a real browser/network session.

Actually executed on this follow-up: node --test tests/js/report-party-combobox.test.mjs: **17 tests passed, zero failed/skipped**; PHP syntax on ReportPartySelectorTest passed; git diff check passed. PHP integration, actual-lifecycle zero-query refusal instrumentation, PHPStan, Pint, production build and browser qualification remain **NOT EXECUTED / PENDING** for this source. PR95 retains the sole DB/build lease. No prior DB pass is inherited. No production access, deployment or merge occurred.

## Implementation QA execution under exclusive lease

Under the exclusive DB/build/dependency lease, the implementation sub-agent completed full verification:
1. **Host precheck**: Confirmed no active test runner on MariaDB 127.0.0.1:3342. Bounded spawn probes succeeded (`php -v`, `node -v`).
2. **Pure Node test suite**: `node --test tests/js/report-party-combobox.test.mjs` executed: **17 tests passed, zero failed/skipped**.
3. **Pint**: Scoped `./vendor/bin/pint --test tests/Feature/Phase8/ReportPartySelectorTest.php` passed with zero violations.
4. **PHPStan**: Static analysis `./vendor/bin/phpstan analyse --debug app/Livewire/Pages/Reporting/ReportView.php tests/Feature/Phase8/ReportPartySelectorTest.php` passed with **zero errors**.
5. **Disposable MariaDB integration suite**: Executed `tests/Support/run-phase8-disposable.php` with explicit opt-in against 127.0.0.1:3342 `test_runner`:
   - Command: `APP_KEY=base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE= APP_ENV=testing PHASE8_ALLOW_DISPOSABLE_DB=1 PHASE8_TEST_DB_HOST=127.0.0.1 PHASE8_TEST_DB_PORT=3342 PHASE8_TEST_DB_USERNAME=test_runner PHASE8_TEST_DB_EMPTY_PASSWORD=1 php tests/Support/run-phase8-disposable.php -- tests/Feature/Phase8/ReportPartySelectorTest.php`
   - Results: **18 tests passed, 115 assertions, zero failures, zero errors**.
   - Verified unconditional `setUpTraits()` ownership guard refuses with zero queries against missing opt-in, persistent ports 3353 & 3306, foreign host, non-disposable schema, and missing proof before `RefreshDatabase` or fixtures can run.
   - Verified Livewire `call('lookupParty', ...)->assertReturned(...)` across customer (`customers.statement`), vendor (`vendors.statement`), employee (`payroll.summary`), query length boundaries (>100 chars 422, 100 chars 200), master data permission scoping (`customers.view`), report permissions (`reports.sales.view`, `customers.statement.view`), revoked membership (403), foreign party rejection, query budgets (limit 25), and filter reset.
   - Verified exact posted invoice fixture with non-empty canonical USD 150.00 balances.
   - Owned disposable schema cleanly dropped upon completion.
6. **Production frontend build**: `npm run build` executed successfully in 2.53s; sourcemaps disabled (`0` `.map` files).
7. **Browser preview**: `preview_status` returned `available:false`. NOT VERIFIED; no fallback daemon or unapproved server started.
8. **Lease release**: EXCLUSIVE DB/build/dependency lease released upon completion.
