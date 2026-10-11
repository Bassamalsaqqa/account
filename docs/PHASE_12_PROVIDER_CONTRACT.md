# Phase 12 provider contract — version 1

Status: implemented optional gateway client; compatible upstream service NOT VERIFIED. No vendor selected, billed requests sent, credentials retrieved, or production features enabled. [ADR 0012](adr/0012-ai-sales-proposals-and-confirmation.md) and [source acceptance](PHASE_12_SOURCE_ACCEPTANCE.md) describe the boundaries.

## Activation gates

Owner approval must identify provider/gateway operator, costs, privacy/data location, raw-content retention, recipient data and endpoint. Independent architect acceptance must approve this client plus upstream routing, authentication, request ceilings, response schema and rollout. A compatible endpoint is required; this repository does not implement or deploy a gateway server. Do not enable flags solely because a fake-provider test passes. Manual Sales works without a gateway or AI account.

Server configuration requires AI_ASSISTANT_ENABLED=true, AI_PROVIDER_APPROVED=true and AI_ASSISTANT_PROVIDER=gateway together. Speech also requires AI_SPEECH_ENABLED=true. Positive approved per-request/daily/monthly company/user cost ceilings are mandatory. Default flags are false/none and every cost ceiling is zero. AI_DISABLED_COMPANY_IDS provides a configuration-controlled tenant disable list. Global disable prevents request reservations and provider traffic; existing applied manual fields remain usable.

AI_GATEWAY_URL is an HTTPS base path, paired with its exact AI_GATEWAY_APPROVED_HOST and reviewed globally routable AI_GATEWAY_PINNED_IPV4. No userinfo/query/fragment, alternate port, private/link-local/reserved destination, browser endpoint override, redirect, proxy or automatic retry is allowed. TLS verification remains enabled; cURL pins the reviewed IPv4 while verifying the hostname. Infrastructure IP rotation requires a reviewed configuration update. AI_GATEWAY_KEY stays in protected server configuration and is never returned to browsers or shared evidence.

## Intent request

POST base/v1/intent, JSON, bearer authorization. Fields: version=1, intent=draft_sales_invoice, text (at most 4,000 characters), locale (ar/en), limits. Only user-entered content is sent; private customer/product lookup happens locally. No whole ledger, tenant ID, actor identity, stock records, cost history or permissions are uploaded.

limits: max_cost_micros, max_input_tokens=6000, max_output_tokens=4000, max_audio_seconds=60. The gateway must enforce these ceilings BEFORE a billable request, reject oversized input, configure bounded completion and never invoke financial tools. Returned usage is not proof of billing. Application caps reserve the full ceiling before dispatch and retain it after failures; they cannot retroactively prevent charges from a noncompliant upstream service. Verify gateway billing/caps independently before activation.

The success object contains exactly proposal, confidence (integer 0–100) and usage. Confidence below 85 is refused; confidence is a provider signal, not financial truth. proposal version=1 and intent=draft_sales_invoice; optional reference fields customer, warehouse, currency, exchange_rate, issue_date, due_date; lines is a list of at most 20 objects containing only product, unit, quantity, unit_price, discount_type, discount_value, tax. Unknown fields, IDs, tools, routes, SQL and model totals are refused. Missing references/quantities yield local clarification. Economic values are decimal STRINGS, never JSON numbers/floats/exponents. Quantity/price/discount allow at most six decimal places; FX at most ten. Arabic/Persian digits normalize locally. discount_type is null/fixed/percent. References are at most 255 characters without control characters. Missing prices use permission-checked canonical selling-price suggestions; missing non-base FX requires clarification. Exact same-company authoritative matching replaces fuzzy guessing.

Example proposal, using synthetic names only:

~~~json
{"version":1,"intent":"draft_sales_invoice","customer":"Ahmed","currency":"ILS","lines":[{"product":"SOAP-3L","unit":"carton","quantity":"5","unit_price":"80"}]}
~~~

The gateway must extract evidence from the description rather than invent values. Ambiguous transcription/proposals must signal uncertainty or null references. Arabic/Levantine, English and mixed-language evaluation is pending a real approved provider.

## Transcription request

POST base/v1/transcription, multipart: version=1, locale=ar/en, limits encoded as one JSON-string field, audio with filename recording.wav and audio/wav MIME. Only canonical RIFF/WAVE mono uncompressed PCM16, sample rate 8–48 kHz, at most 60 seconds / 5,760,044 bytes is accepted. Actual header/data/sample count is checked before provider dispatch; claimed duration/MIME alone is not authority.

Response contains exactly text, confidence, usage. Text is bounded and returned only to the authenticated caller as editable input. Transcription is never an authenticated command or posting confirmation. Used transcription request keys cannot resend audio automatically. Retry is explicit and consumes another conservative budget reservation.

## Common response and failure handling

usage contains exactly cost_micros, input_units, output_units, all nonnegative integers bounded at 1,000,000,000; cost must not exceed the reservation. Intent units correspond to tokens; transcription input units must be actual audio seconds and output units transcript tokens. Cost currency is USD micro-units (1 USD = 1,000,000), for operational budget display only; accounting currency calculations use canonical exact-decimal services.

Response bodies are capped at 128 KiB, JSON nesting at 16. HTTP/request timeout is at most 30 seconds (default 20), connect/read timeout five seconds. Redirects, oversized/malformed/non-success responses and provider failures return sanitized manual-fallback messages. No provider key, URL, headers, body, transcript or reset token is logged by these adapters. Hosting/proxy error logging must separately be reviewed for request-body capture before activation.

## Required independent operational proof

Verify the actual gateway/provider contract, TLS/pin rotation procedure, upstream no-tool policy, language/ASR quality, real cost ceilings, invoice/billing equivalence, deletion/retention, consent copy, latency, phone permission paths and safe shutdown. Keep all features disabled until those gates and architect acceptance pass. These are future approved provider tests, not completed synthetic evidence.
