# Mobile V1 Settlement Targets and Payment Allocation Contract

**Status**: Reviewed & Authoritative  
**Domain**: Mobile Native Merchant API (V1)  
**Branch**: `t3/opening-trade-balances` (PR 105)  
**Upstream Reference**: PR 104 (`efa257fae488d7ea546b6050bf7e08b47f7f4980`)  

---

## 1. Executive Summary & Architecture Invariants

This contract specifies the mobile backend contract for authoritative outstanding settlement target reads and typed payment allocations across both **Customer** (debt collection / receipt) and **Supplier** (disbursement / voucher) operations.

### Core Architectural Invariants:
1. **Explicit Unallocated Advance Semantics (No Auto-Allocation)**:
   - When the client submits a payment with `allocations` omitted or empty `[]`, the payment commits as an **unallocated advance payment** (`is_advance: true`, `unallocated_amount: amount`, `allocated_amount: "0.00"`, `allocations: []`).
   - Neither the backend nor the mobile client invents automatic FIFO/LIFO allocations. All allocations must be explicitly specified by the merchant or left completely unallocated.
2. **Authoritative Outstanding Reads**:
   - The backend is the single source of truth for open invoices, purchases, and opening trade items.
   - Remaining balances derive strictly from canonical business state via `calculateOutstanding()`.
   - Results are sorted chronologically: `business_date ASC, target_type ASC, target_id ASC`.
3. **Exact Decimal Arithmetic**:
   - Currency display amounts use currency minor units (e.g. 2 decimals for `ILS`, `USD`, `JOD`).
   - Exact economic amounts use arbitrary-precision decimals up to scale 6 (`amount_exact`, `remaining_amount_exact`, `allocated_amount_exact`).
   - Zero binary float math (`Brick\Math\BigDecimal`).
4. **Economic Idempotency & Replay**:
   - All payment POST requests require an `Idempotency-Key` header.
   - Canonical payload includes sorted typed targets and amounts.
   - Exact replays return `200 OK` with header `X-Idempotent-Replay: true` and identical snapshot.
   - Altered payload with same key returns `409 Conflict` (`IDEMPOTENCY_KEY_CONFLICT`).
5. **Server-Side Cost Redaction**:
   - Supplier settlement targets expose purchase costs and balances; access requires `purchasing.cost.view` in addition to `vendors.view`. Staff lacking `purchasing.cost.view` receive `403 Forbidden`.
6. **Strict Tenancy & Permission Scoping**:
   - All operations are strictly scoped to the authenticated merchant company. Cross-tenant, cross-customer, and cross-supplier targets fail closed with validation errors (`404 Not Found` or `422 Validation Failed`).

---

## 2. Route Definitions & Permissions

| Method | Path | Route Name | Required Permission | Cost Redaction |
|---|---|---|---|---|
| `GET` | `/api/mobile/v1/companies/{companyId}/customers/{customerId}/settlement-targets` | `mobile.v1.customers.settlement_targets` | `customers.view` | N/A |
| `GET` | `/api/mobile/v1/companies/{companyId}/customers/{customerId}/outstanding` | `mobile.v1.customers.outstanding` | `customers.view` | N/A (Alias) |
| `POST` | `/api/mobile/v1/companies/{companyId}/customers/{customerId}/payments` | `mobile.v1.customers.payments.store` | `money.receipt.create` | N/A |
| `GET` | `/api/mobile/v1/companies/{companyId}/suppliers/{supplierId}/settlement-targets` | `mobile.v1.suppliers.settlement_targets` | `vendors.view` | Requires `purchasing.cost.view` |
| `GET` | `/api/mobile/v1/companies/{companyId}/suppliers/{supplierId}/outstanding` | `mobile.v1.suppliers.outstanding` | `vendors.view` | Requires `purchasing.cost.view` (Alias) |
| `POST` | `/api/mobile/v1/companies/{companyId}/suppliers/{supplierId}/payments` | `mobile.v1.suppliers.payments.store` | `money.vendor_payment.create` | N/A |

---

## 3. Authoritative Settlement Targets Read Contract

### 3.1 Customer Settlement Targets (`GET /customers/{customerId}/settlement-targets`)

Reads all posted, open sales invoices and active customer opening trade balance items for the customer.

#### Request Headers:
- `Authorization: Bearer <mobile_access_token>` (Mandatory)
- `Accept: application/json`

#### Query Parameters:
- `currency` (Optional, string): Filter targets by ISO-4217 currency code (e.g., `ILS`, `USD`). When omitted, all open targets across all currencies are returned.

