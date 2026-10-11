# Direct provider contracts

Authority metadata: Governed by protected local specification `ACCOUNTING_PHASE12_FINAL_SPEC_ARCHITECT_REVIEWED_2026-10-11.md`, SHA-256 `B080FC50222DC3F8E0AE6E8A3701BD4151E0CC020AD9FEEF14DE205521A5FB3F`, as amended by the Owner-authorized P0 clauses of protected `SMALL_TRADER_UNIFIED_AI_ASSISTANT_DESIGN_HANDOFF_2026-10-11.md`, version 1.0 dated 2026-10-11, SHA-256 `DB6D598B24E36205D6118A94C4488CF8DCC9ED3969FFC2585380A014024EB115` (both remain local, private, and unpublished; see [P0 DNS and consent amendment](PHASE_12_P0_DNS_CONSENT_AMENDMENT.md)).

Catalog revision: 2026-10-11.1; checked 2026-10-11 against official documentation. A configured credential is **Configured — not yet verified**. Source QA uses mocked requests only; no connection or provider billing proof is claimed.

- [DeepSeek models](https://api-docs.deepseek.com/quick_start/pricing/): deepseek-flash, chat JSON-object output. JSON-object mode is not strict-schema compliance; all output requires local validation.
- [Qwen structured output](https://www.alibabacloud.com/help/en/model-studio/qwen-structured-output): qwen-plus uses JSON-object output. No blanket claim of strict JSON-schema support across models or regions.
- [Qwen ASR](https://help.aliyun.com/en/model-studio/qwen-asr-api-reference): qwen3-asr-flash uses the synchronous compatible chat endpoint, input_audio data URI and asr_options. Beijing and Singapore legacy endpoints remain supported; keys are region-specific. Encoded audio is bounded separately. No public audio URLs or async storage jobs are used.
- [Groq speech](https://console.groq.com/docs/speech-to-text): Whisper multipart transcription; reserve at least ten seconds of billing and round micro-unit estimates upward.
- [OpenAI structured output](https://developers.openai.com/api/docs/guides/structured-outputs): gpt-4o-mini uses strict JSON schema; schema adherence does not establish financial correctness.
- [OpenAI speech](https://developers.openai.com/api/docs/guides/speech-to-text): gpt-4o-mini-transcribe and whisper-1 use multipart file transcription.

Routes, models and capabilities are curated server-side in `ProviderCapabilityRegistry`. Outbound HTTPS transport verifies TLS peer and hostname certificates (`verify => true`), rejects redirects (`withoutRedirecting()`) and proxies (`proxy => ''`, `CURLOPT_PROXY => ''`), resolves curated registry hosts via native cURL / Guzzle DNS, and bounds response bodies (128 KiB stream cap, JSON decode depth 24) and a 5s connect timeout, read_timeout=5 option and native 5s low-speed safeguard. No automatic retries. Only operational outages can be considered for a separately consented fallback.

Pricing is dated policy data requiring approval, not a permanent hardcoded tariff. Estimated/reserved amounts are integer USD micro-units and never accounting entries. Missing provider cost is UNKNOWN, not zero or an invoice-confirmed charge. Experimental direct audio-to-structured extraction has an interface only and is not selectable.

## Direct-dispatch normal-DNS transport and deadline gate

Direct transport (`DirectProviderTransport`) executes authenticated HTTPS requests directly to curated upstream provider hosts:

1. **Curated endpoints and server-owned paths**:
   - Outbound requests MUST target only hostnames registered in `ProviderCapabilityRegistry` (`api.deepseek.com`, `dashscope.aliyuncs.com`, `dashscope-intl.aliyuncs.com`, `api.openai.com`, `api.groq.com`).
   - Request paths are strictly server-owned constants (`/chat/completions` or `/audio/transcriptions`).
   - The application strictly refuses arbitrary URLs, client-supplied IP addresses, ports, or non-HTTPS schemes. Scheme is unconditionally HTTPS (port 443).

2. **Native DNS resolution superseding destination pinning**:
   - Outbound requests use native system DNS resolution handled by the PHP cURL extension and Guzzle's cURL-backed handler (`CurlHandler`).
   - The prior requirement for manual operator public IPv4 destination address pinning (`config('ai_assistant.provider_ipv4...')`) and `CURLOPT_RESOLVE` is removed from current direct transport specifications by explicit Owner authorization ([P0 DNS and consent amendment](PHASE_12_P0_DNS_CONSENT_AMENDMENT.md)).
   - The application does NOT implement a custom runtime DNS resolver or manual background DNS cache refresh.
   - Historical checkpoint note: Earlier chronological test statements (e.g. at commits `04bdb40`, `f254cf7`, and `760a153`) testing static destination pinning remain authentic historical checkpoint evidence, now formally superseded in active scope by the P0 amendment.

3. **Strict TLS peer and hostname verification**:
   - TLS peer and hostname verification remain strictly enabled (`verify => true`) on every connection. Invalid or mismatched certificates fail closed.

4. **Hardened execution and shared monotonic deadline**:
   - Connect timeout: 5 seconds. Read timeout option: 5 seconds; the selected native cURL handler additionally applies a five-second low-speed timeout, with the shared total deadline authoritative.
   - Monotonic foreground deadline: `AiProviderDeadline` bounds execution to at most 25 seconds (from `AI_ASSISTANT_TIMEOUT_SECONDS`, default 20s), respecting PHP execution headroom (`max_execution_time - 5s`). This deadline allowance is shared across primary and optional fallback attempts.
   - Response streams are strictly capped at 128 KiB (131,072 bytes), with JSON decode depth capped at 24.
   - Automatic retries are disabled.

5. **Realistic SSRF threat disclosure**:
   - Curated server-owned hostnames and strict TLS certificate verification narrow user-controlled fetch vectors, but do NOT guarantee that the resolved IP address is a public address.
   - Hostile DNS poisoning, rebinding, or private IP resolution could cause cURL to initiate a TCP/TLS connection attempt to an internal/loopback address before certificate validation fails.
   - Hostinger shared-hosting egress, DNS resolver latency, cURL behavior, and TLS smoke tests remain **NOT VERIFIED** until authorized operational testing occurs.

6. **Legacy gateway isolation**:
   - `GatewayTransport` and `AI_GATEWAY_PINNED_IPV4` remain unchanged, optional, and disabled by default.
   - Legacy gateway is NOT the active operational mode; requires separate architect and Owner review gates prior to activation.

7. **Scoped consent and reporting boundaries**:
   - Consent is scoped strictly to the selected primary provider/model and approved fallback route per capability (`text_structured`, `audio_transcription`), NOT all visible catalog choices.
   - Speech method choices: cloud speech disclosure is included only when runtime speech is enabled and the effective company-permitted method is `cloud` or `local_if_supported`; `local_only` and `disabled` request no cloud speech consent.
   - Explicit acceptance of updated disclosure is required; policy or connection revisions invalidate consent; selection changes refresh disclosure and reset the checkbox.
   - No report data consent is implicitly approved. Reports and conversational assistant capabilities belong to future Unified P1 as a separate pull request AFTER Phase 12 source acceptance and Owner release decisions; they are NOT implemented in PR #25.

8. **Configuration switches and open operational blockers**:
   - Direct transport requires `AI_ASSISTANT_PROVIDER=direct` as well as `AI_ASSISTANT_ENABLED=true` and `AI_PROVIDER_APPROVED=true`; speech also requires `AI_SPEECH_ENABLED=true`. The `none` default and superseded gateway mode cannot dispatch a direct provider. Local deterministic parsing does not grant provider activation.
   - Live ASR transcription, paid provider calls, physical phone microphones, dialect accuracy, and Hostinger hosting resource quotas remain **NOT VERIFIED**.
   - Deferred Phase 10 independent off-host backup, isolated recovery/key custody, and real password-reset email proofs remain open.
