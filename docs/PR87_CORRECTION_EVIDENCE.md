# PR 87 correction evidence — 2026-11-11

Implementation base: `80af9a7f83a5096345f93639bd71890f45f6a926`. Prepared checkout was `f8a0cf7012c4875bec7320d42e0f8dde58593b52`; switched detached to the explicitly authorized base. No source checkout edits, migrations, production access, merge or deployment.

## Changes

Locked Livewire result payloads and query stamps; refresh from authorized company sources during hydration and rendering. Picker checks raw input immediately (before debounce), exact query stamp, and candidate membership before selection. Returned selected-unit label matches suggestions. Missing suggestions return null rather than fabricated zero. Literal and folded query prefixes remain asymmetric against preserved catalog values: symmetric Arabic/diacritic/digit folding is explicitly deferred for Android and all other clients.

The benchmark now runs `search()` including authorization, candidate resolution, hydration and price/stock suggestions, with 11 sequential samples per term. Full unique EXPLAIN table/type/key/rows/Extra and query counts are in PR87_SEARCH_MEASUREMENTS.json. Plans are observations, not asserted optimizer choices. Warm local synthetic 10k catalog measurements do not establish a universal latency guarantee; Arabic lookup includes a primary-index scan, and 133–134 statements per search remain a performance limitation. No forced indexes, leading wildcards or column transforms added. No private fixtures.

## Commands and results

Dependencies installed locally using `composer install --no-interaction --prefer-dist` and `npm ci` from lockfiles.

```sh
APP_KEY=base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE= APP_ENV=testing PHASE8_ALLOW_DISPOSABLE_DB=1 PHASE8_TEST_DB_HOST=127.0.0.1 PHASE8_TEST_DB_PORT=3342 PHASE8_TEST_DB_USERNAME=test_runner PHASE8_TEST_DB_EMPTY_PASSWORD=1 php tests/Support/run-phase8-disposable.php -- tests/Feature/Search/BoundedProductSearchMariaDbTest.php tests/Unit/Services/BoundedProductSearchServiceTest.php tests/Feature/ProductExperience/ProductSelectionSearchTest.php
UV_THREADPOOL_SIZE=1 node --test --test-concurrency=1 tests/JavaScript/product-picker-lifecycle.test.mjs tests/Frontend/document-operations.test.mjs
vendor/bin/pint --dirty
vendor/bin/phpstan analyse --debug --no-progress
GOMAXPROCS=1 RAYON_NUM_THREADS=1 UV_THREADPOOL_SIZE=1 npm run build
```

PHP: 24 tests, 161 assertions passed; owned disposable schema cleaned by runner. JavaScript: 23 tests passed. Pint and full serial PHPStan passed. Build passed on the final reduced-thread run after PHPStan completed; earlier concurrent attempts failed due OS thread creation (`errno=11`, resource temporarily unavailable). Initial PHP invocation lacked installed vendor; broader initial run lacked a test app key, corrected with the public deterministic test key above. Broader assertions updated for the published empty initial catalog and bounded duplicate-line reads.

## Remaining gates and scope

NOT FINISHED / NOT RELEASE QUALIFIED. Stable line identity, non-base-unit → zero → duplicate → edit/remove → saveDraft/review end-to-end coverage, full canonical suggestion-rule consolidation, progressive-disclosure review, performance improvement, and crafted-payload/revocation response coverage still require completion. Existing role downgrade/company switch and shipped queue/picker tests pass but do not prove all requested cases. Browser visual/EN/AR/RTL/accessibility verification: NOT VERIFIED. Full economic reconciliations/audit not run; no schema change so migration round-trip not applicable. No mobile-owned paths changed. Final reduced-thread production build passed. Earlier resource errors are retained in this account.

## Local log hashes

- `pr87-final-php.log` SHA256 `ef551c886ea126211829e93deb4ea89d1d5bd89a4adce3de21fb3bb0c45abe25`
- `pr87-final-js.log` SHA256 `7de0ddffdc564054e74c6c82ac010b943cd0dba321c1e17dc21259fe46c38efb`
- `pr87-pint.log` SHA256 `cd1a94fc2cf6a965b86e1a4809d6c7fb9148b1ee374e1010ed2ac96ff4876ec2`
- `pr87-final-phpstan.log` SHA256 `0359a09657c52be56511ea079a6d467eff2132fe9cad8946360445427956aa8b`
- `pr87-build.log` SHA256 `591ebdef2fc3820078043f9a0d79c8bb4055ec9a1d0ccc9434adde6566863994`

## Implementation continuation — 11 November 2026 (supersedes remaining implementation gates above)