#### Response Schema (`200 OK`):
```json
{
  "customer_id": "01JM7XYZ000000000000000001",
  "customer_name": "شركة النجاح للتجارة",
  "currency": "ILS",
  "currency_minor_units": 2,
  "total_targets": 2,
  "targets": [
    {
      "target_type": "opening_trade_item",
      "target_id": "01JM7XYZ000000000000000010",
      "target_number": "OPEN-01JM7XYZ000000000000000010",
      "business_date": "2026-01-15",
      "opening_date": "2026-01-15",
      "due_date": null,
      "days_overdue": 0,
      "currency": "ILS",
      "currency_minor_units": 2,
      "original_amount": "500.00",
      "original_amount_exact": "500.000000",
      "allocated_amount": "0.00",
      "allocated_amount_exact": "0.000000",
      "remaining_amount": "500.00",
      "remaining_amount_exact": "500.000000"
    },
    {
      "target_type": "invoice",
      "target_id": "01JM7XYZ000000000000000020",
      "target_number": "INV-2026-00042",
      "business_date": "2026-02-01",
      "issue_date": "2026-02-01",
      "due_date": "2026-03-01",
      "days_overdue": 0,
      "currency": "ILS",
      "currency_minor_units": 2,
      "original_amount": "750.00",
      "original_amount_exact": "750.000000",
      "allocated_amount": "250.00",
      "allocated_amount_exact": "250.000000",
      "remaining_amount": "500.00",
      "remaining_amount_exact": "500.000000"
    }
  ],
  "totals": {
    "remaining_by_currency": {
      "ILS": {
        "amount": "1000.00",
        "amount_exact": "1000.000000",
        "minor_units": 2
      }
    },
    "original_by_currency": {
      "ILS": {
        "amount": "1250.00",
        "amount_exact": "1250.000000",
        "minor_units": 2
      }
    }
  }
}
```

---

### 3.2 Supplier Settlement Targets (`GET /suppliers/{supplierId}/settlement-targets`)

Reads all posted, open purchase bills and active vendor opening trade balance items for the supplier.

#### Required Permissions & Cost Redaction:
- Merchant token user must possess `vendors.view`.
- Merchant token user must possess `purchasing.cost.view` (evaluated via `VendorFinancialRead::allows`).
- If lacking `purchasing.cost.view`, the endpoint responds with:
  ```json
  {
    "error": {
      "code": "FORBIDDEN",
      "message": "Viewing supplier financial settlement targets requires purchasing cost permission."
    }
  }
  ```

#### Response Schema (`200 OK`):
```json
{
  "supplier_id": "01JM7SUP000000000000000001",
  "supplier_name": "شركة التوريدات العالمية",
  "currency": "ILS",
  "currency_minor_units": 2,
  "total_targets": 2,
  "targets": [
    {
      "target_type": "opening_trade_item",
      "target_id": "01JM7SUP000000000000000010",
      "target_number": "OPEN-01JM7SUP000000000000000010",
      "business_date": "2026-01-20",
      "opening_date": "2026-01-20",
      "due_date": null,
      "days_overdue": 0,
      "currency": "ILS",
      "currency_minor_units": 2,
      "original_amount": "450.00",
      "original_amount_exact": "450.000000",
      "allocated_amount": "0.00",
      "allocated_amount_exact": "0.000000",
      "remaining_amount": "450.00",
      "remaining_amount_exact": "450.000000"
    },
    {
      "target_type": "purchase",
      "target_id": "01JM7SUP000000000000000020",
      "target_number": "BILL-SUP-2026-09",
      "business_date": "2026-03-05",
      "purchase_date": "2026-03-05",
      "due_date": "2026-04-05",
      "days_overdue": 0,
      "currency": "ILS",
      "currency_minor_units": 2,
      "original_amount": "600.00",
      "original_amount_exact": "600.000000",
      "allocated_amount": "0.00",
      "allocated_amount_exact": "0.000000",
      "remaining_amount": "600.00",
      "remaining_amount_exact": "600.000000"
    }
  ],
  "totals": {
    "remaining_by_currency": {
      "ILS": {
        "amount": "1050.00",
        "amount_exact": "1050.000000",
        "minor_units": 2
      }
    },
    "original_by_currency": {
      "ILS": {
        "amount": "1050.00",
        "amount_exact": "1050.000000",
        "minor_units": 2
      }
    }
  }
}
```

---

## 4. Payment Recording & Allocation Contract

### 4.1 Payment Allocation Input Wire Formats

The payment recording endpoints accept payments with:
1. **No Allocations (Unallocated Advance)**: `allocations` key is omitted or passed as `[]`.
2. **Typed Allocations (Recommended Native Contract)**: Each item specifies `target_type`, `target_id`, and `amount`.
3. **Legacy Wire Formats (Backward Compatible)**: `invoice_id` / `bill_id` or direct `opening_trade_item_id`.

#### Supported Target Types:
- Customer: `'invoice'` | `'sales_invoice'` | `'opening_trade_item'` | `'opening_balance'`
- Supplier: `'purchase'` | `'bill'` | `'purchase_bill'` | `'opening_trade_item'` | `'opening_balance'`

#### Request Payload Examples:

##### Case A: Unallocated Advance Payment (Debt Collection / Advance)
```json
{
  "amount": "300.000000",
  "payment_date": "2026-03-15",
  "notes": "دفعة نقدية تحت الحساب"
}
```

