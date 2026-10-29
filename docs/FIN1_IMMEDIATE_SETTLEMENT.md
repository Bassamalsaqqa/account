# FIN-1 — source development checkpoint

Baseline and production: `12d32484fbbbdba4df1280e94b4e1891f9e4c44e` (MS-2 deployed with subscription enforcement OFF).

**IN DEVELOPMENT. Not approved for merge, deployment or financial acceptance.** The new command is not called by any route, API, Livewire component or UI. Existing invoice posting and separate receipts remain unchanged. No migration, dependency, financial formula or production setting changes are included.

## Initial canonical composition

`ImmediateSaleCommands` accepts an explicit unpaid/full/partial choice for an existing saved draft. It reuses the mature `SalesInvoiceCommands` confirmation and canonical invoice posting, then `PostCustomerPaymentAction` for cash/bank receipt and allocation. One outer company-first transaction holds both business legs and durable command receipts; a receipt failure rolls back invoice state, numbering, ledger/stock effects and receipts. Commercial write admission and current permissions/session checks remain mandatory.

Preview performs no economic writes. Its encrypted five-minute token binds the company, actor, invoice, original invoice confirmation, exact normalized receipt instructions and payment-account/ledger context. Execute rechecks permissions before replay, rejects stale payment context, and delegates draft/stock/tax/pricing revalidation to the existing invoice command. Actor/company/action-scoped request keys and payload hashes prevent changed-request replay; derived receipt keys link the existing durable invoice/receipt records without a new schema.

Money uses exact decimal strings and existing currency/FX/account-routing primitives. Cross-currency allocations require both explicit currency amounts. Full settlement covers the complete invoice; partial settlement leaves an explicitly previewed remainder. Any explicitly entered excess is previewed as unallocated receipt credit, not silently discarded. A zero invoice uses unpaid rather than a fabricated zero receipt. Existing credit-limit blockers and financial safeguards are preserved.

This initial foundation supports cash and bank transfer only. **Checks are deliberately rejected here**: the existing `MoneyEventScope::checkForPayment` requires its executing canonical `ReceiveCheckAction`. FIN-1 must integrate that event boundary rather than attach a check ID to an ordinary receipt or invent a second check writer.

## Verification and current limits

Initial focused checks passed six tests/31 assertions. An expanded 50-test gate found one new-flow recovery error: a changed draft was safely rejected with a validation exception instead of a re-preview conflict. The command now maps invalidated signed payment context to HTTP 409; assertions were preserved. Its corrected 12-test focused run passed 48 assertions. These attempts and their complete private logs are retained; they are not full-phase acceptance.

The final focused foundation gate passed **50 tests / 255 assertions / exit 0**, including actual reconciliation after the composed sale and mature invoice/receipt/migration regressions. All six canonical domains were healthy after the new full-settlement flow. The failed expanded gate remains historical evidence. [Source-file-pinned receipt](evidence/fin1/foundation.json) records the tested runtime/test hashes and cleanup. Pint and PHPStan pass for the command. No complete `composer qa` certification is claimed for FIN-1; run the final full gate once the complete phase is frozen rather than testing an unfinished phase repeatedly. MS-2 QA does not certify this source.

Remaining before FIN-1 acceptance:

- Canonical incoming-check composition, instrument lifecycle, immutable history and failure rollback.
- Real stock/FEFO/lots/tax rollback scenarios and six reconciliations across partial, mixed-FX and check outcomes; returns/reversals remain canonical.
- Two-process competing users, duplicate/disconnected retries, permission/session revocation and subscription-expiry boundaries around the composed event.
- Broader actor/company/foreign-account negative tests, confirmation tampering, precision and changed routing/context cases; independent inspection of lock order and every alternate entrypoint.
- Existing invoice preview/confirmation integration and AR/EN UI: optional Unpaid / Pay in full / Pay part, exact account/currency/amount review, recovery and receipt/invoice/balance links. Use existing Blade/Livewire/Tailwind components with shadcn-inspired hierarchy, 44px controls and desktop/tablet/mobile RTL/LTR behavior. No React stack, new modal architecture or implicit incoming money.
- Fresh browser acceptance, focused financial/security regression, full QA and independent strict financial-source review before any merge/deployment.

Deployment of MS-2 does not authorize FIN-1 production changes or subscription restriction activation. Owner physical-device and privileged billing-access checks remain separate from this source checkpoint.
