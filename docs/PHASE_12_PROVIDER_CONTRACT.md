# Phase 12 provider contract — version 2 (multi-provider direct and unified transaction assistant)

Authority metadata: Governed by protected local specification `ACCOUNTING_PHASE12_FINAL_SPEC_ARCHITECT_REVIEWED_2026-10-11.md`, SHA-256 `B080FC50222DC3F8E0AE6E8A3701BD4151E0CC020AD9FEEF14DE205521A5FB3F`, as amended by the Owner-authorized P0 clauses of protected `SMALL_TRADER_UNIFIED_AI_ASSISTANT_DESIGN_HANDOFF_2026-10-11.md`, version 1.0 dated 2026-10-11, SHA-256 `DB6D598B24E36205D6118A94C4488CF8DCC9ED3969FFC2585380A014024EB115` (both remain local, private, and unpublished; see [P0 DNS and consent amendment](PHASE_12_P0_DNS_CONSENT_AMENDMENT.md)).

Status: Proposed expanded contract for independent review; source P12-M1–M6 submitted for independent review, draft PR #25; exact HEAD acceptance still pending. No vendor selected, billed requests sent, production credentials retrieved, or production features enabled. [ADR 0012](adr/0012-ai-sales-proposals-and-confirmation.md), [direct provider protocols](PHASE_12_DIRECT_PROVIDER_PROTOCOLS.md), [P0 DNS and consent amendment](PHASE_12_P0_DNS_CONSENT_AMENDMENT.md), [source acceptance](PHASE_12_SOURCE_ACCEPTANCE.md), and [implementation ledger](PHASE_12_FINAL_IMPLEMENTATION_LEDGER.md) describe the boundaries.

Superseded historical note: The initial single-gateway Sales-only design (version 1) is superseded. The gateway client remains in the codebase as a legacy compatibility option (`GatewayIntentProvider`, `GatewaySpeechProvider`, `GatewayTransport`), but is NOT the active final operational mode and does not provide universal audio-to-structured or multi-provider capabilities. Historical gateway test evidence does not claim direct provider coverage.

## Scope, boundaries, and activation gates

1. **Owner and architect gates**: Activation requires explicit Owner approval of provider, operator, region, retention, pricing policy, and recipient data location. Independent architect acceptance must approve this contract, routing, credential handling, ceilings, response schemas, and rollout.
2. **Fail-closed defaults**: Default configuration is completely disabled:
   - `AI_ASSISTANT_PROVIDER=direct` (default is `'none'`; legacy gateway is `'gateway'`).
   - `AI_ASSISTANT_ENABLED=true` (default `false`).
   - `AI_PROVIDER_APPROVED=true` (default `false`).
   - `AI_SPEECH_ENABLED=true` (default `false`; mandatory for audio transcription).
   - Positive approved per-request, daily, and monthly company and user cost ceilings are mandatory in server configuration and company policy. Default cost ceilings are zero, failing closed before request dispatch.
   - `AI_DISABLED_COMPANY_IDS` maintains a configuration-controlled tenant disable list.
   - `COMMAND_API_ENABLED=false` stays false in production per ADR 0011.
3. **Out of scope for Phase 12**: Platform billing, platform credit management and platform administrative UI are outside Phase 12. Real provider activation and physical phone/acoustic proofs remain separately authorized activation gates, not completed source evidence. Account operators obtain no implicit tenant or data rights. Manual Sales and Purchasing workflows remain fully functional without AI features or external accounts.

## Direct transport, network security, and deadline gates

Direct transport (`DirectProviderTransport`) executes authenticated HTTPS requests to curated upstream providers without intermediate proxies:

