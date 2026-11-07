# Durable Purchase Confirmation Replay Safeguard — Recovery Evidence
**Date:** 2026-11-07
**Baseline & Result HEAD:** `06e4f6d71b8dc3202f0708dff91a21b32a5afd33`
**Execution Environment:** MariaDB loopback port 3342, user `test_runner`, disposable test runner `tests/Support/run-phase8-disposable.php`.

---

## 1. Executive Summary

This qualification and hardening cycle resolves the P1 defect identified in `docs/evidence/release-candidate-20261107/huda-independent-current.md`:
- **Defect:** Replaying the original pre-success Livewire snapshot for a new purchase could post a duplicate purchase and payment because form creation was not bound to a pre-validated, durable operation identity before execution.
- **Resolution:** Qualified and finished the existing durable purchase confirmation safeguard (`PurchaseFormConfirmation`, locked `confirmationRequestIdentity` in `PurchaseForm`, table `purchase_confirmation_receipts` via migration `2026_11_07_000005_create_purchase_confirmation_receipts`).
- **Gaps Identified & Fixed:**
  1. Race worker CLI Livewire header: `tests/Support/Purchasing/purchase-confirmation-race-worker.php` threw unhandled `HttpResponseException` when running Livewire updates from CLI without `X-Livewire` header, breaking all concurrency tests. Added `request()->headers->set('X-Livewire', 'true')` after bootstrap.
  2. PHPStan iterable type: `app/Services/Purchasing/PurchaseFormConfirmation.php:41` lacked value type specification on `$confirm` parameter. Fixed docblock to `callable(): ?array{public_id: string, token: string, key: string, digest: string, paid: bool}`.
  3. Failure injection & atomic rollback tests: Added tests in `PurchaseFormSnapshotReplayTest.php` for `confirmPost` caught failures (vendor payment failure injection) and outer receipt insert failure (synthetic query listener), asserting atomic rollback.
  4. Explicit entity assertions: Added explicit assertions across replay tests for exactly one purchase, exactly one purchase number, exactly one stock movement, journal batches (`$paid ? 2 : 1`), exactly one command receipt, exactly one confirmation receipt, and at most one payment (`$paid ? 1 : 0`).
  5. Migration 000005 populated history qualification: Added `test_000005_refuses_down_when_populated_and_retains_restrictive_foreign_keys` to `CorrectionMigrationPreservationTest.php` to assert restrictive foreign keys (`company_id`, `actor_id`, `purchase_id` with `RESTRICT ON DELETE`), `down()` refusal when populated, and clean rollback/re-up when empty.

---

## 2. Exclusive File Scope & Modifications

Only files within the authorized exclusive edit scope were modified or created:
- `app/Services/Purchasing/PurchaseFormConfirmation.php` (docblock typing for PHPStan level 6 compliance)
- `app/Livewire/Pages/Purchasing/PurchaseForm.php` (already wired to `PurchaseFormConfirmation` and locked identity)
- `database/migrations/2026_11_07_000005_create_purchase_confirmation_receipts.php` (provenance table with restrictive foreign keys and nonempty down refusal)
- `tests/Feature/Purchasing/PurchaseFormSnapshotReplayTest.php` (explicit entity assertions, `paidModes` data provider, failure injection tests for caught `confirmPost` and outer receipt insert)
- `tests/Feature/Purchasing/PurchaseFormSnapshotConcurrencyTest.php` (verified 4-mode race tests with MariaDB lock serialization)
- `tests/Feature/Purchasing/CorrectionMigrationPreservationTest.php` (added test for migration 000005 down refusal, FK restrictions, and fingerprint receipt exclusion)
- `tests/Support/Purchasing/purchase-confirmation-race-worker.php` (fixed `X-Livewire` request header)
- `docs/evidence/release-candidate-20261107/recovery-purchase-replay.md` (this report)

No other files or directories were modified. No git operations (staging, commit, push, merge), deployment, or production access occurred.

---

## 3. Test & Verification Evidence

All testing executed through the official disposable runner on port 3342:
`APP_ENV=testing PHASE8_ALLOW_DISPOSABLE_DB=1 PHASE8_TEST_DB_HOST=127.0.0.1 PHASE8_TEST_DB_PORT=3342 PHASE8_TEST_DB_USERNAME=test_runner PHASE8_TEST_DB_EMPTY_PASSWORD=1 php tests/Support/run-phase8-disposable.php -- <targets>`

