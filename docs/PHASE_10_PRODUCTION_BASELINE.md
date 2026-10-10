# ACCOUNTING — PHASE 10 PRODUCTION HARDENING
## Sanitized Carried Production Baseline Metadata

**Document Identity:** `docs/PHASE_10_PRODUCTION_BASELINE.md`\
**Phase State:** **HISTORICAL PHASE 9 / CODEX DISCOVERY EVIDENCE — ARCHITECT ACCEPTANCE PENDING**\
**Carried Acceptance Date:** 2026-10-10 07:31:38 UTC (10:31:38 Asia/Hebron)\
**Accepted Production SHA:** `8d8428261cd2a10690ab77a5c7271e46b5ff5217`\
**Accepted Production Tree:** `6a81c5795790a978eec82fce12b65cae42ddf20f`\
**Target Host / Domain:** `hostinger-dalel` / `account.palsync.net`\
**Sanitization Notice:** This document contains **only public metadata, checksums, schema structures, and sanitized configuration values**. All production passwords, database credentials, `APP_KEY` strings, PBKDF2 escrow key bytes, session cookies, bearer tokens, customer/vendor names, and personal identification data are strictly excluded.

Codex discovery/internal review: **COMPLETE**. Independent architect acceptance: **PENDING final correction review**. Runtime packages: **PREPARED, NOT DISPATCHED**. Phase 10 tests: **NOT RUN**. Production hardening: **INCOMPLETE**. Customer readiness: **NOT YET ESTABLISHED**.

---

## 1. Carried Production Environment & Hosting Configuration

| Parameter | Carried Live Production Value | Evidence Source |
|---|---|---|
| **Operating System** | CloudLinux / CageFS virtual environment | `[CARRIED PRODUCTION EVIDENCE]` |
| **Domain** | `https://account.palsync.net` | `[CARRIED PRODUCTION EVIDENCE]` |
| **Private Application Root** | `/home/u556956644/domains/palsync.net/accounting` | `[CARRIED PRODUCTION EVIDENCE]` |
| **Separated Public Web Root** | `/home/u556956644/domains/palsync.net/public_html/account` | `[CARRIED PRODUCTION EVIDENCE]` |
| **Web Server / PHP Handler** | LiteSpeed LSAPI (`application/x-httpd-alt-php84`) | `[CARRIED PRODUCTION EVIDENCE]` |
| **PHP CLI Binary** | `/opt/alt/php84/usr/bin/php` (PHP 8.4.26 CLI) | `[CARRIED PRODUCTION EVIDENCE]` |
| **Composer Version** | 2.9.8 | `[CARRIED PRODUCTION EVIDENCE]` |
| **Laravel Framework** | 13.33.0 | `[CARRIED PRODUCTION EVIDENCE]` |
| **mPDF Version** | 8.3.1 | `[CARRIED PRODUCTION EVIDENCE]` |
| **Database Server** | MariaDB 11.8.9-MariaDB-log | `[CARRIED PRODUCTION EVIDENCE]` |
| **SQL Mode** | Strict SQL mode active (`STRICT_TRANS_TABLES`, `ERROR_FOR_DIVISION_BY_ZERO`, etc.) | `[CARRIED PRODUCTION EVIDENCE]` |
| **`max_allowed_packet`** | 1,073,741,824 bytes (1 GiB) | `[CARRIED PRODUCTION EVIDENCE]` |
| **PHP Memory Limit** | 512M (both CLI and LSAPI inspected configuration) | `[CARRIED PRODUCTION EVIDENCE]` |
| **Max Execution Time** | CLI: 0; LSAPI configuration: local 0, master 300s (configuration setting, not proven CPU quota) | `[CARRIED PRODUCTION EVIDENCE]` |
| **Session & Cache Storage** | MariaDB database-backed (`sessions`, `cache` tables) | `[CARRIED PRODUCTION EVIDENCE]` |
| **Private Render Temp Dir** | `storage/app/private/document-render/` (0 remaining files after verify) | `[CARRIED PRODUCTION EVIDENCE]` |
| **Public Storage Link** | `public_html/account/storage` -> `accounting/storage/app/public` | `[CARRIED PRODUCTION EVIDENCE]` |
| **Installed PHP Extensions** | `bcmath`, `curl`, `fileinfo`, `gd`, `intl`, `mbstring`, `openssl`, `pdo_mysql`, `tokenizer`, `xml`, `zip` | `[CARRIED PRODUCTION EVIDENCE]` |

