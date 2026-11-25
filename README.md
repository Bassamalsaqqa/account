# Small Trader Accounting

<!-- proprietary-license-notice: Bassamalsaqqa -->
> **License:** Proprietary. Copyright (c) 2026 Bassamalsaqqa. Original application code reserves all rights; third-party dependencies retain their own licenses. See [LICENSE](LICENSE) and [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md).

Multi-tenant, double-entry accounting and inventory system (`التاجر الصغير`) built on Laravel 13, Livewire 4, Tailwind CSS 4, Alpine.js, and MariaDB 10.4+ / MySQL 8.0+ for Hostinger shared hosting (zero Redis, Docker, or Node runtime daemons).

## Current Status

- **Deployment & Recovery:** **NOT deployed** (`account.palsync.net` holds only the Hostinger placeholder). Production database **restore is NOT VERIFIED**; [`bin/recovery-preflight.php`](bin/recovery-preflight.php) is inspection-only (`exit 2`, `bootstrap_authorized=false`; `46` standalone tests / `113` assertions).
- **Implemented Web Core:** Arabic RTL (primary) / English LTR tenant workspaces, platform `/platform` control plane (`company.impersonate` support, `SUBSCRIPTION_ENFORCEMENT_ENABLED=false`), canonical `AccountingPostingService` double-entry ledger, Sales & AR (quotations, invoices, returns, document discounts, receipts, statements), Purchasing & AP (bills, returns, document discounts, landed costs, ADR 0013 zero-cost receipts), multi-warehouse FEFO inventory, Cash/Bank/Checks, Expenses & Payroll, canonical reports/PDF/CSV, Company Data Export V1, and bounded AI read/unsaved-draft tools (default OFF).
- **Mobile API & Proposals:** Native `/api/mobile/v1` and `/api/mobile/v2` boundaries exist in code, with **API and offline capture OFF by default** (`MOBILE_API_ENABLED=false`, `MOBILE_MERCHANT_API_ENABLED=false`, `MOBILE_V2_API_ENABLED=false`, `MOBILE_V2_CASH_SLICE_ENABLED=false`, `MOBILE_V2_OFFLINE_SALES_ENABLED=false`). Mobile document discounts are a **future proposal (not implemented)** (`docs/integration/PROPOSED_DOCUMENT_DISCOUNT_MOBILE_CONTRACT_EXTENSION.md`).
- **Verification Reality:** Backend suite stands at `5,090` tests / `61,638` assertions across mixed baseline + affected runs (not all re-executed on one single HEAD); latest UI regressions passed `71` tests / `854` assertions; paired real-HTTP integration (`12` sale vectors across 3 companies + `6` domain reconciliations + foreign isolation) passed against the `d096` post-qualification runtime baseline (runtime business source unchanged since). Cold-route latency, live authenticated browser UAT, physical-device checks, and demand-mounted AI remain open/HOLD.

## Quick Start & Checks

Single authority for setup, migrations, testing, and qualification: **[Setup & Operations Guide](docs/SETUP_AND_OPERATIONS_GUIDE.md)**.

```bash
# Code style & static analysis
vendor/bin/pint --test
vendor/bin/phpstan analyse --no-progress

# Focused PHPUnit on a dedicated disposable MariaDB test server (never persistent DB)
APP_ENV=testing PHASE8_ALLOW_DISPOSABLE_DB=1 PHASE8_TEST_DB_HOST=127.0.0.1 PHASE8_TEST_DB_PORT=3306 \
PHASE8_TEST_DB_USERNAME=test_runner PHASE8_TEST_DB_EMPTY_PASSWORD=1 \
php tests/Support/run-phase8-disposable.php -- tests/Feature/Phase1/CompanySettingsLivewireTest.php

# Phase G qualification preflight & static gate
php tools/release/qualify --preflight-only --expected-commit=$(git rev-parse HEAD)
php tools/release/qualify --static-only
```

## Documentation Index (Current vs Historical)

### Current & Authoritative

