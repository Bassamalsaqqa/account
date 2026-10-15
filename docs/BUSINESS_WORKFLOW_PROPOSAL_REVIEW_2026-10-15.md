# Daily business workflow improvement release

Baseline: `68b4ec0fdaf98c8cb528181da0d128653f69e937` (accepted V2.3). The Owner reports this baseline deployed. This review uses local source, not a new production inspection. Gemini's V2.3+ proposal is a suggested backlog; its latency and query-reduction percentages are unmeasured. The Owner's autonomous assignment supersedes the proposal's per-package confirmation requirement.

The selected release improves entry, discovery and daily review without introducing new accounting events or a competing calculator. Existing canonical posting, inventory, settlement, AI and public-disclosure boundaries remain authoritative. Deployment requires separate release authorization.

## Recommendation matrix

| Gemini recommendation | Repository finding and release decision |
| --- | --- |
| 35.1 Tenant validation and child ownership | Canonical domain actions already resolve company-owned parents and lines. Several UI `exists:` validations lack company predicates: scope these as defense in depth. New workflow tests exercise context switches, foreign IDs and revoked grants. This is a scoped audit, not proof that every application boundary is vulnerability-free. |
| 35.1 CSV formula protection | Already implemented in `Application/Reporting/Support/CsvCellFormatter`; Unicode whitespace/control prefixes are covered. Keep numeric contracts separate. |
| 35.2 Web/API/AI authorization | `AssistantReadTools`, `AssistantReportExecutor`, `ReportPermissionCatalog` and `ReportingGuard` already authorize canonical reports. Do not replace mature domain guards with a broad new permission framework. |
| 35.3 Integrity UI | Missing owner-facing full reconciliation UI. Defer: full-history synchronous scans on shared hosting need measured bounds and a truthful persisted audit status. No expensive scans on routine page loads. |
| 6 / 17.1 Client calculation | Invoice and quotation numeric fields already use blur updates. Retain exact server previews; avoid a second JS tax/FX engine and unprovable “0ms” claims. |
| 17.2 Navigation/lazy loading | Invoice/Purchase product matching is bounded to 50 plus selected records. Quotation still needs bounded discovery. Improve the touched entry paths; defer application-wide SPA conversion until dirty-form, voice and browser-history lifecycle is covered. |
| 9 Session/cache tuning | Existing database drivers are deliberate deployment configuration. The claimed 40% saving is unsupported. No driver changes or rollup cache without profiling. |
| 9 Sequence locking | Already implemented: `DocumentSequenceService` requires a transaction and locks sequence rows. |
| 9 Composite indexes/ledger rollups | Existing migrations and report queries already carry indexes and exact aggregation. Future query-specific EXPLAIN work is preferable to speculative balance caches. The release adds only a durable party-creation request receipt; financial/inventory schema is unchanged. |
| 11 Purchase/return void; stock reversals | New financial/stock correction workflows. Valuable, but a safe inverse must prove complete original lot/provenance, payments, returns, transfers and cost routing. Defer pending an explicit domain policy, rather than mirror Sales blindly. |
| 12 Brands | Brand model exists; management UI is missing. Implement bilingual management, activation, product filtering and inline creation. Preserve historical product references. |
| 13 Opening balances | `PostOpeningBalancesAction` accepts GL asset/liability/equity amounts, not customer/vendor settlement positions. A wrapper posting only AR/AP would break subledger reconciliation. Defer a complete counterparty opening design. |
| 4 Period lock | Missing global policy. Valuable separately: every financial/stock effective date, legitimate current-period reversal, historical import and permission must be covered. Avoid a partial central-service date check. |
| 14 Settlement discounts | New settlement accounting, allocations and residual policy. Defer; no fabricated cash movement or silent balance write-off. |
| 15 Returned checks | Already implemented through `TransitionCheckAction`, source adapters and payment reversals. Reuse mature Money regressions; add attention filtering, not another debt-restoration engine. |
| 3 Check endorsement | Missing distinct linked vendor settlement/state workflow. Defer pending liability, bounce, reversal and allocation policy. |
| 22 Customer/vendor netting | Missing economically meaningful offset. Defer; requires explicit counterparty linkage, per-currency allocation and statements without fake cash. |
| 30 FX editing and realized gains | Editable transaction rates and cross-currency settlement/check clearance already exist. Keep stored historical exchange-rate snapshots and established gain/loss posting. No new FX architecture. |
| 1 Immediate invoice/purchase payment | Existing receipt/payment actions exist; atomic combined posting UI is missing. Defer new composite financial event until retry and rollback policy is designed. |
| 21 Immediate refunds | Separate return/settlement semantics are required. Defer; returning goods is not evidence of cash paid or received. |
| 20 Customer price cue | Historical query/report exists; inline cue is missing. Add a bounded read-only recorded-unit-price cue from posted sales lines, with original date/currency/unit. Do not imply a historical tax-inclusion policy, overwrite an entered price or convert historical snapshots using mutable unit configuration. |
| 20 Below-cost blocking | New pricing policy. Defer hard blocking and permission-sensitive cost disclosure. This release never adds cost to unauthorized client state. |
| 31 Quotation conversion | Already produces an editable draft. Improve the authorized edit destination and existing provenance affordance; never auto-post. |
| 28 Document duplication | Missing broad repeat-document UX. Keep for a subsequent bounded release: selected inactive parties/units, changed tax/FX, expense recognition versus payment and current-date policy need explicit behavior. |
| 2 Negative stock flexibility | `allow_negative_stock` configuration exists, but canonical valuation/lot policy is strict. Do not activate it as a shortcut. Editable drafts already permit preparing work pending receipt. |
| 16 Landed cost | Existing posted expense allocation is draft-purchase-only, with expense-date limits. Already-sold retrospective COGS is outside this domain policy; no historical revaluation. |
| 23 Unit prices/barcodes | `ProductUnit` prices and unit-linked `ProductBarcode` already exist. Complete Purchasing exact unit lookup and show whole-package stock breakdown with exact base remainder. |
| 32 Manual lots | New allocation semantics; returns must trace the original source, not arbitrary FEFO overrides. Defer pending domain-specific constraints and immutable provenance coverage. |
| 8 Bulk stocktake | Useful new workflow; looping adjustments is insufficient for stale counts, lot cost, concurrency and all-row atomicity. Defer complete count-session design. |
| 24 Payroll sheet | Useful future batch orchestration, outside the daily trade focus selected here. Requires duplicate/month/partial-failure policy. |
| 27 Daily Money summary | Missing opening/inflow/outflow/closing view. Implement by date and account/currency using immutable posting history and existing source-sensitive visibility. Clearly disclose unavailable native amounts and restricted activity. |
| 18 WhatsApp financial sharing | Already present in `financial-share-manager.blade.php`, using deliberately issued expiring/revocable links. Keep the explicit sharing flow and required statement password; do not silently create public disclosures. |
| 25 Catalog order requests | Optional new public interaction, deferred until merchant contact/consent, quantity bounds and quotation-request wording are defined. |
| 26 Print/PDF | Existing canonical renderers, bilingual templates, PDF and print workflows. Retain; targeted real multi-page print refinement remains a separate task, not a renderer rewrite. |
| 34 Quick party creation | Missing in trade forms. Add human-triggered Customer/Vendor creation through existing catalog services, preserving unsaved transaction state and permissions. |
| 34 Quick product creation/global search | Defer the larger product editor and global record search. Product creation requires unit, stock/expiry and pricing policy; global search needs domain-sensitive source authorization. Existing bounded product lookup is retained. |
| 19 Keyboard/barcodes | Sales barcode exists. Add safe purchasing unit-specific lookup and visible draft-only shortcuts; no continuous POS loop or keyboard posting. |
| 29 Tenant data “backup” | Not implemented. A CSV ZIP is an export, not proven recovery. Track recovery separately; no public archive, keys or sensitive financial bundle in this release. |
| 33.1 Arabic normalization | Already implemented in `TenantSpeechCandidates` and reused by resolvers. No duplicate normalizer. Actual speech-quality evaluation remains unverified. |
| 33.2 AI undo/highlight | Already implemented by `AiUnsavedForm`/`AiFormCorrections`; preserve bounded unsaved editing. |
| 33.3 Timeout/retry | Deadline/unavailable handling already exists; real provider timeout and device speech evaluation remain separate. No paid calls, persistent chat or automatic financial actions. |
| 10.1 SaaS plans/onboarding | Not worthwhile for this release's daily-workflow goal. Bootstrap foundations exist; tenant billing/quotas are a separate product program. |
| 10.2 Mobile mutation API | Future channel expansion; not prerequisite to improving the existing web workflow. |
| 10.3 Mass PhaseX renaming | Disproportionate regression cost with no daily user benefit. Do not implement. |
| Future POS, statutory reports, OCR, bulk scanning | Explicitly deferred by the proposal. Preserve compatibility without starting these phases. |

## Verification boundaries

New behavior must pass focused MariaDB, permission and unchanged-economic-history tests, related mature regressions, static analysis, frontend build and bilingual desktop/mobile browser inspection. Reconcile changed synthetic economic fixtures with canonical audits where applicable. Do not reuse V2.3 test counts as new evidence or claim local checks establish Hostinger performance, production health, live AI accuracy, actual mail delivery or independent disaster recovery.

Exact completed changes and observed gate results belong in the consolidated release handback. The operational work is tracked in `OPERATIONS_RECOVERY_AND_MAIL_FOLLOWUP.md` and remains separate from source acceptance.
