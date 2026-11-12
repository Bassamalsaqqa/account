# Mobile V2 Online Base Currency Cash Qualification & Design Proposal

**Document Version**: 1.0.0-PROPOSAL  
**Date**: 2026-11-13  
**Status**: REVIEWABLE DESIGN & QUALIFICATION PROPOSAL (TEST-SAFETY ONLY; NOT FINANCIAL ACCEPTANCE)  
**Base Commit**: `f8a0cf7012c4875bec7320d42e0f8dde58593b52`  
**Foundation Commit**: `17444ea77d5bc126da24ce3a6d5c248648fde37f` (PR94 HEAD)  
**Branch**: `t3/online-cash-qualification-proposal`  
**Mode**: Source-Only Proposal (No Runtime DB / No Financial Adapter Implementation / Production Deny Intact)  

---

## 1. Executive Summary & Architectural Scope

Following the independent P0 test-safety qualification of PR94 (`17444ea77d5bc126da24ce3a6d5c248648fde37f`)—which validated 34/180 disposable DB refusal invariants, 6/55 MariaDB rollback atomicity scenarios, 30/92 receipt identity invariants, and 4/39 absent-row race concurrency tests with clean PHPStan and Pint checks—this document sets forth the concrete, reviewable, smallest-scope proposal for **V2 Online Base Currency Cash** preparation.

### 1.1 Strict Guardrails & Non-Negotiable Invariants
1. **Source-Only Proposal**: This task implements NO financial execution adapter, NO financial API routes, NO Spatie Grant migrations, and NO authentication UI changes.
2. **Production Deny Intact**: In `app/Providers/AppServiceProvider.php`, `FinancialExecutionAdapterInterface` remains strictly bound to `DenyFinancialExecutionAdapter`.
3. **V2-OFF Boundary Unaltered**: `config('mobile.v2_enabled')` defaults to `false`. All `/api/mobile/v2/*` endpoints return HTTP 404 `MOBILE_API_DISABLED` in production.
4. **No Offline Scope**: Offline provisional stock, background retry queues, and device gate revocation workers remain strictly separate architectural concerns.
5. **Base Currency Cash Only**: The first release is constrained exclusively to exact full cash payments in the company base currency (`currency_code === company->base_currency_code`). Unpaid sales, partial payments, credit allowances, and change calculations are explicitly excluded.
6. **Immutable Historical Records**: Historical V1 pilot records (`t3/preserved-v1-read-pilot-e111f83`) remain preserved and immutable. No retroactive evidence modification or cold/warm metric re-qualification is permitted.

---

## 2. Dependency Graph & Repository Lineage

```
                       [PR86 Native Foundation]
                                  │
                                  ▼
                    [PR89: Contract Revision 2]
                    HEAD: cb97c9a7b864b3a40e5dc4fe03c9f168fd5c59e8
                                  │
                                  ▼
                   [PR91: JCS Profile & Decimal]
                    HEAD: f3836ef7aa8a2437e95fedaec3166399a44158b2
                                  │
      ┌───────────────────────────┴───────────────────────────┐
      ▼                                                       ▼
[PR88: Native Auth Slice 2]                      [PR93: Currency Lock Order]
HEAD: 9f73ca8952da6bbfc0eabadbb01e51892ace8bc6   HEAD: 1b8a696feda350bbc2df86a26d05b69fd615ba57
      │                                                       │
      ▼                                                       │  (Independent product picker)
[PR92: V2 Boundary Guard]                                     ├─ [PR87: Picker Hardening]
HEAD: 7b8531bfb932c73c26efee7f1ace6c5ae61fceaf                │  HEAD: 912aa71f557b314837e70138df22615902fc9d7b
      │                                                       │
      ▼                                                       ▼
[PR94: Receipt Identity & Disposable DB]         [Preserved AppServiceProvider]
HEAD: 17444ea77d5bc126da24ce3a6d5c248648fde37f   Both bindings preserved:
      │                                            - CurrencyMinorUnitsResolver (PR93)
      ▼                                            - DenyFinancialExecutionAdapter (PR94)
[THIS PROPOSAL: V2 Online Cash Design]
Foundation: 17444ea77d5bc126da24ce3a6d5c248648fde37f
```

