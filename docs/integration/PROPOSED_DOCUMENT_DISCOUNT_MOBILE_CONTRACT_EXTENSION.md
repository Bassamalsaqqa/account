# Proposed Document Discount Mobile Contract Extension

## Overview
This document specifies the proposed paired mobile API contract extension for document-level discounts on Sales Invoices, enabling parity with the web application while preserving the frozen Revision 3 mobile contract unchanged until formal root review and approval.

## Status: PROPOSED (Revision 3 Frozen - Android Discount Gating Remains Active)

Per root supervisor directive, Android clients under Revision 3 continue to disallow discount entry (`unsupported discount predicate` active). This document defines the exact wire representations, validation semantics, and idempotency guarantees for when discount capabilities are reviewed and enabled in a future contract revision (Revision 4).

## 1. Request Wire Representation

In `POST /api/mobile/v2/sales/preview` and `POST /api/mobile/v2/sales/commit`:

### Header Additions (Optional)
```json
{
  "discount_type": "percent", // "none" | "percent" | "fixed" | null
  "discount_value": "10.000000", // Decimal string matching ^\d{1,14}(\.\d{1,6})?$
  "discount_scope": "document", // Default "document"
  "discount_basis": "subtotal",
  "discount_calculation_version": 2
}
```

### Constraints & Invariants
1. **Bounded Intent**: `discount_type` must be one of `"none"`, `"percent"`, `"fixed"`.
2. **Fixed Discount Precision**: If `discount_type` is `"fixed"`, the value must not exceed currency minor units (e.g. 2 decimals for ILS/USD, 3 decimals for JOD).
3. **Percentage Bounds**: If `discount_type` is `"percent"`, value must be `0 <= value <= 100`.
4. **No Double-Discounting**: Header document discount and manual line discounts are mutually exclusive on new documents.
5. **Tax-Inclusive Guard**: If any line item has `tax_inclusive: true`, document-wide discounts fail closed with `tax_inclusive_global_discount_forbidden` until explicit tax apportioning rules are ratified.
6. **Subtotal Cap**: Total allocated discount cannot exceed eligible pre-tax subtotal.

## 2. Server Response & Wire Projection

In `SaleWireResponseProjection`:
```json
{
  "discount_total": "10.000000",
  "discount_type": "percent",
  "discount_value": "10.000000",
  "lines": [
    {
      "line_number": 1,
      "allocated_document_discount": "5.000000",
      "manual_discount_amount": "0.000000",
      "line_discount": "5.000000"
    }
  ]
}
```

## 3. Allocation Algorithm
The server deterministically apportions the document discount across eligible lines using integer Hare-Niemeyer (Largest Remainder) in currency minor units with ascending `line_number` tie-breaking. Minor unit conservation is strictly guaranteed:
$$\sum \text{allocated\_document\_discount}_i = \text{discount\_total}$$
