# ADR 0005: Purchasing, Vendors and Accounts Payable

Status: Phase 5 Complete / Accepted Source / Merged / Deployed. Phases 5A, 5B, 5C, 5D, 5E, and 5F are accepted, merged to main, and deployed to production.

## Context and checkpoints

Purchasing extends the accepted company-scoped Sales, accounting and inventory
boundaries. Phases 5A/5B/5C/5D/5E are accepted and deployed: Phase 5A covers the
Vendor/purchasing foundation; Phase 5B covers Purchase drafts; Phase 5C covers
Purchase posting, AP, Inventory and Input Tax; Phase 5D covers Purchase Returns;
Phase 5E covers Vendor Payments, AP settlement, balances, statements and aging.
Phase 5F completes the Purchasing history vertical with read-only Purchase and Vendor
price history queries, comparative metrics, and UI integrations. Phase 6 and later
functionality (cheques, expense bills, landed costs) remain unstarted. This ADR records
implemented and accepted decisions alongside future constraints.

Phase 5F accepted source `eee3b8284320aec498a0ec6de13873e3791aca65` was merged
through PR #13 on 2026-10-07 at `f0eb9f860042c27b2e8024a8e79d373654d80773`.
The merge preserves the accepted source tree exactly. That integration commit was
deployed on 2026-10-07 after verified backup, data preservation, healthy reconciliations
and read-only live smoke. Record-dependent history checks remain source-tested because
production had no Products, Vendors or Purchases; no sample business data was created.

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

## Phase 5C posting decisions

- `PostPurchaseAction` holds Company then Purchase locks and coordinates sequence,
  stock receipts, lot creation, account snapshots, GL and lifecycle in one outer
  transaction. A persisted Draft integrity check rejects master/configuration or
  calculation drift; it does not rewrite agreed economics. Tax percentage/mode
  uses the saved Draft snapshot, while current validated Input Tax configuration
  determines recoverability at posting.
- Draft party/company identity reflects the latest Draft save. Canonical POST
  refreshes the existing Purchase identity snapshot fields from the locked current
  Vendor/company through `PurchaseIdentitySnapshot`, shared with Draft building.
  Refresh commits with stock/GL/lifecycle or rolls back with them. Posted identity
  remains frozen; coherent retries do not refresh it from live master data.
- A nullable restrictive `purchase_lines.purchase_tax_account_id` retains the
  historical Input Tax account. Null means tax is capitalized. Inventory base
  value is exact stored line total minus separately recoverable tax. Supplier
  liability remains the exact sum of stored line totals in both currencies.
- `purchase` is a first-class inbound movement with explicit six-decimal cost and
  exact value, no historical-original override, and Purchase source/line identity.
  It requires Purchase post and purchasing cost permissions, never generic stock
  adjustment authority. New movements validate their persisted receiving intent;
  idempotent retries retain the complete request fingerprint.
- Generic inventory `record()` rejects Purchase commands before authorization,
  idempotency or persistence. The dedicated `recordPurchaseReceipt()` entrypoint
  requires an active opaque Purchase-posting capability, not merely an arbitrary
  transaction, and uses the same internal inventory engine. A request-scoped
  `PurchasePostingScope` binds capability object identity to company, Purchase,
  actor, connection/PDO and the actual pending Laravel transaction record.
  Only an executing `PostPurchaseAction` can activate it: the action proves
  ownership with private invocation state that has no public setter. Scope and
  action state clear in `finally`, including failures; expired/forged tokens and
  same-level replacement transactions fail closed. Nested scopes are rejected.
  Capabilities cannot be cloned or serialized and are never stored. Architecture
  tests additionally restrict both activation and receipt call sites to the action.
  Named line/lot/Purchase completion methods also
  require an outer transaction; they validate existing canonical provenance and
  cannot manufacture stock. Posted retries validate history without replaying
  the inventory command.
- One inventory command per Purchase line retains its source line identity. Full
  expiry allocation is mandatory at posting. Ordered lot values round HALF_UP to
  six decimals, with the final lot taking the exact remaining value. Rounded unit
  cost is presentation/history metadata; immutable movement value is acquisition
  authority. Reconciliation and rebuild verify Purchase provenance and use that
  exact value rather than quantity times rounded unit cost.
- GL uses canonical accounting posting only: Dr Inventory, optional Dr Input Tax,
  Cr AP. Exact component residuals use the accepted same-account base-only
  residual primitive, with truthful transaction metadata on converted lines.
  Zero-value journal lines are never invented. A wholly zero-value Draft remains
  editable but cannot post: this checkpoint requires a valid nonzero canonical
  PostingBatch. Zero-value receipt policy requires separate review.
  Positive transaction-currency components that round to zero base value also
  fail closed if the canonical GL cannot retain their currency metadata. Posted
  Inventory/Input Tax/AP transaction amounts must match their source exactly.
