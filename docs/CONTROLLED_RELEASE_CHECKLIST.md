# Controlled Release & Reopening Checklist

Operational procedure only — **not** evidence that a release, backup, restore, or reconciliation has occurred.

Run the clean-source qualification runner for the accepted commit; its PHPUnit inventory includes the offline recovery inspector harness. This is local evidence, not proof of a live restore.

## 1. Before Any Source or Asset Change

1. Obtain Owner authorization and exact accepted release identity (pin deployed SHA, replacement SHA, build digest, and rollback artifact externally). Stage replacement `public/build` privately.
2. Establish a coherent off-host recovery point (SQL + code/build + public media + private files + config + separate `APP_KEY`/`APP_PREVIOUS_KEYS` custody) with completeness manifest and ciphertext SHA-256. Local archives do not prove off-host recovery.
3. Using the **currently deployed code**, enter maintenance (`artisan down --render="errors.503" --retry=60`) or preserve existing maintenance/bypass ownership, and verify unauthenticated 503 response. Stop on failure.
4. Capture schema + full business-row fingerprints (not row counts alone), config, public/private file fingerprints, and `.htaccess` custody.
5. Check out the exact accepted revision and stage its matching `public/build` and generated `bootstrap/cache/release.json`. Supply `RELEASE_EXPECTED_CODE` to deployment; verify the cached version/SHA/asset digest and visible version. Refuse symlinked public roots.

## 2. Deployment Preparation (`bin/deploy.sh`)

Complete [mobile authority migration preflight](SETUP_AND_OPERATIONS_GUIDE.md#mobile-authority-migration-preflight) before any release DDL if pending, then run under Hostinger PHP 8.4:

```bash
PHP_BIN=/opt/alt/php84/usr/bin/php ./bin/deploy.sh
```

Defaults skip migrations and bootstraps; pass `--run-migrations`, `--run-purchasing-bootstrap`, `--run-documents-bootstrap`, or `--run-bootstrap` (`--skip-migrate` retained) only for reviewed scope. The script backs up and validates live `.htaccess` (requiring `application/manifest+json` and Alt-PHP 8.4 handler without overwriting), stages and atomically swaps `public/build`, and leaves **maintenance held**.

## 3. Verification While Maintenance Remains Held

- Match exact source/build identity, migration state, full business-row/schema fingerprints, and file/`.htaccess` checksums (`0755` dirs, `0644` files; restricted backups `0700`/`0600`; never `777`).
- Run all six read-only domain reconciliations across every company (`0 failed`, inspect findings):
  - `php artisan reconciliation:run --force` (or individual CLIs: `accounting:reconcile`, `inventory:reconcile`, `sales:reconcile`, `payables:reconcile`, `money:reconcile`, `phase7:reconcile`).
- Verify authenticated AR/EN dashboard, Settings, reports, Sales/Purchasing creation, API/feature flags, and fresh logs via authorized bypass (no live financial writes or billable AI calls).
- Verify live HTTPS delivery of Vite manifest CSS/JS and `/manifest.webmanifest` (`Content-Type: application/manifest+json`, served from public root, not `/build/`).
- **Additive schema rollback safety:** Retain forward purchase/sales-discount columns and data during code rollback. Null invoice/quotation precision snapshots preserve pre-feature currency rules (`3` decimals JOD/KWD/BHD/OMR, `2` others); never deploy qualification intermediates `c9c1ad7` or `74cd44ed`.

## 4. Explicit Reopening Gate

Hold maintenance and roll back on any failure or unexplained fingerprint mismatch. Only after all gates pass and the Owner authorizes reopening:

```bash
/opt/alt/php84/usr/bin/php artisan up
```

Then confirm public login, authorized AR/EN sessions, HTTPS assets, logs, and the approved effective feature flags. Preserve existing AI and mobile V1 settings; keep subscription enforcement and mobile V2/offline sales OFF unless separately authorized. See [Hostinger Deployment Guide](HOSTINGER_DEPLOYMENT_GUIDE.md), [Backup & Restore Runbook](PHASE_10_BACKUP_RESTORE_RUNBOOK.md), [Setup & Operations Guide](SETUP_AND_OPERATIONS_GUIDE.md), and [ADR-0005](adr/0005-purchasing-vendors-and-ap.md).
