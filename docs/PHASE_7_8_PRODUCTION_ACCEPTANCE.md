# Phase 7–8 production acceptance — 2026-10-09

## Decision

**PHASE 7 AND 8 PRODUCTION ACCEPTED**

This is Codex's bounded, independent production verification after AGY's Owner-authorized deployment. It does not authorize Phase 9 coding or another deployment. Phase 9 remains **UNSTARTED**; its engineering proposal awaits architect review.

## Exact release identity

| Record | Verified identity |
|---|---|
| Local main and origin/main at verification | `28c2a53f84a8a0525ade6e1be7cee31ac17388ba` |
| Live checkout | `96c310f30a07e97ab8e04d5afbf0b2bb805f4317` |
| Live and independently accepted Phase 8 tree | `a71f567ba54b16b30d4d1d4643a14a960e0ff98f` |
| Accepted Phase 8 source | `e8e3e28fb040875d3b55ad403bea54a037e71437` |
| Phase 7 merge, verified live ancestor | `c07559c92816f838532e4b9d1c1537065f50ad5c` |
| Accepted Phase 7 source | `e8a5dee856bc06da48b1313f8776d723af309b5e` |

SSH independently confirmed the exact checkout/tree, clean tracked production tree, Phase 7 ancestry and absence of maintenance mode. The difference between live HEAD and the verification baseline is limited to four documentation files: Blueprint, Master Specification, ADR 0008 and Phase 8 source handoff. It is not runtime drift.

Production: `https://account.palsync.net`; private root `/home/u556956644/domains/palsync.net/accounting`; public root `/home/u556956644/domains/palsync.net/public_html/account`.

## Database, bootstrap and preservation

Independent database audit completed at **2026-10-09 15:19:52 UTC**; post-smoke hash comparison completed at **15:32:00 UTC**. Both used explicitly read-only MariaDB transactions. No migration, bootstrap, repair or financial action was executed.

- **80 tables / 61 applied migrations / zero pending migrations.**
- All three Phase 7 forward migrations are applied:
  - `2026_10_07_200000_create_expense_categories_and_expenses_tables`
  - `2026_10_07_200001_create_employees_and_payroll_tables`
  - `2026_10_07_200002_create_landed_cost_and_purchase_lines_tables`
- Phase 8 adds no migration.
- **13 Expense Categories / 12 document sequences / 35 ledger accounts.** The four Phase 7 sequence types are present with counters at 1.
- **69 registered reports / 87 catalog permissions**, with no catalog permission missing from the Owner role.
- All seven non-owner roles retain exactly the grant sets recorded in both pre-deployment metadata checkpoints. Role grant counts: Administrator 43, Manager 38, Sales 10, Purchasing 9, Warehouse 4, Cashier 6, Viewer 11.
- All pre-existing sequence counters match both backup checkpoints.
- The existing population remains one Company, one User, two Vendors, zero Customers, one Product, one Warehouse and one Cash account. There is no Bank account or Employee.
- Posting batches/lines, stock movements, Sales/Purchase documents and payments, Checks, Transfers, Expenses, Payroll and Landed Cost allocations remain empty. No live business fixture was created.

The pre-Phase 7 metadata count changes are the expected foundation additions (sequences, ledgers, migrations, permissions and Owner grants), plus sessions. The pre-Phase 8 count changes are the expected five reporting permissions/Owner grants plus cache/session activity.

The independently computed SHA-256 table fingerprints before/after verification match for **all 79 non-session tables**, including every financial source, master, permission table and sequence. SQL computed per-row hashes; only digests were retrieved. Session rows changed from 32 to 22 through normal request/session maintenance. This is not a claim that HTTP verification makes zero session/cache writes.

Pre-deployment row-count and grant/sequence preservation was independently compared against the private metadata. AGY's claimed pre-deployment row-content digests were not independently reproduced using its hashing algorithm; those remain worker evidence. Production contains no economic history to exercise nonempty financial lifecycles.

## Six independent read-only reconciliations

