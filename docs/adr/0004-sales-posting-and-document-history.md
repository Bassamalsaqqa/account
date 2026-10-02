# ADR 0004 - Sales posting and document history

Status: Implemented on `phase/4-sales`; pending independent acceptance. Not merged or deployed.

## Decisions

* Document-currency line money rounds HALF_UP to currency minor units (ILS/USD 2, JOD 3); normalized storage is DECIMAL(20,6). Aggregate these rounded lines before converting document totals.
* Base accounting values are exact HALF_UP at six decimals, matching accepted Phase 2 PostingLine `transaction amount * exchange rate` validation. Base minor units govern display, not internal GL valuation. No PHP float input or calculations.
* When a component/cumulative allocation differs from separately rounded conversion, post the converted amount with complete truthful transaction metadata and an explicit base-only residual on the same ledger account. Do not weaken PostingLine validation or label a rounding residual as FX income.
* Company-first locks serialize posting, numbering, receipts and related returns. Revalidate authenticated actor, matching active company, active membership and operation permission under those locks. Sequence increments roll back with failed posting.
* Receipt request identity includes company/actor, exact decimal strings, canonical date, method/account/customer, explicit document-locale intent, normalized optional text and invoice allocations aggregated by invoice then sorted by ID. A caller-owned stable key survives form retries. Resolve retries under the company lock before inspecting mutable outstanding/FX state.
* Receivable relief is cumulative historical book value less actual earlier credits. Settlement is cumulative receipt-rate value; final allocation takes its exact residual. Same-currency allocations only; FX arises from invoice versus settlement rate, not cross-currency allocation. Checks remain Phase 6.
* Historical stock compensation is only `sale_return` from a linked Sales Return or Invoice Void. It requires the original immutable sale movement and allocation. Restored COGS is cumulative original-value entitlement less prior actual restoration; final restoration consumes the exact original residual. Ordinary inventory operations cannot supply an independent total-value override. Request hashes include this provenance and exact value intent.
* Repeated original-line return inputs aggregate before caps. Partial/final discount, tax and credit use cumulative entitlement. Tax reversal uses the invoice's account snapshot. Return void is rejected atomically when current valuation would not remove the same original restored value per product; no successful partial stock/GL reversal.
* Only named authenticated canonical model completion methods change posted lifecycle metadata; no public permission-bypass flag. Child financial/lot allocations are immutable and cannot be appended after posting.
* Quotations are editable only as drafts. Sent -> draft is an explicit audited transition. Sent -> accepted/rejected; sent/accepted -> expired; draft/sent/accepted -> converted is an explicit authorized conversion to one linked invoice draft. Converted/closed histories are stable.
* PDF, print and public share use an explicit whitelisted DocumentData DTO. Posted party and line identity comes from snapshots. Document locale controls labels independently of UI locale. Currency-aware formatting preserves JOD thousandths and six-decimal quantities. Cost/COGS/profit never enters a public DTO.
* Public tokens use SHA-256 lookup plus encrypted recovery; password/expiry/revocation and current source eligibility apply on resolution. Guest statements use a narrow company-constrained token-authorized read; normal statement reads require active tenancy.
* System Sales bootstrap rejects ambient company context, preserves existing non-owner role customizations, and idempotently provisions only sequences and a zero-balance base-currency cash account. Owner receives the static catalog. No customers, taxes, inventory or financial activity is fabricated.

## Verification

Normal-suite regressions cover the above boundaries. Local MariaDB is authoritative. Reconciliation reads actual immutable stock/GL provenance and amount/metadata relationships and never repairs financial history. Browser/PDF evidence remains temporary local worker-run evidence under `.ai/delegations/20261002-phase4-sales-correction-02/`.

Draft posting rechecks exact line/header totals and base-quantity conversions before inventory or GL writes. Explicit receipt document locale is validated against enabled company languages independently of the UI.

Customer master uses canonical bilingual business/address fields and active/inactive status. Form compatibility aliases do not persist duplicate columns. Customer balances group transaction currencies and include unallocated receipt credits. Credit limits are labeled in company base currency and produce advisory warnings from actual book AR plus projected invoice value; authorized posting remains allowed.
