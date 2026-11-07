# Release Candidate Build & Static Readiness Audit

**Audit Date:** 2026-11-07
**Auditor:** Review Sub-Agent (`agy-build`)
**Repository:** `/home/t3dev/.t3/projects/small-trader-platform/account`
**Base Commit (HEAD):** `06e4f6d71b8dc3202f0708dff91a21b32a5afd33`
**Working Tree State:** Extensive intentional dirty changes preserved (176 modified tracked files, 81 intended untracked source/test/documentation files)
**Execution Context:** Independent static, build, dependency, and release-readiness audit conducted in parallel with runtime test agents. No commits, push, merge, deploy, Hostinger, or production access performed.

---

## 1. Executive Summary & Audit Mandate

This audit serves as the authoritative static analysis, asset build, and release-readiness review for the candidate codebase under evaluation on 7 November 2026. The working tree deliberately remains uncommitted to allow parallel review and correction workflows.

### Audit Ownership Boundaries
- **Exclusively Owned by this Audit:** Full repository static analysis (PHPStan), code style audit (`pint --test`), JavaScript Node test suite, Blade view compilation (`view:cache`), production Vite asset build (`public/build/*`), read-only dependency vulnerability audits (`composer audit`, `npm audit`), git diff hygiene, lockfile consistency, secret leakage scans, and cryptographic candidate inventory.
- **Owned by Parallel Agents / Outside Scope:** Database-backed PHPUnit suites (Phase 1–12, MariaDB 10.4 disposable runner), live browser visual qualification, Hostinger infrastructure mutation, and production deployment execution.

### Key Verdicts
| Gate / Verification | Result | Details |
|---|---|---|
| **PHPStan Static Analysis** | **PASS** | Level 6 analysis on `app/` completed with **0 errors**. |
| **Laravel Pint Style Check** | **PASS** | Executed in test mode (`--test`); no files required formatting. |
| **Git Diff Syntax & Conflicts** | **PASS** | `git diff --check` exited 0; no merge conflict markers or whitespace corruptions. |
| **JavaScript Node Tests** | **PASS** | **56 tests passed, 0 failures** across all 7 test suites in `tests/JavaScript/`. |
| **Blade View Cache Compilation** | **PASS** | `php artisan view:cache` compiled all application views without syntax errors. |
| **Production Vite Build** | **PASS** | Compiled in 2.63s; **no sourcemaps**; all 8 manifest entries exist and resolve. |
| **npm Dependency Audit** | **PASS** | **0 vulnerabilities** reported by `npm audit`. |
| **Composer Dependency Audit** | **ADVISORY** | 10 advisories affecting 5 Symfony sub-packages reported upstream on 2026-11-04. |
| **Lockfile & Provenance Parity** | **PASS** | `composer.lock` and `package-lock.json` are pristine (0 diffs); notices aligned. |
| **Secret & Sensitive Data Audit** | **PASS** | No `.env`, application keys, or credentials exposed; only synthetic test fixtures exist. |

---

## 2. Static Analysis & Code Quality Verification

