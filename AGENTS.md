# Small Trader Accounting — Agent Contract

## Sources of truth

Use the repository source and the relevant project documentation together:

| Area | Authoritative Document |
|---|---|
| **Product & Business Rules** | `docs/SMALL_TRADER_ACCOUNTING_MASTER_SPEC_v1.0.md` |
| **Architecture, Schema & Posting** | `docs/ENGINEERING_BLUEPRINT_v1.0.md` |
| **Implemented Architectural Decisions** | `docs/adr/` (ADR 0002 through ADR 0013) |
| **Setup, Portability & Operations** | `docs/SETUP_AND_OPERATIONS_GUIDE.md` |
| **Interface & Everyday Workflow Rules** | `docs/UI_WORKFLOW_RULES.md` |
| **Deployment Procedures** | `docs/HOSTINGER_DEPLOYMENT_GUIDE.md` |
| **Controlled Release Checklist** | `docs/CONTROLLED_RELEASE_CHECKLIST.md` |
| **Approved UI Reference Wireframes** | `references/ui/` (when working on the corresponding screen) |

Read only the sections relevant to the task. Do not load large source-of-truth documents in full for trivial or narrowly scoped work.

## Durable Agent Routing & UI Implementation

- **Framework Stack**: The application is built on Laravel 13, Livewire 4, Tailwind CSS 4, and Alpine.js. Do NOT introduce a second application framework (no React, Vue, or separate frontend daemons).
- **Design Guidance**: Apply BoardUI and shadcn design principles (compact layout density, 44px mobile touch targets, semantic subtle colored surfaces, accessible dialogs, and clean tabular numbers). When agent skills (`boardui`, `shadcn`, `impeccable`) are available in the local environment, consult their patterns for component architecture and interaction design, adapting them natively into the Laravel Blade/Livewire/Tailwind shared components (`resources/views/components/`). These skills are optional portable guidance, never hard machine-specific dependencies or mandatory paths.
- **Shared Component First**: Update existing shared components first (`x-input`, `x-button`, `x-table`, `x-dialog-shell`, etc.). Never fork duplicate implementations.
- **AGY Opt-In**: Codex works directly by default. Delegate to AGY only upon the Owner's literal `$agy-manual` invocation in the existing checkout with a single writer, or direct explicit owner delegation authorization permitting scoped AGY isolated worktrees with a single writer. No automatic/headless/relay/controller/retry dispatches. Codex pauses during AGY editing and independently reviews its actual diff and test execution on handback.

## Git and phase discipline

- Work from the exact baseline, branch, and scope named in the task. Stop and report unexpected branch/SHA drift.
- Never rewrite published history: no force push, rebase, squash, or amend of pushed commits unless the owner explicitly instructs it.
- Do not merge, deploy, access Hostinger, or change production data unless the task explicitly authorizes that action.
- Implement only the requested phase/correction. Do not opportunistically start later roadmap work.
- Keep the tracked working tree clean at handoff and report every deliberate untracked artifact.

## Accounting and inventory invariants

