# Opening Trade Balances Qualification and Release Document

Baseline: `efa5e8ee26637dfb37751e669c16a137859eec0e`, branch `t3/opening-trade-balances`.

## Implementation Scope and Invariants

1. **Concrete Opening Trade Items**: Dedicated `OpeningTradeItem` model records opening customer (AR) and vendor (AP) balances. Trade balances settle through normal collections and payments (`ApplyCustomerPaymentCreditAction`, `ApplyVendorPaymentCreditAction`, `PostCustomerPaymentAction`, `PostVendorPaymentAction`), allocating against opening items alongside invoices and purchases. No synthetic invoices, fake inventory movements, or artificial tax entries are created.
2. **Immutable AR/AP Opening Equity**: Opening items post through canonical `AccountingPostingService` with tenant company lock and dedicated action-owned scopes (`OpeningTradePostingCapability`, `OpeningTradeReversalCapability`, `OpeningTradeEventScope`). Double-count legacy overlap guard (`LegacyGlOpeningOverlapException`) enforces mutual exclusion: posting trade openings is refused if generic AR/AP opening balances exist, and vice versa.
3. **Exact Decimal Primitives & Currency Minor Precision**: `PositiveExactDecimal` and `OpeningTradeAmount` rules enforce exact decimal representation matching active, enabled company currency minor units (`CurrencyMinorUnitsResolver::resolveFreshForCompany` and `MoneyValues::amount`) under canonical company lock. Unrepresentable amounts, negative numbers, missing rates, and overprecision amounts are rejected before API preview/execution and during Livewire row validation. Amounts and rates use exact decimal storage without binary float coercion.
4. **Historical FX and Dated Statements**: Statement queries reflect opening items as-of historical business dates even after reversal. Reversal batches use canonical reversal posting dates. Customer and vendor payment application events track typed opening targets (`opening_trade_item`) with authoritative saved book value relief.
5. **Web Interface**: Single confirmation Livewire form (`OpeningTradeBalanceEntry`) supports English and Arabic (RTL), responsive at 390px mobile and 1440px desktop. Includes focusable localized error summaries, draft row preservation on validation failure, 16px mobile inputs, and 44px mobile touch targets.
6. **API and Android Contract**: Endpoints `/api/v1/accounting/opening-trade-items/preview` and `/execute` provide typed validation, foreign carrying value preview, exact idempotency replay, and 422 validation error handling. The Android client contract is **PLANNED / NOT IMPLEMENTED** in this change; zero mobile/Android files are modified.

## Huda Five Findings Qualification & Resolution

1. **Hidden Stale Additional Row FX (`hiddenstaleadditionalrowFX`)**:
   - Fixed foreign currency additional lines in `OpeningTradeBalanceEntry`: newly added rows share the visible exchange rate from the parent form without stale copied values.
   - Updated Livewire view (`opening-trade-balance-entry.blade.php`) to display real-time base currency equivalent preview per row matching the parent carrying value calculation.
   - Verified that changing the visible exchange rate applies uniformly to all foreign rows upon submission and calculation.
2. **Action-Owned Lifecycle Capability (`OpeningTradeItem directcreate/statussave bypass`)**:
   - `OpeningTradeEventScope` provides action-owned lifecycle capabilities: `assertCanCreateItem`, `assertCanLinkPostingBatch`, and `assertCanReverseItem`.
   - `OpeningTradeItem` model booted lifecycle hooks reject direct creation of `posted` items without active posting capability, reject direct creation of `reversed` items, and reject direct status mutation from `posted` to `reversed` without canonical accounting reversal batch and matching reversal capability.
   - Model `setAttribute` enforces immutability for reversed opening trade items (`getOriginal('status') === self::STATUS_REVERSED`) while allowing legitimate reversal transition attributes during canonical reversal.
3. **Later Credit Application Lifecycle Guard Parity (`laterCustomerApplicationHistory reversedtargetguard parity`)**:
   - Aligned `CustomerApplicationHistory` with `CustomerPaymentHistory` and `VendorPaymentHistoryCommands`.
   - Reversed opening trade items are rejected when the parent customer payment is active, and permitted if and only if the parent customer payment is reversed (`$payment->is_reversed`).
   - Verified both-side lifecycle and full six-service reconciliations across credit application events.
