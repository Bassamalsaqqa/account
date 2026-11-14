# Mobile V2 Online Cash Sale — Public Wire Response Contract & Hand-Off Specification

> **Status: BACKEND SERVER CONTRACT QUALIFIED**  
> **Backend Qualification**: Qualified at execution `6981511405d7bcd04cd8f8a09e64443ce0aaf47e`, evidence published in `717c3da2829792a5ec4f11597905a17dbb8e7cd9`. The backend server wire projections and endpoints are qualified and ready for Android client implementation and controlled integration testing.  
> **End-to-End Freeze Notice**: Full end-to-end / pilot freeze remains pending real Kotlin HTTPS execution and signed device verification. Production financial execution remains strictly gated default-OFF (`DenyFinancialExecutionAdapter` bound in production, `mobile.v2_cash_slice_enabled = false`).

---

## 1. Scope & Normative Contract Authority

This document defines the **public wire response projections** and HTTP interaction contracts for the Mobile V2 Online Cash Sale slice.

- **Normative Request Authority**: Request payloads, economic intent validation, currency minor unit rules, canonical JCS serialization, and event hashing remain governed by the PR #91 Revision 3 specifications:
  - Specification: [`ADR-0001-mobile-v1-contract.md`](file:///home/t3dev/.t3/worktrees/account/t3-9723058c-3d0a7ebb15fba29a/docs/integration/mobile-v1/ADR-0001-mobile-v1-contract.md)
  - Mirrored JSON Schemas: [`docs/integration/mobile-v1/schemas/`](file:///home/t3dev/.t3/worktrees/account/t3-9723058c-3d0a7ebb15fba29a/docs/integration/mobile-v1/schemas/)
- **Zero Field Invention**: No new fields, aliases, or schema versions are introduced beyond the mirrored Revision 3 contracts.

---

## 2. Architectural Guardrails & Wire Principles

1. **Allowlisted Public Wire DTO**:
   - The public HTTP endpoint projects **only** allowlisted wire fields strictly defined in `sale-commit-response.json`.
   - **Zero Internal SQL IDs**: Auto-increment integer identifiers (`sales_invoice_id`, `customer_payment_id`, `customer_id`, etc.) are strictly prohibited from public wire payloads.
   - **Zero Alias Duplication**: Public responses eliminate duplicate and unreviewed field aliases (`sales_invoice_public_id`, `invoice_public_id`, `total_amount`, `paid_amount`, `amount`).
   - **Immutable DB Receipt Canonical Refs**: Internal database tables (`mobile_event_receipts`) retain integer relational foreign keys and immutable canonical result snapshots internally.

2. **Replay & Status Idempotent Field Parity**:
   - Replay (`HTTP 200`) projects **immutably identical** field values as fresh commit (`HTTP 201`).
   - Event status query projects identical official invoice references (`official_invoice`) adhering to `sale-event-status-response.json`.

3. **Identifier Layout & Validation Constraints**:
   - `client_event_id`: Version-agnostic UUID layout (8-4-4-4-12 hex pattern: `^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$`).
   - `invoice_id`, `payment_id`, `company_id`: Canonical 26-character Crockford Base32 ULID (`^[0-7][0-9A-HJKMNP-TV-Z]{25}$`).
   - Monies & Decimals: Canonical fixed-point scalar decimal strings (e.g. `'10.500000'`) bounded by company currency minor units.

---

## 3. Qualified HTTP Endpoints & Status Matrix

### A. Online Cash Sale Commit Endpoint
- **Method & Path**: `POST /api/mobile/v2/companies/{companyId}/sales/online-cash`
- **Authentication**: `Authorization: Bearer <mobile_access_token>` (native bearer token bound to verified user and device)
- **Tenancy**: `companyId` path parameter must match token company binding and active user membership.
- **Request Content-Type**: `application/json` (strictly valid canonical JSON payload conforming to Revision 3 intent profile)
- **Response Status Codes**:
  - **`HTTP 201 Created`**: Fresh execution. All financial legs (invoice, payment, stock ledger) committed atomically under Company-root serialization. Returns `SaleCommitResponse`.
  - **`HTTP 200 OK`**: Idempotent replay of previously committed event. Returns byte-for-byte identical `SaleCommitResponse`.
  - **`HTTP 409 Conflict`**: Altered payload resubmitted under an existing `client_event_id`. Returns standard `CONFLICT` error response with conflict diagnostics.
  - **`HTTP 401 Unauthorized`**: Missing, expired, or revoked bearer token (`UNAUTHENTICATED`).
  - **`HTTP 403 Forbidden`**: Inactive membership, tenant mismatch, or missing required actor permissions.
  - **`HTTP 404 Not Found`**: Cash slice disabled (`v2_cash_slice_enabled = false`) or master API disabled.

### B. Offline Event Status Endpoint
- **Method & Path**: `GET /api/mobile/v2/companies/{companyId}/offline-events/{clientEventId}/status`
- **Authentication**: `Authorization: Bearer <mobile_access_token>`
- **Response Status Codes**:
  - **`HTTP 200 OK`**: Returns `SaleEventStatusResponse`.
    - If event is posted: `status = "posted"`, `official_invoice` contains posted invoice ULID, sequence number, payment ULID, receipt number, grand total, and posted timestamp.
    - If event is unknown: `status = "unknown"`, `official_invoice = null`.
  - **`HTTP 401 Unauthorized`**: Unauthenticated request (`UNAUTHENTICATED`).
  - **`HTTP 404 Not Found`**: Cash slice disabled, unknown route, or master API disabled.

---

## 4. Sale Commit Wire DTO (`sale-commit-response.json`)

Mirrored schema reference: [`docs/integration/mobile-v1/schemas/sale-commit-response.json`](file:///home/t3dev/.t3/worktrees/account/t3-9723058c-3d0a7ebb15fba29a/docs/integration/mobile-v1/schemas/sale-commit-response.json)

All fields are required in the root object. `additionalProperties` is `false`.

| Wire Field | Wire Type | Pattern / Constraints | Nullable | Origin Source | Description |
|---|---|---|---|---|---|
| `client_event_id` | string | `^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$` | No | Client Request | Version-agnostic UUID layout (8-4-4-4-12 hex) |
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

## 5. Sale Event Status Wire DTO (`sale-event-status-response.json`)

Mirrored schema reference: [`docs/integration/mobile-v1/schemas/sale-event-status-response.json`](file:///home/t3dev/.t3/worktrees/account/t3-9723058c-3d0a7ebb15fba29a/docs/integration/mobile-v1/schemas/sale-event-status-response.json)

| Wire Field | Wire Type | Pattern / Constraints | Nullable | Description |
|---|---|---|---|---|
| `client_event_id` | string | `^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$` | No | Version-agnostic UUID layout (8-4-4-4-12 hex) |
| `company_id` | string | `^[0-7][0-9A-HJKMNP-TV-Z]{25}$` | No | Public ID of the company context (ULID) |
| `status` | string | Enum: `["posted", "parked_conflict", "unknown"]` | No | High-level status of the event |
| `payload_digest` | string \| null | `^sha256:[0-9a-f]{64}$` | Yes | SHA-256 digest of original normative request |
| `official_invoice` | object \| null | Schema matching posted invoice fields | Yes | Official invoice details if posted |
| `conflict` | object \| null | Schema matching conflict details | Yes | Conflict diagnostics (always null for clean sales) |
| `queried_at` | string | ISO-8601 date-time | No | Server timestamp of query evaluation |

### `official_invoice` Nested Fields:
- `invoice_id`: ULID string (`^[0-7][0-9A-HJKMNP-TV-Z]{25}$`)
- `invoice_number`: string (1..64)
- `payment_id`: ULID string or null
- `payment_receipt_number`: string (max 64) or null
- `grand_total`: scalar decimal string
- `posted_at`: ISO-8601 date-time string

---

## 6. Upstream Android Kotlin DTO Reference Definition

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
