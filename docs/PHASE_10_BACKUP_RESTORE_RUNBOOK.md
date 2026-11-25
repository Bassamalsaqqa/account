# Phase 10 Backup & Disaster Recovery Runbook

**Status:** Source tooling (`bin/recovery-preflight.php`, `tests/Deployment/recovery-preflight.php`: `46` tests / `113` assertions) is **inspection-only** (`exit 2`, `bootstrap_authorized=false`). A current off-host production snapshot was restored and migrated forward successfully on 2026-11-25, with matching row/file fingerprints, protected-record decryption, login, and all six reconciliations for both companies. This proves that captured snapshot, not historical compatibility or independent recovery after loss of all application keys. Exact identities and private evidence remain in operator custody.

## 1. Two Independent Proofs Required

| Lane | Required Proof | Current Status |
|---|---|---|
| **Historical compatibility** | Restore pre-Phase-9 baseline (`96c310f30a07e97ab8e04d5afbf0b2bb805f4317`: `80` tables / `61` migrations) and migrate forward to `83` / `63` | **NOT VERIFIED** |
| **Current snapshot recovery** | Restore coherent SQL, code/build, storage and configuration from off-host custody; verify decryption, login, fingerprints and six reconciliations | **VERIFIED 2026-11-25** for the captured snapshot; independent key-loss recovery remains unverified |

For releases outside the inspector's legacy baseline list, operators MUST supply explicit external release pins at runtime (`--mode=current --expected-code=<40hex> --expected-build-sha256=<64hex> --expected-tables=<int> --expected-migrations=<int> --manifest-sha256=<64hex>`). Archives/manifests never self-select trusted identity, so release commits never require self-referential SHA allowlists.

## 2. Custody & Coherent Point Rules

- **Off-host survival:** Store ciphertext and separately protected backup passphrases/`APP_KEY`/`APP_PREVIOUS_KEYS` off-host independently of the primary Hostinger account. Never rotate or regenerate keys during recovery, and never extract `.env` from an archive as proof of key recovery.
- **Coherent capture:** Proposed cadence (pending Owner adoption): daily coherent snapshot + pre-release, pre-schema, and pre-key points (proposed retention: 7 daily / 4 weekly / 3 monthly). Capture transaction-consistent SQL + matching files/config with zero secret values in manifests.

## 3. Fail-Closed Rehearsal Sequence

1. **Authorize & verify ciphertext:** Obtain explicit rehearsal authorization; verify encrypted archive and manifest SHA-256 digests without altering ciphertext.
2. **Isolate scratch:** Prepare ephemeral non-public scratch (`0700` dirs / `0600` files) on an isolated host; verify actual network egress/ingress blocks (no prod DB, SMTP, HTTP, cron, or public access).
3. **Decrypt & quarantine:** Validate members before extraction (refuse traversal, symlinks, hardlinks, special files). Never autoload archived code; quarantine any archived `.env` and remove stale `bootstrap/cache/{config,routes*,services,packages}.php`.
4. **Provision disposable MariaDB:** Create an empty loopback-only schema (`accounting_p10_restore_<12hex>`) and restricted user (`p10_restore_<12hex>`) with an external ownership-proof JSON (`host`, `port`, `schema`, `user`, `nonce`).
5. **Prepare fresh app root:** Configure isolated `.env` (`APP_DEBUG=false`, `MAIL_MAILER=array`, explicit rehearsal `null` queue driver in `config/queue.php`, registration disabled).
6. **Run dependency-free preflight inspector** from outside scratch (loads no Laravel/vendor):
   ```bash
   php bin/recovery-preflight.php \
     --manifest=<path> --manifest-sha256=<64hex> --expected-code=<40hex> \
     --scratch=<inert-path> --ownership-proof=<path> \
     --target-host=127.0.0.1 --target-port=<port> \
     --target-schema=accounting_p10_restore_<12hex> --target-user=p10_restore_<12hex> \
     --mode=current --expected-tables=<int> --expected-migrations=<int> --expected-build-sha256=<64hex>
   ```
   Exit `1` = fail-closed refusal; exit `2` = `INSPECTION_ONLY_BOOTSTRAP_BLOCKED` (`bootstrap_authorized=false`). Preflight does not verify Windows ACLs, real network isolation, DB grants, key recovery, or actual SQL restore.
7. **Re-verify isolation & inject keys:** Verify network/DB isolation via non-Artisan tooling, then inject `APP_KEY`/`APP_PREVIOUS_KEYS` via protected file/process custody (never CLI args, logs, or Git).
8. **Import & restore files:** Import decrypted, validated SQL (never `.enc` ciphertext) and verified private/public files.
9. **Migrate & verify app:** Historical lane runs forward `migrate --force` + `purchasing:bootstrap --all` + `documents:bootstrap --all` (`80/61 -> 83/63`). Current lane verifies matching schema/code directly. Verify login, exact historical reports/balances, attachments, AR/EN PDFs, encrypted share decryption, and revoked/expired share blocks.
10. **Run all six read-only reconciliations** across every recovered company (`php artisan reconciliation:run --force`, or `accounting:reconcile`, `inventory:reconcile --all`, `sales:reconcile`, `payables:reconcile`, `money:reconcile`, `phase7:reconcile`); inspect findings, not exit code alone.
11. **Measure observed RPO/RTO** from latest complete off-host capture timestamp (provisional targets `RPO<=24h`, `RTO<=4h` are unverified proposals).
12. **Teardown:** Stop scratch processes, revoke temp DB user, drop only the verified disposable schema, and securely wipe ephemeral plaintext/keys/scratch.

## 4. Required Negative Cases & Invariants

Authorized rehearsal must also prove wrong/missing passphrase or key, corrupt/missing components, schema/code mismatch, traversal/symlink members, unquarantined `.env`/caches, wrong DB target/grants, blocked outbound calls, partial restore interruption, and cleanup failure. Never execute `migrate:fresh`, `migrate:refresh`, `db:wipe`, destructive reseeding, or blind SQL restores over newer production data.
