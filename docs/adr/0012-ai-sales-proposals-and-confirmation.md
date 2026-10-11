# ADR 0012 — AI Sales and Purchase proposals through canonical commands

Status: proposed expanded for independent review, not accepted. Date: 2026-10-11.

Authority metadata: Governed by protected local specification `ACCOUNTING_PHASE12_FINAL_SPEC_ARCHITECT_REVIEWED_2026-10-11.md`, SHA-256 `B080FC50222DC3F8E0AE6E8A3701BD4151E0CC020AD9FEEF14DE205521A5FB3F`, as amended by the Owner-authorized P0 clauses of protected `SMALL_TRADER_UNIFIED_AI_ASSISTANT_DESIGN_HANDOFF_2026-10-11.md`, version 1.0 dated 2026-10-11, SHA-256 `DB6D598B24E36205D6118A94C4488CF8DCC9ED3969FFC2585380A014024EB115` (both remain local, private, and unpublished; see [P0 DNS and consent amendment](../PHASE_12_P0_DNS_CONSENT_AMENDMENT.md)).

## Context and authority

[Approved future roadmap, §9.4](../ACCOUNTING_PHASE_9_FULL_ENGINEERING_ROADMAP_AND_FUTURE_AI_MOBILE_V1.md), `docs/ENGINEERING_BLUEPRINT_v1.0.md`, and [ADR 0011](0011-versioned-command-api-foundation.md) control this implementation. Owner authorization permits source development and isolated QA; provider activation and Phase 12 release remain separate decisions. `AccountingPostingService` and canonical inventory services retain exclusive economic authority.

Initial Phase 12 design specified a vendor-neutral single-gateway client for Sales invoice drafting. The governing Owner final specification expanded the architecture into a multi-provider direct transport and unified transaction proposal system for both Sales and Purchasing. The historical initial gateway design is superseded; it is retained in the codebase solely as a legacy compatibility option and does not provide direct provider coverage.

## Decision

### 1. Multi-provider direct architecture and curated registry

Introduce `ProviderCapabilityRegistry` (Catalog Version `2026-10-11.1`) managing curated text and speech provider registrations:
- **Direct text extraction**: DeepSeek (`deepseek-flash`, global, protocol `chat_json`, thinking disabled), Qwen (`qwen-plus`, Beijing/Singapore, protocol `qwen`, thinking disabled), and OpenAI (`gpt-4o-mini`, global, protocol `openai`, strict JSON schema).
- **Direct speech transcription**: Groq (`whisper-large-v3-turbo`, `whisper-large-v3`, global, protocol `multipart_stt`, 10s billing floor), OpenAI (`gpt-4o-mini-transcribe`, `whisper-1`, global, protocol `openai`), and Qwen (`qwen3-asr-flash`, Beijing/Singapore, protocol `qwen` with base64 data URI in `input_audio`).
- **Direct transport normal-DNS and hardening**: `DirectProviderTransport` executes outbound HTTPS requests targeting curated hostnames registered in `ProviderCapabilityRegistry` (`api.deepseek.com`, `dashscope.aliyuncs.com`, `dashscope-intl.aliyuncs.com`, `api.openai.com`, `api.groq.com`) with server-owned paths (`/chat/completions`, `/audio/transcriptions`). Strictly no arbitrary URLs, IPs, ports, schemes, redirects (`withoutRedirecting()`), or proxies (`proxy => ''`, `CURLOPT_PROXY => ''`). Outbound DNS uses native cURL / Guzzle DNS resolution via the cURL-backed handler (`CurlHandler`); manual destination IPv4 pinning and `CURLOPT_RESOLVE` are removed per Owner P0 authorization ([P0 DNS and consent amendment](../PHASE_12_P0_DNS_CONSENT_AMENDMENT.md)). TLS peer and hostname verification remain strictly enabled (`verify => true`). Connect timeout is 5 seconds; native cURL uses a five-second low-speed safeguard and the total deadline (the read_timeout option is not a cURL read guarantee) each; shared monotonic deadline is <= 25 seconds (`AiProviderDeadline`); streamed responses are capped at 128 KiB (131,072 bytes); automatic retries are disabled.
- **Realistic SSRF threat modeling**: Curated hostnames and strict TLS certificate verification narrow user-controlled fetch vectors, but do NOT guarantee that the resolved IP address is a public address. Hostile DNS poisoning, rebinding, or private IP resolution could initiate a connection attempt to internal infrastructure before certificate validation fails. Hostinger shared-hosting egress, DNS resolver latency, cURL behavior, and TLS smoke tests remain NOT VERIFIED until authorized. No custom runtime resolver or manual IP refresh.
- **Legacy gateway**: `GatewayTransport` and `AI_GATEWAY_PINNED_IPV4` remain unchanged, optional, disabled by default, and are NOT the active operational mode; requires separate architect and Owner review gates.
- **Historical pinning note**: Earlier checkpoint test statements testing static destination pinning remain authentic historical evidence, now superseded in active scope by the P0 amendment.

