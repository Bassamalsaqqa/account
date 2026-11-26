# Small Trader Accounting — Setup & Operations Guide

Single authoritative procedure for fresh installation, CLI provisioning, disposable MariaDB testing, release qualification, and operational maintenance.

## 1. System Requirements

- **PHP:** `^8.4` with extensions `bcmath`, `curl`, `fileinfo`, `gd`, `intl`, `mbstring`, `openssl`, `pdo_mysql`, `tokenizer`, `xml`, `zip`.
- **Database:** MariaDB `10.4+` or MySQL `8.0+` (authoritative for decimal arithmetic, locking, and constraints).
- **Tooling:** Composer `2.x`; Node.js `^20.19.0 || >=22.12.0` (Vite 7 asset build only; zero Node runtime daemons, Redis, Docker, or Supervisor).

## 2. Fresh Local Setup

```bash
git clone https://github.com/Bassamalsaqqa/account.git
cd account
composer install --no-interaction --prefer-dist --optimize-autoloader
npm ci
cp .env.example .env
php artisan key:generate
```

Configure a **new empty** local database in `.env` (`APP_ENV=local`, `APP_DEBUG=true`, `SUBSCRIPTION_ENFORCEMENT_ENABLED=false`, `AI_ASSISTANT_ENABLED=false`, `MOBILE_API_ENABLED=false`, `DB_CONNECTION=mysql`, `DB_HOST=127.0.0.1`), then migrate, build assets, and bootstrap an initial workspace:

```bash
php artisan migrate --force
npm run build
php artisan app:create-user --name="Admin User" --email="admin@example.com" --locale=ar
php artisan app:bootstrap-company --email="admin@example.com" --name-ar="شركة التجارة النموذجية" --name-en="Sample Trading Co" --base-currency=ILS --timezone=Asia/Hebron
```

- **Local dev server:** `php artisan serve --port=8000` (optional `npm run dev` or `composer dev`).
- **Platform operator (new isolated local dev ONLY):** `php artisan platform:operator admin@example.com` (grants full cross-tenant control-plane authority; never run on staging/production without Owner authorization).
- **Never run `composer setup` on existing data** (uses `npm install` and runs `migrate --force` against `.env`).
- **Never run `migrate:fresh`, `migrate:refresh`, `db:wipe`, or destructive reseeding** against persistent data.

## 3. Testing & Release Qualification

Build frontend assets (`npm run build`) before running feature tests that render layouts. All DB tests MUST target an isolated **test-only loopback MariaDB** server (never production or any persistent application database). The test DB user requires `CREATE`, `DROP`, `ALTER`, `INDEX`, `INSERT`, `SELECT`, `UPDATE`, `DELETE`, and `TRIGGER` grants for temporary schemas (`accounting_p8_tmp_%`).

```bash
# Style, static analysis, JS unit tests, and security audits (no DB)
vendor/bin/pint --test
vendor/bin/phpstan analyse --no-progress
node --test tests/Frontend/*.test.js tests/Frontend/*.test.mjs tests/JavaScript/*.test.mjs
composer audit && npm audit

# Standalone recovery preflight test suite (no DB / no Laravel boot)
php tests/Deployment/recovery-preflight.php

# Focused PHPUnit on disposable MariaDB (default timeout 900s; set PHASE8_TEST_TIMEOUT up to 14400)
APP_ENV=testing PHASE8_ALLOW_DISPOSABLE_DB=1 PHASE8_TEST_DB_HOST=127.0.0.1 \
PHASE8_TEST_DB_PORT=3306 PHASE8_TEST_DB_USERNAME=test_runner PHASE8_TEST_DB_EMPTY_PASSWORD=1 \
php tests/Support/run-phase8-disposable.php -- tests/Feature/Phase1/CompanySettingsLivewireTest.php

# Synthetic upgrade comparison against a reviewed prior commit SHA
php tests/Support/rehearse-disposable-migration-upgrade.php "$baseline_sha"
```

