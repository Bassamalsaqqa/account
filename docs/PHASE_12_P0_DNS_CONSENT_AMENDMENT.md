# Phase 12 P0/P0b amendment — normal-DNS direct transport and scoped consent

Authority metadata: Governed by protected local specification `ACCOUNTING_PHASE12_FINAL_SPEC_ARCHITECT_REVIEWED_2026-10-11.md`, SHA-256 `B080FC50222DC3F8E0AE6E8A3701BD4151E0CC020AD9FEEF14DE205521A5FB3F`, as amended by the Owner-authorized P0 clauses of protected `SMALL_TRADER_UNIFIED_AI_ASSISTANT_DESIGN_HANDOFF_2026-10-11.md`, version 1.0 dated 2026-10-11, SHA-256 `DB6D598B24E36205D6118A94C4488CF8DCC9ED3969FFC2585380A014024EB115`. Both governing documents remain local, private, and unpublished.

Status: Owner-authorized architectural amendment to PR #25 draft at exact baseline `760a1531716c0165a1e0dc5b801178aaceed0811` on main `c2e39a32271b14de99e6ffda55ec5b0ea400cb7e`. Owner now explicitly authorizes normal-DNS direct transport, superseding the prior operator IPv4 destination pinning requirement. Direct transport and consent corrections are implemented and locally verified by Codex. Renewed independent exact-head architect acceptance is pending; this is not release or activation approval. [Provider contract](PHASE_12_PROVIDER_CONTRACT.md), [direct provider protocols](PHASE_12_DIRECT_PROVIDER_PROTOCOLS.md), [ADR 0012](adr/0012-ai-sales-proposals-and-confirmation.md), [final source review](PHASE_12_FINAL_SOURCE_REVIEW.md), and [implementation ledger](PHASE_12_FINAL_IMPLEMENTATION_LEDGER.md) incorporate this amendment.

---

## 1. Context and Owner authorization

The initial Phase 12 direct transport design required deployment operators to configure and maintain static public IPv4 address pins (`config('ai_assistant.provider_ipv4.<provider>.<region>')`) resolved via cURL `CURLOPT_RESOLVE`, aiming to avoid synchronous PHP socket hangs during runtime DNS.

On 2026-10-11, the Owner instructed Codex to implement the architect's bounded corrections against PR #25 at exact head `760a1531716c0165a1e0dc5b801178aaceed0811`:

1. **P0 normal-DNS direct transport**: Replacing static destination IPv4 address pinning with native cURL / Guzzle DNS resolution over curated HTTPS registry hostnames.
2. **P0b per-capability scoped consent**: Narrowing user and company consent strictly to the selected primary provider and approved fallback route per capability, rather than blanket approval for all catalog entries.

This amendment supersedes the prior direct pinning requirement in normative specifications without rewriting the protected original specification. Historical chronological checkpoint statements (documenting earlier tests of destination pinning at commits `04bdb40`, `f254cf7`, and `760a153`) are preserved as authentic historical records and are explicitly superseded by this amendment.

---

## 2. P0: Normal-DNS direct transport specification

Direct transport (`DirectProviderTransport`) executes authenticated HTTPS requests to curated upstream providers:

1. **Curated HTTPS registry hosts and server-owned paths**:
   - Outbound requests MUST target only hostnames registered in `ProviderCapabilityRegistry` (Catalog Version `2026-10-11.1`):
     * DeepSeek: `https://api.deepseek.com`
     * Qwen (Beijing): `https://dashscope.aliyuncs.com/compatible-mode/v1`
     * Qwen (Singapore): `https://dashscope-intl.aliyuncs.com/compatible-mode/v1`
     * OpenAI: `https://api.openai.com/v1`
     * Groq: `https://api.groq.com/openai/v1`
   - Target paths are strictly server-owned constants (`/chat/completions` or `/audio/transcriptions`).
   - The application NEVER accepts client-supplied or user-controlled URLs, IP addresses, ports, or schemes. The scheme is unconditionally HTTPS (port 443).

2. **Native cURL / Guzzle DNS resolution**:
   - Outbound HTTP requests use native system DNS resolution handled by the PHP cURL extension and Guzzle's cURL-backed handler (`CurlHandler`).
   - Static IP configuration (`config('ai_assistant.provider_ipv4...')`) and `CURLOPT_RESOLVE` are removed from the direct transport pathway.
   - The application does not implement a custom PHP runtime DNS resolver or manual background DNS cache refresh.

3. **Strict TLS peer and hostname verification**:
   - TLS peer verification (`verify => true`) and hostname verification remain mandatory on every connection.
   - Invalid or hostname-mismatched certificates fail closed. Mocked connection failures map to sanitized `provider_outage`; no real certificate or hosting proof is claimed.

