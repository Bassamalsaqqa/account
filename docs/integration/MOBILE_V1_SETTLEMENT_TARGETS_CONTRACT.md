# Mobile V1 Settlement Targets & Payment Allocation Contract

> **Status:** Implemented behind default-OFF mobile flags (`MOBILE_API_ENABLED=false`, `MOBILE_MERCHANT_API_ENABLED=false`). Authoritative AR/AP outstanding reads and typed payment allocations (Customer receipts & Supplier disbursements).

## 1. Core Invariants

1. **No Auto-Allocation:** Omitted or empty `allocations` creates an unallocated advance (`is_advance: true`, `unallocated_amount == amount`, `allocated_amount: "0.00"`). Never invents FIFO/LIFO splits.
2. **Authoritative Outstanding & Ordering:** Balances derive from canonical `calculateOutstanding()` sorted `business_date ASC, target_type ASC, target_id ASC`.
3. **Exact Decimal Math:** Display amounts match currency minor units (`2` ILS/USD, `3` JOD); `*_exact` fields preserve scale 6 via `Brick\Math\BigDecimal`.
4. **Economic Idempotency:** Payment `POST` requires `Idempotency-Key`. Identical replays return `200 OK` (`X-Idempotent-Replay: true`); altered payloads return `409 Conflict` (`IDEMPOTENCY_KEY_CONFLICT`).
5. **Cost Redaction & Tenancy:** Supplier reads require `purchasing.cost.view` + `vendors.view` (`403 FORBIDDEN` otherwise). Foreign/cross-party targets fail closed (`404`/`422`).
6. **Bounded Keyset Continuation (`MAX_CANDIDATES_SCAN = 2000`):** If scan budget exhausts across closed history before `limit` open targets are found, response returns `pagination.has_more: true` and `next_cursor`. **An empty/partial `targets` page with `has_more: true` means keep paging via `next_cursor`, NEVER debt-free.**

## 2. Routes & Permissions

| Method | Path | Permission | Cost Redaction |
|---|---|---|---|
| `GET` | `/api/mobile/v1/companies/{companyId}/customers/{customerId}/settlement-targets` (alias `/outstanding`) | `customers.view` | N/A |
| `POST` | `/api/mobile/v1/companies/{companyId}/customers/{customerId}/payments` | `money.receipt.create` | N/A |
| `GET` | `/api/mobile/v1/companies/{companyId}/suppliers/{supplierId}/settlement-targets` (alias `/outstanding`) | `vendors.view` | Requires `purchasing.cost.view` |
| `POST` | `/api/mobile/v1/companies/{companyId}/suppliers/{supplierId}/payments` | `money.vendor_payment.create` | N/A |

## 3. Read Contract (`GET .../settlement-targets`)

- **Query params:** `currency` (optional ISO-4217), `limit` (`1..250`, default `50`; else `422`), `cursor` (opaque HMAC-SHA256 base64url token bound to `company_id`, party ID, and `currency`; tampering/reuse returns `422 VALIDATION_FAILED`).
- **Response (`200 OK`):** Includes `customer_id`/`supplier_id`, name, `currency`, `currency_minor_units`, `total_targets`, `targets[]` (`target_type`, `target_id`, `target_number`, `business_date`, `issue_date`/`purchase_date`/`opening_date`, `due_date`, `days_overdue`, `currency`, `currency_minor_units`, `original_amount[_exact]`, `allocated_amount[_exact]`, `remaining_amount[_exact]`), `pagination` (`limit`, `has_more`, `next_cursor`), and `totals` (`remaining_by_currency`, `original_by_currency`).

## 4. Payment Recording & Alias Rules (`POST .../payments`)

- **Target types:** Customer: `invoice` | `sales_invoice` | `opening_trade_item` | `opening_balance`. Supplier: `purchase` | `bill` | `purchase_bill` | `opening_trade_item` | `opening_balance`. Legacy `invoice_id`/`bill_id`/`opening_trade_item_id` keys accepted when consistent.
- **Strict Validation (`422 VALIDATION_FAILED`):**
  - Conflicting aliases (`target_type` vs `kind`, `target_id` vs `invoice_id`/`bill_id`/`opening_trade_item_id`) fail closed before writes.
  - Normalized duplicate `{$targetType}:{$targetId}` in one payment fails closed.
  - Allocation > unpaid target balance, sum(allocations) > `amount`, non-positive `amount`, missing `Idempotency-Key` (`MISSING_IDEMPOTENCY_KEY`), or unposted/reversed targets fail closed.
- **Response (`201 Created` / `200 OK` Replay):** `success`, `id`, `receipt_id` (customer), `payment_number`, `amount[_exact]`, `currency`, party ID, `is_advance`, `allocated_amount[_exact]`, `unallocated_amount[_exact]`, `allocations[]`.
