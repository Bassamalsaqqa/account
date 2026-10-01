# ADR 0003: Inventory Engine, Valuation, and Double-Entry Integration

## Status
Proposed / Pending Acceptance (Phase 3 Correction 02)

## Context
Small Trader Accounting (`التاجر الصغير`) requires an authoritative, audit-proof inventory engine integrated with double-entry accounting. The inventory domain must support moving-average valuation, multi-unit conversions, warehouse transfers, traceable lot tracking with FEFO (First-Expired, First-Out), and exact monetary/quantity precision without float drifts or orphaned state. Shared-hosting compatibility (MariaDB 10.4) and strict multi-tenancy are mandatory.

## Decisions

### 1. Single Truth Store and Movement Semantics
- **Source of Truth:** Immutable `stock_movements` table is the authoritative ledger of all stock events.
- **Materialized Caches:** `inventory_balances` (warehouse level), `inventory_cost_states` (company product level), and `inventory_lot_balances` (warehouse lot level) are strictly derived materialized projections.
- **Canonical Movement Types:**
  - Inbound (positive delta): `opening_balance`, `transfer_in`, `adjustment_increase`.
  - Outbound (negative delta): `transfer_out`, `adjustment_decrease`, `damage_or_loss`, `expiry_disposal`.
- **Paired Warehouse Transfers:**
  - Transfers generate atomic pairs: `transfer_out` at the origin warehouse and `transfer_in` at the destination warehouse.
  - Conserves company-wide product stock: company total quantity, inventory valuation, and moving average cost remain exactly unchanged.
  - Paired transfers are excluded from affecting the company moving average cost state during live processing and deterministic rebuild replay.
- **Immutability:** `StockMovement` rows cannot be updated or deleted (`ImmutableRecordException`).

### 2. Exact Quantity, Cost Precision, and Depletion Policies
- **Precision:**
  - Quantities: `DECIMAL(20, 6)` internal representation.
  - Unit costs and values: `DECIMAL(20, 6)` base-currency valuation.
  - Floats are strictly prohibited throughout authoritative APIs; all calculations use `Brick\Math\BigDecimal` via the `Quantity` value object and `BigDecimal` scaling.
- **Moving Average Valuation:**
  - Inbound movements update company average cost:
    `new_val = old_val + (quantity * unit_cost)`
    `new_avg = new_val / new_qty` (rounded to 6 decimals, `HALF_UP`).
- **Partial Depletion Valuation Policy:**
  - Outbound partial depletion preserves the snapshotted moving average cost:
    `out_val = min(quantity * old_avg, old_val)` (rounded to 6 decimals, `HALF_UP`).
    `value_delta_base = -out_val`
    `new_val = old_val + value_delta_base`
    `new_avg = old_avg`
  - Capping `out_val` at `old_val` guarantees remaining inventory value is strictly non-negative, eliminating negative valuation anomalies on tiny unit costs while keeping the average cost stable across outbound events.
- **Full Depletion Residual Elimination:**
  - When an outbound movement reduces company stock quantity to exactly zero (`new_qty === 0`), calculating movement value as `qty * avg` can leave a residual fraction of a cent due to division rounding (e.g. 1 @ 1.00 + 2 @ 0.00 -> avg 0.333333; 3 * 0.333333 = 0.999999, leaving 0.000001).
  - **Policy:** On full depletion, the movement value delta is assigned the exact negative of the remaining company valuation (`value_delta_base = -old_company_val`). `quantity_base`, `average_cost_base`, and `inventory_value_base` reset cleanly to `0.000000`.
  - This guarantees that the sum of all movement value deltas, the inventory cost state cache, and the double-entry GL inventory control account (1301) reconcile with zero drift.
- **Deterministic Replay & Rebuild:**
  - `InventoryRebuildService` uses immutable movement snapshots (`unit_cost_base` for inbound, `value_delta_base` for outbound) rather than re-evaluating average costs from scratch.
  - This ensures cache rebuild produces identical values to live operations without divergence or rounding drift.
  - Rebuild checks for history corruptions via `InventoryReconciliationService::hasHistoryCorruption()` and strictly refuses to rebuild corrupted movement histories.

