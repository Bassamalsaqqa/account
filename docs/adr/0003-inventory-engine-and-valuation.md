# ADR 0003: Inventory Engine, Valuation, and Double-Entry Integration

## Status
Accepted for review branch phase/3-products-inventory

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
- **Immutability and Restrictive Provenance:**
  - `StockMovement` and `InventoryOperation` rows cannot be updated or deleted (`ImmutableRecordException`).
  - `stock_movements.inventory_operation_id` is `NOT NULL` with `ON DELETE RESTRICT`. Operations retain restrictive foreign keys to the authenticated creator.

### 2. Exact Quantity, Cost Precision, and Depletion Policies
- **Precision:**
  - Quantities: `DECIMAL(20, 6)` internal representation.
  - Unit costs and values: `DECIMAL(20, 6)` base-currency valuation.
  - Floats are strictly prohibited throughout authoritative APIs, services, actions, models, and tests; all calculations and sign checks use `Brick\Math\BigDecimal` via the `Quantity` value object and `BigDecimal` comparisons.
- **Moving Average Valuation:**
  - Inbound movements update company average cost:
    `new_val = old_val + (quantity * unit_cost)`
    `new_avg = new_val / new_qty` (rounded to 6 decimals, `HALF_UP`).
- **Valuation Authorization & Adjustment Cost Policy:**
  - Opening stock requires both `inventory.stock.adjust` and `inventory.cost.view` permissions without role bypasses.
  - Explicit unit cost input requires `inventory.cost.view`. Deliberate authorized `0.000000` unit cost is valid and posts no zero-amount GL journal lines.
  - Omitted unit cost on `adjustment_increase` is resolved internally under product cost state locks: if company quantity > 0, the existing moving average is applied; if company stock is zero or uninitialized, explicit cost setup is required.
- **Partial Depletion Valuation Policy:**
  - Outbound partial depletion preserves the snapshotted moving average cost:
    `out_val = min(quantity * old_avg, old_val)` (rounded to 6 decimals, `HALF_UP`).
    `value_delta_base = -out_val`
    `new_val = old_val + value_delta_base`
    `new_avg = old_avg`
  - Capping `out_val` at `old_val` guarantees remaining inventory value is strictly non-negative, eliminating negative valuation anomalies on tiny unit costs while keeping the average cost stable across outbound events.
- **Full Depletion Residual Elimination:**
  - When an outbound movement reduces company stock quantity to exactly zero (`new_qty === 0`), calculating movement value as `qty * avg` can leave a residual fraction of a cent due to division rounding.
  - **Policy:** On full depletion, the movement value delta is assigned the exact negative of the remaining company valuation (`value_delta_base = -old_company_val`). `quantity_base`, `average_cost_base`, and `inventory_value_base` reset cleanly to `0.000000`.
  - This guarantees that the sum of all movement value deltas, the inventory cost state cache, and the double-entry GL inventory control account (1301) reconcile with zero drift.
- **Deterministic Replay & Rebuild:**
  - `InventoryRebuildService` uses immutable movement snapshots (`unit_cost_base` for inbound, `value_delta_base` for outbound) rather than re-evaluating average costs from scratch.
  - Rebuild checks for history corruptions via `InventoryReconciliationService::hasHistoryCorruption()` and strictly refuses to rebuild corrupted movement histories.

### 3. Authoritative Idempotency and Request Fingerprints
- **Database Uniqueness Backstop:** `inventory_operations` enforces `UNIQUE(company_id, idempotency_key)`, and `stock_movements` enforces compound unique keys.
- **Request Fingerprinting:**
  - `inventory_operations` stores `request_hash CHAR(64)` computed via deterministic SHA-256 canonical encoding of caller intent, including explicit unit cost vs omitted/null, line items, and quantities.
  - On retry with matching `request_hash`, existing movements are returned immediately before resolving mutable cost state, preventing drift when subsequent receipts change moving average cost.
  - Conflicting caller intent under the same key throws `IdempotencyConflictException`.
- **Child Idempotency Keys:**
  - Single-line movements use `$command->idempotencyKey`.
  - Multi-line movements use deterministic child keys: `{$idempotencyKey}:{$index}`.
  - Paired transfers use deterministic child keys: `{$idempotencyKey}:out` / `{$idempotencyKey}:in`.

