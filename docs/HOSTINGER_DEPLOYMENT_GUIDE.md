# Hostinger Production Deployment Guide

- **Status:** **NOT DEPLOYED** — `account.palsync.net` docroot holds only Hostinger's `default.php` placeholder.
- **Target Account / Subdomain:** `u556956644` / `account.palsync.net` (parent `palsync.net`, Hostinger Shared/Cloud Hosting, lifetime SSL + HTTPS redirect active).
- **Authoritative Release Gates:** Complete [Controlled Release Checklist](CONTROLLED_RELEASE_CHECKLIST.md) before any live change and before bringing the site out of maintenance.

## 1. Filesystem Layout & Public Root Rule

Keep the Laravel root **outside** `public_html`. `bin/deploy.sh` requires `PUBLIC_DIR` to be a separate, real (non-symlinked) directory and refuses symlinked public roots.

```text
/home/u556956644/domains/palsync.net/
├── accounting/                 # Private Laravel root (never web-accessible; holds .env, storage, vendor)
│   └── public/build/           # Staged Vite assets + manifest.json (0755 dirs, 0644 files, zero .map)
└── public_html/account/        # Public docroot (index.php, .htaccess, manifest.webmanifest, sw.js, icons/, build/)
```

`public/index.php` resolves `../../accounting` automatically (or `LARAVEL_APP_PATH`) and calls `$app->usePublicPath(__DIR__)`. Keep `.git`, `node_modules`, `tests`, `docs`, dumps, `.env`, and staging archives out of `public_html/account`.

## 2. Clean Server Initial Setup

1. **PHP 8.4:** Select PHP 8.4 for `account.palsync.net` in hPanel (or isolate via `SetHandler application/x-httpd-alt-php84` in `public_html/account/.htaccess` while preserving sibling PHP 8.3 subdomains). Use `/opt/alt/php84/usr/bin/php` for all SSH CLI commands (`php -v` in SSH may point to 8.3).
2. **MySQL Database:** Create DB + user in hPanel → Databases → Management. Connect from PHP via `127.0.0.1:3306` (never `srv*.hstgr.io`).
3. **Clone & Install Dependencies:**
   ```bash
   cd /home/u556956644/domains/palsync.net
   git clone https://github.com/Bassamalsaqqa/account.git accounting
   cd accounting
   /opt/alt/php84/usr/bin/php $(which composer) install --no-dev --prefer-dist --optimize-autoloader --no-interaction
   ```
4. **Configure `.env` & App Key:**
   ```bash
   cp .env.production.example .env
   # Set exact DB_DATABASE, DB_USERNAME, DB_PASSWORD, SMTP settings, REGISTRATION_ENABLED=false
   /opt/alt/php84/usr/bin/php artisan key:generate
   ```
5. **Migrations:** Complete [mobile authority migration preflight](SETUP_AND_OPERATIONS_GUIDE.md#mobile-authority-migration-preflight) if pending, then run `/opt/alt/php84/usr/bin/php artisan migrate --force`.
6. **Frontend Build:** Build locally (`npm run build`), verify `public/build/manifest.json` + zero `.map` files, and copy the complete directory to `accounting/public/build` (never overlay hashed chunks).
7. **Bootstrap User & Deploy:**
   ```bash
   /opt/alt/php84/usr/bin/php artisan app:create-user --name="<Full Name>" --email="<email>" --locale=ar
   chmod +x bin/deploy.sh
   PHP_BIN=/opt/alt/php84/usr/bin/php ./bin/deploy.sh
   ```
   `bin/deploy.sh` leaves **maintenance mode held**. Only reopen after passing [Controlled Release Checklist](CONTROLLED_RELEASE_CHECKLIST.md) and explicit Owner authorization: `/opt/alt/php84/usr/bin/php artisan up`.
8. **Cron (`* * * * *` in hPanel):**
   ```bash
   /opt/alt/php84/usr/bin/php /home/u556956644/domains/palsync.net/accounting/artisan schedule:run >> /dev/null 2>&1
   ```

## 3. Standard Update & Safe Rollback

### Update
Follow pre-change backup, fingerprint, and maintenance rules in [Controlled Release Checklist](CONTROLLED_RELEASE_CHECKLIST.md), checkout the exact accepted release commit (never blind `git pull`), transfer its matching `public/build`, complete [mobile authority migration preflight](SETUP_AND_OPERATIONS_GUIDE.md#mobile-authority-migration-preflight) if needed, and run:

```bash
cd /home/u556956644/domains/palsync.net/accounting
PHP_BIN=/opt/alt/php84/usr/bin/php ./bin/deploy.sh
```

Defaults skip migrations and bootstraps; pass `--run-migrations`, `--run-purchasing-bootstrap`, `--run-documents-bootstrap`, or `--run-bootstrap` only for explicitly reviewed releases. `bin/deploy.sh` preserves live `.htaccess` byte-for-byte (requiring `x-httpd-alt-php84` and `application/manifest+json` for `.webmanifest`), atomically swaps `public_html/account/build`, and leaves maintenance held until `/opt/alt/php84/usr/bin/php artisan up`.

### Rollback
**NEVER restore an old SQL dump over subsequent live accounting activity or run `migrate:fresh` / `migrate:rollback`.** Retain forward migrations (or author an audited compensating migration):

```bash
/opt/alt/php84/usr/bin/php artisan down --render="errors.503" --retry=60
git checkout <known-good-commit-or-tag>
/opt/alt/php84/usr/bin/php $(which composer) install --no-dev --prefer-dist --optimize-autoloader --no-interaction
# Restore matching public/build for that revision, then run deploy.sh without migrations:
PHP_BIN=/opt/alt/php84/usr/bin/php ./bin/deploy.sh
# Complete release checklist, then under Owner authorization:
/opt/alt/php84/usr/bin/php artisan up
```