1. **Curated registry endpoints and server-owned paths**:
   - Outbound requests target only hostnames registered in `ProviderCapabilityRegistry` (Catalog Version `2026-10-11.1`): `api.deepseek.com`, `dashscope.aliyuncs.com`, `dashscope-intl.aliyuncs.com`, `api.openai.com`, and `api.groq.com`.
   - Paths are strictly server-owned constants (`/chat/completions` or `/audio/transcriptions`).
   - The application strictly refuses arbitrary URLs, client-supplied IP addresses, ports, or non-HTTPS schemes. Scheme is unconditionally HTTPS (port 443).
2. **Native DNS resolution and supersession of pinning**:
   - Direct transport uses native cURL / Guzzle DNS resolution via an explicitly selected cURL-backed handler (`CurlHandler`).
   - The prior requirement for manual operator public IPv4 destination address pinning (`config('ai_assistant.provider_ipv4.<provider>.<region>')`) and cURL `CURLOPT_RESOLVE` is removed from current direct transport specifications by explicit Owner authorization ([P0 DNS and consent amendment](PHASE_12_P0_DNS_CONSENT_AMENDMENT.md)).
   - The application does NOT implement a custom runtime DNS resolver or manual background DNS cache refresh.
   - Historical checkpoint note: Earlier chronological test statements (e.g. at commits `04bdb40`, `f254cf7`, and `760a153`) testing static destination pinning remain authentic historical checkpoint evidence, now formally superseded in active scope by the P0 amendment.
3. **Strict TLS peer and hostname verification**:
   - TLS peer verification (`verify => true`) and hostname verification remain strictly enforced on every outbound request.
   - Self-signed, expired, invalid, or hostname-mismatched certificates immediately fail closed (`AssistantUnavailable('provider_outage')`).
4. **Transport hardening**:
   - `withoutRedirecting()`: HTTP redirects (301, 302, 307, 308) are strictly refused.
   - `proxy => ''` and `CURLOPT_PROXY => ''`: Proxies are disabled.
   - Automatic retries are disabled.
   - Connect timeout: 5 seconds. Read timeout option: 5 seconds; the selected native cURL handler additionally applies a five-second low-speed timeout, with the shared total deadline authoritative.
   - Streaming response bodies are capped at 128 KiB (131,072 bytes). JSON decode depth is capped at 24.
5. **Monotonic foreground deadline**:
   - Each foreground PHP hop has an `AiProviderDeadline`; transcription and structured extraction are separate authenticated short-lived hops behind one processing experience.
   - Timeout is bounded to at most 25 seconds (from `AI_ASSISTANT_TIMEOUT_SECONDS`, default 20s), respecting PHP execution headroom (`max_execution_time - 5s`).
   - The monotonic deadline allowance is shared across primary and optional fallback attempts. Exceeding the deadline throws `AssistantUnavailable('provider_outage')`. The deadline is per hop; it does not prove a 30-second total multi-hop/browser lifecycle. Host/proxy quotas, actual latency, and 60-second upload support remain independently unverified.
6. **Realistic SSRF threat analysis**:
   - Restricting outbound traffic to curated server-owned hostnames and enforcing strict TLS peer verification narrows user-controlled fetch vulnerabilities, but does NOT guarantee that the resolved IP address is a public address.
   - Under hostile DNS conditions, DNS poisoning, rebinding, or private IP resolution, cURL could initiate a TCP handshake and send a TLS ClientHello to an internal address before the TLS certificate check fails (or if an internal endpoint presented a matching certificate).
   - Hostinger shared-hosting egress, DNS resolver latency, cURL behavior, and TLS smoke tests remain **NOT VERIFIED** until authorized operational testing occurs.
7. **Legacy gateway isolation**:
   - `GatewayTransport` and `AI_GATEWAY_PINNED_IPV4` remain unchanged, optional, and disabled by default.
   - The legacy gateway is NOT the active final operational mode and requires a separate architect and Owner review gate before any activation.
