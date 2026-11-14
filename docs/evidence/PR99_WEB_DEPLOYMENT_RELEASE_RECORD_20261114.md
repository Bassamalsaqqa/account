# PR 99 Controlled Web Release: Production Deployment Record & Root Operator Attestation

> **DOCUMENT CONTEXT & ROLE BOUNDARY**  
> - **Source-Only Documentation**: Authored on preparation branch `t3/pr99-web-release-recovery`.  
> - **Operator Authority**: Root is the sole production operator. Zero commands, writes, or queries executed on live production by AGY.  
> - **Candidate Commit (PR 99)**: `8cd432220801b08b10b6f25f9ee9a215de94d44c` (0 migrations). PR 99 HEAD is NOT altered.  
> - **Merge Status**: This record documents candidate web deployment; it does NOT claim or execute a merge into `main`.  
> - **Sanitized Attestation**: Records verified Root attestation. Zero passwords, credentials, or private customer records are contained herein.

---

## 1. Executive Deployment Attestation

| Attribute | Verified Deployment Value | Status |
| :--- | :--- | :--- |
| **Deployed Git Commit** | `8cd432220801b08b10b6f25f9ee9a215de94d44c` | **MATCH (Exact Pin)** |
| **Public Build Manifest SHA-256** | `12cee9f9dc2213615ff19d48060308c1a89030f255e694c530083538bd15fedd` | **MATCH (Exact Pin)** |
| **Public .htaccess SHA-256** | `071652f64ad14f324544e95317aed883e59a9337acb5e1b3519bf0e71a36bdcc` | **UNCHANGED** |
| **Deployment Reopened Timestamp** | `2026-11-14T11:29:56Z` | **ONLINE** |
| **Live Maintenance Status** | `maintenance OFF` (disarmed after verified deployment) | **AVAILABLE** |
| **Database Migrations** | 0 migrations executed (default `bin/deploy.sh` skips migrations/bootstrap without requiring extra flags) | **ZERO MUTATIONS** |
| **Deployment Script & Runtime** | Reviewed `bin/deploy.sh` executed under Hostinger PHP 8.4 (`PHP_BIN=/opt/alt/php84/usr/bin/php ./bin/deploy.sh`) | **VERIFIED** |

---

## 2. Pre-Cutover Coherent Capture & Off-Host Custody

Prior to initiating source checkout or asset replacement on production, Root entered maintenance mode and established a fresh coherent backup point from live baseline `f8a0cf7012c4875bec7320d42e0f8dde58593b52`:

- **Capture Point Identifier**: `coherent_recovery_20261114_112352Z_f8a0cf7_c63e5ae869cf`
- **Component Verification**: All 7 archive components (`database.sql`, `source.bundle`, `vendor.tar.gz`, `build_assets.tar.gz`, `config.tar.gz`, `private_storage.tar.gz`, `public_assets.tar.gz`) and `manifest.sha256` were transferred off-host to Root operator custody and verified before checkout.
- **Delineation of Restoration Proof**:
  - Restoration proof was previously attested on 2026-11-14 at 08:22:07Z for source `c4e6e6d5fcb6f7c9ea98e37455eeaf3877dc78cd` into owned disposable schema `accounting_p8_tmp_6f912f8371f0` (104 tables, 79 migrations, 2,681 rows, structure SHA: `2fb72ba50815240d10099e6b8f2a2c9c34d86cb1518ae16dc5630badf699cdc6`) and cleaned up at 08:22:17Z.
  - The fresh pre-cutover capture `coherent_recovery_20261114_112352Z_f8a0cf7_c63e5ae869cf` was validated via complete checksum and manifest verification; **it was not separately restored into a disposable schema**. No restore proof is claimed beyond this defined scope.

---

## 3. Database Fingerprint Verification & Preservation Invariant

The deterministic database fingerprinting utility (`bin/final-release-check.php`) was executed with length-framed row hashing (`R<cols>; N; S<len>:<val>`), primary key ordering via `Seq_in_index`, and DDL normalization:

1. **Pre-Cutover Baseline vs. Post-Deployment Snapshot**:
   - Total tables evaluated: 104 (97 preservation tables, 7 fixed operational mutable tables).
   - Comparison result: **`FINGERPRINTS_EQUAL`** (0 preservation diffs, 0 operational diffs).
2. **Post-Deployment Smoke Check Comparison**:
   - After completing authenticated smoke checks under Arabic (AR) and English (EN) session locales, fingerprint comparison confirmed:
     - Preservation differences: **0** (`preservation_diffs_count = 0`).
     - Operational differences: Churn observed strictly on fixed mutable allowlist (`sessions`, `cache`), classified as `OPERATIONAL_VOLATILITY_ONLY`.
   - **Root Direct Re-Verification**: Root personally verified the post-smoke compare output: classification is strictly `OPERATIONAL_VOLATILITY_ONLY`, row churn occurs exclusively in `cache` and `sessions`, and exactly 0 preservation diffs exist across all 97 preservation tables.
   - Classification taint check: Passed. All business entities (`users`, `companies`, `accounts`, `journal_entries`, `purchases`, `sales_invoices`, etc.) retained zero mutations.

---

## 4. Context-Free 6-Service Domain Reconciliation

All 6 core reconciliation services were executed across **all 2 companies** in the database (without asserting an active-only filter) using the minimal isolated container on the read-only PDO connection:

| Service | Method & Context Mode | Discrepancies | Violations | Health Status |
| :--- | :--- | :---: | :---: | :---: |
| **AccountingReconciliationService** | `reconcile($company, isSystem: true)` | 0 | 0 | **HEALTHY** |
| **InventoryReconciliationService** | `auditCompany($company, fromCli: true)` | 0 | 0 | **HEALTHY** |
| **SalesReconciliationService** | `reconcile($company, isSystem: true)` | 0 | 0 | **HEALTHY** |
| **PayablesReconciliationService** | `reconcile($company, isSystem: true)` | 0 | 0 | **HEALTHY** |
| **MoneyReconciliationService** | `reconcile($company, isSystem: true)` | 0 | 0 | **HEALTHY** |
| **Phase7ReconciliationService** | `reconcile($company, isSystem: true)` | 0 | 0 | **HEALTHY** |

Total checks: **12/12 PASSED** across all companies. Discrepancy count: **0**.

---

## 5. Live Authenticated Web & Asset Smoke Verification

Root performed live authenticated smoke checks using explicit owner-provided test credentials (credentials withheld from logs to maintain privacy):

1. **Locale & Session Flow**:
   - Guest locale selection was verified prior to authentication to ensure user preference state in the database was not mutated.
   - The application serves identical canonical URLs for both Arabic and English, selecting the display language via session locale (not URL path prefixes like `/ar/...` or `/en/...`).
2. **Route Verification (Identical URLs under both AR and EN session locales)**:
   - `/dashboard`: `HTTP 200` (both AR and EN session locales)
   - `/settings`: `HTTP 200` (both AR and EN session locales)
   - `/reports`: `HTTP 200` (both AR and EN session locales)
   - `/purchases`: `HTTP 200` (both AR and EN session locales)
   - `/purchases/create`: `HTTP 200` (both AR and EN session locales)
   - `/invoices/create`: `HTTP 200` (both AR and EN session locales)
3. **Livewire Dynamic Operations**:
   - Component `$refresh` calls on both Arabic and English sessions returned `HTTP 200` without runtime exceptions.
4. **Public Asset Delivery & MIME Types**:
   - CSS bundle: `HTTP 200`, `Content-Type: text/css`
   - JavaScript bundle: `HTTP 200`, `Content-Type: application/x-javascript`
   - Web App Manifest: `HTTP 200`, `Content-Type: application/manifest+json`
5. **Post-Up Production Verification**:
   - Public unauthenticated `/login` returned `HTTP 200`.
   - Authenticated `/dashboard` under both AR and EN returned `HTTP 200` after explicitly removing the operator maintenance bypass cookie, confirming standard production access is fully operational.

---

## 6. Operational Incident Disclosure: Initial Fetch Refspec Correction

In accordance with reporting integrity, Root disclosed the following runtime adjustment during cutover:
- **Observation**: The initial `git fetch "$STAGING_DIR/source-v1.bundle" HEAD` failed because the bundle was created from a named branch ref rather than a symbolic `HEAD`.
- **Timing**: Occurred during the pre-checkout fetch step while maintenance mode was held, **before** source checkout or public asset replacement occurred (coherent backup capture and pre-cutover snapshot reads had already completed successfully).
- **Correction**: Root updated the fetch command to reference the actual named branch ref without an invented remote tracking branch refspec, followed by detached checkout:
  ```bash
  git fetch "$STAGING_DIR/source-v1.bundle" refs/heads/t3/controlled-web-release-nov13
  git checkout --detach 8cd432220801b08b10b6f25f9ee9a215de94d44c
  ```
- **Verification**: Exact commit pin `8cd432220801b08b10b6f25f9ee9a215de94d44c` was validated via `git rev-parse HEAD`. No source or asset corruption occurred.

---

## 7. Staging Custody, Log Hygiene & Financial Invariants

1. **Log Inspection**:
   - Review of `storage/logs/laravel.log` revealed **zero** new critical `ERROR` entries recorded since the start of deployment at `11:23:52Z`.
2. **Financial Invariants**:
   - Mobile V2 and mobile cash flags remained OFF; back-office legitimate financial authority remained unchanged.
   - PR 102 was NOT deployed.
   - Zero permission changes, zero account changes, zero financial writes.
3. **Private Evidence & Retained Artifacts**:
   - Staging directory `operator-63802` holds JSON reports, logs, and previous build directory in private operator custody.
   - Preserved untracked framework assets and lock files remain untouched:
     - `storage/framework/asset-stage.Rod9Vi`
     - `storage/framework/deepseek-assets.cCmzPJ`
     - `storage/framework/deepseek-assets.fYgzxl`
     - `storage/framework/deploy.lock`

---

## 8. Verification Limitations & Scope Caveats

- **Bounded HTTP Testing vs. Browser Rendering**: Live checks confirmed HTTP 200 response codes, payload transmission, and expected Content-Type headers via bounded client requests. This does not represent an end-to-end visual layout inspection or assistive technology / screen-reader accessibility audit.
- **Client 500 Defect Reproduction**: The intermittent client-side 500 error previously reported on purchase creation was not reproduced in this test environment. GET requests to `/purchases/create` and `/invoices/create` render cleanly with HTTP 200; no unsupported root causes or client conjectures are asserted.
- **Privacy & Security Guarantee**: All sensitive secrets, database passwords, session cookies, and user credentials remain strictly redacted and were never written to git tracking.