### Phase G Local Qualification Runner (`tools/release/qualify`)

Executes disjoint batches against disposable MariaDB, checks clean Git state and lockfiles, and writes isolated JUnit/logs outside the repository:

```bash
php tools/release/qualify --preflight-only --expected-commit=$(git rev-parse HEAD)
php tools/release/qualify --static-only
php tools/release/qualify --list-batches

APP_ENV=testing PHASE8_ALLOW_DISPOSABLE_DB=1 PHASE8_TEST_DB_HOST=127.0.0.1 \
PHASE8_TEST_DB_PORT=3306 PHASE8_TEST_DB_USERNAME=test_runner PHASE8_TEST_DB_EMPTY_PASSWORD=1 \
php tools/release/qualify --expected-commit=$(git rev-parse HEAD) --batch-size=25
```

- **Windows notes:** Disable OS sleep during long runs; Windows symlink warnings/skips in `DeploymentScriptContractTest` do not certify Linux symlink behavior.

#### Mobile authority migration preflight

Before running `2026_11_25_100002_track_mobile_authority_and_offline_policy_revocation` on a release DB, rehearse it on a disposable DB matching server engine, `log_bin`, `sql_mode`, migration grants (`ALTER` + `TRIGGER` on `users` and `company_mobile_settings`), and runtime grants using a seeded bearer, lease, and committed receipt:

```sql
SELECT VERSION(), CURRENT_USER(), CURRENT_ROLE(), @@global.log_bin,
       @@global.log_bin_trust_function_creators, @@session.binlog_format,
       @@session.sql_mode;
SHOW GRANTS;
SHOW GLOBAL VARIABLES LIKE 'explicit_defaults_for_timestamp';
```

- MariaDB 10.4 strict-mode rehearsals require `--explicit-defaults-for-timestamp=ON` (prevents error 1067 on `mobile_auth_challenges.expires_at`).
- Migration `CURRENT_USER()` becomes the trigger definer; verify `SHOW CREATE TRIGGER` for all three triggers and test credential updates + mobile-settings updates/deletes under the runtime app account. Hold maintenance and quiesce legacy writers/mobile clients during migration; mobile auth fails closed until recorded.
- `MOBILE_V2_OFFLINE_SALES_ENABLED=false` pauses offline intake; disabling offline policy or changing approved cash/tax config retires company leases in the same transaction.

## 4. Operations, Reconciliation & Release Links

- **Six-Domain Reconciliations:** Run `php artisan reconciliation:run --force` or individual domain CLIs (`accounting:reconcile`, `inventory:reconcile --all`, `sales:reconcile`, `payables:reconcile` [alias `purchasing:reconcile`], `money:reconcile`, `phase7:reconcile` [alias `accounting:reconcile-phase7`]). See [Reconciliation Operations Guide](RECONCILIATION_OPERATIONAL_GUIDE.md).
- **Backup & recovery:** A current production snapshot restored and migrated successfully in isolation on 2026-11-25; six reconciliations passed for both companies. Historical compatibility and independent key-loss recovery remain unverified. See [Backup & Restore Runbook](PHASE_10_BACKUP_RESTORE_RUNBOOK.md).
- **Production deployment:** The web release is live from `main`; retain exact operator release pins. Follow [Hostinger Deployment Guide](HOSTINGER_DEPLOYMENT_GUIDE.md) and [Controlled Release Checklist](CONTROLLED_RELEASE_CHECKLIST.md) (`bin/deploy.sh` keeps maintenance held; permissions `0755`/`0644`, never `777`; no source maps; private data/keys never stored in Git).

| Private Out-of-Band Artifact | Destination / Purpose |
|---|---|
| Production `.env` & `APP_PREVIOUS_KEYS` | Target app root `.env` (key history required to decrypt old issued docs) |
| Encrypted SQL dumps & tenant uploads | Off-host vault / `storage/app/private/companies/*/` (never in Git) |
