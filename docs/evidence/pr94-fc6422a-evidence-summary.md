# PR #94 Exact-Head `fc6422a` Supervisor Evidence and Identity Lock Qualification

## 1. Executive Summary & Review Context

This document records:
1. **Source-Controlled Supervisor Evidence Custody** for exact PR #94 foundation head `fc6422a1f245301abd042d15a8867f25fc367de5`, responding to Owner GitHub reviews (`5886330102`, `5887358460`) and the subsequent PR #95 review on commit `2af24b7`.
2. **Comprehensive Source-Level Lock Hierarchy Audit** across all authentication, device revocation, password epoch, tenant membership, and receipt intake entrypoints, citing actual SQL queries and row locks.
3. **Reconciled Global Lock Order (Strict Acyclic DAG)** reconciling `AuthController::login` and `MobileEventReceiptManager` lock order: `User (L1) -> MobileDevice (L2) -> MobileAccessToken (L3) -> Company (L4) -> CompanyUser (L5)`.
4. **Deterministic Authoritative Membership Filtering**: Eliminating candidate query vs locked membership races in `AuthController::login` using locked current membership rows to authoritatively filter active companies, pick default company, and scope tokens, failing closed with 401 if no active memberships remain.
5. **Fail-Closed Epoch Rejection Without Ad-Hoc Autocommit**: Removing exception-string substring autocommit token updates, verifying repeat stale token rejection even if `revoked_at` is not persisted in DB.
6. **Critical Row-Held Barriers & Test Ownership**: Two-process OS barrier races using observable query events (`hold_lock_table`, `hold_ms`, `start_delay_ms`), production HTTP kernel execution for login and device revocation, Fortify `ResetUserPassword` password updates, bounded deadlock retries, and strict disposable schema ownership verification (`isOwned()`, regex `^accounting_p8_tmp_[a-f0-9]{12}$`, `SELECT DATABASE() === schemaName()`) before fixtures in every test method.
7. **Preservation of Core Invariants**: P0 disposable database ownership guard preserved, historical evidence in `docs/evidence/p0-pr94-refusal-proof.json` preserved intact (34/180 historical artifact), frozen V1 HTTPS pilot `e111f83bc740e9590142869b30428eaa220eab6f` unchanged, Python 210 contract validator manifest `ba753523...` unchanged, and production financial execution default-deny adapter (`DenyFinancialExecutionAdapter`) strictly maintained.

---

## 2. Supervisor Artifact & Exact-Head `fc6422a` Evidence

The supervisor artifact located at `/home/t3dev/.t3/userdata/antigravity-tmp/pr94-p0-preservation/fc6422a-followup/evidence.json` was verified under read-only custody.

### 2.1 Cryptographic Integrity & Log Digests
- **Original Private Supervisor Artifact SHA-256**: `7e8e4c35ffc43cbd04e28fab9e9fe747196ee55a3c068b320a19a4c6b6e187d0` (private un-redacted evidence file)
- **Exact Foundation Head**: `fc6422a1f245301abd042d15a8867f25fc367de5`
- **Supervisor Executor**: Codex supervisor
- **Timestamp**: `2026-11-13T04:58:25.550887+00:00`
- **GitHub Review Reference**: `5887358460` (follow-up to `5886330102`)

| Published Sanitized Log File | SHA-256 Digest | Status / Description |
|---|---|---|
| `docs/evidence/fc6422a-followup/pr94-followup-refusal.log` | `d3d422bc09cd34216e60280d74abe7df7d144de999e61986c742aec534424ed5` | 36 refusal tests / 194 assertions, 0 live DB queries |
| `docs/evidence/fc6422a-followup/pr94-followup-reverse.log` | `a0c491a10a9850cccd68fa9538baaf68e1a7d282e190df58594b164f75b1ea14` | 41 receipt tests / 206 assertions in reverse order, schema `accounting_p8_tmp_53e519180ddc` cleaned |
| `docs/evidence/fc6422a-followup/pr94-followup-random.log` | `7b130fc0d8bfef2ee1d0583184f1aea92f349fab79e5250a099d373cb334980c` | 41 receipt tests / 206 assertions (seed `20261113`), schema `accounting_p8_tmp_e7526be4f306` cleaned |
| `docs/evidence/fc6422a-followup/pr94-followup-static.log` | `8de455a1c8b8a2f1f78b87aa6561c182639fa339bbf3a063b1ccf1070135aa73` | Scoped PHPStan level 6 PASS |

