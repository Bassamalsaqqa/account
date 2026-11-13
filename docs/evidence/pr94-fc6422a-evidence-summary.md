# PR #94 Exact-Head `fc6422a` Supervisor Evidence and Identity Lock Qualification

## 1. Executive Summary & Review Context

This document records:
1. **Source-Controlled Supervisor Evidence Custody** for exact PR #94 foundation head `fc6422a1f245301abd042d15a8867f25fc367de5`, responding to Owner GitHub review `5887358460` and design proposal `0e39f57d1ced8e7310bc54b3bd94483fd3705855`.
2. **Comprehensive Source-Level Lock Hierarchy Analysis** across all authentication, device revocation, password epoch, tenant membership, and receipt intake entrypoints.
3. **Justified Minimum Lock Order Correction** reconciling the confirmed `AuthController::login` vs `MobileEventReceiptManager` lock order mismatch without removing security locks or fabricating session proofs.
4. **Independent OS MariaDB Process Barrier Qualification** proving zero deadlocks, clean convergence, and strict 401/403 semantics under concurrent load on disposable MariaDB 127.0.0.1:3342.
5. **Preservation of Core Invariants**: P0 disposable database ownership guard preserved, historical evidence in `docs/evidence/p0-pr94-refusal-proof.json` preserved intact, frozen V1 HTTPS pilot `e111f83bc740e9590142869b30428eaa220eab6f` unchanged, Python 210 contract validator manifest `ba753523...` unchanged, and production financial execution default-deny adapter strictly maintained.

---

## 2. Supervisor Artifact & Exact-Head `fc6422a` Evidence

The supervisor artifact located at `/home/t3dev/.t3/userdata/antigravity-tmp/pr94-p0-preservation/fc6422a-followup/evidence.json` was read and verified under read-only custody.

### 2.1 Cryptographic Integrity & Log Digests
- **Supervisor Evidence File SHA-256**: `7e8e4c35ffc43cbd04e28fab9e9fe747196ee55a3c068b320a19a4c6b6e187d0`
- **Exact Foundation Head**: `fc6422a1f245301abd042d15a8867f25fc367de5`
- **Supervisor Executor**: Codex supervisor
- **Timestamp**: `2026-11-13T04:58:25.550887+00:00`
- **GitHub Review Reference**: `5887358460` (follow-up to `5886330102`)

| Log File | SHA-256 Digest | Status / Description |
|---|---|---|
| `docs/evidence/fc6422a-followup/pr94-followup-refusal.log` | `d3d422bc09cd34216e60280d74abe7df7d144de999e61986c742aec534424ed5` | 36 refusal tests / 194 assertions, 0 live DB queries |
| `docs/evidence/fc6422a-followup/pr94-followup-reverse.log` | `a0c491a10a9850cccd68fa9538baaf68e1a7d282e190df58594b164f75b1ea14` | 41 receipt tests / 206 assertions in reverse order, schema `accounting_p8_tmp_53e519180ddc` cleaned |
| `docs/evidence/fc6422a-followup/pr94-followup-random.log` | `7b130fc0d8bfef2ee1d0583184f1aea92f349fab79e5250a099d373cb334980c` | 41 receipt tests / 206 assertions (seed `20261113`), schema `accounting_p8_tmp_e7526be4f306` cleaned |
| `docs/evidence/fc6422a-followup/pr94-followup-static.log` | `8de455a1c8b8a2f1f78b87aa6561c182639fa339bbf3a063b1ccf1070135aa73` | Scoped PHPStan level 6 PASS |

Both disposable schemas (`accounting_p8_tmp_53e519180ddc` and `accounting_p8_tmp_e7526be4f306`) were dropped by the runner, and read-only `information_schema` absence was independently verified.

### 2.2 Historical Baseline Preservation
The existing file `docs/evidence/p0-pr94-refusal-proof.json` describes the earlier historical run on commit `17444ea77d5bc126da24ce3a6d5c248648fde37f` (34 tests / 180 assertions). Its byte-for-byte contents are preserved intact as an immutable historical record and are NOT overwritten.

