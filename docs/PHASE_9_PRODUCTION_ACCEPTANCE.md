# Phase 9 production acceptance — 2026-10-10

Status: **Complete / Source Accepted / Merged / Deployed / Production Verified**.

**PHASE 9 PRODUCTION DEPLOYED AND INDEPENDENTLY ACCEPTED**

The Owner authorized this exact production release, backups, additive migrations,
provisioning and verification. AGY executed the bounded deployment; Codex separately
verified the live source, database preservation, backups, assets, bilingual UI and
existing-data documents. This record supersedes the historical production status
in the [source handoff](PHASE_9_SOURCE_ACCEPTANCE_HANDOFF.md),
[Correction 01 handoff](PHASE_9_CORRECTION_01_HANDOFF.md) and
[engineering proposal](PHASE_9_ENGINEERING_PROPOSAL.md). Their development QA
remains historical evidence, not newly executed production tests.

## Release identity and time

| Record | Exact identity |
|---|---|
| Production HEAD / accepted GitHub main | `8d8428261cd2a10690ab77a5c7271e46b5ff5217` |
| Production / accepted merged tree | `6a81c5795790a978eec82fce12b65cae42ddf20f` |
| Accepted implementation source | `8ea77d4a63d1c8cdb0c1bad576dd3791188d233d` |
| Merged implementation | [PR #18](https://github.com/Bassamalsaqqa/account/pull/18), merged 2026-10-10 06:39:43 UTC |
| Independent source acceptance | [Review 5477970506](https://github.com/Bassamalsaqqa/account/pull/18#pullrequestreview-5477970506); both review threads resolved |
| Previous production HEAD | `96c310f30a07e97ab8e04d5afbf0b2bb805f4317` |
| Previous production tree | `a71f567ba54b16b30d4d1d4643a14a960e0ff98f` |
| Accepted planning main | `3f82b947d319d519cea6d25cecd7724bfc909672` |
| Preserved original documentation ancestor | `098b17bf0559a8b8da2308f12b73cee52ea78cd9` |

Environment: `hostinger-dalel`, [account.palsync.net](https://account.palsync.net).
Private application root: `/home/u556956644/domains/palsync.net/accounting`.
Separate public root: `/home/u556956644/domains/palsync.net/public_html/account`.
The public root is not the application checkout.

Independent preflight ran at 06:49:55–06:49:59 UTC. Production ancestry was
15 commits ahead / 0 behind the prior accepted runtime, with clean tracked files.
The application entered maintenance during the 07:09:53–07:10:11 UTC execution.
The canonical deployment completed successfully at **07:18:27 UTC**
(10:18:27 Asia/Hebron). Independent final preservation/resource checks completed
at **07:31:38 UTC**, after browser and PDF reads. Production HEAD/tree and clean
tracked state were independently confirmed, with HTTP 200 and maintenance inactive.

## Execution, safe halts and recovery

AGY built Vite assets in an isolated linked worktree at the exact target source.
Codex inspected the procedure, verified the asset bytes/archive and applied bounded
procedure corrections before execution. No GitHub Actions build was used.

Two preparation defects caused safe halts:

1. At 07:07:13–07:07:16 UTC, the migration-list comparison used Bash process
   substitution through `/dev/fd`, which was unavailable in the hosting environment.
   The application stayed online at the prior source; maintenance and database
   changes had not begun. Codex substituted a portable PHP `array_diff` comparison.
2. The retry completed backups, checkout, both migrations and document provisioning,
   then stopped in maintenance at the preservation comparison. An ignored audit
   helper accessed an associative array as an object, contaminating its original
   pre/post JSON reports. Those diagnostic files were preserved; no retrospective
   worker baseline was fabricated. Codex compared against its separate original
   preflight fingerprints and independently proved preservation before continuation.

AGY corrected only that ignored helper and completed the existing canonical script:

```sh
/opt/alt/php84/usr/bin/php artisan documents:bootstrap --all
PHP_BIN=/opt/alt/php84/usr/bin/php COMPOSER_BIN=/usr/local/bin/composer ./bin/deploy.sh --skip-migrate
```

The document bootstrap had already completed before continuation and was not
repeated. The canonical script exited **0**, installed production dependencies,
performed its existing Purchasing bootstrap, rebuilt config/route/Blade/event
caches, synchronized public assets and brought the application online.
The two migrations were separately applied and verified before `--skip-migrate`.
No Phase 7/8 reprovisioning beyond the canonical script was necessary.

There was no code rollback, database restore or reverse migration. Private staging
scripts and invalid audit diagnostics remain restricted release evidence outside
the public root; transient document-render files are cleared.

## Backups and recovery checkpoints

The complete prior SQL schema/data and application state were captured under
maintenance before checkout/migrations. Backup directory:
`storage/app/private/deployment-backups/phase9-20261010-070956-8d84282/`.
Directories are **0700** and files **0600**. New archives are encrypted at rest
using OpenSSL AES-256-CBC, salted PBKDF2 with 100,000 iterations. A separate
server-local restricted key escrow exists; no key or secret content was exported.

| Artifact | Bytes | SHA-256 |
|---|---:|---|
| SQL before compression/encryption | 242,875 | `8b654f3c2a366e03559c40afe3036a841ffa881e1cfce5cd3c8c90d9f27a3b3e` |
| SQL gzip before encryption | 28,848 | `2743f0752e7886b9c34fcebf0f2feccf6c5ba8e6f2ba4d57c4fcbdc5eb7b51eb` |
| `pre-phase9-db.sql.gz.enc` | 28,880 | `f738d0799f35f89ce4f24b69fb14fd42b1464918c942f0aef6a27ec37faaa421` |
| State tar.gz before encryption | 351,010 | `448897e6781fa626436f4445df1516753edb08382f1d7c1da863d1a87b5d4616` |
| `pre-phase9-state.tar.gz.enc` | 351,040 | `7dba9918242542aa89e5535c6c4abcf4a7ef012b361e0ca0b13425dea0644884` |

Codex independently streamed decryption, compared plaintext/gzip/archive digests,
verified `gzip -t` / `tar -tz`, counted **80 SQL table definitions** and inspected
the archive's **43 entries**. It includes private `.env`, prior application and
actual separate-public-root builds/manifests/entry files, public uploads and private
files. Nested deployment backups, escrow and transient renders are excluded.
New plaintext SQL/gzip/state archives were removed after successful roundtrips.
No SQL, `.env`, encryption key or full private financial DTO was downloaded or
published. Direct web probes of configuration/private deployment paths were denied.

The previous backups remain unchanged:

| Previous archive | SHA-256 |
|---|---|
| `pre-phase7-20261009-143933-51adf80.sql.gz` | `77db466f7872628b505fc0d319755258f47f8b467e63a21ff94704feb127d4e0` |
| `pre-phase8-20261009-144604-c07559c.sql.gz` | `6bd5ce11b5cd6457a3f43839f2f45d4380a9dfc18cd0b0c6faea1f9d7b3a9fb6` |

Recovery retains the two additive migrations. A code/assets rollback can use the
recorded prior SHA and encrypted prior state, with reviewed dependency/cache/public
asset synchronization. Database restoration must not overwrite newer business
transactions automatically. Key continuity and recovery remain governed by
[issuance key recovery](PHASE_9_ISSUANCE_KEY_RECOVERY.md) and the
[deployment guide](HOSTINGER_DEPLOYMENT_GUIDE.md).
Decryption/archive verification is **not** a restore rehearsal; server-local
escrow alone is **not** an off-host disaster-recovery copy.

## Migrations, permissions and economic preservation

Only these two migrations were added, in batch 13:

- `2026_10_09_000001_add_issued_content_to_public_shares`
- `2026_10_09_000002_create_managed_product_catalogs`

Independent checks found **63 applied / 0 pending migrations**, **83 tables**,
nullable `public_shares.encrypted_snapshot` of type **MEDIUMTEXT**, and empty
`catalogs`, `catalog_items`, `catalog_publications` tables. Public shares remain
empty. No destructive schema command, rollback, reset or reseeding occurred.

`documents:bootstrap --all` added the ten intended permissions to the Owner:
**87 → 97**. Total registered permissions are 97; role-permission grants are
**208 → 218**. Exact existing non-Owner grant sets, not merely counts, match:

| Role | Unchanged permission count |
|---|---:|
| Administrator | 43 |
| Manager | 38 |
| Sales | 10 |
| Purchasing | 9 |
| Warehouse | 4 |
| Cashier | 6 |
| Viewer | 11 |

Reporting remains **69 reports**. All **12 document sequence records/counters**
are unchanged. Phase 7/8 foundation and protected delegation contracts are retained.

Codex independently compared sorted row-hash table fingerprints against the
06:49:59 UTC baseline. Before bilingual browser switching, **75 of 80 preexisting
tables** matched; only `cache`, `sessions`, `migrations`, `permissions` and
`role_has_permissions` changed. Final verification matched **74 of 80**: the same
five operational/provisioning deltas plus `users`, because the authorized language
toggle updates locale/`updated_at`. Arabic was restored. A separate hash of every
other user column is unchanged. Configuration and encryption-key continuity are
unchanged, verified through private environment/configuration digests.

All financial, stock, allocation, balance, master-record, audit-event and sequence
fingerprints are unchanged. No customer, invoice, purchase, payment, check, stock
transaction, public token, catalog draft/publication or artificial business record
was created. The three new catalog tables remain empty after verification.

| Independent reconciliation | Result | Relevant live evidence |
|---|---|---|
| Accounting | Healthy | 35 accounts; 0 batches/lines; debit/credit both `0.000000` |
| Inventory | Healthy | No discrepancies; movement/valuation fingerprints unchanged |
| Sales | Healthy | 0 Customers/quotations/invoices/returns/payments; 1 money account |
| Payables | Healthy | 0 Vendor Payments/application events; no violations |
| Money | Healthy | 1 money account; 0 base-only historical lines |
| Phase 7 | Healthy | 0 expense/payroll/landed-cost events; no violations |

These are independent read-only production reconciliations, not worker-only claims.
The [Phase 7/8 acceptance](PHASE_7_8_PRODUCTION_ACCEPTANCE.md) remains preserved.

## Actual application and document verification

| Verification executed by Codex | Actual scope/result |
|---|---|
| Deployed HTTP-kernel renders | **28 passed**: 14 screens × AR/EN; 200, correct `lang`/`dir`, no visible Phase 9 translation keys |
| Authenticated Edge desktop | **22 passed**: 11 screens × AR/EN, normal 1528px viewport; correct RTL/LTR, no page overflow or server error |
| Authenticated Edge mobile | **6 passed**: settings/documents, catalogs, barcode-labels × AR/EN at 390×844; no horizontal page overflow; viewport restored |
| Existing Vendor Statement print | **2 passed**: AR/EN, localized Print button, correct identity/period/direction; actual screenshots inspected |
| Existing Vendor Statement PDF | **2 actual one-page outputs** downloaded/visually inspected; Arabic shaping/RTL and English LTR/identity/period/page numbering readable |
| Independent HTTPS probes | **32 passed**: 3 exact assets, 24 invalid-token GET/HEAD format/locale reads, 4 protected paths, public login |

The 14 server-rendered screens are Dashboard, Settings, Document Settings, Sales
Invoices, Quotations, Sales Returns, Customer Receipts, Purchases, Purchase Returns,
Vendor Payments, Vendors, Catalogs, Barcode Labels and Reports. The verifier reused
an existing authenticated Owner session in memory, with array session/cache,
pre-execution SQL mutation veto and transaction rollback. It did not fabricate
authentication or grant permissions.

The 11 desktop screens are Document Settings, Dashboard, the seven Sales/Purchasing
document lists listed above, Catalogs and Barcode Labels. Catalog prices are
unchecked; no catalogs exist. The media limitation and immutable-image policy are
visible. Document Settings includes historical-identity/quotation-term warnings.
Barcode selection is empty and invalid print/download actions are disabled.
The existing vendor detail exposes bilingual View / Print / Download actions.

Production has two existing Vendors, one Product and no Product Barcodes. There
are no Customers, invoices, purchases, payments or shares. The inspected vendor
has no transactions; the live PDFs contain identity/date/period without financial
entries. Currency-heavy, allocation, multipage, barcode-decoding, restricted-role,
revocation/race and positive public-sharing checks therefore retain the accepted
isolated [source](PHASE_9_SOURCE_ACCEPTANCE_HANDOFF.md) and
[correction](PHASE_9_CORRECTION_01_HANDOFF.md) evidence. They were not invented
or reproduced with production fixtures.

Actual downloaded PDF fingerprints:

| Locale | Bytes / pages | SHA-256 |
|---|---|---|
| Arabic | 38,956 / 1 | `d025a19fcf0c535159ed4ddc05622819b2e420f412749c56e9f19fe929b482cb` |
| English | 43,353 / 1 | `85ed9919880fca1cd21413adc7ef6535c2a2667f4dfd6f149e17acee4fe3d510` |

The browser download-event wait timed out; the actual Arabic file supplied by the
Owner was inspected. English retrieval through the browser's download API succeeded
and matched the Owner's second copy. Both private PDFs remain untracked, outside
the documentation PR. PDF page PNGs remain in ignored local evidence. Browser
screenshots were visually inspected in the tool transcript; saved browser PNG files
are not claimed. The print tab reported no console warnings/errors.

Invalid financial/catalog tokens returned **404** for GET/HEAD across AR, EN, JSON,
print and PDF formats. These requests created no valid grants. HTTPS asset digests
match both private and public build files; dotfile/private-staging probes returned
403/404. No TLS validation bypass was used in independent acceptance.

## Hosting and asset evidence

| Parameter | Independently observed |
|---|---|
| PHP CLI / configured web handler | PHP **8.4.26**; `application/x-httpd-alt-php84` |
| CLI executable | `/opt/alt/php84/usr/bin/php` |
| Composer / Laravel / mPDF | **2.9.8 / 13.33.0 / 8.3.1**; installed production dependencies match lock |
| MariaDB / SQL | **11.8.9-MariaDB-log**; strict SQL mode active |
| Database `max_allowed_packet` | **1,073,741,824 bytes (1 GiB)** |
| PHP memory | CLI and inspected LSAPI configuration **512M** |
| Execution setting | CLI `0`; LSAPI inspection local `0`, master `300` seconds |
| Cache / sessions | Database-backed; canonical cache rebuild and live requests succeeded |
| Private temporary storage | Writable; **0** remaining entries under `storage/app/private/document-render` |
| Product image storage | Public-root storage resolves to intended `storage/app/public` |
| Encryption configuration | APP_KEY retained; previous-key count remains 0; private `.env` digest unchanged |

Required extensions (`bcmath`, `curl`, `fileinfo`, `gd`, `intl`, `mbstring`,
`openssl`, `pdo_mysql`, `tokenizer`, `xml`, `zip`) are installed. Actual web PDF
generation succeeded. The 1 GiB database packet setting accommodates the accepted
bounded encrypted payload contract; no near-cap financial snapshot was created
in production. The inspected LSAPI settings are not a guarantee of Hostinger
per-request CPU/process limits. The application rendering time check occurs after
rendering and is not preemptive; a local 15-second target is not a production SLA.
No Redis, Docker, permanent Node service, worker or Supervisor was introduced.

| Exact deployed asset | SHA-256 |
|---|---|
| `build/manifest.json` | `9d321efe15d1700798f2cb7f8cb11a886f74ded26011d3f52f14eb057c501b16` |
| `build/assets/app-DSmFGccF.css` | `f72823dcfebca7fc0c0718f76072a5f9dcf7abb9a8fe10f1275a617a64557c28` |
| `build/assets/app-DMsN-rLE.js` | `f0d3557302f9eba56acf6ee08eb53f2d49c2a8cf994a70708a2bcd8ab22676cf` |

Codex verified these hashes independently on disk in both roots and over HTTPS.
Build directories are 0755, files 0644. No tracked production files changed.

## Logs, evidence and limits

The active log is daily: `storage/logs/laravel-2026-10-10.log`. The older audit
helper's `laravel.log`-only counter was insufficient and its reported zero is not
used as the log acceptance result. Independent daily-log inspection found four
errors: two array-access errors from the ignored deployment audit helper at
07:10:09/07:10:12, and two errors from the deliberately blocked verifier permission
cache warmup at 07:19:15. Codex reinitialized that verifier's permission cache to
the in-memory store; its 28 checks then passed without SQL mutations. No application
source was modified for either helper repair. Diagnostics were retained.

No new daily-log error followed these repaired helpers during the actual browser,
PDF/print and final operational checks. This is a focused release observation,
not a long-duration exception-rate study.

Private local evidence is under `.ai/delegations/phase9-production-release/`:
independent pre/post fingerprints, resource/operational/backup/HTTP/render reports,
browser/PDF observations, exact asset hashes, AGY execution logs and worker handbacks.
AGY conversations `1d61294b-c92e-4899-9c7f-613848e52d8f` and
`1b7521dc-b658-4118-a6e7-33f148419b90` performed bounded execution and documentation
drafting respectively through the installed delegation workflow. Codex reviewed,
corrected and independently accepted their evidence. Worker results alone were
not used to declare production acceptance.

Remaining non-blocking limitations and future hardening:

- Full restore rehearsal, independent off-host recovery/escrow assurance and measured
  hosting CPU/process budgets remain Phase 10 work.
- Populated positive document/public-sharing/catalog/barcode workflows and live
  restricted-role manipulation were intentionally not exercised without business
  data or legitimate publication. Accepted isolated regressions remain the evidence.
- The Settings hub retains an older “coming soon” print-template card alongside the
  working Document Settings link; this is a navigation-copy observation, not a
  renderer outage. Broad responsive/accessibility coverage remains future hardening.
- Previous Phase 7/8 browser message-channel observations remain qualified historical
  observations; this release does not invent a diagnosis or claim a complete browser
  matrix was rerun.

All nine protected Owner files are unchanged. Both original **708-line** roadmap
copies retain SHA-256
`63c97eeef744a54e9cbbfe991c91f2e057397a755d8d5f1a79317c8f19843f35`.
The new untracked Owner Phase 10 specification and the two supplied
private PDFs are preserved and excluded from publication. Existing branches and
documentation ancestry remain intact.

This reconciliation changes documentation only. No additional runtime feature,
canonical posting/stock writer change, automated messaging, production fixture or
Phase 10–13 implementation was introduced during release finalization. Phase 10
remains future hardening, Phase 11 API readiness, Phase 12 AI assistance and Phase 13
optional mobile, as already recorded in the unchanged roadmap.