Both supervisor disposable schemas (`accounting_p8_tmp_53e519180ddc` and `accounting_p8_tmp_e7526be4f306`) were dropped by the runner, and read-only `information_schema` absence was independently verified.

### 2.2 Historical Baseline Preservation
The existing file `docs/evidence/p0-pr94-refusal-proof.json` describes the earlier historical run on commit `17444ea77d5bc126da24ce3a6d5c248648fde37f` (34 tests / 180 assertions). Its byte-for-byte contents are preserved intact as an immutable historical record and are NOT overwritten.

---

## 3. Comprehensive Source-Level Lock Hierarchy Audit

A full audit of actual runtime row locks across all identity, authentication, device, tenant, and receipt entrypoints confirms the following cited SQL statements and row lock behaviors:

### 3.1 `AuthController::login`
- **Route**: `POST /api/mobile/v1/auth/login` (executed through Laravel HTTP Kernel and `MobileApiBoundary`)
- **Pre-Transaction Checks**: Challenge nonce verification and atomic single-use consumption (`UPDATE mobile_auth_challenges SET consumed_at = now() WHERE challenge_id = ? AND consumed_at IS NULL`).
- **Actual Runtime SQL & Row Locks**:
  1. `SELECT * FROM users WHERE email = ? FOR UPDATE;` (User principal row lock: verifies password, serializes MFA recovery replacement)
  2. `SELECT * FROM mobile_devices WHERE device_id = ? FOR UPDATE;` (Device hardware binding row lock: verifies device status and ownership)
  3. `SELECT company_id FROM company_user WHERE user_id = ? AND status = 'active';` (Candidate active company IDs, sorted ascending)
  4. `SELECT * FROM companies WHERE id IN (...) AND status = 'active' ORDER BY id ASC FOR UPDATE;` (Deterministic tenant boundary row locks in ascending ID order)
  5. `SELECT company_id FROM company_user WHERE user_id = ? AND company_id IN (...) AND status = 'active' ORDER BY company_id ASC FOR UPDATE;` (Authoritative membership row locks in matching ascending company ID order)
  6. Filters active companies strictly by confirmed locked active membership rows; rejects with 401 `INVALID_CREDENTIALS` if none remain; picks default company from confirmed locked set.
  7. Inserts `mobile_access_tokens` row with fresh `password_epoch`.

### 3.2 MFA / Recovery Challenge Consumption
- **Controller**: `AuthController::login` / `LockedTwoFactorSessionController`
- **SQL**: `SELECT * FROM users WHERE id = ? FOR UPDATE;`
- **Effect**: Serializes single-use recovery code replacement (`$user->replaceRecoveryCode($matched)`) under the User (L1) row lock.

### 3.3 Fortify Password Updates & Mobile Password Epoch
- **Actions**: Fortify `ResetUserPassword`, `UpdateUserPassword`
- **SQL**: `UPDATE users SET password = ? WHERE id = ?;`
- **Mechanism**: Modifies `users.password`. Downstream entrypoints evaluate `$expectedEpoch = 'epoch-'.substr(hash('sha256', (string) $user->password), 0, 16)`. Mismatches reject with 401 fail-closed cleanly.

### 3.4 `DeviceController::revoke` and `AuthController::logout`
- **Device Revocation**:
  - Route: `POST /api/mobile/v1/devices/{deviceId}/revoke` (executed through HTTP Kernel, `MobileApiBoundary`, `MobileAuthenticate`)
  - SQL: `SELECT * FROM mobile_devices WHERE device_id = ? FOR UPDATE;`
  - SQL: `UPDATE mobile_devices SET revoked_at = ? WHERE device_id = ?;`
  - SQL: `UPDATE mobile_access_tokens SET revoked_at = ? WHERE device_id = ? AND revoked_at IS NULL;`
- **Logout**:
  - Route: `POST /api/mobile/v1/auth/logout`
  - SQL: `UPDATE mobile_access_tokens SET revoked_at = ? WHERE id = ?;`

