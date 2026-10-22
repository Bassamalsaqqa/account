# AI Native Release and Final Provider Smoke Record — 2026-10-12

## Release identities and authority

- **Previous live:** `455c1e368aa1b5da0d5a61913be3c5af079cf0ef`.
- **Accepted PR #31 head:** `22e6330515da6da4f107fe4deafb229b5288ff1e` ([PR #31](https://github.com/Bassamalsaqqa/account/pull/31); normal history merge, no accepted source edits).
- **Deployed main:** `b0a4b15354aad76b2b81eda2a01a935c05dd6de6`.
- **Runtime changes:** Single raw proxy option (`CURLOPT_PROXY`) removed in `DirectProviderTransport`. Zero migrations, dependencies, or compiled assets.
- **Evidence & execution boundary:** All production operations, checkpoints, and UI interactions were executed by Codex and the Owner. AGY authoring this factual record owns documentation only without runtime, database, SSH, provider, or credential access.

## Outcome summary

| Check / Dimension | Outcome | Operational evidence |
| --- | --- | --- |
| **Release source & deployment** | PASS | Deployed main tree matches accepted PR #31 head tree; Hostinger maintenance drain, lock, encrypted SQL/state checkpoints, rollback bundle to `455c1e`, caches rebuilt. |
| **Database & nonvolatile tables** | PASS | 89 tables, 67 applied migrations, 0 pending; 86 nonvolatile tables fingerprints identical pre-fixture; volatile caches/sessions excluded. |
| **Web routing & Livewire** | PASS | 26 authenticated page GETs (AR/EN) + API session 200 + guest 401 = 28 checks; 0 fresh errors; Livewire aliases pass. |
| **Live books test fixtures** | PASS (retained) | Fictional customer `AIQA20261012-C`, vendor `AIQA20261012-V`, stock product `AIQA20261012-P` created in Tariq Ziad live books per Owner authorization. |
| **Baseline purchase posting** | PASS (retained) | Owner posted `PUR-2026-0001` (2 ILS, +2.000000 qty stock movement to 1301 Dr / 2101 Cr); 6/6 reconciliations healthy at 13:39:56 UTC; history immutable. |
| **Live DeepSeek dispatch (req 2)** | FAIL | Request at 13:36:55 UTC failed fail-closed (`provider_rejected`); curated transport refused non-success HTTP; exact HTTP status/error not retained. |
| **JSON proposal & form handoff** | NOT VERIFIED | No JSON proposal parsed; unsaved Sales form handoff aborted; manual Sales posting not run; QuickConnect UX not begun. |
| **Token reservations & billing** | FAIL-CLOSED | Attempt 2 reserved 6,600 micros (total 2 attempts = 13,200 micros / $0.013200 reserved); units/cost null; billed cost not verified; no retries/reset. |
| **Business data preservation** | PASS | 24 business/financial tables identical before/after AI call; 0 AI invoices/journals/stock/payments; 0 sales invoices; 0 payments. |

## Operational deployment and infrastructure baseline

- **Deployment safeguards:** Executed under maintenance guard (HTTP 503), drain, and deployment lock on private Hostinger app. Git rollback bundle created targeting prior `455c1e368aa1b5da0d5a61913be3c5af079cf0ef`. Streamed encrypted SQL checkpoints and separate restricted on-host key escrow verified (on-host checkpoint is not DR proof; P10 off-host restore and mail proof remain deferred).
- **Integrity & caches:** Autoload, package, config, route, view, and event caches rebuilt. Public asset and `.env` fingerprints unchanged; application user identities, permissions, document sequences, and business files preserved. No CI or full test suite run.

## Governance, credentials, and live fixtures