| Domain | Result |
|---|---|
| Accounting | Healthy; 0 batches/lines; 35 accounts; debit/credit both 0.000000 |
| Inventory | Healthy |
| Sales | Healthy |
| Payables | Healthy |
| Money | Healthy; 1 account; no unknown historical native legs |
| Phase 7 | Healthy; no violations |

The canonical reconciliation services were inspected before invocation and executed in system/context-free mode inside a read-only transaction. They performed no rebuild or repair.

## Backups and recovery

Private backup directory: `storage/app/private/deployment-backups/`, owned by `u556956644`, mode **0700**. Both archives and corresponding SQL/metadata files are owned by that account and mode **0600**.

| Backup | Compressed size | Independently verified SHA-256 |
|---|---:|---|
| `pre-phase7-20261009-143933-51adf80.sql.gz` | 23,662 bytes | `77db466f7872628b505fc0d319755258f47f8b467e63a21ff94704feb127d4e0` |
| `pre-phase8-20261009-144604-c07559c.sql.gz` | 26,151 bytes | `6bd5ce11b5cd6457a3f43839f2f45d4380a9dfc18cd0b0c6faea1f9d7b3a9fb6` |

Both pass `gzip -t`. Their decompressed CREATE TABLE inventories contain **71** and **80** tables, respectively. Uncompressed SQL hashes also match AGY's report:
- Pre-Phase 7: `2e82c3bd0aa409fde4ff7373d601438fd79ff1ec769b3ce28f1bfa724b5ee48f`.
- Pre-Phase 8: `14bec76a1e7edaeec4f30707148f898f4618299bff28ae7e48b69672800c8beb`.

The public storage symlink resolves only to `storage/app/public`, not private storage. HEAD checks for three plausible public mappings of the Phase 8 archive return **404**; `/.env` returns **403**. No private dump or credential was downloaded or exposed.

[Hostinger deployment guide](HOSTINGER_DEPLOYMENT_GUIDE.md), “Safe Revision-Controlled Rollback Procedure,” documents revision-pinned code/assets recovery, migration caution and separately authorized database restore/forward correction. AGY's report identifies the precise Phase 6 and Phase 7 recovery checkpoints. **No restore rehearsal or live restore was performed.** Integrity checks are not proof of a completed recovery rehearsal; that remains a production-hardening task.

## Assets and operating state

- CLI PHP **8.4.19**; PDO MySQL, mbstring, GD, intl and zip are available.
- Installed production Composer package versions match the locked non-development dependencies.
- Config, route and event cache files exist; compiled view directory exists.
- Private/public build directories have **zero permission violations** against directories 0755 / files 0644. Retained older hashed assets are consistent with non-destructive synchronization.
- Public HTTPS manifest/CSS/JS return **200** and match the private and public deployment files:
  - Manifest: `2ead566e4e45b97cb874d48c567bfc9cca6b205d2ffe3008e4f8e27f9b29104e`.
  - `app-BdUKSLS1.css`: `15750677124756184d44dd59a22c65c55c24b813de2abf93e2ea30b6d1ce9f31`.
  - `app-DMsN-rLE.js`: `f0d3557302f9eba56acf6ee08eb53f2d49c2a8cf994a70708a2bcd8ab22676cf`.
- Public root/login resolve successfully to the login page; maintenance mode is off.
- `APP_DEBUG` is false and application environment is production. No secret values were printed.
- The checked application/public root PHP inventory contains no AGY diagnostic scripts; the public root contains its expected index.php.
- The available Laravel log is empty before/after the final successful checks. This is bounded log evidence, not an assertion about every Hostinger/web-server log.
- No SSH-user crontab entry was observed. The accepted source registers no scheduled Phase 7/8 task in `routes/console.php`; this does not prove the absence of separate hPanel jobs. Mail/backup scheduling and a restore rehearsal remain explicit Phase 9/10 operational decisions.

## Independent application verification

### Actual live browser

Used the already authenticated Owner tab in Edge, with no login/session grant, fake User or temporary role.

**23 desktop observations and 5 mobile observations** passed rendered-page, locale/direction and document-width checks. This count includes the Money movement selector's truthful required-selection state and its subsequent selected-account state.