### Lineage Safeguards:
- **Preserved Remote**: `t3/preserved-v1-read-pilot-e111f83` (`e111f83bc740e9590142869b30428eaa220eab6f`) represents reviewed V1 pilot composed source. P0 is not an ancestor of the original pilot; future candidate implementations must explicitly compose follow-ups without altering retroactive evidence.
- **Provider Dual-Binding**: In `app/Providers/AppServiceProvider.php`, both `CurrencyMinorUnitsResolver` (from PR93) and `DenyFinancialExecutionAdapter` (from PR94) must remain concurrently registered in any future integration branch.
- **Android PR20 Documentation**: Provenance closed at `8d05f75`; no re-documentation or specification churn.

---

## 3. Resolution of PR94 Audit Findings

### Item 1: Cross-Subsystem Lock Inversion & Real Concurrency Qualification
#### Current State & Call Graph Conflict
A critical lock inversion exists between mobile authentication and mobile receipt intake:
1. **`AuthController::login` (`app/Http/Controllers/Api/Mobile/V1/AuthController.php#L133-L260`)**:
   - Acquires exclusive row lock on `User`: `User::where('email', ...)->lockForUpdate()->first()`
   - Acquires exclusive row locks on `Company`: `$user->activeCompanies()->where(...)->lockForUpdate()->get()`
   - Acquires exclusive row lock on `MobileDevice`: `MobileDevice::where('device_id', ...)->lockForUpdate()->first()`
   - Inserts/Locks `MobileAccessToken`.
   - **Lock Sequence**: `User` $\rightarrow$ `Company` $\rightarrow$ `Device` $\rightarrow$ `Token`.
2. **`MobileEventReceiptManager::recordOrReplay` (`app/Support/Mobile/Receipt/MobileEventReceiptManager.php#L168-L208`)**:
   - Acquires root canonical lock on `Company`: `Company::whereKey($companyId)->lockForUpdate()->first()`
   - Acquires exclusive row lock on `MobileDevice`: `MobileDevice::where('device_id', ...)->lockForUpdate()->first()`
   - Acquires exclusive row lock on `User`: `User::whereKey($user_id)->lockForUpdate()->first()`
   - Acquires exclusive row lock on `MobileAccessToken`: `MobileAccessToken::where('token_hash', ...)->lockForUpdate()->first()`
   - Acquires membership lock on `company_user`.
   - **Lock Sequence**: `Company` $\rightarrow$ `Device` $\rightarrow$ `User` $\rightarrow$ `Token` $\rightarrow$ `Membership`.
3. **`TenantWriteAccess::assertWritable` (`app/Application/Platform/TenantWriteAccess.php#L43-L44`)**:
   - Acquires `Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail()`
   - Acquires `platform_subscriptions` row lock.
   - All canonical accounting writers (`SalesInvoiceCommands`, `ImmediateSaleCommands`, `ProductCatalogService`, `AccountingPostingService`) invoke `TenantWriteAccess`, mandating that `Company` is ALWAYS the first lock in tenant transactions.

#### The Deadlock Hazard
If Worker A (User logging in via mobile) and Worker B (Cashier submitting a sale event via mobile) run concurrently:
- Worker A locks `User(id=5)`.
- Worker B locks `Company(id=10)`.
- Worker A attempts to lock `Company(id=10)` in `$user->activeCompanies()->lockForUpdate()`, and BLOCKS waiting for Worker B.
- Worker B attempts to lock `User(id=5)` in `User::whereKey(...)->lockForUpdate()`, and BLOCKS waiting for Worker A.
- **Result**: MariaDB InnoDB Deadlock (Error 1213 / SQLSTATE 40001).

#### Analysis of Other Credential/Tenancy Writers
- **`DeviceController::revoke` (`app/Http/Controllers/Api/Mobile/V1/DeviceController.php#L69-L76`)**: Locks `MobileDevice` only. Compatible with `Company -> Device -> User`.
- **Password Updates (`app/Actions/Fortify/UpdateUserPassword.php#L32`)**: Updates `User::password`. Tokens validate `password_epoch` on intake without locking `Company`.
- **Membership Writers (`app/Actions/Company/AddCompanyMemberAction.php`)**: Operates under `TenantWriteAccess`, acquiring `Company` lock first.
- **Spatie Permission Writers (`app/Services/Tenancy/CompanyRoleService.php#L180-L214`)**: Operates under `TenantWriteAccess`, acquiring `Company` lock first, then clearing the Spatie cache via `app(PermissionRegistrar::class)->forgetCachedPermissions()`.

