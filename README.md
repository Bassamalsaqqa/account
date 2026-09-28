# Small Trader Accounting — Phase 0

This repository contains the Laravel application foundation, bilingual responsive shell, and a static settings visual proof. The approved product and architecture sources are in `docs/`; `AGENTS.md` defines implementation guardrails. Business modules and company tenancy start in later phases.

## Requirements

- PHP 8.4 with the extensions required by Laravel and `pdo_mysql`
- Composer, Node.js for asset builds, and MySQL or a MySQL-compatible server
- A web server whose document root is `public/`

Node.js is needed to build assets, not to serve production requests. Redis, Docker, WebSockets, and Supervisor are not required.

## Local setup

1. Ensure PHP 8.4 is first on `PATH` for Composer scripts and Artisan commands.
2. Run `composer install` and `npm ci`.
3. Copy `.env.example` to `.env`; set database credentials and application URL. Keep `.env` private.
4. Run `php artisan key:generate` and `php artisan migrate` against the development MySQL database.
5. Run `npm run build` for production assets.

Public registration is controlled by `REGISTRATION_ENABLED` and defaults to `false`. Create initial users through an authorized private bootstrap process. The protected `/settings` page contains demonstration data only; it does not persist company settings. Arabic is the default interface locale, and `/locale/ar` or `/locale/en` switches direction and language.

## Quality gates

Configure a separate MySQL test database named in `phpunit.xml`, then run:

```text
composer qa
npm run build
```

`composer qa` checks formatting with Pint, static analysis with Larastan, and the PHPUnit suite against MySQL. The application currently has only framework authentication and session/cache/job schema plus Fortify two-factor columns.

## Hosting

Build Vite assets before deployment. Point the hosting document root at `public/`, use PHP 8.4, and configure the site's `.env` for its MySQL database, mail and HTTPS URL. The exact Hostinger plan and directory layout remain deployment-time choices.
