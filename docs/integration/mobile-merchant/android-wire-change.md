# Android Wire Change Specification: Sales Invoice Index Cursor Pagination

## 1. Overview & Context

The Owner Full Android Invoice History feature requires actual keyset-based cursor pagination. Previously, `GET /api/mobile/v1/companies/{companyId}/sales/invoices` returned a bounded result set with `has_more: Boolean` but without a continuation cursor, preventing the Android client from retrieving subsequent pages beyond the initial limit.

This document outlines the wire-level changes, query parameters, security bindings, and strict Kotlin DTO requirements for the Android client.

---

## 2. Wire Protocol Changes

### Endpoint
`GET /api/mobile/v1/companies/{companyId}/sales/invoices`

### New Query Parameters
| Parameter | Type | Required | Constraints | Description |
|---|---|---|---|---|
| `cursor` | string | No | Max length 255, base64url | Opaque, tamper-protected, tenant- and filter-bound pagination token returned in `next_cursor` of preceding page. Omit or empty for first page. |
| `limit` | integer | No | 1 .. 100, default 30 | Page size bound. Values `< 1` or `> 100` are rejected with HTTP 422. |
| `status` | string | No | Enum | `DRAFT`, `POSTED`, `VOID`, `PAID_CASH`, `PAID`, `PARTIAL`, `ON_CREDIT`. |
| `search` | string | No | Max length 100 | Invoice number, public ID, customer name/phone search query. |
| `customer_id` | string | No | Crockford ULID (26 chars) | Scoped customer filter. |
| `date_from` | string | No | YYYY-MM-DD format | Lower bound on `issue_date` (inclusive). |
| `date_to` | string | No | YYYY-MM-DD format | Upper bound on `issue_date` (inclusive). |

### Response Schema Updates
Schema: `docs/integration/mobile-merchant/schemas/sales-invoice-index-response.json`

Added top-level field `next_cursor`:
```json
{
  "company_id": "01JCV8X9MCUST123456789ABCD",
  "count": 30,
  "has_more": true,
  "next_cursor": "eyJ2IjoxLCJjIjoiMDFKLi4uIiwiZmgiOiI4YS4uLiIsInAiOiIwMUpDLi4uIn0.5a8f...",
  "invoices": [ ... ]
}
```

- When `has_more == true`, `next_cursor` is a non-empty string (`maxLength: 255`).
- When `has_more == false` (exhaustion), `next_cursor` is `null`.
- First page request without `cursor` parameter starts from the newest invoice.

---

## 3. Strict Android Kotlin DTO Alignment

For clients using `kotlinx.serialization` with strict decoding (unknown keys prohibited or strict field matching):

```kotlin
package com.smalltrader.android.data.remote.dto

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

@Serializable
data class SalesInvoiceIndexResponse(
    @SerialName("company_id")
    val companyId: String,

    @SerialName("count")
    val count: Int,

    @SerialName("has_more")
    val hasMore: Boolean,

    @SerialName("next_cursor")
    val nextCursor: String? = null,

    @SerialName("invoices")
    val invoices: List<SalesInvoiceListItemDto>
)

@Serializable
data class SalesInvoiceListItemDto(
    @SerialName("id")
    val id: String,

    @SerialName("invoice_number")
    val invoiceNumber: String,

    @SerialName("status")
    val status: String,

    @SerialName("customer_id")
    val customerId: String,

    @SerialName("customer_name")
    val customerName: String? = null,

    @SerialName("date")
    val date: String,

    @SerialName("subtotal")
    val subtotal: String,

    @SerialName("discount")
    val discount: String,

    @SerialName("tax")
    val tax: String,

    @SerialName("total")
    val total: String,

    @SerialName("paid")
    val paid: String,

    @SerialName("remaining")
    val remaining: String,

    @SerialName("currency")
    val currency: String,

    @SerialName("currency_minor_units")
    val currencyMinorUnits: Int,

    @SerialName("payment_status")
    val paymentStatus: String,

    @SerialName("created_at")
    val createdAt: String? = null
)
```

---

## 4. Keyset Invariants and Pagination Lifecycle

1. **Ordering Invariant**:
   Invoices are ordered strictly by `public_id desc` (canonical Crockford ULID).
   Because ULIDs embed 48-bit millisecond timestamps and are strictly unique per row, pagination is 100% deterministic with zero risk of duplicated or skipped rows across page boundaries, even when multiple invoices share identical `issue_date` values.

2. **Zero Offset Cost**:
   Pagination executes via `where('public_id', '<', $cursorPosition)` using the unique B-tree index on `sales_invoices.public_id`. No `OFFSET` scan or full-table buffering occurs ($O(1)$ memory and query complexity).

3. **Tenant & Filter Integrity Binding**:
   The cursor token contains an HMAC-SHA256 signature binding:
   - Tenant Public ID (`c`)
   - Filter Hash (`fh` = `xxh128(json([status, search, customer_id, date_from, date_to]))`)
   - Position ULID (`p`)
   
   If any filter parameter is altered while passing a previous cursor, the backend rejects the request with HTTP 422:
   ```json
   {
     "error": {
       "code": "VALIDATION_FAILED",
       "message": "Cross-filter pagination cursor reuse is rejected."
     }
   }
   ```
   If a cursor is passed across companies, the backend rejects with HTTP 422 (`Cross-tenant pagination cursor reuse is rejected.`).
   If a cursor is tampered with, the backend rejects with HTTP 422 (`Tampered pagination cursor signature.`).


### Read-ready business settings compatibility

`GET /api/mobile/v1/companies/{companyId}/settings/business` retains the master mobile gate, bearer/device validation and active same-company membership. With the merchant gate OFF, `sales.invoice.create` (the existing bootstrap permission) grants only company ID, Arabic/English name/address, phone, tax number, base currency and authoritative `default_currency_minor_units` from `CurrencyMinorUnitsResolver`. No currency precision default is invented. Operational warehouse, cash-account, walk-in, taxes and currency-list fields are absent. Cross-company requests return 404; expired/revoked credentials return 401; missing bootstrap permission returns 403. Responses are private/no-store.

With the merchant gate ON the existing full settings wire projection and permission behavior remain intact. No write gate changes. Android `ProductionTraderRepository.fromReadReady` consumes the precision and optional profile fields from this endpoint; its operational mappings continue to come from bootstrap. The endpoint must be released on the backend before using clients that require this precision; older servers without this route still fail closed.

Purchase-return preview and execution share identifier and exact positive quantity validation. Every submitted line must resolve to this purchase; both supplied identifiers must agree, and duplicate original lines fail with 422. Preview never commits canonical effects.