### 2. Dual intent scope: Sales and Purchasing

The transaction assistant operates across both Sales and Purchasing workflows:
- **Intents**: Handled via Schema v2 intent union (`sales`, `purchase`, `unsupported`, `uncertain`).
- **Entry points**: Global assistant launcher and contextual form launchers supporting Arabic-first, English, and mixed-language input.
- **Canonical command reuse**:
  * Sales: Reuses Phase 11 `SalesInvoiceCommands`.
  * Purchasing: Reuses canonical `CreatePurchaseDraftAction` via `PurchaseDraftCommands`.
- **Zero economic side effects**: The AI layer does NOT write financial history, post transactions, or invoke autonomous tool calls.

### 3. Deterministic complete templates (zero text calls)

Single-turn instructions matching narrow, complete patterns (`DeterministicTransactionParser`) bypass external LLMs entirely:
- Emits Schema v2 proposals directly with zero text-provider calls (`cost_status: 'no_text_call'`).
- Prevents uploading customer directories, vendor lists, catalogs, or financial ledgers to external providers.
- Intercepts disallowed business operations (`payment`, `receipt`, `expense`, `refund`, `transfer`, `دفعة`, `دفع`, `سند قبض`, `سند صرف`, `مصروف`, `تحويل`) and classifies them as `unsupported`.

### 4. Untrusted model output and server-side canonical resolution

- **Schema v2 invariants**: Model outputs are untrusted strings, exact decimal strings (quantities/prices max 6 decimals, exchange rates max 10 decimals, Arabic/Persian digits normalized), and verbatim evidence quotes (`TransactionProposalSchema`). The final schema does not accept model confidence; the earlier gateway confidence cutoff is superseded.
- **Authoritative resolution**: `TransactionProposalResolver` checks fresh authority with company-first locks and scopes each entity query to active company-owned records. References to `Customer`, `Vendor`, `Warehouse`, `Product`, `ProductUnit`, `TaxRate`, and `Currency` are matched against active same-company records.
- **No guessing**: The system never hallucinates or guesses entity IDs, exchange rates, lot numbers, or vendor invoice numbers. Ambiguities generate structured candidate questions for user clarification (`TransactionAssistant::clarify`).
- **Canonical pricing**: Missing prices use permission-checked canonical suggestions from `SalesProductSelection` / `PurchaseProductSelection`.

### 5. Truly unsaved canonical forms, CAS corrections, and Undo

- **Unsaved form mounting**: Proposals populate Livewire components (`InvoiceForm`, `PurchaseForm`) via `AiUnsavedForm` without creating database records.
- **Explicit human Save**: Saving requires user action. Financial posting strictly requires the authenticated Preview → Confirm → Post token workflow.
- **Current-form corrections**: Bounded patches (`FormPatchSchema`, `AiFormCorrections`) use compare-and-swap (CAS) guarded by revision numbers and payload SHA-256 hashes (`AiFormEditing::hash`). Intervening manual edits reject stale patches.
- **Undo**: Reverts to the previous payload snapshot, origin metadata, and unresolved fields. Intervening edits invalidate Undo.
- **Implementation status**: Published draft checkpoint 417aef25b9d1dc58a1e8712bba7a1c7ac6148b0e separates inference preparation from current-browser acknowledgement and a no-provider local commit. It is not merged or architecturally accepted.

### 6. Foreground speech capture and true local recognition

- **Audio validation**: `PcmWave` validates uncompressed mono RIFF/WAVE PCM16 (8–48 kHz, max 60s / 5,760,044 bytes).
- **Installed true local speech recognition**: Uses browser `SpeechRecognition` only when `'processLocally' in Speech.prototype` and `Speech.available({ langs: [locale], processLocally: true }) === 'available'`. Never disguises remote cloud recognition as "local"; never downloads background models. If local recognition fails, it falls back to manual text input.
- **Capture boundaries**: Microphone stop autotransmits only with prior consent. Audio uploads are processed in foreground memory and unlinked immediately in the controller `finally` block. Raw audio is not retained in application storage or background queues; the restricted PHP upload temporary file exists during foreground processing and is removed on exit.

### 7. Governance, BYOK, security, and versioned consent