8. **Sanitized failures**:
   - HTTP status codes 408, 429, 500, 502, 503, 504 and `ConnectionException` map to `AssistantUnavailable('provider_outage')`.
   - Other unsuccessful HTTP statuses map to `AssistantUnavailable('provider_rejected')`.
   - Malformed payloads, invalid JSON, or oversized responses map to `AssistantUnavailable('provider_invalid')`.
   - Transport failures expose only sanitized reason codes, not keys, headers, provider bodies or exception text. Authenticated form/transcript content is intentionally returned only through authorized same-company/user/session workflows. Production request-body logging still needs activation review.

## Curated direct provider catalog and protocols

The provider catalog is versioned and enforced by `ProviderCapabilityRegistry` (Catalog Version `2026-10-11.1`):

| Provider | Capability | Model | Region | Base URL | Protocol | Strict schema | Thinking control | Notes |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `deepseek` | `text_structured` | `deepseek-flash` | `global` | `https://api.deepseek.com` | `chat_json` | No (`json_object`) | Disabled (`thinking: {type: 'disabled'}`) | Context cache enabled. Strict schema not supported upstream; requires full local schema validation. |
| `qwen` | `text_structured` | `qwen-plus` | `beijing` | `https://dashscope.aliyuncs.com/compatible-mode/v1` | `qwen` | No (`json_object`) | Disabled (`enable_thinking: false`) | DashScope mainland China region. JSON-object output mode. |
| `qwen` | `text_structured` | `qwen-plus` | `singapore` | `https://dashscope-intl.aliyuncs.com/compatible-mode/v1` | `qwen` | No (`json_object`) | Disabled (`enable_thinking: false`) | DashScope international region. JSON-object output mode. |
| `openai` | `text_structured` | `gpt-4o-mini` | `global` | `https://api.openai.com/v1` | `openai` | Yes (`json_schema`, `strict: true`) | Default | Structured Outputs JSON Schema. Schema adherence does not establish financial truth. |
| `groq` | `audio_transcription` | `whisper-large-v3-turbo` | `global` | `https://api.groq.com/openai/v1` | `multipart_stt` | N/A | N/A | Multipart WAV upload. 10-second minimum billing floor (`minimum_billed_audio_seconds = 10`). |
| `groq` | `audio_transcription` | `whisper-large-v3` | `global` | `https://api.groq.com/openai/v1` | `multipart_stt` | N/A | N/A | Multipart WAV upload. 10-second minimum billing floor. |
| `openai` | `audio_transcription` | `gpt-4o-mini-transcribe` | `global` | `https://api.openai.com/v1` | `openai` | N/A | N/A | Multipart WAV upload to `/audio/transcriptions`. |
| `openai` | `audio_transcription` | `whisper-1` | `global` | `https://api.openai.com/v1` | `openai` | N/A | N/A | Standard Whisper multipart STT. |
| `qwen` | `audio_transcription` | `qwen3-asr-flash` | `beijing` | `https://dashscope.aliyuncs.com/compatible-mode/v1` | `qwen` | N/A | N/A | Chat compatible endpoint `/chat/completions`. Audio sent as base64 data URI in `input_audio`. No multipart. 10 MB encoded bound. `asr_options: {enable_itn: false}`. |
| `qwen` | `audio_transcription` | `qwen3-asr-flash` | `singapore` | `https://dashscope-intl.aliyuncs.com/compatible-mode/v1` | `qwen` | N/A | N/A | Chat compatible endpoint with base64 data URI audio. |

Notes on catalog boundaries:
- Groq is registered exclusively for speech transcription (`audio_transcription`).
- DeepSeek does not support audio transcription.
- Qwen ASR models cannot be dispatched for text extraction.
- Experimental direct audio-to-structured extraction interface (`AudioToStructuredIntentProvider`) is not selectable (`audio_to_structured: false`).
- Pricing in policy configuration is dated reference data requiring human review; it is not a permanent tariff or proof of billing. Never claim current pricing.

## Deterministic complete templates (zero text calls)

