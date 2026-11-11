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
