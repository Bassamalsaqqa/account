# FIN-1 - invoice and immediate settlement

Production/baseline remains `12d32484fbbbdba4df1280e94b4e1891f9e4c44e` (MS-2, enforcement OFF). Development: `feature/fin1-immediate-sales-settlement-20261029`, [draft PR #69](https://github.com/Bassamalsaqqa/account/pull/69).

**HOLD for release acceptance. Source development only. Full FIN-1 QA and independent strict financial-source review are required. No FIN-1 merge or deployment is authorized.**

## Behavior and authority

The existing invoice workspace defaults to Unpaid and preserves draft saving and separate receipts. Authorized users explicitly choose full/partial settlement and an existing cash/bank account or incoming check. The existing focused posting-confirmation panel reviews invoice totals, receipt/account/currency/date/rate, allocations, outstanding balance, excess credit and check metadata before confirmation. Validation preserves the saved draft and manual values. The invoice detail already provides receipt links, balance, new invoice and document actions; receipt navigation returns to its invoice. Two untranslated check labels discovered in that receipt journey were corrected.

`ImmediateSaleCommands` composes mature `SalesInvoiceCommands` with `PostCustomerPaymentAction` or `ReceiveCheckAction` in one company-first `TenantWriteAccess` transaction. Numbering, stock, ledger, receipt, allocation and durable command receipts commit together or roll back together. Exact decimal strings, currency precision, authoritative stock/pricing/tax and existing credit-limit blockers remain intact. No new migration, dependency, operator grant, licensing or production-setting change.

The encrypted five-minute confirmation binds actor/company/invoice, canonical invoice confirmation, exact receipt instructions and account/control-ledger context. Fresh invoice view/post and receipt create/allocate permissions, membership, tenant context, API proof where applicable and commercial commit admission remain mandatory. Checks additionally require `money.check.incoming.manage`; they route through the executing canonical receive action to checks in hand and remain received, not cleared. Even a received check cannot use direct payment reversal: the existing `TransitionCheckAction` must own cancellation/return. This corrects the initial secondary audit's contrary suggestion.

Exact authorized retries return the original outcome even after token expiry; different requests cannot reuse that key. Competing actors/keys on one stocked draft leave one sale deduction and one receipt. Zero invoices use Unpaid; explicit excess remains unapplied credit. Payment edits invalidate review. The default unpaid UI retains the existing invoice command.

## Verification actually executed

- AGY secondary package: 26 tests / 141 assertions / exit 0, independently inspected against hashes, receipt and source. Its original service-line races did not establish stock concurrency.
- Integrated check/UI gate: 65 tests / 357 assertions / exit 0. Strengthened stocked two-process, two-authorized-actor gate: **65 tests / 361 assertions / exit 0**.
- Final receipt localization plus mature check lifecycle/security regressions: **19 tests / 131 assertions / exit 0**. Counts overlap; do not add these totals.
- Focused synthetic coverage includes FEFO, stock/receipt failure rollback, partial/mixed-FX settlement, canonical returns/reversals, stale accounts/tokens, permissions and tenant boundaries. All six reconciliation domains passed in the composed financial scenarios.
- Pint and PHPStan pass without suppression; JavaScript 44/44; production build 310 modules, exit 0. Locked Composer/npm audits report zero advisories/vulnerabilities. Dependencies unchanged.
- Actual synthetic browser: AR RTL and EN LTR at 390/768/1440px, no page overflow; payment inputs/selects and radio labels provide 44px targets. Error summary announcement/focus, field descriptors, manual amount preservation and cancel-to-post-trigger focus were verified. Browser review found and corrected focus ordering by moving payment review inside the focused confirmation panel.
- A synthetic Arabic incoming-check sale completed through normal UI, invoice/receipt links and corrected AR/EN receipt labels. A separate read-only probe verified exactly one posted invoice, receipt/allocation and received check; all six reconciliations returned healthy. No production transaction or provider call.
- Disposable schemas removed, owned MariaDB/web processes stopped, browser viewport reset/test tab closed. Forty private files and the translation stash remain protected.

[Compact source-pinned evidence](evidence/fin1/integration.json). [Original foundation evidence](evidence/fin1/foundation.json) remains historical. Raw logs remain private. Browser screenshots were inspected in-session; no durable screenshot archive is claimed.

Historical attempts remain traceable: the initial foundation stale-draft error was safely rejected but used the wrong exception category, corrected to HTTP 409. A later 31-test/196-assertion run had passing assertions but failed source-stability because Codex formatted a tracked file after launch; diagnostic only, superseded by unchanged-source integrated gates. A sandbox-denied PHP launch executed no tests. None is final PASS.

## Remaining acceptance

One frozen-source full `composer qa` and independent strict financial/security review are required. Review combined authority, lock order, incoming-check lifecycle/routing, outer-commit admission, permission/session revocation and Livewire hydration/replay. Broader browser offline/retry, keyboard-only complete submission, restricted-role UI and physical-device/scanner checks remain unverified. Desktop responsive emulation is not device acceptance; Windows symlink skips are not Linux proof.

The browser did not establish the transient success notification; posted invoice/receipt identifiers, allocation and balance were visibly verified instead. No paid provider or subscription enforcement was activated. Keep PR #69 draft; no later roadmap phase, FIN-1 merge or FIN-1 deployment has occurred.
