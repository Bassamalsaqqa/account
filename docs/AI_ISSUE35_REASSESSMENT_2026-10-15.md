# Issue #35 reassessment

Baseline: `360f7bcef3bb14041c3cfd52731f39f71a0419be` (deployed V2.4).

The original diagnosis that the launcher collapses provider failures to one generic error is outdated at this baseline. `AssistantLauncher::safeReason()` already permits distinct authentication, credit, format, parameter, rate-limit, timeout, outage and invalid-response reasons. The relevant launcher catches use `AssistantUnavailable::diagnosticReason()`. `DeepSeekSdkAdapter` classifies supported failures; `AiDiagnostics` records only an allowlisted failure code, stage and bounded HTTP status. Unknown failures remain a bounded generic category. This does not establish the cause of any current production incident.

## Reproduced defect and correction

`AssistantDiagnosticTest::test_provider_authentication_failure_is_shown_without_raw_response_content` supplied a chat-completion response to `DeepSeekQuickConnect::testConnection()`. That connection check now expects the balance-health response. The stale fixture failed at connection verification before reaching the intended mocked HTTP 401.

The correction supplies a synthetic balance-health response followed by the existing synthetic 401. It changes no runtime code, provider routing, credentials, budgets or diagnostic policy.

## Independent evidence

- Baseline filtered test: 1 test, 1 assertion, failure (`verified` expected; `failed` returned).
- Corrected `AssistantDiagnosticTest`: **4 tests, 167 assertions passed**, PHP 8.4.25, owned disposable MariaDB schema; schema cleaned.
- HTTP requests were faked with stray-request prevention. No paid provider requests or production access occurred.
- Tests exercise distinct sanitized provider errors and verify raw response content is withheld. This is synthetic source-level evidence, not live provider availability evidence.

## Remaining uncertainty

If an operator still observes a failure, request separately authorized, content-free evidence: displayed reason, allowlisted stage/status, timestamp and route/model configuration identifiers. Do not collect credentials, user prompts, provider response bodies or financial payloads. Investigate a concrete SDK failure only after safe evidence distinguishes it; no speculative runtime refactor is warranted now.

Off-host recovery, isolated restoration and real password-reset email verification remain separately tracked and unverified by this assignment.
