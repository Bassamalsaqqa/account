# Phase 10 P1 Backup, Off-Host Custody and Isolation Preparation Packet

**Document Identity:** `docs/PHASE_10_P1_BACKUP_ISOLATION_PREPARATION.md`\
**Task ID:** `p10_p1_backup_prep_20261010` (Run ID: `run_79bf5536fa5e414391eb55bce9a11b9c`)\
**Phase State:** Stage 0 Bounded Preparation Only — Actionable Planning Packet\
**Source Baseline:** `1f1fa5874eaffe749d901ee14a21850e327c9687`\
**Accepted Main Baseline:** `c74c9a4e0b4135055dbb8ed7d3e3715d2ddba24f` (tree `24ba8457fccd1eb7af236571d197ec67abf895c5`)\
**Production Runtime Release:** `8d8428261cd2a10690ab77a5c7271e46b5ff5217` (tree `6a81c5795790a978eec82fce12b65cae42ddf20f`)\
**Operational Status:** **BLOCKED EXTERNAL AUTHORIZATION — NOT VERIFIED LIVE**\
**Customer Readiness:** **NOT YET ESTABLISHED**

---

## 1. Authority Boundaries and Operational Disclaimers

1. **Worker Scope Only:** This document is an actionable, source-evidenced preparation packet prepared by the AGY worker within this isolated workspace. The AGY worker did not access the hosting panel, server, or production environment. Codex performed separately authorized read-only host/panel inventory, recorded in the [central Stage 0 record](PHASE_10_P1_OPERATIONAL_READINESS.md).
2. **Phase 10 Source Records:** Existing runbooks and matrices (`docs/PHASE_10_BACKUP_RESTORE_RUNBOOK.md`, `docs/PHASE_10_OPERATIONAL_MONITORING_RUNBOOK.md`, `docs/PHASE_10_SOURCE_ACCEPTANCE_MATRIX.md`, `docs/PHASE_10_ARCHITECT_HANDOFF.md`) are Phase 10 source records, not P0 records. Exact PR #21 source candidate was accepted by the independent architect ([PR #21 review](https://github.com/Bassamalsaqqa/account/pull/21#pullrequestreview-5480577247), [release comment](https://github.com/Bassamalsaqqa/account/pull/21#issuecomment-6101589437); source comment is not GitHub APPROVE) and merged to main as `d97a63fb0e3536abcccf229eb42909e848a63f57` (tree `cd3bef3d321acfb7093ff4a5d3b8831d28e91fcd`). Stage 0 PR #22 was accepted subject to this finite P3 status correction ([PR #22 review](https://github.com/Bassamalsaqqa/account/pull/22#pullrequestreview-5480746067), [Owner review](https://github.com/Bassamalsaqqa/account/pull/22#pullrequestreview-5480754648)). Current Owner explicitly authorized accepted merges/deploy as source deployment direction only; all three P1 actual operational proofs remain BLOCKED EXTERNAL AUTHORIZATION, and P10-G/full customer readiness is NOT ACCEPTED.
3. **AGY worker operational prohibitions:** No production credentials, `.env` files, live databases, live archives, encryption keys, SMTP servers, provider APIs, or Git metadata were accessed, used, or modified. No production or source database operations are executed now.
4. **Host & Platform Environment:** Windows host capabilities are **NOT VERIFIED**. Encrypted ephemeral volumes, VMs, containers, and firewalls were not provisioned.
5. **Preservation of Existing Schemas:** The four older interrupted synthetic schemas from prior runs (`accounting_p8_tmp_*`) remain preserved and quarantined in the stopped task-owned scratch server. Blanket prefix cleanup is strictly prohibited.
6. **Live Gate Status:** All actual recovery/off-host/mail proofs remain **BLOCKED EXTERNAL AUTHORIZATION / NOT VERIFIED**. Customer readiness is **NOT YET ESTABLISHED**.

---

## 2. Carried Historical Backup Evidence & Carried Escrow Baseline

