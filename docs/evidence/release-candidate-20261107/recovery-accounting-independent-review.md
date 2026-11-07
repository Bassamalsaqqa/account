# Independent Accounting Source Review — 2026-11-07

**Review Sub-Agent:** Independent Source Review (gemini-3.8-flash, T3 Code Antigravity harness)
**Baseline & Target SHA:** `06e4f6d71b8dc3202f0708dff91a21b32a5afd33`
**Repository Working Directory:** `/home/t3dev/.t3/projects/small-trader-platform/account`
**Candidate State:** Preserved extensive dirty candidate on `main`
**Review Type:** Static Source Code, DDL, Architecture & Boundary Review (READ-ONLY)
**Subprocess Execution:** None (No tests, builds, static analysis runners, or browser sessions invoked)

---

## 1. Executive Summary & Disposition

An independent source review was conducted on the candidate purchase confirmation retry and replay safeguard implementation, addressing the P1 defect documented in `docs/evidence/release-candidate-20261107/huda-independent-current.md`:
- **Original P1 Defect:** Replaying the original pre-success Livewire request/snapshot for a new purchase could post a duplicate purchase and vendor payment because ordinary form creation was not bound to a pre-validated, durable operation identity before writes began.
- **Reviewed Remedy:** Implementation of `App\Services\Purchasing\PurchaseFormConfirmation`, locked `confirmationRequestIdentity` on `App\Livewire\Pages\Purchasing\PurchaseForm`, canonical execution in `App\Application\Commands\ImmediatePurchaseCommands`, and durable provenance tracking via database migration `2026_11_07_000005_create_purchase_confirmation_receipts`.

### Disposition
**NO MATERIAL DEFECT FOUND in bounded scope.**
The reviewed source code, migration DDL, transactional boundaries, and authorization contracts correctly and robustly resolve the P1 idempotency defect. All verification criteria specified for this independent review have been satisfied:
1. Stable request identity created and locked before first write.
2. Complete economic and settlement payload digest verification with 409 rejection upon modification.
3. MariaDB row-level company serialization combined with database-level composite unique constraint (`company_id`, `request_key`).
4. Fresh per-request authorization evaluation on both initial execution and replay, invalidating cached roles/permissions.
5. Strict post-execution and payment integrity validation upon replay via `ImmediatePurchaseCommands`.
6. Atomic failure handling: caught execution errors produce zero immutable economic writes, and receipt insertion failures trigger complete transaction rollback.
7. Exact string decimal representations and Brick\Math BigDecimal arithmetic.
8. Migration preservation with restrictive foreign keys (`RESTRICT ON DELETE`) and populated-table down refusal.

