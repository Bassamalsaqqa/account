# Direct provider contracts

Catalog revision: 2026-10-11.1; checked 2026-10-11 against official documentation. A configured credential is **Configured — not yet verified**. Source QA uses mocked requests only; no connection or provider billing proof is claimed.

- [DeepSeek models](https://api-docs.deepseek.com/quick_start/pricing/): deepseek-flash, chat JSON-object output. JSON-object mode is not strict-schema compliance; all output requires local validation.
- [Qwen structured output](https://www.alibabacloud.com/help/en/model-studio/qwen-structured-output): qwen-plus uses JSON-object output. No blanket claim of strict JSON-schema support across models or regions.
- [Qwen ASR](https://help.aliyun.com/en/model-studio/qwen-asr-api-reference): qwen3-asr-flash uses the synchronous compatible chat endpoint, input_audio data URI and asr_options. Beijing and Singapore legacy endpoints remain supported; keys are region-specific. Encoded audio is bounded separately. No public audio URLs or async storage jobs are used.
- [Groq speech](https://console.groq.com/docs/speech-to-text): Whisper multipart transcription; reserve at least ten seconds of billing and round micro-unit estimates upward.
- [OpenAI structured output](https://developers.openai.com/api/docs/guides/structured-outputs): gpt-4o-mini uses strict JSON schema; schema adherence does not establish financial correctness.
- [OpenAI speech](https://developers.openai.com/api/docs/guides/speech-to-text): gpt-4o-mini-transcribe and whisper-1 use multipart file transcription.

Routes, models and capabilities are curated server-side. The HTTPS transport verifies certificates, rejects redirects/proxies, pins checked public IPv4 destinations and bounds response sizes/timeouts. No automatic retries. Only operational outages can be considered for a separately consented fallback.

Pricing is dated policy data requiring approval, not a permanent hardcoded tariff. Estimated/reserved amounts are integer USD micro-units and never accounting entries. Missing provider cost is UNKNOWN, not zero or an invoice-confirmed charge. Experimental direct audio-to-structured extraction has an interface only and is not selectable.

## Direct-dispatch destination and deadline gate

Direct dispatch additionally requires a deployment-operator-reviewed public IPv4 address for the exact curated provider/region host. Empty or nonpublic pins refuse before HTTP. The transport performs no synchronous runtime DNS lookup; TLS verifies the curated hostname while cURL uses the reviewed address. Addresses are configuration, never browser/BYOK input. A request-scoped monotonic allowance is shared by the primary and optional fallback, with bounded reads and PHP execution headroom.

Before any separately approved provider activation, the operator must verify current authoritative host resolution, review the public destination, configure the relevant AI_*_IPV4 setting, verify TLS and real hosting timeout behavior, and establish a review/refresh procedure for provider address changes. This source checkpoint configures no actual provider address. Destination rotation may cause a fail-closed outage until revalidated. Hostinger timing and actual provider availability remain NOT VERIFIED.