Historical backup checkpoints and on-host escrow records are classified strictly as `[CARRIED PRODUCTION EVIDENCE]`, not fresh operational proofs. Decryption stream tests and archive listing do not establish database restoration, key recovery, or application availability.

### 2.1 Pre-Phase 9 Backup Checkpoint (Carried)
Captured at `2026-10-10 07:09:56 UTC` under `storage/app/private/deployment-backups/phase9-20261010-070956-8d84282/`:
- **Prior Schema State:** 80 tables and 61 migrations at commit `96c310f30a07e97ab8e04d5afbf0b2bb805f4317`.
- **Ciphertext Components at Rest:**
  - `pre-phase9-db.sql.gz.enc`: Stored size 28,880 bytes, SHA-256 `f738d0799f35f89ce4f24b69fb14fd42b1464918c942f0aef6a27ec37faaa421`.
  - `pre-phase9-state.tar.gz.enc`: Stored size 351,040 bytes, SHA-256 `7dba9918242542aa89e5535c6c4abcf4a7ef012b361e0ca0b13425dea0644884`.

### 2.2 Earlier Historical Checkpoints (Carried)
- **Pre-Phase 7 Backup:** `pre-phase7-20261009-143933-51adf80.sql.gz` (SHA-256: `77db466f7872628b505fc0d319755258f47f8b467e63a21ff94704feb127d4e0`).
- **Pre-Phase 8 Backup:** `pre-phase8-20261009-144604-c07559c.sql.gz` (SHA-256: `6bd5ce11b5cd6457a3f43839f2f45d4380a9dfc18cd0b0c6faea1f9d7b3a9fb6`).

### 2.3 Carried Same-Host Escrow Limitation
Key escrow files reside on the primary host under `storage/app/private/deployment-key-escrow/` (`0700`/`0600`).
- **Limitation:** Same-host escrow cannot survive loss or compromise of the primary host/account (Risk RSK-12).
- **Classification:** CARRIED baseline evidence, not independent off-host custody.

---

## 3. Two Independent Restoration Proof Lanes

Readiness requires two independent, non-substitutable operational proofs:

### 3.1 Lane A: Historical Upgrade Compatibility Proof
- **Objective:** Restore the pre-Phase 9 baseline (`80 tables / 61 migrations` at `96c310f30a07e97ab8e04d5afbf0b2bb805f4317`) into an isolated database, verify structure and counts, then forward-upgrade under reviewed compatible target code to the Phase 9/10 baseline (`83 tables / 63 migrations`).
- **Procedure:**
  1. First satisfy the explicit authorization and complete pre-bootstrap isolation gate in section 8. Then restore matching historical code/build/files and SQL into the verified disposable MariaDB schema; verify 80 tables and 61 migrations.
  2. Run forward migrations: `2026_10_09_000001_add_issued_content_to_public_shares` and `2026_10_09_000002_create_managed_product_catalogs`.
  3. Execute idempotent bootstrappers: `php artisan purchasing:bootstrap --all` and `php artisan documents:bootstrap --all`.
  4. Verify updated schema: exactly 83 tables, 63 migrations, 97 registered permissions and the exact carried Phase 9 grant sets (218 total at acceptance); counts alone do not prove authorization preservation.
  5. Verify historical row preservation across all tables and execute all six domain reconciliations.
- **Status:** **BLOCKED EXTERNAL AUTHORIZATION / NOT VERIFIED**.

