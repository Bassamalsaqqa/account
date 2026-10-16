# Separate future phase: check endorsement and owner equity

Consultation only, based on Release A parent `f9ef4bd5d179ae524c697df7cb839aa94857b5f1`. No financial action, migration, account bootstrap or lifecycle change is implemented by this document. These proposals need independent source acceptance and Owner business-policy decisions before implementation.

## Customer checks endorsed to vendors

Current source supports incoming/outgoing checks through `ReceiveCheckAction`, `IssueCheckAction`, `TransitionCheckAction`, `MoneyEventScope`, `CheckCreation`, `CheckHistory` and source-specific financial adapters. `Check` uses guarded canonical history. ADR-0006 explicitly excludes endorsement/factoring. A received customer check is not equivalent to a newly issued company check: its drawer, customer provenance, custody and recourse remain relevant. Never rewrite `direction`, party snapshots or original receipt history to represent an endorsement.

Proposed boundary: a new canonical endorsement action owns one atomic business event, with company-first locking, fresh actor/party permissions, the original incoming check, selected vendor settlement/allocation, check-custody event, immutable provenance and canonical accounting batch. All effects require a capability scoped to that event; a public adapter cannot independently settle a vendor or change custody. A unique economic-payload receipt and check lock serialize retries, duplicate submissions and competing deposit/return/endorsement attempts. Compare currency, exact amount, date, vendor, allocations, original check and recourse terms on idempotent retry.

For a deliberately accepted **full-face endorsement that immediately settles vendor debt with recourse**, the conceptual entry is Dr vendor payable / Cr checks in hand at its stored carrying value, with an explicit FX policy for any difference. This is a proposal, not current system behavior. Do not book a bank movement, sales revenue, expense or stock event. Preserve original customer receipt and invoice allocations until policy determines a return/recourse event. Do not silently reuse vendor-payment checks: those adapters currently represent company-issued liabilities and their terminal transitions.

Dishonor must reference the exact endorsement and its frozen carrying/settlement legs. A typical recourse chain needs two separately evidenced obligations: the vendor can reclaim the amount from the company, and the company can reclaim the check amount from the customer. Whether those arise at dishonor, demand or an actual refund is a policy decision. Avoid both reversing the original receipt and separately debiting the customer for the same face amount. Reinstated vendor AP, customer AR and any intermediate returned-check/recourse asset must have one auditable bridge; downstream allocations and partial settlements need explicit unwind rules. Bank fees and penalties are separate authorized events, not edits to face value. Clearing an endorsed check records custody/lifecycle evidence without a second vendor settlement or fictitious bank entry.

Owner decisions required for that phase:

- Full checks only or partial/split endorsements; initial recommendation: full-face, one vendor, same currency only.
- When vendor debt is extinguished, whether endorsement is conditional and whether recourse remains.
- Evidence/date of dishonor and demand; permitted undo before physical handover; who can attest custody.
- Handling vendor refusal, already-deposited/cleared/returned checks, partial allocations and vendor overpayments.
- FX realization dates and ledger routing; original carrying values must never be reconstructed from current rates.

Acceptance should cover competing transitions, payload-conflicting retries, stale models, revoked access, tenant isolation, immutable party/check snapshots, all-leg rollback after every fault point, and expanded check/AP/AR/GL reconciliation. Historical pre-feature checks remain readable without inferred endorsements. Design any required forward migrations and retained-history rollback separately.

## Owner capital contributions and drawings

Current system account catalog includes `opening_balance_equity`; it is not an operational capital/drawings account. No dedicated owner contribution or drawing actions were found. Opening balances, customer receipts, vendor payments, payroll and ordinary expenses must not be reused to disguise owner transactions.

For an initial **cash/bank, same-currency, sole-owner** scope, propose two canonical actions owned by `MoneyEventScope` and posted through `AccountingPostingService`: contribution Dr cash/bank / Cr owner capital; drawing Dr owner drawings (contra-equity) / Cr cash/bank. Both affect cash and equity, neither sales nor operating profit. Owner loans are liabilities and need a distinct future policy/action. Distributions/dividends and multi-owner interests also need separate policy; a UI label cannot decide their accounting treatment.

Each action should freeze company/owner identity, money account and equity routing, exact native/base amount, FX quote if later allowed, date, purpose, permission, numbering and request payload. Company-first locking, exact strings, canonical capability ownership, economic-payload idempotency, concurrent convergence and all-leg atomicity are mandatory. Posted records are immutable; an explicitly authorized reversal posts an exact inverse with provenance rather than changing balances or deleting records. Server-side cost/financial restrictions extend to these screens, exports, search and reports.

Decide before implementation: sole owner versus named ownership interests; capital versus owner loan; equity account catalog/normal balances; fiscal closing treatment for drawings; allowable backdates/closed periods; currencies and FX; withdrawal limits and cash-negative policy; and whether stock/noncash assets are excluded (recommended initially). An asset contribution/withdrawal would need its own inventory/valuation/tax boundary and must not be added opportunistically.

Required later evidence: forward provisioning/migration preservation, account uniqueness per company, canonical money/GL reconciliation including both equity types, no P&L contamination, reversal/retry/concurrency/permissions tests, and historical/report compatibility. Neither feature is part of Mobile Release A or its deployment readiness.

## Separately outstanding operations

Off-host backup recovery, isolated restoration and real password-reset email verification remain outstanding. Local disposable fixtures and encrypted on-host release backups do not complete them.
