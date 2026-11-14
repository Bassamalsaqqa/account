# PR 99 Controlled Web Release: Root Operator Preflight & Recovery Runbook

> **OPERATIONAL LANE & ROLE BOUNDARY**  
> - **Preparation Lane**: Source-only recovery preparation and runbook authoring.  
> - **Operator Authority**: **Root is the sole production operator.**  
> - **Agent Constraint**: Strictly read-only research. No SSH commands, no database queries, no build execution, no child agents.  
> - **Release Candidate (PR 99)**: Git commit `8cd432220801b08b10b6f25f9ee9a215de94d44c` (0 migrations).  
> - **Live Production Baseline**: Git commit `f8a0cf7012c4875bec7320d42e0f8dde58593b52` (deployed on `account.palsync.net`).  
> - **Documentation Branch**: This runbook resides on preparation branch `t3/pr99-web-release-recovery` (an operational artifact branch, NOT deployed candidate source).  
> - **Preserved Live Untracked Assets**: `asset-stage.Rod9Vi`, `deepseek-assets.cCmzPJ`, `deepseek-assets.fYgzxl`, `deploy.lock`. Strictly retain; do not clean up.

---

## 1. Phase A: Safe Read-Only Preflight & Metadata Inspection

Root executes these read-only inspection commands over SSH (`hostinger-accounting`).  
**Safety Invariants**:
- **NO Laravel application bootstrap** (avoids triggering read-write database connection pools or service provider boot).
- **NO database queries or connections** in Phase A.
- **NO passwords or secrets** in command-line arguments, process table (`ps`), or `.env` grep output.
- **Strictly read-only inspection** of tools, git, filesystem, and candidate staging digests.

```bash
# ------------------------------------------------------------------------------
# 1. SSH to Hostinger Account & Verify Host Tool Versions
# ------------------------------------------------------------------------------
ssh hostinger-accounting

# Check PHP 8.4 CLI binary
/opt/alt/php84/usr/bin/php -v

# Check required extensions without booting Laravel framework
/opt/alt/php84/usr/bin/php -r '
    $required = ["bcmath", "curl", "fileinfo", "gd", "intl", "mbstring", "openssl", "pdo_mysql", "tokenizer", "xml", "zip"];
    $missing = array_filter($required, fn($ext) => !extension_loaded($ext));
    if ($missing) { echo "MISSING_EXTENSIONS: " . implode(", ", $missing) . PHP_EOL; exit(1); }
    echo "PHP 8.4 runtime and required extensions: VERIFIED" . PHP_EOL;
'

# Check system tool versions
git --version
tar --version
openssl version
mysqldump --version

# Check filesystem disk space on Hostinger private mount
df -h /home/u556956644/

# ------------------------------------------------------------------------------
# 2. Verify Live Source Baseline & Working Tree
# ------------------------------------------------------------------------------
cd /home/u556956644/domains/palsync.net/accounting

# Verify exact deployed commit SHA (MUST match f8a0cf7012c4875bec7320d42e0f8dde58593b52)
git rev-parse HEAD

# Check git status (confirm tracked files are clean; observe untracked assets)
git status --porcelain

# Confirm preserved untracked assets exist untouched (DO NOT PRUNE)
ls -ld asset-stage.Rod9Vi deepseek-assets.cCmzPJ deepseek-assets.fYgzxl deploy.lock 2>/dev/null || true

# ------------------------------------------------------------------------------
# 3. Verify Live Build Manifest & .htaccess Custody
# ------------------------------------------------------------------------------
# Verify live build manifest SHA-256 (MUST match 4f7ea5b3a956aca1eaf120c0ee67d499b9fc91854f672c0db32bdceee4ac96ab)
sha256sum /home/u556956644/domains/palsync.net/public_html/account/build/manifest.json

# Verify live .htaccess SHA-256 (MUST match 071652f64ad14f324544e95317aed883e59a9337acb5e1b3519bf0e71a36bdcc)
sha256sum /home/u556956644/domains/palsync.net/public_html/account/.htaccess

# Confirm maintenance mode is currently OFF (down marker absent)
test -f storage/framework/down && echo "STATUS: MAINTENANCE_ACTIVE" || echo "STATUS: MAINTENANCE_OFF (marker absent)"

# ------------------------------------------------------------------------------
# 4. Verify Staged Candidate Artifacts in Private Staging
# ------------------------------------------------------------------------------
STAGING_DIR="/home/u556956644/domains/palsync.net/accounting-release-staging/pr99-8cd4322"

ls -lh "$STAGING_DIR"

# Check bundle SHA-256 (MUST match 3877102e136a59941b6320fb7b3b2fc43b6e18fb4c67549140cb577e95db689f)
sha256sum "$STAGING_DIR/source-v1.bundle"

# Check build tar SHA-256 (MUST match 5f5282238bca8c3cca8f5f87f7947f11cd03d126a324c73b42ca1b1f7df056af)
sha256sum "$STAGING_DIR/build.tar.gz"

# Verify bundle integrity and inspect its embedded HEAD commit (MUST be 8cd432220801b08b10b6f25f9ee9a215de94d44c)
git bundle verify "$STAGING_DIR/source-v1.bundle"
git bundle list-heads "$STAGING_DIR/source-v1.bundle"

# ------------------------------------------------------------------------------
# 5. Check On-Host Backup Freshness
# ------------------------------------------------------------------------------
# Check existing backup timestamps (note: October backups are stale and NOT current proof)
ls -lt /home/u556956644/domains/palsync.net/backups/ 2>/dev/null || true
```

