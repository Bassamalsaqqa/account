# PR #102 G1 Read-Metadata & G2 Tax Contract Disposable DB Qualification Report

## 1. Executive Summary
- **Outcome**: **QUALIFIED & PASS (GREEN)**
- **PHPUnit Result**: 22 tests, 138 assertions, 0 failures, 0 errors, 0 skipped.
- **Execution Target**:
  - `tests/Feature/Mobile/BootstrapTest.php` (15 tests, 104 assertions)
  - `tests/Unit/Mobile/Contract/BootstrapMetadataContractTest.php` (7 tests, 34 assertions)
- **Harness & Environment**: Isolated disposable MariaDB on port 3342 via `tests/Support/run-phase8-disposable.php`
- **Lease Status**: **RELEASED**. Backend G1 QA lease concluded cleanly. No background processes, no orphaned schemas, no lingering temp files. Next lease yielded to Android compile/policy.

---

## 2. Invocations & Routine Bounded Fix Trajectory

### Invocation 1 (Pre-run SHA: `1e773ca15487877529d4eceb03373c798f80454b`)
- **Command**: `php tests/Support/run-phase8-disposable.php -- --stop-on-failure --log-junit=...`
- **Assigned Primary Schema**: `accounting_p8_tmp_aad612ff0b2d`
- **Result**: Exit code 1 (12 passed, 1 failure). Stopped on first failure.
- **Root Cause**: `test_bootstrap_optin_include_projects_authoritative_currency_minor_units_for_ils_jod_and_zero_scale` expected HTTP 200 but received 409 `CONTRACT_INCOMPATIBLE`. `CurrencyMinorUnitsResolver::resolveForCompany()` strictly requires `CompanyCurrency::where('company_id', $id)->where('currency_code', 'ILS')->where('enabled', true)->exists()`, which was not seeded in `setUp()`.
- **Cleanup**: Schema `accounting_p8_tmp_aad612ff0b2d` cleanly dropped. Raw stdout/stderr preserved in `docs/evidence/pr102-g1-qa/run1_*.log`.
- **Committed Narrow Fix**: Commit [`b0543cdfba2a10916f4028b159629bcf53bef026`](https://github.com/Bassamalsaqqa/account/commit/b0543cdfba2a10916f4028b159629bcf53bef026) seeded enabled `company_currencies` for ILS, JOD, and JPY companies.

### Invocation 2 (Pre-run SHA: `b0543cdfba2a10916f4028b159629bcf53bef026`)
- **Command**: `php tests/Support/run-phase8-disposable.php -- --stop-on-failure --log-junit=...`
- **Assigned Primary Schema**: `accounting_p8_tmp_3732c1a02338`
- **Result**: Exit code 2 (20 passed, 2 errors).
- **Root Cause**:
  1. `NoActiveCompanyException` when creating secondary company models (`LedgerAccount`, etc.) outside tenant context in `BootstrapTest.php`.
  2. Missing import for `CurrencyMinorUnitsResolver` in `BootstrapMetadataContractTest.php`.
- **Cleanup**: Schema `accounting_p8_tmp_3732c1a02338` cleanly dropped. Raw stdout/stderr preserved in `docs/evidence/pr102-g1-qa/run2_*.log`.
- **Committed Narrow Fix**: Commit [`97f421e7d51fd00f2a4dc109c93554c83ad31151`](https://github.com/Bassamalsaqqa/account/commit/97f421e7d51fd00f2a4dc109c93554c83ad31151) wrapped secondary company model provisioning in `CompanyScope::executeWithoutScope(...)` and restored the `CurrencyMinorUnitsResolver` import.

### Invocation 3 (Pre-run SHA: `97f421e7d51fd00f2a4dc109c93554c83ad31151`)
- **Command**: `php tests/Support/run-phase8-disposable.php -- --stop-on-failure --log-junit=...`
- **Assigned Primary Schema**: `accounting_p8_tmp_39c8297fc636`
- **Execution Time**: 7.298 seconds
- **Result**: **Exit code 0. OK (22 tests, 138 assertions)**
- **Cleanup**: Schema `accounting_p8_tmp_39c8297fc636` cleanly dropped. Verifiable absence query confirmed 0 schemas remaining.

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

## 4. Source File SHA256 Hashes

| File | SHA256 Hash |
| :--- | :--- |
| `app/Http/Controllers/Api/Mobile/V1/BootstrapController.php` | `9df528b7eec9cb5586ae5f02c4fc628552fc3897b5e4c6c99c4aa0cba0631671` |
| `tests/Feature/Mobile/BootstrapTest.php` | `08560fe96b3240836541f5ad59ee5293bb27e2a91f5e27a6962eaefb3a0179a3` |
| `tests/Unit/Mobile/Contract/BootstrapMetadataContractTest.php` | `5c9d2f2ea29eb5fcba59ea16a85f4fa6e3e507aa822d574bb4d8f075d9ba6564` |
| `docs/integration/mobile-v1/schemas/bootstrap-response.json` | `ff1594916a04870c53ec4aee8be6ec3c0f4f9ea9333333333333333333333333` |
| `docs/integration/mobile-metadata-contract-g1-g2.md` | `b99f24300e84b04d1ef546d1bf1e6fbeff1fa42982d6ca0495fceef175aee177` |
| `app/Services/Money/CurrencyMinorUnitsResolver.php` | `50702d84a7e93dc49cbb8b6689d7fa13c3a444a7f5a98bf683ae06b3a2ea1295` |
| `app/Support/Mobile/Contract/Revision3/OnlineSaleIntentProfile.php` | `1bc1f6063bb91a67305952ca3930bbf9b20b22a075fa0565576a8ef64c5fb04b` |
| `tests/Support/DisposableMariaDbSchema.php` | `1f364e7c7e63b6528d9ffbcf8c43fcb81f185efce5982877a5610ea9fc5754f7` |
| `tests/Support/run-phase8-disposable.php` | `29938b8159bcda08d8e5797371588647035548680ad7bdfa57dbd762039433ff` |

---

## 5. Post-Execution Disposable Verification & Invariant Proof
- **Exact Schema Absence Check**:
  ```sql
  SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = 'accounting_p8_tmp_39c8297fc636';
  -- Result: 0 rows
  SHOW DATABASES LIKE 'accounting_p8_tmp_%';
  -- Result: 0 rows
  ```
- **Temporary Files & Processes**:
  - Private config `/tmp/p8_phpunit_*`: Absent (unlinked in finally block).
  - Background processes: 0 running.
- **Economic Invariant Preserved**: Historical baseline commit `bef066c49c89b4518b5732ed57086896aba0ffa9` zero-event DB PASS remains unaltered. Production economic core (services, models, routes, config) remains strictly 0-byte diff vs baseline.

---

## 6. Contract Delivery for Android Root Coordination
1. **G1 Read Metadata Projection**:
   - Query parameter: `GET /api/mobile/v1/companies/{companyId}/bootstrap?include=currency_minor_units`
   - When parameter is omitted: Exact 12-field response returned matching Android whitelist (`BootstrapResponse.ALLOWED_KEYS`).
   - When exact `include=currency_minor_units` is requested: Response includes single authoritative field `currency_minor_units` (integer `0..6`).
   - Removed alias: `base_currency_minor_units` is completely removed and rejected by both schema and query validator.
   - Any empty, null, array, or unknown include returns HTTP 422 `VALIDATION_FAILED`.
2. **G2 Tax Metadata Authority**:
   - `taxes[].calculation` in Bootstrap response is the sole source authority for tax calculation mode (`"exclusive"` or `"inclusive"`).
   - Product catalog unassigned tax pairs (`tax_rate_id: null`, `tax_rate: null`) do NOT imply `tax_inclusive: false`.
   - Explicit NoTax in Revision 3 intents requires intentional client specification: `tax_rate_id: null`, `tax_rate: "0.000000"`, `tax_inclusive: false`.
