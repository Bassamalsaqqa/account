# Huda bounded application regressions — 2026-11-07

Status: **FOCUSED CHECKS PASS — parent owns consolidated release qualification.**

Baseline and resulting HEAD: `06e4f6d71b8dc3202f0708dff91a21b32a5afd33`. Extensive pre-existing dirty work, including the larger PurchaseForm redesign, is preserved. No children, new branches, dependency changes, commit, push, deployment, Hostinger or production access.

## Ownership and inspected contracts

Read repository AGENTS, `huda-regression-final.md`, `huda-runtime-failure-index.log`, documented disposable-runtime setup, and the relevant purchase draft, posting/settlement receipt, platform billing transaction, account-default and exact decimal implementations. Used `ui-ux-design` as the sole primary UI router and existing DESIGN/UI_WORKFLOW_RULES guidance; no templates, components, assets, browser work or UI-agent files changed.

Application write ownership stayed within `app/Livewire/Pages/Purchasing/PurchaseForm.php`. Inspected but deliberately did **not** modify `app/Application/Platform/ManageSubscription.php`: the billing failure is an incorrect caller-owned nested-transaction retry expectation, not evidence that its canonical receipt checks need weakening. The only test changes are the four assigned focused classes. Other owners' CompanyDataExport, environment/support/deploy/barcode, ReleaseUi and platform access/security work is untouched.

## Owned application delta

The PurchaseForm diff against HEAD contains substantial work from before this assignment. Only these additional deltas belong to Huda:

- Add a Livewire-locked completed-confirmation record containing the already-successful purchase public ID, encrypted confirmation token, execution key, reviewed digest and paid/unpaid flag.
- On repeated `saveAndConfirm`, reuse that record and enter `confirmPost` rather than updating an immutable posted purchase as a draft.
- `confirmPost` replays the same canonical `ImmediatePurchaseCommands::execute` request. It does not merely return a cached redirect: current actor/company/payment authorization, command-receipt request identity, and posted purchase/payment integrity checks still run.
- Preserve the reviewed form/settlement digest check on replay. Changed quantity or payment notes are rejected without modifying posted history or creating another purchase, payment, posting batch or stock movement.
- An existing-draft form may revisit its successful result only when its locked completed record matches that form's purchase ID. Other edit forms retain the persisted-draft immutability guard. Successful completion then clears preview-only state as before.

Regression coverage expands the existing purchase test to new/existing draft × unpaid/full-cash settlement. Both `saveAndConfirm` and direct `confirmPost` converge on one command receipt; paid cases retain one vendor payment. Changed draft/settlement input is rejected, and revoking vendor-payment creation permission after completion makes a paid retry forbidden rather than returning an authorization-bypassing cached success.

## Billing snapshot contract

Read-only runtime inspection returned `@@innodb_snapshot_isolation = 1` and `@@transaction_isolation = REPEATABLE-READ` on the documented MariaDB instance. No server/session setting changed.

The old test opened a manual outer transaction, established a snapshot, committed a receipt and subscription revision through a peer connection, then expected the nested service transaction to silently restart its caller's snapshot. With snapshot isolation enabled, the subscription locking read correctly raises record-changed error 1020. Laravel propagates a concurrency failure from the nested transaction; only the owner of the outer transaction can safely restart all caller work. Catching it inside the service or resetting an unowned outer transaction would break transaction ownership/atomicity.

The regression now uses a retryable `DB::transaction(..., attempts: 3)` at the **test-owned outer boundary**. The peer commit happens once; stale-snapshot failure is not accepted as a passing result. After the boundary retry, the test must receive the real `amount` validation error for the attempted 80 + 50 settlement against a 100 charge, retain exactly one payment and only the original configure request, and leave tenant posting batches/stock movements empty. Existing simultaneous identical-retry and competing-operator cases also pass. ManageSubscription's existing own-boundary retries, current locking reads, revision checks and overpayment behavior remain unchanged.

## Default accounts and decimal expectations