#### Proposed Consistent Lock Graph & Candid Hold
Sorting locks inside `MobileEventReceiptManager` alone is mathematically impossible because `TenantWriteAccess` strictly anchors `Company` as the root lock for all multi-tenant accounting operations. 

Therefore, the global lock hierarchy must be:
$$\mathbf{Company} \longrightarrow \mathbf{Device} \longrightarrow \mathbf{User} \longrightarrow \mathbf{Token} \longrightarrow \mathbf{Membership} \longrightarrow \mathbf{Receipt}$$

**Required Patch to `AuthController::login`**:
- Remove the destructive `lockForUpdate()` call on `$user->activeCompanies()`. During authentication, reading active companies requires no row lock on the `companies` table; membership status can be verified with read consistency or by locking the specific `company_user` pivot row only if mutable.
- If company row locking is required, it must either be deferred until tenant selection or locked in strict global order.
- **Candid Architectural Hold**: Until `AuthController::login` is updated in a reviewed PR, a cross-process concurrency hold is declared. Sequential tests must not be marketed as parallel.
- **Qualification Requirement**: Concurrency qualification must execute two REAL separate MariaDB worker processes (`worker-login.php` and `worker-intake.php`) against disposable port 3342, firing simultaneous login and event submission under barrier synchronization to prove zero 1213 deadlocks under load.

---

### Item 2: Tenant Existence Oracle & Uniform Error Mapping
#### Current Defect & Security Leakage
In `MobileEventReceiptManager.php#L168-L214`:
- If `Company` is missing or inactive: Throws `MobileEventForeignScopeException('Target company not found or inactive.')`.
- If `company_user` membership is missing: Throws `MobileEventForeignScopeException('User does not possess active authorized membership in the requested company.')`.

This distinction creates an **existence oracle**: an authenticated bearer can probe arbitrary `company_id` integers. If the company exists, they receive a membership error; if it does not, they receive a "not found" error.

#### Proposed Normative Specification
1. **Stage 1 (Unlocked Token Authentication)**: Evaluates bearer token hash against `MobileAccessToken`. If invalid, expired, or revoked, returns HTTP 401 `MobileEventAuthenticationException('The Bearer token has been revoked or expired.')`. Zero company database queries occur.
2. **Stage 2 (Unified Tenant Scope Evaluation)**: When the token is valid, evaluate tenant validity and membership inside the root transaction. If the target company does not exist, is inactive, or the user is not an active member, map ALL failure branches to an identical, uniform canonical error:
   ```json
   {
     "code": "UNAUTHORIZED_TENANT_ACCESS",
     "message": "Unauthorized tenant scope.",
     "status": 403
   }
   ```
3. **Evaluation Order**: This uniform check MUST execute BEFORE checking client event hash, receipt existence, actor scope, or device scope on historical receipts, eliminating all side-channel probing.

---

### Item 3: Normative Profile & Context Binding (Strict JCS & Typed Terminal DTO)
#### Current Defect
In `MobileEventReceiptManager.php#L665-L690`, `verifyPayloadContextBinding()` inspects context bindings with `if (isset($target['company_id']))`, making them optional. Furthermore, `buildTerminalCanonicalResult()` uses `FORBIDDEN_CREDENTIAL_SUBSTRINGS` (`['password', 'token_hash', 'secret']`), relying on heuristic keyword guessing.

#### Proposed Normative Specification
1. **Mandatory Normative Profile Binding**: Reuse PR91 `OnlineSaleIntentProfile` (`app/Support/Mobile/Contract/Revision3/OnlineSaleIntentProfile.php`). Context bindings are NOT optional; the normative profile MUST strictly require:
   - `actor_id`: ULID string matching authenticated token user.
   - `client_event_id`: RFC 4122 UUID v4 matching request URL/attribute.
   - `company_id`: ULID string matching active company public ID.
   - `device_id`: UUID string matching authenticated device.
   - `currency`: ISO 4217 uppercase 3-letter code matching company base currency.
   - `currency_minor_units`: Server-qualified integer (0..6) from `CurrencyMinorUnitsResolver`.
   - `warehouse_id`: ULID string of an active company warehouse.
   - `customer_id`: ULID string of an active company customer.
   - `lines`: Non-empty list of ordered line items.
   - `payment`: Exact cash payment instruction.