### 3. Authoritative Idempotency and Deterministic Child Keys
- **Database Uniqueness Backstop:** `stock_movements` enforces a compound `UNIQUE(company_id, idempotency_key)` constraint.
- **Child Idempotency Keys:**
  - Single-line movements use `$command->idempotencyKey`.
  - Multi-line movements use deterministic child keys: `{$idempotencyKey}:{$index}`.
  - Paired transfers use deterministic child keys: `{$idempotencyKey}:out` / `{$idempotencyKey}:in` (or with index for multi-line transfers).
- **Retry Semantics:**
  - When an idempotency key matches an existing operation, the service performs a deep symmetric comparison of all canonical parameters (company, products, warehouses, movement type, movement date, source type/ID, quantities, unit costs, lots, units, actors, and reasons).
  - Exact matches return the existing movements without re-executing business logic or creating duplicate records.
  - Material discrepancies throw `IdempotencyConflictException`.

### 4. Deterministic Deadlock-Free Concurrency and Lock Order
- To prevent database deadlocks under high concurrency, all transactions acquire locks in a strict global hierarchy:
  1. `Company` row (`lockForUpdate`).
  2. `Product` rows, ordered by `id ASC` (`lockForUpdate`).
  3. `Warehouse` rows, ordered by `id ASC` (`lockForUpdate`).
  4. `InventoryBalance` rows, ordered by `warehouse_id ASC, product_id ASC` (`lockForUpdate`).
  5. `InventoryCostState` rows, ordered by `product_id ASC` (`lockForUpdate`).
  6. `InventoryLot` rows, ordered by `id ASC` (`lockForUpdate`).
  7. `InventoryLotBalance` rows, ordered by `lot_id ASC, warehouse_id ASC` (`lockForUpdate`).
- Negative stock checks are executed under lock for both warehouse balance and company cost state before inserting any movements.

### 5. Traceable Lots, FEFO, and Expiry Policy
- **Traceability:** Products configured with `track_expiry = true` require lots with `received_date` and `expiry_date`.
- **FEFO Allocation Order:**
  - Lots are sorted by `expiry_date ASC` (soonest expiring first).
  - Ties are broken by `received_date ASC`, then `id ASC`.
- **Separation of Concerns:**
  - `allocate()`: Read-only preview without acquiring database row locks or requiring transaction.
  - `allocateWithLock()`: Execution-ready allocation requiring an active database transaction and locking candidate lots and balances under global lock order.
- **Expiry Rules:**
  - Normal outbound movements (sales, issues, transfers) exclude expired lots (`expiry_date < movement_date`).
  - Explicit expiry disposal (`movement_type = expiry_disposal`) requires that the designated lot is actually expired as of the movement date (`expiry_date <= movement_date`).

### 6. Atomic Inventory-GL Unit of Work
- Inventory actions that impact valuation commit stock movements and double-entry general ledger batches atomically within a single database transaction:
  - **Opening Stock:** Dr Merchandise Inventory (1301) / Cr Opening Balance Equity (3101).
  - **Adjustment Increase:** Dr Merchandise Inventory (1301) / Cr Inventory Loss Recovery (5301).
  - **Adjustment Decrease / Damage:** Dr Inventory Discrepancy & Shrinkage Loss (5301) / Cr Merchandise Inventory (1301).
  - **Expiry Disposal:** Dr Expired Goods Loss (5302) / Cr Merchandise Inventory (1301).
- No direct database writes to posting tables; postings are routed exclusively through `AccountingPostingService`.
- Any failure in stock recording or financial posting rolls back both effects atomically.

### 7. Reconciliation and Rebuild Architecture
- **Union-Based Identity Matching:** Reconciles the UNION of identities derived from movements and existing cache records, immediately detecting phantom cache rows (balances without underlying movements).
- **Controlled Rebuild:** Reconstructs exact materialized cache states by replaying immutable movements in exact sequence after validating movement history integrity. Rebuild strictly refuses corrupt movement histories. Phantom balances are zeroed out to maintain historical integrity.
- **System vs. Normal Context:** `rebuildForCompany` and `auditCompany` require an active matching company context in normal mode, or an explicit `fromCli: true` mode for console commands. System mode strictly rejects execution if ANY ambient context is present.
