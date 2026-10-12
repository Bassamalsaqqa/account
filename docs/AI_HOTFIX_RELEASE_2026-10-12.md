# AI Settings Hotfix Release and Provider Dispatch Verification — 2026-10-12

## Release identities and authority

- **Previous live/main:** `d9d9ede3e42926f9b51084a3ed0ba224f0f3fa66`.
- **Accepted PR #30 head:** `b2270166e925727a60664042b2cdbcb34dab5bdd` ([PR #30](https://github.com/Bassamalsaqqa/account/pull/30); Owner authorized controlled merge/release).
- **Deployed main:** `455c1e368aa1b5da0d5a61913be3c5af079cf0ef`.
- **Runtime change boundaries:** Validated request-limit integer normalization in `AiGovernance` and two `wire:model.live` dependencies in `AISettings` Blade. No migrations, dependencies, compiled assets, canonical writers, or unrelated modules changed.
- **Evidence distinction:** Hostinger checks (maintenance, streaming checkpoints, caches, database audits, reconciliations, web GETs) and Owner browser actions (UI policy save, consent renewal, live assistant submission) were operated by Codex and the Owner. AGY implementation worker reviewed accepted source and authored this factual record without production/database access or credentials.

## Outcome summary

| Check / Dimension | Outcome | Operational evidence |
| --- | --- | --- |
| **Release source identity** | PASS | Deployed main tree matches the accepted PR #30 head tree; runtime changes bounded to integer normalization and Blade bindings. |
| **Database & schema preservation** | PASS | 89 tables, 67 applied migrations, 0 pending; 86 nonvolatile tables exact full-row/count fingerprints matched. No production migrations or fixtures. |
| **Financial reconciliations** | PASS | All 6 reconciliations healthy (Accounting, Inventory, Sales, Payables, Money, Phase 7); 0 financial saves/posts. |
| **Web & API routing baseline** | PASS | 26 authenticated page GETs (AR/EN) + session 200 + guest 401 = 28 checks; 0 fresh application errors. |
| **AI settings UI configuration** | PASS | Saved in Owner browser: daily limits 2; $0.01/req, $0.02 daily/monthly; renewed consent; key remains `configured_unverified`. |
| **Live assistant dispatch attempt** | FAIL | 1 Arabic unsaved-sale instruction at 11:44:00 UTC failed fail-closed with `provider_invalid`; 0 retries. |
| **Provider connectivity & charges** | NOT VERIFIED | Pre-dispatch failure; network connectivity, provider authentication, and returned tokens/charges remain unknown. |
| **Cost & token reservation** | FAIL-CLOSED | 6,600 micros reserved fail-closed; input/output units `null`; reported cost `null`; cost status `unknown`. |
| **Unsaved form handoff** | NOT VERIFIED | Aborted before proposal extraction or draft Sales/Purchase resolution; no form populated. |
| **Operational stop & UX status** | STOP ENFORCED | Immediate stop on failure; 0 retries; Quick Connect / UX implementation not begun and deferred. |

## Operational preservation and environment checks

- **Maintenance and rollback controls:** Enforced HTTP 503 maintenance, request drain, deployment lock, encrypted on-host streaming SQL/state checkpoints, and Git rollback bundle (`d9d9ede3e42926f9b51084a3ed0ba224f0f3fa66`). On-host checkpoints do not prove independent off-host disaster recovery; deferred Phase 10 gates remain unchanged.
- **Application runtime caches:** Composer optimized autoload and config/route/view/event caches were rebuilt; Livewire Linux aliasing was verified.
- **Database integrity:** 89 tables, 67 applied migrations, 0 pending; all 86 nonvolatile tables matched exact full-row/count fingerprints before and after release (excluding volatile `sessions`, `cache`, and `cache_locks`).
- **Data safety & reconciliations:** All 6 reconciliations passed (Accounting, Inventory, Sales, Payables, Money, Phase 7); production `.env`, user identities, permissions, document sequences, and uploaded assets remained untouched.
- **Fixture boundaries:** No production migrations or business fixtures were run (synthetic migrations and fixtures were strictly confined to separate local disposable test databases).

## Saved policy and recorded live request

Through the Owner browser, company policy was saved with daily company/user request limits 2, per-request ceiling $0.01 (10,000 micros), and daily/monthly ceilings $0.02 (20,000 micros). Integer normalization resolved the earlier configuration rejection. Existing text consent was renewed; speech, fallback, and personal BYOK routes remained disabled. The company-managed BYOK text route remained selected. The stored key was neither retrieved nor exported and remains `configured_unverified`.

