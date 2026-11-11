# PR88 correction verification

Source baseline: `648e2968686f0f947eb6f72d96a80f305b31d4fc` (PR88 authorized published head). Authorized local base `c1576decf1a5a90a0bb13dc0d21b2ded91ba7fd0` (includes unpublished supervisor doc fix only). Resulting commit and tree are reported in the implementation handoff. No production or persistent database access.

## Actual gates

- Focused mobile, Phase11 API security, and unit contract suites: **119 tests, 803 assertions**, passed cleanly. Includes single verified MariaDB consistent read (`CatalogSnapshot::withConsistentRead` REPEATABLE READ isolation) with independent PHP concurrent writer worker verification, collision-free canonical JSON row framing (`CatalogSnapshotFramingTest`), query-source multi-tenancy scoping tests with foreign barcode/balances, absolute anchor `\A...\z` regex validation, and Python Draft2020 response validation. Primary test schema `accounting_p8_tmp_96c998eabd3f` cleaned.
- Pint dirty-files check passed; PHPStan whole configured scope passed with no errors; composer audit found no advisories.
- Python frozen-contract validator passed **210 checks**, including all **39 manifest files** unchanged (manifest SHA-256 `ba75352349e552f53b02ad7159570171f88943dc26e5bdf2a4b013386b0d0659`). Actual customer directory and product catalog responses validated with Python Draft202012Validator.
- **10k Fixture Qualification**: Portable benchmark tracked in git at `tests/Benchmark/Catalog10kBenchmarkTest.php`. Executed against a real 10,000 product catalog on disposable MariaDB (schema `accounting_p8_tmp_04d5418b107a` cleaned):
  - Bootstrap endpoint: 200 OK, cold latency 1012.45 ms (warm 1011.76 ms), 185 DB queries cold (183 warm), memory delta 2.4 MB cold (37 KB warm).
  - Product limit 1: 200 OK, cold latency 1000.69 ms (warm 1022.98 ms), 179 DB queries cold (179 warm), memory delta 622 KB cold (26 KB warm).
  - Product limit 30: 200 OK, cold latency 940.58 ms (warm 994.06 ms), 179 DB queries cold (179 warm), memory delta 125 KB cold (125 KB warm).
  - Non-matching search: 200 OK, cold latency 1078.50 ms (warm 1025.59 ms), 173 DB queries cold (173 warm), memory delta 21 KB cold (21 KB warm).
  - Keyset bounded chunks (256 items per chunk) across company-scoped primary keys bound PHP and PDO result buffers to $O(1)$ independently of catalog size.
  - EXPLAIN plans verify proper index usage: products chunk uses `products_company_id_active_index` (`ref: const,const`); product units chunk joins on `product_units_unit_id_foreign` and product `PRIMARY`; product page limit 30 scans `products_public_id_unique` (`type: index`, 62 rows examined for limit 31).
  - Emits machine-readable `QUALIFICATION_METRICS_JSON` payload without flaky latency assertion thresholds.
- Each disposable runner reported primary-schema cleanup; race tests restore/drop their independently owned fixture schema. No fixture transfer, browser session/CSRF bypass, financial activation, merge or deployment.

## Architectural limitations & documented behaviors