2. **Elimination of Keyword Guessing via Typed Terminal DTO**:
   Replace string substring checks with an explicit, strictly typed terminal DTO class:
   ```php
   final readonly class TerminalSaleResultDto
   {
       public function __construct(
           public string $status, // 'completed'
           public int $sales_invoice_id,
           public string $sales_invoice_public_id,
           public int $customer_payment_id,
           public string $customer_payment_public_id,
           public ?int $command_receipt_id,
           public string $currency,
           public string $grand_total, // Exact decimal string e.g. "150.000000"
           public string $paid_amount,  // Exact decimal string e.g. "150.000000"
           public int $lines_count
       ) {}
   }
   ```
3. **Protocol & Version Distinctions**:
   - Contract Revision: Revision 3.
   - API Route: `/api/mobile/v2/events/sale`.
   - Event Version: 2 (`event_version === 2`).
   - Representation: `v2_online_sale_intent`.
   - Legacy V1 Admission: Explicitly rejected with HTTP 422 (`Legacy v1 event admission is unsupported.`).
   - Original canonical bytes: Preserved byte-for-byte in `longblob` column `original_canonical_bytes`.

---

### Item 4: Explicit Financial Authority & Server-Side Capabilities
#### Core Principle: Bearer Identity $\neq$ Financial Authority
Authenticating a bearer token proves that the hardware device and user account are valid. It **does NOT confer ambient authority to commit financial transactions**.

#### Required Spatie Canonical Capabilities
No custom or invented permissions (`identity.verify`, `identity.probe`) are permitted. Financial execution requires real, canonical permissions defined in `CompanyRoleService::PERMISSIONS`:
1. `sales.invoice.create`: Authority to initialize a sales invoice draft.
2. `sales.invoice.post`: Authority to number, deduct inventory, and post GL entries.
3. `money.receipt.create`: Authority to create a customer payment voucher.
4. `money.receipt.allocate`: Authority to bind customer payment against sales invoice.

#### Transactional Authorization & Cache Lock Threat
- During execution, the system must set Spatie team context (`setPermissionsTeamId($companyId)`) and unset Eloquent cached relations on the User (`$user->unsetRelation('roles')->unsetRelation('permissions')`).
- **Threat Vector**: Spatie's `PermissionRegistrar::forgetCachedPermissions()` flushes process memory and cache tags, but is non-transactional across MariaDB workers. Concurrent role modifications during intake can cause race windows.
- **Decision Rule**: Because authority policies cannot be synthesized without owner approval, the boundary MUST stop at the deny-boundary stub (`DenyFinancialExecutionAdapter`), failing closed with HTTP 403 `FinancialExecutionDeniedException`. No unreviewed `Grant` model migration may be introduced.

---

### Item 5: Native Canonical Domain Actions & Base Currency Cash Policy
#### Browser Guard Decoupling
`CommandSessionGuard` was built specifically for web browser session proofs (verifying HTTP session cookies and session storage tokens). Synthesizing or faking web session state for mobile API calls is strictly prohibited.

#### Atomic Multi-Leg Domain Execution
All financial legs must execute within a SINGLE database transaction (`DB::transactionLevel() === 1`), ensuring zero partial public commitments:

```
[Client Request: POST /api/mobile/v2/events/sale]
                       │
                       ▼
      ┌────────────────────────────────────────────────────────┐
      │ Root DB Transaction Level Guarantee (level === 0)      │
      └────────────────────────┬───────────────────────────────┘
                               │
                               ▼
 1. Canonical Locks: Company -> Device -> User -> Token -> Membership
                               │
                               ▼
 2. Scope & Uniform Tenant Check (Stage 1 & Stage 2)
                               │
                               ▼
 3. Normative Profile Validation (Strict JCS, Context Bindings)
                               │
                               ▼
 4. Existing Receipt Check (Idempotent Replay on hash_equals; 409 on mismatch)
                               │
                               ▼
 5. Leg A: DocumentSequenceService::generateNextNumber (Invoice Number)
                               │
                               ▼
 6. Leg B: CreateSalesInvoiceDraftAction::execute (Invoice Draft)
                               │
                               ▼
 7. Leg C: PostSalesInvoiceAction::execute (Post Invoice + Decrement Stock + GL)
                               │
                               ▼
 8. Leg D: PostCustomerPaymentAction::execute (Post Payment + Cash Ledger + GL)
                               │
                               ▼
 9. Leg E: CustomerPaymentAllocation (Allocate Payment to Invoice)
                               │
                               ▼
10. Leg F: MobileEventReceipt::create (Durable Terminal Receipt Record)
                               │
                               ▼
      ┌────────────────────────────────────────────────────────┐
      │ ROOT COMMIT (Outermost PDO Commit)                    │
      └────────────────────────┬───────────────────────────────┘
                               │
                               ▼
         [Return HTTP 201 Response with Terminal DTO]
```