- Named model completion methods may attach only exact receipt/posting provenance
  under fresh authorization; ordinary updates remain prohibited. Posted headers,
  lines and receiving intent are immutable. Retry checks complete source stock,
  lots and exact batch content before returning; corrupted history is reported,
  never repaired. Purchase numbers are assigned only at POST and roll back with
  all other effects on failure.
- Generic posting audit stores lifecycle/number/batch identity only. Cost-redacted
  readers receive no costs, amounts, FX or stock/accounting provenance identifiers.
  UI confirmation explains inventory receipt, vendor liability and locking.
- Returns, voids, Vendor payments/statements, PDFs/shares, landed costs, expenses
  and checks remain outside Phase 5C.

## Phase 5D Purchase Returns decisions

- Purchase Returns strictly require a coherently posted Purchase invoice.
  Drafts carry no sequence consumption, stock effects, or accounting entries.
  Number sequence PRT is allocated only inside the canonical POST transaction.
- Returns inherit original currency, base currency, and saved exchange rate;
  return exchange rates are immutable historical facts, not market settlements.
- Commercial allocation derives attributable historical book components independently:
  A = AP relief base, T = recoverable input tax relief, H = A - T (commercial inventory),
  I = actual inventory carrying value removed, D = I - H (COGS valuation adjustment).
  If D > 0: Dr COGS D / Cr Inventory D. If D < 0: Dr Inventory abs(D) / Cr COGS abs(D).
  Net Inventory credit strictly equals I (the exact sum of movement values).
- Zero-value returns (A=0, T=0, I=0, D=0) record canonical stock movements with posting_batch_id null.
  Any nonzero financial effect requires a valid canonical posting batch.
- Inventory issues use dedicated type `purchase_return` issued from the original receiving
  warehouse and exact original receipt movements and lots.
  Pure moving-average revaluation updates current company value: partial return requires
  historical target <= old value; final depletion (newQty == 0) removes the exact old value,
  clearing any accumulated fractional residual.
- Expired vendor lots are returnable for `purchase_return` only; normal expiry checks remain
  enforced for all other outbound operations.
- Inactive stock products and inactive/soft-deleted original vendors are permitted for
  corrective purchase returns provided company ownership, stock type, and provenance coherency hold.
  The original receiving warehouse must remain active.
- If the original historical input tax account is now inactive, or the original currency is disabled,
  posting fails closed and rolls back atomically; no silent account rerouting or currency bypass.
- Dedicated `recordPurchaseReturnIssue()` requires connection/PDO/transaction-bound
  `PurchaseReturnPostingScope` and `PurchaseReturnIssueCapability`, owned exclusively by
  `PostPurchaseReturnAction`.
- Posted returns, lines, and allocations are immutable. No return void or reversal is supported.
  Read models redact all sensitive cost and financial fields when `purchasing.cost.view` is absent.

## Phase 5E Vendor Payments and Accounts Payable decisions

- Vendor Payments use active same-company MoneyAccounts (Cash/Bank) under canonical
  cash_control/bank_control parent accounts. Cheques and cross-currency allocations are Phase 6.
  Foreign-account selection requires explicit settlement FX; only base-currency
  selection defaults to one. Missing FX produces no estimated settlement difference.
- No mutable vendor balance, purchase paid_amount, or payment_status columns exist.
  Purchase payable positions (`outstanding`, `credit`, `settled`, `partially_paid`, `unpaid`)
  and Vendor balances derive purely from posted invoices, posted returns, and active allocations.
- Historical AP relief uses cumulative residual tracking (`PayableBookValue` and `PayableReliefHistory`).
  Payment settlement residual tracks exact conversion; difference S - B is realized FX
  (delta > 0: FX Loss, delta < 0: FX Gain; opposite sign of Accounts Receivable).
- Unallocated payment balances remain as debit AP vendor advances at payment-date rate;
  later applications append immutable `VendorPaymentApplicationEvent` and `VendorPaymentAllocation`
  records without a second cash movement; zero-FX applications complete with null posting batch.
- Request-scoped runtime authority (`VendorPaymentPostingScope`, `VendorPaymentApplicationScope`)
  binds exact tenant, actor, models, DB connection/PDO, and transaction record.
  Provisional constructors, initial allocation append, and lifecycle completion require active capability.
- Coherent reversal in one atomic transaction reverses dependent application events newest to oldest,
  then original payment batch via `AccountingReversalService`; inactive/soft-deleted entities do not block reversal.
