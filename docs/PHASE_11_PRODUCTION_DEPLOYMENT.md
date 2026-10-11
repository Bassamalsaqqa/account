# Phase 11 production deployment — 2026-10-11

Architect acceptance: source `c669c093c5ee61ffc71bc7af2fed74f9b41aec3a`. [PR #24](https://github.com/Bassamalsaqqa/account/pull/24) was merged with its exact accepted head, without rewriting history, at 08:15:24 UTC. Merge/main/deployed source: `c2e39a32271b14de99e6ffda55ec5b0ea400cb7e`; tree `c4b50af0531b37be204898cbff860a3361d38d2c`. Previous runtime: `3a77afef5c79dc947d306845a725241989acd33a`.

## Verification

The additive `2026_10_11_100000_create_command_receipts_table` migration completed: 83→84 tables and 63→64 migrations. The new receipt table was empty before reopening. Before/after row counts and SHA-256 fingerprints of 79 existing nonvolatile tables matched exactly; document sequences and permission/role grants matched. Sessions, caches and migration bookkeeping were excluded from immutable-data comparisons. Private/public business-file fingerprints matched. All six canonical reconciliations were healthy: Accounting, Inventory, Sales, Payables, Money and Phase 7. No synthetic business records were created in production.

`COMMAND_API_ENABLED=false` is explicit in protected production configuration and false in the warmed configuration cache. All other configuration values were preserved. Actual HTTP-kernel inspection rejected the API with 404 before any database query. Twelve bounded public checks passed: AR/EN login rendering, password-reset request page (GET only), health, protected-route login redirection, two disabled API paths, denied private configuration/dependency files (403/404), and both released CSS/JS assets. No authenticated user was impersonated; authenticated personal workflow testing remains with the Owner.

Locked dependencies and build assets were verified. Host CLI PHP 8.4.26 and MariaDB 11.8.9 were observed. Deployment used the pinned PHP 8.4 binary; the host's default SSH PHP is 8.2 and must not be used for this release. Public/private access guards and directory 0755/file 0644 deployment permissions were preserved. Tracked production source was clean and maintenance mode was removed after integrity checks.

## Rollback and retained evidence

Encrypted on-host SQL/application-state checkpoints, a code bundle and a checked asset rollback buffer were created before mutation. Secret-bearing state (including configuration) and separate checkpoint-key custody stay restricted on-host; neither backups nor keys were downloaded or exposed. These checkpoints are not independent off-host recovery proof.

One release attempt stopped at an asset checksum manifest mismatch before configuration changes or migration. The prepared code/assets-only rollback restored the previous runtime and reopened the site; no database restore or reverse migration occurred. The manifest newline defect was corrected, and actual archived file checksums were added to the pre-maintenance guard. The subsequent deployment passed all checks. Private logs preserve both attempts; no uninterrupted or zero-downtime claim is made.

After migration, the rollback procedure retains the additive empty receipt table and preserves all business data. This post-migration rollback path was inspected, not destructively rehearsed. Database restoration requires the separately approved isolated recovery procedure.

## Continuing boundaries

Phase 11 is deployed with new command API exposure disabled. Phase 12 source development is authorized; provider activation, costs/privacy approval and Phase 12 release acceptance remain separate gates. Deferred Phase 10 independent off-host backup/key recovery, both actual isolated restoration lanes and approved real password-reset delivery remain NOT VERIFIED. This deployment does not certify customer readiness or satisfy those operational gates.
