# Phase 9 implementation checkpoints

Implementation authorized by the Product Owner after planning PR #17 merged.
Baseline: `3f82b947d319d519cea6d25cecd7724bfc909672`;
tree: `145f8e270a01c1fe16752da1c40ca0433213b732`.
Branch: `phase/9-documents-catalog-sharing`.
The accepted [P9-0 execution lock](PHASE_9_P0_DISCOVERY_AND_EXECUTION_LOCK.md)
remains the scope and policy authority. This file records implementation evidence,
not a competing roadmap. No implementation merge or production access is authorized.

## A1 interface checkpoint

Retain `DocumentData`, the five existing Sales output routes and mPDF pipeline.
Existing constructor arguments and document/line keys remain compatible.
An optional final `presentation` array contains only safe current decorative options:
`logo` (verified local raster data URI or null), `footer` (bounded text or null),
and `show_qr` (boolean). It must never substitute stored party identity or terms.
QR content remains an explicitly supplied, validated share URL; rendering cannot
create a share. Locale selection affects presentation, not persisted source facts.

Additional string document keys may describe stored `base_currency_code`,
`exchange_rate`, `amount_base`, `payment_method`, `original_reference`, and
truthfully labelled current payment status. Receipt lines preserve original legs
with explicit `currency_code`, `payment_currency_code`, `payment_currency_amount`,
`base_currency_code`, and `settlement_base_value`. Any private later application
section is distinct and excluded from default/public receipt construction.
Templates format these exact strings; they never recalculate balances or FX.

Codex exclusively owns DTO/builders/render services, routes, permissions/schema,
share policy and final integration. AGY may modify only its pinned task allowlist.
Rendering/public share payloads must remain allowlisted and free of private IDs/costs.
Purchasing receives a private adapter/presentation boundary; no public cost DTO.

## Package acceptance state

| Package | State | Independently executed evidence |
|---|---|---|
| P9-A | Core/source checkpoint accepted; integrated browser evidence retained for F | Disposable MariaDB: 18 tests / 132 assertions; focused Larastan Level 6 passes; actual AR/EN five Sales PDFs and multi-page invoice rasters inspected |
| P9-B | Pending A1/A2 acceptance | None |
| P9-C | Pending A1/A2 acceptance | None |
| P9-D | Pending C integration | None |
| P9-E | Pending A/C integration | None |
| P9-F | Pending A–E acceptance | None |

Evidence and delegation receipts are intentionally ignored under
`.ai/delegations/phase9-implementation/`. Protected Owner artifacts and the
708-line original/copy roadmap remain outside implementation edits.

## P9-A checkpoint evidence

Five Sales routes remain compatible; bilingual View/PDF/Print/Download actions use
fresh source/output permission intersections and delivery reauthorization. Existing
canonical writers are unchanged. Receipt principal, payment consumption and base
settlement have separate currency labels; later applications are a private appendix.
Tests compare economic table fingerprints, posting identity, stored FX/tax/discount
and carton conversion snapshots after master changes. New logo uploads are bounded,
same-company trusted raster files. Current decorative branding never fills missing
historical identity. Quotation terms are defaults for future draft UI only.

AGY presentation/settings task started at `66259683f911ecbedb05432eabbeb0ec1525ef3d`,
conversation `6577f6d5-415f-45fe-986c-039eb8fa96b6`, relay exit 0. The installed
controller stalled before creating a workspace; Codex used the authorized isolated
worktree/manual collection fallback and the installed relay without bypass flags.
Codex reviewed all files, moved worker translations into existing `resources/lang`,
corrected audit/scoped test assertions, bound the private appendix to its explicit
DTO field, and independently executed the tests. Worker lint is not counted as lead QA.

Rendering caps include 500 lines, 1,000 statement source entries, 256 KiB total text,
4 MiB embedded assets, 50 pages and 15 MiB output. The 15-second post-render delivery
refusal is not hard CPU cancellation or a Hostinger performance guarantee. Actual
multi-page AR/EN samples fit the limits; final pathological resource measurements
remain part of F. Temporary files use request-owned private UUID directories with
cleanup in `finally`, files/directories retain the deployment permission policy.

Permission provisioning adds new capabilities to Owner; new Administrator defaults
exclude them. Existing-role upgrade preserves non-Owner grants. Protected grant and
role assignment actions require a current same-company Owner; default role titles
alone do not grant delegation authority. `documents:bootstrap` is an explicit local
or future approved release command; it has not been run against production.

## P9-B — private Purchasing outputs accepted internally

Lead review accepted the bounded AGY adapters after replacing duplicate line reads
with `PurchaseReadModel` / `PurchaseReturnReadModel`, removing missing-provenance
currency fallbacks and omitting unavailable historical warehouse/account identity.
Four private routes and matching detail-screen View / Print / PDF actions enforce
source/output intersections, cost redaction and fresh delivery authorization.
Vendor Statements use the existing canonical query; Vendor Payments retain the
exact `VendorFinancialRead` intersection and separate original/later allocations.
No financial, inventory, numbering or lifecycle writer changed.

AGY baseline `91a32942bba0fc96a7578e9843e2887e80748cef`, conversation
`525d1447-85ef-43a6-82de-bde7821331c0`. Six allowlisted new files collected;
worker-reported lint/PDF checks are not counted as lead acceptance. Independently
executed disposable MariaDB: **8 tests / 117 assertions passed**, including actual
AR/EN four-output PDF export, native ILS/USD/JOD, cross-currency settlement legs,
restricted quantity-only output and economic zero-write fingerprints. PDF raster
inspection verified readable shaping, layout and currency columns; browser actions
remain in the final integrated gate. Larastan Level 6 for the new B classes passed.

## P9-C — integration in progress

New issued financial shares use a neutral first GET/HEAD, deliberate CSRF POST,
15-minute exact-token/revision/password-bound unlock and fresh validity checks on
every HTML/JSON/print/PDF delivery. Ordinary shares default 30 days/max 365;
Statements default seven/max 30 and require a password. The nullable additive
MEDIUMTEXT issuance migration preserves genuine legacy rows and retained schema
on code rollback. No issued-content failure falls back to a live financial query.

Fixed canonical encrypted receipts/Statements, company-owned grant lifetime,
legacy labels, restricted management, canonical APP_URL links and smaller guest
PDF budgets are integrated. Lead-reviewed additional tests exposed and verified
the correction of guest quotation scoping. Worker-led regression execution:
**9 tests / 269 assertions passed**; lead independently ran the earlier combined
changed-domain gate (**23 tests / 292 assertions**). Final lead execution, actual
browser UX, simultaneous-process concurrency and release evidence remain pending.

## P9-D / P9-E

Catalog schema/security is lead-owned. Approved revisions and immutable publication
request receipts prevent a delayed retry from creating another revision. Frozen
same-company image references validate actual bytes and path containment; images
remain public marketing assets under accepted Option A. AGY owns isolated catalog
presentation/composer and barcode-label files at baseline `91a32942...`; lead
integration/acceptance is pending. The accepted roadmap and Owner files are unchanged.
