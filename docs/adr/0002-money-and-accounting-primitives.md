# ADR 0002: Money, FX, and Double-Entry Accounting Primitives

## Status
Accepted (Phase 2)

## Context
Small Trader Accounting (`التاجر الصغير`) requires exact financial math, robust multi-currency transactions, tamper-evident double-entry bookkeeping, and shared-hosting compatibility (MariaDB 10.4). Financial errors, floating-point rounding drifts, silent ledger tampering, or out-of-order mutations are unacceptable.

## Decisions

### 1. Exact Math with `brick/math` (No Floating-Point Arithmetic)
- **Problem:** Native PHP floats (IEEE 754) introduce precision loss (e.g. `0.1 + 0.2 = 0.30000000000000004`), which causes ledger imbalances, tax drift, and rounding discrepancies.
- **Decision:** All financial calculations use `Brick\Math\BigDecimal` via `MoneyAmount` and `ExchangeRate` value objects.
- **Precision:**
  - Monetary amounts: `DECIMAL(20, 6)` internal representation.
  - Exchange rates: `DECIMAL(20, 10)` precision.
- **Validation:** Any `float` or malformed numeric input is rejected immediately with `InvalidMoneyException`.
- **Display:** Display formatting respects currency minor units (ILS/USD 2 decimals, JOD 3 decimals) using `RoundingMode::HALF_UP` without altering internal stored precision.

### 2. Universal Foreign Exchange (FX) Convention
- **Convention:** **Base-currency units per 1 transaction-currency unit**.
  - Example: If the company base currency is ILS and transaction is in USD: Rate is `3.5000000000 ILS/USD`.
  - Conversion to Base: `Base Amount = Transaction Amount * Rate`.
  - Conversion from Base: `Transaction Amount = Base Amount / Rate`.
  - Company base currency rate is always strictly `1.0000000000` (no database rate row needed).
- **Snapshotting:** Posted batches and lines permanently snapshot the exchange rate at posting time; subsequent rate updates never affect historical postings.

### 3. Canonical Double-Entry Posting Service Write Boundary
- **Boundary:** All posting batches and lines must be created exclusively via `App\Services\Posting\AccountingPostingService`.
- **Enforcement:** No controllers, Livewire components, observers, seeders, or helpers may write directly to `posting_batches` or `posting_lines`.
- **Invariants Enforced Before Persistence:**
  - Company context matches.
  - Active poster membership or authorized system path.
  - Transaction currency is enabled; base currency matches company base.
  - At least 2 posting lines with sequential, unique line numbers.
  - Each line has strictly one positive debit or credit (never negative, never both).
  - Exact debit/credit balance equality: `sum(debit_base) === sum(credit_base)`.
  - Every account belongs to the same company and is active.
- **Atomicity:** All batch and line writes execute within a single InnoDB database transaction.

### 4. Ledger Immutability and Reversal
- **Rule:** Posted financial records are immutable. No updates or hard deletes are allowed.
- **Model Protection:** `PostingBatch` and `PostingLine` reject update and delete operations via model boot guards (`ImmutableRecordException`), except for transitioning `status` to `reversed` and setting `reversed_by_batch_id`.
- **Reversal:** Voiding or correcting a transaction is performed through `App\Services\Posting\AccountingReversalService`, which:
  - Locks the original batch (`lockForUpdate`).
  - Creates an inverse batch (Debit becomes Credit, Credit becomes Debit) with deterministic reversal idempotency key.
  - Sets reciprocal references: `reversal_of_id` and `reversed_by_batch_id`.
  - Marks original batch status as `reversed`.
  - Guarantees complete audit trail without data deletion.

### 5. Idempotency and Race Safety
- **Enforcement:** Compound unique index `(company_id, idempotency_key)` on `posting_batches`.
- **Behavior:**
  - If a command with the same idempotency key and matching source semantics arrives, the existing batch is returned without re-posting.
  - If a command with the same key arrives with differing source semantics, an `IdempotencyConflictException` is thrown.
  - Concurrent race attempts are caught by MySQL unique constraint code 1062 and handled cleanly.

### 6. MariaDB 10.4 Compatibility
- Standard DDL types (`BIGINT`, `DECIMAL(20,6)`, `DECIMAL(20,10)`, `VARCHAR(191)`).
- MariaDB-compatible unique constraints: `(company_id, code)` and `(company_id, system_key)` where `NULL` is allowed.
- No partial indexes (e.g. `WHERE system_key IS NOT NULL`).
- No DB ENUMs (string columns with application-level constants).

### 7. Derived Ledger Balances
- **Rule:** No mutable `balance` column exists on `ledger_accounts`.
- **Rationale:** Stored running balance columns are susceptible to concurrency drift, deadlocks, and reconciliation nightmares.
- **Implementation:** `App\Services\Accounting\DerivedLedgerBalanceService` derives exact account balances, period activities, and trial balances directly from canonical posting lines using exact `BigDecimal` sums.

## Consequences
- Guaranteed mathematical correctness with zero float drift.
- Full tamper-evident auditability across all transactions.
- Zero risk of cross-company account contamination.
- Clean foundation ready for Phase 3 inventory and subledger integration.
