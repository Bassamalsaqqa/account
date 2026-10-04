# Small Trader Accounting — Agent Contract

## Sources of truth

Use the repository source and the relevant project documentation together.

- Product/accounting scope: `docs/SMALL_TRADER_ACCOUNTING_MASTER_SPEC_v1.0.md`
- Engineering/schema/posting boundaries: `docs/ENGINEERING_BLUEPRINT_v1.0.md`
- Implemented-domain decisions: `docs/adr/`
- Deployment work only: `docs/HOSTINGER_DEPLOYMENT_GUIDE.md`
- Approved UI references only when working on the corresponding screen: `references/ui/`

Read only the sections relevant to the task. Do not load both large source-of-truth documents in full for trivial or narrowly scoped work.

## Git and phase discipline

- Work from the exact baseline, branch, and scope named in the task. Stop and report unexpected branch/SHA drift.
- Never rewrite published history: no force push, rebase, squash, or amend of pushed commits unless the owner explicitly instructs it.
- Do not merge, deploy, access Hostinger, or change production data unless the task explicitly authorizes that action.
- Implement only the requested phase/correction. Do not opportunistically start later roadmap work.
- Keep the tracked working tree clean at handoff and report every deliberate untracked artifact.

## Accounting and inventory invariants

1. Use exact decimal primitives only. No PHP floats for money, FX, tax, discounts, quantities, valuation, costing, or allocations.
2. `AccountingPostingService` is the canonical writer for posting batches/lines. Do not write financial history directly.
3. Inventory quantity/value changes go through canonical inventory services and immutable stock movements. Do not mutate inventory caches as business truth.
4. All legs of one business event must commit atomically. Stock, accounting, numbering, provenance, and lifecycle state must not be independently committable.
5. Posted financial/stock history is immutable. Corrections use explicit return/reversal/void/cancel workflows.
6. Derived balances are not mutable authority.
7. Every company-owned operation requires active company context, server-side authorization, and strict same-company ownership. Never fall back to another company's configuration or records.
8. Sensitive cost/profit data must be server-redacted. CSS hiding is never authorization.
9. Default sale/purchase prices are suggestions copied to editable transaction lines; they are not historical truth.
10. Historical vendor/customer price information derives from actual posted transaction lines unless an explicitly reviewed read-only cache exists.
11. Expiry-tracked inventory uses traceable lots and FEFO where applicable.
12. Core financial/inventory side effects do not belong in Eloquent observers, Blade, or Livewire templates.

## Canonical business-event boundaries

For actions that create financial or stock history:

- Treat permissions, an open DB transaction, and source-code call-site scans as defense in depth, not proof of canonical authority.
- Look for alternate public/runtime entrypoints that could commit only one leg of the business event.
- Canonical effects that must be inseparable should use an action/domain boundary that cannot be reproduced by merely starting another transaction.
- Idempotency must compare the economic payload, not only the key.
- Concurrent submissions must converge to one canonical result.
- Any failure after numbering, inventory, accounting, or provenance begins must roll back every effect.

## Document snapshots and provenance

- Drafts may carry editable snapshots for preview.
- When a document becomes immutable at canonical posting, refresh mutable party/company identity snapshots at posting time unless the domain explicitly defines a different snapshot moment.
- After posting, historical snapshots/provenance must remain unchanged even if master data changes.
- Economic snapshots such as tax percentages, unit conversions, historical cost/value, and account routing follow their explicit domain policy and must never be reconstructed from mutable current configuration when historical correctness requires a stored snapshot.

## Review expectations

For PR/code review, do not stop at the happy path or at passing tests.

Check, in this order:

1. accounting/business correctness and exact-value invariants;
2. atomicity and partial-commit bypasses;
3. tenant isolation, authorization, and sensitive-data exposure;
4. idempotency, retries, stale models, and concurrency;
5. immutable history, snapshots, and provenance;
6. migrations/data preservation and rollback behavior;
7. deployment/runtime compatibility;
8. UI/RTL/accessibility regressions.

Inspect both the diff and the surrounding canonical implementation. Compare analogous mature flows when useful (for example Sales vs Purchasing), but do not copy behavior blindly when domain semantics differ.

A worker report or automated review is evidence, not acceptance. Verify claims against source/tests yourself.

## Database and deployment safety

- MariaDB/MySQL behavior is authoritative for integration, decimal, locking, FK, and migration tests.
- Never use `migrate:fresh`, `migrate:refresh`, `db:wipe`, or destructive reseeding against persistent data.
- Never edit an already-applied migration to change production history; add a forward migration.
- Shared-hosting compatibility is mandatory: no required Redis, Docker, permanent Node server, WebSockets, or Supervisor.
- Preserve deployment file-permission policy: directories 0755, files 0644, never 777.

## UI implementation

Stitch/Figma/screenshots are design references, not implementation architecture.

Translate approved patterns into the Laravel + Livewire + Tailwind system and preserve:

- Arabic RTL and English LTR;
- mobile/tablet/desktop responsiveness;
- keyboard/focus behavior;
- loading/empty/error/restricted states;
- truthful status;
- accessible contrast;
- clear currency/numeric presentation.

Do not serialize restricted financial values into the client.

## Verification and handoff

Run the checks relevant to the change. For substantial PHP/domain work, the normal gates include:

- focused tests;
- relevant regression suites;
- `composer qa`;
- production frontend build when frontend/runtime assets are affected;
- migration round-trip on disposable MariaDB when schema changes;
- Accounting, Inventory, and Sales reconciliations when economic behavior is affected.

At handoff report:

- baseline and resulting commit SHA;
- files/migrations changed;
- tests and assertion counts actually run;
- static analysis/build/audit/reconciliation results;
- deviations, limitations, and unresolved risks;
- confirmation of whether merge/deployment/production access occurred.