### 3.2 Lane B: Current Disaster Recovery Proof
- **Objective:** Restore a complete current coherent recovery point (`83 tables / 63 migrations` at exact accepted production SHA `8d8428261cd2a10690ab77a5c7271e46b5ff5217`) after simulated loss of the primary host/account, using off-host ciphertext and separately held secrets.
- **Required Coherent Elements:** Full matching application source/code and compatible dependencies, SQL dump, matching build digest (`build/manifest.json` SHA-256 `9d321efe15d1700798f2cb7f8cb11a886f74ded26011d3f52f14eb057c501b16`), private files (`storage/app/private/`), public assets (`storage/app/public/`), configuration reference, and applicable Laravel key history (`APP_KEY`, `APP_PREVIOUS_KEYS`).
- **Verification:** Pre-bootstrap isolation verification, database import, tenant login, 14 bilingual routes (AR/EN), print/PDF rendering, issued-record decryption replay, and all six domain reconciliations.
- **Status:** **BLOCKED EXTERNAL AUTHORIZATION / NOT VERIFIED**. Historical compatibility cannot substitute for this proof.

---

## 4. Repository Cryptography, Key Management & Storage Reference

### 4.1 Cryptographic Configurations and Call-Sites
- **Application Cipher:** AES-256-CBC with HMAC-SHA256 authenticated encryption ([config/app.php#L98-L106](../config/app.php#L98-L106)) implemented via Laravel's `Illuminate\Encryption\Encrypter`.
  - *Distinction:* Laravel application encryption incorporates an authentication MAC over IV and ciphertext, rejecting tampered payloads. In contrast, the backup archive encryption uses raw OpenSSL CLI PBKDF2/AES-256-CBC (`openssl enc -aes-256-cbc -pbkdf2 -iter 100000`), which is unauthenticated; archive integrity must be verified via independent SHA-256 digests prior to decryption.
- **Active Key & Key History:** Loaded from `env('APP_KEY')` and `env('APP_PREVIOUS_KEYS')` ([config/app.php#L100, L102-L106](../config/app.php#L100)).
- **Application Encryption Usage:**
  - `app/Services/Sales/IssuedDocumentContent.php:29-42`: Encrypts/decrypts document JSON snapshots (`Crypt::encryptString`, `Crypt::decryptString`) for `public_shares.encrypted_snapshot`.
  - `app/Services/Sales/IssuedFinancialShares.php:101, 117`: Encrypts/decrypts share access tokens (`encrypted_token`).
  - `app/Services/Catalogs/CatalogService.php:287, 333, 339, 438`: Encrypts/decrypts catalog access tokens (`encrypted_token`).
  - `app/Services/Sales/PublicShareService.php:155`: Decrypts public share token.
- **Key Custody Invariant:** If `APP_KEY` is rotated or lost on the primary host, historical encrypted snapshots and tokens remain recoverable provided applicable historical keys are preserved in an independent key escrow. Generating a key (`key:generate`) without maintaining `APP_PREVIOUS_KEYS` breaks decryption of historical records.

### 4.2 Storage Disks and Service Paths
- **Filesystem Configuration:** [config/filesystems.php#L33-L60](../config/filesystems.php#L33-L60) defines `'local'` (`storage/app/private`), `'public'` (`storage/app/public`), and standard `'s3'` drivers.
- **Installed Service Paths (Carried Baseline):**
  - PHP 8.4 CLI: `/opt/alt/php84/usr/bin/php`
  - LiteSpeed LSAPI: `application/x-httpd-alt-php84`
  - MariaDB: `11.8.9-MariaDB-log` (strict SQL mode)
  - Composer: `2.9.8`
  - Private App Root: `/home/u556956644/domains/palsync.net/accounting`
  - Public Web Root: `/home/u556956644/domains/palsync.net/public_html/account`

---

## 5. Backup Cadence, Retention Policy & Coherent Capture Requirements

### 5.1 Proposed Cadence and Retention (Awaiting Owner Adoption)
- **Proposed Cadence:** Daily coherent backup point plus critical checkpoints (pre-release, pre-migration, pre-key-rotation).
- **Proposed Retention:** `7 daily / 4 weekly / 3 monthly`.
- **Status:** **PROPOSED, NOT ADOPTED**. Requires Owner adoption and hosting capacity verification.
- **Age Thresholds (Proposed Target):** Age > 24h triggers warning; Age > 48h triggers critical alert. Measured strictly from the source `captured_at` of the latest complete, off-host verified point.

### 5.2 Coherent Capture Requirements & Host Capability Escalation
- **Maintenance Prohibition:** Putting the application into maintenance mode (`artisan down`) is strictly forbidden by policy and is not authorized.
- **Capture Coherence:** Low traffic periods or file modification timestamps (`mtime`) do **not** guarantee SQL and filesystem coherence.
- **Fail-Closed Capture Standard:** Backup capture must be rejected if immutability and content hashes before and after across a bounded consistent snapshot cannot prove SQL/file coherence.
- **Host Escalation:** CloudLinux/CageFS capabilities (such as filesystem snapshots or LVM) remain unknown. If the hosting environment cannot provide provably coherent non-maintenance snapshots, the capability gap must be escalated to the Owner/architect. No production database operations are performed now.

---

## 6. Off-Host Destination Options, Independent Secrets Custody & Primary-Host-Loss Recovery

### 6.1 Off-Host Destination Options
To survive complete loss or suspension of the primary hosting account, ciphertext must be pushed to an independent provider:
- **Option 1: S3-Compatible Object Storage** (e.g. Cloudflare R2, Wasabi, Backblaze B2, AWS S3) with bucket versioning/object lock.
- **Option 2: Independent SFTP/rsync Host** with public-key authentication restricted to an isolated chroot.
- **Option 3: Encrypted Cloud Cold Storage** for monthly retention points.

*Constraint:* Destination provider and account are proposed options; specific selection requires explicit Owner approval.

### 6.2 Independent Secrets Custody
- **Separation of Custody:** Independent recovery copies of backup secrets and applicable application-key history must be held under separately controlled custody from backup storage and must not depend on primary-host access. The runtime necessarily holds its active application keys; this does not substitute for independent key recovery. Archived .env may co-contain keys and credentials: ciphertext access remains restricted.
- **Custody Channel:** Stored in an independent, access-controlled vault or hardware key held by a designated Key Custodian.
- **Archive Contents:** Application state archives (`*.tar.gz.enc`) contain the production `.env` file and are secret-bearing. Custody of the archive itself must be strictly access-controlled.

### 6.3 Primary-Host-Loss Recovery Verification
Verification procedure assuming total primary host/account unavailability:
1. Operator retrieves ciphertext components and manifest from the independent off-host store using off-host credentials.
2. Operator retrieves the trusted manifest SHA-256 digest via an out-of-band channel.
3. Operator retrieves decryption passphrases and application key history from independent vault custody.
4. Operator stands up an isolated recovery host and independently verifies environment, database grants/identity and network controls. Offline inspector checks alone never permit bootstrap. After explicit scoped approval and safety proof, execute restore without accessing primary infrastructure.

---

## 7. Manifest Specification (Version 1 Inventory) & Digest Binding

Enforced by the offline inspector ([bin/recovery-preflight.php](../bin/recovery-preflight.php)):

### 7.1 Required Top-Level Manifest Fields
- `version`: Integer, must be `1`.
- `mode`: `"historical"` or `"current"`.
- `point_id`: String (regex `/^[a-zA-Z0-9_-]{1,80}$/D`).
- `captured_at`: ISO 8601 UTC timestamp (`YYYY-MM-DDTHH:MM:SSZ`).
- `completed_at`: ISO 8601 UTC timestamp (`YYYY-MM-DDTHH:MM:SSZ`).
- `off_host_verified_at`: ISO 8601 UTC timestamp or `null`.
- `code_sha`: 40-character hex commit SHA (Historical: `96c310f30a07e97ab8e04d5afbf0b2bb805f4317`; Current: `8d8428261cd2a10690ab77a5c7271e46b5ff5217` or `c74c9a4e0b4135055dbb8ed7d3e3715d2ddba24f` is technically allowlisted by the inspector; this assignment pins the actual deployed point to `8d8428261cd2a10690ab77a5c7271e46b5ff5217`, not a deployment of documentation-only main).
- `build_sha256`: 64-character hex SHA-256 of frontend `build/manifest.json`.
- `schema`: `{"tables": 80, "migrations": 61}` (historical) or `{"tables": 83, "migrations": 63}` (current).
- `key_history_refs`: Array of 1..32 objects with `{"id": string, "cipher": "AES-256-CBC"}` (zero raw key bytes).
- `config_ref`: String identifier.
- `components`: Object containing exactly six `.enc` components.

### 7.2 Six Required Ciphertext Components
Each component must end with `.enc`, have byte size > 0 and $\le$ 512 MiB, and match `point_id` and SHA-256 digest:
1. `sql`: Encrypted MariaDB database dump.
2. `code_build`: Matching full application source/code and frontend build assets; dependency locks and compatible installed dependencies or reproducible offline dependency custody.
3. `private_files`: Required private business files, attachments and templates; omit recursive deployment-backup copies, on-host key escrow and transient renders. Preserve the originals; independently authorize key custody separately.
4. `public_assets`: Encrypted `storage/app/public` uploads.
5. `encrypted_records`: Coverage of encrypted issued financial records, catalog tokens and Fortify 2FA records; identify existing SQL coverage rather than inventing extra production exports.
6. `configuration`: Relevant configuration dependencies in protected ciphertext; nonsecret config_ref is the manifest reference, not proof that the archive excludes secrets.

### 7.3 Independent Manifest Digest Verification
The manifest file's SHA-256 hash must be verified against the trusted `--manifest-sha256` parameter before reading or parsing JSON ([bin/recovery-preflight.php#L62](../bin/recovery-preflight.php#L62)).

---

The retained historical point has two physical encrypted archives, while the inspector models six logical components. Before use, an authorized operator must establish a reviewed manifest mapping and complete matching code/build/file/configuration/key coverage from the trusted archives. Do not fabricate six independent captures, recapture timestamps, or claim that inspector success proves archive coherence. Any representation/tooling gap remains NOT VERIFIED until safely reconciled in isolation.

## 8. Fail-Closed Pre-Bootstrap Isolation Architecture

### 8.1 Safe Nonpublic Encrypted Scratch
- **Storage Substrate:** Decryption and extraction require verified encrypted ephemeral storage (e.g. dedicated encrypted volume). Plain `tmpfs` is not necessarily encrypted as pages may swap to unencrypted disks; BitLocker and VM environments are not verified.
- **Permissions:** Strict POSIX `0700` directories, `0600` files. Depth $\le$ 20, entries $\le$ 20,000. The inspector checks scratch symlinks and bounded traversal ([bin/recovery-preflight.php#L223-L230](../bin/recovery-preflight.php#L223-L230)); independent archive-member validation must additionally reject hardlinks and special device nodes before extraction. The inspector does not extract or validate archive members.
- **Archive Traversal Refusal:** Tar/zip members containing `..` or absolute paths (`/`) immediately abort extraction.

### 8.2 Archived Environment & Cache Quarantine
- **Quarantine Directory:** Any extracted `.env` or cached file must be placed in a separate `/quarantine` folder outside the application root.
- **Active Application Root:** Must contain zero archived `.env` files and zero framework cache files in `bootstrap/cache/` (`config.php`, `routes-v7.php`, `services.php`, `packages.php`) ([bin/recovery-preflight.php#L235-L241](../bin/recovery-preflight.php#L235-L241)).

### 8.3 Independently-Owned Restricted Database & Nonce Proof
- **Dedicated Loopback Server:** Database host must be numeric loopback only (`127.0.0.1` or `::1`).
- **Naming Pattern:** Schema `accounting_p10_restore_<12hex>`, user `p10_restore_<12hex>`.
- **Privilege Confinement:** User granted permissions *only* on that specific schema. No global grants or persistent schema access.
- **Ownership Proof Binding:** Protected file (mode `0600`) binding `host`, `port`, `schema`, `user`, and 64-hex `nonce` ([bin/recovery-preflight.php#L143-L152](../bin/recovery-preflight.php#L143-L152)). Emptiness (0 tables) verified via non-Artisan tooling before bootstrap.

### 8.4 Network, Mail, Queue, and Environment Isolation BEFORE Artisan
1. **Network Egress/Ingress Block:** Outbound internet traffic, external DB IPs, and SMTP ports (25, 465, 587) blocked at the network/firewall level. Public inbound traffic blocked.
2. **Environment Selection:** Merely naming a file `.env.rehearsal` does not load it. Explicitly select the fresh rehearsal configuration, inspect its resolution and process overrides without Laravel, and verify destination/network/grants before any application bootstrap.
3. **Environment Parameters:**
   - `APP_ENV=rehearsal`, `APP_DEBUG=false`, `APP_URL=http://127.0.0.1:<port>`
   - `DB_CONNECTION=mysql`, `DB_HOST=127.0.0.1`, `DB_PORT=<port>`, `DB_DATABASE=accounting_p10_restore_<12hex>`, `DB_USERNAME=p10_restore_<12hex>`
   - Unset `DB_URL` and `DB_SOCKET` ([config/database.php#L49, L55](../config/database.php#L49)).
   - `MAIL_MAILER=array` ([config/mail.php#L78-L80](../config/mail.php#L78-L80)) to prevent network transmission and log exposure.
   - Queue: [config/queue.php#L32-L65](../config/queue.php#L32-L65) lacks a `'null'` connection in its connections array; setting `QUEUE_CONNECTION=null` causes unexpected resolution errors. A rehearsal-only queue connection with driver `null` must be defined in trusted scratch rehearsal configuration and selected via `QUEUE_CONNECTION=rehearsal_null`. No runtime source code edits.
   - Scheduler (`artisan schedule:run`) and workers must not run.
4. **Pre-Bootstrap Verification:** Effective configuration values and blocked network egress must be verified before executing any Artisan command.

### 8.5 Exit 2 Authority Boundary & Protected Key Injection
- **Exit Code 2 Meaning:** [bin/recovery-preflight.php](../bin/recovery-preflight.php) returning Exit 2 (`bootstrap_authorized=false`, `INSPECTION_ONLY_BOOTSTRAP_BLOCKED`) confirms metadata and scratch checks passed, but **NEVER** authorizes bootstrap or key injection.
- **Prerequisites for Bootstrap & Key Injection:** Requires independent verification of network egress blocks, target database emptiness/grants, effective environment resolution, AND explicit Product Owner operational approval.
- **Key Injection:** Application keys (`APP_KEY`, `APP_PREVIOUS_KEYS`) injected just-in-time via protected file permissions (`0600`) or secure process memory; never via CLI arguments, shell history, Git, or logs.

### 8.6 Secure Cleanup & Escalation
- **Uncertainty Principle:** Cleanup MUST NOT drop any database if schema ownership, connection identity, or process binding is uncertain; halt, quarantine, and escalate.
- **Exact Schema Drop:** Only the exact verified disposable schema `accounting_p10_restore_<12hex>` and user `p10_restore_<12hex>` may be dropped under scoped approval.
- **Preserve Older Schemas:** The four older schemas (`accounting_p8_tmp_*`) remain preserved.
- **Crypto-Erasure:** Simple user-space file overwriting or unlinking on SSD storage does not guarantee physical wear-leveling erasure; crypto-erasure (destroying the encryption key of the ephemeral volume) must be performed after retaining authorized sanitized evidence.

---

## 9. Finite Synthetic Negative Test and Real Proofs Matrix

### 9.1 Synthetic Negative Tests (Offline Tooling Checks)
Verified against [bin/recovery-preflight.php](../bin/recovery-preflight.php) (standalone runner [tests/Deployment/recovery-preflight.php](../tests/Deployment/recovery-preflight.php), carried baseline 21 executed / 50 assertions / 1 NOT RUN):

| Test Case | Condition / Injected Fault | Expected Result & Code | Failure Reason |
|---|---|---|---|
| `TC-SYN-01` | Missing mandatory CLI parameter. | Exit 1 | `INVOCATION` |
| `TC-SYN-02` | Tampered manifest file (SHA-256 digest mismatch). | Exit 1 | `MANIFEST_TRUST` |
| `TC-SYN-03` | Manifest contains unredacted raw `APP_KEY` or `base64:` key. | Exit 1 | `SECRET_EXPOSURE_REFUSED` |
| `TC-SYN-04` | Commit SHA does not match expected baseline. | Exit 1 | `CODE_BUILD_IDENTITY` |
| `TC-SYN-05` | Corrupted component ciphertext (checksum mismatch). | Exit 1 | `COMPONENT_HASH` |
| `TC-SYN-06` | Component path contains directory traversal (`../`). | Exit 1 | `COMPONENT_PATH` |
| `TC-SYN-07` | Inverted timestamps (`captured_at` > `completed_at`). | Exit 1 | `POINT_TIMESTAMPS` |
| `TC-SYN-08` | Future timestamp in manifest. | Exit 1 | `POINT_TIMESTAMPS` |
| `TC-SYN-09` | Active `.env` file present in scratch root (unquarantined). | Exit 1 | `ACTIVE_ARCHIVED_CONFIGURATION` |
| `TC-SYN-10` | Stale cache file in scratch `bootstrap/cache/config.php`. | Exit 1 | `ACTIVE_STALE_FRAMEWORK_CACHE` |
| `TC-SYN-11` | Target database host is non-loopback IP. | Exit 1 | `TARGET_HOST` |
| `TC-SYN-12` | Target schema name does not match `accounting_p10_restore_<12hex>`. | Exit 1 | `TARGET_SCHEMA` |
| `TC-SYN-13` | Target user does not match `p10_restore_<12hex>`. | Exit 1 | `TARGET_USER` |
| `TC-SYN-14` | Ownership proof file binding or nonce mismatch. | Exit 1 | `OWNERSHIP_BINDING` / `OWNERSHIP_NONCE` |
| `TC-SYN-15` | Symlink present in scratch directory. | Exit 1 | `SYMLINK_REFUSED` |
| `TC-SYN-16` | Valid parameters, trusted digest, clean scratch, bound proof. | **Exit 2** | `INSPECTION_ONLY_BOOTSTRAP_BLOCKED` |

### 9.2 Real Operational Proofs (Separately Authorized Execution)
Executed only under explicit Product Owner authorization in an isolated target:

| Proof ID | Verification Target | Pass Criteria | Evidence Retention |
|---|---|---|---|
| `OP-PRF-01` | Ciphertext Decryption | PBKDF2 stream decryption clean; decrypted SQL matches trusted digest. | Sanitized checksum log; source ciphertext intact. |
| `OP-PRF-02` | Pre-Bootstrap Isolation | Zero outbound network connectivity; target DB empty; no active `.env` or stale caches. | Firewall probe log; non-Artisan query evidence. |
| `OP-PRF-03` | Schema Upgrade (Lane A) | 80/61 verified; forward migrations to 83/63 clean; bootstrappers succeed; row preservation confirmed. | Migration status log; table row counts. |
| `OP-PRF-04` | Application Verification | Tenant login succeeds; all nine financial documents verified in AR/EN print and PDF; 14 core screens render HTTP 200. | Response headers; PDF render checks. |
| `OP-PRF-05` | Issued Record Decryption | Historical encrypted snapshots verified readable via key history. Note: Carried production baseline contains 0 issued records (`public_shares` 0 rows); synthetic fixtures provide separate supporting proof. | In-memory verification; zero secret logging. |
| `OP-PRF-06` | Six Reconciliations | `Accounting`, `Inventory`, `Sales`, `Payables`, `Money`, and `Phase 7` healthy with no domain discrepancies. Five run via CLI (`accounting:reconcile`, `inventory:reconcile`, `sales:reconcile`, `money:reconcile`, `phase7:reconcile`); Payables runs via existing reviewed service harness. | Sanitized reconciliation results. |
| `OP-PRF-07` | Ephemeral Cleanup | Verified disposable schema dropped; user revoked; crypto-erasure of scratch; 4 older schemas preserved. | Non-Artisan post-check; process termination check. |

---

## 10. Observed RPO and RTO Timestamp Formulas

### 10.1 Observed Recovery Point Objective (RPO)
$$\text{Observed RPO} = T_{\text{disaster}} - T_{\text{captured\_at}}(P_{\text{latest\_complete\_offhost}})$$

- **Rule:** Measured from the source `captured_at` timestamp of the latest *complete*, independently verified, off-host recoverable point.
- **Snapshot Consistency Window:** $\Delta t_{\text{snap}} = T_{\text{completed\_at}} - T_{\text{captured\_at}}$.
- **Freshness Invariant:** Uploading or transferring an old backup point today does not refresh its source capture timestamp or improve its RPO.
- **Target vs Guarantee:** Proposed RPO $\le 24\text{h}$ is a target to measure against, not an operational guarantee.

### 10.2 Observed Recovery Time Objective (RTO)
$$\text{Observed RTO} = T_{\text{verified\_online}} - T_{\text{declared\_start}}$$

- **Rule:** Measured from the declared start of recovery until application login, document rendering, key decryption, and all six reconciliations are verified healthy.
- **Target vs Guarantee:** Proposed RTO $\le 4\text{h}$ is a target to measure against, not an operational guarantee.

---

## 11. Fail-Closed Stop Conditions

Recovery rehearsal must immediately halt if any of the following occur:
1. Manifest SHA-256 digest does not match out-of-band trusted checksum.
2. Ciphertext component size or SHA-256 hash mismatch.
3. OpenSSL decryption failure or corrupted block detected.
4. Archive contains path traversal (`..`), absolute paths (`/`), or symlinks.
5. Production `.env` or stale bootstrap cache detected in the active application root.
6. Target DB host is not loopback (`127.0.0.1` or `::1`).
7. Target schema name does not match `accounting_p10_restore_<12hex>`.
8. Target database is not empty at initial pre-import ownership verification, or belongs to persistent data. A legitimately imported owned target is expected to contain restored tables.
9. Prohibited external network reachability or unexpected outbound traffic occurs. Negative probes must target an operator-controlled endpoint and demonstrate denied traffic; do not probe live production databases.
10. Application encryption keys (`APP_KEY`, `APP_PREVIOUS_KEYS`) unavailable or invalid.
11. Migration error or schema divergence during Lane A upgrade.
12. Any of the six domain reconciliations reports discrepancies or integrity violations.
13. Uncertainty during cleanup regarding schema ownership or connection identity.
14. Timebox exceeded.

*Halt Action:* Abort immediately. If ownership is confirmed, drop only the verified disposable schema. Securely destroy ephemeral scratch. Quarantine evidence and escalate.

---

## 12. Required Scoped Product Owner Authorization Fields

Codex must obtain explicit scoped Product Owner approval before initiating rehearsal. The authorization must specify:
1. `authorized_operator`: Name/role of designated operator.
2. `rehearsal_type`: `HISTORICAL_UPGRADE` or `CURRENT_DISASTER_RECOVERY`.
3. `target_host_spec`: Dedicated isolated rehearsal host identifier and loopback port (must not be production).
4. `approved_backup_point_id`: Approved recovery point ID and trusted manifest SHA-256 digest.
5. `approved_data_scope`: Carried snapshot or synthetic fixtures.
6. `key_custodian`: Named custodian holding decryption passphrases and application keys.
7. `destination_spec`: Named off-host storage endpoint and credentials.
8. `max_rehearsal_window_hours`: Explicit timebox.
9. `cleanup_policy`: Explicit instruction to drop only the verified disposable schema, crypto-erase scratch, and preserve the four older schemas.
