# PR 99 Controlled Web Release: Root Operator Preflight, Coherent Capture & Recovery Runbook

> **OPERATIONAL LANE & ROLE BOUNDARY**  
> - **Preparation Lane**: Source-only recovery preparation and runbook authoring.  
> - **Operator Authority**: **Root is the sole production operator.**  
> - **Agent Constraint**: Strictly read-only research and tool preparation. No SSH commands, no database queries, no build execution, no child agents.  
> - **Release Candidate (PR 99)**: Git commit `8cd432220801b08b10b6f25f9ee9a215de94d44c` (0 migrations).  
> - **Live Production Baseline**: Git commit `f8a0cf7012c4875bec7320d42e0f8dde58593b52` (deployed on `account.palsync.net`).  
> - **Owner Custody Model**: Owner explicit NO-ENCRYPTION override. Security model enforces POSIX filesystem permissions (`umask 077`: `0700` directories, `0600` files) transferred via SSH to Owner-custodied protected local off-host directory. No GPG/passphrase/key workflow.  
> - **Preserved Live Untracked Assets**: `asset-stage.Rod9Vi`, `deepseek-assets.cCmzPJ`, `deepseek-assets.fYgzxl`, `deploy.lock`. Strictly retain; do not prune or clean up.  
> - **Live Host Environment (Verified 05:59 UTC)**: 104 tables (all InnoDB), 79 migrations; storage: private ~1MB, public ~1.2MB, vendor ~213MB; 0 active crons, 1 lsphp worker.

---

## 1. Phase A: Safe Read-Only Preflight & Metadata Inspection

Root executes these read-only inspection commands over SSH (`hostinger-accounting`).  
**Safety Invariants**:
- **NO Laravel application bootstrap** (avoids triggering read-write database connection pools or service provider boot).
- **NO database queries or connections** in Phase A.
- **NO passwords or secrets** in command-line arguments, process table (`ps`), or `.env` grep output.
- **Capture helper runs in safe DRY-RUN / PREFLIGHT mode by default** (0 writes, exit 0 on verified preflight).

```bash
# ------------------------------------------------------------------------------
# 1. SSH to Hostinger Account & Verify Host Tool Versions
# ------------------------------------------------------------------------------
ssh hostinger-accounting

# Check PHP 8.4 CLI binary and system tools
/opt/alt/php84/usr/bin/php -v
git --version
tar --version
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
# 5. Execute Safe Preflight Dry-Run Using Capture Utility
# ------------------------------------------------------------------------------
# bin/capture-coherent-backup.php runs in DRY-RUN mode by default (0 writes).
# It verifies .env without Laravel boot, checks tools, validates permissions, and tests isolation:
/opt/alt/php84/usr/bin/php bin/capture-coherent-backup.php
```

---

## 2. Phase B: Coherent Capture Under Quiescence with Trap Safety

> **FAILURE SAFETY & TRAP CONTRACT**  
> If backup capture fails **before** any source or build modifications, baseline availability must be restored **only if** this capture run initiated maintenance. A pre-existing or foreign maintenance guard must NEVER be reopened.

```bash
cd /home/u556956644/domains/palsync.net/accounting

# 1. Record pre-existing maintenance status
PREEXISTING_MAINTENANCE=0
if [ -f storage/framework/down ]; then
    PREEXISTING_MAINTENANCE=1
fi

# 2. Define fail-safe cleanup trap
cleanup_capture_failure() {
    local exit_code=$?
    if [ $exit_code -ne 0 ]; then
        echo "CAPTURE FAILED (exit code $exit_code)." >&2
        if [ $PREEXISTING_MAINTENANCE -eq 0 ] && [ -f storage/framework/down ]; then
            echo "Restoring baseline availability (bringing application back online)..." >&2
            /opt/alt/php84/usr/bin/php artisan up
        else
            echo "Pre-existing maintenance guard preserved; application remains held in maintenance." >&2
        fi
    fi
}
trap cleanup_capture_failure EXIT

# 3. Enter maintenance mode with unguessable operator bypass token
BYPASS_TOKEN=$(openssl rand -hex 16)
/opt/alt/php84/usr/bin/php artisan down --render="errors.503" --retry=60 --secret="$BYPASS_TOKEN"

# 4. Verify 503 response and maintenance marker (writer quiescence established)
test -f storage/framework/down || { echo "ERROR: Maintenance marker missing"; exit 1; }
curl -s -k -I https://account.palsync.net | head -n 5

# 5. Execute coherent capture helper
# Captures:
#   - database.sql (transaction-consistent mysqldump via 0600 client options, 104 InnoDB tables)
#   - source.bundle (Git bundle of baseline HEAD f8a0cf7)
#   - build_assets.tar.gz (public/build with manifest 4f7ea5b3)
#   - config.tar.gz (.env preserving app key history under 0600 + .htaccess)
#   - private_storage.tar.gz (storage/app)
#   - public_assets.tar.gz (public root excluding build)
#   - manifest.json and manifest.sha256
#   - complete_recovery_bundle.tar (all components bundled together with 0600 permissions)
/opt/alt/php84/usr/bin/php bin/capture-coherent-backup.php --execute

# 6. Capture succeeded: disarm the failure trap (maintenance remains intentionally held for deployment)
trap - EXIT

# Record point directory path from capture output
POINT_DIR=$(ls -td /home/u556956644/domains/palsync.net/accounting-backups/coherent_recovery_* | head -n 1)
echo "Coherent capture successfully established at: $POINT_DIR"
```

