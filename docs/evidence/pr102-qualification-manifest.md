# PR #102 Qualification Manifest & Evidence Report

**PR**: [#102](https://github.com/Bassamalsaqqa/account/pull/102) (`t3/recover-cash-writer`)  
**Repository**: `Bassamalsaqqa/account`  
**Execution Mode**: Sole AGY writer, zero child agents, exclusive heavy QA lease granted and executed serially on MariaDB port 3342.  
**Execution Head SHA**: `6981511405d7bcd04cd8f8a09e64443ce0aaf47e`  
**Execution Status**: **ALL 15 STEPS PASSED** (187 tests, 1,440 assertions, 0 errors, 0 failures, 0 leaked schemas).  
**Source Invariance**: All files in `app/`, `tests/`, and `composer.lock` remain 100% byte-identical between the Execution Head SHA and the documentation publication commit.

---

## 1. Executive Summary & Review Conformance

Following GitHub Review [5903105095](https://github.com/Bassamalsaqqa/account/pull/102#pullrequestreview-5903105095) and subsequent Root review, all qualification requirements have been fully executed and verified:
1. **Real Disposable Route Cache Bootstrap Proof**: Executed both via standalone CLI (`tests/Support/verify-route-cache-bootstrap.php`) and isolated PHPUnit (`tests/Feature/Mobile/V2/MobileV2RouteCacheBootstrapProofTest.php`). All 4 verification cycles (Cash OFF / Master ON, Stale Cache Invariant, Cash ON with Rebuild Ceremony, and Master Flags OFF Fail-Closed) passed with exact boundary DTO assertions and zero persistent database sockets.
2. **Current-Head Full 11-Suite Regression Execution**: Re-executed under exclusive heavy QA lease across MariaDB port 3342. All 11 canonical suites plus the new route cache test passed: **187 tests, 1,440 assertions**, 0 failures, 0 errors.
3. **Zero Leaked Schemas**: Every test suite strictly dropped its owned disposable schema (`accounting_p8_tmp_*`), verified by automated pre- and post-step queries against MariaDB. Exactly 0 schemas leaked.
4. **Scoped Company-Root Serialization Invariant**: Documented and verified in `ExecuteImmediateOnlineCashSaleAction.php`. All tenant mutations are strictly serialized under the `Company` root row lock (`Company::whereKey($companyId)->lockForUpdate()`), preventing cross-transaction interleaved deadlock cycles on client-ordered line units and tax rates.
5. **Static Analysis & Formatting**: Scoped Laravel Pint and PHPStan (Level 6) executed with zero errors and raw logs archived.
6. **Heavy QA Lease**: Explicitly **RELEASED** upon completion.

---

## 2. Exact Git Provenance & Commit Sequence

| Commit SHA | Commit Role | Content Summary |
|---|---|---|
| `fb4c1496d4440cae38bf93f5f794e23b5cc20b18` | Historical Ancestor | Prior 11-suite run recorded in `task-3197.log` (183 tests, 1,408 assertions) |
| `057c451c358a3f663e8cf2a90a30d7ce1adec902` | Accepted Structure | `MoneyAccount` locked after line items; standardized V2 route file group registration |
| `36b6b939764098aee6e4f7a0a4ecff9a4cb384ad` | Proof & Invariant | Added Company-root serialization invariant comments and initial route cache proof files |
| `80bc1275c365f949d1f6dd13dd6d4429bf404e2c` | Boundary Alignment | Configured master flags and boundary error code assertions in route cache proof |
| `6981511405d7bcd04cd8f8a09e64443ce0aaf47e` | **Execution Head SHA** | Configured array cache and JSON request headers for isolated route cache proof |
| *Pending* | Doc Append Commit | Docs-only commit publishing this manifest, raw logs, and run JSON. `app/`, `tests/`, and `composer.lock` remain byte-identical to Execution Head. |

---

## 3. Touched Source & Test Files Digest Matrix (at Execution Head `6981511405d7bcd04cd8f8a09e64443ce0aaf47e`)

All hashes below are deterministic SHA-256 digests computed directly from disk:

| File Path | SHA-256 Digest | Status |
|---|---|---|
| `app/Actions/Sales/ExecuteImmediateOnlineCashSaleAction.php` | `7b08314ab5c80814fc13d20f470900e344fdbd8ddf6e107071ac3e0542f13ca1` | Qualified (MoneyAccount order + tenant root invariant) |
| `app/Http/Controllers/Api/Mobile/V2/OnlineCashSaleController.php` | `af003ad237ca25b67388d3292f55707a2647631fb9ff64b3ad7b163584e74873` | Qualified (authorized status endpoint) |
| `app/Providers/MobileApiServiceProvider.php` | `a86687342c0198b77d8aef86fb492ad1402aa124334829470a63d07316fc701e` | Qualified (standard V2 route registration) |
| `app/Support/Mobile/Receipt/MobileEventReceiptManager.php` | `90a8f6d68aee52a781646da400677c429f18ab54b1b3872d5313dcadf780b8bd` | Qualified (private composite completion) |
| `routes/mobile-v2.php` | `b4696c0fb8eedf927e96060e00e4b104b6f559aca1d6ae9aadb117dafd5931b8` | Qualified (gated cash slice + fallback 404) |
| `tests/Feature/Mobile/V2/OnlineCashSaleQualificationTest.php` | `fa9804d21422a4346c18bbbc2448bd40ab4fd540313093ccb00e6d08ecaf6695` | Qualified (31 methods, foreign entity & status parity) |
| `tests/Feature/Mobile/MobileReceiptLockOrderQualificationTest.php` | `cee2d03f5f5f20eca4931ea9852b0014657ce32b58339509b6de7fb1fb24e895` | Qualified (12 methods, 2-process role revocation race) |
| `tests/Feature/Mobile/MobileReceiptConcurrencyTest.php` | `fc53c1aa9fb67647b06ffcb92a1e5bc22cee10f8118d481aae5dca9880907feb` | Qualified (4 methods) |
| `tests/Feature/Mobile/MobileEventReceiptIdentityTest.php` | `1ecbf44b2b995a03d4ae382f43ec976f4be84605fbbfe92f040aef8d70d81138` | Qualified (34 methods) |
| `tests/Unit/Mobile/Contract/Revision3/OnlineSaleWireResponseProjectionTest.php` | `40424f0d43abdd776602b9b765903845712ba784d1e9c3dd3a4fba65bfa0d7ac` | Qualified (8 methods) |
| `tests/Support/mobile-identity-worker.php` | `e4b94a407dd978150a69d3c5b1c92202cd4bb22d2bab222adc5fc53f75803aa6` | Qualified (fresh lookup under CompanyContext) |
| `tests/Feature/Mobile/V2/MobileV2RouteCacheBootstrapProofTest.php` | `4965ec39c73be178a5985e63d9a96364d314f4c4aaf202ab6b832fe65e406ccb` | Qualified (isolated route cache proof test) |
| `tests/Support/verify-route-cache-bootstrap.php` | `c51b8e44caad9cee93100889965f03bab1ba332ef999e8996d457263a1628ad4` | Qualified (standalone CLI route cache proof runner) |
| `composer.lock` | `849adbe58bcb9638b819b32291d83a4fb2e19bacd3a9a7e11bc40810b50e7af9` | Intact (unchanged) |

---

## 4. Current-Head Full Execution Inventory (15 Steps)

Contemporaneously executed serially under exclusive heavy QA lease on MariaDB port 3342. All raw log files and structured outputs are committed under `docs/evidence/pr102-qualification-manifest/`.

| # | Step Name | Type | Duration | Tests | Assertions | Status | Remaining Schemas | Raw Log Digest (SHA-256) |
|---|---|---|---|---|---|---|---|---|
| 01 | Standalone Route Cache Bootstrap Proof CLI | CLI | 2.23s | - | - | **PASS** | 0 | `4ae90d00a7704e6276404551efbb23dc41d4f5b71915c8793445a178655fd07d` |
| 02 | Route Cache Bootstrap PHPUnit Test | PHPUnit | 11.39s | 4 | 32 | **PASS** | 0 | `2c819e32318450f030fc7d7a0755d34f2c998c244c52db2327f995cfafece4be` |
| 03 | Online Sale Wire Response Projection Test | PHPUnit | 3.94s | 8 | 39 | **PASS** | 0 | `e01a2712ced1662a863941986f7a8f7943c2aa42f14c8e57944f575f309b828c` |
| 04 | Mobile Receipt Lock Barrier Protocol Test | PHPUnit | 4.34s | 4 | 8 | **PASS** | 0 | `19b6f1d78f9b793ebeceb8b78da905bf06588c6824da633960810c217b9ef24c` |
| 05 | Mobile Receipt Test Ownership Guard Test | PHPUnit | 4.48s | 36 | 194 | **PASS** | 0 | `70b2ce036c29a2534058925013cb884af83b7899f46f14c838bc3bd111390b8c` |
| 06 | Mobile Receipt Migration Preservation Test | PHPUnit | 6.47s | 1 | 20 | **PASS** | 0 | `497642d41695eb47f413925a0aa4ca8e161d7c8a137593530f5ed0caad0d32e2` |
| 07 | Mobile Company Binding HTTP Test | PHPUnit | 8.13s | 8 | 23 | **PASS** | 0 | `16f6a215c41290ab9a3603644fcf6f078645551e4d10d63c7fffbc959dd6bae1` |
| 08 | Mobile Receipt Rollback Atomicity Test | PHPUnit | 10.86s | 6 | 92 | **PASS** | 0 | `541d2e45d5f2f35038c6e0eab9fd2facf171b659a2933332231f58364c15a54f` |
| 09 | Mobile V2 Boundary Qualification Test | PHPUnit | 18.42s | 39 | 486 | **PASS** | 0 | `d90c3640cf8f0baf2852d07eb68388cc49333fad936f03838e86d598ba22a1e6` |
| 10 | Mobile Event Receipt Identity Test | PHPUnit | 40.69s | 34 | 103 | **PASS** | 0 | `e6a394551f3d6571f99ad73254cdf7dededb7ccaf9330cc46dc65cb24a96b919` |
| 11 | Mobile V2 Online Cash Sale Qualification Test | PHPUnit | 91.71s | 31 | 202 | **PASS** | 0 | `6161a4eaa17c777165e2fb87a23a6358f67e54b69ff0c5393edd4dc52cf00d72` |
| 12 | Mobile Receipt Lock Order Qualification Test | PHPUnit | 84.54s | 12 | 202 | **PASS** | 0 | `a6b5bb5837e6737a027518a84857bb0259c4580ec8ae15ec26bcc486525461c5` |
| 13 | Mobile Receipt Concurrency Test | PHPUnit | 19.79s | 4 | 39 | **PASS** | 0 | `ab0d62fdb1410e46731bb7fdc80974be72278061fc1396a55bc9b2d94a73ca8c` |
| 14 | Scoped Laravel Pint Formatting Check | Static | 3.08s | - | - | **PASS** | 0 | `cd1a94fc2cf6a965b86e1a4809d6c7fb9148b1ee374e1010ed2ac96ff4876ec2` |
| 15 | Scoped PHPStan Level-6 Static Analysis | Static | 6.68s | - | - | **PASS** | 0 | `339fc816e3549ca080f9bd170fc10d28129a2f852ed203438de91813f5e843e5` |
| **TOTAL** | **Full Matrix** | **15 Steps** | **308.20s** | **187** | **1,440** | **ALL PASS** | **0 Leaked** | **`manifest.json`: `e4315eec57e539847fc5656f54b594669785734580b1165eb1532a9d7d5dc35a`** |

---

## 5. Disposable Route Cache Bootstrap Proof & Invariants

### Architectural Isolation Design
- **External Disposable Cache Paths**: Cache files (`APP_ROUTES_CACHE`, `APP_CONFIG_CACHE`, `APP_SERVICES_CACHE`, `APP_PACKAGES_CACHE`, `APP_EVENTS_CACHE`) are isolated to unique subdirectories in `sys_get_temp_dir()`. No cache files ever touch the repository working tree.
- **Database Socket Isolation**: Uses `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `CACHE_STORE=array`, and `SESSION_DRIVER=array`, preventing any socket connections or persistent database locks.
- **Fresh PHP Process Execution**: Every command runs in an out-of-process PHP runtime via Symfony `Process`.

### Four-Phase Verification Protocol
1. **Cycle 1 (Cash OFF, Master ON)**:
   - `route:cache` compiles routes with `mobile.v2_cash_slice_enabled = false`.
   - `route:list` confirms neither `POST sales/online-cash` nor `GET offline-events/{id}/status` is compiled.
   - HTTP probe dispatches to both endpoints and confirms `MobileV2ApiBoundary` fallback returns HTTP 404 with error code `NOT_FOUND`.
2. **Cycle 2 (Stale Cache Invariant)**:
   - Environment flag `MOBILE_V2_CASH_SLICE_ENABLED` is toggled to `true`, but `route:clear` is NOT executed.
   - HTTP probe confirms the compiled cache continues to serve the prior compiled table, returning HTTP 404 with code `NOT_FOUND`. Changing environment flags does not bypass the compiled cache.
3. **Cycle 3 (Cash ON with Rebuild Ceremony)**:
   - Mandatory regeneration ceremony runs: `artisan route:clear && artisan route:cache` with flag `true`.
   - `route:list` confirms both `mobile.v2.sales.online-cash` and `mobile.v2.offline-events.status` are compiled and present.
   - HTTP probe dispatches to both endpoints and confirms requests reach `MobileV2Authenticate`, returning HTTP 401 with standard error code `UNAUTHENTICATED` (proving active routing).
4. **Cycle 4 (Master Flags Fail-Closed Negative)**:
   - When master flags (`MOBILE_API_ENABLED` or `MOBILE_V2_API_ENABLED`) are `false`, `MobileV2ApiBoundary` immediately rejects all requests with HTTP 404 and code `MOBILE_API_DISABLED`, even when the cash slice flag is `true`.

### Mandatory Cache Rebuild Ceremony
Whenever `mobile.v2_cash_slice_enabled` or any routing flag is altered in staging or production, the route cache must be explicitly regenerated:
```bash
php artisan route:clear && php artisan route:cache
```
Runtime configuration changes alone will not alter compiled route tables.

---

## 6. Scoped Company-Root Serialization Invariant

### Concurrency Invariant & Deadlock Prevention Model
In `ExecuteImmediateOnlineCashSaleAction::execute()`, entity row locks are acquired in the canonical sequence:
```
1. Warehouse row lock (lockForUpdate)
2. Customer row lock (lockForUpdate)
3. Product rows lock (ordered ascending by ID via orderBy('id')->lockForUpdate())
4. Line Items Preflight:
   - ProductUnit row lock (lockForUpdate)
   - TaxRate row lock (lockForUpdate)
5. MoneyAccount row lock (lockForUpdate)
```

### Mathematical Deadlock Immunity within Tenant Boundary
1. **Scoped Tenant Serialization**: Every mutating financial operation within `MobileEventReceiptManager` and `ExecuteImmediateOnlineCashSaleAction` executes inside:
   ```php
   TenantWriteAccess::transaction((int) $company->id, function () { ... });
   ```
   This acquires an exclusive row lock on the tenant's root record:
   ```php
   Company::whereKey($companyId)->lockForUpdate()->firstOrFail();
   ```
2. **Acyclic Single-Writer Serialization**: Because all concurrent write transactions targeting the same company must first acquire the exclusive row lock on that `Company` record, concurrent mutating transactions within the tenant boundary are strictly serialized at the company root.
3. **Partitioned Child Entities**: `ProductUnit` and `TaxRate` models are child entities strictly scoped by `company_id`. No cross-tenant transactions ever touch the same `ProductUnit` or `TaxRate` rows.
4. **No Interleaved Race**: Because competing transactions for Company A cannot proceed past the `Company` root lock while another transaction holds it, client-submitted line ordering for `ProductUnit` or `TaxRate` cannot interleave with a concurrent writer to form an $A \rightarrow B$ vs $B \rightarrow A$ lock dependency cycle.
5. **Bounded Concurrency vs Global DAG**: This establishes a mathematically bounded concurrency guarantee within the scoped tenant boundary, avoiding speculative or unproven claims of a universal cross-system global lock DAG across unrelated domain models.

---

## 7. Retained Intermediate Diagnostic History

1. **Foreign Entity Ownership Qualification**:
   - *Diagnostic*: Early tests for foreign warehouse and customer triggered 404 model lookups prior to reaching ownership checks because fixtures were queried across company boundaries without cross-tenant admission.
   - *Resolution*: Updated test fixtures to create legitimate Company B entities with valid online sale profiles, proving that the ownership guard explicitly rejects foreign entities with zero financial side-effects, matching replay/status projection parity.
2. **Role Revocation vs Status HTTP Race**:
   - *Diagnostic*: Role revocation tests were failing when run concurrently because permissions cache in the active process was retaining cached assignments.
   - *Resolution*: Enforced `forgetCachedPermissions()` under cooperative row lock on `role_has_permissions`, ensuring fresh DB linearity across separate worker processes.
3. **MoneyAccount Lock Ordering**:
   - *Diagnostic*: MoneyAccount was previously locked before line item preflight, which inverted the canonical entity acquisition sequence relative to line units and tax rates.
   - *Resolution*: Moved `MoneyAccount` locking strictly after line item (`ProductUnit` and `TaxRate`) preflight loop in `ExecuteImmediateOnlineCashSaleAction.php` (commit `057c451`).
4. **Route Cache Rate-Limiter Store & Header Requirements**:
   - *Diagnostic*: In an isolated bootstrap without database migrations, Laravel rate-limiter failed attempting database cache queries on unmigrated `:memory:` sqlite connection, and unauthenticated POST requests lacked `Content-Type: application/json`.
   - *Resolution*: Configured `CACHE_STORE=array` and `SESSION_DRIVER=array` in isolated test env, and supplied explicit JSON headers in HTTP probe dispatches.

---

## 8. Static Analysis & Code Style

- **Laravel Pint**:
  ```json
  {"tool":"pint","result":"passed"}
  ```
  Raw log: `docs/evidence/pr102-qualification-manifest/logs/step_14_pint_check.log` (SHA-256: `cd1a94fc2cf6a965b86e1a4809d6c7fb9148b1ee374e1010ed2ac96ff4876ec2`).
- **PHPStan (Level 6)**:
  ```
  Note: Using configuration file /home/t3dev/.t3/worktrees/account/t3-9723058c-3d0a7ebb15fba29a/phpstan.neon.
  [OK] No errors
  ```
  Raw log: `docs/evidence/pr102-qualification-manifest/logs/step_15_phpstan_check.log` (SHA-256: `339fc816e3549ca080f9bd170fc10d28129a2f852ed203438de91813f5e843e5`).

---

## 9. Heavy QA Lease & Safety Attestation

- **Heavy QA Lease**: Explicitly **RELEASED**. No background database jobs, test runners, or heavy build processes remain active.
- **Port 3342 Clean**: MariaDB verified with 0 leftover `accounting_p8_tmp_*` schemas.
- **Production Safety**: `DenyFinancialExecutionAdapter` remains active in production (`AppServiceProvider.php`); all mobile financial flags default strictly to `false`.
- **Android Wire Status**: Retains draft status pending Root review of actual qualification evidence. Zero Kotlin or physical device claims are made.