The application provides a zero-provider deterministic path via `DeterministicTransactionParser`:

1. **Syntax rules**: Complete, single-turn transaction instructions matching rigid patterns bypass external text models entirely:
   - English: `sell to "<party>": <qty> "<unit>" "<product>" at <price> <currency>; ...`
   - Arabic: `بيع إلى "<party>": <qty> "<unit>" "<product>" بسعر <price> <currency>; ...`
   - Purchase English: `purchase from "<party>": ...` or `buy from "<party>": ...`
   - Purchase Arabic: `شراء من "<party>": ...`
2. **Zero text calls**: When matching, `DeterministicTransactionParser` emits a Schema v2 proposal directly. Zero tokens are dispatched to external LLMs, no network request is sent, and `cost_status` is recorded as `'no_text_call'`.
3. **Zero ledger exposure**: No customer lists, vendor directories, product catalogs, ledger balances, or accounting histories are sent to any model.
4. **Keyword guard**: Disallowed intents (`payment`, `receipt`, `expense`, `refund`, `transfer`, `دفعة`, `دفع`, `سند قبض`, `سند صرف`, `مصروف`, `تحويل`) immediately return `['version' => 2, 'intent' => 'unsupported']` without LLM invocation.

## Transaction proposal schema v2 and evidence invariants

When text inference runs via `DirectTextProvider`, output is validated by `TransactionProposalSchema`:

1. **Intent union**: Supported intents are `['sales', 'purchase', 'unsupported', 'uncertain']`.
2. **Schema version 2**:
   - Header references: `party`, `currency`, `warehouse`, `exchange_rate`, `date`, `due_date`, `notes`.
   - Purchase header: `vendor_invoice_number` (forbidden in sales proposals).
   - Lines: Array of at most 20 objects containing `product`, `unit`, `quantity`, `unit_price` (Sales only), `unit_cost` (Purchase only), `discount_type` (`null`, `'fixed'`, `'percent'`), `discount_value`, `tax`, and `lots` (Purchase only).
   - Lots: Array of at most 20 objects containing `quantity`, `lot_number`, and `expires_at`.
3. **Exact decimal strings**:
   - All economic values (`quantity`, `unit_price`, `unit_cost`, `discount_value`, `exchange_rate`, lot `quantity`) MUST be decimal strings, never JSON numbers or floats.
   - Scale: Quantities, unit amounts, and discounts allow at most six decimal places (`\d{1,10}(?:\.\d{1,6})?`). Exchange rates allow at most ten decimal places (`\d{1,10}(?:\.\d{1,10})?`).
   - Arabic and Persian digits normalize locally to standard ASCII digits. Trailing zeros are stripped via exact decimal representations (`strippedOfTrailingZeros()`).
4. **Verbatim evidence validation (`prove()`)**:
   - Every reference string, decimal value, and lot attribute MUST be accompanied by a verbatim quote from the user text in the `evidence` map.
   - Strings must match verbatim (`mb_strtolower($quote) === mb_strtolower($value)`).
   - Numbers must match exact decimal equality and adhere to word/number boundary checks in the source text (`(?<![\p{N}.\-])`).
   - Semantic aliases, inferred IDs, unstated currencies, or hallucinated lot numbers fail evidence validation (`AssistantUnavailable('evidence_invalid')`).
5. **No model-confidence authority**: The final v2 schema does not accept a confidence field or impose an 85 threshold. Extra fields fail local validation. The earlier gateway confidence cutoff is historical behavior only.

## Server-side canonical resolution and clarification

`TransactionProposalResolver` binds untrusted proposal references to canonical database entities:

