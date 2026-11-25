# Small Trader Accounting — Setup & Operations Guide

## 1. System Requirements & Runtime Environment

This project is built for standard shared-hosting (e.g. Hostinger cPanel / Apache) and local development without requiring background daemons, Docker, WebSockets, or Redis.

| Component | Required Version | Supported / Tested | Notes |
|---|---|---|---|
| **PHP** | `^8.4` | PHP 8.4.25+ | Required extensions: `pdo_mysql`, `bcmath`, `mbstring`, `intl`, `gd`, `zip`, `xml`, `curl` |
| **Database** | MariaDB `10.4+` or MySQL `8.0+` | MariaDB 10.4.32 | Case-sensitivity, transactional isolation, and exact decimal arithmetic authoritative |
| **Composer** | `2.x` | Composer 2.8+ | Package dependency locking |
| **Node.js** | `^20.19.0` or `>=22.12.0` | Node 20.19+, 22.12+ | Strictly required by Vite 7.3.7 (`engines.node`); not required for runtime serving |
| **Web Server** | Apache 2.4+ or Nginx | Apache with mod_rewrite | Shared hosting compatible (`.htaccess` provided in public docroot) |

---

## 2. Fresh Clone Installation

Clone the repository to your local working directory from the deployed origin:

```bash
git clone https://github.com/Bassamalsaqqa/account.git
cd account
```

> [!NOTE]
> The canonical deployed repository is `https://github.com/Bassamalsaqqa/account.git`. (The sibling repository `https://github.com/Bassamalsaqqa/accounting.git` is a private visual reference and must not be used as the clone origin).

### Step 2.1 — Install PHP & Node Dependencies

Install dependencies strictly matching lockfiles without silently modifying locks:

```bash
# POSIX / Bash
composer install --no-interaction --prefer-dist --optimize-autoloader
npm ci

# Windows PowerShell
composer install --no-interaction --prefer-dist --optimize-autoloader
npm ci
```

### Step 2.2 — Configure Local Environment

Copy the default environment configuration:

```bash
# POSIX / Bash
cp .env.example .env
php artisan key:generate

# Windows PowerShell
Copy-Item .env.example .env
php artisan key:generate
```

Configure your local database connection and defaults in `.env`:

```ini
# Core Application Environment Defaults
APP_ENV=local
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000
SUBSCRIPTION_ENFORCEMENT_ENABLED=false
AI_ASSISTANT_ENABLED=false
AI_PROVIDER_APPROVED=false
AI_TURN_ENABLED=false

# Local Database Configuration: create this NEW empty database first;
# use a dedicated local identity with privileges on this database only.
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=accounting_dev
DB_USERNAME=accounting_dev
DB_PASSWORD=<your-local-database-password>
```

> [!IMPORTANT]
> Never run destructive commands (`migrate:fresh`, `migrate:refresh`, `db:wipe`) against any existing or persistent database. Always create a dedicated, empty local database (e.g. `accounting_dev`).

### Step 2.3 — Run Forward Migrations

Execute forward migrations sequentially:

```bash
php artisan migrate --force
```

### Step 2.4 — Initial User & Company Provisioning (CLI Bootstrap)

Public self-registration is disabled by default (`REGISTRATION_ENABLED=false`). Provision initial accounts using authorized Artisan commands:

```bash
# 1. Create an initial login user (prompts securely for password, minimum 8 characters)
php artisan app:create-user --name="Admin User" --email="admin@example.com" --locale=ar

# 2. Bootstrap an initial company workspace assigned to that user
php artisan app:bootstrap-company --email="admin@example.com" --name-ar="شركة التجارة النموذجية" --name-en="Sample Trading Co" --base-currency=ILS --timezone=Asia/Hebron

# 3. (NEW ISOLATED LOCAL INSTANCE ONLY) Grant platform operator permissions for local control-plane development
php artisan platform:operator admin@example.com
```

> [!CAUTION]
> `php artisan platform:operator` grants full platform-operator authority across tenant workspaces. It is strictly for **new isolated local development environments only**. Never execute this command to expand operator grants on existing persistent staging or production instances without formal Owner authorization.

### Step 2.5 — Build Production Frontend Assets

Compile Tailwind CSS 4 and Vite bundle:

```bash
npm run build
```

This compiles client bundles with zero source maps and generates `public/build/manifest.json`.

---

## 3. Local Development Server & Script Audit

### Development Workflow

Run the development server using relative commands:

```bash
# Terminal 1: Serve local application
php artisan serve --port=8000

# Terminal 2: Run Vite dev watcher (optional for live HMR)
npm run dev
```