#### First-Release Cash Business Policy
1. **Base Currency Only**:
   - `currency_code === $company->base_currency_code`
   - `exchange_rate === '1.0000000000'`
   - Rejects any foreign currency transactions at the boundary.
2. **Positive Grand Total & Exact Full Cash**:
   - Stricter than canonical web sales (which allow unpaid drafts): Grand total must be strictly positive (`grand_total > 0`).
   - `payment.amount === invoice.grand_total`.
   - No underpayment (partial), no overpayment credit, and no change calculation.
3. **Explicit NoTax vs Real Zero Tax**:
   - `tax_rate_id: null`: Explicit NoTax line. Requires `tax_rate: "0.000000"` and `tax_inclusive: false`. Omitted key is strictly forbidden.
   - `tax_rate_id: ULID`: Real zero tax rate. Requires active tax rate row with rate `"0.000000"`.
4. **No Global NoPay Claim**: The system makes no claim of supporting unpaid or deferred payment sales on mobile in this release.

---

### Item 6: Concurrency Resilience, Absence-Row Race & Rollback Proof
#### Database Invariants
- Backed by InnoDB table `mobile_event_receipts` with `UNIQUE KEY (company_id, client_event_id)`.
- Client event ID is strictly RFC 4122 UUID v4 (`char(36) ascii_bin`).
- Status is strictly `'completed'`. No intermediate `'processing'` or `'pending'` status is ever written to disk, eliminating phantom processing records.

#### Concurrency & Lost-ACK Resolution
1. **Absent-Row Race Convergence**:
   - Two concurrent worker processes submitting the identical `(company_id, client_event_id)` race to insert.
   - Worker 1 acquires company lock, executes all legs, and commits.
   - Worker 2 catches MariaDB unique constraint violation (SQLSTATE 23000 / Error 1062) or deadlock (Error 1213 / SQLSTATE 40001).
   - Worker 2 retries under bounded backoff (max 5 retries, 10–30ms jitter), reads Worker 1's committed receipt, confirms `hash_equals(payload_digest)`, and returns the identical replay outcome without re-executing business effects.
2. **Lost-ACK Scope Check Before Hash**:
   - On retry, Worker 2 verifies that `actor_id` and `device_id` match the existing receipt BEFORE evaluating `payload_digest`.
   - If a different actor or device attempts to replay the event, it fails with uniform 403 scope error, preventing digest oracle attacks.
3. **Failure Rollback Atomicity (All 6 Points)**:
   Injected faults at ANY stage roll back 100% of mutations:
   - Point 1: Failure during document numbering $\rightarrow$ 0 rows.
   - Point 2: Failure during invoice draft creation $\rightarrow$ 0 rows.
   - Point 3: Failure during stock allocation / decrement $\rightarrow$ 0 rows.
   - Point 4: Failure during GL posting $\rightarrow$ 0 rows.
   - Point 5: Failure during customer payment posting $\rightarrow$ 0 rows.
   - Point 6: Failure immediately after `MobileEventReceipt::create` $\rightarrow$ 0 rows.
   - Verified across all 6 reconciliation services:
     1. `AccountingReconciliationService`
     2. `InventoryReconciliationService`
     3. `SalesReconciliationService`
     4. `MoneyReconciliationService`
     5. `Phase7ReconciliationService`
     6. `PayablesReconciliationService`

---

### Item 7: PR93 Currency Concurrency & Scale Immutability
#### Invariants Established by PR93
1. **Deterministic Locking Order**:
   - Master `Currency` table records are locked via `sharedLock()` in deterministic alphabetical order by `code`. This allows concurrent sales across different companies without global table serialization.
   - Tenant `CompanyCurrency` (`companies_currencies`) is locked exclusively with `lockForUpdate()`.
