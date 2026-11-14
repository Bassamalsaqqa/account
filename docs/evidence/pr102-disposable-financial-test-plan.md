# PR #102 Disposable Financial Execution Test Plan

**PR**: [#102](https://github.com/Bassamalsaqqa/account/pull/102) (`t3/recover-cash-writer`)  
**Repository**: `Bassamalsaqqa/account`  
**Document Status**: **SOURCE-ONLY PLAN** (Plan only; zero implementation, zero listeners, zero DB sockets, zero heavy QA, zero financial execution grant).  
**Source Lineage**: Review `b165` on plan baseline commit `2f38528`.  
**Execution Target**: Disposable MariaDB (Port 3342) under exclusive heavy QA lease when explicitly granted.  
**Accepted Core Invariant Base**: `6981511405d7bcd04cd8f8a09e64443ce0aaf47e` (0-byte diff on `app/`, `routes/`, `config/`, `composer.lock`).  
**Client Handoff Schema Digest**: `edaef0309765e10d6462b36b5c660eb02f65c057c810ab72f756f7664e3ca275`.  
**Future Pairing Manifest Target Pins (Recorded Externally to Prevent Self-SHA Cycle)**:
- Backend Target Pin: Evaluated against actual commit SHA at execution time (outside this file)
- Peer Android Publication HEAD: `6bc22542500ecb0dae5ad99246e82982dcef680f`
- Peer Android Executable HEAD: `78ac4437e4010b68408278788dc82eb826fe8014`
- Authorized Scope: Existing **`ROOT_AUTHORIZED_PAIRED_EXECUTION`** (`DisposableMobileV2BackendHarness::SCOPE_PAIRED_EXECUTION`) via existing CLI command flag **`--run`**. Zero invented scopes or flags.

---

## 1. Executive Summary & Review Intent

Following the successful Root-coordinated paired readiness attestation (Run 5 `ooug5prl`, exit code 0, 2 tests PASS), this document specifies the concrete, reviewable, source-first test plan for **disposable native financial execution** on Mobile V2.

In strict adherence to Review `b165` and the Outer Financial Acceptance Criteria:
- **Basic HTTP smoke alone is strictly insufficient for pilot acceptance**.
- Full pilot qualification **must require each required critical scenario to PASS separately**:
  1. Fresh online cash commit (201).
  2. Idempotent exact replay (200).
  3. Status query parity (200 with 6-field official invoice subset).
  4. Client event ID tamper conflict rejection (409).
  5. **Lost ACK Recovery**: Verified response suppression after commit with client reconnection and status reconciliation.
  6. **Atomicity & Rollback**: Named throw-only fault injection verifying Delta 0 against baseline.
  7. **Negative Boundaries**: Tenant mismatch, missing permissions, revoked tokens, insufficient inventory.
- **Scope Qualification Boundary**: Client financial scope acceptance was **NOT** proven by Run 5 readiness JSON (which only proved `READINESS_ONLY` early return). Actual client parser acceptance of the financial lifecycle enum/mapping and external environment must be verified directly before issuing financial commands; basic financial execution is not yet qualified.

---

## 2. Architecture & Existing Primitives Inspection

Inspection of the codebase confirms that all core financial execution primitives, transactional lock sequences, and fault injection stages exist in source:

### 2.1 Existing Execution Primitives
| Component | File Path | Status & Existing Capabilities |
|---|---|---|
| `ExecuteImmediateOnlineCashSaleAction` | `app/Actions/Sales/ExecuteImmediateOnlineCashSaleAction.php` | Atomically executes Leg 1 (draft), Leg 2 (post + numbering + FEFO lot deduction + GL), Leg 3 (payment + numbering + 100% allocation). Locks Company root row (`lockForUpdate`), verifies permissions under lock, enforces strict stock. |
| `QualifiedCashFinancialExecutionAdapter` | `tests/Support/QualifiedCashFinancialExecutionAdapter.php` | Test-only adapter implementing `FinancialExecutionAdapterInterface`. Scopes Spatie permissions to tenant team, asserts granular permissions (`sales.invoice.create`, `sales.invoice.post`, `money.receipt.create`, `money.receipt.allocate`), requires `NativeFinancialExecutionCapability`. Production binding remains `DenyFinancialExecutionAdapter`. |
| Core Named Fault Injection Stages | `ExecuteImmediateOnlineCashSaleAction::$simulatedFaultStage` (lines 56–70) | Throw-only named stage mechanism: `after_draft` (line 414), `after_posting` (line 418), `after_payment` (line 456), `after_composite_completion` (line 467). Strictly throw-only in `testing` environment; no caller code or arbitrary callbacks. *Note: Underlying action supports these stages, but harness CLI wiring `--simulate-fault-stage` is PLANNED, not yet implemented.* |
| Child HTTP Bootstrap Binding | `DisposableMobileV2BackendHarness.php` (lines 964–990) | Child `php -S` process already binds `QualifiedCashFinancialExecutionAdapter::class` upon framework boot when MariaDB port 3342 is verified. When authorized under existing scope `ROOT_AUTHORIZED_PAIRED_EXECUTION`, financial routes flow directly to the Laravel kernel. |

### 2.2 Parent Android Source-Confirmed Financial Mapping
Inspection of Android repository HEAD (`6bc22542500ecb0dae5ad99246e82982dcef680f`, executable `78ac4437e4010b68408278788dc82eb826fe8014`) confirms exact source-level parser requirements:
- **Source Locations**: `CashPilotHandoffConfig.kt:270, 975` and `SmallTraderOnlineCashPilotSmokeTest.kt:244`.
- **Runtime Handoff JSON Fields Required for Financial Branch**:
  - `handoff_scope`: `"ROOT_AUTHORIZED_PAIRED_EXECUTION"` (serialized JSON wire key; maps to internal property `scope` in Kotlin client data classes).
  - `lifecycle_phase`: `"ACTIVE_TLS_READY"`
  - `startup_tls.status`: `"ACTIVE_TLS_READY"` (note: `READINESS_ONLY_VERIFIED` forces client early return before token/bootstrap/financial dispatch).
- **Mandatory External Environment Variables**:
  - `SMALL_TRADER_EXPECTED_LIFECYCLE=FINANCIAL_PAIRED_RUN`
  - `SMALL_TRADER_ROOT_APPROVED_HANDOFF_SHA`: Independent whole-file SHA-256 of live `runtime_client_handoff.json` (must equal `SMALL_TRADER_EXPECTED_HANDOFF_SHA`).
  - `SMALL_TRADER_EXPECTED_CLIENT_SHA`: `6bc22542500ecb0dae5ad99246e82982dcef680f` (publication pin).
  - `SMALL_TRADER_EXPECTED_CLIENT_EXECUTABLE_SHA`: `78ac4437e4010b68408278788dc82eb826fe8014` (executable pin).
  - `SMALL_TRADER_EXPECTED_BACKEND_SHA`: Actual backend execution HEAD commit SHA.
  - `SMALL_TRADER_CASH_PILOT_HANDOFF`: Absolute path to live `runtime_client_handoff.json`.
- **Client Condition `isFinancialApproved`**:
  `scope == ROOT_AUTHORIZED_PAIRED_EXECUTION && !provisionOnly && !readinessOnly && expectedLifecycle == FINANCIAL_PAIRED_RUN && actualFileSHA == rootApprovedSHA`
- The financial execution branch executes **ONLY** after the host TLS GET readiness probe with matching nonce succeeds AND `isFinancialApproved` evaluates to true.

---

## 3. Existing Paired `--run` Unbounded Loop & Planned Finite Deadline

In `MobileV2HarnessCommand::handleRun` ([lines 382–385](tests/Support/Harness/MobileV2HarnessCommand.php#L382-L385)), the existing paired `--run` execution loop is currently unbounded:

```php
while ($running) {
    pcntl_signal_dispatch();
    usleep(500_000);
}
```

### Planned Minimal Test-Only Finite Deadline Extension
To prevent hung processes during paired test execution while preserving existing operational behavior:
1. **Option `--paired-hold-seconds=<int>`**:
   - Automated paired test execution strictly requires an explicit positive integer `1..120` seconds (`--paired-hold-seconds=1..120`).
   - Default `0` is manual unbounded mode for operational sessions (never an approved automated test invocation).
2. **Pre-Framework / Pre-Provision / Pre-Start Signal Registration**:
   - `pcntl_signal` registration for `SIGINT` and `SIGTERM` occurs before kernel DB, provision, or transport start.
   - Safe interrupted setup checks: signal dispatch and check occurs between provision, start, and hold, ensuring the harness does not continue setup after a known abort.
   - If interrupted by signal during setup or hold, the command logs `Paired run ABORTED by interrupt signal <SIG>.` and returns non-zero (`self::FAILURE`), matching the readiness hold abort behavior.
3. **Truthful Progress & Scenario Logging**:
   - Upon finite deadline expiration, the harness logs `Paired run deadline completed (<N>s hold expired).` and returns `self::SUCCESS`.
   - This log message strictly marks deadline completion and is distinct from any financial scenario pass.
4. **Guaranteed Bounded Cleanup**:
   - `finally` block strictly executes `$harness->cleanup()`, dropping the exact owned schema, deleting the temporary directory, and terminating child processes.

---

## 4. What Already Exists vs What Minimal Test-Only Implementation Is Needed

| Area | Already Exists in Codebase | Minimal Test-Only Implementation Needed |
|---|---|---|
| **Domain Logic** | Full 3-leg cash sale execution, FEFO inventory ledger, GL transactions, Company-root row lock. | **None**. Production/core domain code is complete and frozen. |
| **Transport & CLI** | Existing `--run` command and existing scope `ROOT_AUTHORIZED_PAIRED_EXECUTION`. | Add `--paired-hold-seconds=1..120` (positive 1..120 for automated runs, default 0 manual unbounded) with early pre-provision signal registration, safe interrupted setup checks, truthful `Paired run deadline completed (<N>s hold expired).` log, non-zero exit on interrupt, and guaranteed `finally` cleanup to `handleRun`. |
| **Fault Injection** | `ExecuteImmediateOnlineCashSaleAction::$simulatedFaultStage` with 4 throw stages (`after_draft`, `after_posting`, `after_payment`, `after_composite_completion`). | Excluded from minimal deadline patch; remains separate follow-on critical drill. |
| **Lost ACK Control** | Unit-level replay test in `OnlineCashSaleQualificationTest.php`. | **Planned Reviewable Test Proxy Filter**: Harness requires a test proxy control that suppresses the completed client ACK **after independently verifying that the commit transaction succeeded in MariaDB**. Must be **consume-once on the targeted client event ID**, so subsequent recovery replays/status queries succeed without being dropped. |
| **Pure Guard Tests** | 57 pure tests verifying readiness router, pcntl, certs, permissions. | Add pure guard tests asserting bounds (1..120), malformed values, mode combinations, missing pcntl, registration failure, and actual used signal guard path. |

---

## 5. Finite Test Scenarios Specification

### Scenario 1: Minimal Next Concrete Case — Basic Financial Slice
This is the minimal next concrete scenario to qualify before executing lost ACK and rollback drills.

#### Step 1.1: Fresh Online Cash Sale Commit (HTTP 201)
- **Endpoint**: `POST /api/mobile/v2/companies/{companyId}/sales/online-cash`
- **Headers**: `Authorization: Bearer <mobile_access_token>`, `Content-Type: application/json`
- **Payload**: Authoritative synthetic request template matching canonical JCS wire digest:
  - `client_event_id`: Normative version-agnostic UUID layout (`[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}`); fixture generates v4.
  - `device_id`: Enrolled mobile device UUID
  - `customer_id`: Seeded customer ULID
  - `warehouse_id`: Seeded warehouse ULID
  - `currency`: `'ILS'`
  - `lines`: 1 item, quantity `1.000000`, unit price `50.000000`, tax rate `16.000000` (exclusive), line total `58.000000`
  - `payment`: `settlement_mode: 'full'`, `payment_method: 'cash'`, `amount: '58.000000'`
- **Expected Wire Response (10 Keys, HTTP 201)**:
  ```json
  {
    "client_event_id": "c0686be5-888f-4890-90bf-1f05f416a19a",
    "status": "posted",
    "invoice_id": "<ULID>",
    "invoice_number": "INV-...",
    "payment_id": "<ULID>",
    "payment_receipt_number": "REC-...",
    "grand_total": "58.000000",
    "amount_paid": "58.000000",
    "change_returned": "0.000000",
    "posted_at": "<ISO-8601>"
  }
  ```

#### Step 1.2: Idempotent Exact Replay (HTTP 200)
- **Action**: Repeat identical request with same `client_event_id` and wire payload.
- **Expected Response**: HTTP 200 with byte-identical 10-key JSON body.
- **Database Parity Assertion**: Count of `sales_invoices`, `customer_payments`, `customer_payment_allocations`, `stock_movements`, and `mobile_event_receipts` must be identical before and after replay (zero duplication).

#### Step 1.3: Event Status Query (HTTP 200)
- **Endpoint**: `GET /api/mobile/v2/companies/{companyId}/offline-events/{clientEventId}/status`
- **Expected Response (6-field subset in official_invoice, HTTP 200)**:
  ```json
  {
    "client_event_id": "c0686be5-888f-4890-90bf-1f05f416a19a",
    "company_id": "01M7CZPF6TP283F3ZRXF5P8GT2",
    "status": "posted",
    "payload_digest": "sha256:...",
    "official_invoice": {
      "invoice_id": "<ULID>",
      "invoice_number": "INV-...",
      "payment_id": "<ULID>",
      "payment_receipt_number": "REC-...",
      "grand_total": "58.000000",
      "posted_at": "<ISO-8601>"
    },
    "conflict": null,
    "queried_at": "<ISO-8601>"
  }
  ```
- **Parity Assertion**: Assert matching `invoice_id`, `payment_id`, `invoice_number`, `payment_receipt_number`, and `grand_total` between commit DTO and status query DTO.

#### Step 1.4: Tamper Conflict Rejection (HTTP 409)
- **Action**: Submit duplicate `client_event_id` with modified payment amount (`59.000000`) or altered product ID.
- **Expected Response**: HTTP 409 Conflict with error code `CONFLICT`. Cites actual exception `MobileEventDuplicatePayloadException` caught in `OnlineCashSaleController::store()` ([OnlineCashSaleController.php:116-121](app/Http/Controllers/Api/Mobile/V2/OnlineCashSaleController.php#L116-L121)). Zero state modification.

#### Step 1.5: Six Read-Only Reconciliation Services & Methods
Following commit, invoke the 6 canonical read-only reconciliation services CLI / in-process directly against the owned database in context-free / system mode (NOT via HTTP), matching [tests/Support/Phase7/financial-scenarios.php:103-105](tests/Support/Phase7/financial-scenarios.php#L103-L105):
1. `App\Services\Accounting\AccountingReconciliationService::reconcile(Company $company, isSystem: true)`: Verifies double-entry balanced batches and ledger consistency (`isHealthy: true`, `violations: []`).
2. `App\Services\Inventory\InventoryReconciliationService::auditCompany(Company $company, fromCli: true)`: Verifies inventory lot balances, warehouse stock, and reports 0 discrepancies (`isHealthy: true`, `discrepancies: []`).
3. `App\Services\Sales\SalesReconciliationService::reconcile(Company $company, isSystem: true)`: Verifies sales invoice line integrity and payment allocations (`isHealthy: true`, `violations: []`).
4. `App\Services\Purchasing\PayablesReconciliationService::reconcile(Company $company, isSystem: true)`: Verifies purchase ledger remains unaffected (`isHealthy: true`, `violations: []`).
5. `App\Services\Money\MoneyReconciliationService::reconcile(Company $company, isSystem: true)`: Verifies customer payments, receipts, and cash money accounts (`isHealthy: true`, `violations: []`).
6. `App\Services\Accounting\Phase7ReconciliationService::reconcile(Company $company, isSystem: true)`: Verifies multi-ledger integration rules (`isHealthy: true`, `violations: []`).

*Note: Explicitly passing `isSystem: true` on the 5 financial reconciliation services and `fromCli: true` on the inventory audit is mandatory, as both default to `false` and would otherwise omit the required system context.*

---

### Scenario 2: Critical Scenario — Lost ACK Response Suppression Recovery
- **Failure Model**: The backend successfully commits all 3 legs and receipt in MariaDB, but the network disconnects before the HTTP 201 response reaches the client.
- **Harness Control**: Test proxy control intercepts response for targeted `client_event_id`. It verifies that the `MobileEventReceipt` exists in MariaDB with status `posted`, then suppresses the response bytes.
- **Consume-Once Invariant**: The suppression rule triggers exactly once for that `client_event_id` and is immediately consumed.
- **Client Recovery**: Client encounters connection timeout/drop, marks transaction as pending, and issues status query `GET /.../offline-events/{clientEventId}/status` (or idempotent replay POST).
- **Assertions**:
  - Client receives HTTP 200 with matching official invoice/payment public ULIDs.
  - MariaDB verification: Zero duplicate invoices, payments, or receipts created.
- **Qualification Role**: Must pass as an individual critical scenario; separate from basic financial case.

---

### Scenario 3: Critical Scenario — Atomicity & Rollback via Named Throw Stages
- **Failure Model**: Mid-transaction failure occurs during multi-leg financial execution.
- **Harness Control**: Set `--simulate-fault-stage=after_posting` (or `after_payment`).
- **Baseline Invariant**: The seeded MariaDB schema already contains baseline `StockMovement` records from initial inventory lot creation.
- **Verification Assertions**:
  - Endpoint returns HTTP 500 (handled via transaction failure).
  - **StockMovement Delta = 0**: Pre-run and post-rollback `StockMovement` counts and row fingerprints are identical (zero new movements created).
  - Sequential invoice and receipt numbering counters roll back to pre-test baseline.
  - GL posting batches and lines roll back to pre-test baseline.
  - FEFO lot allocations, customer payments, sales invoices, and receipts roll back to pre-test baseline.
  - Zero leaked locks on Company root or inventory balance rows.
- **Qualification Role**: Must pass as an individual critical scenario; separate from basic financial case.

---

### Scenario 4: Negative Boundary Cases & Isolation Safeguards
To ensure that negative permission drills do not contaminate remaining test cases, negative boundary cases run against an **isolated fresh owned fixture / baseline**:

1. **Role Fixture Assignment & Revocation**:
   - The test staff actor canonically receives role `'Sales'` PLUS direct permissions (`$this->staff->assignRole('Sales')` and `$this->staff->givePermissionTo([...])` per [OnlineCashSaleQualificationTest.php:197-206](tests/Feature/Mobile/V2/OnlineCashSaleQualificationTest.php#L197-L206)).
   - Negative permission drills on the isolated fixture must remove the inherited role grant (`removeRole('Sales')`) AND revoke the direct permissions to prevent false positive passes.
2. **Missing Create Permission**: Actor without `sales.invoice.create` -> returns HTTP 403 Forbidden (`AuthorizationException` mapped in [OnlineCashSaleController.php:128-133](app/Http/Controllers/Api/Mobile/V2/OnlineCashSaleController.php#L128-L133)).
3. **Foreign Tenant Access**: Actor attempting commit for foreign company ID -> returns HTTP 403 Forbidden (`AuthorizationException`).
4. **Revoked Device Session**: Mobile device marked inactive or un-enrolled -> returns HTTP 401 Unauthenticated via Sanctum authentication guard.
5. **Negative Stock Prevention**: Requesting quantity exceeding available inventory -> returns HTTP 422 Unprocessable Entity (`InvalidInventoryMovementException` mapped in [OnlineCashSaleController.php:122-127](app/Http/Controllers/Api/Mobile/V2/OnlineCashSaleController.php#L122-L127)).

---

## 6. Concrete Reviewable Minimal Next Patch Scope

The minimal next discrete patch scope to be implemented and verified consists of:

1. **`tests/Support/Harness/MobileV2HarnessCommand.php`**:
   - In `handleRun()`, add option `--paired-hold-seconds=1..120` (explicit positive 1..120 required for automated paired runs, default 0 manual unbounded).
   - Wire pre-framework / pre-provision / pre-start `SIGINT`/`SIGTERM` registration returning non-zero (`self::FAILURE`, `Paired run ABORTED by interrupt signal <SIG>.`), with safe interrupted setup checks between provision, start, and hold.
   - Truthful progress logging: `Paired run deadline completed ({$pairedHoldSeconds}s hold expired).` upon finite deadline expiration, strictly distinct from any financial scenario pass.
   - Guaranteed bounded cleanup in `finally`: `$harness->cleanup()` drops owned schema, removes temp directory, and terminates child processes.
2. **`tests/Support/Harness/mobile-v2-harness-cli.php`**:
   - Parse `--paired-hold-seconds=<int>` option pre-framework, enforce bounds (1..120), enforce mode restriction strictly to `--run`, and verify `pcntl` availability.
3. **`tests/Unit/Mobile/Harness/DisposableMobileV2BackendHarnessGuardTest.php`**:
   - Add pure guard tests verifying option validation (1..120 bounds, malformed input), mode combinations, missing `pcntl`, signal handler registration failure, and actual used signal guard path without starting database or network listeners.
4. **Future Outer Financial Operator Validation Contract (Plan Note)**:
   - Android PR 23 P2 requires fresh `CashPilotEvidenceJSON` containing explicit `clientPublicationHead` and `clientExecutableHead`.
   - The future outer financial validator must independently verify both fresh JSON pins against the Root-approved private publication and executable pins, alongside backend actual HEAD, live handoff SHA, and lifecycle state. Missing, mismatched, or swapped pins strictly deny financial qualification.
5. **Zero Production Changes**: Zero changes to `app/`, `routes/`, `config/`, or `composer.lock`. Core invariance remains 100% byte-identical.