- `VendorPaymentFinalInputChronologyRegressionTest`: standalone payments canonically prefer an eligible company-base-currency account before `sort_order`. Company creation supplies an ILS account in addition to the fixture's later ILS account; assuming that later fixture ID is the default was also incorrect. The test now checks an eligible same-company ILS selection despite the foreign account's higher sort priority, disables all same-company ILS accounts to exercise the actual foreign fallback, and verifies USD/JOD fallback and purchase preselection select the intended account with blank explicit foreign FX. PaymentForm and canonical account selection are unchanged.
- `QuickProductReceiveTest`: persisted-draft inputs intentionally trim quantity display zeros and format editable monetary inputs. String-only `BigDecimal::isEqualTo` assertions compare the exact quantity and unit-cost values independent of trailing-zero representation. No floats, epsilon, rounding, truncation, canonical formatting changes or economic precision relaxation were introduced.

## Reproduction and actual results

From the account repository:

```bash
APP_ENV=testing PHASE8_ALLOW_DISPOSABLE_DB=1 \
PHASE8_TEST_DB_HOST=127.0.0.1 PHASE8_TEST_DB_PORT=3342 \
PHASE8_TEST_DB_USERNAME=test_runner PHASE8_TEST_DB_EMPTY_PASSWORD=1 \
PHASE8_TEST_TIMEOUT=14400 \
php tests/Support/run-phase8-disposable.php -- \
  tests/Feature/Refinement/HumanWorkflowRefinementTest.php \
  tests/Feature/ManagedSaas/PlatformBillingConcurrencyTest.php \
  tests/Feature/Phase5E/VendorPaymentFinalInputChronologyRegressionTest.php \
  tests/Feature/BusinessWorkflow/QuickProductReceiveTest.php

vendor/bin/pint --test \
  app/Livewire/Pages/Purchasing/PurchaseForm.php \
  tests/Feature/Refinement/HumanWorkflowRefinementTest.php \
  tests/Feature/ManagedSaas/PlatformBillingConcurrencyTest.php \
  tests/Feature/Phase5E/VendorPaymentFinalInputChronologyRegressionTest.php \
  tests/Feature/BusinessWorkflow/QuickProductReceiveTest.php

vendor/bin/phpstan analyse --no-progress --memory-limit=512M \
  app/Livewire/Pages/Purchasing/PurchaseForm.php \
  app/Application/Platform/ManageSubscription.php

git diff --check
```

- **Final focused integration run: PASS, 56 tests, 385 assertions, exit 0; 01:00.331; 91.00 MB.** Log: `huda-app-regression-final-focused.log`.
- Targeted Pint: **PASS**. Log: `huda-app-regression-pint.log`.
- Targeted application PHPStan: **PASS, no errors**. Log: `huda-app-regression-phpstan.log`.
- `git diff --check`: **PASS**.
- Initial diagnostic focused run: 53 tests, 299 assertions, two vendor-default fixture failures; its schema was cleaned. Corrected account fixture and expanded purchase regressions subsequently passed. Log: `huda-app-regression-focused.log`.
- The raw `.log` files are intentionally retained local evidence and ignored by the repository's log rule; this Markdown is the portable handoff record.

## Database safety and remaining qualification

Used only the documented disposable test runner and helper-created schemas on loopback port 3342 with the intentionally empty-password `test_runner` identity. Final runner-owned primary schema `accounting_p8_tmp_b75aaff9e517` was cleaned by its creating helper; nested billing test schemas were likewise managed exclusively by their respective helper objects. No manual DROP, sibling-schema adoption/cleanup, schema enumeration or server teardown occurred. The earlier report's claim of ownership over auxiliary schema `accounting_p8_tmp_2f13bbe960c8` is **not** relied upon: owning this server does not confer ownership of any other worker's schema. Server PID 3597 remains live with its original private data directory.

No additional accounting defect was confirmed within this bounded inspection. No migration or canonical economic-policy change was made. The passing focused classes include their existing reconciliation assertions; this is not a claim that all company reconciliations, the full suite, full static analysis, browser/UI, production build, dependency audit or deployment qualification have run. Parent must consolidate the remaining release gates. No full suite was started.