---

## 3. Lock Enumeration Across Actual Source Entrypoints

A full source inspection of lock acquisition call graphs across the codebase established the following resource dependencies:

### 3.1 `AuthController::login`
- **Inputs**: `email`, `password`, `device_id`, `challenge_id`, `nonce`, optional `mfa_code`, optional `recovery_code`.
- **Pre-Transaction Checks**: Challenge verification and atomic single-use consumption (`consumed_at = now() WHERE consumed_at IS NULL`).
- **Locks Acquired (Original)**:
  1. `User::where('email', ...)->lockForUpdate()->first()`
  2. `$user->activeCompanies()->where('companies.status', 'active')->lockForUpdate()->get()` (Implicit joined query locking `company_user` then `companies` in plan order)
  3. `MobileDevice::where('device_id', ...)->lockForUpdate()->first()`
  4. Inserts `MobileAccessToken` with fresh `password_epoch`.

### 3.2 MFA / Recovery Challenge Consumption
- **LockedTwoFactorSessionController**:
  `User::whereKey($sessionId)->lockForUpdate()->firstOrFail()`
  Serializes 2FA recovery code replacements against concurrent logins.

### 3.3 Fortify Password Updates & Mobile Password Epoch
- **Actions**: `UpdateUserPassword`, `ResetUserPassword`.
- **Mechanism**: Updates `User::password`. Invalidation is enforced downstream via `$expectedEpoch = 'epoch-'.substr(hash('sha256', $user->password), 0, 16)`.
- When `expectedEpoch` does not match `$token->password_epoch`, downstream entrypoints reject and revoke the token.

### 3.4 `DeviceController::revoke` and `AuthController::logout`
- **Device Revocation**:
  Locks `MobileDevice::where('device_id', ...)->where('user_id', ...)->lockForUpdate()->first()`.
  Updates `MobileDevice::revoked_at` and bulk-revokes all tokens for that device.
- **Logout**:
  Calls `$token->revoke()`, updating `MobileAccessToken::revoked_at`.

### 3.5 `MobileEventReceiptManager` (`recordOrReplay` and `lookup`)
- **Locks Acquired (Original)**:
  1. `Company::whereKey($companyId)->lockForUpdate()->first()`
  2. `MobileDevice::where('device_id', $token->device_id)->lockForUpdate()->first()`
  3. `User::whereKey($token->user_id)->lockForUpdate()->first()`
  4. `MobileAccessToken::where('token_hash', $tokenHash)->lockForUpdate()->first()`
  5. `DB::table('company_user')->where('company_id', $companyId)->where('user_id', $user->id)->lockForUpdate()->first()`

### 3.6 Tenant Membership & Role Writers
- **`TenantWriteAccess::assertWritable`**:
  Locks `Company::whereKey($companyId)->lockForUpdate()` -> `platform_subscriptions::lockForUpdate()`.
- **`SalesActorGuard::lockAndAuthorize`**:
  Locks `Company::whereKey($companyId)->lockForUpdate()` -> `CompanyUser::where('company_id', ...)->where('user_id', ...)->lockForUpdate()`.
- **`UpdateCompanyMemberRoleAction` & `ToggleCompanyMemberStatusAction`**:
  Locks `Company::whereKey($companyId)->lockForUpdate()` -> `CompanyUser::lockForUpdate()`.

---

## 4. Analysis of Inversions & The Compatible DAG

### 4.1 The Two Observed Inversions
1. **User vs Company Inversion**:
   `AuthController::login` locked `User` then `Company`.
   `MobileEventReceiptManager` locked `Company` then `User`.
2. **User vs Device Inversion**:
   `AuthController::login` locked `User` then `Device`.
   `MobileEventReceiptManager` locked `Device` then `User`.
3. **Company vs CompanyUser Joined Lock Inversion**:
   `$user->activeCompanies()->lockForUpdate()` in `login` executed an `INNER JOIN` where InnoDB locked `company_user` rows *before* `companies` rows, inverting against `MobileEventReceiptManager` and `SalesActorGuard` which lock `companies` *before* `company_user`.