Desktop: Dashboard; Customers; Vendors; Products; Inventory; Invoices; Purchases; Money; Checks; Expenses; Employees; Reports hub; Money balances/movements; Inventory stock/low-stock; Expense summary; Payroll summary; Sales by Product; the three Payroll forms.

Mobile at **390 × 844**: Dashboard, Reports hub, Money balances, Expenses and Employees. No document-level horizontal overflow; wide tables retain their own scrolling. The original viewport and Settings page were restored.

Observed nonempty available-data cases:
- One Cash account, **0.000000 ILS** native/base balance, as of **2026-10-09**.
- One low-stock Product: available **0.000000**, minimum/shortfall **5.000000**, canonical unit **piece**.
- Two existing Vendors and one Product in the directories.

No business form was submitted. Existing-history reports correctly show empty states; no live test transaction was invented.

An authorized Money balances CSV was downloaded through the actual browser. It contains a UTF-8 BOM, six Arabic headers with **ILS** base label and exactly one existing Cash row. File length **175 bytes**, SHA-256 `3af428beddea108f0d856cbc392a71eecec013782702f8c9302933be4015936e`.

### Read-only Arabic/English server rendering

**34/34 HTTP-kernel checks: 17 screens × 2 locales.** Each returned 200 with the explicitly asserted `lang=ar, dir=rtl` or `lang=en, dir=ltr`; visible-text checks found no raw reporting/payroll/expense/money translation keys.

These run on the deployed application with the existing Owner principal, an **in-memory array session** cloned from existing authorized session state and read-only MariaDB. They do not change persisted locale/configuration or create a session grant. They are server-render tests, **not English live-browser screenshots**.

Screens: Dashboard, Customers, Inventory, Money, Checks, Expenses, Employees, three Payroll forms, Reports hub, Money balances, selected Money movements, Inventory low-stock, Expense summary, Payroll summary and Sales by Product.

An initial read-only probe of Vendor Index was discarded: its established authorization performs SELECT FOR UPDATE, which an intentionally read-only transaction refuses. The actual authenticated browser Vendor screen passed. Initial verifier-script schema/property mistakes were corrected; none are application-source changes or counted as passing checks.

### Evidence exceptions

- Saved Owner locale was not changed. Actual responsive browser evidence is Arabic; English is independently verified server rendering. No English responsive production-browser run is claimed.
- Three browser console messages reported an asynchronous listener/message-channel closure. They did not coincide with failed application rendering; their origin was not established. Do not describe the browser console as error-free.
- One Owner exists; no production restricted-role matrix was fabricated. Accepted isolated source tests remain the negative authorization evidence.
- Backups were verified, not restored. Nonempty financial, Check, Payroll and acquisition flows remain accepted isolated-suite evidence.
- AGY's local smoke script uses in-process HTTP requests, does not enforce all asserted status codes and includes stale report aliases. Its AR/EN locale claims were insufficient independently; the valid routes and explicit locale/direction checks above replace those claims.

## Evidence and Git boundary

Ignored local evidence: `.ai/delegations/20261009-production-acceptance-phase9-plan/`.
Key files: `independent-shell-checks.txt`, `independent-db-audit.json`, `independent-post-smoke-audit.json`, `independent-public-http-and-csv.json`, `independent-ar-en-render.json`. Browser observations/screenshots are in the tool transcript; no saved screenshot-file inventory is claimed.

AGY's deployment execution, maintenance/backup creation, migrations, repeated bootstraps and stage ordering remain **worker-reported execution**. Codex independently verified the resulting release, archives, applied schema/provisioned records, preserved grants/counters, hashes, read-only reconciliation and bounded application rendering. No full PHPUnit/CI/migration/concurrency run was repeated.

Documentation reconciliation and the [Phase 9 proposal](PHASE_9_ENGINEERING_PROPOSAL.md) are prepared on a separate local documentation branch. They await architect review; they are not a production rollout or a Phase 9 implementation. Main/origin remain at the verification baseline. All nine Owner files are preserved byte-for-byte.

Codex performed authorized read-only Hostinger access, no deployment, no financial/configuration mutation, no schema change, no merge and no Phase 9 coding.