- Historical validation reconstructs complete canonical posting commands, including
  transaction metadata and same-AP-account base residuals. Original Payment batches
  use initial allocations only; application batches are independently reconstructed.
  Persisted accounting boundaries and allocation append order preserve old relief
  after later Returns, applications, and reversals. Corrupt history is never repaired.
- Reversal completion requires a separate live `VendorPaymentReversalScope` capability.
  Its prepared event order binds each dependent reversal and the final Payment
  reversal to the same outer transaction, including applications without a GL batch.
- An inactive/soft-deleted Vendor may receive a fully allocated historical settlement,
  but no new advance. MoneyAccount balances remain ledger-derived; Phase 5E adds no
  insufficient-funds or overdraft restriction.
- Purchase-Return-created Vendor credit is visible in balances, statements, and net
  aging position. It is not automatically allocated to another Purchase; only
  unallocated Vendor Payment advances have an explicit application workflow.
- Centralized financial read authorization requires `purchasing.cost.view` AND at least one of
  (`money.vendor_payment.create`, `allocate`, `reverse`, `vendors.statement.view`).
  Sensitive financial fields are redacted on the server; CSS hiding is prohibited.

- Vendor Payment reversal supplies one explicit Company-local business date to all dependent and parent accounting reversals. Absolute lifecycle timestamps remain timestamps. The captured Company-local lifecycle date is checked before commit; date inconsistency fails atomically. Historical statements and aging use the persisted reversal business date, so later Company timezone changes do not reinterpret old reversals. Historical validation requires dependent reversal batches to share the parent reversal date. Shared reversal callers that omit an explicit business date retain their existing default.

- Reversal cannot precede the original Payment or any dependent application business
  date, including zero-GL applications. Future activity remains reversible when the
  Company-local current date reaches it; reversal never silently uses a future date.
- Statement filters require canonical date-only values and From on or before the
  effective To cutoff. Invalid ranges produce no statement or aging summary.
- Statement aging uses the same company-local end-of-day cutoff as the statement
  closing balance. Purchase/Return/payment/application business dates and reversal
  business dates determine historical positions; later activity does not rewrite them.
- Payables reconciliation validates exact canonical purchasing/payment source
  batches, not equality with the entire AP control account. Legitimate AP opening
  balances can exist outside the Vendor source subledger.

## Phase 5F Purchase and Vendor Price History decisions

- **Authoritative History Source**: Only coherent, posted purchases (`Purchase::STATUS_POSTED`
  with non-null `purchase_number` and `posted_at`) constitute historical purchase and price history.
  Drafts, voids, deleted documents, and cross-company records are strictly excluded.
- **Snapshot Preservation Across Master-Data Lifecycle**: Historical price records read line-level
  snapshots (`product_name_ar/en`, `product_sku`, `unit_name_ar/en`, and Vendor name/address/contact fields in `vendor_snapshot`) rather than
  mutable master data. Missing/malformed historical display identity fails closed; language fallback
  stays within persisted snapshots. Vendor code is not frozen by the current Purchase snapshot contract
  and remains null unless explicitly present in a historical snapshot. Retired Product identity remains
  visible but its Product-detail link is suppressed; the Purchase link remains available when authorized.
- **Purchase Returns Invariant**: Corrective purchase returns record return movements and relief,
  but never erase, diminish, or rewrite the original purchase price history line or its vendor charge.
- **Deterministic, Company-Scoped Query Contract**:
  - `ProductPurchaseHistoryQuery`: Retrieves recent authoritative purchase price lines for a product
    across all vendors, scoped strictly to the authenticated tenant company, with deterministic
    ordering (`purchases.purchase_date` DESC, `purchases.id` DESC, `purchase_lines.line_number` ASC)
    and bounded recent-line limits (1–100 items).
  - `VendorProductHistoryQuery`: Retrieves recent purchase price lines for a vendor across products,
    and provides a `productsSupplied()` aggregation summarizing distinct products supplied with
    latest purchase date, latest unit cost, latest net commercial price per base unit, distinct Purchase
    count, and cumulative base quantity within the advertised recent-line window.
  - `VendorProductPriceHistoryQuery`: Queries historical price lines for a specific vendor + product
    pair, and provides a `latest()` convenience helper.
