# Accounting Owner feature-testing guide — 2026-10-12

Target: [live Accounting](https://account.palsync.net), deployed `d9d9ede3e42926f9b51084a3ed0ba224f0f3fa66`, accepted PR #28 source `15032eadcab49f66e14a97ce87da3205da7ea308`. See the [release evidence and limitations](UNIFIED_V1_PRODUCTION_RELEASE_2026-10-12.md). This is a testing checklist, not a claim that every feature or real provider has passed live UAT.

The platform's implemented assistant, report questions, speech, direct BYOK dispatch and authenticated API switches are enabled. Company policy and external provider credentials are yours to configure. No credentials, consent, provider pricing or business transactions were invented by the release operator.

## Start here

1. Sign in afresh. The API requires a real recent login, not an old/remembered session. Select the intended company and confirm its currency and language.
2. Open [Settings → AI](https://account.palsync.net/settings/ai). Configure your company policy and supported provider connections. Adding/replacing a company or personal BYOK key requires password confirmation. The local connection validation checks configuration; it does not prove real provider delivery.
3. Choose allowed connections and explicit primary text/speech routes. Optional fallback must be approved and use the same company/personal credential source as its primary. Personal BYOK is an independent Owner-controlled choice.
4. Fill truthful privacy operator, retention description and HTTPS policy URL. Set dated model pricing with its HTTPS source, checked date and the applicable text/audio rates; do not guess rates or set falsely free pricing.
5. Fill every required company/user request and budget limit. Platform test caps are USD 0.01/request, USD 1/company and user daily, USD 5/company and user monthly. Enter USD amounts in Settings, not micro-unit integers. Company settings may choose lower values; a provider estimate above the effective cap will be refused. Platform caps are operational configuration, not adjustable through the company form.
6. Enable company AI, the global button, Sales/Purchase intents, report questions and the supported report allowlist you want to test. Choose guided/seamless and your speech method; enable follow-up questions if desired. Save the policy. Company opt-in does not grant staff new report or transaction permissions.
7. In the assistant, review the selected provider/model/region and explicitly consent before provider calls. Transaction and report-planning consent are separate. Policy/key/fallback changes can require renewed consent. You decide whether and when to make real, potentially billed calls.

Policy creation requires privacy details and positive limits even if your immediate first exercise is a deterministic report. Until company setup is complete, an absent launcher or setup refusal is expected; the platform switches themselves are enabled.

## Manual application checks — no AI calls needed

Use existing authorized records for read-only checks. For write/post/void/return exercises, choose a deliberate test company/environment and record what you change; posted history is intentionally immutable and should not be treated as disposable UI data. The release operator did not create live fixtures.

| Area | What to try and compare |
|---|---|
| Company and access | Selected company, roles, active membership, permitted versus restricted pages; switching companies must not reveal the first company's records |
| Dashboard | Values, periods, currency labels and links to canonical reports |
| Customers and Vendors | Master data, opening balances, statements, historical identity and currency presentation |
| Products and Units | Piece/carton conversions, editable suggested prices, barcodes and supported product identity |
| Sales | Quotation, unsaved invoice form, preview, deliberate draft/post, receipt, return and existing sharing lifecycle |
| Purchasing | Unsaved purchase form, preview, deliberate draft/post, Vendor Payment, Purchase Return, permission-controlled costs |
| Inventory | Warehouses, stock movements, lots/expiry/FEFO, valuation and supported adjustment/transfer workflows |
| Money | Cash/Bank balances, accounts, receipts/payments/transfers and checks where configured |
| Expenses and Payroll | Categories, expense forms, employee/payroll and landed-cost workflows as configured |
| Reports | Period/date/currency filters, source drilldowns, exact totals, CSV and permission redaction |
| Settings | Company/document/numbering, Print/PDF, Sharing/Links, taxes, purchasing, AI policy/credentials/usage |
| Authentication | Login/logout, device sessions, 2FA and recovery pages; actual email delivery remains an unproven operational gate |

Repeat representative list/detail/form flows in Arabic RTL and English LTR at 390, 768 and 1440 px. Check keyboard focus, readable numbers, validation, loading/error/empty/restricted states, overflowing tables and dialog boundaries.

Check AR/EN print/PDF for all nine outputs: Quotation, Sales Invoice, Sales Return, Customer Receipt, Customer Statement, Purchase, Purchase Return, Vendor Payment and Vendor Statement. Compare statement opening/activity/closing balances, dates and per-currency totals with the canonical sources. Public financial links require their existing issue/password/expiry/revocation controls. A default Viewer must not receive protected Vendor Payment/Statement document bytes.

For actual labels/scanning, cover Code 128, EAN-13, EAN-8, UPC-A and UPC-E and confirm the decoded product/unit and piece/carton identity. This guide does not claim physical scanning or every PDF was newly tested during deployment.

## Assistant report checks

After company setup and report permissions, supported deterministic typed questions run locally without a provider request. The seven curated sources are Sales summary, Customer balances, Purchasing summary, Vendor balances, Low stock, Expiry and Money balances. Totals/currencies/periods and source links must match the corresponding canonical report. V1 may show a bounded summary rather than every source row.

| Input | Expected behavior |
|---|---|
| `Sales summary this month` / `ملخص مبيعات هذا الشهر` | Supported historical Sales summary with the explicit month |
| `Sales summary` | Ask for a supported period rather than guessing |
| `Customer balances` / `أرصدة العملاء` | Current balances; preserve currency truth |
| `Customer balances this month` | Refuse the unsupported historical qualifier on a current-balance source |
| `Low stock in USD` | Refuse a currency qualifier unsupported by that report |
| `sales for Ahmed this month` / `مبيعات أحمد هذا الشهر` | Refuse unsupported named-party filtering; never return an unqualified company-wide answer |
| Vendor/product/location restrictions, arbitrary dates or unsupported currencies | Refusal or clarification; never silently discard the essential qualifier |
| Ambiguous/unrecognized transaction versus report instruction | Explicit operation choice when needed; no silent provider routing or financial execution |

Follow-up questions are bounded by supported periods/currencies and current company/actor/session/policy context. Unauthorized report access must remain refused independently of AI settings. Optional provider planning sends the user's question and bounded schema; it does not send stored report rows/totals. Your typed text itself is data sent to a provider if you consent to provider planning.

## Provider-dependent Sales/Purchase and voice checks

These require your configured keys, actual dated pricing/privacy terms and consent; they may incur charges. They are not free deterministic tests.

- Ask for an Arabic/English Sales or Purchase document using your intended party, products, quantities, piece/carton units, currency and prices. Missing or ambiguous entities should prompt correction/selection rather than guessed posting.
- Inspect the resulting editable form and financial/inventory preview. AI handoff alone must remain truly unsaved: it must not post an invoice, purchase, ledger or stock movement. Edit the fields normally; saving/posting is a separate explicit manual action.
- Try guided and seamless modes. Record a short Arabic/English/mixed-language phrase with the contextual microphone after granting the browser permission yourself. Review transcription and operation choice. Cloud transcription is provider-dependent; local browser speech support is device-dependent.
- Cancel/discard a proposal and confirm manual workflow remains usable. Switch company or invalidate policy/connection/session and confirm an old proposal cannot be applied to another context.
- Inspect Usage: estimated, reserved, provider-reported and unknown costs must be distinguished. Missing real billing evidence must not be described as verified free usage. A budget refusal is an enforced limit, not proof of a provider outage.
- Do not manufacture provider outages or repeatedly dispatch paid requests just to exercise fallback. Local fake-HTTP tests cover those negatives; live fallback/voice/cost evidence remains a separate Owner-controlled exercise.

## Enabled command API

The shipped API uses the same fresh database-backed login session and CSRF protection. It has no API-key/Bearer-token issuance UI. Do not paste your AI provider key into API requests.

From a freshly logged-in browser, this read-only same-origin request checks the session endpoint without posting anything:

```javascript
fetch('/api/v1/session', {
  credentials: 'same-origin',
  headers: { Accept: 'application/json' }
}).then(async response => ({ status: response.status, body: await response.json() }));
```

Keep the returned account/session details private. Expected authenticated status is 200; anonymous/expired/remember-only access must be 401. Navigating directly with an HTML-only Accept header may return 406; API clients should request JSON. Company lookup URLs live under `/api/v1/companies/{companyId}/lookups/` and require the selected same-company context and current permissions. Mutating Sales commands require JSON, CSRF, economic idempotency and explicit Preview/Confirm/Execute; test those against owned isolated data rather than casually creating live history.

## Reporting feedback

Send the screen/feature, Arabic or English, expected versus actual result, approximate time and a screenshot with private details removed. For AI, include the provider/model/region and sanitized error name, not keys, passwords, reset links, session cookies, SQL or private financial exports. Do not put a credential in a screenshot or issue.

AGY supplied a read-only draft guide, independently corrected by Codex for actual flag names, permissions, provider-dependent handoff, pricing fields, consent/data boundaries and the current authorized deployment. Worker narrative was not accepted as production evidence.