##### Case B: Full or Partial Allocation to Invoices and Opening Items
```json
{
  "amount": "500.000000",
  "payment_date": "2026-03-15",
  "reference": "REC-00123",
  "notes": "سداد رصيد افتتاحي وفاتورة مبيعات",
  "allocations": [
    {
      "target_type": "opening_trade_item",
      "target_id": "01JM7XYZ000000000000000010",
      "amount": "200.000000"
    },
    {
      "target_type": "invoice",
      "target_id": "01JM7XYZ000000000000000020",
      "amount": "300.000000"
    }
  ]
}
```

##### Case C: Backward Compatible Legacy Wire Allocation
```json
{
  "amount": "300.000000",
  "allocations": [
    {
      "invoice_id": "01JM7XYZ000000000000000020",
      "amount": "300.000000"
    }
  ]
}
```

---

### 4.2 Response Payload Schema (`201 Created` / `200 OK Replay`)

```json
{
  "success": true,
  "id": "01JM7PAY000000000000000001",
  "receipt_id": "01JM7PAY000000000000000001",
  "payment_number": "PAY-2026-00015",
  "amount": "500.00",
  "amount_exact": "500.000000",
  "currency": "ILS",
  "customer_id": "01JM7XYZ000000000000000001",
  "is_advance": false,
  "allocated_amount": "500.00",
  "allocated_amount_exact": "500.000000",
  "unallocated_amount": "0.00",
  "unallocated_amount_exact": "0.000000",
  "allocations": [
    {
      "target_type": "opening_trade_item",
      "target_id": "01JM7XYZ000000000000000010",
      "amount": "200.00",
      "allocated_amount": "200.00",
      "amount_exact": "200.000000",
      "allocated_amount_exact": "200.000000"
    },
    {
      "target_type": "invoice",
      "target_id": "01JM7XYZ000000000000000020",
      "amount": "300.00",
      "allocated_amount": "300.00",
      "amount_exact": "300.000000",
      "allocated_amount_exact": "300.000000"
    }
  ]
}
```

When unallocated advance is created:
```json
{
  "success": true,
  "id": "01JM7ADV000000000000000001",
  "amount": "300.00",
  "is_advance": true,
  "allocated_amount": "0.00",
  "allocated_amount_exact": "0.000000",
  "unallocated_amount": "300.00",
  "unallocated_amount_exact": "300.000000",
  "allocations": []
}
```

---

## 5. Validation Rules & Rejection Matrix

| Condition | HTTP Status | Error Code | Description / Message |
|---|---|---|---|
| Missing `Idempotency-Key` header | `422` | `MISSING_IDEMPOTENCY_KEY` | Header required for economic writes |
| Missing or negative payment amount | `422` | `VALIDATION_FAILED` | Amount must be positive decimal string |
| Ambiguous allocation target keys | `422` | `VALIDATION_FAILED` | E.g. Specifying both `invoice_id` and `opening_trade_item_id` in same allocation row |
| Conflicting `target_type` and key | `422` | `VALIDATION_FAILED` | E.g. `target_type: 'invoice'` with `opening_trade_item_id` |
| Duplicate allocation target | `422` | `VALIDATION_FAILED` | Same target specified multiple times in `allocations` |
| Allocation exceeds target unpaid balance | `422` | `VALIDATION_FAILED` | Allocation amount exceeds target `calculateOutstanding()` |
| Sum of allocations exceeds payment amount | `422` | `VALIDATION_FAILED` | Sum of `allocated_amount` > payment `amount` |
| Target belongs to different customer/vendor | `404` / `422` | `NOT_FOUND` / `VALIDATION_FAILED` | Target not found or does not belong to party |
| Target in unposted/reversed status | `422` | `VALIDATION_FAILED` | Target must be active and posted |
| Idempotency payload conflict | `409` | `IDEMPOTENCY_KEY_CONFLICT` | Payload changed under existing idempotency key |
| Missing cost view permission on supplier read | `403` | `FORBIDDEN` | Missing required `purchasing.cost.view` |

---

## 6. Stack Integration & Dependency Notes

- **Branch Foundation**: PR 105 (`t3/opening-trade-balances`) is developed on the dedicated branch worktree `/home/t3dev/.t3/worktrees/account/t3-50d1c3c0-c79b8f6ba88d6936`.
- **Baseline State**: Baseline commit `9dfe5828fd2618392405c6e3b5458708cf90d1af`.
- **Relationship to PR 104 (`efa257fae488d7ea546b6050bf7e08b47f7f4980`)**:
  - PR 104 established the core canonical actions and opening trade item models.
  - PR 105 implements the full mobile V1 API integration layer, extending both `CustomerController` and `SupplierController` with typed targets (`opening_trade_item`, `invoice`, `purchase`), unallocated advance semantics, backward-compatible legacy wire hashes, and comprehensive test coverage.
  - This implementation is fully self-contained in branch `t3/opening-trade-balances` without cross-branch cherry-picks or forced rebases.
