# Controlled release and reopening checklist

This is a future operational procedure, not evidence that a release, backup, restore or reconciliation was executed. Release preparation and reopening are separate authorized operations. `bin/deploy.sh` never reopens production.

## Before any source or asset change

1. Obtain exact-head source acceptance and Owner release authorization. Record the clean deployed SHA, accepted replacement SHA, matching builds and known-good rollback artifact. Stage the approved replacement privately, without replacing live source or assets yet.
2. Establish a coherent recovery point containing SQL, compatible code/build, public media, private files, relevant configuration dependencies and applicable Laravel encryption-key history. Record source timestamps, completeness manifest and ciphertext checksums. Protect backup-decryption secrets and application keys separately. A local archive is not independently verified off-host recovery; the existing operational recovery gates remain outstanding until demonstrated.
3. Enter maintenance using the **currently deployed code**, with an authorized restricted bypass for authenticated checks if needed. Verify the actual maintenance marker and unauthenticated maintenance response before any checkout, dependency change or build transfer. If maintenance already exists, retain its owner and bypass configuration. Stop on failure; do not change source first and rely on the new script to guard an earlier `git pull`.
4. Capture schema and full business-row fingerprints, configuration and public/private-file fingerprints, live `.htaccess` custody, permissions and a rollback-compatible database plan. Row counts alone do not establish data preservation. Do not print secrets or sensitive records into shared evidence.
5. Only now update the source to the exact accepted revision and transfer its matching build. No arbitrary advancement to an unreviewed main head. Public-root symlinks are refused; a checkout can mutate their live configuration before a later backup. Use a separately prepared real public directory with verified custody.

## Preparation script

Run under verified PHP 8.4:

```bash
PHP_BIN=/opt/alt/php84/usr/bin/php ./bin/deploy.sh
```

Defaults run no migrations and no purchasing/documents bootstrap. Use `--run-migrations`, `--run-purchasing-bootstrap`, `--run-documents-bootstrap` or `--run-bootstrap` only for the reviewed release scope. `--skip-migrate` remains compatible. These options do not authorize production operations by themselves.

The script preserves a pre-existing maintenance marker without rerunning `artisan down`. It verifies live `.htaccess` into restricted private custody **before Composer install, migrations, caches or public asset replacement**. Existing configuration remains byte-for-byte unchanged. Missing/conflicting manifest MIME and PHP-handler declarations require manual operator review; there is no automatic rewrite. Source `.htaccess` is used only for a fresh public directory, after validation. Symlinked public roots, web configuration and backup custody are rejected.

Publishing uses a fixed allowlist: entrypoint, web manifest, service worker, offline shell, reviewed icons and Vite build; optional robots/favicon files are copied when present. Uploads and unrelated business files are not synchronized or removed. Source/destination asset links are refused.

The preparation result is **maintenance held**, not production ready. On any failure, retain maintenance and inspect rollback safety.

## Verification while maintenance remains held

- Compare exact source/build identity, reviewed migration state, full business-row/schema fingerprints, uploads/private/public files and configuration fingerprints against the protected baseline. Explain every authorized difference; stop on unexplained changes.
- Run the six existing reconciliation services for every in-scope company: `AccountingReconciliationService`, `InventoryReconciliationService`, `SalesReconciliationService`, `PayablesReconciliationService`, `MoneyReconciliationService`, and `Phase7ReconciliationService`. Existing CLI entry points are `accounting:reconcile`, `inventory:reconcile`, `sales:reconcile`, `money:reconcile`, and `phase7:reconcile`; Payables uses its existing service through a separately reviewed read-only harness. Do not invent a Payables CLI, tax reconciliation, or unsupported whole-AP-ledger policy. Inspect findings, not only process exit status. These services implement their accepted domain policies; an independent known integrity defect remains a release consideration even if a check reports healthy.
- Through the authorized maintenance bypass, verify authenticated Arabic and English dashboard, Settings, reports, Sales/Purchase creation and shared-layout workflows. Check actual Linux Livewire aliases, fresh logs, essential API route behavior and feature flags. No financial writes or billable AI calls are needed for this release verification.
- Verify HTTPS delivery of actual CSS/JS referenced by the Vite manifest, and `https://account.palsync.net/manifest.webmanifest` with `application/manifest+json`. The web manifest is a published static file at the public root, **not** `/build/manifest.webmanifest`. A source directive is not proof of the live response MIME.
- Confirm the protected `.htaccess` checksum is unchanged after publishing assets, including Hostinger PHP handling and operator-specific rules. Verify intended permissions: directories 0755, files 0644, restricted backup files 0600/directories 0700; never 777.
- Verify safe rollback to the known-good source/build with retained forward schema and business data. Do not restore an old SQL snapshot over subsequent production activity or reverse applied migrations for convenience.

## Explicit reopening gate

Record sanitized checks and outstanding risks. If any critical authenticated error, unexplained preservation difference or reconciliation failure exists, keep maintenance and use the reviewed rollback plan. Do not mark unexecuted checks PASS.

Only after those gates pass and the Owner authorizes reopening:

```bash
/opt/alt/php84/usr/bin/php artisan up
```

Then verify unauthenticated login, actual authorized AR/EN sessions, assets, fresh logs and intended API/AI feature state. Do not modify passwords, bypass permissions or dispatch paid requests as a smoke shortcut. Reopening is not performed by the preparation script.

See [Hostinger deployment guide](HOSTINGER_DEPLOYMENT_GUIDE.md), [backup/restore runbook](PHASE_10_BACKUP_RESTORE_RUNBOOK.md), and [ADR-0005](adr/0005-purchasing-vendors-and-ap.md) for the accepted Payables policy.
