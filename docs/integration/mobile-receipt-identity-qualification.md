# Mobile Receipt Identity & Financial Authority Qualification

Baseline: Stacked on PR92 `origin/t3/mobile-v2-boundary-qualification` HEAD `7b8531bfb932c73c26efee7f1ace6c5ae61fceaf` (includes PR88 native auth base `9f73ca8952da6bbfc0eabadbb01e51892ace8bc6`). Prepared checkout baseline `f8a0cf7012c4875bec7320d42e0f8dde58593b52`. Branch `t3/mobile-receipt-identity-qualification`.

---

## 1. Executive Summary & Architectural Scope

This qualification establishes the cross-version durable mobile event receipt identity, absent-row race handling, InnoDB/MariaDB rollback atomicity, and financial authority review for the Small Trader mobile subsystem.

### Key Architectural Invariants & Hardened Guarantees:
1. **Durable Mobile Event Identity & Strict Contract Allowlist**: Backed by `mobile_event_receipts` with a strict `UNIQUE(company_id, client_event_id)` constraint spanning all API versions (v1, v2, v3, and offline events). Client event IDs are strictly validated as RFC 4122 UUIDs (`^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$`). Event versions and representations are strictly allowlisted (`[1, 2, 3]` and `['v1_canonical', 'v2_intent', 'v3_offline_event']`); arbitrary ranges or unknown profiles are denied by default.
2. **Strict Canonical JCS Byte Verification**: Enforces strict JSON parsing via `StrictJsonParser` preventing duplicate keys, trailing characters, invalid escapes, or non-finite numbers. Byte-for-byte canonical JCS serialization (`CanonicalJsonSerializer`) is verified against the incoming bytes before admission, preserving decimal lexemes without normalizations. Generic parser exceptions prevent echoing sensitive payload keys or credentials.
3. **Scope-Before-Digest Security (Zero Oracle)**: Active company and actor scope checks execute *before* payload digest comparison. Foreign actor, device mismatch, or foreign company access returns uniform authentication/scope errors, preventing attackers from probing payload contents or using digest mismatch 409 responses as a cross-tenant oracle. Denies `SupportViewContext` impersonation.
4. **Verified Authenticated Lookup API**: The `lookup` method requires a verified bearer token hash, active device, fresh company membership, and unrevoked password epoch under transaction locks. Lookup rejects raw caller IDs as proof and executes under the same security gate as `recordOrReplay`.
5. **Default-Deny Financial Authority**: Mobile bearer tokens establish staff and device identity *only*. All mobile financial execution remains strictly denied by default via `DenyFinancialExecutionAdapter` (throwing `FinancialExecutionDeniedException`). Financial execution requires explicit, reviewed canonical capability mapping. Per-call executor and permissions overrides are prohibited; tests bind authorized adapters via the container and verify real Spatie permissions against row locks in `model_has_roles` and `model_has_permissions`. Caller passing empty permissions cannot bypass authority.
6. **Hierarchical Transactional Row Locking**: Enforces consistent lock acquisition order to prevent deadlocks: Company root first, then token, device, user, membership row, and Spatie assignment rows (`company_id` team foreign key). Cache tags and Spatie registrar caches are cleared locally.
7. **Ambient Guard Isolation**: Captures prior guard state without lazy authentication, enforces strict isolation, clears `CompanyContext::clearSessionless()` before and in `finally` blocks, and cleanly restores prior guard state.
8. **Bounded Terminal Results & Restrict On Delete**: `original_canonical_bytes` is stored with binary charset (`longblob`) to prevent UTF-8 charset normalization. Foreign keys to `sales_invoices`, `customer_payments`, and `command_receipts` enforce `ON DELETE RESTRICT` (forbidding `SET NULL`). Terminal canonical results are bounded (<= 64 KiB) and scrubbed of credential artifacts.
9. **InnoDB Concurrency & Deadlock Resilience**: Duplicate error detection is strictly constrained to SQLSTATE 23000, error 1062, and exact constraint `mobile_event_receipts_company_client_event_unique`. Deadlock detection is restricted to error 1213 / SQLSTATE 40001. Retries are disallowed within nested transactions (`$initialLevel > 0`) to prevent corrupted savepoints.
10. **All-Or-Nothing Atomicity & Zero Replay Bypass**: Any failure during invoice numbering, draft creation, stock allocation, payment posting, or receipt record creation rolls back all database mutations cleanly, leaving 0 leftover rows and 100% healthy reconciliations across all six audit services.

---

## 2. Authority & Call Graph Audit