- **Owner-only activation**: Only active `CompanyUser` with `is_owner: true` can enable AI (`enabled`) or personal BYOK credentials (`personal_credentials_enabled`).
- **Delegated permissions**: Explicit capabilities `ai.settings.manage`, `ai.credentials.manage`, and `ai.personal_credentials.manage` govern AI operations, distinct from generic admin roles (`roles.manage`, allAdmin).
- **Write-only BYOK credentials**: Secrets are encrypted via Laravel `Crypt::encryptString` in `AiProviderConnection`. Submission requires fresh password re-authentication (`Hash::check`) over HTTPS + CSRF. Validation never performs billed test calls. Revocation clears ciphertext, records timestamps, and increments revisions.
- **Versioned per-capability consent**: Consent is scoped strictly to the selected primary provider/model and approved fallback route per capability (`text_structured`, `audio_transcription`), NOT all visible catalog choices. Speech method choices: cloud speech disclosure is included only when runtime speech is enabled and the effective company-permitted method is `cloud` or `local_if_supported`; `local_only` and `disabled` request no cloud speech consent. Explicit acceptance of updated disclosure is required before dispatch. SHA-256 fingerprint binds catalog version, company, policy revision, capability route, connection ID/revision, provider, model, region, source, privacy terms, and fallback settings. Policy or connection revisions invalidate fingerprints; route selection changes refresh disclosure and reset the checkbox without consenting new routes. No report data consent is implicitly approved; reporting and conversational assistant capabilities belong to future Unified P1 as a separate pull request AFTER Phase 12 source acceptance and Owner release decisions; they are NOT implemented in PR #25.
- **Settings controls**: `global_button_enabled` toggle hides the global button while retaining contextual launchers. `allowed_intents` allowlist restricts workflows.

## Concurrency, identity, and budget

- **Company-first database locks**: Requests are serialized behind company row locks. Identical request UUID, purpose, and input HMAC converge to a single provider request; conflicting inputs fail.
- **Budget reservations**: Integer USD micro-unit reservations enforce per-request, daily, and monthly company and user ceilings.
- **Combined worst-case reservation**: Primary and optional named fallback costs are summed and reserved upfront before dispatch.
- **Retained reservations**: Failed, timed-out, or unknown provider outcomes retain their reservations without retroactive refunds. Estimated, reserved, and reported costs remain strictly distinct (`cost_status: 'no_text_call'` or `'unknown'`).
- **Deterministic request limits**: `daily_company_requests` and `daily_user_requests` enforce deterministic volume limits.
- **Outage-only fallback**: Exactly one named fallback route may be configured, triggered strictly on provider outages (HTTP 408, 429, 500, 502, 503, 504 or connection failure). Never triggered for invalid output, refusal, or ambiguity. Must share the same credential source and tenant ownership.

## Privacy, retention, and audit

- **Ephemeral proposal caching**: Minimized encrypted proposal data is cached in `AiFormState` for 600 seconds, keyed by session and form UUID.
- **Atomic audit trail**: On draft creation, the encrypted canonical command approved by the human, receipt identity, actor/company, and resulting invoice/purchase ID commit atomically in the same database transaction as the canonical draft.
- **Retention pruning**: The repository hourly maintenance schedule (`AiRetention::prune`; production activation unperformed) prunes expired proposals and clears old approved commands according to company-configured retention days (1–365 days, default 30), retaining content-free usage and provenance records.

## Consequences, limits, and operational prerequisites

- **Fail-closed defaults**: System defaults are disabled (`AI_ASSISTANT_PROVIDER=none`, `AI_ASSISTANT_ENABLED=false`, `AI_PROVIDER_APPROVED=false`, `AI_SPEECH_ENABLED=false`, cost ceilings zero). `COMMAND_API_ENABLED=false` stays false in production.
- **Shared hosting compatibility**: No Redis, permanent Node.js server, WebSockets, Docker, or persistent daemons required.
- **Schema preservation**: Additive `assistant_requests` migration preserves all financial tables and accounting data.
- **Out of scope**: Platform billing, credits and platform admin UI are outside Phase 12. Physical phone testing and real provider activation remain separately authorized activation gates. Account operators obtain no implicit tenant rights.
- **Operational verification pending**: Real provider models, physical phone microphones, dialect accuracy, live ASR paid calls, and Hostinger hosting resource quotas remain NOT VERIFIED. Source candidate is P12-M6 with P0 normal-DNS and P0b consent amendment, draft PR #25; exact HEAD acceptance still pending. Deferred Phase 10 independent off-host backup, isolated recovery/key custody, and real password-reset mail proofs remain open.
