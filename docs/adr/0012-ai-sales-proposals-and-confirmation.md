# ADR 0012 — AI Sales proposals through canonical commands

Status: proposed for independent Phase 12 source acceptance. Date: 2026-10-11.

## Context and authority

[Approved future roadmap, §9.4](../ACCOUNTING_PHASE_9_FULL_ENGINEERING_ROADMAP_AND_FUTURE_AI_MOBILE_V1.md) and [ADR 0011](0011-versioned-command-api-foundation.md) control this implementation. Owner authorization permits source development and isolated QA; provider activation and Phase 12 release remain separate decisions. AccountingPostingService and canonical inventory services retain exclusive economic authority.

## Decision

Introduce StructuredIntentProvider and SpeechTranscriptionProvider contracts. The initial optional transport is a vendor-neutral HTTPS gateway client with a strict [provider contract](../PHASE_12_PROVIDER_CONTRACT.md). A compatible gateway is an external dependency, not an existing verified service. No gateway infrastructure or vendor account is installed or activated. This transport choice is submitted for architect review; a direct vendor adapter may replace it after provider selection without changing orchestration or financial services.

Start with draft_sales_invoice only. Model output contains untrusted references and exact decimal strings, never authoritative entity IDs, totals, tools or SQL. Resolve active same-company Customer/Product/ProductUnit/Warehouse/Tax through private services. Duplicate or missing references require clarification. Barcode identity binds its Product/Unit; carton conversions use ProductUnit. Relative dates use the company timezone. Canonical price permissions and financial rules apply again at resolution, apply, draft creation, preview and execution.

Applying a suggestion only populates an editable manual form. Explicit Save as Draft calls Phase 11 SalesInvoiceCommands. Posting requires the existing authenticated Preview → Confirm → Execute token, refreshed permissions, stock-context verification and idempotency. LLM output and audio never confirm a posting. No accounting/stock writer is changed or duplicated.

## Concurrency, identity and budget

Company-first database locks serialize request reservations. Same UUID/purpose/HMAC input converges; conflicting payloads fail. Network calls hold no database locks and are not automatically retried. Fresh actor/membership checks run after provider return. Encrypted apply tokens bind actor, tenant, proposal, resolved canonical hash and expiry. Apply re-resolves mutable sources; changed prices/context require another suggestion. Phase 11 commands enforce final economic atomicity, concurrency and confirmation.

Conservative integer USD micro-unit reservations enforce per-request, daily and monthly company/user caps. Failed or unknown usage is never refunded. Provider billing is independently unverified, and provider-side ceilings are an activation prerequisite. Own usage is available to authorized Sales creators; aggregate company usage additionally requires fresh settings.company.manage. Administrative global/speech/company disable switches remain server configuration, never model or browser parameters.

## Privacy and lifecycle

Audio capture is opt-in and capped by actual mono PCM16 sample count. Explicit submission uses authenticated same-origin CSRF protection. Temporary upload files are not moved into storage. Discard/navigation stop tracks, abort uploads and invalidate asynchronous capture generations. Returned transcripts stay editable; no raw audio/text/transcript is persisted or cached by the application. Upstream retention is a separate provider decision.

Only minimized encrypted structured proposals are cached for 600 seconds; expiry pruning removes caches while retaining content-free usage metadata. Accepted audit stores the encrypted exact canonical command approved by the human, command receipt identity, actor/company and resulting invoice. It participates in the same transaction as canonical draft creation. Encryption uses Laravel key custody; deferred Phase 10 key-recovery safeguards still apply. Approved financial-command audit retention follows business history; it is distinct from short-lived proposal/audio retention.

## Consequences and limits

Defaults are disabled, provider unapproved/none, speech off, cost ceilings zero. Shared hosting needs no permanent worker, Redis, Node server or new accounting backend; existing Laravel scheduling can prune expired proposals after an approved deployment. Additive assistant_requests migration preserves all financial tables. Production rollback must keep audit data and table; never roll back this migration on live data merely to revert code.

Real provider language/transcription accuracy, upstream caps/privacy, physical phone microphones and activation are NOT VERIFIED. Fake providers verify orchestration only. Phase 13, global AI chat, other financial intents and autonomous posting are out of scope. Phase 11 API exposure stays disabled in production.