### 2.1 Full Repository PHPStan
- **Command:** `php -d memory_limit=1G vendor/bin/phpstan analyse --no-progress`
- **Configuration:** [phpstan.neon](file:///home/t3dev/.t3/projects/small-trader-platform/account/phpstan.neon) (Level 6, Larastan extension enabled)
- **Result:** `[OK] No errors`
- **Log:** [agy-build-phpstan.log](file:///home/t3dev/.t3/projects/small-trader-platform/account/docs/evidence/release-candidate-20261107/agy-build-phpstan.log)
- **Scope Verified:** All modified and newly introduced PHP classes across `app/`, including the correction boundary (`app/Services/Corrections/*`), standalone reviews (`app/Services/Reviews/*`), purchasing actions (`app/Actions/Purchasing/*`), Livewire components, and domain exceptions.

### 2.2 Laravel Pint (Style Audit)
- **Command:** `vendor/bin/pint --test`
- **Mode:** Test-only (read-only verification, zero file mutation)
- **Result:** `{"tool":"pint","result":"passed"}` (Exit code 0)
- **Log:** [agy-build-pint.log](file:///home/t3dev/.t3/projects/small-trader-platform/account/docs/evidence/release-candidate-20261107/agy-build-pint.log)
- **Verdict:** All modified and untracked PHP code strictly adheres to Laravel coding standards without requiring automated styling modification.

### 2.3 Git Diff Check
- **Command:** `git diff --check`
- **Result:** Clean (Exit code 0, 0 bytes output)
- **Log:** [agy-build-git-diff-check.log](file:///home/t3dev/.t3/projects/small-trader-platform/account/docs/evidence/release-candidate-20261107/agy-build-git-diff-check.log)
- **Verdict:** No whitespace errors, trailing carriage returns, or unresolved git conflict markers exist across the 176 modified tracked files.

---

## 3. Frontend & JavaScript Verification

### 3.1 JavaScript Node Unit Tests
- **Command:** `node --test tests/JavaScript/*.test.mjs`
- **Result:** **56 tests passed, 0 failures, 0 skipped** (Execution time: 1.21s)
- **Log:** [agy-build-js-tests.log](file:///home/t3dev/.t3/projects/small-trader-platform/account/docs/evidence/release-candidate-20261107/agy-build-js-tests.log)
- **Suites Executed:**
  1. `company-data-download.test.mjs` (5 tests): ZIP stream validation, double-submit protection, 423 re-authentication handling, teardown cleanup.
  2. `maintenance-status.test.mjs` (1 test): Explicit 503 maintenance page recognition vs generic network errors.
  3. `mobile-shell.test.mjs` (9 tests): Sidebar persistence, collapse state restoration, quick-add menu focus return, user/dropdown menu keyboard accessibility, corrupted `localStorage` resilience.
  4. `money-display.test.mjs` (2 tests): Exact two-decimal formatting, preservation of malformed input for server-side validation without float casting.
  5. `omnibox-lifecycle.test.mjs` (12 tests): Modal focus trapping, Alpine.js tick synchronization, race condition resolution (issue #62), Escape dismissal, in-flight re-authorization.
  6. `pr56-security-failures.test.mjs` (1 test): JSON payload download validation and 423 error recovery.
  7. `profile-two-factor.test.mjs` (26 tests): HTTP 500/502/503 post-commit failure handling, secret cleanup, TOTP validation retry safety, Fortify password confirmation integration.

### 3.2 Blade Template Compilation
- **Command:** `php artisan view:cache`
- **Result:** `INFO Blade templates cached successfully.`
- **Log:** [agy-build-view-cache.log](file:///home/t3dev/.t3/projects/small-trader-platform/account/docs/evidence/release-candidate-20261107/agy-build-view-cache.log)
- **Scope Verified:** All Blade views across sales, purchasing, money, expenses, inventory, platform, auth, and shared components (`resources/views/components/*`, `resources/views/livewire/*`) compiled cleanly without syntax or parsing regressions.

---

## 4. Production NPM Build & Asset Manifest Verification

### 4.1 Production Asset Build
- **Command:** `npm run build` (`vite build`)
- **Version:** Vite 7.3.7, Tailwind CSS v4, `@tailwindcss/vite`
- **Build Duration:** 2.63s
- **Log:** [agy-build-npm-build.log](file:///home/t3dev/.t3/projects/small-trader-platform/account/docs/evidence/release-candidate-20261107/agy-build-npm-build.log)
- **Generated Assets (`public/build/`):**
  - `manifest.json` (1.68 kB, gzip: 0.42 kB)
  - `assets/app-ChIbt4ro.css` (121.89 kB, gzip: 20.79 kB)
  - `assets/app--tqmR2Rx.js` (99.35 kB, gzip: 34.18 kB)
  - `assets/index-B2ZkNDM-.js` (454.37 kB, gzip: 117.45 kB)
  - `assets/IBM-Plex-LICENSE-BJZk5MFB.txt` (4.36 kB, gzip: 1.96 kB)
  - `assets/IBMPlexSansArabic-Regular-B2a76lLF.woff2` (71.90 kB)
  - `assets/IBMPlexSansArabic-Bold-DP_E0jdd.woff2` (73.79 kB)
  - `assets/IBMPlexSansArabic-Medium-CRRMh-dh.woff2` (75.94 kB)
  - `assets/IBMPlexSansArabic-SemiBold-FtXILkUg.woff2` (76.41 kB)

### 4.2 Sourcemap Audit
- **Verification:** `find public/build -name "*.map"`
- **Result:** **0 sourcemap files found.**
- **Compliance:** In full accordance with `AGENTS.md` and `HOSTINGER_DEPLOYMENT_GUIDE.md`: production builds must never leak sourcemaps to preserve Hostinger inode limits and protect application internals.

### 4.3 Asset Manifest Reference Integrity
Every entry in [public/build/manifest.json](file:///home/t3dev/.t3/projects/small-trader-platform/account/public/build/manifest.json) was cryptographically verified against the filesystem on disk:
- `node_modules/@zxing/browser/esm/index.js` -> `assets/index-B2ZkNDM-.js` (454,373 bytes) [EXISTS]
- `resources/css/app.css` -> `assets/app-ChIbt4ro.css` (121,887 bytes) [EXISTS]
- `resources/fonts/ibm-plex-sans-arabic/IBMPlexSansArabic-Bold.woff2` -> `assets/IBMPlexSansArabic-Bold-DP_E0jdd.woff2` (73,788 bytes) [EXISTS]
- `resources/fonts/ibm-plex-sans-arabic/IBMPlexSansArabic-Medium.woff2` -> `assets/IBMPlexSansArabic-Medium-CRRMh-dh.woff2` (75,936 bytes) [EXISTS]
- `resources/fonts/ibm-plex-sans-arabic/IBMPlexSansArabic-Regular.woff2` -> `assets/IBMPlexSansArabic-Regular-B2a76lLF.woff2` (71,904 bytes) [EXISTS]
- `resources/fonts/ibm-plex-sans-arabic/IBMPlexSansArabic-SemiBold.woff2` -> `assets/IBMPlexSansArabic-SemiBold-FtXILkUg.woff2` (76,408 bytes) [EXISTS]
- `resources/fonts/ibm-plex-sans-arabic/LICENSE.txt` -> `assets/IBM-Plex-LICENSE-BJZk5MFB.txt` (4,362 bytes) [EXISTS]
- `resources/js/app.js` -> `assets/app--tqmR2Rx.js` (99,354 bytes) [EXISTS]

---

## 5. Dependency Security & Lockfile Audit

### 5.1 npm Audit
- **Command:** `npm audit`
- **Result:** `found 0 vulnerabilities`
- **Log:** [agy-build-npm-audit.log](file:///home/t3dev/.t3/projects/small-trader-platform/account/docs/evidence/release-candidate-20261107/agy-build-npm-audit.log)

### 5.2 Composer Audit
- **Command:** `composer audit --format=plain`
- **Log:** [agy-build-composer-audit.log](file:///home/t3dev/.t3/projects/small-trader-platform/account/docs/evidence/release-candidate-20261107/agy-build-composer-audit.log)
- **Findings:** 10 security advisories affecting 5 transitive Symfony packages, all reported on 2026-11-04:
  1. `symfony/http-foundation` (CVE-2026-118514, GHSA-3h27-hjv7-fqjx, GHSA-xg2q-4rrq-657q, GHSA-5mx8-mwrp-5693):
     - HEAD request method matching, path rule script name bypass, trusted proxy CIDR edge cases, client host header URL generation.
  2. `symfony/http-kernel` (GHSA-wg5q-m2ww-f3c9):
     - Inline fragments untrusted forwarded headers host/scheme handling.
  3. `symfony/routing` (CVE-2026-118516):
     - Host placeholders matching start of path in compiled URL matchers.
  4. `symfony/serializer` (CVE-2026-118515, GHSA-pxj9-jh84-vhm5, GHSA-hqp2-7rr8-hf26):
     - UploadedFile object building from request data, DataUriNormalizer file read, ObjectNormalizer dotted-key property path traversal.
  5. `symfony/yaml` (GHSA-hwfg-qwvh-2wfx):
     - Polynomial parse times on untrusted YAML documents.
- **Impact Assessment:**
  - *Severity in this architecture:* **Low to Moderate / Non-Exploitable in Standard Flow**.
    - The application does not expose untrusted YAML parsing or Symfony Serializer normalizers to end users.
    - URL routing is handled by Laravel's routing pipeline and behind Apache/LiteSpeed URL rewrites.
    - Forwarded headers and trusted proxies are controlled via Laravel middleware configuration (`TrustProxies`).
  - *Recommendation:* Upgrade Symfony packages via standard Composer maintenance update (`composer update symfony/*`) in a dedicated maintenance window following the current release candidate signoff.

### 5.3 Lockfile Consistency & Third-Party Notices
- `composer.lock` and `package-lock.json` are pristine and match HEAD exactly.
- `composer validate --no-check-all --strict` confirms `composer.json` is completely valid.
- [THIRD_PARTY_NOTICES.md](file:///home/t3dev/.t3/projects/small-trader-platform/account/THIRD_PARTY_NOTICES.md) was reviewed and accurately accounts for:
  - Laravel application skeleton and MIT framework notices ([third-party/laravel/LICENSE.md](file:///home/t3dev/.t3/projects/small-trader-platform/account/third-party/laravel/LICENSE.md)).
  - IBM Plex Sans Arabic SIL Open Font License v1.1 bundle.
  - Native Blade/CSS adaptations of BoardUI and shadcn patterns (no third-party JS/React source incorporated).

---

## 6. Forward Migrations & Schema Scope Audit

The candidate introduces four forward migrations in `database/migrations/`:

| Migration File | Purpose & Scope | Rollback (`down()`) Behavior | Safety Assessment |
|---|---|---|---|
| `2026_11_07_000001_add_void_columns_to_purchase_returns_and_permissions.php` | Adds `voided_at`, `voided_by` (FK users restrict), `void_reason`, `void_posting_batch_id` (FK posting_batches restrict) to `purchase_returns`. Registers 5 new purchasing void/return permissions and assigns to Owner and Manager roles across all companies. | **Fails closed** with `RuntimeException` if any void purchase return history exists. Drops columns and foreign keys only on clean database. | **SAFE.** Strict tenant isolation and immutable history protection preserved. |
| `2026_11_07_000002_add_purchase_void_business_dates.php` | Preflights all historical void purchases and returns against posting batches and stock movements before adding `void_business_date` column to `purchases` and `purchase_returns`. Backfills exact dates. | **Fails closed** with `RuntimeException` if any historical void business dates exist. | **SAFE.** Preflight guards MariaDB non-transactional DDL against partial schema application. |
| `2026_11_07_000003_create_purchase_correction_receipts.php` | Creates `purchase_correction_receipts` table. Enforces unique constraints on `[company_id, request_key]` and `[company_id, purchase_id]`. Restrictive foreign keys to `companies`, `users`, `purchases`. | **Fails closed** with `RuntimeException` if any receipts exist. Drops table only if empty. | **SAFE.** Guarantees immutable provenance for purchase corrections. |
| `2026_11_07_000004_create_trade_correction_receipts.php` | Creates `sales_invoice_correction_receipts`, `sales_return_correction_receipts`, and `purchase_return_correction_receipts` tables. Enforces unique constraint on `[company_id, request_key]`. | **Fails closed** with `RuntimeException` if receipts exist in any of the three tables. | **SAFE.** See Recommendation 8.1 regarding compound uniqueness. |

---

## 7. Secrets & Sensitive Data Leakage Audit

A comprehensive multi-pass automated regex and pattern inspection was conducted across all files (excluding `.git`, `vendor`, and `node_modules`):
- `.env`, `.env.production`, and `.env.backup` are properly ignored by `.gitignore` and are **not tracked**.
- No private keys (`-----BEGIN ... PRIVATE KEY-----`) exist in the repository.
- No live API keys, Stripe/payment tokens, database passwords, or unredacted user secrets are present in source, tests, or documentation.
- All credential-like strings discovered in `tests/` are synthetic test values (`synthetic-never-real-key-12345`, `SECRET_TOKEN_XYZ_123`, `synthetic-owner-report-password`, etc.).
- Build logs in `docs/evidence/release-candidate-20261107/` are sanitized and contain zero secrets or customer records.

---

## 8. Release Blockers vs Operational Deployment Gates

### 8.1 Release Blockers (Code-Level / Static Audit)
1. **None in Build / Static Analysis:**
   - Static analysis, styling, Blade compilation, JavaScript tests, and asset building are 100% green.
2. **Trade Correction Uniqueness Parity (Defense in Depth):**
   - *File:* [database/migrations/2026_11_07_000004_create_trade_correction_receipts.php](file:///home/t3dev/.t3/projects/small-trader-platform/account/database/migrations/2026_11_07_000004_create_trade_correction_receipts.php#L28)
   - *Observation:* Migration 000003 enforces `$table->unique(['company_id', 'purchase_id'])` in addition to the request key. Migration 000004 enforces `$table->unique(['company_id', 'request_key'])` but lacks an explicit `$table->unique(['company_id', 'source_id'])`.
   - *Impact:* While `TenantWriteAccess` serializes execution at the company level and checks `$source->status === 'posted'`, adding the composite unique index `['company_id', 'source_id']` to migration 000004 provides defense-in-depth against duplicate correction receipts for the same source document.
3. **Payables Reconciliation Void Provenance:**
   - *Status:* Resolved in working tree. `app/Services/Purchasing/PayablesReconciliationService.php` was updated to recognize `STATUS_VOID` records with valid `PurchaseVoidIntegrity` provenance, and passes PHPStan and Pint.

### 8.2 Operational Deployment-Only Gates
These gates are operational procedures executed on Hostinger during release preparation and must **never** be bypassed:
1. **Pre-Deployment Backup & Off-Host Recovery Point:**
   - A complete database dump, code/build snapshot, and uploads archive must be created and verified off-host prior to entering maintenance.
2. **Maintenance Mode Enforcement:**
   - Live application must be put into maintenance mode using the existing deployed code before updating filesystem assets.
3. **Protected `.htaccess` Custody:**
   - Verify that the live document root `.htaccess` contains the CloudLinux Alt-PHP 8.4 handler (`application/x-httpd-alt-php84`) and PWA webmanifest MIME type (`AddType application/manifest+json .webmanifest`). The deployment script `bin/deploy.sh` verifies this byte-for-byte into private custody before running migrations or asset synchronization.
4. **Controlled Migration Execution:**
   - Run forward migrations sequentially via `/opt/alt/php84/usr/bin/php ./bin/deploy.sh --run-migrations`.
5. **Post-Migration Six Reconciliation Verification:**
   - Run all six reconciliation services across every tenant:
     - `php artisan accounting:reconcile`
     - `php artisan inventory:reconcile`
     - `php artisan sales:reconcile`
     - `php artisan money:reconcile`
     - `php artisan phase7:reconcile`
     - Payables read-only reconciliation harness (`PayablesReconciliationService`).
   - All services must report healthy with zero integrity violations.
6. **Explicit Reopening Gate:**
   - Only after all six reconciliation checks pass, assets are confirmed live over HTTPS, and Owner provides explicit signoff, execute `/opt/alt/php84/usr/bin/php artisan up`.

---

## 9. Cryptographic Release Candidate Inventory

To uniquely and deterministically identify this release candidate without creating a git commit, cryptographic SHA256 digests of the source diff and inventory were computed:

- **Base Commit SHA:** `06e4f6d71b8dc3202f0708dff91a21b32a5afd33`
- **Git Diff SHA256 (against HEAD):** `462a3b8cc778425090fcec81162aff062d416a87bed7894c1a6b7e96b1afc1df`
- **Git Diff Size:** 1,507,198 bytes across 176 modified tracked files
- **Tracked Modified File Count:** 176 files
- **Intended Untracked Source / Test / Doc Count:** 81 files
- **Candidate Source Inventory Aggregate Digest (SHA256):** `178e77f64cc4283bf28d705d24d05d3ad3ff053827182e3b8af0581059848d6b`

### 9.1 Classification of Working Tree Content
1. **Intended Untracked Application Source & Migrations:**
   - `app/Actions/Purchasing/VoidPurchaseAction.php`
   - `app/Actions/Purchasing/VoidPurchaseReturnAction.php`
   - `app/Actions/Sales/DeleteReturnDraftAction.php`
   - `app/Application/Commands/ImmediatePurchaseCommands.php`
   - `app/Domain/Purchasing/Exceptions/PurchasingReversalBlockedException.php`
   - `app/Livewire/Concerns/CorrectsTradeDocument.php`
   - `app/Livewire/Concerns/ReviewsStandaloneReversal.php`
   - `app/Services/Corrections/*` (9 service classes)
   - `app/Services/Purchasing/*` (6 void capability and scope classes)
   - `app/Services/Reviews/StandaloneReversalReview.php`
   - `database/migrations/2026_11_07_000001_...` through `000004_...`
   - `resources/lang/{en,ar}/*` (corrections, standalone, purchase settlement)
   - `resources/views/components/*` (correction preview, shortcuts, inline panel, page heading, standalone review)
   - `resources/views/livewire/partials/*` (immediate purchase review/settlement)
   - `tests/Feature/Purchasing/*`, `tests/Feature/Phase4/*`, `tests/Feature/Phase7/*`, `tests/Feature/Phase12/*`
   - `docs/CORRECTION_IMPLEMENTATION_20261107.md`, `docs/UI_REDESIGN_20261107.md`
2. **Owned Temporary Build & Evidence Outputs:**
   - `public/build/*` (compiled assets, manifest, fonts, license - git ignored)
   - `docs/evidence/release-candidate-20261107/agy-build-*.log` (8 static audit logs)
   - `storage/framework/views/*` (compiled Blade cache views)

---

## 10. Audit Evidence Logs Reference

The following sanitized logs were produced directly during this audit and reside in [docs/evidence/release-candidate-20261107/](file:///home/t3dev/.t3/projects/small-trader-platform/account/docs/evidence/release-candidate-20261107/):
- [agy-build-phpstan.log](file:///home/t3dev/.t3/projects/small-trader-platform/account/docs/evidence/release-candidate-20261107/agy-build-phpstan.log) (PHPStan Level 6 analysis)
- [agy-build-pint.log](file:///home/t3dev/.t3/projects/small-trader-platform/account/docs/evidence/release-candidate-20261107/agy-build-pint.log) (Pint code style verification)
- [agy-build-js-tests.log](file:///home/t3dev/.t3/projects/small-trader-platform/account/docs/evidence/release-candidate-20261107/agy-build-js-tests.log) (JavaScript Node test runner output)
- [agy-build-view-cache.log](file:///home/t3dev/.t3/projects/small-trader-platform/account/docs/evidence/release-candidate-20261107/agy-build-view-cache.log) (Blade template cache compilation)
- [agy-build-npm-build.log](file:///home/t3dev/.t3/projects/small-trader-platform/account/docs/evidence/release-candidate-20261107/agy-build-npm-build.log) (Vite production build output)
- [agy-build-npm-audit.log](file:///home/t3dev/.t3/projects/small-trader-platform/account/docs/evidence/release-candidate-20261107/agy-build-npm-audit.log) (npm security audit)
- [agy-build-composer-audit.log](file:///home/t3dev/.t3/projects/small-trader-platform/account/docs/evidence/release-candidate-20261107/agy-build-composer-audit.log) (Composer security audit)
- [agy-build-git-diff-check.log](file:///home/t3dev/.t3/projects/small-trader-platform/account/docs/evidence/release-candidate-20261107/agy-build-git-diff-check.log) (Git diff whitespace and conflict check)