At 2026-10-12 11:44:00 UTC, one non-confidential Arabic instruction was submitted through the assistant UI. Persisted audit fields in `assistant_requests`:
- `purpose`: `intent`
- `provider`: `deepseek`
- `model`: `deepseek-flash`
- `region`: `global`
- `status`: `failed`
- `failure_code`: `provider_invalid`
- `attempt_count`: 1
- `input_units`: `null`
- `output_units`: `null`
- `estimated_cost_micros`: 6600
- `reserved_cost_micros`: 6600 (retained fail-closed; no refund or invented billing outcome)
- `reported_cost_micros`: `null`
- `cost_status`: `unknown`

## Verified local diagnosis: Guzzle proxy option conflict

- **Local diagnostic reproduction:** Codex independently exercised accepted `DirectProviderTransport` locally with dummy credentials and a loopback-only fixture without external provider calls, reproducing `provider_invalid`.
- **Root cause isolation:** Native options exposed an `InvalidArgumentException` at `CurlFactory::rejectConflictingCurlOptions`: locked Guzzle 8.2.0 rejects raw `CURLOPT_PROXY` and explicitly requires Guzzle's `proxy` option.
- **Source defect in transport:** In `DirectProviderTransport.php`, options included both `'proxy' => ''` and `'curl' => [CURLOPT_PROXY => '']`. Production and local `CurlFactory` match source.
- **Operational consequence:** The failure occurred prior to socket dispatch. No remote provider response was received; remote authentication, reachability, token usage, and invoiced cost remain unverified. This local probe is not proof of DeepSeek connectivity.
- **Independent local correction probe:** Removing only raw `CURLOPT_PROXY` lets the same accepted transport consume a synthetic loopback JSON response successfully. Guzzle's explicit `proxy => ''` stays in place; TLS, redirects, timeouts and byte bounds remain enforced. This is a source correction, not another production release or provider test.

## Provider documentation, references, and deferred milestones

- **API configuration:** The selected model and endpoint match the [DeepSeek API guide](https://api-docs.deepseek.com/). The [published peak pricing](https://api-docs.deepseek.com/quick_start/pricing/) supports the conservative USD 0.30 input / USD 1.20 output per million token reservation rates used here; these are not observed billed costs.
- **Error & rate limit taxonomy:** Error classification is documented in [DeepSeek error codes](https://api-docs.deepseek.com/quick_start/error_codes/); rate limit handling follows [DeepSeek rate limits](https://api-docs.deepseek.com/quick_start/rate_limit/). No evidence suggests provider rate limiting caused the live failure.
- **Corrective state:** The separate `fix/ai-native-transport` review candidate removes only raw `CURLOPT_PROXY`, updates the two existing option assertions and adds a bounded native loopback fixture with regressions. It has not been merged, deployed or independently architecturally accepted. Production remains at `455c1e368aa1b5da0d5a61913be3c5af079cf0ef`.
- **Milestone deferral:** Quick Connect and AI UX implementations remain not begun and deferred. No retries or additional paid calls were made.

## Correction verification and review boundary

- Codex independently ran one combined subset: `DirectProviderNativeTransportTest`, `DirectProviderDnsPolicyTest` and `DirectProviderContractTest`: **26 tests, 208 assertions, 0 failures/errors/skips**, PHP 8.4.25. Database access was disabled; all native HTTP traffic used disposable loopback fixtures and dummy credentials. Exact PHP file hashes were checked on integration.
- Native cases cover JSON and multipart responses, whitespace keepalives, poisoned HTTP/HTTPS proxy variables, 302 refusal, oversized/malformed/empty bodies, 400/401/402/422 rejection, 429/500/503 outage and truncated text. Counters assert one request per negative case; fixture startup/connections/process cleanup are bounded. Loopback HTTP proves neither remote DNS nor TLS nor authenticated DeepSeek availability.
- Independent Pint on four changed PHP files, targeted PHPStan for `DirectProviderTransport`, and `git diff --check`: **PASS**. No frontend changes, migration, broad integration suite or GitHub CI was required for this one-line transport correction.
- AGY supplied the local fixture/tests and record, then corrected them after Codex review. Its initial native-test turn expired while waiting; elapsed time was not accepted as a passing result. Final worker output and actual source were inspected before independent verification. Worker-reported additional unit runs are not substitutes for the independently executed subset above.
- The next live attempt requires acceptance and controlled release of this correction. The original two-attempt / USD 0.02 total scope has one recorded attempt already consumed; no automatic retry, additional provider, credential export, financial save/post or expanded spending is authorized by this record.
