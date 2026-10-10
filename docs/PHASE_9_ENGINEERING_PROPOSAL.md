# Phase 9 engineering proposal — continuity and reconciliation

Status: **COMPLETE / SOURCE ACCEPTED / MERGED / DEPLOYED / PRODUCTION VERIFIED**.
The Owner-authorized release `8d8428261cd2a10690ab77a5c7271e46b5ff5217` is recorded
in the [2026-10-10 production acceptance](PHASE_9_PRODUCTION_ACCEPTANCE.md).
Planning PR #17 was merged at `3f82b947d319d519cea6d25cecd7724bfc909672`.
C1–C5 and the catalog-media addendum remain frozen. The
[source handoff](PHASE_9_SOURCE_ACCEPTANCE_HANDOFF.md) and
[ADR 0009](adr/0009-documents-secure-sharing-and-catalogs.md) retain implementation
and actual QA. Phase 10–13 implementation remains outside this release authorization.

The single current execution contract is [Phase 9 P9-0 discovery and execution lock](PHASE_9_P0_DISCOVERY_AND_EXECUTION_LOCK.md). It reconciles this earlier proposal with the complete [Product Owner roadmap](ACCOUNTING_PHASE_9_FULL_ENGINEERING_ROADMAP_AND_FUTURE_AI_MOBILE_V1.md), accepted source, Master Specification, Blueprint and ADRs 0004–0008. The owner roadmap copy retains its original bytes; the execution lock explicitly supersedes its stale production-verification checkpoint.

The original 242-line proposal and seven-file production documentation reconciliation are preserved in commit `098b17bf0559a8b8da2308f12b73cee52ea78cd9`, directly based on accepted main `28c2a53f84a8a0525ade6e1be7cee31ac17388ba`. This continuity update does not abandon, reset or duplicate that work. [Phase 7–8 independent production acceptance](PHASE_7_8_PRODUCTION_ACCEPTANCE.md) remains intact and qualified by its actual evidence.

## Reconciled decisions

- Reuse the accepted Sales DTO/print/mPDF/token/QR and Product media/Unit/Barcode infrastructure.
- Preserve existing Sales outputs and add four required private Purchasing outputs: Purchase, Purchase Return, Vendor Payment and Vendor Statement. Evaluate and defer broader Expense, stock, Money, Payroll and blanket report PDF coverage.
- Correct exact currency/Unit/lifecycle presentation without changing accounting, stock, allocations, numbering or canonical posting writers.
- Add source-specific secure receipt sharing, issued Customer Statement snapshots, revision-aware quotation grants and selected live catalogs. Purchase/Vendor/Payroll/Expense/stock finance remains private.
- C1: adopt confirmed Option A: new financial links initially return a neutral document-free GET/HEAD landing; CSRF-protected View Document POST establishes bounded exact-share/revision access for every HTML/PDF/print/API read. Password optional for Quotation/Invoice/Return/Receipt, mandatory with finite expiry for Statements; preserve and label legacy disclosure with revoke/reissue.
- C2: freeze new public receipt disclosure to issued facts and original allocation/currency/settlement legs. Later payment applications appear only in a separate private section; changed public scope requires authorized preview/reissue, with no payment-writer changes.
- C3: Owner receives new Phase 9 capabilities by default; existing non-owner grants remain unchanged. New-company Administrator gets no new protected publication/share/price/settings capabilities automatically. Owner may explicitly delegate; ordinary role management cannot self-escalate into them.
- C4: freeze approved catalog image/thumbnail identity per published item/revision. Product primary-image changes cannot update it; unavailable approved media gives a placeholder/omission. Explicit approved publication advances revision at the same link.
- C5: adopt fixed issuance Statements as immutable versioned allowlisted canonical JSON, SHA-256 integrity and encrypted `MEDIUMTEXT`/equivalent private storage; proposed caps 2 MiB plaintext/4 MiB ciphertext/1,000 entries require measurement. Atomic snapshot/share issuance and payload-aware retries reject failure before an active grant; no live fallback. Retain `APP_PREVIOUS_KEYS`, encrypted-backup/key recovery and no secret/full-DTO logs. Label legacy live Statements and offer revoke/reissue without retrospective fabrication.
- Catalog prices are OFF server-side by default. Optional explicit item selling prices use one approved currency, configured Unit and clear tax basis; deliberate updates preserve a valid unrevoked link. Pause can resume that link; revoke permanently retires it. Explicit authorized New Link issues a distinct token/grant while preserving previous grants and audit/publication history.
- Implementation Correction 01 centralizes Company-local catalog dates (exclusive next-day start, stored UTC) and financial lifetimes (exact N × 24 hours from issuance). Legacy timestamps are preserved; password-only edits do not renew expiry. See the [focused correction handoff](PHASE_9_CORRECTION_01_HANDOFF.md).
- Approved catalog-media Option A: deliberately published Product photographs are public marketing assets. Pause/expiry/revocation/Company disable immediately denies managed catalog HTML/API/JSON/PDF/QR destinations; existing direct public-storage image URLs and downloaded/cached copies may remain independently available. Administration must explain this limitation. Preserve C4 frozen approved identity and trusted same-company image checks; never expose private financial files, costs or foreign media. No new proxy, private duplicate storage or migration; §14 of the execution lock specifies the managed-endpoint versus direct-image regression.
- Include bounded barcode labels as explicitly requested in the latest kickoff; camera scanning stays deferred.
- Copy/Web Share/WhatsApp/mailto require a deliberate user action. No automatic delivery or SMTP integration.
- Use P9-A–F package names and dependencies from the execution lock, replacing the earlier P0–P7 decomposition.
- Keep Phase 10 hardening, Phase 11 API/command readiness, Phase 12 optional mic/text AI interpretation and Phase 13 optional mobile separate. No AI/mobile code in Phase 9.

The execution lock supplies the evidence inventory, updated documents/RBAC matrix, public threat contract, proposed additive migrations, concrete UI flows, unchanged AGY package dependencies, carried discovery test/PDF results and precise C1–C5 negative acceptance matrix. The five policy decisions are closed; resource budgets remain proposed measurement targets. Correction 01 runs documentation/Git verification only, with no runtime tests or discovery repeated.

The preceding discovery and correction results describe the accepted planning checkpoint. The Owner subsequently authorized P9-A–F implementation and additive local schema work. The implementation handoff supersedes the former UNSTARTED status; production acceptance and the original roadmap are preserved. The P9-0 lock remains the policy authority, and no implementation PR merge or deployment is authorized.