- **Policy & budget preflight:** Policy rev3 active with one company DeepSeek BYOK connection (`deepseek-flash`, global, text_structured) and valid consent. Speech, fallback, and personal BYOK disabled. Limits set to $0.01/request, $0.02 daily/monthly; max 2 requests. Prior failed attempt had reserved 6,600 micros (actual cost unknown), leaving 1 attempt and 13,400 micros available.
- **Credential override:** Credential metadata remained rev1 (single configuration event; replacement not verified). The Owner explicitly overrode the key rotation prerequisite and instructed using the existing saved credential for this bounded smoke test. The operator never retrieved a key from chat/logs, inspected its plaintext, or exported/copied it into tools or evidence. The canonical application decrypted the stored credential only for its authorized provider dispatch; no security guard was weakened.
- **Owner-authorized live book fixtures:** Owner explicitly authorized live-books testing in Tariq Ziad books acknowledging immutable test history. Fictional master data created via UI: customer `AIQA20261012-C`, vendor `AIQA20261012-V`, stock-tracked product `AIQA20261012-P` (non-expiry, piece unit, sale price 2 ILS, cost 1 ILS). Real records were not reused.
- **Purchase baseline posting:** Saved purchase `AIQA20261012-PURCHASE-TEST` (2 pcs @ 1 ILS, FX 1, 0 tax/discount). Automatic review rejected automated Codex posting click; Owner performed handoff click. Verified `PUR-2026-0001` posted 2.000000 ILS with stock movement (+2.000000 qty/value, unit cost 1) debiting Inventory (1301) and crediting AP (2101). Zero payment. All 6 financial reconciliations passed with zero violations. Test records are permanently retained in live history; they are not removed, reversed, or hidden.

## Final assistant request and provider refusal

At 2026-10-12 13:36:55 UTC, a final non-confidential Arabic sales instruction using fictional labels was submitted via canonical app entry (requesting 1 piece @ 2 ILS for customer `AIQA20261012-C`, product `AIQA20261012-P`). Persisted audit record in `assistant_requests`:
- **Request sequence:** Request #2 (exhausts company/user allocation of 2).
- **Routing & model:** Purpose `intent`, provider `deepseek`, model `deepseek-flash`, region `global`.
- **Execution status:** `failed`, failure code `provider_rejected`, attempt count 1.
- **Units & costs:** Input/output units `null`; estimated/reserved 6,600 micros; reported cost `null`; cost status `unknown`.
- **Transport behavior:** Curated transport received a non-success HTTP response from DeepSeek and refused processing. No JSON proposal was extracted, and no unsaved sales form handoff occurred.
- **Sanitized error boundary:** Exact HTTP status code and raw provider error body were not retained by operator evidence. No specific cause such as request format, credential rejection or insufficient balance is asserted. The accepted transport maps HTTP 429/500/503 to `provider_outage`; this request was `provider_rejected`, so no rate-limit incident is claimed. This failure does not prove authenticated DeepSeek connectivity or account validity. See public [DeepSeek Error Codes](https://api-docs.deepseek.com/quick_start/error_codes/).
- **Ledger preservation:** 24 inspected financial tables matched identical row fingerprints before and after the AI request against the post-purchase baseline. Zero AI-created invoices, journals, stock movements, or payments. Sales invoice and customer/vendor payment counts remained 0. Manual sales posting was not run due to the provider failure stop.

## Status, scope consumption, and next steps

- **Cumulative budget consumption:** Both authorized smoke attempts consumed 13,200 micros ($0.013200) total reserved. Actual provider-billed cost is not verified. No budget reset, refund, automated retry, fallback provider, or 3rd call occurred.
- **Final read-only policy check:** Daily company/user request allowance is 0; USD 0.006800 remains available under the unchanged USD 0.02 budget, which does not authorize a third attempt. Consent remains current, speech disabled and fallback off.
- **Overall verification verdict:** Release PASS; local transport source accepted; live DeepSeek remote dispatch FAIL; JSON extraction and unsaved Sales handoff NOT VERIFIED. QuickConnect UX remains not begun behind the live provider failure gate.
- **Next minimum requirements:** Verify provider account key validity and credit balance externally, or implement bounded source-only sanitized HTTP status logging for diagnostics. Further live calls strictly require new explicit Owner scope, attempt allowance, and budget authorization. No key value is requested here. Key rotation remains unverified per Owner override.

*Check completed at 2026-10-12 13:39:56 UTC. This record documents finite observed operator evidence; it does not claim full test suite execution, disaster recovery proof, or complete feature acceptance.*

Codex independently compared the AGY-authored draft with the actual deployment output, read-only request metadata, 24-table before/after fingerprints and exact stock/ledger reconciliation results, and corrected scope wording before publication. Only this sanitized document is published; private SQL/state checkpoints, keys, application sessions, transcripts and raw provider bodies are excluded. The earlier bounded AGY source-expectation task produced no accepted handback and is not completion evidence.