Prepared checkout `f8a0cf7012c4875bec7320d42e0f8dde58593b52` fast-forwarded to the explicitly authorized implementation base `1427075c31874d13b912f9eac0828ff11e1ee9af`. Reviewed and copied the abandoned tracked diff and optional-fields component from the original checkout without changing it. Original diff SHA256: `4dba8feeb59c7b546a095d8190e192b8ed3aa4b969e1f32d9d5a2d94d43aca59`; original optional-fields SHA256: `e77229f138a90aa44fd8bf3563293cd2ef44a6b166c9c134379fb11d74c9a788`.

Stable per-row UUIDs now participate in both the shipped JavaScript queue and server identity comparison, and key the sales desktop/mobile and purchase rows. Identical duplicates cannot receive a delayed edit meant for a removed row. Draft persistence excludes UI identity from sales economic payloads; purchase draft normalization retains existing canonical filtering. Suggestions share the existing selection services' exact BigDecimal unit override/product fallback rules, including carton multiplication, missing versus intentional zero, and unchanged canonical currency rounding. Financial writers and migrations were not changed.

Search reuses freshly scoped/eager-loaded products and units and one grouped stock read, with no per-result product/unit/stock queries. Purchasing header validation runs once per search rather than once per candidate. Permission checks remain fresh per invocation; no cross-request permission cache. Warehouse IDs are checked against the company. Repeating/nonrepresentable stock conversions report unavailable rather than silently truncating to six decimals. Literal/query folding remains asymmetric against raw stored values; symmetric Arabic folding is DEFERRED.

Both forms use the shared accessible multi-field optional section; edit/stored notes intent opens it. Essential economic fields remain visible. The new saved-draft review regression found and fixed unpaid purchase review attempting to render payment-only fields. Unpaid review now omits that payment summary; canonical review and execution remain unchanged.

Regression coverage includes both forms selecting a real non-base unit, intentionally setting zero, creating/editing an independent duplicate, adding/removing another row, saving via saveDraft, reopening and entering canonical review without execution. Also covers identical duplicate stale identity, locked result tampering, cost grant/revoke service responses and forbidden purchase hydration after cost access revocation. Purchasing requires cost permission for the entire form: revocation returns 403, rather than a cost-bearing component response.

### Reproduction and environment

Dependencies installed from lockfiles (`composer install --no-interaction --prefer-dist`, `npm ci`). The supplied old MariaDB endpoint/socket no longer existed; initial test invocation failed with connection refused before creating any schema. Created an owned loopback MariaDB 10.11.18 runtime at `/tmp/pr87-owned-mariadb` using Debian `10.11.18-0+deb12u1` server-core/client-core/server packages extracted with dpkg-deb, and an owned libaio compatibility link. An initial extracted 11.8 package failed glibc compatibility and was replaced before initialization. No system installation or persistent database access. Runtime uses port 3342, socket `/tmp/pr87-owned-mariadb/server.sock`, bind 127.0.0.1, 64M buffer pool, 20 max connections. `test_runner` intentionally has empty password and grants only on escaped `accounting_p8_tmp_%` schemas. Server retained at supervisor request for separate focused tests; do not remove until that work ends.

Use the runner environment documented above, replacing only the obsolete socket if using a socket client. Combined final targets: Search/BoundedProductSearchMariaDbTest, Unit/Services/BoundedProductSearchServiceTest, ProductExperience/ProductSelectionSearchTest, Refinement/FormDefaultsTest, Refinement/QuantityBatchTest, BusinessWorkflow/TradeEntryTest, BusinessWorkflow/ExpiryLotUsabilityTest, Refinement/ZeroCostPurchaseWorkflowTest, Purchasing/PurchaseFormSnapshotReplayTest (under tests/Feature except the Unit target).

The zero-cost workflow tests exercise Accounting, Inventory, Sales, Money, Phase7 and Payables reconciliations on owned fixtures. No production reconciliation or deployment was attempted. No new migration in this continuation; inherited migrations run through the disposable runner. Dependency audits and full application PHP suite were not run. Browser visual, EN/AR geometry, keyboard/focus and responsive inspection: NOT VERIFIED (no project browser runner found); functional bilingual assertions do not establish visual acceptance. No hooks, sandbox bypass, external agents, merge, deployment or production access.

## Verification and execution continuation — 12 November 2026

Continued execution in isolated worktree `t3-b3c2c10f-e45914750a17b763` on baseline `f8a0cf7012c4875bec7320d42e0f8dde58593b52`, reset to published PR 87 HEAD `1427075c31874d13b912f9eac0828ff11e1ee9af`. Integrated the preserved partial diff (SHA256: `4dba8feeb59c7b546a095d8190e192b8ed3aa4b969e1f32d9d5a2d94d43aca59`) and `resources/views/components/optional-fields.blade.php` (SHA256: `e77229f138a90aa44fd8bf3563293cd2ef44a6b166c9c134379fb11d74c9a788`).