4. **Livewire Lost-ACK Replay with Disabled Currency (`Livewireauthorizedsamekey lostACKreplay`)**:
   - Preserved authorized immutable replay in `OpeningTradeBalanceEntry`: when an existing idempotency key matches a previously committed opening trade item, structural validation passes without blocking on subsequent currency disablement.
   - Strict fresh validation remains enforced for new events.
   - Idempotency conflict with altered economic payload fails closed with `IdempotencyConflictException`.
5. **API Preview Base Rate 1 Parity & Side-Specific Permissions (`APIpreview baserate1 parity`)**:
   - Base currency exchange rate in `/api/v1/.../accounting/opening-trade-items/preview` must be exactly 1; non-1 base rate returns 422.
   - Side-specific permissions (`customers.manage`, `vendors.manage`, `purchasing.cost.view`, `settings.accounting.manage`) are validated before any GL overlap status is disclosed, preventing unauthorized disclosure.

## Stitch Reference Retrieval

Stitch project `15641450350510420845` reference screens retrieved:
- English reference: `projects/15641450350510420845/screens/fed38deef26a4441a1aea22de54f3095`
- Arabic reference: `projects/15641450350510420845/screens/a1aa4aef54964150ba79cd1c22cb72f7` (resolving previous typo `ea1aa...`)

Visual review confirmed Arabic RTL layout alignment, field structure, and shared exchange rate presentation.

## Migration and Database Round-Trip

- Forward migration: `database/migrations/2026_11_20_000001_create_opening_trade_items_and_extend_allocations.php`.
- Rollback explicitly drops named composite allocation indexes (`cpa_opening_item_idx`, `vpa_opening_item_idx`) before dropping target foreign-key columns, guaranteeing clean down/up round-trip without MariaDB error 1061.
- Populated-history refusal guard executes before any DDL modifications.
- Production migration status: **HOLD**. Migration is unapplied in production and requires explicit Owner review and approval prior to production release.

## Reproducible Verification Commands

Verification was conducted on disposable loopback MariaDB (port 3342) using isolated ephemeral schemas:

```sh
# Full Accounting + Regression test suite (includes 6 reconciliation services on opening fixtures)
php tests/Support/run-phase8-disposable.php -- --do-not-cache-result tests/Feature/Accounting tests/Feature/Phase4/LaterCustomerCreditTest.php tests/Feature/Phase6/CheckLifecycleTest.php tests/Feature/Phase5E/VendorPaymentResidualAndPreservationTest.php

# Static analysis and style verification
vendor/bin/pint --test
vendor/bin/phpstan analyse --memory-limit=1G --no-progress app/
npm run build
git diff --check
```

## Exact Test Counts and Evidence

- **Complete Extended Test Suite**: **101 tests, 963 assertions**, zero failures/errors, owned schema `accounting_p8_tmp_00c15516ea0b` cleaned (04:00.375, 95MB memory).
- **All Six Canonical Reconciliation Services Verified**:
  - AccountingReconciliationService
  - InventoryReconciliationService
  - SalesReconciliationService
  - PayablesReconciliationService
  - MoneyReconciliationService
  - Phase7ReconciliationService
- **Static Analysis & Build**:
  - PHPStan: 804 files analyzed in `app/`, 0 errors (Level 6).
  - Pint: Passed cleanly (`{"tool":"pint","result":"passed"}`).
  - Vite Frontend Build: `vite build` completed in 3.66s (manifest, css, js).
  - Git whitespace diff: Clean (`git diff --check` passed).

## Release Gates and Remaining Limits

1. **Browser Matrix**: Full keyboard Tab navigation traversal and exotic cross-currency browser permutations were qualified in targeted scenarios.
2. **Tenant Cutover Audit**: Real tenant data cutover audit has not been run against production data; cutover tooling was qualified on fixture schemas only.
3. **Release Status**: Production deployment, production migrations, and post-merge execution remain on strict **HOLD** pending Owner approval. No push to remote until independent Huda Codex review completes.
4. **CI Workflows**: Zero GitHub Actions workflows exist or were triggered; zero Actions minutes consumed.