### A. Focused Suite (Replay, Concurrency, Migration, Settlement, Refinement)
```sh
php tests/Support/run-phase8-disposable.php -- \
  tests/Feature/Purchasing/PurchaseFormSnapshotReplayTest.php \
  tests/Feature/Purchasing/PurchaseFormSnapshotConcurrencyTest.php \
  tests/Feature/Purchasing/CorrectionMigrationPreservationTest.php \
  tests/Feature/Purchasing/ImmediatePurchaseSettlementTest.php \
  tests/Feature/Refinement/HumanWorkflowRefinementTest.php
```
- **Result:** `OK (73 tests, 886 assertions)`
- **Runtime:** `02:07.899`, Memory: `91.00 MB`
- **Primary Schema:** `accounting_p8_tmp_e2f58ac01232` (Owned primary schema cleaned confirmed)
- **Breakdown:**
  - `PurchaseFormSnapshotReplayTest`: 29 tests, 218 assertions PASS (4 modes replay, 4 modes changed economics rejection, 16 modes revoked permission recheck, 1 identity binding test, 2 modes payment failure rollback, 4 modes outer receipt insert failure rollback).
  - `PurchaseFormSnapshotConcurrencyTest`: 4 tests, 90 assertions PASS (concurrent identical snapshot submissions converge without duplicate writes).
  - `CorrectionMigrationPreservationTest`: 9 tests, 307 assertions PASS (migrations 000001, 000002, 000003, 000004, and 000005 populated history preservation and down refusal).
  - `ImmediatePurchaseSettlementTest`: 17 tests, 108 assertions PASS (full cash settlement, atomic rollback, reused key, permission revocation, zero cost).
  - `HumanWorkflowRefinementTest`: 14 tests, 163 assertions PASS (workflow refetch and confirmation).

### B. Extended Purchasing Regressions
```sh
php tests/Support/run-phase8-disposable.php -- \
  tests/Feature/Purchasing/PurchaseCorrectionTest.php \
  tests/Feature/Purchasing/TradeCorrectionTest.php \
  tests/Feature/Purchasing/VoidPurchaseTest.php \
  tests/Feature/Purchasing/VoidPurchaseReturnTest.php \
  tests/Feature/Purchasing/ReleaseReconciliationTest.php
```
- **Result:** `OK (84 tests, 461 assertions)`
- **Runtime:** `01:37.157`, Memory: `91.00 MB`
- **Primary Schema:** `accounting_p8_tmp_0e68a80f6ffe` (Owned primary schema cleaned confirmed)

**Total Test Coverage:** 157 tests, 1,347 assertions, 0 failures, 0 errors, 0 skipped.

---

## 4. Code Quality & Static Analysis Evidence

### A. Pint Style Enforcement
```sh
./vendor/bin/pint --test \
  app/Services/Purchasing/PurchaseFormConfirmation.php \
  app/Livewire/Pages/Purchasing/PurchaseForm.php \
  database/migrations/2026_11_07_000005_create_purchase_confirmation_receipts.php \
  tests/Feature/Purchasing/PurchaseFormSnapshotReplayTest.php \
  tests/Feature/Purchasing/PurchaseFormSnapshotConcurrencyTest.php \
  tests/Feature/Purchasing/CorrectionMigrationPreservationTest.php \
  tests/Support/Purchasing/purchase-confirmation-race-worker.php
```
- **Result:** `{"tool":"pint","result":"passed"}` (Exit code 0).

### B. PHPStan Static Analysis (Level 6)
```sh
./vendor/bin/phpstan analyse \
  app/Services/Purchasing/PurchaseFormConfirmation.php \
  app/Livewire/Pages/Purchasing/PurchaseForm.php
```
- **Result:** `[OK] No errors` (Exit code 0).

---

## 5. Risk Assessment & Safety Boundary

1. **Transaction Atomicity:** `PurchaseFormConfirmation::execute` runs entirely inside a canonical `DB::transaction`. If an outer receipt insert fails or a failure bubbles up, MariaDB rolls back the entire transaction (purchase draft, lines, lots, posting batches, stock movements, vendor payments, allocations, command receipts).
2. **Replay Invariance:** Captured original snapshots re-submitted after response loss match the persisted receipt by request key and payload digest. The replay re-verifies company/actor/source authorization, checks immutable draft constraints, and invokes `ImmediatePurchaseCommands::execute` with identical keys to return the existing command receipt without creating second records.
3. **Foreign Key Integrity:** `purchase_confirmation_receipts` enforces `restrictOnDelete` on `company_id`, `actor_id`, and `purchase_id`, preventing deletion of canonical entities while confirmation provenance exists.
4. **Safety Boundary Adherence:** No git commits, tags, pushes, or deployments were performed. Parent agent retains exclusive ownership of Git and publication.
