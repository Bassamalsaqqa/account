# Opening Trade Balances Qualification and Release Document

Baseline: `dc60da85ac528ff921134779b021ed605cf696bc`, branch `t3/opening-trade-balances`.

## Implementation Scope and Invariants

1. **Concrete Opening Trade Items**: Dedicated `OpeningTradeItem` model records opening customer (AR) and vendor (AP) balances. Trade balances settle through normal collections and payments (`ApplyCustomerPaymentCreditAction`, `ApplyVendorPaymentCreditAction`, `PostCustomerPaymentAction`, `PostVendorPaymentAction`), allocating against opening items alongside invoices and purchases. No synthetic invoices, fake inventory movements, or artificial tax entries are created.
2. **Immutable AR/AP Opening Equity**: Opening items post through canonical `AccountingPostingService` with tenant company lock and dedicated action-owned scopes (`OpeningTradePostingCapability`, `OpeningTradeReversalCapability`, `OpeningTradeEventScope`). Double-count legacy overlap guard (`LegacyGlOpeningOverlapException`) enforces mutual exclusion: posting trade openings is refused if generic AR/AP opening balances exist, and vice versa.
3. **Exact Decimal Primitives**: `PositiveExactDecimal` rule rejects unrepresentable amounts, negative numbers, missing rates, and overprecision FX before API preview/execution and during Livewire row validation. Amounts and rates use exact decimal storage without binary float coercion.
4. **Historical FX and Dated Statements**: Statement queries reflect opening items as-of historical business dates even after reversal. Reversal batches use canonical reversal posting dates. Customer and vendor payment application events track typed opening targets (`opening_trade_item`) with authoritative saved book value relief.
5. **Web Interface**: Single confirmation Livewire form (`OpeningTradeBalanceEntry`) supports English and Arabic (RTL), responsive at 390px mobile and 1440px desktop. Includes focusable localized error summaries, draft row preservation on validation failure, 16px mobile inputs, and 44px mobile touch targets.
6. **API and Android Contract**: Endpoints `/api/v1/accounting/opening-trade-items/preview` and `/execute` provide typed validation, foreign carrying value preview, exact idempotency replay, and 422 validation error handling. The Android client contract is **PLANNED / NOT IMPLEMENTED** in this change; zero mobile/Android files are modified.

## Stitch Reference Retrieval

Stitch project `15641450350510420845` reference screens retrieved:
- English reference: `projects/15641450350510420845/screens/fed38deef26a4441a1aea22de54f3095`
- Arabic reference: `projects/15641450350510420845/screens/a1aa4aef54964150ba79cd1c22cb72f7` (resolving previous typo `ea1aa...`)

Visual review in conversation confirmed Arabic RTL layout alignment and field structure. Reference metadata and visual retrieval provide design guidance; formal Owner visual acceptance remains outstanding. No new design was generated.

## Migration and Database Round-Trip

- Forward migration: `database/migrations/2026_11_20_000001_create_opening_trade_items_and_extend_allocations.php`.
- Rollback explicitly drops named composite allocation indexes (`cpa_opening_item_idx`, `vpa_opening_item_idx`) before dropping target foreign-key columns, guaranteeing clean down/up round-trip without MariaDB error 1061.
- Populated-history refusal guard executes before any DDL modifications.
- Production migration status: **HOLD**. Migration is unapplied in production and requires explicit Owner review and approval prior to production release.

## Reproducible Verification Commands

Verification was conducted on disposable loopback MariaDB (port 3342) using owned isolated schemas:

```sh
# Full Accounting test suite (includes 6 reconciliation services on opening fixtures)
php tests/Support/run-phase8-disposable.php -- --do-not-cache-result tests/Feature/Accounting

# Frontend unit and component tests
npm test

# Static analysis and style verification
vendor/bin/pint --test
vendor/bin/phpstan analyse --memory-limit=1G --no-progress

# Production build verification (no source maps)
npm run build
git diff --check dc60da85..HEAD
```

## Exact Test Counts and Evidence

- **Accounting Suite**: **59 tests, 586 assertions**, zero failures/errors, owned schema cleaned. Includes full coverage of:
  - Opening trade items creation, preview, idempotency, and reversal
  - Two-process AR/AP company lock race condition tests
  - Populated history downgrade refusal and migration round-trip
  - Negative actor/party/tenant/currency/decimal validation matrix
  - All six canonical reconciliation services (Accounting, Inventory, Sales, Payables, Money, Phase 7) on opening fixtures
- **Frontend Suite**: **140 passed**, zero failures.
- **Static Analysis & Build**:
  - PHPStan: 804 files analyzed, 0 errors.
  - Pint: Passed cleanly.
  - Production build: Asset manifest verified, 0 source maps generated.
  - Git whitespace diff: Clean (`git diff --check` passed).
- **Browser Verification**: Verified via T3 browser on owned fixture schema (MariaDB port 3342) in English and Arabic RTL at 390px and 1440px viewports:
  - Invalid input rejection with draft retention and error summary focus
  - Successful customer and vendor opening balance postings
  - Mixed partial settlement (allocating payments across both normal documents and opening balances)
  - Detail page and receipt preview rendering with opening-item resolution

## Release Gates and Remaining Limits

1. **Browser Matrix**: Exhaustive keyboard Tab navigation traversal and all cross-currency browser permutations were not exhaustively exercised.
2. **Tenant Cutover Audit**: Real tenant data cutover audit has not been run against production data; cutover tooling was qualified on fixture schemas only.
3. **Release Status**: Production deployment, production migrations, and post-merge execution remain on strict **HOLD** pending Owner approval.
4. **CI Workflows**: Zero GitHub Actions workflows exist or were triggered; zero Actions minutes consumed.