2. **Exact Minor Units (No Default 2)**:
   - Minor units are resolved via `CurrencyMinorUnitsResolver` (e.g. 0 for JPY, 3 for BHD/KWD, 2 for USD/EUR, 4 for specialized assets). Defaulting to 2 is forbidden.
3. **Scale Immutability**:
   - Historical financial transactions retain their posted precision permanently. Retrospective scale mutations are rejected.
4. **Scope Limitation**:
   - Applies exclusively to sales intake. No claims are made regarding purchasing or AP workflows.
5. **DTO Security Enforcement**:
   - PR91 validation helpers (`OnlineSaleIntentProfile`) validate structure; transactional DTOs enforce runtime security during domain execution.

---

### Item 8: Plaintext Retention, Android Outbox & Data Minimization
#### Unresolved Privacy Policies
1. **Plaintext Receipt Retention**:
   - `mobile_event_receipts.original_canonical_bytes` currently retains raw JSON payloads indefinitely in a `longblob`.
   - Payloads contain customer names, phone numbers, and transactional details.
   - **Open Question**: Owner decision is required to establish a formal data retention and minimization policy (e.g., cryptographic hashing only after a 30-day reconciliation window, or field-level encryption for PII).
2. **Android Client Security Status (NOT False End-to-End Ready)**:
   - The Android client currently stores offline events in a local SQLite database in plaintext.
   - Application manifest lacks explicit backup exclusions (`android:allowBackup="true"` risk).
   - Hardware key invalidation (e.g., user resetting biometric lock or lockscreen PIN) can cause unrecoverable outbox state.
   - The Android subsystem is NOT certified end-to-end ready.
3. **Subsystem Isolation**:
   - Offline provisional stock allocation, auth-blocked retry queues, and device gate revocation remain strictly separate architectures that must not be merged into this online-only proposal.

---

## 4. Phased Implementation Roadmap (Future Bounded Patches)

| Phase | Target Scope | Key Files Touched | Verification / Exit Criteria |
|---|---|---|---|
| **Phase 1** | Auth Lock Inversion & Tenant Oracle Fix | `AuthController.php`, `MobileEventReceiptManager.php` | Two real MariaDB worker processes running concurrent login + receipt intake; uniform 403 on missing/inactive/non-member tenant; 0 deadlocks |
| **Phase 2** | Normative Profile & Typed Terminal DTO | `OnlineSaleIntentProfile.php`, `TerminalSaleResultDto.php` | Mandatory context validation; byte-for-byte JCS verification; strict allowlisted DTO; rejection of legacy v1 |
| **Phase 3** | Canonical Financial Execution Adapter | `CanonicalFinancialExecutionAdapter.php`, `AppServiceProvider.php` | Multi-leg atomic execution (Invoice + Stock + GL + Payment + Allocation + Receipt); production deny remains default |
| **Phase 4** | Full Rollback & Concurrency Qualification | `MobileReceiptConcurrencyTest.php`, `MobileReceiptRollbackAtomicityTest.php` | 6 fault injection points verified on MariaDB port 3342; all 6 reconciliation services pass 100% clean; 0 leftover rows |

---

## 5. Authority & Policy Decision Matrix

| Dimension | Verified Source State (PR94 / Base) | Proposed Implementation | Status / Decision Owner |
|---|---|---|---|
| **V2 API Gate** | Default OFF; returns 404 `MOBILE_API_DISABLED` | Retain default OFF; enabled only in testing harness | **VERIFIED SOURCE** |
| **Financial Adapter** | `DenyFinancialExecutionAdapter` bound in `AppServiceProvider` | Introduce `CanonicalFinancialExecutionAdapter` for test qualification; production stays Deny | **VERIFIED SOURCE** |
| **Auth Lock Ordering** | Inverted (`User -> Company` in login vs `Company -> User` in receipt) | Standardize to `Company -> Device -> User -> Token`; patch `AuthController` | **PROPOSAL (HOLD)** |
| **Tenant Error Leak** | Leaks company existence via distinct error messages | Uniform HTTP 403 `UNAUTHORIZED_TENANT_ACCESS` for missing/inactive/non-member | **PROPOSAL** |
| **Context Bindings** | Optional in receipt manager (`if (isset(...))`) | Mandatory fields in normative profile (`OnlineSaleIntentProfile`) | **PROPOSAL** |
| **Terminal DTO** | Array with heuristic substring credential search | Immutable `TerminalSaleResultDto` with fixed allowlist | **PROPOSAL** |
| **Payment Mode** | Supports arbitrary test payloads | Strict Base Currency Exact Full Cash (`grand_total === paid_amount > 0`) | **PROPOSAL** |
| **Tax Policy** | Supports tax rates | Explicit NoTax (`null`/`"0.000000"`) vs Real Zero (`ULID`/`"0.000000"`) | **PROPOSAL** |
| **Plaintext Retention** | Stores raw canonical bytes indefinitely in `longblob` | Unresolved; needs retention TTL or post-audit redaction | **OPEN (Owner Decision)** |
| **Android Client Storage** | Plain SQLite outbox; unencrypted backup risk | Unresolved; client encryption & keystore lifecycle uncertified | **OPEN (Owner Decision)** |
| **Grant Migrations** | No `Grant` model; Spatie team roles used | No new Grant migrations; strictly canonical Spatie capabilities | **VERIFIED SOURCE** |