---

## 2. Phase B: Capture design remains unqualified

**NOT EXECUTABLE. No capture or maintenance has occurred.** The previous shell recipe is withdrawn: `openssl dgst -hmac file:...` authenticates using the literal argument rather than loading the intended key file. It also removed plaintext before independent recovery verification. Neither behavior is accepted.

Before any root-operated capture, qualify an authenticated encryption/decryption format with separate protected secret delivery, tamper/truncation/wrong-key refusal tests, failure-safe artifact retention, and an independently recoverable key/ciphertext custody record. Do not replace this requirement with an ad hoc command or a ciphertext checksum alone.

The coherent point must cover verified writer quiescence, transaction-consistent SQL, matching code/build and actual public/private files, encrypted records and applicable configuration/key-history references. Derive actual component scope and counts; do not use dummy components. Record exact source/build hashes and capture times. Verify transfer and a restore rehearsal in an independently owned inert target before claiming recoverability. The inspection-only Phase 10 tool does not establish restore success.

The remaining sections are design notes, not execution authorization or a deploy-ready command sequence. Root must review the entire operator procedure and current custody before using them. Existing business data, keys, live source, build and staging remain preserved.

## 3. Off-Host Custody & Separation

Root downloads ciphertext directly to a local secure store (or designated custodian host).

> **Non-Secret Custodian Request**: Root coordinates with Owner regarding the exact local/off-host destination directory (e.g. `~/.secure-recovery/account-palsync/` on Root workstation). No secret tokens or passwords are exchanged in chat.

```bash
# Executed on Root workstation:
LOCAL_DIR="$HOME/.secure-recovery/account-palsync/$POINT_ID"
mkdir -p "$LOCAL_DIR" && chmod 0700 "$LOCAL_DIR"

# Download encrypted components and manifest digests
scp -p "hostinger-accounting:$BACKUP_DIR/*" "$LOCAL_DIR/"

# Verify digest locally
cd "$LOCAL_DIR"
sha256sum -c manifest.sha256

# Custody Invariants:
# 1. Independent Storage: Survives Hostinger account loss or server suspension.
# 2. Custody Separation: Passphrase held in Root password manager, NOT stored on Hostinger.
# 3. Key History: APP_KEY history held independently in Root password vault.
```

---

## 4. Phase 10 Offline Inspector Boundary

`bin/recovery-preflight.php` is a **preflight inspection tool ONLY**.  
- It validates archive member sizes, path traversals, symlink refusals, and structure.  
- It **always reports `bootstrap_authorized=false`** (dedicated exit code `2`).  
- It does **NOT** prove full restore or replace rehearsal.  
- Any true recovery rehearsal requires provisioning an isolated loopback disposable database (`accounting_p10_restore_<12hex>`) on an isolated non-production port.  
- **Production Safety**: NEVER run `artisan migrate:fresh`, `db:wipe`, or import database dumps over persistent live data.

---

## 5. Candidate 8cd4322 Deployment & Reconciliations (Maintenance Held)