1. **Exact decimal arithmetic**: Use exact decimal primitives only (`Brick\Math\BigDecimal`). Zero PHP binary float arithmetic for money, FX, tax, discounts, quantities, valuation, costing, or allocations. Money amounts use 2 decimal places for human display and currency inputs; exact economic storage, FX, unit conversions, and quantities use exact arbitrary-precision decimals (`BigDecimal`), never silently rounding or truncating historical economic data. Quantities trim trailing display zeros without silently truncating fractional quantities.
2. **Canonical posting service**: `AccountingPostingService` is the canonical writer for posting batches/lines. Do not write financial history directly.
3. **Canonical inventory movements**: Inventory quantity/value changes go through canonical inventory services and immutable stock movements. Do not mutate inventory caches as business truth.
4. **Atomic business events**: All legs of one business event must commit atomically. Stock, accounting, numbering, provenance, and lifecycle state must not be independently committable.
5. **Immutable posted history**: Posted financial/stock history is immutable. Corrections use explicit return/reversal/void/cancel workflows.
6. **Derived balances**: Derived balances are not mutable authority.
7. **Strict tenant isolation**: Every company-owned operation requires active company context, server-side authorization, and strict same-company ownership. Never fall back to another company's configuration or records.
8. **Server-side redaction**: Sensitive cost/profit data must be server-redacted. CSS hiding is never authorization.
9. **Price suggestions**: Default sale/purchase prices are suggestions copied to editable transaction lines; they are not historical truth.
10. **Historical pricing**: Historical vendor/customer price information derives from actual posted transaction lines unless an explicitly reviewed read-only cache exists.
11. **Traceable lots & FEFO**: Expiry-tracked inventory uses traceable lots and FEFO where applicable.
12. **No core side effects in views/observers**: Core financial/inventory side effects do not belong in Eloquent observers, Blade, or Livewire templates.
13. **Zero-value transactions**: Legitimate zero-value items follow explicit domain policy (ADR 0013: valid zero-cost purchases create stock receipts and numbering without empty journals); no generic zero-value bypass.
14. **Security & Impersonation**: Authenticated, audited operator impersonation requires explicit platform grant with clean expiry; no compulsory MFA; subscription enforcement remains OFF globally and per-company until separate owner authorization.
15. **AI Assistant boundaries**: Built-in assistant answers permitted questions, executes authorized read-only reports, and drafts editable UNSAVED forms. Bounded read optimization is released; demand-mounted AI, client bundle splitting, paired latency, and 100-line virtualized draft qualification remain on HOLD.

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
- Keep Hostinger inode usage bounded: stage and replace complete manifest-verified build directories; never overlay hashed chunks. Production builds must contain no source maps.
- Keep development files, source repositories, archives and backups outside the public document root. Preserve verified off-host recovery before pruning old on-host copies; remove owned temporary deployment staging after verification.
- Preserve the currently configured and deployed subdomain/document root. Do not move directories, change DNS/hosting configuration, or introduce a symlinked public root as incidental cleanup. Use this project's reviewed Hostinger release procedure, not generic dist/public_html examples.

## UI implementation

- Follow `docs/UI_WORKFLOW_RULES.md` for every tenant/control-plane screen and shared workflow. Owner UX decisions apply to analogous pages, not only the example URL. Update shared components first; do not fork duplicate implementations.

Stitch/Figma/screenshots are design references, not implementation architecture.

Translate approved patterns into the Laravel + Livewire + Tailwind system and preserve:

- Arabic RTL and English LTR;
- mobile/tablet/desktop responsiveness (compact desktop density, 44px mobile touch targets);
- keyboard/focus behavior and short field errors;
- loading/empty/error/restricted states;
- truthful status;
- accessible contrast;
- clear currency/numeric presentation (`tabular-nums` with isolated LTR).

Do not serialize restricted financial values into the client.

## Verification and handoff

Run the checks relevant to the change. For substantial PHP/domain work, the normal gates include:

- focused tests;
- relevant regression suites;
- `composer qa` (or pint, phpstan, and isolated phpunit);
- production frontend build (`npm run build`) when frontend/runtime assets are affected;
- migration round-trip on disposable MariaDB when schema changes;
- Accounting, Inventory, Sales, Payables, Money, and Phase7 reconciliations when economic behavior is affected.

At handoff report:

- baseline and resulting commit SHA;
- files/migrations changed;
- tests and assertion counts actually run;
- static analysis/build/audit/reconciliation results;
- deviations, limitations, and unresolved risks;
- confirmation of whether merge/deployment/production access occurred.

## Reproducibility and portability invariant

Every completed change must have reproducible source/setup, accurate Git/QA state, bounded owned-artifact cleanup, and explicit private-data transfer exceptions. See `docs/SETUP_AND_OPERATIONS_GUIDE.md` for the authoritative setup, build, test, and fresh-clone instructions. Never rely on untracked runtime code, machine-specific paths, or global workstation caches.
