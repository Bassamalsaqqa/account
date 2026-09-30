# Small Trader Accounting — Hostinger Production Deployment Guide
**Target Domain:** `account.palsync.net`  
**Hosting Platform:** Hostinger Shared Hosting (Cloud / Business Web Hosting)  
**Execution Context:** Production Deployment Guide

---

## 1. Verified Hostinger Hosting Facts

These specifications were directly verified via the live Hostinger API/MCP read-only inspection:

| Property | Value / Verified State | Verification Source |
|---|---|---|
| **Account Username** | `u556956644` | Live Hostinger API (`hosting_domains_list-website-subdomains`) |
| **Parent Domain** | `palsync.net` | Live Hostinger API |
| **Subdomain** | `account.palsync.net` | Live Hostinger API |
| **Configured Document Root** | `/home/u556956644/domains/palsync.net/public_html/account` | Live Hostinger API |
| **Current Document Root Content** | Only `default.php` (Hostinger placeholder). No application deployed. | Live Hostinger File Browser |
| **Web PHP Support** | Hostinger currently runs PHP 8.3 on `palsync.net`, with **PHP 8.4 selectable** in hPanel | Live Hostinger API (`hosting_php_get`) |
| **Required PHP Extensions** | `bcmath`, `curl`, `fileinfo`, `gd`, `intl`, `mbstring`, `openssl`, `pdo`, `nd_pdo_mysql`, `tokenizer`, `xml`, `zip` all enabled | Live Hostinger API (`hosting_php_get`) |
| **Database Host** | `127.0.0.1:3306` (Local connection from PHP scripts). Do not use `srv*.hstgr.io` from PHP | Hostinger Hosting Architecture Rules |
| **SSL / HTTPS** | Lifetime SSL active on `account.palsync.net`, HTTPS redirect enabled | Live Hostinger API (`hosting_ssl_status`) |
| **Cron System** | System cron available via PHP CLI with standard 5-part cron syntax | Live Hostinger API (`hosting_cron-jobs_list`) |

---

## 2. Secure Filesystem Architecture (Keeping Laravel Root Private)

### The Security Imperative
Under no circumstances should the entire Laravel application repository be cloned or copied directly into `/home/u556956644/domains/palsync.net/public_html/account`. If the project root is placed inside `public_html`, sensitive files such as `.env`, `storage/logs`, SQLite databases, composer files, and internal code become potentially reachable over HTTP if web server rewrites fail.

### Approved Private Layout
The application files must reside **outside** `public_html`, alongside it under the domain directory:

```text
/home/u556956644/domains/palsync.net/
├── accounting/                         <-- PRIVATE APPLICATION ROOT (NOT WEB-ACCESSIBLE)
│   ├── .env                            <-- Protected environment variables
│   ├── app/
│   ├── bootstrap/
│   ├── config/
│   ├── database/
│   ├── routes/
│   ├── storage/
│   ├── vendor/
│   ├── artisan
│   └── public/                         <-- Source public assets & build artifact destination
│       └── build/                      <-- Pre-compiled Vite assets (manifest.json, CSS, JS)
│
└── public_html/
    └── account/                        <-- PUBLIC DOCUMENT ROOT (WEB-ACCESSIBLE)
        ├── index.php                   <-- Front controller (bridges to ../../accounting)
        ├── .htaccess                   <-- URL rewriting
        ├── favicon.ico
        ├── robots.txt
        └── build/                      <-- Deployed Vite assets (manifest.json, assets/)
```

### Public Root Wiring Strategies

Our `public/index.php` is engineered to support both deployment methods cleanly and fails closed with an HTTP 500 error if the private application root cannot be resolved:

#### Method A: SSH Symlink (Recommended if SSH symlinks are enabled)
If deploying via SSH shell, safely back up the initial placeholder directory and create a symbolic link pointing directly to the private application's `public/` directory:
```bash
cd /home/u556956644/domains/palsync.net/public_html
# Safely archive initial placeholder directory if it exists
if [ -d "account" ] && [ ! -L "account" ]; then
    mv account account_initial_backup_$(date +%Y%m%d%H%M%S)
fi
ln -s /home/u556956644/domains/palsync.net/accounting/public account
```
*Note: Never use destructive `rm -rf` commands on live document root directories.*