```bash
cd /home/u556956644/domains/palsync.net/accounting

# 1. Verify candidate bundle and check out 8cd4322
git bundle verify "$STAGING_DIR/source-v1.bundle"
git fetch "$STAGING_DIR/source-v1.bundle" HEAD:candidate-8cd4322
git checkout candidate-8cd4322
git rev-parse HEAD # MUST be 8cd432220801b08b10b6f25f9ee9a215de94d44c

# 2. Extract candidate build into private staging before deploy.sh handles publishing
# (bin/deploy.sh verifies .htaccess custody and handles asset replacement)
tar -xzf "$STAGING_DIR/build.tar.gz" -C /home/u556956644/domains/palsync.net/public_html/account/

# 3. Run deploy preparation (PR 99 has 0 migrations; default skips migrations)
PHP_BIN=/opt/alt/php84/usr/bin/php ./bin/deploy.sh

# 4. Execute the 6 Domain Reconciliations While Maintenance Is Held
/opt/alt/php84/usr/bin/php artisan accounting:reconcile
/opt/alt/php84/usr/bin/php artisan inventory:reconcile --all   # MUST include --all
/opt/alt/php84/usr/bin/php artisan sales:reconcile
/opt/alt/php84/usr/bin/php artisan money:reconcile
/opt/alt/php84/usr/bin/php artisan phase7:reconcile

# Payables Reconciliation: Run for every active company via PayablesReconciliationService
/opt/alt/php84/usr/bin/php -r '
    require "vendor/autoload.php";
    $app = require_once "bootstrap/app.php";
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();
    $service = app(App\Services\Purchasing\PayablesReconciliationService::class);
    foreach (App\Models\Company::all() as $company) {
        $report = $service->reconcile($company);
        echo "Company: " . $company->displayName() . " | Healthy: " . ($report->isHealthy ? "YES" : "NO") . PHP_EOL;
        if (!$report->isHealthy) { exit(1); }
    }
'

# 5. Confirm .htaccess integrity after deploy.sh
sha256sum /home/u556956644/domains/palsync.net/public_html/account/.htaccess
# Expected: 071652f64ad14f324544e95317aed883e59a9337acb5e1b3519bf0e71a36bdcc

# 6. Test authenticated bypass endpoint
curl -s -k -I "https://account.palsync.net/$BYPASS_TOKEN"
curl -s -k -I "https://account.palsync.net/manifest.webmanifest"
```

---

## 6. Complete Rollback Procedure

If any reconciliation fails or unexpected discrepancy is detected while maintenance is held:

1. **Revert Source Code**:
   ```bash
   cd /home/u556956644/domains/palsync.net/accounting
   git checkout f8a0cf7012c4875bec7320d42e0f8dde58593b52
   ```
2. **Restore Baseline Frontend Build**:
   Restore the `f8a0cf7` frontend build from the pre-deployment `code_build.tar.gz` archive to `/home/u556956644/domains/palsync.net/public_html/account/build`:
   ```bash
   tar -xzf "$BACKUP_DIR/code_build.tar.gz" -C /home/u556956644/domains/palsync.net/public_html/account/ build
   sha256sum /home/u556956644/domains/palsync.net/public_html/account/build/manifest.json
   # MUST match 4f7ea5b3a956aca1eaf120c0ee67d499b9fc91854f672c0db32bdceee4ac96ab
   ```
3. **Run deploy.sh**:
   ```bash
   PHP_BIN=/opt/alt/php84/usr/bin/php ./bin/deploy.sh
   ```
4. **Verify `.htaccess` Checksum**:
   ```bash
   sha256sum /home/u556956644/domains/palsync.net/public_html/account/.htaccess
   # MUST match 071652f64ad14f324544e95317aed883e59a9337acb5e1b3519bf0e71a36bdcc
   ```
5. **Re-verify Reconciliations**:
   Database was untouched (0 migrations). Re-run the 6 reconciliations to confirm consistency.

---

## 7. Reopening Gate

Under the controlled release checklist (`docs/CONTROLLED_RELEASE_CHECKLIST.md` line 42):
> *"Only after those gates pass and the Owner authorizes reopening: `/opt/alt/php84/usr/bin/php artisan up`"*

No new or speculative sign-off conditions are invented. If the existing Owner controlled release authorization covers the verified check results, Root executes:
```bash
/opt/alt/php84/usr/bin/php artisan up
curl -s -k -I https://account.palsync.net | head -n 5
```