1. **Tenant scoping**: Fresh company/actor authority is checked with company-first locks in a transaction; entity queries independently scope active same-company records. This is not a claim that every referenced record is locked.
2. **Authoritative resolution**:
   - `Customer` / `Vendor`: Resolved by exact match on `code`, `name_ar`, or `name_en`. Ambiguous references produce a candidate list (max 5) for human clarification.
   - After exact references, `TenantSpeechCandidates` normalizes Arabic/Persian digits, diacritics, tatweel, alef variants, taa marbuta/alef maqsura and English case/whitespace. Normalized collisions ask for a choice. Near-name matching scans at most 100 authorized active records and returns at most five candidates; even a single fuzzy candidate requires explicit choice. Larger pools refuse fuzzy resolution while exact identifiers remain available. Unoffered IDs cannot substitute for unknown references.
   - `Warehouse`: Resolved by exact match; falls back to company default warehouse when unspecified.
   - `Currency`: Standardized (`ILS`, `USD`, `JOD`). Missing currency defaults to vendor default or company base currency. Missing non-base exchange rate prompts a clarifying question.
   - `Product` & `ProductUnit`: Resolved through same-company active records. Barcodes bind their specific product and unit; carton conversions use `ProductUnit`.
   - Relative dates (`today`, `yesterday`, `اليوم`, `أمس`, `امبارح`, `مبارح`) resolve using the active company's timezone.
   - Prices: Missing prices use permission-checked canonical suggestions (`SalesProductSelection` / `PurchaseProductSelection`). Provided prices are validated against user permissions (`sales.invoice.change_price`, `sales.invoice.change_discount`).
3. **Interactive clarification**: If references are ambiguous or missing, `TransactionAssistant` records the proposal in `clarifying` state with structured candidate questions. The user selects candidates via `TransactionAssistant::clarify`.
4. **Optional original-description editing**: Seamless forms expose “Edit what I said” only on demand. Reprocessing requires explicit replacement confirmation and warns about additional approved usage. `AiTranscriptRevisions` prepares a separate protected result; it does not change current fields. A fresh browser acknowledgement checks original intent/session/revision and latest human payload before replacing the unsaved form. Clarification preserves the original form until another acknowledgement. No automatic regeneration, Save or posting occurs; ordinary follow-up patches retain their separate Undo behavior.

## Speech transcription protocol and local speech recognition

1. **Audio encoding**:
   - Format: Canonical uncompressed mono RIFF/WAVE PCM16.
   - Sample rate: 8,000 Hz to 48,000 Hz.
   - Duration / size: At most 60 seconds / 5,760,044 bytes.
   - Validation: `PcmWave::seconds()` validates the actual RIFF header, format size, channel count, sample rate, block align, and sample bytes. Claimed browser duration or MIME headers are never authority.
2. **Language handling**:
   - Locales: `ar`, `en`, `mixed`.
   - Locales `ar` and `en` pass explicit language codes (`ar` for Groq/OpenAI, `ar` in Qwen `asr_options`).
   - Locale `mixed` omits the language parameter, allowing provider models to handle mixed speech without bias.
3. **Installed true local SpeechRecognition**:
   - Frontends check `window.SpeechRecognition` or `window.webkitSpeechRecognition`.
   - Requires `'processLocally' in Speech.prototype` and `Speech.available({ langs: [locale], processLocally: true }) === 'available'`.
   - True on-device operating system speech recognition only. NO remote "local" labeling, NO downloading background models.
   - If local recognition fails or is unavailable, it NEVER silently forwards audio to cloud providers; it falls back to manual text input.
4. **Capture lifecycle**:
   - Audio is captured in-memory using an `AudioWorkletNode`.
   - Microphone stop autotransmits ONLY if intentional prior consent was given.
   - Temporary upload files are deleted immediately in the controller's `finally` block. Raw audio is never persisted in database columns, object storage, or background queues.
   - Tab navigation, form clear, or discard immediately aborts in-flight capture and invalidates pending tokens.

## Unsaved canonical forms, follow-up corrections, CAS, and Undo