---

## 2. Pinned Asset Hashes & Documented File Permissions

### 2.1 Static Frontend Build Assets
Built strictly from exact source `8d8428261cd2a10690ab77a5c7271e46b5ff5217` via `npm run build` and verified identically in both private `public/build` and mapped public web root:

| Asset Path | Size (Bytes) | SHA-256 Digest | Documented Permissions |
|---|---:|---|---|
| `build/manifest.json` | 331 | `9d321efe15d1700798f2cb7f8cb11a886f74ded26011d3f52f14eb057c501b16` | Normalized directory `0755`, file `0644` |
| `build/assets/app-DSmFGccF.css` | 80,239 | `f72823dcfebca7fc0c0718f76072a5f9dcf7abb9a8fe10f1275a617a64557c28` | Normalized directory `0755`, file `0644` |
| `build/assets/app-DMsN-rLE.js` | 51,516 | `f0d3557302f9eba56acf6ee08eb53f2d49c2a8cf994a70708a2bcd8ab22676cf` | Normalized directory `0755`, file `0644` |

### 2.2 Filesystem Permission Model
- **Public Web Build Assets:** Normalized to directories `0755`, files `0644` by `bin/deploy.sh` for web server read access.
- **Documented Deployment Backups & Key Escrow:** Directories `0700`, files `0600` under `storage/app/private/deployment-backups/` and `storage/app/private/deployment-key-escrow/`.
- **Note:** General private storage and framework directories are verified writable; we do not assert that every arbitrary file across the system has been scanned or that permissions are universally uniform without an explicit filesystem-wide audit.

---

## 3. Database Schema, Migrations & Role-Permission Matrix (Carried Production Facts)

### 3.1 Migration Status
- **Total Applied Migrations:** Exactly 63 applied migrations (Batches 1 through 13).
- **Pending Migrations:** 0.
- **Batch 13 Additive Migrations:**
  1. `2026_10_09_000001_add_issued_content_to_public_shares` (added `MEDIUMTEXT` nullable `encrypted_snapshot`, `access_profile`, `content_version`, `content_hash`, `subject_revision`, `request_key`, `request_hash`, `issued_at`).
  2. `2026_10_09_000002_create_managed_product_catalogs` (added `catalogs`, `catalog_items`, `catalog_publications`).

### 3.2 Table Counts & Structure
- **Total Database Tables:** Exactly 83 tables.
  - 80 pre-existing tables from Phase 1 through Phase 8.
  - 3 Phase 9 tables: `catalogs` (empty), `catalog_items` (empty), `catalog_publications` (empty).
  - Pre-existing table `public_shares` retains 0 rows.

### 3.3 Role Grants & Permissions
`documents:bootstrap --all` provisioned Phase 9 permissions to the `Owner` role only:
- **Total Registered Permissions:** 97 (up from 87).
- **Total Role Grants Across Roles:** 218 (up from 208).
- **Owner Permissions:** Exactly 97 (87 pre-existing + 10 Phase 9).
- **Non-Owner Role Grants (Strictly Unchanged):**
  - **Administrator:** 43 permissions
  - **Manager:** 38 permissions
  - **Sales:** 10 permissions
  - **Purchasing:** 9 permissions
  - **Warehouse:** 4 permissions
  - **Cashier:** 6 permissions
  - **Viewer:** 11 permissions
- **Phase 9 Permissions Added to Owner:**
  `settings.documents.manage`, `purchasing.document.pdf`, `money.receipt.share`, `catalogs.view`, `catalogs.manage`, `catalogs.publish`, `catalogs.share`, `catalogs.revoke`, `catalogs.show_prices`, `inventory.barcode_labels.print`.