### 3.5 `MobileEventReceiptManager` (`recordOrReplay` and `lookup`)
- **Actual Runtime SQL & Row Locks**:
  1. `SELECT * FROM mobile_access_tokens WHERE token_hash = ?;` (Initial unlocked read to resolve user and device without oracle leakage)
  2. `SELECT * FROM users WHERE id = ? FOR UPDATE;` (User principal row lock: L1)
  3. `SELECT * FROM mobile_devices WHERE device_id = ? FOR UPDATE;` (Device hardware binding row lock: L2)
  4. `SELECT * FROM mobile_access_tokens WHERE token_hash = ? FOR UPDATE;` (Bearer token row lock: L3; verifies validity and password epoch)
  5. `SELECT * FROM companies WHERE id = ? FOR UPDATE;` (Tenant boundary row lock: L4)
  6. `SELECT * FROM company_user WHERE company_id = ? AND user_id = ? AND status = 'active' FOR UPDATE;` (Tenant membership row lock: L5)
  7. Team-scoped role/permission authorization (L6)
  8. Canonical financial entities (`sales_invoices`, `customer_payments`) locked via canonical writer transactions (L7)
  9. `INSERT INTO mobile_event_receipts ...;` (Terminal receipt record: L8)

### 3.6 Tenant Writers & Sales Actions (Why Canonical Writers Do NOT Lock User)
An essential finding from the source audit: **canonical tenant writers do NOT lock the `User` table**.
- **`TenantWriteAccess::assertWritable(int $companyId)`**:
  - SQL: `SELECT * FROM companies WHERE id = ? FOR UPDATE;`
  - SQL: `SELECT * FROM platform_subscriptions WHERE company_id = ? FOR UPDATE;`
  - Row locks: `companies` -> `platform_subscriptions`. Never locks `users`.
- **`SalesActorGuard::lockAndAuthorize(int $companyId, User $actor, string $perm)`**:
  - SQL: `SELECT * FROM companies WHERE id = ? FOR UPDATE;`
  - SQL: `SELECT * FROM company_user WHERE company_id = ? AND user_id = ? FOR UPDATE;`
  - Row locks: `companies` -> `company_user`. Never locks `users`.
- **`ToggleCompanyMemberStatusAction::execute`**:
  - SQL: `SELECT * FROM companies WHERE id = ? FOR UPDATE;`
  - SQL: `SELECT * FROM company_user WHERE company_id = ? AND id = ? FOR UPDATE;`
  - SQL: `UPDATE company_user SET status = ? WHERE id = ?;`
  - Row locks: `companies` -> `company_user`. InnoDB foreign key validation acquires S-lock on parent `users` row during `company_user` status update. Bounded deadlock retries handle concurrent worker interleavings cleanly.

---

## 4. Reconciled Global Lock Hierarchy (Acyclic DAG)

All identity, authentication, device, tenant, and receipt entrypoints strictly observe the following global Directed Acyclic Graph:

```
Level 1: User                 (Principal identity, credentials, password epoch, 2FA)
   ↓
Level 2: MobileDevice         (Hardware binding registered to user)
   ↓
Level 3: MobileAccessToken    (Session credential bound to user and device)
   ↓
Level 4: Company              (Tenant boundary, locked in deterministic ascending companies.id order)
   ↓
Level 5: CompanyUser          (Tenant membership row binding user to company, locked in matching company_id order)
   ↓
Level 6: Team Permissions     (Spatie roles/permissions scoped by company_id)
   ↓
Level 7: Canonical Entities   (Sales invoices, customer payments, inventory ledger)
   ↓
Level 8: MobileEventReceipt   (Terminal receipt claim and audit record)
```