Visit `http://127.0.0.1:8000` in your browser.

### Composer Scripts Audit & Warning

The repository `composer.json` defines convenience scripts:

- `composer setup`: Runs `composer install`, copies `.env`, generates app key, runs `php artisan migrate --force`, and runs `npm install` + `npm run build`.
  > [!WARNING]
  > Do **NOT** run `composer setup` blindly on an existing installation or with existing data. It runs forward migrations against whichever database is defined in `.env` and uses `npm install` instead of `npm ci`. For fresh, controlled setups, follow Steps 2.1–2.5 above.
- `composer dev`: Concurrently runs `artisan serve`, `queue:listen`, `pail`, and `npm run dev`. Useful for active local development when tools like `npx concurrently` are installed.
- `composer qa`: Runs `@pint --test`, `@phpstan`, and `@test`. (Note: `@test` invokes PHPUnit against the database configured in `phpunit.xml` or `.env`. For isolated disposable test execution, see Section 4 below).

---

## 4. Canonical Verification & Testing

Unit, feature, and integration tests MUST be executed against an isolated disposable MariaDB instance to preserve persistent databases and verify MySQL/MariaDB decimal semantics.

### Step 4.1 — Running Verified PHP Feature Tests

Use the tracked `tests/Support/run-phase8-disposable.php` runner. It creates and migrates a temporary schema on an explicitly configured **local test-only MariaDB server**, applies isolated PHPUnit configuration, and drops its owned schema afterward. Provision that test-only server first; never point it at the application database, production credentials, or the persistent preview database.

Run `npm ci` and `npm run build` in the checkout before testing routes that render the application layout. These tests use the generated `public/build/manifest.json`. For release qualification, retain a separate JUnit report per test batch so a later timeout leaves completed results available.

Required environment configuration for the test runner:
- `APP_ENV=testing`
- `PHASE8_ALLOW_DISPOSABLE_DB=1`
- `PHASE8_TEST_DB_HOST=127.0.0.1` (loopback only)
- `PHASE8_TEST_DB_PORT=3306` (or dedicated test port, e.g. 3342; NEVER the persistent preview port 3353)
- `PHASE8_TEST_DB_USERNAME=test_runner` (dedicated test user; requires schema privileges listed below)
- `PHASE8_TEST_DB_PASSWORD=` (if password is intentionally empty, set `PHASE8_TEST_DB_EMPTY_PASSWORD=1`)
- `PHASE8_TEST_TIMEOUT=900` (optional configurable timeout in seconds; strictly positive integer up to 14400; default 900s for focused runs)

> [!IMPORTANT]
> The test database user requires `CREATE`, `DROP`, `ALTER`, `INDEX`, `INSERT`, `SELECT`, `UPDATE`, `DELETE`, and `TRIGGER` privileges to create, migrate, and drop temporary schemas matching `accounting_p8_tmp_%`. Never point the test runner at a production database or the persistent development preview database (port 3353).

```bash
# POSIX / Bash — focused test target
APP_ENV=testing PHASE8_ALLOW_DISPOSABLE_DB=1 PHASE8_TEST_DB_HOST=127.0.0.1 PHASE8_TEST_DB_PORT=3306 PHASE8_TEST_DB_USERNAME=test_runner PHASE8_TEST_DB_EMPTY_PASSWORD=1 \
php tests/Support/run-phase8-disposable.php -- tests/Feature/Phase1/CompanySettingsLivewireTest.php

# Windows PowerShell — focused test target
$env:APP_ENV="testing"
$env:PHASE8_ALLOW_DISPOSABLE_DB="1"
$env:PHASE8_TEST_DB_HOST="127.0.0.1"
$env:PHASE8_TEST_DB_PORT="3306"
$env:PHASE8_TEST_DB_USERNAME="test_runner"
$env:PHASE8_TEST_DB_EMPTY_PASSWORD="1"
php tests/Support/run-phase8-disposable.php -- tests/Feature/Phase1/CompanySettingsLivewireTest.php
```

### Test Execution Budget & Configurable Timeout

- **Focused Feature Tests**: The default 900-second timeout applies to focused test targets.
- **Complete Suite Execution**: Runtime depends on the host and the selected tests. A single full run can exceed the runner's four-hour limit. If running the suite in one invocation, set an explicit timeout budget:

```bash
# POSIX: keep the dedicated test-server settings from Step 4.1
PHASE8_TEST_TIMEOUT=14400 php tests/Support/run-phase8-disposable.php -- tests

# PowerShell: keep the dedicated test-server settings from Step 4.1
$env:PHASE8_TEST_TIMEOUT="14400"
php tests/Support/run-phase8-disposable.php -- tests
```

