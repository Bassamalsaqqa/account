# DeepSeek setup and bounded smoke test — 2026-10-12

## Source and authority

- Deployed source and correction baseline: `d9d9ede3e42926f9b51084a3ed0ba224f0f3fa66`.
- Correction branch: `fix/ai-settings-limits`; this record does not authorize merge or deployment.
- Owner authorized one saved company DeepSeek text connection, explicit scoped consent, local report questions and at most two non-confidential smoke requests, with USD 0.01/request and USD 0.02 total limits. Voice, fallback and private BYOK routes were excluded from this test.
- Credential entry and password confirmation were completed by the Owner through the existing write-only form. No credential value was retrieved from chat/logs, exported, recorded in evidence or added to source.

## Observed production results

| Check | Result | Evidence |
| --- | --- | --- |
| Source identity and availability | PASS | Exact deployed baseline; maintenance off |
| Saved DeepSeek connection | PASS for configuration only | One active `deepseek / deepseek-flash / global / text_structured` company connection; no provider verification implied |
| Global entry point | PASS | Business assistant visible in authenticated English and Arabic layouts after policy save/reload |
| English report question | PASS, local computation | `Sales summary this month in ILS`; canonical Sales Summary source link, ILS filter, 2026-10-01 through 2026-10-31 and Asia/Hebron period |
| Arabic report question | PASS, local computation | Equivalent Arabic question; same canonical report, currency and period; Arabic presentation |
| Transaction smoke request | FAIL before dispatch | Non-confidential Arabic unsaved-sale instruction refused with a safe generic message |
| Actual DeepSeek connection/response | NOT VERIFIED | No provider request created; no retries or standalone paid probe |
| Sales/Purchase unsaved-form handoff | NOT RUN to completion | Configuration refusal prevented handoff; Purchase attempt not started |
| Tokens/provider invoice cost | NOT AVAILABLE | Zero request rows; no paid dispatch or observed provider token usage |
| Business preservation | PASS for this test window | Counts and full-row hashes unchanged across 24 inspected business tables, including canonical posting, stock, Sales/Purchasing documents, allocations, numbering and master data |

The request inventory remained zero at the final read-only checkpoint, `2026-10-12T11:12:48Z`. Evidence contains only hashes, counts and sanitized operational metadata; private inspection scripts/results remain outside tracked publication files. This is a narrow integration/setup check, not a repeat production acceptance or disaster-recovery proof.

## Confirmed cause and bounded correction

Editing the request-count fields in Settings submits strings such as `"2"`. Laravel's integer validator accepts these values, but the saved JSON retained strings. `AiRequestBudget::limit()` correctly requires integers and refused the saved policy with `budget_configuration` before creating a reservation or making HTTP requests. The selected provider, consent fingerprint and pricing were present and valid; this failure is not evidence of a DeepSeek authentication or network defect.

Normalize validated policy limits to integers before persistence. Keep the existing validator bounds, budget guard, tenant/role checks, consent fingerprints and provider dispatch controls. An existing affected policy must be saved again after the source correction is released; that creates a fresh revision and requires normal consent refresh. No migration or direct production database correction is proposed.

The report allowlist and connection pricing inputs now update immediately when their controlling selections change, using existing Livewire bindings. These are unsaved UI updates; policy persistence still requires Save.

## Focused source verification

- Before correction: the new edited-count regression failed exactly on string `"2"` versus integer `2` (1 test, 2 assertions, 1 failure). Its owned disposable MariaDB schema was cleaned.
- Corrected combined subset: `AiSettingsSecurityTest`, `AiGovernanceTest` and `QuestionGovernanceUiTest` — **31 tests, 137 assertions**, zero failures/errors/skips. The owned disposable MariaDB schema was cleaned.
- Regressions save browser-like string request counts, reserve two requests, refuse the third and reject zero/fractional/oversized/non-numeric limits without changing policy or sending HTTP.
- Changed PHP Pint check: PASS. Targeted PHPStan on `AiGovernance.php`: zero errors. Whitespace check: PASS. Targeted settings markup detector: no findings.
- No JavaScript, CSS, dependencies, lockfiles or migrations changed. No broad suite, GitHub CI, production financial operation or paid request was run.
- Post-release Linux/browser verification of the corrected controls and actual DeepSeek dispatch remain pending; local source checks are not live integration acceptance.

## Pricing and privacy truth

The smoke policy uses conservative published peak/cache-miss prices of USD 0.30 per million input tokens and USD 1.20 per million output tokens, checked on 2026-10-12. These are budgeting estimates, not provider invoice costs; off-peak/cache-hit rates can be lower. See [DeepSeek model/pricing documentation](https://api-docs.deepseek.com/quick_start/pricing/).

The [general DeepSeek privacy policy](https://cdn.deepseek.com/policies/en-US/deepseek-privacy-policy.html) identifies the operator, China storage and variable retention. It does not establish an independently verified API-specific retention guarantee for this application. No zero-retention or no-training guarantee was invented. Only the bounded non-confidential smoke instructions were approved for dispatch; stored reports and business records were not sent.

## Setup simplification recommendation

Provide a one-provider path: select provider, securely enter key/password, set a small budget, review sourced pricing/privacy and consent, then run an explicitly billable connection test. Show the primary connection automatically when only one allowed connection exists. Group voice, fallback, personal keys, per-user limits, detailed report allowlists and retention controls under Advanced. Keep server-side permissions, spending limits and explicit consent intact; distinguish local validation from real provider testing.

This branch contains a bounded bug correction, not that larger setup redesign. Production code is unchanged; the live smoke test stays stopped until a corrected release and a new test attempt are authorized. No production financial record or fixture was created, no production migration was run, and no provider retry, merge or deployment occurred during this assignment. Synthetic fixtures and schema migrations were confined to the owned local disposable test schemas.
