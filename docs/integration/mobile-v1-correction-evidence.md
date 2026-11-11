# PR88 correction verification

Source baseline: `648e2968686f0f947eb6f72d96a80f305b31d4fc` (PR88 authorized published head). Resulting commit/tree are reported in the implementation handoff. No production or persistent database access.

## Actual gates

- Focused mobile, Phase11 API security, and unit contract suites: **119 tests, 803 assertions**, passed cleanly. Includes single verified MariaDB consistent read (`CatalogSnapshot::withConsistentRead` REPEATABLE READ isolation) with independent PHP concurrent writer worker verification, collision-free canonical JSON row framing (`CatalogSnapshotFramingTest`), query-source multi-tenancy scoping tests with foreign barcode/balances, absolute anchor `\A...\z` regex validation, and Python Draft2020 response validation. Primary test schema `accounting_p8_tmp_96c998eabd3f` cleaned.
- Pint dirty-files check passed; PHPStan whole configured scope passed with no errors; composer audit found no advisories.
- Python frozen-contract validator passed **210 checks**, including all **39 manifest files** unchanged (manifest SHA-256 `ba75352349e552f53b02ad7159570171f88943dc26e5bdf2a4b013386b0d0659`). Actual customer directory and product catalog responses validated with Python Draft202012Validator.
- **10k Fixture Qualification**: Benchmarked against a real 10,000 product catalog on disposable MariaDB (schema `accounting_p8_tmp_10d13d6d99ca` cleaned):
  - Bootstrap endpoint: 200 OK, latency 2025.96 ms, 186 DB queries, memory delta 0 KB ($O(1)$ memory).
  - Product limit 1: 200 OK, latency 1754.50 ms, 180 DB queries, memory delta 743.41 KB (< 1 MB).
  - Product limit 30: 200 OK, latency 1648.83 ms, 180 DB queries, memory delta 586.67 KB (< 1 MB).
  - Non-matching search: 200 OK, latency 1255.60 ms, 174 DB queries, memory delta 0 KB.
  - Keyset bounded chunks (256 items per chunk) across company-scoped primary keys bound PHP and PDO memory to $O(1)$. Total fingerprint scan cost is $O(n)$ in company catalog size; page limit 30 bounds only product DTO results returned, not the whole company catalog fingerprint computation.
- Each disposable runner reported primary-schema cleanup; race tests restore/drop their independently owned fixture schema. No fixture transfer, browser session/CSRF bypass, financial activation, merge or deployment.

## Reproduce

Install locked dependencies with `composer install --no-interaction --prefer-dist --optimize-autoloader`. Use a provisioned test-only MariaDB; this run used numeric loopback **3342**, test_runner with deliberately empty password and accounting_p8_tmp_% schema grants. Never use persistent 3353.

```sh
APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= APP_ENV=testing PHASE8_ALLOW_DISPOSABLE_DB=1 PHASE8_TEST_DB_HOST=127.0.0.1 PHASE8_TEST_DB_PORT=3342 PHASE8_TEST_DB_USERNAME=test_runner PHASE8_TEST_DB_EMPTY_PASSWORD=1 php tests/Support/run-phase8-disposable.php -- tests/Feature/Mobile tests/Feature/Phase11/ApiSecurityTest.php tests/Unit/Mobile
```

Other commands: `vendor/bin/pint --test --dirty`, `vendor/bin/phpstan analyse --no-progress`, `python3 tools/validate-mobile-contract.py && python3 -O tools/validate-mobile-contract.py`, `composer audit --format=plain`, `git diff --check`.

## Retained task artifacts

Final logs are deliberately retained outside the checkout in `/tmp/pr88-correction-evidence/`; they contain only synthetic test context.

| Log | SHA-256 |
|---|---|
| `mobile-verified-tests.log` | `ae3f4744845f7b658aa37422d8391f523224e7c66e54987ae9fb84cb7cfd2e19` |
| `mobile-style-verified.log` | `cd1a94fc2cf6a965b86e1a4809d6c7fb9148b1ee374e1010ed2ac96ff4876ec2` |
| `mobile-static-verified.log` | `e6b351ce3d4be36578afbe018812edb1909f17473359c7ef926e1fc5bbf47934` |
| `mobile-contract.log` | `cb94e29cd750ee253b8e3da14f2d11c829f1a1d6a59ca1960500eef496c6b65c` |
| `mobile-audit.log` | `dac04dda7966b04d2eb83ba22b729e4025f820bba102192effa983b1fa377af8` |
| `mobile-10k-qualification.log` | `58042d8f45d3f42e0ea07908fffa7bf5a9bd12af9b6bf73cfbbf0b57b94b8711` |