### 4.2 The Qualified Global Lock Hierarchy
To resolve all inversions into a strict Directed Acyclic Graph (DAG) without removing security locks:

```
Level 1: User                 (Principal identity, credentials, password epoch, 2FA)
   ↓
Level 2: Company              (Tenant boundary, locked in deterministic ascending companies.id order)
   ↓
Level 3: MobileDevice         (Hardware binding registered to user)
   ↓
Level 4: MobileAccessToken    (Session credential bound to user and device)
   ↓
Level 5: CompanyUser          (Tenant membership row binding user to company)
   ↓
Level 6: Canonical Entities   (Sales invoices, payments, command receipts)
   ↓
Level 7: MobileEventReceipt   (Terminal receipt claim and audit record)
```

### 4.3 Why This Hierarchy Is Minimally Justified
- **Preserves Multi-Company Login**: `AuthController::login` first locks the authenticating `User`, resolves active memberships, and then locks active `Company` rows in strictly sorted `companies.id ASC` order, followed by `CompanyUser` rows in matching `company_id ASC` order. Logins for users belonging to multiple companies acquire company locks in identical global order, eliminating multi-tenant login deadlocks.
- **Eliminates User/Device Inversion**: `MobileEventReceiptManager` locks `User` before `Company`, and locks `Company` before `Device`. Both `login` and `receipt` now traverse `User -> Company -> Device -> Token`.
- **Zero Impact on Single-Tenant Business Actions**: Actions like `SalesActorGuard` and `TenantWriteAccess` lock `Company -> CompanyUser` (Levels 2 and 5), which are strictly downstream of `User` (Level 1) and maintain identical relative ordering.

---

## 5. Independent OS MariaDB Process Barrier Test Results

The suite `tests/Feature/Mobile/MobileReceiptLockOrderQualificationTest.php` executes four independent two-process barrier race tests. Each worker runs as a separate OS process (`Process`) with its own PHP runtime, memory space, and MariaDB PDO connection pool, synchronizing via temporary file barriers (`.ready` / `go`).

### Test 1: Real Production Login vs Receipt Intake
- **Workers**: Worker A (`AuthController::login`), Worker B (`MobileEventReceiptManager::recordOrReplay`).
- **Fixture**: User belonging to two active companies (`Company A` ILS, `Company B` USD), registered mobile device, valid challenge.
- **Outcome**: Both workers reached the barrier and completed with exit code 0.
- **Worker A**: Status `200`, outcome `logged_in`, issued token, returned 2 companies.
- **Worker B**: Status `200`, outcome `created`, terminal receipt ID created.
- **Database Consistency**: Exactly 1 sales invoice, 1 customer payment, 1 receipt created. Accounting, Sales, Inventory (`auditCompany`), and Money reconciliations all reported `isHealthy === true`.
- **Deadlocks Observed**: 0.

### Test 2: Device Revocation vs Receipt Intake
- **Workers**: Worker A (`DeviceController::revoke`), Worker B (`MobileEventReceiptManager::recordOrReplay`).
- **Outcome**: Exit code 0 on both workers.
- **Convergence Verification**: Worker A always succeeds with `200` (`revoked`). Worker B converges to either:
  - `200` (`created`) if receipt locks were acquired before revocation, OR
  - `401` (`auth_error`, message: `Device has been revoked or does not match token owner.`) if revocation committed before receipt lock acquisition.
- **Negative Invariant**: When revoked first, 0 receipts, 0 invoices, and 0 customer payments were committed. The device is verified revoked in the database.
- **Deadlocks Observed**: 0.

### Test 3: Tenant Membership Deactivation vs Receipt Intake
- **Workers**: Worker A (`ToggleCompanyMemberStatusAction::execute`), Worker B (`MobileEventReceiptManager::recordOrReplay`).
- **Outcome**: Exit code 0 on both workers.
- **Convergence Verification**: Worker A deactivates membership (`new_status: inactive`). Worker B converges to either `200` (`created`) or `403` (`foreign_scope`, message: `User does not possess active authorized membership in the requested company.`).
- **Negative Invariant**: 403 foreign scope semantics preserved; 0 leaked invoices or receipts on deactivation-first outcome.
- **Deadlocks Observed**: 0.