- **$O(n)$ Scan Cost & Query Volume**: Total catalog fingerprint scan cost is $O(n)$ in company catalog size. For 10,000 products, fingerprinting issues 161 chunk queries (40 product chunks + 40 product unit chunks + 1 unit chunk + 40 barcode chunks + 40 balance chunks), leading to 173–185 total DB queries per request when combined with authentication, device lease validation, and pagination. Page limit 30 bounds returned product DTOs, not the company catalog version computation.
- **End-to-End Memory**: Keyset chunks of 256 bound intermediate result sets, but total end-to-end framework peak memory during request processing is ~81 MB. Small memory deltas during isolated queries do not prove overall constant application peak memory.
- **Inter-HTTP Version Drift**: Each HTTP entrypoint initiates its own consistent read transaction at level 0. Independent requests separated in time will observe different snapshot versions if mutations commit between requests. No unapproved caching architecture is introduced; this limitation is accepted and documented.
- **Ambient Transaction Precondition**: `CatalogSnapshot::withConsistentRead` checks `$connection->transactionLevel() > 0`. If an ambient transaction is already open, it executes the callback within that transaction without attempting `SET TRANSACTION ISOLATION LEVEL` (which MySQL/MariaDB rejects mid-transaction with error 1568). Callers opening an ambient transaction must establish `REPEATABLE READ` themselves; `withConsistentRead` assumes caller ownership and does not alter isolation for an ambient transaction. Standard mobile HTTP entrypoints always start at transaction level 0.
- **Query Semantics Integrity**: Suggested filtering on `u.active` in product unit joins was evaluated and rejected; invalid unit configurations are surfaced and rejected by controller validation rather than silently hidden from versioning.

## Reproduce

Install locked dependencies with `composer install --no-interaction --prefer-dist --optimize-autoloader`. Use a provisioned test-only MariaDB; this run used numeric loopback **3342**, test_runner with deliberately empty password and accounting_p8_tmp_% schema grants. Never use persistent 3353.

Run the focused mobile test suite (119 tests, 803 assertions):
```sh
APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= APP_ENV=testing PHASE8_ALLOW_DISPOSABLE_DB=1 PHASE8_TEST_DB_HOST=127.0.0.1 PHASE8_TEST_DB_PORT=3342 PHASE8_TEST_DB_USERNAME=test_runner PHASE8_TEST_DB_EMPTY_PASSWORD=1 php tests/Support/run-phase8-disposable.php -- tests/Feature/Mobile tests/Feature/Phase11/ApiSecurityTest.php tests/Unit/Mobile
```

Run the tracked 10k product catalog qualification benchmark:
```sh
APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= APP_ENV=testing PHASE8_ALLOW_DISPOSABLE_DB=1 PHASE8_TEST_DB_HOST=127.0.0.1 PHASE8_TEST_DB_PORT=3342 PHASE8_TEST_DB_USERNAME=test_runner PHASE8_TEST_DB_EMPTY_PASSWORD=1 php tests/Support/run-phase8-disposable.php -- tests/Benchmark/Catalog10kBenchmarkTest.php
```

Other validation commands:
```sh
vendor/bin/pint --test --dirty
vendor/bin/phpstan analyse --no-progress
python3 tools/validate-mobile-contract.py && python3 -O tools/validate-mobile-contract.py
composer audit --format=plain
git diff --check
```

## Retained task artifacts

Final logs are deliberately retained outside the checkout in `/tmp/pr88-correction-evidence/`; they contain only synthetic test context.

| Log | SHA-256 | Description |
|---|---|---|
| `mobile-verified-tests.log` | `ae3f4744845f7b658aa37422d8391f523224e7c66e54987ae9fb84cb7cfd2e19` | 119 tests, 803 assertions passing run |
| `mobile-style-verified.log` | `cd1a94fc2cf6a965b86e1a4809d6c7fb9148b1ee374e1010ed2ac96ff4876ec2` | Pint style verification |
| `mobile-static-verified.log` | `e6b351ce3d4be36578afbe018812edb1909f17473359c7ef926e1fc5bbf47934` | PHPStan static analysis clean run |
| `mobile-contract.log` | `cb94e29cd750ee253b8e3da14f2d11c829f1a1d6a59ca1960500eef496c6b65c` | Python 210 checks contract verification |
| `mobile-audit.log` | `dac04dda7966b04d2eb83ba22b729e4025f820bba102192effa983b1fa377af8` | Composer security audit clean run |
| `mobile-10k-qualification.log` | `58042d8f45d3f42e0ea07908fffa7bf5a9bd12af9b6bf73cfbbf0b57b94b8711` | Historical untracked qualification baseline |
| `mobile-10k-tracked-qualification.log` | `9dd5dc5df0273ba1acfc9fa38285570ba9db31af0c30c27f8abf216cd4a108e8` | Tracked qualification run output |
