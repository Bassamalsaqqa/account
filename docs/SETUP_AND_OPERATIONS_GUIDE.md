# Small Trader Accounting — Setup & Operations Guide

## 1. System Requirements & Runtime Environment

This project is built for standard shared-hosting (e.g. Hostinger cPanel / Apache) and local development without requiring background daemons, Docker, WebSockets, or Redis.

| Component | Required Version | Supported / Tested | Notes |
|---|---|---|---|
| **PHP** | `^8.4` | PHP 8.4.25+ | Required extensions: `pdo_mysql`, `bcmath`, `mbstring`, `intl`, `gd`, `zip`, `xml`, `curl` |
| **Database** | MariaDB `10.4+` or MySQL `8.0+` | MariaDB 10.4.32 | Case-sensitivity, transactional isolation, and exact decimal arithmetic authoritative |
| **Node.js** | `^20.19.0` or `>=22.12.0` | Node 20.19+, 22.12+ | Strictly required by Vite 7.3.7 (`engines.node`) |
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

```bash
# Install PHP vendor packages strictly matching composer.lock
composer install --no-interaction --prefer-dist --optimize-autoloader

# Install Node modules matching package-lock.json
npm ci
```

### Step 2.2 — Configure Environment

Copy the default environment configuration:

```bash
cp .env.example .env
php artisan key:generate
```

Configure your local database connection in `.env`:

```ini
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=accounting_dev
DB_USERNAME=root
DB_PASSWORD=
```

> [!IMPORTANT]
> Never run destructive commands (`migrate:fresh`, `migrate:refresh`, `db:wipe`) against any existing or persistent database. Always create a dedicated, empty local database (e.g. `accounting_dev`).

### Step 2.3 — Run Forward Migrations

```bash
php artisan migrate --force
```

### Step 2.4 — Build Production Frontend Assets

Compile Tailwind CSS 4 and Vite bundle:

```bash
npm run build
```

This compiles client bundles with zero source maps and generates `public/build/manifest.json`.

---

## 3. Local Development Server

Run the development server:

```bash
# Terminal 1: Serve local application
php artisan serve --port=8000

# Terminal 2: Run Vite dev watcher (optional for live HMR)
npm run dev
```

Visit `http://127.0.0.1:8000` in your browser.

---

## 4. Canonical Verification & Testing

Unit, feature, and integration tests MUST be executed against an isolated disposable MariaDB instance to preserve persistent databases and verify MySQL/MariaDB decimal semantics.

### Step 4.1 — Running Verified PHP Feature Tests

Use the tracked `tests/Support/run-phase8-disposable.php` runner. It creates and migrates a temporary schema on an explicitly configured **local test-only MariaDB server**, applies isolated PHPUnit configuration, and drops its owned schema afterward. Provision that test-only server first; never point it at the application database or production credentials. Supply `APP_ENV=testing`, `PHASE8_ALLOW_DISPOSABLE_DB=1`, and `PHASE8_TEST_DB_HOST`, `PHASE8_TEST_DB_PORT`, `PHASE8_TEST_DB_USERNAME`, `PHASE8_TEST_DB_PASSWORD` for that server (see `tests/Support/DisposableMariaDbSchema.php`). For intentionally empty local passwords, explicitly set `PHASE8_TEST_DB_EMPTY_PASSWORD=1`.

```bash
# Run a focused target using the explicitly configured disposable test server
php tests/Support/run-phase8-disposable.php -- tests/Feature/Phase1/CompanySettingsLivewireTest.php

# Run all PHPUnit tests under the same isolated configuration
php tests/Support/run-phase8-disposable.php -- tests
```

The Windows release workstation also has a private `run-verified-qa.py` harness that starts/stops its own MariaDB server and binds evidence to the source. That private harness is not part of a fresh clone and is not a portable installation dependency. Preserve its release receipts through the private transfer procedure. Full release QA includes Pint, configured PHPStan and the complete isolated PHPUnit suite; execute each gate with the locked dependencies.

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

## 5. Private Data & Credentials Separation Protocol

The public GitHub repository contains application source code and migrations only. It **never** contains live production data, company attachments, private keys, or credentials.

### Private Artifacts Protocol (Transferred via Secure Encrypted Out-of-Band Channel Only)

1. **Environment Secrets**: `.env.production` containing production application keys (`APP_KEY`), database credentials, and session salts. Transferred directly to the server root `.env`.
2. **Private Tenant Uploads**: Customer file attachments, vendor invoice scans, and landed cost receipts located in `storage/app/private/companies/*/`. Restored directly to the tenant-isolated directory structure.
3. **Database Backups**: Full MariaDB SQL dumps generated via `mysqldump` and verified off-host before deployment releases.

---

## 6. Production Deployment Overview

Production releases follow the verified Hostinger release procedure documented in `docs/HOSTINGER_DEPLOYMENT_GUIDE.md`:
- Directory permissions: `0755` for directories, `0644` for files (never `0777`).
- Assets staged as complete manifest-verified directories; no hash overlays.
- Source maps strictly excluded from production builds.
- Off-host backup verified before and after each release.
