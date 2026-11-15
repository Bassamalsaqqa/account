# PR #102 G1 Read-Metadata & G2 Tax Contract Disposable DB Qualification Report

## 1. Executive Summary
- **Outcome**: **QUALIFIED & PASS (GREEN)**
- **PHPUnit Result**: 22 tests, 138 assertions, 0 failures, 0 errors, 0 skipped.
- **Execution Target**:
  - `tests/Feature/Mobile/BootstrapTest.php` (15 tests, 104 assertions)
  - `tests/Unit/Mobile/Contract/BootstrapMetadataContractTest.php` (7 tests, 34 assertions)
- **Harness & Environment**: Isolated disposable MariaDB on port 3342 via `tests/Support/run-phase8-disposable.php` with ephemeral schema isolation and admin credentials check.
- **Lease Status**: **RELEASED**. Backend G1 QA lease concluded cleanly. No background processes, 0 active schemas, 0 orphaned configs. Next lease yielded to Android compile/policy.

---

## 2. Invocations & Routine Bounded Fix Trajectory

### Exact Command & Environment
All invocations executed the concrete two-file target without abbreviation:
```bash
APP_KEY="base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=" \
APP_ENV="testing" \
PHASE8_ALLOW_DISPOSABLE_DB="1" \
PHASE8_TEST_DB_HOST="127.0.0.1" \
PHASE8_TEST_DB_PORT="3342" \
PHASE8_TEST_DB_USERNAME="test_runner" \
PHASE8_TEST_DB_EMPTY_PASSWORD="1" \
PHASE8_TEST_TIMEOUT="180" \
php tests/Support/run-phase8-disposable.php -- \
  --stop-on-failure \
  --log-junit="<run_junit_path>" \
  tests/Feature/Mobile/BootstrapTest.php \
  tests/Unit/Mobile/Contract/BootstrapMetadataContractTest.php
```

