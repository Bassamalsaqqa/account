# Accounting AI V2.2 controlled production release

Production: https://account.palsync.net. Verified live on 2026-10-14 at 08:18:05 UTC. Owner authorized the accepted sequential merges and deployment. This record contains sanitized operational evidence; it does not establish independent disaster recovery or live model accuracy.

## Source identities and history

Previous deployed source: `32d40c8d7339e9371a16751e7a2ab8ff092f1830`. Previous GitHub main: `088893def0c4ee985bf670c8cc6d3caecaed7b79`.

Final deployed source and GitHub main: **`7fdb22b271266efd965737777f4cb08ba5bf0b2a`**, tree **`379bba573a49b46b54d4795f29cde576a7351de5`**. The final tree equals the accepted PR #42 tree. The production tracked tree is clean and maintenance is OFF.

| Accepted source | PR | Normal merge commit |
|---|---|---|
| A2 `12812ff3e8f364ffbaba9d2bd33505ea9128dc53` | [#39](https://github.com/Bassamalsaqqa/account/pull/39) | `5ea1216a72b4690cd477522fa2635d85b3a00ece` |
| A3 `103fff459e7469753671796ad0021671ef72f419` | [#40](https://github.com/Bassamalsaqqa/account/pull/40) | `880a98efaef03e995635fe07fef7551c456d06a9` |
| A4 `3846f6366e82122b5460ca485533c10457e0e830` | [#41](https://github.com/Bassamalsaqqa/account/pull/41) | `4ac959091eea0ec277f409cd1c2e20f41082b80d` |
| A5/A6 and shared boundary/parity `bd72de5f5e09e41303754ec49a47abf0064bdc81` | [#42](https://github.com/Bassamalsaqqa/account/pull/42) | `7fdb22b271266efd965737777f4cb08ba5bf0b2a` |

A1's accepted source `53282ff37d09d1a0860b2962af317dd061e678b0` was already merged. Published ancestry and head branches were preserved; no squash, rebase, amend or force push occurred. No accepted implementation was changed during this release. This subsequent documentation record is not a new deployed runtime.

## Preservation and controlled activation

Maintenance and an exclusive deployment lock guarded replacement. Before changing source, restricted encrypted SQL/application-state backups, ciphertext checksums, a verified Git bundle, previous assets and original environment/web configuration were retained privately. Archive encryption secrets are held separately from archives on the same hosting account. No backup, SQL, key, cookie, token or production account identifier was exported or published.

Linux PHP 8.4 platform checks and Composer optimized autoload passed. Package discovery and configuration, routes, views and event caches were rebuilt. Explicit Livewire component resolution passed on the actual host. There were no dependency, migration, role-catalog or canonical financial-writer changes requiring production provisioning. No migration, reseeding or database restoration was executed.

Full-row fingerprints prove **86 nonvolatile tables preserved exactly**, out of **89 tables / 67 applied migrations**. Only `cache`, `cache_locks` and `sessions` are excluded as volatile. Financial histories, inventory, parties, numbering, permissions, company policies, encrypted credentials, consent, requests and usage remained unchanged. Business/private/public file checksums passed, excluding generated builds/render caches, protected backup custody, the eight intentional new PWA assets and the separately verified web configuration change.

Only four new feature flags were enabled: `AI_TURN_ENABLED`, `AI_VOICE_AUTO_SUBMIT_ENABLED`, `AI_DAILY_BRIEFING_ENABLED`, `PWA_ENABLED`. Every other environment line was preserved byte-for-byte. Existing AI, report, provider-approval and command API flags remain enabled. No company activation, report-allowlist expansion, new grant, key replacement, consent creation or provider call occurred. Existing company settings and narrower permissions continue to control available features.

Platform spending ceilings remain USD **0.01/request**, **1/company and user/day**, **5/company and user/month**. Custom company limits and all historical counters/reservations were preserved; unknown-cost reservations were not reset or reclassified.

## Actual release verification

| Check | Observed result |
|---|---|
| Accounting, inventory, Sales, Purchasing/AP, money and Phase 7 reconciliations | All six domains healthy across existing companies before and after deployment |
| Authenticated AR/EN HTTP | 13 existing routes in both languages: 26 checks PASS |
| API session | Actual fresh authenticated Owner session 200; unauthenticated request 401; actor/company binding verified |
| Livewire and application logs | Alias and snapshots render; zero fresh application errors during authenticated checks |
| Public assets | 11 HTTPS checks PASS; accepted disk hashes, expected MIME and 0755/0644 public modes |
| Actual Edge UI | Arabic RTL and English LTR dashboard/drawer render; daily action returns permitted canonical cards |
| Paid dispatch / financial writes | No model calls or financial writes by release verification; five AI-history tables remain fingerprint-identical after drawer/briefing checks |

The route checks cover dashboard, Settings, AI Settings, reports, Sales/Purchase creation and other shared-layout workflows. They use the existing legitimate authenticated Owner session; no impersonated account, password change, fabricated session or security bypass was used. A fresh normal sign-in was required for the API rather than accepting remembered credentials. Temporary locale changes were restored.

The only web-server configuration addition is `AddType application/manifest+json .webmanifest` within `mod_mime`; every existing directive was retained byte-for-byte and the original file is available for rollback. Hostinger otherwise served the manifest as `text/plain`. Its CDN losslessly recompresses three PNG icons: HTTPS decoded dimensions and every pixel match accepted files, while all non-PNG assets retain exact byte checks. A one-pixel substitution is rejected. SVG/manifest/script checks were not relaxed.

## Measured Hostinger response work

| Authorized current-data scenario | SQL queries | Elapsed | DTO bytes | Peak PHP memory | Model calls |
|---|---:|---:|---:|---:|---:|
| Monthly Sales comparison | 76 | 71.48 ms | 1,634 | 36 MiB | 0 |
| Daily briefing under existing policy | 162 | 120.20 ms | 3,025 | 36 MiB | 0 |

These are bounded CLI measurements on the actual hosting environment and current selected company's data, not model/network latency, browser round-trip measurements, a stress test or general hosting capacity certification. The inspector enforces 192 MiB memory, a 25-second execution bound and a 64 KiB output bound. It forbids external HTTP, uses process-local caches, rejects data-changing SQL and rolls back the outer transaction. Hostinger rejects canonical `SELECT FOR UPDATE` authorization inside server-enforced READ ONLY transactions (SQLSTATE 25006 / code 1792); the measurement preserves those fresh authorization locks rather than removing them. Independent fingerprinting/reconciliation audits retain server-enforced read-only transactions.

## Guard-triggered rollback and evidence custody

Seven guarded attempts rolled back before the successful release. Private-inspector initialization/query-classification errors, incompatible read-only locking, manifest MIME and lossless CDN PNG encoding were diagnosed and corrected without changing accepted application source. Each post-replacement failure restored the previous source, original environment/assets, rebuilt caches, verified database/files and six reconciliations, then reopened. There was no database rollback/import or financial mutation. This exercised source/assets/config rollback; it was not a disaster-recovery rehearsal.

Restricted on-host archives, separate encryption-secret custody, original builds/configuration, code bundle, preservation fingerprints, reconciliation summaries and sanitized HTTP/resource checks remain available. Local ignored private release scripts, transcripts and cropped AR/EN screenshots are deliberate evidence artifacts and are not staged. Protected Owner specifications, private PDFs, unrelated worktrees and quarantined schemas remain untouched. The controlling Owner original's SHA-256 remains `d2bb378de74235a8329114f394dee0b3b1aeb8107bd2f84f8154f46972637aa8`.

AGY received accepted source and sanitized documentation only. A bounded release-checklist dispatch timed out without edits; Codex completed and verified production operations independently. No production custody material was sent to AGY.

## Remaining operational and product evidence

- Actual new live DeepSeek planning, Palestinian Arabic microphone acoustic accuracy and physical PWA installation/offline lifecycle were **NOT VERIFIED** in this release. No paid calls were made; mocked source tests are not substituted for those proofs.
- Independent off-host backup/key recovery, both real isolated restore lanes and approved real password-reset email delivery remain **NOT VERIFIED / separate operational authorization required**. On-host archives and successful decrypt/gzip checks do not satisfy those gates.
- Hostinger account quotas, representative larger-data/concurrency capacity and provider/network latency remain uncertified. Current-data measurements above are the available observed bounds.
- Company-policy-disabled capabilities remain disabled until explicitly authorized through existing settings. The report API remains report-only; UI/API parity is not native-client transaction onboarding.

Carried source checks are documented in [the integrated checkpoint](AI_V22_INTEGRATED_RELEASE_CHECKPOINT.md): 148 tests / 775 assertions, 45 Node tests, final 2 tests / 26 assertions, Pint, targeted PHPStan and Vite build PASS. They were not broadly rerun for deployment. No GitHub CI was introduced or executed.

**Deployment result: PASS for the controlled release. Owner product testing can proceed; the remaining independent recovery, live model, microphone and installed-device evidence is not implied by this result.**