### Existing Command Infrastructure vs Mobile Requirements:
1. **`CommandReceipt`**:
   - `CommandReceipt` provides idempotency for browser command sessions bound to `(company_id, user_id, action, idempotency_token)`.
   - It is inherently coupled to browser web sessions proofed by `CommandSessionGuard`.
   - It lacks client event identity across API versions, device scoping, representation tagging, and raw payload digest matching for mobile protocol revisions.
2. **`ImmediateSaleCommands` & `SalesInvoiceCommands`**:
   - These commands orchestrate sales invoice drafts, document sequencing, posting, and immediate payment allocations.
   - They enforce strict tenant scoping via `CompanyContext` and `TenantWriteAccess`.
   - They rely on `CommandSessionGuard::validateSessionProof()` which verifies active HTTP web session tokens in session storage.
3. **`PostCustomerPaymentAction` & `AccountingPostingService`**:
   - `PostCustomerPaymentAction` requires active transaction locks on Company, Customer, and MoneyAccount, verifying team permissions (`money.receipt.create`).
   - `AccountingPostingService` commits double-entry journal entries into `posting_batches` and `posting_lines`.
4. **Guard Evaluation**:
   - Synthesizing or bypassing `CommandSessionGuard` for mobile requests would compromise browser session guarantees and violate tenancy boundaries. Fake session drivers in tests/workers are removed.
   - Mobile financial execution is decoupled through a dedicated adapter boundary (`FinancialExecutionAdapterInterface`) that enforces canonical domain actions without synthesized web session artifacts.

---

## 3. Financial Authority Review & Capability Mapping Proposal

### Principles:
- Mobile bearer tokens authenticate the device and user identity, but **do not confer ambient financial authority**.
- Mobile requests must be authorized against real canonical Spatie permissions within the company's team scope (`setPermissionsTeamId($companyId)`).
- Invented permissions (e.g. `'identity.verify'`, `'identity.probe'`) are rejected.

### Proposed Capability Mapping for Mobile Financial Operations:
| Mobile Operation | Required Canonical Permissions | Canonical Domain Action |
|---|---|---|
| Immediate Sale (Cash/Bank) | `sales.invoice.create`, `sales.invoice.post`, `money.receipt.create`, `money.receipt.allocate` | `CreateSalesInvoiceDraftAction`, `PostSalesInvoiceAction`, `PostCustomerPaymentAction` |
| Credit Sale (Unpaid) | `sales.invoice.create`, `sales.invoice.post` | `CreateSalesInvoiceDraftAction`, `PostSalesInvoiceAction` |
| Price Override | `sales.invoice.change_price` | Price validation override |
| Discount Application | `sales.invoice.change_discount` | Discount percentage/fixed amount |
| Receipt Collection Only | `money.receipt.create`, `money.receipt.allocate` | `PostCustomerPaymentAction` |

### Default Production Policy:
- In production, `DenyFinancialExecutionAdapter` is bound in `AppServiceProvider`.
- Any attempt to process or replay financial events through `MobileEventReceiptManager` without an authorized, explicit adapter override fails with HTTP 403 `FinancialExecutionDeniedException`.

---

## 4. Forward Migration & Schema Design

### Migration File: `database/migrations/2026_11_12_000001_create_mobile_event_receipts_table.php`