4. **Transport hardening and bounded execution**:
   - **No redirects**: `withoutRedirecting()` ensures HTTP redirects (301, 302, 307, 308) are strictly refused.
   - **No proxies**: Proxies are explicitly disabled (`proxy => ''`, `CURLOPT_PROXY => ''`).
   - **No application retries**: No retry middleware is added. Native request bodies refuse rewind/replay; Expect negotiation is disabled. Only the existing approved outage fallback may make a second application attempt within its reserved budget.
   - **Short connect and transfer limits**: Connect timeout is 5 seconds. The `read_timeout=5` option is retained, but it is not a native cURL read guarantee; cURL additionally uses a five-second low-speed safeguard and the total deadline.
   - **Monotonic foreground deadline**: Every foreground hop is governed by `AiProviderDeadline`, bounded to at most 25 seconds (respecting `max_execution_time - 5s`). This deadline allowance is shared across primary and optional fallback attempts.
   - **Streamed response bounding**: Response body streams are capped at 128 KiB (131,072 bytes). JSON decode depth is capped at 24. Exceeding limits aborts immediately with `AssistantUnavailable('provider_invalid')`.

5. **Realistic SSRF threat modeling**:
   - Restricting target destinations to curated server-owned HTTPS hostnames and enforcing strict TLS peer verification substantially narrows Server-Side Request Forgery (SSRF) and arbitrary URL fetch vulnerabilities.
   - However, curated hostnames and TLS verification **do NOT guarantee that the resolved IP address is a public address**.
   - Under hostile DNS conditions, DNS rebinding, internal resolver poisoning, or split-horizon DNS, a curated hostname could resolve to a loopback (`127.0.0.0/8`) or RFC 1918 private IP address (`10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`).
   - In such a scenario, cURL initiates a TCP handshake and sends a TLS ClientHello to the resolved private IP before the TLS certificate check fails (due to certificate mismatch or connection reset), potentially generating internal network traffic.
   - Real Hostinger shared-hosting egress, DNS resolver latency, cURL behavior, and TLS smoke tests remain **NOT VERIFIED** until authorized operational testing occurs.

6. **Legacy gateway transport isolation**:
   - The superseded legacy gateway transport (`GatewayTransport`) and its optional configuration (`AI_GATEWAY_PINNED_IPV4`) remain unchanged, optional, and disabled by default.
   - Legacy gateway is NOT the active operational mode and cannot be used for direct multi-provider routing. Any future gateway activation requires a separate architect and Owner review gate.

---

## 3. P0b: Per-capability scoped consent and governance

Direct consent is stored in tenant/user-scoped `AiUserPreference.consents` and governed by `AiCompanyPolicy`. `AiConsent` remains historical gateway infrastructure:

1. **Per-capability route scoping**:
   - Consent is scoped strictly to the selected primary provider/model and approved fallback route for each capability (`text_structured`, `audio_transcription`).
   - Consent does NOT grant blanket authorization for all visible catalog providers or arbitrary future models.
   - Each capability consent record binds:
     * Specific provider, model, and region.
     * Specific credential source (`company_byok` or `personal_byok`).
     * Privacy terms, data retention policy, and operator disclosure.
     * Designated fallback route (if configured).

2. **Speech method constraints**:
   - Cloud speech requires the runtime `AI_SPEECH_ENABLED=true` flag and a permitted effective company/user speech method.
   - Cloud disclosure is included for:
     * `cloud`: Audio sent to the consented upstream speech transcription provider.
     * `local_if_supported`: Installed genuinely local recognition is attempted; when unavailable the permitted cloud route remains subject to its own consent. A failed local start does not silently upload. `local_only` and `disabled` request no cloud speech consent; unsupported local-only offers manual text.

3. **Explicit disclosure and invalidation**:
   - Users must explicitly accept updated disclosures before dispatch.
   - Policy and connection revisions, provider/model/region/capability, privacy and fallback settings bind the fingerprint. Selecting a different route refreshes disclosure and resets the checkbox; it does not consent that route. Existing consent for an unchanged connection is not silently rewritten.
   - Metadata-only route resolution uses the same selection rules as dispatch without decrypting keys. Accept rederives the selected routes and compares the displayed fingerprints and selection snapshot inside one transaction; stale or undisclosed selections, revoked keys/memberships and foreign/private connections cannot acquire consent. Recording the displayed fingerprints is atomic. Dispatch continues to require fresh authorization and exact consent.

4. **Report data consent isolation**:
   - No consent is granted or implied for report data, financial ledger analysis, or customer/vendor analytics.
   - Phase 12 AI assistant scope is strictly limited to transaction proposal creation and form corrections for Sales and Purchasing forms.
   - Reporting and conversational assistant capabilities belong to future Unified P1, which will be submitted as a separate pull request AFTER Phase 12 source acceptance and Owner release decisions; they are NOT implemented in PR #25.

---

## 4. Quality assurance and pending verification