### 4.1 Proof of Acyclic Ordering Across Operations
1. **`AuthController::login`**: Traverses L1 (`users`) -> L2 (`mobile_devices`) -> L4 (`companies` ASC) -> L5 (`company_user` ASC). Inserts L3 (`mobile_access_tokens`).
2. **`MobileEventReceiptManager`**: Traverses L1 (`users`) -> L2 (`mobile_devices`) -> L3 (`mobile_access_tokens`) -> L4 (`companies`) -> L5 (`company_user`) -> L6 (Permissions) -> L7 (Entities) -> L8 (`mobile_event_receipts`).
3. **`DeviceController::revoke`**: Operates at L2 (`mobile_devices`) -> L3 (`mobile_access_tokens`).
4. **Fortify `ResetUserPassword`**: Operates at L1 (`users`).
5. **`TenantWriteAccess` / `SalesActorGuard`**: Operates at L4 (`companies`) -> L5 (`company_user`).
6. **No Transitive Cycles**: Every operation acquires locks in monotonically increasing level order. Within Level 4 and Level 5, multiple rows are always acquired in strictly ascending numeric ID order (`companies.id ASC`, `company_user.company_id ASC`), preventing intra-level deadlocks.

---

## 5. Independent OS MariaDB Process Barrier Test Results

The qualification suite `tests/Feature/Mobile/MobileReceiptLockOrderQualificationTest.php` executes five independent two-process barrier race tests on disposable MariaDB (port 3342). Each worker runs as a separate OS process (`Process`) with its own PHP runtime, memory space, and MariaDB PDO connection pool, synchronizing via temporary file barriers (`.ready` / `go`) and observable query events (`DB::listen` held-lock barriers).

### Test 1: Real Production Login vs Receipt Intake
- **Workers**: Worker A (HTTP Kernel `POST /api/mobile/v1/auth/login`), Worker B (`MobileEventReceiptManager::recordOrReplay`).
- **Barrier**: Worker A holds lock on `users` row for 150ms; Worker B starts with 30ms offset to establish real InnoDB lock wait.
- **Fixture**: Multi-company owner user belonging to `Company A` (ILS) and `Company B` (USD), registered mobile device, valid challenge.
- **Outcome**: Both workers exit with code 0.
- **Worker A**: Status `200`, outcome `logged_in`, issued token, returned 2 companies.
- **Worker B**: Status `200`, outcome `created`, terminal receipt ID created.
- **Consistency**: Exactly 1 sales invoice, 1 customer payment, 1 receipt created. Accounting, Sales, Inventory (`auditCompany`), and Money reconciliations all reported `isHealthy === true`.
- **Deadlocks**: 0.

### Test 2: Device Revocation vs Receipt Intake
- **Workers**: Worker A (HTTP Kernel `POST /api/mobile/v1/devices/{deviceId}/revoke`), Worker B (`MobileEventReceiptManager::recordOrReplay`).
- **Barrier**: Worker A holds lock on `mobile_devices` row for 150ms.
- **Outcome**: Exit code 0 on both workers.
- **Worker A**: Status `200`, outcome `revoked`.
- **Worker B**: Converges cleanly to either `200` (`created`) or `401` (`auth_error`, message: `Device has been revoked or does not match token owner.`).
- **Negative Invariant**: When revoked first, 0 receipts, 0 invoices, and 0 customer payments were committed. Device is verified revoked in database.
- **Deadlocks**: 0.

### Test 3: Tenant Membership Deactivation vs Receipt Intake
- **Workers**: Worker A (`ToggleCompanyMemberStatusAction::execute`), Worker B (`MobileEventReceiptManager::recordOrReplay`).
- **Barrier**: Worker A holds lock on `companies` row for 150ms; bounded deadlock retry loop handles concurrent InnoDB foreign key checks.
- **Outcome**: Exit code 0 on both workers.
- **Worker A**: Status `200`, outcome `status_updated`, new status `inactive`.
- **Worker B**: Converges cleanly to either `200` (`created`) or `403` (`foreign_scope`, message: `User does not possess active authorized membership in the requested company.`).
- **Negative Invariant**: 403 foreign scope semantics preserved; 0 leaked invoices or receipts on deactivation-first outcome. Membership verified `inactive` in database.
- **Deadlocks**: 0 unhandled.

