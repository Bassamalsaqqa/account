# Phase 9 engineering proposal — continuity and reconciliation

Status: **P9-0 DISCOVERY COMPLETE / EXECUTION CONTRACT AWAITING ARCHITECT REVIEW**. Phase 9 runtime implementation remains **UNSTARTED**.

The single current execution contract is [Phase 9 P9-0 discovery and execution lock](PHASE_9_P0_DISCOVERY_AND_EXECUTION_LOCK.md). It reconciles this earlier proposal with the complete [Product Owner roadmap](ACCOUNTING_PHASE_9_FULL_ENGINEERING_ROADMAP_AND_FUTURE_AI_MOBILE_V1.md), accepted source, Master Specification, Blueprint and ADRs 0004–0008. The owner roadmap copy retains its original bytes; the execution lock explicitly supersedes its stale production-verification checkpoint.

The original 242-line proposal and seven-file production documentation reconciliation are preserved in commit `098b17bf0559a8b8da2308f12b73cee52ea78cd9`, directly based on accepted main `28c2a53f84a8a0525ade6e1be7cee31ac17388ba`. This continuity update does not abandon, reset or duplicate that work. [Phase 7–8 independent production acceptance](PHASE_7_8_PRODUCTION_ACCEPTANCE.md) remains intact and qualified by its actual evidence.

## Reconciled decisions

- Reuse the accepted Sales DTO/print/mPDF/token/QR and Product media/Unit/Barcode infrastructure.
- Preserve existing Sales outputs and add four required private Purchasing outputs: Purchase, Purchase Return, Vendor Payment and Vendor Statement. Evaluate and defer broader Expense, stock, Money, Payroll and blanket report PDF coverage.
- Correct exact currency/Unit/lifecycle presentation without changing accounting, stock, allocations, numbering or canonical posting writers.
- Add source-specific secure receipt sharing, issued Customer Statement snapshots, revision-aware quotation grants and selected live catalogs. Purchase/Vendor/Payroll/Expense/stock finance remains private.
- Recommend fixed issuance statement data, replacing the prior fixed-filter/live-recomputation recommendation. Preserve and explicitly label legacy live statement links; never fabricate historical issuance data.
- Catalog prices are OFF server-side by default. Optional explicit item selling prices use one approved currency, configured Unit and clear tax basis; catalog updates are deliberate and keep the same managed public link.
- Include bounded barcode labels as explicitly requested in the latest kickoff; camera scanning stays deferred.
- Copy/Web Share/WhatsApp/mailto require a deliberate user action. No automatic delivery or SMTP integration.
- Use P9-A–F package names and dependencies from the execution lock, replacing the earlier P0–P7 decomposition.
- Keep Phase 10 hardening, Phase 11 API/command readiness, Phase 12 optional mic/text AI interpretation and Phase 13 optional mobile separate. No AI/mobile code in Phase 9.

The execution lock supplies the evidence inventory, full documents/RBAC matrix, public threat contract, proposed additive migrations, concrete UI flows, AGY package ownership, discovery test/PDF results, acceptance matrix and remaining architect decisions. No minor technical decision requires a Product Owner approval cycle.

This documentation PR does not authorize implementation, merge, migration or deployment. Stop for one independent architect review of the exact planning commit; begin P9-A only after accepted scope and explicit Product Owner implementation authorization.
