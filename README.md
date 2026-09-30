# Small Trader Accounting

Small Trader Accounting (`التاجر الصغير`) is a multi-tenant, double-entry accounting and trading management system engineered for small merchants in Palestine and the Arab world.

The authoritative product requirements and architectural blueprints reside in `docs/`:
- `docs/SMALL_TRADER_ACCOUNTING_MASTER_SPEC_v1.0.md` (Product & Business Rules)
- `docs/ENGINEERING_BLUEPRINT_v1.0.md` (Architecture, Security & Technical Specifications)
- `AGENTS.md` (Implementation Non-Negotiables & Rules of Engagement)

Phase progress:
- **Phase 0:** Application Foundation, Responsive Bilingual (Arabic RTL / English LTR) Shell, and Initial Security / Hostinger Deployment Readiness.
- **Phase 1:** Multi-Tenancy Foundation, Company Isolation, RBAC (Owner, Admin, Accountant, Cashier, Viewer), and Company Settings Management.
- **Phase 2 (In Progress):** Money and Accounting Primitives (Exact BigDecimal Arithmetic, Universal FX, Chart of Accounts, Canonical Double-Entry Posting, Immutability, Reversal, and Reconciliation).

## Requirements

- PHP 8.4 with required extensions (`bcmath`, `curl`, `fileinfo`, `gd`, `intl`, `mbstring`, `openssl`, `pdo_mysql`, `tokenizer`, `xml`, `zip`)
- MariaDB 10.4+ or MySQL 8.0+ (authoritative for DB integration, decimal precision, locking, and migrations)
- Composer 2.x
- Node.js (for asset compilation via Vite; not required for runtime serving)

Shared-hosting compatibility is mandatory: no permanent Node server, Redis, Docker, WebSockets, or Supervisor required.

## Local Setup

1. Ensure PHP 8.4 is first on `PATH` for Composer scripts and Artisan commands.
2. Run `composer install` and `npm install`.
3. Copy `.env.example` to `.env`; set database credentials (MySQL/MariaDB) and application URL.
4. Run `php artisan key:generate` and `php artisan migrate`.
5. Run `npm run build` for frontend assets.

Public self-registration is disabled by default (`REGISTRATION_ENABLED=false`). Initial users are created through authorized CLI commands (`app:create-user`). Multi-tenant companies are provisioned and activated under active company context.

## Quality Gates

Before declaring any phase complete or submitting code, configure a MySQL/MariaDB test database and run:

```bash
composer qa
npm run build
php artisan route:cache
php artisan route:clear
git diff --check
```

`composer qa` executes:
- Laravel Pint for code styling (`@pint --test`)
- PHPStan / Larastan static analysis at Level >= 6 with zero errors (`@phpstan`)
- Full PHPUnit test suite against MySQL/MariaDB (`@test`)

## Hosting & Deployment

Production deployment is targeted to Hostinger shared hosting using separated private application source (`/domains/palsync.net/accounting`) and public document root (`/domains/palsync.net/public_html/account`). See `docs/HOSTINGER_DEPLOYMENT_GUIDE.md` and `bin/deploy.sh`.