#### Method B: Separated Public Assets via Deployment Script (Robust & Portable)
When symbolic links are restricted or undesirable, `public_html/account` remains a standard directory. 
The repository's `public/index.php` dynamically checks candidates:
1. Standard local root (`__DIR__.'/..'`)
2. Explicit `LARAVEL_APP_PATH` environment variable
3. Two levels up (`dirname(__DIR__, 2).'/accounting'`), which resolves from `/home/u556956644/domains/palsync.net/public_html/account` directly to `/home/u556956644/domains/palsync.net/accounting`
4. Three levels up (`dirname(__DIR__, 3).'/accounting'`) for home-directory private app layouts

If public is separated, `public/index.php` automatically calls:
```php
$app->usePublicPath(__DIR__);
```
This guarantees that `asset()` URLs, Vite manifests, and file uploads correctly resolve to `public_html/account/`.

---

## 3. Initial Production Setup Walkthrough (Linear & Runnable)

Follow these steps in exact chronological order on a clean server:

### Step 3.1: Switch Subdomain PHP Version to 8.4 & Verify CLI PHP
1. In Hostinger hPanel, navigate to **Websites** → Select `palsync.net` (or manage subdomains).
2. Go to **Advanced** → **PHP Configuration**.
3. Select **PHP 8.4** and click **Update** (if updating globally for the domain is desired).
4. **Subdomain Isolation Note:** If the parent domain (`palsync.net`) hosts other active subdomains running on PHP 8.3, `account.palsync.net` can be safely isolated to PHP 8.4 without impacting sibling sites by setting the CloudLinux Alt-PHP handler in its document root (`public_html/account/.htaccess`):
   ```apache
   <FilesMatch "\.(php4|php5|php3|php2|php|phtml)$">
       SetHandler application/x-httpd-alt-php84
   </FilesMatch>
   ```
   The included `bin/deploy.sh` script automatically detects Hostinger's `/opt/alt/php84/usr/bin/php` and ensures this directive is preserved during public asset synchronization.
5. **Important distinction:** Web PHP (handled by LiteSpeed/Apache) and SSH CLI PHP may differ on Hostinger shared servers. In SSH, check your CLI version:
   ```bash
   php -v
   ```
   If `php -v` shows PHP 8.3 or older, locate and use the Hostinger CloudLinux PHP 8.4 binary:
   ```bash
   /opt/alt/php84/usr/bin/php -v
   ```
   Use the verified PHP 8.4 path explicitly for every command below. The examples use `/opt/alt/php84/usr/bin/php`; confirm that it exists on this account first.

### Step 3.2: Create MySQL Production Database
1. In hPanel, go to **Databases** → **Management**.
2. Create a new MySQL database:
   - Provide database name and username. Hostinger will prepend your account username (e.g., `u556956644_...`).
   - Copy the exact database name and database username from the hPanel dashboard. Do not invent or guess them.
   - Generate a strong random password (store securely).
3. The internal database host is always `127.0.0.1` and port is `3306`.

### Step 3.3: Clone Repository to Private Path
Connect via SSH to Hostinger:
```bash
cd /home/u556956644/domains/palsync.net
git clone <repository-git-url> accounting
cd accounting
```

### Step 3.4: Install Composer Production Dependencies under PHP 8.4
*Crucial Prerequisite:* Artisan commands (`key:generate`, `migrate`, etc.) require `vendor/autoload.php`. Composer must be executed under PHP 8.4 before running any Artisan command:
```bash
# Explicitly run Composer using the verified PHP 8.4 binary
/opt/alt/php84/usr/bin/php $(which composer) install --no-dev --prefer-dist --optimize-autoloader --no-interaction
```
If the `composer` target is not a PHP script that runs under the verified PHP binary, use a verified `composer.phar` instead. Do not fall back to a Composer wrapper using an older PHP runtime.

### Step 3.5: Configure Production `.env` and Generate Application Key
Copy the prepared production template:
```bash
cp .env.production.example .env
```
Edit `.env` using `nano .env` and configure:
- `DB_DATABASE=<exact_db_name_from_hpanel>`
- `DB_USERNAME=<exact_db_user_from_hpanel>`
- `DB_PASSWORD=<actual_db_password>`
- `REGISTRATION_ENABLED=false` (public self-registration disabled).
- **Mail Configuration:** For password reset emails to reach user inboxes, configure a verified SMTP provider (e.g., Hostinger Business Email, Brevo, Postmark, Mailgun):
  - `MAIL_MAILER=smtp`
  - `MAIL_HOST=<smtp_host>`
  - `MAIL_PORT=587`
  - `MAIL_USERNAME=<smtp_user>`
  - `MAIL_PASSWORD=<smtp_password>`
  - `MAIL_ENCRYPTION=tls`
  *(Note: Leaving `MAIL_MAILER=log` will write password resets to `storage/logs/laravel.log` without sending emails).*

