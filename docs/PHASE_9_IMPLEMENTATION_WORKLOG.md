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
| P9-A | In progress: A1 interface established; presentation/settings delegation next | No runtime checks yet |
| P9-B | Pending A1/A2 acceptance | None |
| P9-C | Pending A1/A2 acceptance | None |
| P9-D | Pending C integration | None |
| P9-E | Pending A/C integration | None |
| P9-F | Pending A–E acceptance | None |

Evidence and delegation receipts are intentionally ignored under
`.ai/delegations/phase9-implementation/`. Protected Owner artifacts and the
708-line original/copy roadmap remain outside implementation edits.