To compare a synthetic upgrade from a recorded deployment commit with a fresh candidate schema, use the same disposable MariaDB settings and a clean candidate checkout. Supply the full reviewed Git SHA for the deployed baseline.

```bash
php tests/Support/rehearse-disposable-migration-upgrade.php "$baseline_sha"
```

The runner requires an additive migration history, compares the applied migration names and table definitions, and drops its two owned schemas. A populated recovery rehearsal remains a separate release gate.

#### Mobile authority migration preflight

Before any release DDL, rehearse `2026_11_25_100002_track_mobile_authority_and_offline_policy_revocation` on a disposable, deployment-equivalent database with the same engine/version, binlog settings, SQL mode, effective migration grants and runtime grants. Use a synthetic baseline with a mobile bearer, an issued lease and a committed receipt, and the same account relationship between the migrator, definer and runtime as the planned deployment.

Record the following read-only evidence for the planned deployment connection under the approved release procedure. Compare it with the disposable rehearsal before running `artisan migrate`.

```sql
SELECT VERSION(), CURRENT_USER(), CURRENT_ROLE(), @@global.log_bin,
       @@global.log_bin_trust_function_creators, @@session.binlog_format,
       @@session.sql_mode;
SHOW GRANTS;
```

- Verify that the migration account already has the required ALTER and data privileges and `TRIGGER` on both `users` and `company_mobile_settings`. Verify actual trigger creation and metadata visibility on the disposable database under the matched grants. Binlog settings can require elevated privileges even with the table-level grant. A root-only or binlog-disabled rehearsal does not qualify a more restricted deployment. [MariaDB trigger privileges](https://mariadb.com/docs/server/server-usage/triggers-events/triggers/create-trigger) [MariaDB binlog rules](https://mariadb.com/docs/server/server-usage/stored-routines/binary-logging-of-stored-routines)
- The migration connection's `CURRENT_USER()` becomes the definer. Use the same account for retries and verify that it will remain present with the effective privileges needed when triggers fire, including changing `users.mobile_authority_revision` and reading/updating the lease rows. Exercise a credential change and mobile-settings update and delete using the deployment-equivalent application account. Inspect the three definitions with `SHOW CREATE TRIGGER`. If privileges, binlog policy, definer lifetime or the rehearsal cannot be verified, hold migrations before any DDL. This procedure does not grant privileges or alter server settings.
- Keep maintenance held and quiesce legacy writers and mobile clients during the migration and any retry. MariaDB commits each DDL step independently. After an interruption, rerun the same forward migration under the same definer. A mismatched definition or unexpected step order requires operator review with maintenance held. Verify that Artisan recorded the migration and the three trigger definitions match before reopening traffic. Mobile authentication fails closed until the migration is recorded.
- The migration retires legacy bearers and leases and preserves committed receipts and their original event bytes. Staff can sign in again on the same device and look up a committed receipt with current permissions. Use a forward correction once the migration has recorded authority changes or issued lease history.

`MOBILE_V2_OFFLINE_SALES_ENABLED=false` pauses offline intake. With Mobile V2 and its cash slice available, committed receipt status remains accessible. Disabling a company's offline policy retires its leases in the same transaction. Changing the approved cash routing or default tax selection, toggling availability of the approved cash account, or changing the configured tax rate, calculation, availability or output ledger also retires the company's leases. Restoring the original setup requires a fresh lease.

For a clean MariaDB 10.4 rehearsal, start the disposable server with `--explicit-defaults-for-timestamp=ON`. With the older server default, the existing strict-mode mobile migration can reject `mobile_auth_challenges.expires_at` with error 1067. Record the deployment server's value with `SHOW GLOBAL VARIABLES LIKE 'explicit_defaults_for_timestamp'` when comparing environments. [MariaDB system variable reference](https://mariadb.com/docs/server/server-management/variables-and-modes/server-system-variables#explicit_defaults_for_timestamp)

### Windows Environment & Long-Run Guidelines

1. **Awake Requirement**: Prevent Windows system sleep during test runs; system sleep pauses process clocks and causes false timeout errors in concurrent or time-sensitive test cases.
2. **Symlink Warnings**: Windows may lack symlink privileges. Record the actual warnings/skips and which downstream assertions did not execute; this is not proof of a Linux pass. Verify Linux behavior separately when deployment scripts change.

### Step 4.2 — Code Quality & Static Analysis

```bash
# 1. Code Style Check (Laravel Pint)
vendor/bin/pint --test

# 2. Static Analysis (Larastan / configured PHPStan level 6)
vendor/bin/phpstan analyse --no-progress

# 3. Frontend & JavaScript Unit Tests
node --test tests/Frontend/*.test.js tests/Frontend/*.test.mjs tests/JavaScript/*.test.mjs

# 4. Dependency Security Audits
composer audit
npm audit
```

---

## 5. Backup, Restoration and Disaster Recovery Runbook

The authoritative disaster recovery specification and fail-closed rehearsal procedure is defined in [**docs/PHASE_10_BACKUP_RESTORE_RUNBOOK.md**](PHASE_10_BACKUP_RESTORE_RUNBOOK.md).

### Operational Principles & Strict Isolation Controls

1. **Off-Host Backup Requirement**: Coherent backups containing transaction-consistent MariaDB SQL dumps, encrypted issued documents, and private uploads are an operational requirement. They must reside in independent off-host storage surviving primary hosting loss or account compromise. (Note: automated off-host backup schedules require separate operational verification; do not claim automation exists without evidence).
2. **Never Destructive On Persistent Data**: Never run `migrate:fresh`, `db:wipe`, or schema rollback against persistent data. Recovery strictly uses forward migrations.
3. **Fail-Closed Rehearsal Sequence**:
   - Establish an isolated scratch environment with network ingress and egress blocked (no outbound HTTP/SMTP/AI provider calls, no scheduled cron jobs, and rehearsal-only queue connection with `driver => null`).
   - Provision a dedicated disposable database on numeric loopback only; verify target schema emptiness before import.
   - Separate external key custody: inject `APP_KEY` and `APP_PREVIOUS_KEYS` securely without extracting them into plain shell history or logs.
   - Quarantine any archived `.env` or stale bootstrap caches (`bootstrap/cache/*`).
   - Validate pre/post 10 primary table fingerprints (verifying zero row count leaks or unauthorized data mutation).
   - Execute forward migrations (`php artisan migrate --force`).
   - Run the six read-only domain reconciliations across every restored company:
     - `php artisan accounting:reconcile`
     - `php artisan inventory:reconcile`
     - `php artisan sales:reconcile`
     - `php artisan money:reconcile`
     - `php artisan phase7:reconcile`
     - Payables reconciliation via `PayablesReconciliationService` using a reviewed isolated harness (Payables has no direct CLI command; do not invent a nonexistent CLI command).
   - Teardown: revoke temporary DB identity, drop the disposable schema, destroy temporary scratch keys, and clean up isolated processes.

All reconciliations must return zero anomalies (`0 failed`, consistent checksums).

---

## 6. Private Data & Credentials Separation Protocol

The public GitHub repository contains application source code and migrations only. It **never** contains live production data, company attachments, private keys, or credentials.

### Private Artifacts Manifest (Transferred via Secure Encrypted Out-of-Band Channel Only)

| Artifact | Source Location | Destination / Purpose | Sensitivity |
|---|---|---|---|
| **Production `.env`** | Private off-host vault | Production root `.env` | Critical (`APP_KEY`, DB credentials, mail secrets) |
| **Key History** | Private off-host vault | `APP_PREVIOUS_KEYS` in `.env` | Critical (required to decrypt historical issued documents) |
| **Tenant Uploads** | Backup archive | `storage/app/private/companies/*/` | High (customer tax documents, vendor receipts, scans) |
| **Database Dumps** | Encrypted off-host backup | Disposable recovery or MariaDB server | High (complete commercial and accounting ledger) |
| **Workstation Archives** | Local `.release-b1-local/` | Private archive custody | Potentially critical: may include confidential patches, files, logs or keys; inspect and transfer securely, never commit |

---

## 7. Production Deployment Overview

Production releases follow the verified Hostinger release procedure documented in `docs/HOSTINGER_DEPLOYMENT_GUIDE.md`:
- **Directory Layout**:
  - Private application source: `/home/u556956644/domains/palsync.net/accounting`
  - Public document root: `/home/u556956644/domains/palsync.net/public_html/account`
  - PHP Binary: `/opt/alt/php84/usr/bin/php`
- **File Permissions**: Directories `0755`, files `0644`, never `777`.
- **Atomic Build Replacement**: Complete manifest-verified build directories are staged and replaced atomically; hashed chunks are never overlaid.
- **Zero Source Maps**: Production builds exclude all source maps (`.map`).
- **Off-Host Recovery**: Verified off-host backup completed before and after release.
- **Deploy Script**: Triggered via `bin/deploy.sh` following `docs/CONTROLLED_RELEASE_CHECKLIST.md`.