---

## 6. Adversarial Qualification Matrix & Exit Criteria

| Scenario | Worker Configuration | Injected Fault / Race Condition | Expected Behavior & Exit Criteria |
|---|---|---|---|
| **Concurrent Login vs Intake** | 2 OS Processes (MariaDB 3342) | Worker 1 logs in while Worker 2 submits sale event for same user/company | Both succeed without deadlock (1213); 0 lock timeout exceptions; valid token issued and valid receipt stored |
| **Absent-Row Event Race** | 2 OS Processes (MariaDB 3342) | Both workers submit exact same `(company_id, client_event_id)` simultaneously | Worker 1 creates sale; Worker 2 catches 1062/1213, backs off, and returns replay; exactly 1 invoice, 1 payment, 1 receipt |
| **Altered Payload Conflict** | 2 OS Processes (MariaDB 3342) | Worker 1 and Worker 2 submit same `client_event_id` with different amounts | Worker 1 commits; Worker 2 catches collision, compares digest, and returns HTTP 409 `MobileEventDuplicatePayloadException` |
| **Fault After Numbering** | 1 Process (Disposable DB) | Exception thrown immediately after `DocumentSequenceService::generateNextNumber` | Root transaction rolls back; 0 invoices, 0 sequence increments leaked, all 6 reconciliations 100% healthy |
| **Fault After Invoice Post** | 1 Process (Disposable DB) | Exception thrown immediately after `PostSalesInvoiceAction` | Root transaction rolls back; 0 invoices, 0 stock movements, 0 posting batches, all 6 reconciliations 100% healthy |
| **Fault After Stock Deduction** | 1 Process (Disposable DB) | Inventory balance constraint violation triggered | Root transaction rolls back; inventory balance restored to opening quantity; 0 stock movements |
| **Fault After Payment Post** | 1 Process (Disposable DB) | Exception thrown immediately after `PostCustomerPaymentAction` | Root transaction rolls back; 0 customer payments, 0 cash ledger entries, 0 GL posting batches |
| **Fault After Receipt Insert** | 1 Process (Disposable DB) | Exception thrown during `MobileEventReceipt::created` model event | Root transaction rolls back; 0 receipts, 0 invoices, 0 payments; all 6 reconciliations clean |

---

## 7. Verification Evidence & Base/Result References

- **Prepared Checkout Base**: `f8a0cf7012c4875bec7320d42e0f8dde58593b52`
- **Foundation Commit**: `17444ea77d5bc126da24ce3a6d5c248648fde37f`
- **P0 Verification Prerequisite**:
  - Refusal Guard: 34 tests, 180 assertions (`docs/evidence/p0-pr94-refusal-proof.json`)
  - Rollback Atomicity: 6 tests, 55 assertions (`MobileReceiptRollbackAtomicityTest.php`)
  - Receipt Identity: 30 tests, 92 assertions (`MobileEventReceiptIdentityTest.php`)
  - Concurrency: 4 tests, 39 assertions (`MobileReceiptConcurrencyTest.php`)
  - Static Analysis: PHPStan Level 6 (0 errors), Laravel Pint (PSR-12 clean)
- **Local Proposal Location**: `docs/integration/mobile-v2-online-cash-proposal.md`
- **Reviewer Status**: Local proposal committed locally for supervisor review prior to publishing or branch merging.
