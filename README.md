# Small Trader Accounting

<!-- proprietary-license-notice: Bassamalsaqqa -->
> **License:** Proprietary. Copyright (c) 2026 Bassamalsaqqa. All rights reserved for the original application code. Third-party components retain their own licenses. See [LICENSE](LICENSE) and [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md).

Small Trader Accounting (`التاجر الصغير`) is a multi-tenant, double-entry accounting and trading management system engineered for small merchants in Palestine and the Arab world.

## Current State & Roadmap Checkpoint

- **Runtime milestone (4 November 2026)**: Bounded assistant/report read optimization released at `c6c176cbbb9438e80d0d62b08810affd9674263b`. Verify current local, GitHub and production SHAs before release; this dated checkpoint is not a live synchronization guarantee.
- **Implemented Scope**:
  - Bilingual company workspaces (Arabic RTL primary, English LTR secondary).
  - Multi-tenant isolation with server-side authorization and full platform operator audit log.
  - Double-entry general ledger with canonical atomic posting (`AccountingPostingService`).
  - Sales & Customers (AR): quotations, invoices, returns, payments, statements, price history.
  - Purchasing & Vendors (AP): invoices, returns, payments, landed cost allocations (ADR 0005, ADR 0007, ADR 0013).
  - Multi-warehouse inventory with traceable batches/lots and FEFO expiry tracking.
  - Treasury & Cash: cash registers, bank accounts, customer/vendor check management.
  - Expenses & Payroll: categorized vouchers, receipt attachments, employee salary slips.
  - Canonical reporting: trial balance, balance sheet, income statement, tax reports, cash flows, streamed CSV export.
  - AI Assistant: Bounded read-only queries, local authorized report execution, and editable unsaved form proposals.
- **Current Status & Defers**:
  - Bounded read optimization is released.
  - Demand-mounted AI, client bundle separation, enabled-state paired browser latency, and 100-line virtualized draft qualification remain deferred on HOLD.
  - Global and per-company subscription enforcement remains OFF.
  - Cold-route latency and authenticated live-browser qualification remain open; local warm timings do not certify every route or device.
  - Documentation and tracked source support a new isolated development setup. Clean network dependency installation and a complete new-machine bootstrap still require environment-specific verification; private production credentials/data never come from Git.
  - Historical workstation cleanup is incomplete: retained worktrees and private recovery evidence require separate custody review. The archive hashes do not establish complete recovery of ignored or binary content.
- **Network & Architecture Boundaries**:
  - Connected web application with network-only progressive web app capabilities; strictly network-connected (no offline financial writes or asynchronous client mutations).
  - Shared-hosting compatibility: zero background daemons (no Redis, Docker, or permanent Node processes).

## Documentation Index & Sources of Truth

| Guide | Purpose |
|---|---|
| [**AGENTS.md**](AGENTS.md) | Agent contract, accounting invariants, and rules of engagement |
| [**PRODUCT.md**](PRODUCT.md) | Product scope, user workflows, and business invariants |
| [**DESIGN.md**](DESIGN.md) | Design tokens, BoardUI/shadcn adaptations, and visual hierarchy |
| [**Setup & Operations**](docs/SETUP_AND_OPERATIONS_GUIDE.md) | Single authority for fresh-clone installation, testing, and operations |
| [**UI Workflow Rules**](docs/UI_WORKFLOW_RULES.md) | Navigation, form layout, table density, and interaction standards |
| [**Master Product Spec**](docs/SMALL_TRADER_ACCOUNTING_MASTER_SPEC_v1.0.md) | Complete business domain specifications |
| [**Engineering Blueprint**](docs/ENGINEERING_BLUEPRINT_v1.0.md) | Architecture, database schema, and technical boundaries |
| [**Deployment Guide**](docs/HOSTINGER_DEPLOYMENT_GUIDE.md) | Hostinger production deployment and release procedure |
| [**Controlled Release Checklist**](docs/CONTROLLED_RELEASE_CHECKLIST.md) | Gate verification checklist prior to production deployment |
| [**Release & Orchestration Guide**](docs/RELEASING.md) | Versioning conventions, build manifests, mobile API compatibility, and release notes |
| [**Architectural Decisions**](docs/adr/) | Accepted ADRs (0002 through 0013) |

## System Requirements

- **PHP**: `^8.4` (tested on PHP 8.4.25+) with extensions: `bcmath`, `curl`, `fileinfo`, `gd`, `intl`, `mbstring`, `openssl`, `pdo_mysql`, `tokenizer`, `xml`, `zip`.
- **Database**: MariaDB `10.4+` or MySQL `8.0+` (authoritative for transactional isolation, locking, and exact decimal arithmetic).
- **Composer**: `2.x`.
- **Node.js**: `^20.19.0 || >=22.12.0` (required for asset compilation via Vite 7; runtime serving requires zero Node daemons).
- **Shared Hosting**: Zero background daemons; fully compatible with standard Apache/cPanel/Hostinger environments (no Redis, Docker, or Supervisor required).

## Installation & Quality Gates

All fresh-clone installation, environment configuration, database migration, and test procedures are consolidated in the authoritative guide:

👉 **[docs/SETUP_AND_OPERATIONS_GUIDE.md](docs/SETUP_AND_OPERATIONS_GUIDE.md)**

Quick validation command for active development:

```bash
# Code style check
vendor/bin/pint --test

# Static analysis (Larastan / PHPStan Level 6)
vendor/bin/phpstan analyse --no-progress

# Focused isolated PHPUnit (configure the dedicated test-only server first;
# see Setup & Operations for environment and full-suite timeout settings)
php tests/Support/run-phase8-disposable.php -- tests/Feature/Phase1/CompanySettingsLivewireTest.php
```