1. **Truly unsaved editable forms**:
   - Livewire forms (`InvoiceForm`, `PurchaseForm`) use `AiUnsavedForm`.
   - AI proposals mount into editable form fields without creating database invoices or purchases.
   - Ephemeral proposal state is encrypted in cache (`AiFormState`, TTL 600s), keyed by form UUID and user session.
   - Discarding AI input clears proposal state while leaving all manual form inputs intact.
2. **Canonical draft save and posting**:
   - Saving requires explicit human submission:
     * Sales: Calls Phase 11 `SalesInvoiceCommands`.
     * Purchasing: Calls canonical `PurchaseDraftCommands` -> `CreatePurchaseDraftAction`.
   - Final human fields, command receipt, and encrypted AI provenance commit atomically in the draft creation transaction.
   - Financial posting strictly requires the existing authenticated Preview → Confirm → Post token workflow. No AI economic writer, posting action, or autonomous posting exists.
3. **Follow-up corrections and CAS**:
   - Company policy controls `workflow_mode`: `seamless` (auto-applies valid corrections) or `guided` (reviews before applying).
   - Follow-up is independently controlled by `follow_up_enabled`.
   - `FormPatchSchema`: Bounded operations (`set`, `remove_line`, `add_line`, max 10 operations). Single-field instructions have a zero-text deterministic parser.
   - Compare-and-Swap (CAS): Guarded by `aiFormRevision` and SHA-256 payload hash (`AiFormEditing::hash`). Any intervening manual edit increments revision and rejects stale patches with `form_changed`.
   - Undo: Restores previous payload snapshot and origin tracking. Intervening manual edits invalidate Undo.
   - Execution status: Published source checkpoint 417aef25b9d1dc58a1e8712bba7a1c7ac6148b0e prepares a protected patch, then requires fresh browser generation/payload/revision acknowledgement before a separate no-provider commit. Manual edits discard late results. This source is draft and unmerged.

## Governance, BYOK, security, and versioned consent

1. **Company policy & Owner-only controls**:
   - Managed in `AiCompanyPolicy` with revision counters and company-first locking.
   - Enabling AI (`enabled`) and enabling personal credentials (`personal_credentials_enabled`) requires Company Owner authorization (`is_owner: true` on active `CompanyUser`).
2. **Explicit delegated permissions**:
   - AI governance uses dedicated permissions:
     * `ai.settings.manage`: Manage company policy, limits, and routes.
     * `ai.credentials.manage`: Manage company BYOK credentials.
     * `ai.personal_credentials.manage`: Manage personal BYOK credentials.
   - Generic administration permissions (`roles.manage`, allAdmin) do NOT grant AI settings or key custody.
3. **Write-only BYOK credentials**:
   - Credentials are encrypted via Laravel `Crypt::encryptString` in `AiProviderConnection`.
   - Key submission and management occur exclusively via password-reauthenticated, CSRF-protected HTTPS POST requests. Stored/decrypted keys are never returned or hydrated into Livewire properties, JavaScript or HTML. Newly typed secrets exist only in protected password inputs for explicit HTTP submission; they are never persisted as Livewire state.
   - Credential validation (`validateConnection`) verifies catalog route compatibility and decryptability; it NEVER executes a billed external test call.
   - Revocation clears ciphertext (`''`), records `revoked_at`, sets status to `revoked`, and increments connection revision.