---

## 3. Off-Host Custody Transfer & Validation (Root Workstation)

Root executes this on their **local workstation** to establish true off-host custody:

```bash
# Executed on Root workstation:
TIMESTAMP=$(date -u +'%Y%m%d')
LOCAL_STORE="$HOME/.secure-recovery/account-palsync/$TIMESTAMP"
mkdir -p "$LOCAL_STORE" && chmod 0700 "$LOCAL_STORE"

# Transfer backup artifacts via SSH/SCP
scp -p hostinger-accounting:"$POINT_DIR/*" "$LOCAL_STORE/"

# Verify SHA-256 checksums on Root workstation
cd "$LOCAL_STORE"
sha256sum -c manifest.sha256

# Perform inert tar listing & path traversal validation
tar -tvf complete_recovery_bundle.tar
tar -tf complete_recovery_bundle.tar | grep -E '^/|\.\./' && {
    echo "REJECTED: Malformed member paths detected"; exit 1;
} || echo "Inert tar path validation: PASSED (0 absolute paths, 0 traversals)"

# Custody Invariants Verified:
# 1. Stored on Owner-custodied local drive (survives primary host loss or account suspension).
# 2. Permissions strictly 0700 directory, 0600 files.
# 3. .env and key history preserved in private custody without transmission over public networks.
```

---

## 4. Restore Verification Plan (Cleaned-Up & Bounded)

Root or QA verifies the backup using an **isolated loopback disposable schema** on `127.0.0.1:3342`:
- Schema: Dedicated freshly created disposable schema `accounting_p10_restore_<12hex>`.
- User: Dedicated temporary user `p10_restore_<12hex>` with grants restricted exclusively to that schema.
- Import: Standard external pipe (`mysql --defaults-extra-file=... < database.sql`).
- Framework Invariant: **NO credentials activation from `.env`** (no production database host/user/pass connected).
- Isolation Invariant: **NO application boot pointed at live production database**.
- Validation: Prove restored table count matches 104, migration count matches 79, and existing encrypted domain records in `public_shares` are present.

---

## 5. Candidate 8cd4322 Deployment & Reconciliations (Maintenance Held)

Once off-host backup custody is confirmed, Root proceeds to deploy candidate `8cd4322`:

```bash
cd /home/u556956644/domains/palsync.net/accounting

# 1. Fetch & checkout candidate 8cd4322 from staged bundle
git bundle verify "$STAGING_DIR/source-v1.bundle"
git fetch "$STAGING_DIR/source-v1.bundle" HEAD:candidate-8cd4322
git checkout candidate-8cd4322
git rev-parse HEAD # MUST match 8cd432220801b08b10b6f25f9ee9a215de94d44c

# 2. Extract candidate build into public root
tar -xzf "$STAGING_DIR/build.tar.gz" -C /home/u556956644/domains/palsync.net/public_html/account/

# 3. Run deploy preparation (PR 99 has 0 migrations; default skips migrations)
PHP_BIN=/opt/alt/php84/usr/bin/php ./bin/deploy.sh

# 4. Execute the 6 Domain Reconciliations While Maintenance Is Held
/opt/alt/php84/usr/bin/php artisan accounting:reconcile
/opt/alt/php84/usr/bin/php artisan inventory:reconcile --all
/opt/alt/php84/usr/bin/php artisan sales:reconcile
/opt/alt/php84/usr/bin/php artisan money:reconcile
/opt/alt/php84/usr/bin/php artisan phase7:reconcile

# Payables Reconciliation: Iterate over all active companies
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

# 5. Verify .htaccess integrity after deploy.sh
sha256sum /home/u556956644/domains/palsync.net/public_html/account/.htaccess
# MUST match: 071652f64ad14f324544e95317aed883e59a9337acb5e1b3519bf0e71a36bdcc

# 6. Verify authenticated bypass smoke
curl -s -k -I "https://account.palsync.net/$BYPASS_TOKEN"
curl -s -k -I "https://account.palsync.net/manifest.webmanifest"
```

---

## 6. Zero-Data-Loss Rollback Procedure

If any reconciliation fails or unexpected discrepancy is detected while maintenance is held:

1. **Revert Source Code**:
   ```bash
   cd /home/u556956644/domains/palsync.net/accounting
   git checkout f8a0cf7012c4875bec7320d42e0f8dde58593b52
   ```
2. **Restore Baseline Frontend Build**:
   Restore the `f8a0cf7` frontend build from `$POINT_DIR/build_assets.tar.gz` to `/home/u556956644/domains/palsync.net/public_html/account/build`:
   ```bash
   tar -xzf "$POINT_DIR/build_assets.tar.gz" -C /home/u556956644/domains/palsync.net/public_html/account/build
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
   Database was untouched (0 migrations). Re-run reconciliations to confirm complete baseline integrity.

---

## 7. Reopening Gate

Under `docs/CONTROLLED_RELEASE_CHECKLIST.md` line 42:
> *"Only after those gates pass and the Owner authorizes reopening: `/opt/alt/php84/usr/bin/php artisan up`"*

When the verified check results satisfy the existing Owner controlled release authorization:
```bash
/opt/alt/php84/usr/bin/php artisan up
curl -s -k -I https://account.palsync.net | head -n 5
```