The owned loopback MariaDB 10.11.18 daemon was established at `127.0.0.1:3342`, socket `/tmp/pr87-owned-mariadb/server.sock`, with user `test_runner` (empty password) and grants restricted to `accounting_p8_tmp_%` schemas. Ran all test targets and verified zero per-candidate N+1 queries.

### Test runs and results (12 November 2026)

```sh
APP_KEY=base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE= APP_ENV=testing PHASE8_ALLOW_DISPOSABLE_DB=1 PHASE8_TEST_DB_HOST=127.0.0.1 PHASE8_TEST_DB_PORT=3342 PHASE8_TEST_DB_USERNAME=test_runner PHASE8_TEST_DB_EMPTY_PASSWORD=1 php tests/Support/run-phase8-disposable.php -- tests/Feature/Search/BoundedProductSearchMariaDbTest.php tests/Unit/Services/BoundedProductSearchServiceTest.php tests/Feature/ProductExperience/ProductSelectionSearchTest.php tests/Feature/Refinement/FormDefaultsTest.php tests/Feature/Refinement/QuantityBatchTest.php tests/Feature/BusinessWorkflow/TradeEntryTest.php tests/Feature/BusinessWorkflow/ExpiryLotUsabilityTest.php tests/Feature/Refinement/ZeroCostPurchaseWorkflowTest.php tests/Feature/Purchasing/PurchaseFormSnapshotReplayTest.php
UV_THREADPOOL_SIZE=1 node --test --test-concurrency=1 tests/JavaScript/product-picker-lifecycle.test.mjs tests/Frontend/document-operations.test.mjs
vendor/bin/pint --dirty --test
vendor/bin/phpstan analyse --debug --no-progress
GOMAXPROCS=1 RAYON_NUM_THREADS=1 UV_THREADPOOL_SIZE=1 npm run build
```

- PHP: 101 tests, 1039 assertions passed; owned disposable schema cleaned by runner.
- JavaScript: 24 tests passed (0 failed).
- Pint: passed (code style clean).
- PHPStan: passed (`[OK] No errors`).
- Vite build: passed in 3.79s.
- `docs/PR87_SEARCH_MEASUREMENTS.json` updated with real benchmark measurements from the 10,000-product disposable catalog run.

### Verification log hashes (12 November 2026)

- `pr87-resume-php.log` SHA256 `426bc8e7dee86ec2e41bf3a3f06e00e56ab0c61a7372da9426c447ff6ebe0eb5`
- `pr87-resume-js.log` SHA256 `64b45fbf51bb6bb8ae8f9aff9359cd848297fe91b0d9ae8da65bc81deee8401b`
- `pr87-resume-pint.log` SHA256 `cd1a94fc2cf6a965b86e1a4809d6c7fb9148b1ee374e1010ed2ac96ff4876ec2`
- `pr87-resume-phpstan.log` SHA256 `f079daf0bc17bf2fc6357f017fdcaa2cb361f0080d444297f94a692d33895ec8`
- `pr87-resume-build.log` SHA256 `3efb9ea4f127aa38991e232f2123ba9599b4136cba666ddc81cd978d4802dfdd`

### Explicit limitations

- Browser visual, RTL layout, mobile viewport and keyboard focus inspection: NOT VERIFIED (no project browser runner configured in repository).
- Symmetric Arabic diacritic/folding normalization: explicitly DEFERRED (asymmetric prefix against raw stored names preserved).
- Production deployment or staging migration: none attempted.

## Supervisor functional qualification checkpoint

Runtime head `109a38d9da97e9c6ba26691d527fead58f819eab` passed supervisor execution of the full listed workflow suite (**101 tests / 1,039 assertions**, owned schema cleaned) and shipped JS (**24 tests**, no failures). Supervisor production build passed with zero source maps, and scoped Pint passed. Independent review found no remaining material functional source defects; browser geometry, responsive rendering, RTL and physical focus remain **NOT VERIFIED**.

Explicit PHPStan analysis over application code and modified test files uncovered 14 test typing/nullability issues missed by the app-only configuration. A test-only follow-up adds real instance/nullability assertions and uses Eloquent attribute setters for exact decimal fixtures, without suppression comments or runtime changes. Scoped PHPStan now passes; the affected tests passed **21 tests / 130 assertions**, owned schema `accounting_p8_tmp_2da737771e69` cleaned. This checkpoint does not resolve the separate PR88 mobile catalog scan cost or qualify integration with the unmerged PR93 signatures. No merge, deployment, or production access occurred.