- **Protected Permissions (Owner-Only by Default):**
  `settings.documents.manage`, `money.receipt.share`, `catalogs.publish`, `catalogs.share`, `catalogs.show_prices`.

### 3.4 Document Sequences & Reporting Registry
- **Document Sequence Counters:** Exactly 12 registered counters; all counters and next-number sequences unchanged and intact.
- **Reporting Registry:** Exactly 69 registered report variants; definitions and access policies intact.

---

## 4. Retained Backups & Recovery Custody References

At the recorded Phase 9/P10-0 checks, encrypted pre-release backups were retained under `storage/app/private/deployment-backups/phase9-20261010-070956-8d84282/`:

### 4.1 Phase 9 Pre-Release Backups
- **Backup Snapshot Timestamp:** Captured at `2026-10-10 07:09:56 UTC` (RPO baseline relates to this snapshot instant).
- **Prior Baseline Schema:** Contains **80 tables and 61 migrations** at prior accepted SHA `96c310f30a07e97ab8e04d5afbf0b2bb805f4317`. (Testing current compatibility in an isolated target requires restoring this prior baseline, then applying forward reviewed migrations).
- **Key Custody Reference:** Retained in server-local escrow directory `storage/app/private/deployment-key-escrow/` (mode `0700`/`0600`). No key content is exported or published.
- **Encryption Scheme:** OpenSSL AES-256-CBC with salted PBKDF2 (100,000 iterations). Note that AES-CBC is unauthenticated encryption; integrity is verified via trusted retained SHA-256 digests and stream round-trip tests rather than relying on guaranteed decryption failure on corruption.

| Artifact Name | Stored State | Stored Size (Bytes) | Plaintext Size (Bytes) | SHA-256 Digest |
|---|---|---:|---:|---|
| **SQL Database Dump** | Plaintext *(purged after enc)* | — | 242,875 | `8b654f3c2a366e03559c40afe3036a841ffa881e1cfce5cd3c8c90d9f27a3b3e` |
| **SQL Database Gzip** | Pre-encryption *(purged)* | — | 28,848 | `2743f0752e7886b9c34fcebf0f2feccf6c5ba8e6f2ba4d57c4fcbdc5eb7b51eb` |
| `pre-phase9-db.sql.gz.enc` | Ciphertext at rest | 28,880 | — | `f738d0799f35f89ce4f24b69fb14fd42b1464918c942f0aef6a27ec37faaa421` |
| **State Archive Tar** | Plaintext *(purged after enc)* | — | 351,010 | `448897e6781fa626436f4445df1516753edb08382f1d7c1da863d1a87b5d4616` |
| `pre-phase9-state.tar.gz.enc` | Ciphertext at rest | 351,040 | — | `7dba9918242542aa89e5535c6c4abcf4a7ef012b361e0ca0b13425dea0644884` |

### 4.2 Retained Historical Backups
- **Pre-Phase 7 Backup:** `pre-phase7-20261009-143933-51adf80.sql.gz` (SHA-256: `77db466f7872628b505fc0d319755258f47f8b467e63a21ff94704feb127d4e0`)
- **Pre-Phase 8 Backup:** `pre-phase8-20261009-144604-c07559c.sql.gz` (SHA-256: `6bd5ce11b5cd6457a3f43839f2f45d4380a9dfc18cd0b0c6faea1f9d7b3a9fb6`)

---

## 5. Economic & Inventory Reconciliations (Carried Live Status)

The six canonical domain reconciliations were executed in read-only mode by Codex during Phase 9 acceptance:

| Domain | Status | Authoritative Observations |
|---|---|---|
| **Accounting** | **Healthy** | 35 ledger accounts; 0 journal batches; 0 journal lines; total Debits = `0.000000`, total Credits = `0.000000`. |
| **Inventory** | **Healthy** | Zero valuation or quantity discrepancies; stock movement and valuation fingerprints unchanged. |
| **Sales** | **Healthy** | 0 Customers; 0 Quotations; 0 Invoices; 0 Sales Returns; 0 Customer Payments; 1 active money account. |
| **Payables** | **Healthy** | 0 Vendor Payments; 0 allocation/application events; zero integrity violations across 2 Vendors. |
| **Money** | **Healthy** | 1 primary cash account; 0 base-only historical lines; zero unallocated checks or transfers. |
| **Phase 7 (Ops/Payroll)** | **Healthy** | 0 operating expenses; 0 employees; 0 salary entries; 0 landed cost allocations; zero violations. |

---

## 6. Live Application Verification Evidence (Carried)

### 6.1 Server & Browser Renders
- **HTTP-Kernel Renders:** 28 checks (14 core screens × AR/EN) returned `HTTP 200`, correct `lang` and `dir`, zero raw translation keys.
- **Edge Desktop (1528px):** 22 checks across 11 screens (Document Settings, Dashboard, Invoices, Quotations, Returns, Payments, Purchases, Purchase Returns, Vendor Payments, Catalogs, Barcode Labels) passed with no page overflow.
- **Edge Mobile (390×844):** 6 checks (Settings/Documents, Catalogs, Barcode Labels × AR/EN) passed with no horizontal page overflow and clean viewport restoration.
- **HTTPS Security Probes:** 32 checks passed:
  - 3 static assets returned `HTTP 200` with matching hashes.
  - 24 invalid-token requests (`/share/{token}`, `/catalog/{token}`) across HTML, JSON, print, and PDF returned `HTTP 404`.
  - 4 protected internal paths returned `HTTP 403`/`404`.
  - Public login route returned `HTTP 200`.

### 6.2 Vendor Statement PDF Fingerprints
Generated from real live Vendor data (zero transactions) without fake data insertion:

| Output Locale | Pages | Size (Bytes) | SHA-256 Checksum | Visual Review |
|---|---:|---:|---|---|
| **Arabic (AR)** | 1 | 38,956 | `d025a19fcf0c535159ed4ddc05622819b2e420f412749c56e9f19fe929b482cb` | Readable RTL Arabic shaping, vendor identity, statement period, and page numbering. |
| **English (EN)** | 1 | 43,353 | `85ed9919880fca1cd21413adc7ef6535c2a2667f4dfd6f149e17acee4fe3d510` | Clean LTR layout, correct identity, statement period, and page numbering. |

---

## 7. Baseline Freshness & Hand-off Limitations

1. **Retained Evidence Only:** Sections 1–6 reflect the live environment state at the time of Phase 9 acceptance (**2026-10-10 07:31:38 UTC**).
2. **Worker versus lead evidence:** AGY did not access production. Codex subsequently performed the bounded fresh verification in the appendix below; sections 1–6 retain their original Phase 9 timestamps. Publication does not refresh that evidence.
3. **Restoration Gap:** Backup archives are verified for PBKDF2/AES-256 decryption and archive consistency, but have **not** been imported into a standalone test database (OPS-01 readiness gate remains open).
4. **Minimal Live Data:** The live production database contains 2 Vendors, 1 Product, and 0 commercial transactions. Populated workflow testing must remain strictly confined to disposable MariaDB test harnesses.

## Historical Codex P10-0 production verification — 2026-10-10 08:24:31 UTC

Codex performed a bounded read-only refresh after the AGY local drafts. This section supersedes draft statements that live freshness was pending. No deployment, migrations, bootstrap, financial transaction, catalog/share issuance, role change or mail send occurred.