### Test 4: Password Epoch Advance vs Receipt Intake
- **Workers**: Worker A (user password update advancing password epoch), Worker B (`MobileEventReceiptManager::recordOrReplay`).
- **Outcome**: Exit code 0 on both workers.
- **Convergence Verification**: Worker A updates password (`new_epoch`). Worker B converges to either `200` (`created`) or `401` (`auth_error`, message: `User credentials have changed since token establishment.`).
- **Persistence Invariant**: When credentials changed first, the token's `revoked_at` is persisted in the database even though the economic transaction rolled back.
- **Deadlocks Observed**: 0.

---

## 6. Full Suite Regression & Tooling Manifest

| Suite / Check | Command | Count / Status | Notes |
|---|---|---|---|
| Refusal Ownership Guard | `phpunit tests/Unit/Mobile/MobileReceiptTestOwnershipGuardTest.php` | 36 tests, 194 assertions OK | 0 live DB queries |
| Barrier Protocol Unit Tests | `phpunit tests/Unit/Mobile/MobileReceiptLockBarrierProtocolTest.php` | 4 tests, 8 assertions OK | Non-DB barrier protocol |
| Two-Process MariaDB Barrier Races | `run-phase8-disposable.php -- tests/Feature/Mobile/MobileReceiptLockOrderQualificationTest.php` | 4 tests, 43 assertions OK | Fresh schema, cleaned |
| V1 Auth / Device Regressions | `run-phase8-disposable.php -- tests/Feature/Mobile/Auth*.php` | 18 tests, 115 assertions OK | Fresh schema, cleaned |
| 4 Receipt Classes (Reverse) | `run-phase8-disposable.php -- --order-by=reverse tests/Feature/Mobile/Mobile*Receipt*.php` | 41 tests, 206 assertions OK | Fresh schema, cleaned |
| 4 Receipt Classes (Random 20261113) | `run-phase8-disposable.php -- --order-by=random --random-order-seed=20261113 tests/Feature/Mobile/Mobile*Receipt*.php` | 41 tests, 206 assertions OK | Fresh schema, cleaned |
| Python Contract Validator (Normal) | `python3 tools/validate-mobile-contract.py` | 210 checks OK | Manifest SHA `ba7535...` |
| Python Contract Validator (Optimized) | `python3 -O tools/validate-mobile-contract.py` | 210 checks OK | Manifest SHA `ba7535...` |
| PHPStan Static Analysis | `./vendor/bin/phpstan analyse --memory-limit=2G app/Http/Controllers/Api/Mobile app/Support/Mobile tests/Feature/Mobile/MobileReceiptLockOrderQualificationTest.php tests/Support/mobile-identity-worker.php tests/Unit/Mobile/MobileReceiptLockBarrierProtocolTest.php` | Level 6 PASS, 0 errors | Scoped app + changed tests |
| Pint Code Style | `./vendor/bin/pint --test ...` | PASS | Strictly formatted |

---

## 7. Remaining Financial Authority & Business Policy Gates (OPEN)

This qualification resolves the identity, device, and receipt lock ordering mismatch. It **DOES NOT** activate native mobile financial flows:
1. **Production Deny Adapter Remains Active**: Native mobile sales requests continue to be rejected by `DenyFinancialExecutionAdapter` in production.
2. **Authority Delegation Protocol OPEN**: Who may delegate native financial authority, and whether grants are validated transactionally at canonical commit, remains an open design gate.
3. **Inseparable Canonical Event Boundary OPEN**: Making native sale creation, inventory allocation, customer payment, and receipt generation an atomic, single-intent transaction remains an open design gate.
4. **Data Minimization & Plaintext Retention OPEN**: Retention periods and key lifecycle policies remain open.