Now generate the encryption key (Artisan is now functional):
```bash
/opt/alt/php84/usr/bin/php artisan key:generate
```

### Step 3.6: Run Database Migrations
Run the database migrations:
```bash
/opt/alt/php84/usr/bin/php artisan migrate --force
```

### Step 3.7: Supply Pre-compiled Production Frontend Assets
A fresh Git clone does not include `public/build` (it is `.gitignore`d). Shared hosting does not require and should not run a permanent Node server. 

Compile the production assets on your development/CI machine and transfer them to the private application's `public/build` directory:
```bash
# On your local machine:
npm run build

# Transfer public/build to Hostinger accounting/public/build via scp:
scp -r public/build u556956644@account.palsync.net:/home/u556956644/domains/palsync.net/accounting/public/
```
*(Alternatively, upload a zip of `public/build` via hPanel File Manager into `/home/u556956644/domains/palsync.net/accounting/public/` and extract it)*.

Verify that `/home/u556956644/domains/palsync.net/accounting/public/build/manifest.json` exists before proceeding.

### Step 3.8: Bootstrap Initial Login User
Because public self-registration is strictly disabled by default, run the interactive user creation command:
```bash
/opt/alt/php84/usr/bin/php artisan app:create-user --name="<Full Name>" --email="<user@example.com>" --locale="ar"
```
The command will prompt for the password using hidden input (`secret()`) so passwords never appear in shell history, logs, or process lists. It immediately activates and verifies the initial user.

### Step 3.9: Synchronize Public Assets & Warm Caches
Run the provided deployment script:
```bash
chmod +x bin/deploy.sh
PHP_BIN=/opt/alt/php84/usr/bin/php ./bin/deploy.sh
```
The deployment script will verify all prerequisites, synchronize the public assets to `public_html/account`, and warm production route, config, view, and event caches.

---

## 4. Automated Cron Job Configuration

Hostinger supports scheduled cron tasks.

In hPanel → **Advanced** → **Cron Jobs**, add a custom cron job:
- **Type:** Custom
- **Schedule:** `* * * * *` (Every minute)
- **Command:**
  ```bash
  /opt/alt/php84/usr/bin/php /home/u556956644/domains/palsync.net/accounting/artisan schedule:run >> /dev/null 2>&1
  ```
  *(Or `/usr/bin/php` if confirmed to be PHP 8.4+)*.

---

## 5. Deployment Update & Rollback Procedures

### Standard Update Workflow
Before updating, ensure pre-compiled frontend assets have been built (`npm run build`), transferred to `accounting/public/build`, and take a database backup:
```bash
cd /home/u556956644/domains/palsync.net/accounting
git pull origin main
PHP_BIN=/opt/alt/php84/usr/bin/php ./bin/deploy.sh
```

### Safe Revision-Controlled Rollback Procedure
If a deployment needs to be rolled back to a known-good commit or tag:
1. **Enter Maintenance Mode:**
   ```bash
   /opt/alt/php84/usr/bin/php artisan down
   ```
2. **Revert to a Known Good Git Revision / Tag:**
   Do not use `git reset --hard HEAD~1` as it can discard uncommitted configurations or misidentify the intended target commit. Instead, inspect the history and check out the known-good release tag or commit hash:
   ```bash
   git log --oneline -n 5
   git checkout <known-good-commit-or-tag>
   ```
3. **Re-install dependencies under PHP 8.4:**
   ```bash
   /opt/alt/php84/usr/bin/php $(which composer) install --no-dev --prefer-dist --optimize-autoloader --no-interaction
   ```
4. **Database Schema Considerations:**
   *Important:* Reverting application code does **not** automatically rollback database migrations. **Do NOT run `migrate --force` or blind `migrate:rollback` during rollback.**
   - If the bad deployment did not alter the database schema, no database mutation is needed.
   - If the bad deployment did alter database tables or data, restore the verified database backup taken immediately prior to the deployment (via Hostinger hPanel phpMyAdmin import or Hostinger daily backup snapshot) or deploy an audited forward compensating migration.
5. **Restore Frontend Build Artifacts for Rollback Revision:**
   Ensure the `public/build` directory contains the build artifacts corresponding to the rollback revision.
6. **Synchronize Public Assets & Warm Caches Without Re-running Migrations:**
   Run `deploy.sh` with the `--skip-migrate` flag:
   ```bash
   PHP_BIN=/opt/alt/php84/usr/bin/php ./bin/deploy.sh --skip-migrate
   ```
7. **Exit Maintenance Mode:**
   ```bash
   /opt/alt/php84/usr/bin/php artisan up
   ```