| Check | Actual result |
|---|---|
| Production HEAD / tree | `8d8428261cd2a10690ab77a5c7271e46b5ff5217` / `6a81c5795790a978eec82fce12b65cae42ddf20f`; clean tracked checkout; maintenance inactive |
| Schema / grants / registry | 83 tables, 63 applied migrations, zero pending, 97 permission catalog entries, 69 reports; Owner/non-Owner exact grant sets and 12 sequence records unchanged |
| Reconciliations | Accounting, Inventory, Sales, Payables, Money and Phase 7 healthy in both before/after read-only snapshots |
| Existing-session deployed kernel | 28 passed: 14 routes × AR/EN, HTTP 200, correct lang/dir, no visible Phase 9 translation keys; SQL mutation veto, in-memory session/cache and transaction rollback |
| HTTPS | 32 passed with normal TLS verification: three exact asset hashes; 24 invalid share/catalog GET/HEAD format probes; four protected-path refusals; login 200 |
| Files and recovery metadata | Eight on-host asset/backup digest comparisons match retained acceptance; assets agree in private/public roots; documented build and backup modes retained; backup archive decryption/import not repeated |
| Semantic preservation | Only `cache` and `sessions` changed across this run and since the retained Phase 9 table snapshot. Other 81 table fingerprints, exact role grants, sequences, environment digest and non-locale user digest unchanged |
| Log | Current daily-log error count 4 before / 4 after; historical helper entries retained, zero new error entries during refresh |
| Runtime/config | PHP 8.4.26; MariaDB 11.8.9; installed production dependencies match lock; required extensions available; Company timezone `Asia/Hebron`; configuration evidence remains distinct from actual resource quotas |
| Recovery email | SMTP selected; explicit SMTP host key absent. End-to-end delivery, SMTP URL/alternative configuration and approved recovery path remain NOT VERIFIED; no email sent |

Private source evidence: `.ai/delegations/phase10-p0/fresh-live/summary.json`, `shell.txt`, `before-db.json`, `after-db.json`, `resources.json`, `ar-en-render.json`, `http.json` and `hash-verification.json`. No raw financial rows, credentials, keys or session payloads belong in published documentation.

Fresh web-browser screenshots/keyboard/mobile interactions and actual populated PDFs were NOT RUN in P10-0. Phase 9 browser/PDF acceptance is carried evidence; new bilingual checks above are deployed HTTP-kernel renders, not browser automation. Isolated restore, off-host/key recovery, delivery, provider scheduling and actual CPU/process/IO measurements remain triaged A/D/E work. These do not become PASS because Codex completed discovery; independent architect acceptance remains pending.

**Codex discovery/internal baseline review complete; independent architect P10-0 acceptance PENDING. Runtime implementation NOT AUTHORIZED; production hardening incomplete and customer readiness NOT YET ESTABLISHED.**

## Recovery boundaries for Phase 10

### A. Historical upgrade compatibility

The pre-Phase 9 recovery point contains **80 tables / 61 migrations** at `96c310f30a07e97ab8e04d5afbf0b2bb805f4317`. A future separately authorized isolated rehearsal must restore matching code/build/files/SQL, verify this prior state, then forward-upgrade to the accepted Phase 9 **83 tables / 63 migrations**, checking content and all six reconciliations at each stage. This proves historical upgrade compatibility only, not current disaster recovery or a daily recovery point.

### B. Current coherent-backup disaster recovery

Independently restore a current coherent backup with compatible exact code/build, SQL, private attachments, public assets, encrypted issuance/grants/revisions, relevant configuration and applicable application-key history. Require encrypted off-host retention that survives loss of the hosting account, independent recovery of backup decryption secrets and Laravel `APP_KEY`/`APP_PREVIOUS_KEYS`, separately controlled custody/access logs and trusted digests. Same-host escrow/on-host hashes do not establish this capability.

D1-B proposes daily completed coherent backups and pre-release/schema/key-change checkpoints, 7 daily/4 weekly/3 monthly retained points, completion/failure/transfer monitoring and >24h age alerts for the latest **off-host recoverable** point. These are proposed policies awaiting authorized adoption/capacity validation, not current hosting facts. Record the adopted cadence, retention, alert ownership and any Owner/architect-approved material change; missing proof remains P1. Measure actual recovery-point gap/age and restore elapsed steps (observed RPO/RTO); ≤24h/≤4h are provisional goals, never claimed from the old checkpoint.

