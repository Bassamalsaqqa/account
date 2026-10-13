# AI V2.2 A3 development checkpoint

Baseline: `688941d0dc3a2dcbb637c04a8c2fb30ad81ab6de`; isolated branch `feature/ai-v22-unsaved-drafting`. A2 corrections remain in their own draft PR #39. This is an initial backend prototype, not completed A3 or independent acceptance.

Protected controlling V2.2 Owner specification SHA-256: `d2bb378de74235a8329114f394dee0b3b1aeb8107bd2f84f8154f46972637aa8`; original preserved and unpublished.

AGY implemented the bounded prototype; Codex inspected, corrected and tested it. `AssistantDraftTurn` delegates to existing `TransactionAssistant`, proposal validation, same-company entity resolution and encrypted form-state services. Deterministic requests use zero model calls; the synthetic natural-language test uses exactly one mocked official SDK response through the existing adapter. No alternate transport or financial engine was added.

Caller-owned request/form/session identities are mandatory, so retries cannot manufacture another reservation. Fresh authorization is rechecked before returning handoff/clarification. Unknown intents fail closed. Missing parties remain clarifications; a fresh permitted manual-create link is informational only. No customer/vendor/product, walk-in, invoice, posting or stock record is automatically created. Existing manual Sales/Purchase routes receive editable UNSAVED forms and retain human Save/Post authority.

The narrow TransactionAssistant readiness check refuses an unverified text connection or known insufficient-credit health before reservation. Transient health failures do not erase existing verification.

Verification actually executed on owned disposable MariaDB, with no real keys or paid calls:

- 14 tests / 75 assertions, zero failures/errors/skips: AR/EN deterministic Sales, Purchasing, one mocked natural-language request, existing/missing/ambiguous/foreign-company parties, restricted staff, caller identity, replay/conflict/cancellation, actual Livewire InvoiceForm adoption and unverified connection.
- Scoped Pint and PHPStan pass.
- Quantities/prices remain exact strings; tests assert no automatic invoices, posting batches or stock movements. Existing company/usage/security services are reused.

Changed files: AssistantDraftTurn.php (new), TransactionAssistant.php (nine-line readiness addition), AssistantDraftTurnTest.php (new), this checkpoint.

Remaining A3 work: cohesive unified planning/transaction orchestration and UI integration, additional natural Arabic/Purchase/multi-line/mid-dispatch revocation cases and practical browser handoff evidence. Existing legacy transaction entry remains active; this new prototype is not wired into runtime routes or the launcher yet. Broader natural-language or live microphone accuracy is NOT VERIFIED. A4/A5/A6 have not started here.

No production access, financial mutations, migrations, merge, deployment, GitHub CI, live provider request or historical reservation reset occurred. Independent source review is required before release.