| Document | Scope |
|---|---|
| [AGENTS.md](AGENTS.md) | Agent invariants, accounting rules, and review gates |
| [PRODUCT.md](PRODUCT.md) / [DESIGN.md](DESIGN.md) | Product scope, UI design tokens, and responsive rules |
| [Setup & Operations Guide](docs/SETUP_AND_OPERATIONS_GUIDE.md) | **Single authority** for clone, CLI bootstrap, testing, and qualification |
| [Hostinger Deployment Guide](docs/HOSTINGER_DEPLOYMENT_GUIDE.md) | Shared-hosting layout, `bin/deploy.sh`, cron, and rollback |
| [Controlled Release Checklist](docs/CONTROLLED_RELEASE_CHECKLIST.md) | Pre/post-maintenance verification and reopening gates |
| [Backup & Restore Runbook](docs/PHASE_10_BACKUP_RESTORE_RUNBOOK.md) | Off-host custody, `bin/recovery-preflight.php`, and rehearsal rules |
| [Reconciliation Operations Guide](docs/RECONCILIATION_OPERATIONAL_GUIDE.md) | Six-domain CLI audits, `reconciliation:run`, and alerting |
| [UI Workflow Rules](docs/UI_WORKFLOW_RULES.md) | Durable tenant/platform UX and accessibility rules |
| [Master Product Spec](docs/SMALL_TRADER_ACCOUNTING_MASTER_SPEC_v1.0.md) & [Blueprint](docs/ENGINEERING_BLUEPRINT_v1.0.md) | Core domain specifications and schema architecture |
| [ADRs (0002–0013)](docs/adr/) & [Frozen Mobile V1 Mirror](docs/integration/mobile-v1/ADR-0001-mobile-v1-contract.md) | Accepted architecture decisions and SHA-256 pinned contract |
| [Platform Impersonation](docs/PLATFORM_SUPPORT_VIEW.md) | `company.impersonate` delegation contract |
| [Opening Inventory Import](docs/CONTROLLED_OPENING_INVENTORY_IMPORT.md) | `inventory:opening-import` strict XLSX plan/apply boundary |
| [Company Data Export V1](docs/COMPANY_DATA_EXPORT_V1_CONTRACT.md) | Owner-only ZIP/CSV snapshot contract |
| [Mobile V2 Offline Pilot](docs/integration/mobile-v2-offline-sale-pilot.md) & [Settlement Targets](docs/integration/MOBILE_V1_SETTLEMENT_TARGETS_CONTRACT.md) | V2 lease/offline wire contract (default OFF) and V1 AR/AP allocation |
| [Proposed Mobile Document Discount](docs/integration/PROPOSED_DOCUMENT_DISCOUNT_MOBILE_CONTRACT_EXTENSION.md) | **FUTURE PROPOSAL (NOT IMPLEMENTED)** |

### Historical Milestones, Handoffs & Evidence Archives

- **Implementation/Milestone Handoffs (Historical):** [Corrections (2026-11-07)](docs/CORRECTION_IMPLEMENTATION_20261107.md), [UI Redesign (2026-11-07)](docs/UI_REDESIGN_20261107.md), [MS-1A](docs/MANAGED_PLATFORM_MS1A.md), [MS-2](docs/MANAGED_PLATFORM_MS2.md), [FIN-1](docs/FIN1_IMMEDIATE_SETTLEMENT.md), [Report Party Selectors](docs/implementation/REPORT_PARTY_SELECTORS.md), [Laravel Provenance Audit](docs/LARAVEL_PROVENANCE_AUDIT_20261025.md), [Mobile Read Migrations](docs/integration/mobile-read-release-migrations.md), [Mobile V1/V2 Notes & Evidence](docs/integration/mobile-v1-backend-notes.md), [PR88 Decisions](docs/integration/mobile-v1-correction-decisions.md), [PR88 Verification](docs/integration/mobile-v1-correction-evidence.md), [V1/V2 Review Evidence](docs/integration/mobile-v1-v2-review-evidence.md), [Android Wire Change](docs/integration/mobile-merchant/android-wire-change.md).
- **Evidence Artifacts:** Immutable logs, screenshots, and JSON manifests under `docs/evidence/` and `.ai/`.