*(Note: Prior test metrics reported in `recovery-purchase-replay.md`—157 tests and 1,347 assertions—reflect execution by earlier test workers and delegates, not this sub-agent's execution).*

---

## 2. In-Depth Boundary & Code Analysis

### A. Stable Pre-Write Identity Resolution
- **Source Reference:** `app/Livewire/Pages/Purchasing/PurchaseForm.php:103`, `app/Livewire/Pages/Purchasing/PurchaseForm.php:218`, `app/Services/Purchasing/PurchaseFormConfirmation.php:19-40`.
- **Mechanism:**
  - In `PurchaseForm::mount`, before any draft creation or database mutation:
    ```php
    $this->confirmationRequestIdentity = app(PurchaseFormConfirmation::class)->identity(
        $this->pageCompanyId, auth()->user(), $publicId
    );
    ```
  - The property `$confirmationRequestIdentity` is annotated with Livewire's `#[Locked]`, ensuring cryptographic message authentication (HMAC) by Livewire against client-side tampering.
  - `PurchaseFormConfirmation::identity` bundles `company_id`, `actor_id`, `source` (`$publicId` or `null`), and a newly generated `Str::uuid()`, returning a cryptographically encrypted string via `Crypt::encryptString`.
  - In `PurchaseFormConfirmation::key`, decryption verifies that `company_id`, `actor_id`, and `source` strictly match the authenticated actor and current form scope, and verifies valid UUID structure, throwing HTTP 403 on any tampering or cross-tenant/cross-actor reuse.
  - **Resolution:** When an original pre-success snapshot is replayed (e.g. after network response loss), the replayed snapshot carries the exact original `confirmationRequestIdentity`, mapping directly to the identical persisted receipt.

### B. Changed Economics Rejection
- **Source Reference:** `app/Services/Purchasing/PurchaseFormConfirmation.php:49-54`, `app/Livewire/Pages/Purchasing/PurchaseForm.php:988-1033`.
- **Mechanism:**
  - Every confirmation attempt passes `$this->settlementDigest()` into `PurchaseFormConfirmation::execute`.
  - `settlementDigest()` computes a SHA-256 hash combining:
    1. Normalized line items: `product_id`, `product_unit_id`, `item_description`, `quantity`, `unit_cost`, `discount_type`, `discount_value`, `tax_rate_id`, and lot customizations.
    2. Document metadata: `vendor_id`, `warehouse_id`, `vendor_invoice_number`, `currency_code`, `exchange_rate`, `purchase_date`, `due_date`, `notes`, and `document_locale`.
    3. The complete `$this->settlement` array: payment mode, payment method, money account, payment date, amounts, exchange rate, and check details.
  - On replay, `PurchaseFormConfirmation::execute` verifies:
    ```php
    if ((int) $receipt->actor_id !== (int) $actor->id
        || $receipt->source_public_id !== $source
        || ! hash_equals($receipt->payload_digest, $digest)) {
        throw new HttpException(409, 'Purchase confirmation request changed.');
    }
    ```
  - Any modification to quantities, prices, currency, dates, notes, or payment terms fails `hash_equals` and triggers HTTP 409. In `PurchaseForm::saveAndConfirm`, this exception is caught and surfaces as `$this->previewError = __('commands.form_changed_repreview_required')` with zero database writes.

### C. Concurrency Serialization & Unique Constraints
- **Source Reference:** `app/Services/Purchasing/PurchaseFormConfirmation.php:44-48`, `app/Services/Sales/SalesActorGuard.php:21`, `database/migrations/2026_11_07_000005_create_purchase_confirmation_receipts.php:18`.
- **Mechanism:**
  - **Company-Level Lock:** `PurchaseFormConfirmation::execute` begins an outermost database transaction (`DB::transaction`). Its first action invokes `SalesActorGuard::lockAndAuthorize`, which executes:
    ```php
    Company::whereKey($companyId)->lockForUpdate()->firstOrFail();
    ```
    This serializes concurrent confirmation requests for the same tenant at the InnoDB row lock level.
  - **Receipt Table Unique Key:** Table `purchase_confirmation_receipts` defines:
    ```php
    $table->unique(['company_id', 'request_key'], 'purchase_confirmation_request_key');
    ```
  - **Convergence:** Under simultaneous replay or concurrent double submission:
    - Worker 1 acquires the company lock, verifies no receipt exists, runs `$confirm()`, executes `ImmediatePurchaseCommands::execute`, inserts the receipt, and commits.
    - Worker 2, unblocked upon Worker 1's commit, acquires the company lock and queries `purchase_confirmation_receipts`. It finds the receipt, matches the payload digest, and routes to `ImmediatePurchaseCommands::execute`.
    - `ImmediatePurchaseCommands::execute` locks the company and purchase, finds the existing `CommandReceipt`, validates canonical posted integrity, and returns the existing purchase/payment models without writing new records.

### D. Fresh Authorization & Permission Checks on Replay
- **Source Reference:** `app/Services/Purchasing/PurchaseFormConfirmation.php:44-47`, `app/Services/Sales/SalesActorGuard.php:21-27`, `app/Application/Commands/ImmediatePurchaseCommands.php:200-213`.
- **Mechanism:**
  - Both initial executions and replays invoke `SalesActorGuard::lockAndAuthorize`:
    - Locks `CompanyUser` row (`lockForUpdate()`).
    - Sets permissions team: `setPermissionsTeamId($companyId)`.
    - Resets Spatie Eloquent cached relations: `$actor->unsetRelation('roles')->unsetRelation('permissions')`.
    - Checks required grants: `purchasing.purchase.create` (or `purchasing.purchase.edit_draft`), `purchasing.cost.view`, and `purchasing.purchase.post`.
  - On replay, `ImmediatePurchaseCommands::execute` re-runs `authorize(...)`:
    - Re-evaluates `purchasing.purchase.post` and `purchasing.cost.view`.
    - If payment was included (`$paid = true`), re-evaluates `money.vendor_payment.create` (and `money.check.outgoing.manage` if check payment).
  - If any permission has been revoked since the original request was formulated, replay is immediately halted with an `AuthorizationException` (HTTP 403 Forbidden). Permissions cannot be smuggled via stale session tokens.

### E. Canonical Posted & Payment Integrity on Replay
- **Source Reference:** `app/Services/Purchasing/PurchaseFormConfirmation.php:57-59`, `app/Application/Commands/ImmediatePurchaseCommands.php:117-142`.
- **Mechanism:**
  - On finding an existing confirmation receipt, `PurchaseFormConfirmation` decrypts the original confirmation payload and calls:
    ```php
    $purchase = Purchase::where('company_id', $companyId)->findOrFail($receipt->purchase_id);
    abort_unless($purchase->public_id === $confirmation['public_id'], 409);
    app(ImmediatePurchaseCommands::class)->execute($purchase, $actor, $confirmation['token'], $confirmation['key']);
    ```
  - In `ImmediatePurchaseCommands::execute`:
    - Queries `CommandReceipt` with `lockForUpdate()`.
    - Verifies `hash_equals($receipt->request_hash, $hash)`.
    - If unpaid: calls `PurchasePostingCommandBuilder::validatePosted($locked)`, verifying ledger journal batches, line allocations, and stock movements.
    - If paid: calls `VendorPaymentPostedIntegrityValidator::validate($payment)` (and `CheckHistory::validate($instrument)` if check).
  - Any data corruption or missing posting records causes replay to abort with HTTP 409 rather than silently returning an unverified state.

### F. Caught Confirmation Failure vs. Partial Economic Commit
- **Source Reference:** `app/Livewire/Pages/Purchasing/PurchaseForm.php:352-385`, `app/Livewire/Pages/Purchasing/PurchaseForm.php:883-911`, `app/Services/Purchasing/PurchaseFormConfirmation.php:62-66`.
- **Analysis:**
  - In `PurchaseForm::saveAndConfirmOnce`, draft creation/update (`$this->save(true)`) occurs first. At this stage, the purchase is in `draft` status (no inventory movements, no posting batches, no accounts payable entries).
  - Next, `confirmPost()` invokes `ImmediatePurchaseCommands::execute(...)`.
  - `ImmediatePurchaseCommands::execute` wraps the entire posting, inventory movement, and payment creation inside `TenantWriteAccess::transaction` (`DB::transaction`).
  - If a downstream failure occurs (such as payment processing error, insufficient account balance, or banking failure):
    - The transaction in `ImmediatePurchaseCommands` rolls back entirely. No posting batches, stock movements, vendor payments, or command receipts persist.
    - `confirmPost()` catches `\Throwable $e`, sets `$this->previewError`, and returns `null`.
    - `$this->completedConfirmation` remains `null`.
    - `saveAndConfirmOnce()` returns `null`.
    - In `PurchaseFormConfirmation::execute`, `$confirmation = $confirm()` yields `null`.
    - `PurchaseFormConfirmation` does not insert a receipt and returns `null`.
  - **Verdict:** No immutable economic partial commit can occur. The draft purchase remains an unposted, mutable draft. No orphaned journals or stock movements are created.

### G. Receipt Insertion Failure Rollback
- **Source Reference:** `app/Services/Purchasing/PurchaseFormConfirmation.php:43-78`.
- **Analysis:**
  - `PurchaseFormConfirmation::execute` runs entirely inside `DB::transaction(...)`.
  - If an exception occurs during the insertion of `purchase_confirmation_receipts` (such as a database connection error, disk failure, foreign key violation, or synthetic query failure):
    - The outer `DB::transaction` catches the unhandled exception and initiates a full MariaDB rollback.
    - The draft purchase creation, line items, lots, postings, inventory receipts, payments, and command receipts are rolled back atomically.
    - Zero records persist across all tables.

### H. Exact Decimals, Migration Preservation & Foreign Keys
- **Source Reference:** `database/migrations/2026_11_07_000005_create_purchase_confirmation_receipts.php`, `app/Livewire/Pages/Purchasing/PurchaseForm.php:757-775`.
- **Decimals:**
  - Purchase form input validation asserts that line quantities, unit costs, and discount values are exact strings (`is_string(...)`).
  - Arithmetic calculations are performed using `Brick\Math\BigDecimal` via `PurchaseCalculator`, eliminating IEEE-754 floating-point inaccuracies.
  - MariaDB schema enforces fixed precision (`DECIMAL(18,6)` / `DECIMAL(18,4)`).
- **Migration & Foreign Keys:**
  - Migration `2026_11_07_000005_create_purchase_confirmation_receipts.php` defines:
    - `foreignId('company_id')->constrained()->restrictOnDelete()`
    - `foreignId('actor_id')->constrained('users')->restrictOnDelete()`
    - `foreignId('purchase_id')->constrained()->restrictOnDelete()`
  - In `down()`:
    ```php
    if (DB::table('purchase_confirmation_receipts')->exists()) {
        throw new RuntimeException('Purchase confirmation provenance exists; retain this migration.');
    }
    Schema::dropIfExists('purchase_confirmation_receipts');
    ```
  - This strictly forbids dropping the migration if populated history exists, protecting audit provenance in production while allowing clean rollback/re-up on empty test fixtures.

### I. Existing-Draft Authorization on Replay
- **Source Reference:** `app/Livewire/Pages/Purchasing/PurchaseForm.php:200-209`.
- **Analysis:**
  - Huda's initial review highlighted that replaying an existing-draft confirmation would fail in `authorizeForm()` because the purchase had already transitioned to `posted` status (`assertMutableDraft()` rejection).
  - The reviewed code fixes this directly:
    ```php
    if ($this->publicId !== null && ($this->completedConfirmation['public_id'] ?? null) !== $this->publicId
        && ($this->confirmationRequestIdentity === '' || ! app(PurchaseFormConfirmation::class)->completed(
            (int) $company->id, auth()->user(), $this->publicId, $this->confirmationRequestIdentity
        ))) {
        Purchase::where('company_id', $company->id)->where('public_id', $this->publicId)->firstOrFail()->assertMutableDraft();
    }
    ```
  - When replaying an existing draft with a completed confirmation receipt, `completed()` returns `true`, safely bypassing `assertMutableDraft()`, allowing the replay request to reach `PurchaseFormConfirmation::execute` and return the idempotent result.

---

## 3. Bounded Auxiliary Scan (Corrections & Voids)

A bounded static scan of `App\Services\Corrections\TradeCorrection` and `App\Actions\Purchasing\VoidPurchaseAction` was performed to ensure consistency with the confirmation safeguard:
- `TradeCorrection` follows identical security principles:
  - Fresh permission checks via `SalesActorGuard::lockAndAuthorize` under row locks.
  - Cryptographically encrypted preview tokens with 10-minute expirations and SHA-256 payload digests.
  - Strict document immutability checks (`status === 'posted'`).
  - Restrictive foreign keys on all correction receipt tables (`restrictOnDelete`).
- Void actions enforce reciprocal inventory movements, ledger reversals, and date consistency across all legs.
- No defects or contract mismatches were identified across the correction and void boundaries.

---

## 4. Scope Limitations & Review Boundary

- **Read-Only Inspection:** This review is an independent source and DDL audit. No subprocesses, PHPUnit tests, Pint styling, PHPStan analysis, database queries, HTTP network replays, or browser automations were executed by this reviewer.
- **Test Results Attribution:** The test figures referenced in prior recovery documents (157 tests, 1,347 assertions) were executed and reported by prior delegates. They provide corroborating context but were not relied upon in lieu of direct source verification.
- **Git & Operational Invariance:** No git commands (`add`, `commit`, `push`, `branch`), migration runs, cache purges, server operations, or production connections were performed.