Verify isolated application startup/login, tenant authorization, file/row/content hashes, schema/key/cipher/version compatibility, existing grant expiry/revocation and **Accounting, Inventory, Sales, Payables, Money, Phase 7** reconciliations. At the recorded baseline no issued financial content exists on production; document this limitation, and separately backup/restore owned synthetic encrypted fixtures for application/previous-key decryption. Never fabricate production grants/Customers/transactions, reconstruct missing immutable issuance from live data, or equate fixture proof with actual production-record recovery.

### C. Secret-bearing archive and pre-bootstrap isolation

The accepted Phase 9 release record explicitly says the encrypted state archive contains private production `.env` and application state. It can therefore co-contain DB/provider credentials and application-key values. Separate **backup decryption** key escrow does not prove application keys are absent from the archive. Treat every ciphertext copy and extracted scratch artifact as secret-bearing; retain restricted custody and independently recover application key history. Do not retrieve archives/keys, rotate keys or extract anything during this correction.

Apply [package D1-C](PHASE_10_AUTONOMOUS_IMPLEMENTATION_PACKAGES.md#d1-c-fail-closed-pre-bootstrap-isolation-both-proof-lanes) to both restore lanes: (1) verify trusted encrypted checksum; (2) decrypt/extract only in restricted nonpublic network-isolated scratch using separately authorized offline backup-secret custody; (3) quarantine archived production `.env` and any stale Laravel config caches; (4) generate isolated settings with a newly verified owned disposable host/schema/user; (5) block production DB/network access, SMTP/integrations/jobs/queues/public ingress; (6) prove effective isolated connection identity and blocked alias/egress/ingress **before any Artisan, migration or application startup**; (7) inject only separately authorized temporary application keys/previous-key history through protected custody after the pre-bootstrap gate; (8) verify recovery and securely clean up plaintext, secrets, keys, schemas, credentials, caches and transient processes, or retain only approved quarantined evidence. **Never activate archived production credentials.**

Negative proofs cover production `.env`, stale cached DSN, production host aliases, wrong destination/ownership, SMTP/external/job execution, public ingress, unavailable/wrong keys, corruption/incoherent backup, path escape and missing assets/content. Fail before unsafe work. Decryption/gzip/tar consistency checks are neither historical nor current actual restoration acceptance. Both restore proofs, independent off-host/key recovery and real approved account recovery remain **NOT RUN / NOT VERIFIED**; customer readiness is not established.

## Documentation publication scope

PR #19 is merged at current main `3e9ebf812a463db9d3f18aaf07b10431c63e84f5` (tree `6e4f98739ace0c0890847f33f6bad0de1a4e84e6`). Its tree equals preserved Phase 9 documentation commit `47bd392ff4e2adb268805bfd7339805243c677c1`. Phase 9 production runtime remains the accepted historical release `8d8428261cd2a10690ab77a5c7271e46b5ff5217`; this correction performs no production refresh. PR #20 branch `docs/phase10-p0-contract` preserves `47bd392… → e20c1ea… → documentation correction` by a normal new commit, with no branch reconstruction or history rewrite. A main-to-branch merge is unnecessary because both the effective and merge-base differences already contain exactly the four intended P10-0 documents and the Phase 9 documentation trees are equal.

Only these four P10-0 planning documents are publication files. The protected Owner original, roadmaps, supplied PDFs, existing browser artifacts and ignored evidence remain unchanged/excluded. The accepted Phase 9 production record is untouched. No runtime, test, migration, dependency, lockfile or deployment-script edits; no CI, application tests, browser runs, SMTP probes, backup/key retrieval/extraction, schema operations, production access, merge or deployment.

Codex discovery/internal review: **COMPLETE**. Independent architect acceptance: **PENDING final correction review**. Runtime packages: **PREPARED, NOT DISPATCHED**. Phase 10 tests: **NOT RUN**. Production hardening: **INCOMPLETE**. Customer readiness: **NOT YET ESTABLISHED**.
