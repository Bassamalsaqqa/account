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

The legacy connection verification marker is retained for compatibility, with truthful UI copy: available provider credit is not proof of a successful conversation. A health check neither resets model counters nor releases old reservations. Actual live balance health and practical browser verification of this new setup copy are **NOT RUN**. Voice accuracy and A2–A6 acceptance remain unverified.

No live paid calls, production financial mutations, counter resets, cap increases, merges or deployment are part of this source checkpoint. Production Owner-verified connection success remains historical evidence; development fake responses do not certify real dictation accuracy, billing or live tool behavior.
