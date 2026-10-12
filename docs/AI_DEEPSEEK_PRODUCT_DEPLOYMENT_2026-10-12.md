# DeepSeek product — controlled production deployment

Status: **DEPLOYED; focused release verification passed**. Verification completed at `2026-10-12T18:37:26Z`. This record does not claim live DeepSeek connectivity, real microphone accuracy or complete operational readiness.

## Source and authorization

- Production: `https://account.palsync.net`.
- Accepted PR #33 source: `6cf06a71245500ffcb0d7c7d933032f11bce012e`.
- Owner-merged and deployed main: `088893def0c4ee985bf670c8cc6d3caecaed7b79`.
- Previous deployed runtime and rollback source: `b0a4b15354aad76b2b81eda2a01a935c05dd6de6`.
- The final independent architect review accepted the exact correction head; the Owner subsequently merged and explicitly authorized deployment. The merged tree was verified identical to the accepted head. Codex performed no additional source modification or merge.
- [Accepted source evidence](https://github.com/Bassamalsaqqa/account/blob/6cf06a71245500ffcb0d7c7d933032f11bce012e/docs/AI_DEEPSEEK_PRODUCT_ACCEPTANCE_2026-10-12.md) and [merged PR #33](https://github.com/Bassamalsaqqa/account/pull/33).

## Controlled release procedure

The prior application stayed live during preparation. The release required the exact prior SHA, clean tracked production source, unchanged accepted main, an exclusive deployment lock, verified PHP/Composer compatibility, a freshly authenticated existing operator session and trusted frontend archive integrity before maintenance.

Inside maintenance, access was held closed while the application drained, all six reconciliation domains passed, and private rollback checkpoints were captured. SQL was streamed directly through compression and encryption; no plaintext SQL backup was written. The secret-bearing state archive includes production configuration and was encrypted without exposing its contents. Decryption/compression/archive integrity checks were performed on-host without extraction; these checks are not a restoration rehearsal.

The exact merged release was checked out without rewriting history. Composer installed the locked production dependencies under PHP **8.4.26** and passed `check-platform-reqs --no-dev`; the additions were `laravel/ai` v1.2.0 and `symfony/yaml` v8.1.8. Autoload, package discovery, config, route, view and event caches were rebuilt. The accepted source was rebuilt locally with Vite: **62 modules**, then the staged archive checksum was checked on Hostinger. Public CSS/JavaScript were verified through actual HTTPS against the deployed file bytes. Static directories/files use 0755/0644.

**No migration ran:** the migration diff was empty, and the existing 67 migrations and 89 tables were preserved. No settings policy, key, permission, transaction or financial history was changed. Existing platform AI, report-question, speech and command API settings and cost ceilings were preserved exactly; this release neither activates additional providers nor enlarges budgets.

## Actual verification

| Check | Result |
| --- | --- |
| Deployed Git identity and tracked working tree | PASS — exact merged SHA; no tracked drift |
| Authenticated Hostinger HTTPS pages | PASS — 26 checks: 13 routes in Arabic RTL and English LTR |
| Routes inspected | Dashboard; Sales/Purchase creation and lists; products; customers; vendors; inventory; reports; Settings; AI Settings; Money |
| Authenticated session API | PASS — 200 with correct operator/company binding |
| Anonymous session API | PASS — 401 without actor/company disclosure |
| Actual Linux Livewire resolution | PASS — assistant launcher and DeepSeek setup aliases; rendered snapshots and authenticated shared layouts |
| AI Settings | PASS — built-in DeepSeek setup rendered in both languages; no connection-test click or settings save |
| Fresh application errors during authenticated checks | PASS — zero ERROR/CRITICAL/ALERT/EMERGENCY entries in the inspected interval |
| Accounting, Inventory, Sales, Payables, Money, Phase 7 reconciliations | PASS — all six healthy before and after deployment for every company |
| Database preservation | PASS — full-row count/hash fingerprints unchanged across **86 nonvolatile tables**; 89 total tables and 67 migrations unchanged |
| Permissions, users, numbering and AI history | PASS — exact fingerprints; sequence/permission/role metadata separately matched |
| Configuration, private files and uploads | PASS — production `.env` and business-file fingerprints unchanged |
| Public release assets | PASS — trusted archive and actual HTTPS byte integrity; readable static permissions |
| Reopening | PASS — maintenance off; anonymous `/` returns 302, `/login` 200, `/api/v1/session` 401 |
| Provider dispatch by release verification | **Zero** — no paid connection test, assistant Send, fallback or speech-provider request |

The 28 authenticated checks consist of 26 page checks plus two API checks. Volatile cache, cache-lock and session tables were excluded from exact fingerprint comparison. The existing operator session was reused after the Owner signed in; only its temporary locale was changed for AR/EN inspection and restored afterward. No test account was created, password changed or persistent user locale modified. These are focused release checks, not broad regression, accessibility certification or real audio tests.

## Backup, rollback and sensitive material

Encrypted SQL and state checkpoints, a verified source bundle, and matching prior public/private build artifacts remain in restricted private on-host custody. Backup artifacts have 0600 permissions in a 0700 release directory. Temporary database client options were removed; credentials, SQL, keys, session cookies, reset tokens and raw private audit data were not exported or published.

| Retained artifact | Bytes | SHA-256 |
| --- | ---: | --- |
| Encrypted SQL checkpoint | 32688 | `ecbbc414da47dc13ee21c61dd308e5756586bc3d42cdd5b7c4ef9cd03327bf05` |
| Encrypted application-state checkpoint | 582944 | `3a1151240b3c5652a82ca40f7558735a916aa298e1661e4e868334e974379350` |
| Verified source bundle | 5827808 | `869c6ffbc3dd739fb992f53eec685d13e47b76edb0e9e6f90e9e59f4651d238a` |
| Prior release static artifacts | 3020800 | `fee04e4b8e2f3d9190fb0d6fc437fdc1f8ef5613dfda1b9e246d833833863a8f` |

Release tooling had a fail-closed rollback guard: restore the exact prior source and locked dependencies, restore its public/private build artifacts, rebuild caches, compare database/business-file preservation, rerun authenticated checks and reopen only after those pass. No failure triggered rollback in this release. A later rollback must first enter maintenance and recheck current business preservation; **do not reverse migrations or overwrite the database**. Retained artifacts and prior source provide rollback preparation, not proof of an executed rollback drill.

The checkpoint secrets are retained separately from ciphertext within the same hosting account. **This is not independent off-host backup/key recovery.** The state ciphertext contains production configuration and must never be extracted or activated outside the separately approved isolated-recovery procedure.

## Remaining gates and preservation

- Genuine SDK-backed DeepSeek response and end-to-end live unsaved-form handoff remain **NOT VERIFIED** for this release. Historical paid attempts, reservations and request counters are untouched. No new paid test was authorized or performed by this deployment.
- Actual Arabic/English microphone recognition, permission-prompt behavior and device quality remain **NOT VERIFIED**. Source/browser lifecycle checks carried from the accepted head used synthetic events. The native browser-permission product decision is preserved.
- API-specific provider retention and actual billed costs remain **NOT VERIFIED**; no promise or estimate is relabeled as an invoice.
- Phase 10 isolated restore, independent off-host backup/key custody and real password-recovery delivery remain deferred operational gates. On-host encrypted checkpoint integrity does not satisfy them.
- PR #32's separate documentation privacy findings are not waived or changed here. No production financial record was deleted, reversed, recreated or added.
- Protected Owner specifications, private PDFs/evidence, original roadmaps and unrelated worktrees were preserved. No GitHub CI or broad test rerun occurred.

Production is reopened on the accepted release. This documentation-only record does not authorize further source changes, provider spending, feature-policy changes or a future deployment.
