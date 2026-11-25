# Proposed Document Discount Mobile Contract Extension

> **STATUS: FUTURE PROPOSAL ONLY — NOT IMPLEMENTED.**
> Web sales and purchasing document discounts are implemented in Laravel UI/domain, **not** in the mobile API. Mobile V1/V2 sales endpoints and Android production V2 checkout do **not** implement document-level discounts (Android production checkout rejects nonzero discounts).

## 1. Proposed Wire Additions (`POST /api/mobile/v2/sales/preview` & `commit`)

Optional header fields reserved for a future ratified Revision 4 contract:
- `discount_type`: `"none" | "percent" | "fixed" | null`
- `discount_value`: exact decimal string matching `^\d{1,14}(\.\d{1,6})?$`
- `discount_scope`: `"document"` (default), `discount_basis`: `"subtotal"`, `discount_calculation_version`: `2`

Proposed constraints: fixed discounts capped at currency minor units; percentages `0..100`; header document discounts and manual line discounts mutually exclusive; `tax_inclusive: true` lines fail closed (`tax_inclusive_global_discount_forbidden`); allocated total capped at eligible pre-tax subtotal.

## 2. Proposed Response Projection & Apportionment

`SaleWireResponseProjection` would return `discount_total`, `discount_type`, `discount_value`, and per-line `allocated_document_discount`, `manual_discount_amount`, and `line_discount`, apportioned via deterministic Hare-Niemeyer (Largest Remainder) in minor units with ascending `line_number` tie-breaking so $\sum \text{allocated\_document\_discount}_i = \text{discount\_total}$.