### Invocation 1 (Pre-run SHA: `1e773ca15487877529d4eceb03373c798f80454b`)
- **Assigned Primary Schema**: `accounting_p8_tmp_aad612ff0b2d`
- **Result**: Exit code 1 (12 passed, 1 failure). Stopped on first assertion failure.
- **Root Cause**: `test_bootstrap_optin_include_projects_authoritative_currency_minor_units_for_ils_jod_and_zero_scale` expected HTTP 200 but received 409 `CONTRACT_INCOMPATIBLE`. `CurrencyMinorUnitsResolver::resolveForCompany()` strictly requires `CompanyCurrency::where('company_id', $id)->where('currency_code', 'ILS')->where('enabled', true)->exists()`, which was not seeded in `setUp()`.
- **Cleanup**: Schema `accounting_p8_tmp_aad612ff0b2d` cleanly dropped. Raw stdout/stderr preserved in `run1_stdout.txt` / `run1_stderr.txt`.
- **Committed Narrow Fix**: Commit [`b0543cdfba2a10916f4028b159629bcf53bef026`](https://github.com/Bassamalsaqqa/account/commit/b0543cdfba2a10916f4028b159629bcf53bef026) seeded enabled `company_currencies` for ILS, JOD, and JPY companies.

### Invocation 2 (Pre-run SHA: `b0543cdfba2a10916f4028b159629bcf53bef026`)
- **Assigned Primary Schema**: `accounting_p8_tmp_3732c1a02338`
- **Result**: Exit code 2 (20 passed, 2 errors).
- **Execution Disclosure**: In PHPUnit, `--stop-on-failure` halts on assertion failures, but does not stop on unexpected PHP exceptions/errors. As a result, PHPUnit executed all 22 tests across both files, reporting 20 passes and 2 errors. This surfaced the complete defect inventory for iteration 2:
  1. `NoActiveCompanyException` when creating secondary company models (`LedgerAccount`, etc.) outside tenant context in `BootstrapTest.php`.
  2. Missing import for `CurrencyMinorUnitsResolver` in `BootstrapMetadataContractTest.php`.
- **Cleanup**: Schema `accounting_p8_tmp_3732c1a02338` cleanly dropped. Raw stdout/stderr preserved in `run2_stdout.txt` / `run2_stderr.txt`.
- **Committed Narrow Fix**: Commit [`97f421e7d51fd00f2a4dc109c93554c83ad31151`](https://github.com/Bassamalsaqqa/account/commit/97f421e7d51fd00f2a4dc109c93554c83ad31151) wrapped secondary company model provisioning in `CompanyScope::executeWithoutScope(...)` and restored the `CurrencyMinorUnitsResolver` import.

### Invocation 3 (Pre-run SHA: `97f421e7d51fd00f2a4dc109c93554c83ad31151`)
- **Assigned Primary Schema**: `accounting_p8_tmp_39c8297fc636`
- **Execution Time**: 7.298 seconds
- **Result**: **Exit code 0. OK (22 tests, 138 assertions)**
- **Cleanup**: Schema `accounting_p8_tmp_39c8297fc636` cleanly dropped. Verifiable absence witness confirmed 0 schemas remaining.

---

## 3. Full Qualified Test Inventory

### Feature Test Suite (`tests/Feature/Mobile/BootstrapTest.php` - 15 tests, 104 assertions)
1. `test_bootstrap_succeeds_with_valid_configuration`: 200 OK standard payload, valid wire response schema.
2. `test_bootstrap_fails_truthfully_when_walkin_customer_is_missing_or_inactive`: 409 `BOOTSTRAP_CONFIG_UNSUPPORTED` on inactive, null, or foreign customer.
3. `test_bootstrap_fails_truthfully_when_cash_account_is_missing_inactive_or_currency_mismatched`: 409 on inactive, currency mismatched, or null cash account.
4. `test_bootstrap_fails_truthfully_when_default_warehouse_is_missing_or_inactive`: 409 on inactive or null warehouse.
5. `test_bootstrap_allows_empty_units_and_taxes_without_invented_defaults`: 200 OK with empty units/taxes arrays.
6. `test_bootstrap_fails_when_approved_cash_account_is_bank_type`: 409 on bank money account.
7. `test_bootstrap_fails_when_ledger_account_is_inactive_or_foreign`: 409 on inactive or foreign ledger account.
8. `test_bootstrap_fails_when_base_currency_is_disabled_or_inactive`: 409 on disabled company currency or inactive master currency.
9. `test_bootstrap_fails_when_configured_default_tax_rate_is_foreign_or_inactive`: 409 on inactive or foreign tax rate.
10. `test_bootstrap_fails_with_409_when_stored_unit_decimal_places_exceeds_contract`: 409 `CONTRACT_INCOMPATIBLE` on out-of-bounds decimal places.
11. `test_bootstrap_fails_when_default_warehouse_is_foreign_or_inactive`: 409 on foreign warehouse.
12. `test_bootstrap_absent_include_emits_exact_twelve_response`: Confirms exact 12-key whitelist when `include` is absent, strictly verifying absence of `currency_minor_units` and `base_currency_minor_units`.
13. `test_bootstrap_optin_include_projects_authoritative_currency_minor_units_for_ils_jod_and_zero_scale`: Verifies exact 13 keys and authoritative resolution for ILS (scale 2), JOD (scale 3), and JPY (scale 0).
14. `test_bootstrap_rejects_empty_null_array_and_unknown_include_with_422`: Rejects `?include=`, `?include`, `?include[]=...`, `?include=unknown_field`, and removed `?include=base_currency_minor_units` with HTTP 422 `VALIDATION_FAILED`.
15. `test_bootstrap_controller_409_when_currency_metadata_unresolvable_while_absent_include_retains_prior_behavior`: Proves corrupt/out-of-range minor units or un-enabled company currency fail closed with 409 `CONTRACT_INCOMPATIBLE` when opted in, while default request without include retains prior behavior (200 OK).

### Unit & Contract Suite (`tests/Unit/Mobile/Contract/BootstrapMetadataContractTest.php` - 7 tests, 34 assertions)
1. `test_bootstrap_absent_include_emits_exact_twelve_keys_without_minor_units`: Wire schema validation for golden 12-field fixture.
2. `test_bootstrap_optin_projection_and_alias_removal`: Wire schema test verifying single `currency_minor_units` across scales 0, 2, 3, 6, and rejecting `base_currency_minor_units` alias.
3. `test_controller_rejects_unknown_include_projection_with_422`: Direct controller invocation asserting HTTP 422 on unknown, array, empty, or bare include queries.
4. `test_currency_minor_units_resolver_fail_closed_contract`: Proves resolver throws `InvalidArgumentException` on unresolvable currency code.
5. `test_catalog_tax_pair_truthfulness_and_no_invented_inclusive`: Wire schema test verifying paired nullability on catalog products without invented tax_inclusive.
6. `test_v2_explicit_no_tax_rule_enforcement`: Direct execution of `OnlineSaleIntentProfile::construct()` proving clean construction for explicit NoTax (`tax_rate_id: null`, `tax_rate: "0.000000"`, `tax_inclusive: false`) and asserting `IntentProfileValidationException` when mutated with non-zero rate or `tax_inclusive: true`.
7. `test_bootstrap_taxes_calculation_authority`: Wire schema test verifying `taxes[].calculation` enum authority.

---

## 4. Machine-Generated Source File Hashes (from `pre_run3_hashes.json`)

| File | SHA256 Hash |
| :--- | :--- |
| `app/Http/Controllers/Api/Mobile/V1/BootstrapController.php` | `e35838f0bfda7ccb9534c20ec4d8d45f5eedef7501912499f5ac384637d2727c` |
| `tests/Feature/Mobile/BootstrapTest.php` | `bcde31e3b0044e34bdb55286b8937c91cb0b25f08671117be093602e9cad415f` |
| `tests/Unit/Mobile/Contract/BootstrapMetadataContractTest.php` | `34e486deed32224e7d25cecf556f617f582590ad9f32cf3655b9dc3cbd8b5721` |
| `docs/integration/mobile-v1/schemas/bootstrap-response.json` | `3c7c7be0f7e096fa3063436aaa4ca9ecb18c27805312a8ddb6ca1adbfb89de55` |
| `docs/integration/mobile-metadata-contract-g1-g2.md` | `d31fd4bef314d5e03fc1f60f97252694405dbce06f84aba3922e03befd29ca3a` |
| `app/Services/Money/CurrencyMinorUnitsResolver.php` | `982852c8faa129d678dc6a46b24b52267306ad420c21828ba7bca641a0938ecd` |
| `app/Support/Mobile/Contract/Revision3/OnlineSaleIntentProfile.php` | `6fa214c8696338f50117c465dce1e54893fbefd627ef6183f5ece42d182f45f8` |
| `tests/Support/DisposableMariaDbSchema.php` | `ac69d1e6f0875eff250ce1fd688f47fbb95162dcd081f33f889ebf1fc184c352` |
| `tests/Support/run-phase8-disposable.php` | `06cb8f5f25b7fed9d08b29c0baee7de155725e3a7fbd0ddd3525190b43860329` |

---

## 5. Post-Execution Disposable Verification & Invariant Proof
- **Exact Schema Absence Witness (`docs/evidence/pr102-g1-qa/schema_absence_witness.txt`)**:
  ```text
  WITNESS_TIMESTAMP: 2026-11-15T08:07:01Z
  MARIADB_HOST_PORT: 127.0.0.1:3342
  EXACT_QUERY SCHEMA = accounting_p8_tmp_aad612ff0b2d => COUNT: 0
  EXACT_QUERY SCHEMA = accounting_p8_tmp_3732c1a02338 => COUNT: 0
  EXACT_QUERY SCHEMA = accounting_p8_tmp_39c8297fc636 => COUNT: 0
  WILDCARD_QUERY LIKE accounting_p8_tmp_% => COUNT: 0
  ```
- **Temporary Files & Processes**:
  - Private config `/tmp/p8_phpunit_*`: Absent (unlinked in finally block).
  - Background processes: 0 running.
  - Raw stdout/stderr logs tracked under both `.log` and `.txt` extensions to guarantee repository portability without gitignore filtering.
- **Economic Invariant Preserved**: Historical baseline commit `bef066c49c89b4518b5732ed57086896aba0ffa9` zero-event DB PASS remains unaltered. Production economic core (services, models, routes, config) remains strictly 0-byte diff vs baseline.

---

## 6. Contract Delivery for Android Root Coordination
1. **G1 Read Metadata Projection**:
   - Query parameter: `GET /api/mobile/v1/companies/{companyId}/bootstrap?include=currency_minor_units`
   - When parameter is omitted: Exact 12-field response returned matching Android whitelist (`BootstrapResponse.ALLOWED_KEYS`).
   - When exact `include=currency_minor_units` is requested: Response includes single authoritative field `currency_minor_units` (integer `0..6`).
   - Schema SHA256: `3c7c7be0f7e096fa3063436aaa4ca9ecb18c27805312a8ddb6ca1adbfb89de55`.
   - Removed alias: `base_currency_minor_units` is completely removed and rejected by both schema and query validator.
   - Any empty, null, array, or unknown include returns HTTP 422 `VALIDATION_FAILED`.
2. **G2 Tax Metadata Authority**:
   - `taxes[].calculation` in Bootstrap response is the sole source authority for tax calculation mode (`"exclusive"` or `"inclusive"`).
   - Product catalog unassigned tax pairs (`tax_rate_id: null`, `tax_rate: null`) do NOT imply `tax_inclusive: false`.
   - Explicit NoTax in Revision 3 intents requires intentional client specification: `tax_rate_id: null`, `tax_rate: "0.000000"`, `tax_inclusive: false`.

---

## 7. Machine-Generated Artifact Manifest (`docs/evidence/pr102-g1-qa/artifact_manifest.json`)

| Artifact Name | SHA256 Hash | Bytes |
| :--- | :--- | :--- |
| `post_run1_hashes.json` | `1f1f15daba109918dec8e41199f7a13737b55a2d53ca6d124a5478063f62b94d` | 1,144 |
| `post_run1_head.txt` | `b80573ed406e1c6c12815012dca8d6e616e9ef689fdc0be21e480f23497f1f38` | 41 |
| `post_run1_status.txt` | `8be372a315be3f2e95cad0ecb82027606127910dbead4cb0c3493698e51bebce` | 30 |
| `post_run2_hashes.json` | `837ede2207250b354c4d370184f112df4e5ef7a5fff775019153ea3eba8720b3` | 1,144 |
| `post_run2_head.txt` | `b17bfa2da91eb305db291b16905f9a0bedbe3cf56f85ea0d75e4dd0e9f53b0ff` | 41 |
| `post_run2_status.txt` | `8be372a315be3f2e95cad0ecb82027606127910dbead4cb0c3493698e51bebce` | 30 |
| `post_run3_hashes.json` | `c6549c0f2d186bd58d9b9c4886dd23a3fc0199a45d0ac54d09a4249668d2154f` | 1,144 |
| `post_run3_head.txt` | `470b2d90d31daf79d74eb88249776bc8c6bb6e175e7eac659f6cf962a4d0c4d0` | 41 |
| `post_run3_status.txt` | `8be372a315be3f2e95cad0ecb82027606127910dbead4cb0c3493698e51bebce` | 30 |
| `pre_run1_hashes.json` | `1f1f15daba109918dec8e41199f7a13737b55a2d53ca6d124a5478063f62b94d` | 1,144 |
| `pre_run1_head.txt` | `b80573ed406e1c6c12815012dca8d6e616e9ef689fdc0be21e480f23497f1f38` | 41 |
| `pre_run1_status.txt` | `8be372a315be3f2e95cad0ecb82027606127910dbead4cb0c3493698e51bebce` | 30 |
| `pre_run2_hashes.json` | `837ede2207250b354c4d370184f112df4e5ef7a5fff775019153ea3eba8720b3` | 1,144 |
| `pre_run2_head.txt` | `b17bfa2da91eb305db291b16905f9a0bedbe3cf56f85ea0d75e4dd0e9f53b0ff` | 41 |
| `pre_run2_status.txt` | `8be372a315be3f2e95cad0ecb82027606127910dbead4cb0c3493698e51bebce` | 30 |
| `pre_run3_hashes.json` | `c6549c0f2d186bd58d9b9c4886dd23a3fc0199a45d0ac54d09a4249668d2154f` | 1,144 |
| `pre_run3_head.txt` | `470b2d90d31daf79d74eb88249776bc8c6bb6e175e7eac659f6cf962a4d0c4d0` | 41 |
| `pre_run3_status.txt` | `8be372a315be3f2e95cad0ecb82027606127910dbead4cb0c3493698e51bebce` | 30 |
| `run1_junit.xml` | `62e6f583abc84ff2303aa9595d423fc65e7d3b94496b6f5177be48e97df951e4` | 5,732 |
| `run1_meta.json` | `51d6427523acb6b6ab3b35232ad983411c697fdbeee7d539bb69bd0920c49c5c` | 108 |
| `run1_stderr.log` / `.txt` | `837d346fa84ebd524dc7c6cfd159c116d18e9c69e2c40c8a6d7c34004204ed88` | 106 |
| `run1_stdout.log` / `.txt` | `55de438afe6b6885364f59f210c2ebd4e5064983f71cbb60222e18839b21bd81` | 1,124 |
| `run2_junit.xml` | `1ebb2ec128aba39c2f9e92e4afe7e8c004ec0e44ced0140689517cfdc5e073c4` | 11,674 |
| `run2_meta.json` | `e0840ab8f703b5528cbccb08aba2e71ff5831e5399598c4060af25bf137c5e4f` | 108 |
| `run2_stderr.log` / `.txt` | `80fa5584dc90d814430fc31b52090b1916e30f7819362dd73cc1aec38396ac23` | 106 |
| `run2_stdout.log` / `.txt` | `1b21eed8aea5072f87cd156aa036ca503bf3c0fba6591126d51609e015765221` | 3,275 |
| `run3_junit.xml` | `f41c792dfed1224326b23de2698ef09da38df57554f39cf699f7e3c18866eb43` | 8,820 |
| `run3_meta.json` | `e092b006759d90df01f99bae3ce868c5cf6f0812c4de49bf16b96711ead69166` | 108 |
| `run3_stderr.log` / `.txt` | `60970453aa2b254d842be3eebba0e2b7414f746a74332cb7a89fd58b3bcfea90` | 106 |
| `run3_stdout.log` / `.txt` | `e1d1ce76c435c2a7ad59dd3e8047dea9a210485a4691c053fa83e9b62f9ba9f4` | 442 |
| `schema_absence_witness.txt` | `fe3b339067634e09fa23efbc8ec6db9b519c2c4e80586c11f0d50c035a7c7961` | 318 |