4. **Versioned consent fingerprints and capability scoping**:
   - Consent is scoped strictly to the selected primary provider/model and approved fallback route per capability (`text_structured`, `audio_transcription`), NOT a blanket grant for all visible catalog choices.
   - Speech method choices: cloud speech disclosure is included only when runtime speech is enabled and the effective company-permitted method is `cloud` or `local_if_supported`; `local_only` and `disabled` request no cloud speech consent.
   - Explicit acceptance of updated disclosure is required before provider dispatch.
   - SHA-256 fingerprint computed across: Catalog version, Company ID, Policy revision, Capability route, Connection ID & revision, Provider, Model, Region, Credential source, Privacy operator/retention/policy URL, and Fallback settings.
   - Direct-route consent is stored per-company/per-user in `AiUserPreference.consents`; its preferences hold permitted connection/method selections. The historical gateway uses `AiConsent`. Policy, model, fallback or key revision changes invalidate direct fingerprints; selection changes refresh the displayed subset and reset the checkbox without granting new consent. Dispatch and handoff recheck current consent.
   - **No report data consent**: No consent is granted or implied for report data, accounting queries, or business analytics. Reports and conversational assistant capabilities belong to future Unified P1 as a separate pull request AFTER Phase 12 source acceptance and Owner release decisions; they are NOT implemented in PR #25.
5. **Settings controls**:
   - `global_button_enabled`: When disabled, hides the global assistant launcher while preserving contextual form launchers.
   - `allowed_intents`: Strict allowlist (Sales, Purchase, both, or an explicit empty list that denies all AI intents). Prevents unsupported flows (expenses, receipts, vendor payments) from being routed to purchasing or sales.

## Budgeting, worst-case reservations, and outage-only fallback

1. **Integer USD micro-units**:
   - Budgets and costs are tracked as integer USD micro-units (1 USD = 1,000,000 micros).
   - Ceilings enforced per-request, daily company/user, and monthly company/user.
   - Deterministic request counts (`daily_company_requests`, `daily_user_requests`).
2. **Combined worst-case reservation**:
   - When a primary route and an optional named fallback route are configured, the full estimated cost of BOTH routes is reserved upfront before dispatch.
   - If an operation fails, times out, or produces unknown usage, the reserved budget is RETAINED, never refunded.
   - Estimated, reserved, and reported costs are strictly distinct (`cost_status: 'no_text_call'` or `'unknown'`). No invented prices or billing proofs.
3. **Outage-only named fallback**:
   - At most ONE named fallback connection may be configured per capability (`text_connection`, `speech_connection`).
   - Fallback is triggered ONLY for operational outages (HTTP 408, 429, 500, 502, 503, 504 or connection failure).
   - Fallback is NEVER triggered for invalid provider output, schema rejection, bad JSON, or semantic ambiguity.
   - Fallback route MUST share the same credential source (`company_byok` or `personal_byok`) and company ownership as the primary route.

## Retained superseded gateway specification

For historical audit, the version 1 single-gateway contract is retained as a superseded legacy reference:

1. **Endpoint**: POST `base/v1/intent` (JSON, Bearer key). POST `base/v1/transcription` (multipart).
2. **Boundaries**: Schema v1 was sales-only (`draft_sales_invoice`). Universal audio transcription was not adapted.
3. **Status**: Superseded by multi-provider direct transport. The legacy services remain for historical compatibility tests. The final orchestrator requires `direct`; setting `gateway` cannot dispatch it. No compatible external gateway is verified.

## Required independent operational proof

Before any production enablement of AI features, the following independent operational proofs must be completed:

1. **Hostinger DNS and egress verification**: Verification of DNS resolution, egress connectivity, and TLS handshake latency to curated provider hostnames from Hostinger shared hosting; smoke tests remain NOT VERIFIED until authorized.
2. **Vendor commercial contracts**: Selection of upstream vendors (DeepSeek, Alibaba Cloud, Groq, OpenAI), execution of data processing agreements, review of data retention policies, and verification of upstream hard billing caps.
3. **Live ASR and acoustic validation**: Physical device microphone testing with Arabic (Levantine, Palestinian), English, and mixed-language audio in realistic acoustic environments; live ASR paid calls and physical phone tests remain NOT VERIFIED.
4. **Architect milestone acceptance**: Formal independent architect sign-off on Phase 12 milestone completion (P12-M6) and PR #25.
5. **Open Phase 10 operational blockers**: Deferred Phase 10 independent off-host backup, isolated recovery/key custody, and real password-reset mail proofs remain open.