| Verification area | Status | Details |
| --- | --- | --- |
| P0 normal-DNS transport | PASS ? LOCAL SOURCE QA | No direct pins; curated URLs, mock changing network addresses, redirect/proxy/TLS-error/timeout refusals and actual bounded sink/deadline tests passed. Real DNS/TLS is not simulated by these HTTP fakes. |
| P0b per-capability consent | PASS ? LOCAL SOURCE QA | Selected primary/fallback only, AR/EN disclosure, no implied consent on selection, stale revisions, membership/role/foreign/private isolation, atomic recording and speech-method exclusions passed. |
| Historical test suites | HISTORICAL CHECKPOINT (PASS) | 202 Phase 12 tests / 1,832 assertions, 144 mature canonical tests / 478 assertions, 27 frontend tests recorded at commit 760a153 remain valid historical evidence under prior configuration. |
| Hostinger hosting and egress | NOT VERIFIED | Shared-hosting egress, DNS resolution timing, cURL execution, and memory limits remain unverified pending authorized operational smoke test. |
| Live provider and acoustic proof | NOT VERIFIED | Real provider credentials, paid API calls, physical phone microphones, and dialect accuracy remain unverified activation gates. |
| Phase 10 DR and mail gates | OPEN | Deferred Phase 10 independent off-host backup, isolated recovery/key custody, and real password-reset email proofs remain open. |

## 5. Executed P0 evidence and review handback

Codex took over after all three bounded headless AGY assignments ended with quota failures. Partial files were inspected before integration; inaccurate worker claims about consent storage, speech fallback, gateway class names and native read timeouts were corrected. Worker worktrees and private logs remain preserved; no worker committed or pushed.

| Executed gate | Result |
| --- | --- |
| Focused affected feature batch: provider/deadline/DNS, governance, settings, company controls, speech, budgets, transaction/audio, corrections and both unsaved forms | **128 tests / 895 assertions**, no failures/errors/skips; owned MariaDB schema cleaned |
| Final metadata-versus-dispatch correction: consent scope, governance, company controls and transaction regressions | **35 tests / 189 assertions**, no failures/errors/skips; owned MariaDB schema cleaned |
| Distinct final test cases across these successful runs (latest duplicate results counted once) | **129 tests / 901 assertions**; not a full-suite run |
| Existing integrated economic journey, included in the affected batch | **1 test / 54 assertions**, Accounting, Inventory, Sales, Money, Phase 7 and Payables reconciliations PASS; no provider HTTP |
| Whole-repository Pint; PHPStan, level 6 | PASS, no suppressions |
| Frontend production build | PASS, 61 modules; no new JavaScript or framework |
| Whitespace, local Markdown links, exact changed scope, disabled defaults, protected artifacts and sensitive-content review | PASS; no migrations or canonical economic writer changes |

Reproduction uses PHP 8.4 and `tests/Support/run-phase8-disposable.php` with explicit loopback test opt-in, not persistent application schemas. Targets for the affected batch: `tests/Unit/Phase12/{AiProviderDeadlineTest,DirectProviderContractTest,ProviderCapabilityMatrixTest,DirectProviderDnsPolicyTest}.php` and `tests/Feature/Phase12/{AssistantConsentScopeTest,AiGovernanceTest,AiSpeechPolicyTest,AiCompanyControlsTest,AiSettingsSecurityTest,AiRequestBudgetTest,TransactionAssistantTest,AssistantAudioBoundaryTest,AiFormCorrectionsTest,PurchaseFormCorrectionsTest,UnsavedAssistantFormsTest,FinalAssistantEconomicJourneyTest}.php`. The final correction reran only `AssistantConsentScopeTest`, `AiGovernanceTest`, `AiCompanyControlsTest` and `TransactionAssistantTest`. Pint used `--test`; PHPStan used `analyse --memory-limit=512M --no-progress`; frontend used `npm run build`.

Earlier focused iterations failed: 41 tests / 121 assertions / 13 errors (Laravel fake sink integration), 68 / 365 / one failed 403 assertion, and new-test fixture/instrumentation runs of 21 / 94 (two errors, one failure) and 21 / 109 (one error, one failure). Each was corrected and its coverage passed in the final successful runs; none is claimed as a passing run. Synthetic DNS/address rotation and TLS error tests do not execute a real DNS change or TLS handshake. Local PHP 8.4.25 reports cURL 8.21.0 with AsynchDNS; Hostinger capabilities remain NOT VERIFIED.

The 14 previously inventoried protected artifact identities were checked against all 15 existing copies with zero missing files or hash drift; the additional Unified Assistant handoff hash also matches the authority above. No protected source was staged. Ignored JUnit/logs and interrupted worker files remain in private `.ai` custody. Unrelated worktrees and quarantined schemas were not adopted or deleted.

The correction has no financial/stock writer, schema, dependency, public API, or reporting feature changes. No production access, provider dispatch, real credentials, GitHub CI, merge or deployment occurred. Independent architect acceptance of the exact pushed head remains mandatory. Source merge/deployment and provider activation each need their own Owner decision.

Primary transport references: [Guzzle request options](https://docs.guzzlephp.org/en/stable/request-options.html), [cURL connect timeout](https://curl.se/libcurl/c/CURLOPT_CONNECTTIMEOUT.html), [cURL hostname verification](https://curl.se/libcurl/c/CURLOPT_SSL_VERIFYHOST.html). Installed Laravel/Guzzle code was inspected: a streaming default handler can use PHP streams, so this transport explicitly selects native cURL; bounded sink writes preserve the decoded response cap during transfer.
