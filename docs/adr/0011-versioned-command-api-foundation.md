# ADR 0011 — Versioned Sales command API foundation

Status: implementation candidate on phase/11-api-command-foundation; independent architect acceptance pending. API disabled by default. No Phase 11 merge or deployment.

## Scope and authentication decision

The approved roadmap section 9.3 calls for reusable Sales intent, preview and confirmed-action contracts, browser/API parity, exact values, tenant permissions, retry safety and revocable authentication. The first surface is Sales Invoice create/update/preview/execute/read plus scoped entity lookups and device sessions. Existing Purchasing, Money and other domain actions remain canonical. Native apps, bearer-token issuance and AI providers are outside this candidate.

Use the existing Fortify credential/MFA login and Laravel same-origin cookie session, with real CSRF enforcement. This avoids a second authentication engine and new dependencies on shared hosting. A fresh credential login stamps a maximum-age marker; a fresh login with the remember checkbox is legitimate, but later automatic remember-cookie recall is refused for API access. Database sessions on the canonical database connection are required. Session storage on another connection or an unsupported driver fails closed before web session bootstrap. Native mobile token authentication requires a separately reviewed mechanism; simulated client requests in this candidate use the accepted cookie contract.

## Commands and accounting authority

SalesDraftIntent accepts only declared header/line fields. Economic values must be decimal strings, normalized to six quantity/money places and ten FX places with existing value-object bounds. Integers, floats, exponent notation, unsupported fields and oversized inputs are refused. Defaults are suggestions; no historical value is reconstructed from a mutable default.

SalesInvoiceCommands invokes CreateSalesInvoiceDraftAction, UpdateSalesInvoiceDraftAction and PostSalesInvoiceAction. It adds no ledger or inventory writer. Company-first transactions and fresh SalesActorGuard checks precede receipt replay as well as new work. API commands additionally lock their actual owned session and freshly verify the user password on the same connection before business effects. Session deletion/password change after middleware proof is refused by the command boundary.

The forward migration adds command_receipts only. A unique company/actor/action/key identity, case-sensitive ASCII keys and normalized payload hashes distinguish retries from conflicting requests. Target identity is part of update/execute matching. Deleted drafts leave receipts with null references, so their keys cannot silently recreate documents. Receipts commit atomically with canonical effects. Replays return the current authorized canonical resource, not an immutable response cache.

Preview runs without numbering, financial posting, stock reservation or immutable history changes. It validates canonical draft integrity and current rules, displays exact totals and FX source, and provides advisory aggregated stock/FEFO projections without costs. An encrypted five-minute confirmation token binds company, actor, target, draft revision and current identity/routing/pricing/tax/inventory context. Execute rechecks everything under locks and invokes the canonical post action only after valid confirmation. Exact committed retries remain replayable after token expiry; fresh authorization still applies.

## HTTP boundary and data protection

Phase11ApiBoundary is global but acts only on api/v1 and api/v1/*. When disabled it returns 404 with zero database queries before session/CSRF/auth bootstrap. When enabled it bounds body size/depth, enforces JSON/TLS, rejects supplied actor/company/cost fields and floating JSON values, and applies IP and authenticated rate limits.

API exception reporting records exception class and trace ID only. Actual downstream error responses are sanitized as well as thrown exceptions, because Laravel may render before the outer middleware regains control. Fixed problem envelopes preserve session cookies and relevant protocol headers without SQL, paths or tokens. Trace IDs accept bounded UUIDs or are generated server-side. Ordinary web exception behavior is preserved.

Read projections execute within company-first authorization transactions; raw Eloquent models and costs are not returned. Entity lookups are company-scoped and bounded to 50 rows. Customer eligibility uses the stored status column; Product/Unit prices are explicitly labeled suggestions. Session identifiers/payloads are never returned; device handles are actor-bound HMACs. Enumeration/revocation require fresh, nonfuture password confirmation, and foreign handles are refused.

## Browser behavior

Livewire InvoiceForm and InvoiceDetail use the same shared commands. Review saves a draft and renders a server preview; a separate explicit confirmation posts it. Server confirmation tokens, request keys, digest and preview data are locked. Edited/stale previews fail closed. Arabic RTL and English LTR share the same preview component, exact currency formatting, focus lifecycle and localized blockers/errors. Unrecognized errors never display exception messages.

## Acceptance and release

See [implementation ledger](../PHASE_11_IMPLEMENTATION_LEDGER.md) and [source acceptance evidence](../PHASE_11_SOURCE_ACCEPTANCE.md) for executed checks and limitations. Worker claims are not acceptance. Independent architect source acceptance, Owner-authorized merge/deployment and separately accepted API exposure are distinct gates. Phase 12 cannot begin before Phase 11 source acceptance; real AI activation additionally requires provider/cost/privacy approval. Deferred Phase 10 recovery, off-host custody and real-mail proofs remain unverified.
