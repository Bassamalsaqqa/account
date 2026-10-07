# ADR 0006: Money, Transfers, Cross-Currency Settlement and Checks

Status: Phase 6 Complete / Accepted Source / Merged / Deployed. Phase 6 accepted source `3d32c80a2b437c8e7245e7c5b4f6ee16298ca172` merged through PR #14 on 2026-10-07 at `51adf805a85de72c3f15c764bc2eac7786f4efd1`. Deployed to production on 2026-10-07. Phase 7 remains unstarted.

Baseline: `2c878a18bb8f82b83c83b007d99b4789ee3f84b3` (Phase 5 complete).

## Ledger authority and read models

Cash/Bank balances and movements derive from immutable PostingLines against each
MoneyAccount's exact child LedgerAccount. Native currencies are kept separate.
Base balances remain available when legacy foreign-currency opening lines lack
native transaction metadata; native balances then explicitly report unavailable.
Recognized same-account base residuals do not invent native principal. Movement
running totals include preceding history before pagination. Accounts, source
links, recent activity and operational registers remain company/permission scoped.
There is no stored balance, price cache or insufficient-funds/overdraft rule.

Money activity and movement rows enforce the original source's financial-read
authority, including reversals. Vendor Payment rows require VendorFinancialRead;
outgoing Check rows require Check view plus purchasing cost view. Filtering precedes
limits/pagination. If an account has hidden movements, per-row running base/native
values are unavailable rather than disclosing hidden effects or fabricating a
visible-only balance. Authorized aggregate account balances remain ledger-derived.

## Canonical Money event authority

Only the final concrete Transfer posting/reversal and Check receipt/issue/transition
actions can enter MoneyEventScope. Opaque, non-serializable capabilities bind the
company, authenticated actor/context, connection, PDO, exact live outer transaction
and executing action. Exact prepared records, posting commands and reversals are
authorized once. Scope authority expires in finally and after transaction rollback.
Models cannot independently create/edit/delete Money history; posting remains
exclusively through AccountingPostingService and canonical AccountingReversalService.
Company-first locks, deterministic resource locks, request hashes and numbering
are part of one atomic event. Audit payloads contain identifiers/lifecycle only.

## Transfers

Transfers persist both accounts, currencies, amounts, historical rates, exact base
values, frozen account identity, TRF number, intent identity and posted/reversed
lifecycle. Same-currency transfers require equal amounts and one rate, producing
equal debit/credit base values and no FX. Base currency requires rate one.

Cross-currency transfer gain is destination base minus source base:

- positive: credit FX Gain;
- negative: debit FX Loss.

Destination is debited and source credited at their respective actual persisted
amount/rate. TRF uses the transfer business-date year. Reversal uses exact canonical
inverse lines and cannot precede the original business date. Negative derived
account balances are allowed under the existing product policy.

## Dual-currency Customer/Vendor allocation

`allocated_amount` retains document-currency principal relief. The additive
`payment_currency_amount` stores payment/advance currency consumed. Legacy
same-currency rows are backfilled identically; their request hashes are unchanged.
Allocation version 1 preserves legacy request shape, while explicit dual-amount
requests use version 2. No live FX table or implicit cross-rate is authority.

Unallocated principal is payment amount minus active payment-currency consumption.
Unallocated base is payment base minus active settlement base. Final settlement
segments use the exact original payment residual. Historical AR/AP book relief
continues to use persisted historical chronology and exact final document residual.
Initial posting reconstructs initial allocations only; later applications retain
separate immutable event history and never move Cash/Bank again.

For `delta = settlement base - historical book relief`:

- Customer: positive is gain, negative is loss;
- Vendor: positive is loss, negative is gain.

Cross-currency statement/balance conversion legs restore consumed payment-currency
principal and relieve document-currency principal once, including historical
application/reversal business dates. Statement aging uses the same as-of cutoff.
User entry exposes both amounts explicitly. Auto Allocate remains same-currency
only, avoiding a hidden accepted conversion rate.

## Incoming Checks

One authoritative FK, `CustomerPayment.check_id`, links the instrument. Check-method
payments have null MoneyAccount; the inverse relationship is derived. Receiving a
Check uses the accepted receipt engine: debit Checks in Hand, credit AR/Customer
credit with historical allocation FX. Currency/rate/principal and party identity
are frozen at receipt. The initial Check event has no separate GL batch.

Permitted lifecycle: received -> deposited -> cleared; return/cancellation is
available before clearing, and return is available after clearing. Deposit records
custody and the intended same-currency Bank without GL. Clearing cannot precede
receipt, earlier activity or due date. It debits Bank at actual clearing rate and
credits Checks in Hand at receipt carrying value; difference is realized FX.

Return after clear reverses clearance first, then dependent credit applications
newest-first, then the original receipt. Pre-clear cancellation/return reverses
applications and receipt. All inversions and lifecycle completion are atomic.
Cancellation after clearance is forbidden.

## Outgoing Checks

`VendorPayment.check_id` is the sole payment relationship. Issue replaces AP with
Checks Issued through the existing payment/advance engine; Bank does not move.
The drawn Bank is frozen, same-company and same Check currency.

Permitted lifecycle: issued -> cleared, or issued -> returned/cancelled. Clearing
debits Checks Issued at issue carrying value and credits Bank at actual clearing
rate; opposite cash direction determines FX. A cleared outgoing Check cannot
casually return/cancel. Pre-clear return/cancellation reverses dependent advance
applications before the original Vendor Payment and restores AP/advance positions.

Check events are append-only, recording previous/next state, business date, actor,
request identity, Bank/rate when relevant and canonical original/inverse provenance.
Current status is a controlled projection, never the only economic history.

## Reconciliation boundary

Money reconciliation validates account routing, currency/company metadata,
Transfer/Check exact history, Customer receipt/application history and both
directions of canonical source ownership. Accounting, Inventory, Sales and Payables
reconciliation retain their accepted responsibilities. Legitimate opening balances
remain valid: no whole Cash/Bank/AP control balance is forced equal to one source
subledger. Orphan/duplicate canonical source batches fail closed.

## Authorization and upgrade

New permissions: `money.transfer.view/create/reverse`, `money.check.view`,
`money.check.incoming.manage`, `money.check.outgoing.manage`. Legacy
`money.check.manage` remains catalogued but does not implicitly grant directional
authority. Outgoing Check financial data additionally requires purchasing cost
authority; receipt/payment create and reverse permissions remain independently
required. Stale Livewire requests reauthorize and financial data is server-redacted.

New-company roles receive the defined least-privilege defaults. `money:bootstrap`
creates missing catalog/TRF configuration and grants Owner the static catalog;
existing customized non-owner grants and sequence counters are preserved.

## Migration and hosting safety

Three additive migrations create transfers, dual allocation provenance, and
Checks/events with nullable payment MoneyAccount links. Backfill first verifies
legacy tenant/currency coherence. Rollback refuses to discard new Money history or
version-2 allocations. Disposable MariaDB forward/rollback/reapply preservation is
required; production remains forward-only. No Redis, Node server, queues daemon,
Docker or other shared-hosting requirement is introduced.

## Explicit limits

Private Check images are optional and not implemented in this candidate. No OCR,
endorsement/factoring, bank feeds, bank-statement matching, automatic live FX,
card processing or new overdraft policy. Check settlement Bank currency must equal
instrument currency. Operational Money views are not the Phase 8 reporting suite.
No Phase 7 expenses, payroll, employees or landed cost is included. Phase 7
remains unstarted.
