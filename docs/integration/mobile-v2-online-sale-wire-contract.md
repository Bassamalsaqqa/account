# Mobile V2 Online Cash Sale — Public Wire Response Contract & Hand-Off Specification

> **Status: DRAFT SERVER CONTRACT PREPARATION — NOT FROZEN YET**  
> **Notice**: This document serves as the field mapping hand-off between backend `OnlineSaleWireResponseProjection` and the upstream Android Kotlin client models (`SaleCommitResponse.kt`, `SaleEventStatusResponse.kt`). Final contract freeze remains pending future physical device and real Kotlin HTTPS financial E2E test gates. PHP test execution does not substitute for native Android integration.

---

## 1. Architectural Guardrails & Response Principles

1. **Allowlisted Public Wire DTO**:
   - The public HTTP endpoint `/api/mobile/v2/companies/{companyId}/sales/online-cash` projects **only** allowlisted wire fields strictly defined in `sale-commit-response.json`.
   - **Zero Internal SQL IDs**: Auto-increment integer identifiers (`sales_invoice_id`, `customer_payment_id`, `customer_id`, etc.) are strictly prohibited from public wire payloads.
   - **Zero Alias Duplication**: Public responses eliminate duplicate and unreviewed field aliases (`sales_invoice_public_id`, `invoice_public_id`, `total_amount`, `paid_amount`, `amount`).
   - **Immutable DB Receipt Canonical Refs**: Internal database tables (`mobile_event_receipts`) retain integer relational foreign keys and immutable canonical result snapshots internally.

2. **Replay & Status Idempotent Field Parity**:
   - Replay (HTTP 200) projects **immutably identical** field values as fresh commit (HTTP 201).
   - Event status query projects identical official invoice references (`official_invoice`) adhering to `sale-event-status-response.json`.

---

## 2. Sale Commit Wire DTO (`sale-commit-response.json`)

All fields are required in the root object. `additionalProperties` is `false`.

| Wire Field | Wire Type | Pattern / Constraints | Nullable | Origin Source | Description |
|---|---|---|---|---|---|
| `client_event_id` | string | `^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$` | No | Client Request | RFC 4122 UUID v4 event identifier |
| `status` | string | Enum: `["posted"]` | No | Terminal State | Financial execution status (`"posted"`) |
| `invoice_id` | string | `^[0-7][0-9A-HJKMNP-TV-Z]{25}$` | No | `sales_invoices.public_id` | Canonical 26-character Crockford Base32 ULID |
| `invoice_number` | string | Min: 1, Max: 64 | No | `sales_invoices.invoice_number` | Human-readable document sequence number |
| `payment_id` | string \| null | `^[0-7][0-9A-HJKMNP-TV-Z]{25}$` | Yes | `customer_payments.public_id` | Canonical 26-character Crockford Base32 ULID |
| `payment_receipt_number` | string \| null | Max: 64 | Yes | `customer_payments.payment_number` | Human-readable payment receipt number |
| `grand_total` | string | `^(?:0\.(?=[0-9]{1,6}$)[0-9]*[1-9][0-9]*\|[1-9][0-9]{0,13}(?:\.[0-9]{1,6})?)$` | No | `sales_invoices.grand_total_currency` | Exact positive decimal lexeme (DEC(20,6)) |
| `amount_paid` | string | `^(?:0\|[1-9][0-9]{0,13})(?:\.[0-9]{1,6})?$` | No | `customer_payments.amount` | Exact non-negative decimal lexeme |
| `change_returned` | string | `^(?:0\|[1-9][0-9]{0,13})(?:\.[0-9]{1,6})?$` | No | Computed Tender Conservation | Exact non-negative decimal lexeme (e.g. `'0.000000'`) |
| `posted_at` | string | ISO-8601 date-time (`format: "date-time"`) | No | `sales_invoices.created_at` | Server commit timestamp in UTC (RFC 3339) |

---

## 3. Sale Event Status Wire DTO (`sale-event-status-response.json`)

Used by clients during connection recovery or polling.

| Wire Field | Wire Type | Pattern / Constraints | Nullable | Description |
|---|---|---|---|---|
| `client_event_id` | string | UUID v4 | No | Event identifier being queried |
| `company_id` | string | ULID | No | Public ID of the company context |
| `status` | string | Enum: `["posted", "parked_conflict", "unknown"]` | No | High-level status of the event |
| `payload_digest` | string \| null | `^sha256:[0-9a-f]{64}$` | Yes | SHA-256 digest of original normative request |
| `official_invoice` | object \| null | Schema matching posted invoice fields | Yes | Official invoice details if posted |
| `conflict` | object \| null | Schema matching conflict details | Yes | Conflict diagnostics (always null for clean sales) |
| `queried_at` | string | ISO-8601 date-time | No | Server timestamp of query evaluation |

### `official_invoice` Nested Fields:
- `invoice_id`: ULID string
- `invoice_number`: string (1..64)
- `payment_id`: ULID string or null
- `payment_receipt_number`: string (max 64) or null
- `grand_total`: scalar decimal string
- `posted_at`: ISO-8601 date-time string

---

## 4. Upstream Android Kotlin DTO Reference Definition

```kotlin
package com.smalltrader.android.network.dto

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

@Serializable
data class SaleCommitResponse(
    @SerialName("client_event_id") val clientEventId: String,
    @SerialName("status") val status: String,
    @SerialName("invoice_id") val invoiceId: String,
    @SerialName("invoice_number") val invoiceNumber: String,
    @SerialName("payment_id") val paymentId: String? = null,
    @SerialName("payment_receipt_number") val paymentReceiptNumber: String? = null,
    @SerialName("grand_total") val grandTotal: String,
    @SerialName("amount_paid") val amountPaid: String,
    @SerialName("change_returned") val changeReturned: String,
    @SerialName("posted_at") val postedAt: String
)

@Serializable
data class SaleEventStatusResponse(
    @SerialName("client_event_id") val clientEventId: String,
    @SerialName("company_id") val companyId: String,
    @SerialName("status") val status: String,
    @SerialName("payload_digest") val payloadDigest: String? = null,
    @SerialName("official_invoice") val officialInvoice: OfficialInvoiceDto? = null,
    @SerialName("conflict") val conflict: ConflictDto? = null,
    @SerialName("queried_at") val queriedAt: String
)

@Serializable
data class OfficialInvoiceDto(
    @SerialName("invoice_id") val invoiceId: String,
    @SerialName("invoice_number") val invoiceNumber: String,
    @SerialName("payment_id") val paymentId: String? = null,
    @SerialName("payment_receipt_number") val paymentReceiptNumber: String? = null,
    @SerialName("grand_total") val grandTotal: String,
    @SerialName("posted_at") val postedAt: String
)
```
