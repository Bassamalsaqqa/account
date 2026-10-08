# ADR 0007 — Expenses, payroll-lite, employee advances and landed cost

Status: COMPLETE / ACCEPTED / MERGED / AWAITING DEPLOYMENT.
Baseline: `a98e8e492b6cbef65593cf16a3108fd94d5997ed` (Phase 6 complete).
Accepted source: `e8a5dee856bc06da48b1313f8776d723af309b5e`.
PR: [#15](https://github.com/Bassamalsaqqa/account/pull/15), merged on 2026-10-08 through normal merge commit `c07559c92816f838532e4b9d1c1537065f50ad5c`.
Accepted and merged tree: `6d783943249ce3f085196ae67adf82ce91055ed8` (exact equality).
Deployment: NOT YET DEPLOYED. Separate Product Owner deployment authorization is required.
Phase 8: PLANNED / UNSTARTED.

## Accepted correction checkpoints

All three corrections received independent architect source acceptance before merge:

1. **Correction 01** (`e1f08198e67bf0ccbee085e7352206ed32e34423`): inactive/archived Employees remain eligible to settle recognized salary obligations and retain authorized historical readback; new Advances and Salary Entries remain blocked after retirement. Purchase acquisition amounts use the stored base currency. Landed Cost chronology is enforced before allocation mutations, and backwards Purchase-date edits roll back atomically when they invalidate an allocation.
2. **Correction 02** (`20cded4189e83c36eae46cbb86e3e00300ef9a0a`): every new permanent Expense upload is either owned by the canonical Expense or removed, including failed submissions and Cash/Bank/Check retries. Landed Cost classification follows fresh capability checks. Expense Index uses the frozen bilingual category snapshot while preserving category-ID filters and financial redaction.
3. **Correction 03** (`e8a5dee856bc06da48b1313f8776d723af309b5e`): Check Index and Detail share snapshot-only party formatting, preserving Customer/Vendor/Employee/payee identity across master-data changes, bilingual fallbacks and existing source authorization.

## Authority and immutable history

`AccountingPostingService` remains the only GL writer. Each of the eight Phase 7 post/reverse actions owns a request-local `Phase7EventScope`, bound to the exact authenticated actor, Company, PDO connection and database transaction. Concrete final action ownership, one-use exact record completion and command/reversal identity guard the four new source namespaces. Starting an arbitrary transaction does not grant authority. Source/allocation creation and lifecycle updates cannot be reproduced through public model saves or quiet saves. Financial sources cannot be deleted, including quiet deletion.

Company is locked first. Sequence, source, allocations, GL, Check linkage and audit commit together. Idempotency compares the ordered normalized economic payload. `Phase7History` reconstructs original commands from immutable values, validates exact canonical original/inverse batches and historical allocation consumption, and runs on retries, reversals and reconciliation. Current master retirement does not rewrite posted identity or routing. No mutable money, employee, payable or landed-cost balances are introduced.

## Expenses

Ordinary expenses are immediately paid. Dr the category's same-company, debit-normal operating-expense child ledger; Cr Cash/Bank or Checks Issued. Attaching a Vendor is informational and never creates AP.

Explicit `landed_cost` expenses instead Dr `landed_cost_clearing`; Cr settlement. Fuel/transport/delivery categories do not imply capitalization. Category identity and ledger routing freeze at posting. New selections require active same-company account, exact ledger structure and enabled currency. Historical reconstruction includes retired MoneyAccounts.

Reversal uses the exact canonical inverse on/after the source business date. A pending draft plan becomes `cancelled`; cancelled nonfinancial plan metadata may be removed with audit when draft lines are replaced. Locked capitalization cannot be reversed. Check-paid sources reverse only through canonical Check terminal transitions.

Attachments use the private local disk beneath `expenses/{company_id}/`; accepted types are PDF/JPEG/PNG up to 10 MiB. Domain metadata/path validation prevents cross-company/private-folder escape. Authorized downloads require fresh expense view authority and additionally cost view for landed costs. A failed new expense submission removes its newly stored file. Attachments are documentary metadata, not a separate financial source.

## Employees, advances and salaries

Employee contact identity is separately readable from salary defaults. Create/update actions freshly authorize salary fields. A user managing identity without salary read authority receives no salary defaults and creates a zero suggestion in Company base currency. Default salary/currency are suggestions only; posting snapshots employee identity and accepted economic values.

Advance: Dr `employee_advances`; Cr Cash/Bank or Checks Issued. Principal and historical carrying base are derived from active allocations. A consumed advance cannot reverse until dependent salary entries reverse. Parent reversal cannot precede those dependent inverse business dates.

Salary entry fields and formula:

```text
earned_salary = base_salary + bonus - deduction
advance_applied = sum(selected advance principal)
net_payable = earned_salary - advance_applied
```

Values must be nonnegative and exact at currency minor units (ILS/USD 2, JOD 3). Zero base/default and zero-effect entries are supported; a zero-effect entry owns no GL batch. Active periods for the same employee cannot overlap. Duplicate advance IDs are rejected.

Dr `salary_expense` at earned salary's recognition-date base value. Cr `employee_advances` at consumed historical carrying value. Cr `salary_payable` for remaining recognition-date base value. The difference between consumed advance book value and salary relief is realized FX: salary relief greater than advance book is a gain; smaller is a loss (the advance is an asset). Exact final carrying residual and bounded base partition avoid rounding drift or negative payable base. There is no second Cash/Bank posting on advance application.

Salary payment (`SLP`) allocates to one or more posted entries of the same employee, Company and currency. No unallocated salary payment or overpayment; use an advance for prepayment. Duplicate entry IDs are rejected. Dr `salary_payable` at historical book relief; Cr Cash/Bank or Checks Issued at payment-date settlement value. Settlement above payable book is FX loss; below is gain. The final allocation consumes the exact remaining book amount.

Reversing a payment restores payable availability through inverse accounting and allocation metadata. Reversing an entry requires payments reversed first and cannot precede their inverse business dates. Reversal reasons are at most 500 characters, validated before effects, never truncated.

## Check integration

The existing Phase 6 Check engine and append-only events remain authoritative. `CheckFinancialSourceResolver` resolves exactly one supported source: CustomerPayment, VendorPayment, Expense, EmployeeAdvance or SalaryPayment. Payment/source owns the canonical Check FK; there is no independently mutable duplicate relationship. Old Customer/Vendor ordered request payloads and hashes remain compatible.

New outgoing source issue posts against Checks Issued. Clearance uses the frozen Bank and historical carrying value, including subsequently retired/deleted Bank, disabled currency or inactive ledger with coherent structural provenance. Current eligibility remains mandatory at initial selection. Terminal return/cancel applies source dependency blockers and inverse history atomically; terminal notes are max 500, custody/clearance notes max 2000. Source-specific financial permission applies to read/clear/terminal paths.

## Draft landed cost and Purchase convergence

Allocation is allowed only for coherent posted, unreversed landed expenses and Draft Purchases, with expense date no later than Purchase date. One expense belongs to one Purchase. Only same-company stock lines participate; distinct line IDs and exact full expense-base allocation are required.

Methods: value (commercial acquisition value before landed costs), quantity (`quantity_base`), manual. Stable line order, bounded rounded parts and final exact residual preserve the sum. Multiple freight expenses may contribute to one Purchase.

Draft line replacement recomputes automatic plans. Manual replacement explicitly supplies `landed_cost_manual_allocations[expense_id][new_line_number]`; stable new line numbers map to new persisted IDs after replacement. Missing/invalid replacement rolls back the entire edit. Cancelled draft plans do not block editing.

Canonical Purchase posting audits ownership and totals before stock/GL effects; freezes `purchase_lines.landed_cost_allocated_base`; records acquisition value including landed cost in receipts/lots; and completes allocations as `locked` through Purchase posting authority. Locked plans and frozen line values remain immutable. The additional base-only accounting is Dr Inventory / Cr Landed Cost Clearing. Commercial Purchase amount, Vendor AP and price-history metric remain unchanged.

Purchase Returns preserve accepted `D = I - H`: AP relief `H` is commercial; historical acquisition removal `I` includes landed cost; valuation difference absorbs `D`. No automatic freight refund through AP and no retroactive posted-Purchase revaluation.

## Foundation and permissions

New system assets: `employee_advances` (1202), `landed_cost_clearing` (1501). Existing `salary_expense` (5202) and `salary_payable` (2103) are reused. Operating expense children 5203–5214 and 13 default categories are provisioned through the idempotent catalog infrastructure. Reserved code conflicts fail closed; no speculative code allocator.

Sequences: `expense` EXP, `employee_advance` ADV, `salary_entry` SAL, `salary_payment` SLP. Bootstrap ensures catalog/accounts/categories/sequences without financial records or number consumption. Existing customized non-owner grants remain unchanged; Owner receives missing catalog authority.

Permissions:

```text
money.expense.view / manage / reverse
employees.view / manage
payroll.salary.view / post / pay / reverse
payroll.advance.manage
purchasing.landed_cost.manage
```

Landed financial read/manage additionally requires purchasing cost view. Salary/default data is excluded before queries/serialization when unauthorized. Fresh decisions apply on every Livewire request. Money/Check rows, recent limits and overview counts follow original economic source, including reversals; hidden history makes per-row running balances unavailable rather than false or inferentially revealing. Cash/Bank aggregate access remains unchanged.

## Reconciliation and migrations

`Phase7ReconciliationService` performs exact source-to-batch and batch-to-source ownership checks, exact inverse/request/FX/allocation reconstruction, active residual/period checks, and landed/Purchase receipt/GL integrity. It does not equate an entire control account to a subsystem when legitimate unrelated accounting can exist.

Three additive migrations create nine tables and default-zero `purchase_lines.landed_cost_allocated_base`. Every Phase 7 `down()` performs a shared whole-phase history preflight before any DDL, so history in a later dependency cannot cause an earlier migration to partially drop schema. Persistent data is never reset. Disposable MariaDB preservation/empty-history round-trip and populated-history refusal are required evidence.

## Scope

Payroll-lite only. No attendance, leave, statutory payroll, recurring automation, approvals, retroactive landed revaluation, Phase 8 reporting or Phase 9 documents. Independent source acceptance and normal Git integration are complete. Deployment remains pending separate authorization; Phase 8 remains planned and unstarted.
