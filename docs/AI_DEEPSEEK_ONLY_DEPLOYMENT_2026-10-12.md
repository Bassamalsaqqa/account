# DeepSeek-only controlled deployment - 12 October 2026

Owner authorized deployment of exact source `32d40c8d7339e9371a16751e7a2ab8ff092f1830` after implementation handback. At deployment authorization, PR #36 was draft/unmerged and no independent architect acceptance had been recorded. This records an Owner-authorized direct-source deployment, not architectural acceptance or a main merge.

Prior production/main: `088893def0c4ee985bf670c8cc6d3caecaed7b79`.

The release uses the established private Hostinger layout and maintenance-safe procedure. Fresh encrypted SQL/state archives, code bundle, rollback assets and integrity manifests stay in restricted on-host custody. Backup keys remain in a separate protected directory on the same account; independent off-host recovery and actual restoration remain unverified Phase 10 gates.

No migration or canonical financial writer change belongs to this source. The deploy does not normalize company policies, replace credentials, reset limits/usage/reservations, renew consent, post transactions or make provider calls. The Owner handles any explicit company transition through the deployed application.

Outcome: **DEPLOYED AND LIVE**; release verification passed. Detailed table hashes/session evidence/backups/keys remain private; this document contains sanitized outcomes only.

## Verification outcome

- Released source: `32d40c8d7339e9371a16751e7a2ab8ff092f1830` (detached deployment; PR #36 was draft/unmerged at deployment).
- Verification timestamp UTC: `2026-10-12T20:05:23+00:00`.
- Hostinger CLI PHP 8.4.26; Composer 2.9.8; existing dependencies unchanged. Composer autoload and Laravel package/config/route/view/event caches rebuilt.
- 28 total actual Hostinger HTTPS checks (27 authenticated, one anonymous): 13 routes in both Arabic RTL and English LTR, plus authenticated API session 200 and unauthenticated API session 401. Dashboard, Sales/Purchase creation, listings, Products, Customers, Vendors, Inventory, reports, Settings, DeepSeek setup and Money passed. Explicit launcher/setup Livewire aliases resolved on Linux. No fresh application errors during these checks.
- Schema unchanged: 89 tables and 67 migrations; no migration executed.
- All 86 nonvolatile full-row table fingerprints matched. Users, permissions, roles, sequence counters, company AI policies, encrypted connections, consents, requests and usage records were preserved. Volatile exclusions: cache, cache_locks and sessions.
- Six domain reconciliations healthy before and after: Accounting, Inventory, Sales, Payables, Money and Phase 7.
- Production `.env` and business-file hashes preserved. Public build HTTPS bytes matched the uploaded build; static directories 0755/files 0644.
- Maintenance off. Public root 302, login 200 and guest API session 401 verified after reopening.
- Existing platform AI/API/speech/questions activation and spending ceilings preserved. Removing unused commercial-audio/gateway configuration constants is the intended source change, not a production secret/limit change.
- No paid provider call, financial transaction, policy normalization, credential replacement, consent renewal, usage reset or PR merge performed during release.
- Restricted encrypted backup integrity, code bundle and prior asset rollback artifacts verified. Temporary database dump options file removed. Backups/keys/SQL/session evidence remain in private custody; no archive or key downloaded.

## Remaining operational boundaries

The deployment does not certify live DeepSeek connectivity, actual provider billing, physical microphone transcription quality, independent off-host backup/key recovery, a real restoration rehearsal or email delivery. These remain separate proofs. The Owner may need the explicit **Use DeepSeek only** transition before testing an existing legacy configuration; its permissions, disabled workflows, custom limits and historical reservations are preserved. A real provider test consumes the existing budget and requires explicit consent; no automatic retry or limit increase is performed.

Rollback target remains `088893def0c4ee985bf670c8cc6d3caecaed7b79`, with matching prior assets/dependencies. Rollback changes source/assets/caches only; it does not reverse migrations or overwrite business data. Independent architect review was pending at deployment authorization; subsequent acceptance and merge are recorded below.

## Subsequent source reconciliation and Owner-access verification

PR #36 subsequently received independent exact-head source acceptance at `32d40c8d7339e9371a16751e7a2ab8ff092f1830`. Following the Owner-authorized investigation, it was merged normally as `50bf994a6c1c9d007cb798f0b290c84b4ba50d12`. The merge tree is identical to the deployed source tree; no old-main redeployment, history rewrite, runtime correction or migration was necessary.

The actual authenticated Owner session passed active-company membership, Owner status, scoped role/catalog, cached permission and fresh guard checks for AI settings, credential management and assistant use. The setup page and enabled Test control rendered in Arabic and English, and the assistant opened without dispatch. No role repair or permission expansion was justified or performed. Sensitive actor/company/session identities remain in private evidence.

A focused disposable-MariaDB/fake-provider run passed **40 tests / 201 assertions**, covering setup authorization, restricted delegated settings access, company/session changes, revoked membership, spending controls, replay protection and sanitized provider errors. Restricted-staff and wrong-company checks use synthetic fixtures; no production employee was impersonated or created. The owned synthetic schema was cleaned. No GitHub CI or broad suite was run.

A separate production verification passed **28 total HTTPS checks (27 authenticated, one anonymous)**, explicit Linux Livewire aliases, zero fresh application errors, and all six domain reconciliations. Existing business tables and encrypted connection records were unchanged. The only nonvolatile table changes arose from separately authorized company-budget updates, consent/audit records and one explicitly authorized non-confidential connection test; volatile cache/session changes are expected. No financial records, permissions, role catalog, sequence or installation-level configuration changed. Historical usage/reservations were preserved.

Provider authentication remains an external readiness gate. Test-specific metadata, limits, costs, identifiers and diagnostic evidence are retained privately; this record does not certify successful provider connectivity, token accounting or actual billing. No automatic retry was made. The deployment proof and its original timestamps above remain historical facts, separate from this follow-up.
