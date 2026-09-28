# AGENTS.md
## Small Trader Accounting — AI Agent Rules

Read these files before product/domain/architecture work:

1. `SMALL_TRADER_ACCOUNTING_MASTER_SPEC_v1.0.md`
2. `ENGINEERING_BLUEPRINT_v1.0.md`

When UI reference assets are present, also inspect the approved Stitch/Figma/reference screen assets before implementing the corresponding view.

## Non-negotiable rules

1. Do not use PHP floats for money, FX, tax, discounts, valuation or payment allocation.
2. No financial writes outside the canonical accounting/posting services.
3. No inventory quantity mutation outside inventory services and immutable stock movements.
4. Every company-owned operation must use active company context and server-side authorization.
5. Never fall back to another company's account, product, customer, warehouse or configuration.
6. Financial + stock effects of one business event commit atomically in a database transaction.
7. Posted financial/stock history is not hard-deleted or silently edited. Use reversal/return/void/cancel flows.
8. No core financial or inventory side effects in Eloquent observers.
9. No business logic in Blade views or Livewire templates.
10. Controllers/Livewire components authorize, validate and call application/domain actions; they do not become domain services.
11. MySQL is authoritative for DB integration, locking, decimal and migration tests.
12. Arabic RTL and English LTR must remain functional.
13. Every implemented screen must be responsive on mobile, tablet and desktop.
14. Do not hide sensitive cost/profit values only with CSS; unauthorized values must not be serialized/rendered.
15. Shared-hosting compatibility is mandatory: no required Redis, Docker, permanent Node server, WebSockets or Supervisor.
16. Do not add dependencies without clear need and justification.
17. Do not create giant `Utility.php`, generic business `Helpers.php`, giant controllers or duplicate truth stores.
18. Default sale/purchase prices are suggestions copied onto editable transaction lines; no negotiation engine.
19. Historical vendor/customer price information comes from actual transaction lines unless a read-only cache is later justified.
20. Expiry-tracked products use traceable lots and FEFO by default.
21. Public share pages explicitly whitelist fields and never expose internal cost/vendor/audit information.
22. New domain behavior requires tests.
23. Run project QA and production frontend build before declaring a phase complete.
24. Implement only the requested phase. Do not opportunistically build future modules.

## UI implementation rule

Stitch/Figma/screenshots are design references, not implementation architecture.

Translate approved visual patterns into the Laravel + Livewire + Tailwind design system. Reuse semantic tokens/components/patterns. Do not create screen-specific one-off CSS when a reusable pattern is appropriate.

Preserve:
- responsive transformations;
- Arabic RTL;
- keyboard/focus behavior;
- loading/empty/error/restricted states;
- truthful status;
- accessible contrast;
- clear currency and numeric presentation.

## Completion report

At the end of each task, report:
- files changed;
- migrations;
- tests added/changed;
- commands run;
- QA/test/build results;
- deviations from the master specification/blueprint;
- remaining risks or deferred items.