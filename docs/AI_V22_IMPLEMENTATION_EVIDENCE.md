# AI V2.2 implementation evidence

Controlling Owner specification: local `docs/ACCOUNTING_AI_ASSISTANT_DEEPSEEK_V2_2_LOCKED_SOURCE_OF_TRUTH_2026-10-13.md`, SHA-256 `d2bb378de74235a8329114f394dee0b3b1aeb8107bd2f84f8154f46972637aa8`. The protected local original is not included in this change.

Source baseline: `50bf994a6c1c9d007cb798f0b290c84b4ba50d12`. `origin/main` was fetched and matched on 2026-10-13. This record describes source development, not production acceptance.

## Locked transport and privacy

The existing `laravel/ai` 1.2.0 integration remains the sole paid DeepSeek transport. Composer dependencies, credentials and the bounded SDK gateway remain in place. No SDK comparison or alternative Chat Completions client is introduced. The new fixed-host HTTP operation is exclusively the [DeepSeek balance endpoint](https://api-docs.deepseek.com/api/get-user-balance/).

Balance health is separate from a successful model conversation. It consumes no model tokens and must neither reserve a paid turn nor clear historical unknown-cost exposure. Provider balances and local company/user budgets are different controls.

Model planning will remain one outbound response followed by local authorized execution. Report figures, resolved database identities and returned business records must not be retransmitted. [DeepSeek tool calls](https://api-docs.deepseek.com/guides/tool_calls/) are proposals; they cannot authorize execution. The SDK contract checkpoint uses fake responses and no automatic tool loop.

## Execution ledger

| Package | Current state | Review boundary |
|---|---|---|
| Gate 0 SDK tool-intent contract | SOURCE CHECKPOINT COMPLETE; INDEPENDENT ACCEPTANCE PENDING | Fake provider envelopes, bounds, no execution/retransmission |
| A1 free connection health | SOURCE CHECKPOINT COMPLETE; INDEPENDENT ACCEPTANCE PENDING | Owner/key/revision checks; zero paid reservation; truthful setup status |
| A2 company-scoped turn orchestration | NOT RUN | Session/company binding, idempotency, one planning call, canonical local answers |
| A3 existing-party unsaved handoffs | Existing regressions carried; V2.2 integration NOT RUN | No walk-in/master-data creation, no Save/Post |
| A4 thin drawer and cancellable voice submission | NOT RUN | Final transcript only, quiet/grace cancellation, one turn request |
| A5 zero-model briefing | NOT RUN | Canonical permissions, periods/currencies, zero provider traffic |
| A6 shell-only PWA | NOT RUN | No offline financial data or native credentials |
| A7 external narrative | FUTURE / NOT AUTHORIZED | Separate privacy and worst-case cost acceptance required |

The natural-language/report expansion work remains in a separate isolated development worktree for reconciliation into A2. Its earlier explicit-Send-only voice behavior is superseded by A4; it is not claimed as complete V2.2 implementation.

## Verification and release

AGY headless availability was verified with a literal `Hello` response. Its bounded health implementation reached the worker time checkpoint without a final completion report. Codex inspected the partial work, corrected it, and independently completed verification. Worker output is not acceptance.

The clean combined focused run used PHP 8.4.25, PHPUnit 12.5.36 and the owned disposable MariaDB runner:

```text
tests/Support/run-phase8-disposable.php --
  tests/Feature/DeepSeekProduct/DeepSeekSetupTest.php
  tests/Unit/DeepSeekProduct/DeepSeekBalanceHealthTest.php
  tests/Unit/AiV22/SdkToolIntentContractTest.php
  tests/Unit/DeepSeekProduct/DeepSeekSdkAdapterTest.php
```

Result: **106 tests, 429 assertions, zero failures/errors/skips**, 37.340 seconds; disposable schema cleanup confirmed. Targeted PHPStan passed for changed PHP source and tests. Pint and whitespace checks passed. Laravel view compilation and subsequent cache clearing passed in the isolated synthetic environment. No JavaScript/CSS, dependency, migration or canonical financial-writer changes are included; a frontend rebuild is not required for this checkpoint.

Coverage includes fresh password confirmation even for a saved key, revoked membership during the health response, six-per-minute health throttling without paid reservations, preservation of historical unknown-cost exposure under zero model caps, TLS/redirect/proxy/time-limit options, bounded malformed/oversized responses, and fail-closed SDK proposal envelopes. SDK tool definitions cannot execute business actions; the proposal method is not yet connected to runtime turns.

The legacy connection verification marker is retained for compatibility, with truthful UI copy: available provider credit is not proof of a successful conversation. A health check neither resets model counters nor releases old reservations. Actual live balance health is **NOT RUN**. The correction round below adds synthetic local browser verification. Voice accuracy and A2–A6 acceptance remain unverified.

## Exact-head review corrections

Reviewed parent: `cf8f74ec95bac5cc09a7211476134b7f8838d554`; [independent review](https://github.com/Bassamalsaqqa/account/pull/38#pullrequestreview-5502387495) and [correction directive](https://github.com/Bassamalsaqqa/account/pull/38#issuecomment-6143930303). All four findings are corrected in source; independent exact-head re-acceptance is still pending.

| Finding | Corrected behavior | Focused evidence |
|---|---|---|
| Sticky credential verification | Unchanged verified keys remain verified after timeout, 429, 5xx or malformed replies. Latest sanitized health outcome/time is kept separately in revision-scoped immutable audit events. HTTP 401 marks the key unverified; no credit preserves prior key verification but prevents setup Start Using. New/replaced keys remain unverified until a successful healthy response; revoked keys cannot refresh. | Setup transient-error fixtures, 401/no-credit, replacement timeout, concurrent replacement and revoked membership during I/O. |
| Empty balance arrays | Valid empty `balance_infos: []` honors `is_available`; an empty object `{}` still fails schema validation. Native array type, bounded bytes/entries/decimal strings and existing transport controls are retained. | True/false empty arrays plus malformed empty-object fixture and original transport negative cases. |
| Tool-schema parity | `AssistantIntentProposal` validates raw names, paired calendar dates (maximum 366 days), currency shape and finite grouping/comparison values. It has no query/execution methods or resolved IDs. The SDK uses this proposal DTO, not the narrower executable `AssistantReportPlan`, which remains unchanged. | Expanded AR/EN named/date/comparison SDK proposals; raw IDs, unknown fields, invalid dates/enums and oversized/control-bearing names fail. One model response, no execution or result egress. |
| One-click saved-key refresh | An authenticated active authorized Owner uses Refresh Connection Health without re-entering a password or ticking consent again. Existing current scoped consent is required, never minted by refresh. Key provisioning/replacement, transition, enablement and material consent changes retain explicit protected actions. | Repeated Livewire refresh; missing/materially changed consent; connection/policy revisions and fresh Owner checks; actual local browser click without setup prompts. |

The final combined relevant subset adds `tests/Unit/AiV22/AssistantIntentProposalTest.php` to the four targets above: **173 tests / 626 assertions, zero failures/errors/skips**, PHP 8.4.25, 51.369 seconds, 86 MiB. The owned schema was cleaned. Targeted PHPStan, Pint, Blade compilation/cache clearing and whitespace checks passed. No broad suite, CI, dependency changes or migrations were used.

Local Edge verification used a newly owned disposable schema, synthetic Owner and synthetic encrypted key, fake free-GET responses and blocked model dispatch. Observed: one-click success; timeout retained Key Previously Verified and Start Using while showing failure; Arabic RTL at 390 px displayed valid empty/no-credit separately and hid Start Using, with no horizontal overflow. English desktop and Arabic mobile screenshots are retained in ignored local evidence. A later browser extension timeout prevented additional viewport checks; those are not claimed. The QA server was stopped and its owned schema cleanup confirmed. Browser checks recorded three fake free GETs and zero assistant requests, invoices, purchases, posting batches or stock movements.

Scope limitation: this checkpoint controls setup verification and Start Using; it does not add a new verification-status guard to the existing paid launcher dispatch path. Existing server consent, authorization, revocation and budget checks remain in force. Do not interpret setup gating as newly proved global paid-runtime blocking. Canonical name resolution, supported-filter authorization and local execution belong to A2, which has not begun.

AGY's isolated correction attempt timed out without producing changes. Codex completed the corrections and verification. A separate read-only source review found the empty-object edge case, which was fixed and included in the final passing run. No protected Owner original or unrelated worktree was changed; ignored local QA/worker artifacts are deliberately retained.

No live paid calls, production financial mutations, counter resets, cap increases, merges or deployment are part of this source checkpoint. Production Owner-verified connection success remains historical evidence; development fake responses do not certify real dictation accuracy, billing or live tool behavior.
