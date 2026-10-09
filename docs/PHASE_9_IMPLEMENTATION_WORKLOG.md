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