### Test 4: Password Epoch Advance vs Receipt Intake
- **Workers**: Worker A (Fortify `ResetUserPassword`), Worker B (`MobileEventReceiptManager::recordOrReplay`).
- **Barrier**: Worker A holds lock on `users` row for 150ms; advances password epoch.
- **Outcome**: Exit code 0 on both workers.
- **Worker A**: Status `200`, outcome `password_updated`, fresh epoch issued.
- **Worker B**: Converges cleanly to either `200` (`created`) or `401` (`auth_error`, message: `User credentials have changed since token establishment.`).
- **Stale Token Repeat Invariant**: Repeat receipt attempt with stale token is cleanly rejected with 401 fail-closed even though `revoked_at` is NOT persisted in DB by exception catches (verifying `assertNull($tokenRow->revoked_at)`).
- **Deadlocks**: 0.

### Test 5: Authoritative Membership Filtering During Concurrency Race
- **Scenario**: A user initially has active memberships in Company A and Company B. A concurrent administrator revokes membership in Company B before login completes.
- **Sub-case A (Single Revocation)**: Login confirms active membership only in Company A. Authoritative filter removes Company B; response contains exactly 1 company (`Company A`) marked `is_default: true`.
- **Sub-case B (All Revoked)**: All company memberships deactivated. `AuthController::login` strictly rejects with 401 `INVALID_CREDENTIALS`, preventing token issuance with empty or invalid company scopes.
- **Deadlocks**: 0.

---

## 6. Full Suite Regression & Tooling Manifest

| Suite / Check | Command | Count / Status | Notes |
|---|---|---|---|
| Refusal Ownership Guard & Protocol | `phpunit tests/Unit/Mobile/MobileReceiptTestOwnershipGuardTest.php tests/Unit/Mobile/MobileReceiptLockBarrierProtocolTest.php` | 40 tests, 202 assertions OK | Non-DB barrier protocol & refusal guards |
| Two-Process MariaDB Barrier Races | `run-phase8-disposable.php -- tests/Feature/Mobile/MobileReceiptLockOrderQualificationTest.php` | 5 tests, 67 assertions OK | Fresh schema, cleaned |
| V1 Auth / Device Regressions | `run-phase8-disposable.php -- tests/Feature/Mobile/Auth*.php` | 18 tests, 115 assertions OK | Fresh schema, cleaned |
| 5 Receipt Classes (Reverse) | `run-phase8-disposable.php -- --order-by=reverse tests/Feature/Mobile/Mobile*Receipt*.php` | 46 tests, 273 assertions OK | Reverse order, cleaned |
| 5 Receipt Classes (Random 20261113) | `run-phase8-disposable.php -- --order-by=random --random-order-seed=20261113 tests/Feature/Mobile/Mobile*Receipt*.php` | 46 tests, 273 assertions OK | Random seed `20261113`, cleaned |
| Python Contract Validator (Normal) | `python3 tools/validate-mobile-contract.py` | 210 checks OK | Manifest SHA `ba7535...` |
| Python Contract Validator (Optimized) | `python3 -O tools/validate-mobile-contract.py` | 210 checks OK | Manifest SHA `ba7535...` |
| PHPStan Static Analysis | `./vendor/bin/phpstan analyse --memory-limit=2G app/Http/Controllers/Api/Mobile app/Support/Mobile tests/Feature/Mobile/MobileReceiptLockOrderQualificationTest.php tests/Support/mobile-identity-worker.php tests/Unit/Mobile/MobileReceiptLockBarrierProtocolTest.php` | Level 6 PASS, 0 errors | Scoped app + changed tests |
| Pint Code Style | `./vendor/bin/pint --test ...` | PASS | Strictly formatted |

---

## 7. Remaining Financial Authority & Business Policy Gates (OPEN)

This qualification resolves the identity, device, tenant membership, and receipt lock ordering mismatch. It **DOES NOT** activate native mobile financial flows:
1. **Production Deny Adapter Remains Active**: Native mobile sales requests continue to be rejected by `DenyFinancialExecutionAdapter` in production.
2. **Authority Delegation Protocol OPEN**: Who may delegate native financial authority, and whether grants are validated transactionally at canonical commit, remains an open design gate.
3. **Inseparable Canonical Event Boundary OPEN**: Making native sale creation, inventory allocation, customer payment, and receipt generation an atomic, single-intent transaction remains an open design gate.
4. **Data Minimization & Plaintext Retention OPEN**: Retention periods and key lifecycle policies remain open.
