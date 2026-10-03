# ADR 0005: Purchasing, Vendors and Accounts Payable

Status: Phase 5A accepted; Phase 5B draft decisions proposed for independent review.

## Context and checkpoints

Purchasing extends the accepted company-scoped Sales, accounting and inventory
boundaries. Phase 5A implements Vendor master, purchasing configuration, Input Tax
configuration, and sequence definitions only. Phase 5B introduces Purchase drafts;
subsequent reviewed checkpoints introduce posting/returns/voids, Cash/Bank Vendor
payments and read models. This ADR records future constraints, not implemented
financial functionality.

## Phase 5A decisions

- Vendors use public ULIDs, immutable company ownership, normalized optional codes
  unique per company, bilingual identity/contact fields and active/inactive status.
  Soft deletion retains master data. Future transaction references must restrict
  deletion; history remains visible after inactivation. No stored AP balance,
  purchase totals or last-price authority.
- Canonical Vendor and purchasing-settings services lock Company first and reuse
  the accepted actor guard to check authentication, current company, active
  membership and fresh team permissions. Vendor reads and mutations reauthorize,
  including stale Livewire components. vendors.view and vendors.manage remain
  distinct; vendors.statement.view is reserved.
- company_purchase_settings has nullable bounded payment terms (0–3650 days),
  nullable same-company active receiving warehouse and duplicate vendor invoice
  warning default true. These are defaults/configuration, not posting policy.
- settings.purchases.manage is static. New Owner/Administrator receive the full
  catalog; Manager receives it consistent with existing settings permissions.
  Purchasing is not automatically granted settings or cost access. Existing-company
  upgrade adds the catalog to Owner only and leaves every non-owner/custom role
  untouched. The Owner invariant is deliberate; there is no global role-name bypass.
- purchasing:bootstrap {companyPublicId?} --all is an explicit system-only,
  idempotent configuration upgrade requiring no ambient company context. It creates
  only missing purchase settings and purchase sequence definitions and updates the
  Owner catalog. It does not call Sales bootstrap, create Vendors/transactions or
  reset Sales numbering. New-company creation uses the same configuration action.
  Authorized Settings access can lazily create a missing settings row.
- Supported sequence types are purchase / PUR, purchase_return / PRT,
  vendor_payment / VPM. Four-digit yearly defaults match accepted Sales
  presentation. Provisioning never consumes a number; existing types/configuration
  and Sales sequence counters are preserved, including custom never-reset sequences.
  The generic service retains its accepted Sales namespace.
- The existing tax table/action gains optional Purchase/Input Tax configuration.
  It accepts only active same-company non-control asset/debit tax_input or its
  direct child, mirroring accepted Output Tax hierarchy rules. Null remains valid.
  Sales/output validation and posted account snapshots remain unchanged.
  Percentages remain exact percentage points: 16.000000 = 16%.

## Future financial boundaries (not implemented in 5A)

- Purchase lifecycle is draft|posted|void. Paid/partially-paid and AP outstanding
  are derived from valid activity/allocations, never independently mutable flags.
  Drafts have no final number, accounting or inventory effect. Final numbers are
  assigned at posting under sequence locks; posted numbers are never reused.
- Initial Purchase posting supports stock products only. Non-stock/service Vendor
  Bills belong to the later Expense capability; landed-cost allocation is deferred.
  One transaction coordinates source validation, canonical inventory movements and
  canonical balanced accounting posting. There are no manual cache/ledger writes.
- Configured Input Tax is recoverable; absent configuration means nonrecoverable
  tax capitalized into stock cost. Never use another company's account. Exact
  BigDecimal line/document rounding and posted rate/account snapshots apply.
- Cash/Bank Vendor payments use actual same-currency MoneyAccounts. Outgoing
  Checks and cross-currency allocation are Phase 6. Same foreign currency with
  changed rates still requires exact historical AP relief and realized FX.
- Vendor advances may remain unallocated and later apply through append-only
  idempotent application events, without paying Cash/Bank a second time. Posted
  payments and existing allocations remain immutable; reversals coherently undo
  dependent application adjustments in the same transaction.
- Posted Purchase/Return/payment history is immutable. Corrections use explicit
  return, reversal or coherent controlled void; no silent source/history rewrite.
- Purchase Returns must retain original receipt/lot/line provenance, enforce
  cumulative return limits and use attributable historical receipt cost with
  deterministic residual handling. If current valuation cannot support a coherent
  reversal, fail closed instead of using an unrelated current average.
- Vendor prices derive from actual posted Purchase lines, not a duplicated price
  cache or negotiation engine. Vendor AP and inventory value must reconcile exactly
  to canonical GL and immutable stock history. Reconciliation is read-only.
- Purchase and Vendor financial public sharing is not exposed because cost/vendor
  data is sensitive. No new cost access is granted by Vendor identity permissions.

## Phase 5B draft decisions

- Purchases, lines and line lots represent mutable document drafts and receiving
  intent only. Number, posting/void fields, inventory valuation, movement and
  received-lot links remain null. No sequence, stock, cost-state or ledger write
  occurs. Direct non-draft creation/lifecycle changes fail closed; future posting
  requires a separately reviewed canonical transition.
- Company-first authenticated actor checks precede atomic header/line/lot
  replacement. Active same-company Vendor, warehouse, stock Product, ProductUnit
  and TaxRate are revalidated. Exactly one active default Purchase unit is required;
  the Sales default is irrelevant. Quantity conversion must be exact and respect
  both selected and base Unit fraction rules.
- New due dates follow nullable payment terms: null means no date, zero means the
  purchase date. Editing a null date preserves it. Receiving defaults use an active
  purchase warehouse, then the active inventory default; otherwise selection is
  required. UI locale and document locale remain independent.
- Suggestions use selected-unit configured Purchase price, otherwise Product base
  Purchase cost times conversion, otherwise zero, divided by document FX and rounded
  to currency minor units. Actual supplier costs are editable and never update
  Product defaults or establish historical vendor prices.
- Accepted Sales calculation primitives provide percentage-point tax and exact
  currency rounding. Purchase validation rejects excessive discounts instead of
  silently capping them. Document totals sum stored rounded lines, including their
  six-decimal base equivalents. Input Tax configuration does not change the
  supplier document total; recoverability and posting snapshots belong to 5C.
- Expiry lot intent may be partial but cannot exceed line quantity. Valid past
  expiry dates and repeated external lot numbers are allowed. Non-expiry products
  reject lot rows. Product/unit changes clear UI lot intent; server validation
  rejects retained incompatible lot provenance independently.
- Duplicate supplier invoice numbers are trimmed, scoped to company/Vendor and
  warning-only when configured. There is no uniqueness constraint.
- purchasing.cost.view and purchasing.purchase.edit_draft are static permissions.
  Create/edit requires cost access. Index/detail use explicit presentation fields
  and omit costs, discounts, tax amounts and totals when unauthorized. Read services
  and stale components reauthorize. New Manager/Purchasing defaults include these
  permissions; existing-company upgrades preserve customized non-owner roles.
- No Purchase posting, AP, receipts, returns, payments, financial read models,
  PDFs/public shares or Phase 6/7 functionality is introduced in 5B.

## Verification

Disposable local MariaDB is authoritative for tenant/RBAC, validation, role upgrade,
Input Tax, Sales regressions, sequence preservation and migration round-trip tests.
Arabic RTL and English LTR Vendor/configuration screens use existing responsive
tokens and components. No Phase 5A financial posting, stock movement or AP tables.