```sql
CREATE TABLE `mobile_event_receipts` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `company_id` bigint unsigned NOT NULL,
    `client_event_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `actor_id` bigint unsigned NOT NULL,
    `device_id` char(36) NOT NULL,
    `event_version` smallint unsigned NOT NULL,
    `representation` varchar(64) NOT NULL,
    `payload_digest` char(64) NOT NULL,
    `original_canonical_bytes` longtext CHARACTER SET binary NULL,
    `sales_invoice_id` bigint unsigned NULL,
    `sales_invoice_public_id` varchar(32) NULL,
    `customer_payment_id` bigint unsigned NULL,
    `customer_payment_public_id` varchar(32) NULL,
    `command_receipt_id` bigint unsigned NULL,
    `status` varchar(32) NOT NULL DEFAULT 'completed',
    `terminal_canonical_result` json NULL,
    `created_at` timestamp NULL,
    `updated_at` timestamp NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `mobile_event_receipts_company_client_event_unique` (`company_id`, `client_event_id`),
    KEY `mobile_event_receipts_company_actor_idx` (`company_id`, `actor_id`),
    KEY `mobile_event_receipts_company_device_idx` (`company_id`, `device_id`),
    KEY `mobile_event_receipts_company_invoice_idx` (`company_id`, `sales_invoice_id`),
    KEY `mobile_event_receipts_company_payment_idx` (`company_id`, `customer_payment_id`),
    CONSTRAINT `mobile_event_receipts_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `mobile_event_receipts_actor_id_foreign` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `mobile_event_receipts_device_id_foreign` FOREIGN KEY (`device_id`) REFERENCES `mobile_devices` (`device_id`) ON DELETE RESTRICT,
    CONSTRAINT `mobile_event_receipts_sales_invoice_id_foreign` FOREIGN KEY (`sales_invoice_id`) REFERENCES `sales_invoices` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `mobile_event_receipts_customer_payment_id_foreign` FOREIGN KEY (`customer_payment_id`) REFERENCES `customer_payments` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `mobile_event_receipts_command_receipt_id_foreign` FOREIGN KEY (`command_receipt_id`) REFERENCES `command_receipts` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## 5. Concurrency, Race Handling & Rollback Atomicity Evidence

### Concurrency Mechanics:
1. **Absent-Row Race Convergence**:
   - Two concurrent worker processes racing to commit the exact same `(company_id, client_event_id)` both reach the database without fake session guard bypasses.
   - Transaction A locks the company and commits the invoice, payment, and receipt.
   - Transaction B attempts insertion, catching either exact unique constraint violation (1062) or InnoDB deadlock (1213 / `DeadlockException`), backs off with jitter, re-reads the receipt under lock, and returns the identical replay outcome.
   - Verified in `tests/Feature/Mobile/MobileReceiptConcurrencyTest.php::test_concurrent_workers_same_company_same_event_converge_to_single_sale`: exactly 1 invoice created, 1 payment created, 1 receipt created, 0 duplicates.
2. **Altered Payload Conflict**:
   - Two concurrent worker processes submitting identical `(company_id, client_event_id)` but different payloads.
   - First process commits; second process catches the collision, compares digests via `hash_equals()`, and throws HTTP 409 `MobileEventDuplicatePayloadException`.
   - Scope checks execute prior to digest comparison, preventing oracle vulnerabilities.
   - Verified in `test_concurrent_workers_same_company_same_event_different_payload_produces_conflict_409`.
3. **Cross-Tenant Independence**:
   - Two concurrent worker processes submitting the identical `client_event_id` for two different companies (`TenantA` and `TenantB`).
   - Both proceed concurrently and succeed independently, inserting exactly 2 receipts into the database.
   - Verified in `test_concurrent_workers_same_event_different_companies_succeed_independently`.
4. **Fault Rollback Atomicity**:
   - Five distinct fault injection points tested in `tests/Feature/Mobile/MobileReceiptRollbackAtomicityTest.php`:
     - Numbering service failure: 0 rows written.
     - Invoice draft failure: 0 rows written.
     - Stock decrement failure: 0 rows written.
     - Customer payment failure: 0 rows written.
     - Receipt record creation failure (hooked on `created` event inside transaction): 0 rows written.
   - Full canonical post test verifies that all 6 reconciliation services report clean, healthy states with 0 violations:
     - `accounting:reconcile`
     - `inventory:reconcile`
     - `sales:reconcile`
     - `money:reconcile`
     - `phase7:reconcile`
     - `PayablesReconciliationService`

---

## 6. Verification & Test Suite Summary

Executed on isolated disposable MariaDB schemas (`tests/Support/run-phase8-disposable.php` on port 3342):

| Test Suite | Tests | Assertions | Result | Notes |
|---|---|---|---|---|
| `MobileEventReceiptIdentityTest` | 21 | 70 | PASS | Strict JSON, byte JCS verification, UUID client_event_id, version allowlist, scope-before-digest, verified lookup, default-deny replay/lookup, row locking |
| `MobileReceiptRollbackAtomicityTest` | 6 | 55 | PASS | 5 fault injections (including fault after MobileEventReceipt::create) with zero leftover state + full canonical post with all 6 reconciliations verified |
| `MobileReceiptConcurrencyTest` | 4 | 39 | PASS | 2-worker race convergence, altered payload 409 conflict, cross-tenant concurrent independence, no fake session bypass |
| `MobileReceiptMigrationPreservationTest` | 1 | 20 | PASS | Forward/rollback roundtrip preserves business fingerprint and all 6 reconciliations |
| `All Mobile Feature Tests` | 131 | 1095 | PASS | Full regression pass across all native mobile feature tests |
| `Mobile Contract Validator` | 210 | 210 | PASS | Python OpenAPI 3.1 & JSON Schema Draft 2020-12 validation (`tools/validate-mobile-contract.py`) |
| `PHPStan Static Analysis` | - | 0 errors | PASS | `./vendor/bin/phpstan analyse --level=8` |
| `Laravel Pint Code Style` | - | - | PASS | `./vendor/bin/pint --test` |