### 4. Deterministic Deadlock-Free Concurrency and Lock Order
- All transactions acquire locks in a strict global hierarchy:
  1. `Company` row (`lockForUpdate`).
  2. `Product` rows, ordered by `id ASC` (`lockForUpdate`).
  3. `Warehouse` rows, ordered by `id ASC` (`lockForUpdate`).
  4. `InventoryBalance` rows, ordered by `warehouse_id ASC, product_id ASC` (`lockForUpdate`).
  5. `InventoryCostState` rows, ordered by `product_id ASC` (`lockForUpdate`).
  6. `InventoryLot` rows, ordered by `id ASC` (`lockForUpdate`).
  7. `InventoryLotBalance` rows, ordered by `lot_id ASC, warehouse_id ASC` (`lockForUpdate`).
- Negative stock checks are executed under lock for both warehouse balance and company cost state before inserting any movements.

### 5. Fail-Closed ProductUnit and Master Data Lifecycle
- **ProductUnit Configuration Invariants:**
  - Every Product requires exactly one `is_base = true` `ProductUnit` row with `conversion_to_base = 1.000000`, matching `products.base_unit_id` and belonging to the same company.
  - Both `firstOrCreate` auto-repair fallbacks are removed from `InventoryMovementService`; corrupt or missing configurations reject operations fail-closed.
- **Safe Base-Unit Changes:**
  - Base unit modifications are allowed only for healthy pristine products with zero stock movements, zero alternate units, zero unit-linked barcodes, and no lots.
  - Before replacing anything, the current declared Unit and ProductUnit configuration is validated (exactly one base row, matching declared base unit ID, active row, active same-company Unit, exact conversion factor 1.000000). A base unit switch fails closed on corrupt existing configuration rather than silently repairing master data.
  - Automatic conversion rebasing is prohibited.
- **Master Data Deactivation Guards:**
  - Active products with company stock quantity > 0 cannot be deactivated.
  - Warehouses with positive inventory balances cannot be deactivated. Default warehouse cannot be deactivated.
  - Units used as base by an active product cannot be deactivated.
  - Warehouse updates enforce that the effective resulting default warehouse must be active; setting an inactive warehouse as default or deactivating an active default is rejected, preserving an active default warehouse.

### 6. Traceable Lots, FEFO, and Expiry Boundary
- **Traceability:** Products configured with `track_expiry = true` require lots with `received_date` and `expiry_date`.
- **FEFO Allocation Order:**
  - Candidate lots are sorted by `expiry_date ASC` (soonest expiring first), then `received_date ASC`, then `id ASC`.
- **Calendar Expiry Boundary:**
  - The expiry date is the **last valid calendar day**.
  - Equal movement date and expiry date (`movement_date === expiry_date`) allows normal issue/allocation and strictly forbids expiry disposal.
  - From the following day (`movement_date > expiry_date`), normal consumption is forbidden and expiry disposal is permitted.
- **Multibyte Character Limits:**
  - Human text limits (movement reason, transfer reason, lot number) count UTF-8 characters via `mb_strlen` rather than raw bytes, matching schema `VARCHAR` limits (512 characters for reasons, 128 characters for lot numbers).

### 7. Atomic Inventory-GL Unit of Work
- Inventory actions that impact valuation commit stock movements and double-entry general ledger batches atomically within a single database transaction:
  - **Opening Stock:** Dr Merchandise Inventory (1301) / Cr Opening Balance Equity (3101).
  - **Adjustment Increase:** Dr Merchandise Inventory (1301) / Cr Inventory Loss Recovery (5301).
  - **Adjustment Decrease / Damage:** Dr Inventory Discrepancy & Shrinkage Loss (5301) / Cr Merchandise Inventory (1301).
  - **Expiry Disposal:** Dr Expired Goods Loss (5302) / Cr Merchandise Inventory (1301).
- No direct database writes to posting tables; postings are routed exclusively through `AccountingPostingService`.
- Any failure in stock recording or financial posting rolls back both effects atomically.

### 8. Reconciliation and Provenance Audit
- Read-only audit via `InventoryReconciliationService` distinguishes history corruptions (broken foreign keys, negative running balances, operation provenance mismatches) from cache discrepancies (derived vs cached balances, GL balance vs inventory valuation, master data configuration inconsistencies).
- Provenance checks verify operation presence, creator match, movement type coherence, line count consistency, and catalog membership (`movement`, `transfer`). Any unknown operation kind is flagged as history corruption, and `InventoryRebuildService` refuses to rebuild until history corruption is resolved.