- **Historical Net Commercial Price per Base Unit Metric**:
  - Raw historical unit prices (`unit_cost`), transaction currency, line discount, and unit are displayed
    faithfully with preserved precision (no rounding to currency minor units).
  - For cross-transaction and cross-unit comparison, the system derives an explicitly labeled comparative
    metric: **Historical Net Commercial Price per Base Unit**, derived from persisted tax-separated book components:
    `net_commercial_price_per_base_unit = (line_total_base - line_tax_base) / quantity_base`
  - Arithmetic uses exact `BigDecimal` operations with 6 decimal places and `RoundingMode::HALF_UP`.
  - The metric reflects commercial price after line discounts, strictly excludes both inclusive and exclusive
    tax, applies the historical transaction exchange rate, and normalizes to the product's base unit in company
    base currency.
  - If `quantity_base` is zero or negative, the metric is omitted (`null`). The metric is accompanied by an
    explanatory caption in the UI. It is never confused with or substituted for moving-average inventory
    valuation (`InventoryCostState`).
- **Canonical Coherence and History Invariants**:
  - `PurchaseHistoryProvenance` validates all selected lines in one joined read: exact same-company
    Purchase/line/master ownership, Posted number/actor/timestamp metadata, and the referenced Posted
    original batch with `source_type = purchase` and `source_id = Purchase.id`. Normal history reads
    do not replay inventory lots, stock movements or posting lines; deep `validatePosted()` remains
    authoritative for reconciliation and posting retries outside this read model.
  - Stored null SKUs remain null and are never overwritten by subsequent mutable product master SKUs.
  - Unsnapshotted vendor codes remain null and are never invented or fallen back from mutable master vendor data.
- **Server-Side Security, Centralized Guard, and Cost Protection**:
  - Centralized `PurchasingHistoryGuard` validates authentication, active company context matching requested company,
    active company user membership, same-company entity ownership, and `purchasing.cost.view` permission for all
    query entry points.
  - When `purchasing.cost.view` is absent, the Purchase Price History tab on `ProductDetail` and the Products
    tab on `VendorDetail` are completely omitted from the rendered UI, and no price, discount, or cost data
    is serialized or transmitted to the client. CSS-only redaction is strictly prohibited.
  - Stale Livewire requests that attempt to activate price history tabs without `purchasing.cost.view`
    reauthorize server-side and automatically revert to the `overview` tab.
  - Surface permissions remain enforced: `inventory.stock.view` or `inventory.product.manage` for product
    access, and `vendors.view` for vendor access.
  - Transaction hyperlinks to `purchases.show` require `purchasing.purchase.view`; when absent, document
    numbers render as plain text. Product links in vendor history require product view permissions.
  - The lightweight hint on `PurchaseForm` displays the last unit cost, original unit, and date for the selected vendor
    and product pair only when `purchasing.cost.view` is held; it is batched via `latestForProducts` to avoid N+1 queries,
    purely informational, and never overwrites, mutates, or sets draft line unit costs or default catalog prices.
- **Bounded Queries and Aggregation Accuracy**:
  - `productsSupplied` summary is bounded to the latest 100 posted lines (up to 50 product summaries by default, clamped 1-100) and counts distinct purchase documents
    within that window, rather than counting purchase lines as documents.
  - Zero database migrations, zero price cache tables, and zero `last_vendor_price` columns are introduced.
  - Read queries and UI interactions cause zero mutations to `purchases`, `purchase_lines`, `posting_batches`,
    `posting_lines`, `stock_movements`, `inventory_balances`, or `inventory_cost_states`.
  - Query performance relies on existing composite indexes on `purchases` (`company_id, status, purchase_date`
    and `company_id, vendor_id, purchase_date`) and `purchase_lines` (`company_id, product_id` and
    `company_id, purchase_id`), verified via MariaDB `EXPLAIN` to utilize index scans.

## Verification

Disposable local MariaDB is authoritative for tenant/RBAC, validation, role upgrade,
Input Tax, Sales regressions, sequence preservation and migration round-trip tests.
Arabic RTL and English LTR screens use existing responsive tokens and components.
Phase 5C, 5D, and 5E tests additionally verify exact receipt/GL equality, lot residuals,
immutable provenance, authorization, idempotency, AP book relief, realized FX, and atomic rollback.
Phase 5A/5B retain their configuration-only and side-effect-free Draft boundaries.
Phase 5F tests verify exact comparative net commercial pricing, tenant isolation, snapshot preservation
after entity retirement, returns preservation, authorization gating, redaction without cost view,
and zero database mutations.

### Bounded history reads

Vendor product summary counts and quantities refer only to the latest 100 coherent posted Purchase lines, explicitly labeled in the UI; they are not all-time totals. All contributing lines pass the lightweight batched canonical-source provenance check. Batched form hints rank latest rows per Product in SQL and hydrate only those rows (at most 100 Products), so old history is not hydrated merely to show a latest-price hint. No deep posting replay occurs during history rendering. Product identities are validated against persisted same-company rows, including retired master records. No pagination or price cache is implied.
