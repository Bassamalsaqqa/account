# Small Trader Accounting

<!-- proprietary-license-notice: Bassamalsaqqa -->
> **License:** Proprietary. Copyright (c) 2026 Bassamalsaqqa. Original application code reserves all rights; third-party dependencies retain their own licenses. See [LICENSE](LICENSE) and [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md).

Multi-tenant, double-entry accounting and inventory system (`التاجر الصغير`) built on Laravel 13, Livewire 4, Tailwind CSS 4, Alpine.js, and MariaDB 10.4+ / MySQL 8.0+ for Hostinger shared hosting (zero Redis, Docker, or Node runtime daemons).

## Current Status

- **Release Status:** Web release **`b1.3`** is live on Hostinger. Exact source/build identity is shown by `ReleaseIdentity` and recorded with qualification and recovery evidence in private operator custody. Local follow-up changes require their own verification before deployment. Unstamped development builds display `Build unverified`.
- **Default-OFF Templates vs Live Features:** `.env.example` and `.env.production.example` keep optional feature flags OFF by default. Live production has **Mobile V1 (`MOBILE_API_ENABLED`) and AI enabled**; the **merchant API (`MOBILE_MERCHANT_API_ENABLED`), Mobile V2, offline sales, and subscription enforcement remain OFF**.
- **Implemented Core & Rules:** Arabic RTL (primary) / English LTR tenant workspaces, `/platform` control plane (`company.impersonate` support, subscription enforcement OFF), canonical `AccountingPostingService` ledger, Sales/AR, Purchasing/AP (including ADR 0013 zero-cost receipts), FEFO multi-warehouse inventory, Cash/Bank/Checks, Expenses/Payroll, reports/PDF/CSV, Company Data Export V1, and bounded AI read/unsaved-draft tools. Money displays and inputs use **two decimal places** (`BigDecimal` exact storage/FX/quantities; trailing-zero quantity trimming without silent truncation).
- **Problem Reporting & Operator Grants:** Authenticated tenant and `/platform` shells expose a lazy-loaded problem reporter (`POST /diagnostics/bug-reports`; **no guest reporter**, no automatic capture of form inputs or business data, and no active retention/pruning policy). User-entered notes are stored untrusted (users must omit passwords, party names, amounts, and secrets) and are **omitted** from sanitized agent briefs (`php artisan bugs:brief`). `php artisan platform:operator` grants core platform permissions only; `support.bug_reports.view` and `support.bug_reports.manage` require explicit `--permission=` grants.
- **Recovery & Mobile Gates:** A 2026-11-25 off-host production snapshot restored in isolation and passed all six reconciliations, proving that captured snapshot only — **not** historical pre-Phase-9 compatibility and **not** independent key-loss/disaster recovery (`bin/recovery-preflight.php` remains inspection-only). Mobile client work is Android-only (`mobile/v1` live; `mobile/v2` and offline capture OFF; mobile document discounts are an unimplemented proposal; physical-device acceptance remains unverified; **no iOS app gate**). Cold-route latency, demand-mounted AI, and client bundle splitting remain on **HOLD**.

## Quick Start & Verification

Single authority for local setup, migrations, disposable MariaDB testing, and qualification: **[Setup & Operations Guide](docs/SETUP_AND_OPERATIONS_GUIDE.md)**.

```bash
vendor/bin/pint --test && vendor/bin/phpstan analyse --no-progress
node --test tests/Frontend/*.test.js tests/Frontend/*.test.mjs tests/JavaScript/*.test.mjs
npm run build

# Focused PHPUnit on isolated disposable MariaDB (never persistent DB)
APP_ENV=testing PHASE8_ALLOW_DISPOSABLE_DB=1 PHASE8_TEST_DB_HOST=127.0.0.1 \
PHASE8_TEST_DB_PORT=3306 PHASE8_TEST_DB_USERNAME=test_runner PHASE8_TEST_DB_EMPTY_PASSWORD=1 \
php tests/Support/run-phase8-disposable.php -- tests/Feature/Phase1/CompanySettingsLivewireTest.php
```

## Documentation Index

| Document | Authority / Scope |
|---|---|
| [AGENTS.md](AGENTS.md) | Agent invariants, exact arithmetic, posting/atomicity, and review gates |
| [PRODUCT.md](PRODUCT.md) / [DESIGN.md](DESIGN.md) | Product rules, UI constitution, and CSS token authority ([`resources/css/app.css`](resources/css/app.css)) |
| [UX Route & Workflow Matrix](docs/UX_ROUTE_WORKFLOW_MATRIX.md) | Role redaction boundaries, route archetypes, and verification/HOLD gates |
| [UI Workflow Rules](docs/UI_WORKFLOW_RULES.md) | Durable tenant/platform UX, RTL, and accessibility rules |
| [Setup & Operations Guide](docs/SETUP_AND_OPERATIONS_GUIDE.md) | **Single authority** for local install, testing, mobile preflight, and qualification |
| [Hostinger Deployment Guide](docs/HOSTINGER_DEPLOYMENT_GUIDE.md) | Shared-hosting filesystem layout, `bin/deploy.sh`, cron, and rollback |
| [Controlled Release Checklist](docs/CONTROLLED_RELEASE_CHECKLIST.md) | Pre/post-maintenance verification and reopening gates |
| [Backup & Restore Runbook](docs/PHASE_10_BACKUP_RESTORE_RUNBOOK.md) | Off-host custody, `bin/recovery-preflight.php`, and rehearsal sequence |
| [Reconciliation Operations Guide](docs/RECONCILIATION_OPERATIONAL_GUIDE.md) | Six-domain CLI audits (`reconciliation:run`) and sanitized alerting |
| [Master Spec](docs/SMALL_TRADER_ACCOUNTING_MASTER_SPEC_v1.0.md) & [Blueprint](docs/ENGINEERING_BLUEPRINT_v1.0.md) | Core accounting/inventory domain specifications and schema architecture |
| [ADRs (0002–0013)](docs/adr/) & [Mobile V1 ADR](docs/integration/mobile-v1/ADR-0001-mobile-v1-contract.md) | Accepted architecture decisions and frozen Mobile V1 contract |
| Feature & Integration Contracts | [Platform Support View](docs/PLATFORM_SUPPORT_VIEW.md), [Opening Import](docs/CONTROLLED_OPENING_INVENTORY_IMPORT.md), [Data Export V1](docs/COMPANY_DATA_EXPORT_V1_CONTRACT.md), [Mobile V2 Offline Pilot](docs/integration/mobile-v2-offline-sale-pilot.md), [Mobile Settlement Targets](docs/integration/MOBILE_V1_SETTLEMENT_TARGETS_CONTRACT.md), [Proposed Mobile Discount (NOT IMPLEMENTED)](docs/integration/PROPOSED_DOCUMENT_DISCOUNT_MOBILE_CONTRACT_EXTENSION.md) |

Historical milestone notes and frozen evidence live under `docs/evidence/`, `docs/integration/`, and `.ai/`.
