# Mobile V2 Offline Cash Sale Pilot

> **Status:** Implemented in code behind **default-OFF** flags (`MOBILE_API_ENABLED=false`, `MOBILE_MERCHANT_API_ENABLED=false`, `MOBILE_V2_API_ENABLED=false`, `MOBILE_V2_CASH_SLICE_ENABLED=false`, `MOBILE_V2_OFFLINE_SALES_ENABLED=false`). Paired real-HTTP verification (`12` vectors across 3 companies + `6` domain reconciliations + foreign-tenant zero-leakage) passed against the `d096` post-qualification runtime baseline (runtime business source unchanged since). Production API & offline sales remain **OFF**.

Requires `MOBILE_V2_OFFLINE_SALES_ENABLED=true`, mobile/merchant/V2/cash flags, and explicit per-company limits (duration, count, total) in `company_mobile_settings`. V1 `/bootstrap` continues reporting offline unavailable unless enabled. All V2 endpoints require native bearer auth and active invoice + cash receipt permissions.

## 1. Wire Contract (`routes/mobile-v2.php`)

- **`POST /api/mobile/v2/companies/{companyId}/offline-sale-lease`:** Accepts `{"catalog_snapshot_version":"<cached-version>"}`. Returns company/actor/device IDs, `lease_id`, `catalog_snapshot_version`, UTC `issued_at`/`expires_at`, currency & minor units, warehouse/cash account/walk-in customer/tax IDs, three policy limits, and posted count/total. Same active snapshot returns the existing lease; mismatched snapshot/policy returns `409` until expiry.
- **`POST /api/mobile/v2/companies/{companyId}/offline-events`:** Uses Revision 3 `offline-sale-intake-request`/`response` schemas and restricted JCS canonical digest over preserved outbox bytes (`client_event_id`, case, decimal lexemes, line order). `200` confirms canonical invoice + payment (or idempotent recovery).
- **`GET /api/mobile/v2/companies/{companyId}/offline-events/{clientEventId}/status`:** Uses `sale-event-status-response`. Client must verify digest before adopting receipt. `unknown` preserves outbox event for retry/review. Recoverable after lease expiry by the same staff/device.
- **`409` conflicts:** Returns V2 error envelope with `error.conflict_evidence.reason`. Refusals roll back lease counter charges and financial legs while preserving original bytes.

## 2. Operational & Authority Boundaries

- One active lease per `(company, staff, device)`; capture timestamp must fall inside server lease window (no future client timestamps; server posting date governs accounting).
- Supports full base-currency cash sales at authoritative precision using configured warehouse, cash account, and default tax/no-tax setup. Revalidates prices, units, tax, and live stock at intake. Disabling company policy or changing approved cash/tax settings retires active leases atomically.
- **Android client invariants:** Persist lease scope, catalog version, limits, exact pending totals, and raw canonical bytes across restarts; verify status before resend after lost ACK; collect 2dp human input while preserving wire precision; reconcile rejected cash sales.
- **Required release-gate witnesses:** Multi-connection MariaDB lock-contention witnesses covering login vs deactivation, token/device revocation vs admission, password reset/MFA/recovery changes vs lease/intake, role removal vs replay/status, last-slot race, and overlapping AI/support lock orders.
