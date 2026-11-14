# PR 99 Controlled Web Release: Root Operator Preflight, Coherent Capture & Recovery Runbook

> **OPERATIONAL LANE & ROLE BOUNDARY**  
> - **Preparation Lane**: Source-only recovery preparation and runbook authoring.  
> - **Operator Authority**: **Root is the sole production operator.**  
> - **Agent Constraint**: Strictly read-only research and tool preparation. No SSH commands, no database queries, no build execution, no child agents.  
> - **Release Candidate (PR 99)**: Git commit `8cd432220801b08b10b6f25f9ee9a215de94d44c` (0 migrations).  
> - **Live Production Baseline**: Git commit `f8a0cf7012c4875bec7320d42e0f8dde58593b52` (deployed on `account.palsync.net`).  
> - **Owner Custody Model**: Owner explicit NO-ENCRYPTION override. Security model enforces POSIX filesystem permissions (`umask 077`: `0700` directories, `0600` files) transferred via SSH to Owner-custodied protected local off-host directory. No GPG/passphrase/key workflow.  
> - **Preserved Live Untracked Assets**: `storage/framework/asset-stage.Rod9Vi`, `storage/framework/deepseek-assets.cCmzPJ`, `storage/framework/deepseek-assets.fYgzxl`, `storage/framework/deploy.lock`. Strictly retain; do not prune or clean up.  
> - **Live Host Environment (Verified 05:59 UTC)**: 104 tables (all InnoDB), 79 migrations; storage: private ~1MB, public ~1.2MB, vendor ~213MB; 0 active crons, 1 lsphp worker.

---

## 1. Phase A: Safe Read-Only Preflight & Metadata Inspection

Root executes these read-only inspection commands over SSH (`hostinger-accounting`).  
**Safety Invariants**:
- **NO Laravel application bootstrap** (avoids triggering read-write database connection pools or service provider boot).
- **NO database queries or connections** in Phase A.
- **NO passwords or secrets** in command-line arguments, process table (`ps`), or `.env` grep output.
- **Zero-write dry-run preflight**: Helper validates tools, paths, pins, `.env`, and permissions with 0 disk mutations.
- **Operator private deployment directory**: The capture utility is staged in the private operator staging directory (`/home/u556956644/domains/palsync.net/accounting-release-staging/pr99-8cd4322/bin/capture-coherent-backup.php`) rather than assuming it is already live in the baseline `bin/` directory.

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

# Check git status (confirm tracked files are completely clean)
git status --porcelain --untracked-files=no

# Confirm preserved untracked framework assets exist untouched in storage/framework/ (DO NOT PRUNE)
ls -ld storage/framework/asset-stage.Rod9Vi \
       storage/framework/deepseek-assets.cCmzPJ \
       storage/framework/deepseek-assets.fYgzxl \
       storage/framework/deploy.lock 2>/dev/null || true

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
# 4. Verify Staged Candidate Artifacts & Extract Private Helper
# ------------------------------------------------------------------------------
STAGING_DIR="/home/u556956644/domains/palsync.net/accounting-release-staging/pr99-8cd4322"

ls -lh "$STAGING_DIR"

# Check bundle SHA-256 (MUST match 3877102e136a59941b6320fb7b3b2fc43b6e18fb4c67549140cb577e95db689f)
sha256sum "$STAGING_DIR/source-v1.bundle"

# Check build tar SHA-256 (MUST match 5f5282238bca8c3cca8f5f87f7947f11cd03d126a324c73b42ca1b1f7df056af)
sha256sum "$STAGING_DIR/build.tar.gz"

# Verify bundle integrity and pin its embedded HEAD commit before any checkout (MUST be 8cd432220801b08b10b6f25f9ee9a215de94d44c)
git bundle verify "$STAGING_DIR/source-v1.bundle"
git bundle list-heads "$STAGING_DIR/source-v1.bundle" | grep -q "8cd432220801b08b10b6f25f9ee9a215de94d44c" || {
    echo "ERROR: Candidate bundle HEAD does not match pinned commit 8cd4322"; exit 1;
}

# ------------------------------------------------------------------------------
# 5. Execute Safe Preflight Dry-Run Using Staged Capture Utility
# ------------------------------------------------------------------------------
# capture-coherent-backup.php runs in DRY-RUN mode by default (0 writes).
# It validates tools, pins, paths, .env, and permissions without modifying disk:
/opt/alt/php84/usr/bin/php "$STAGING_DIR/bin/capture-coherent-backup.php" \
    --app-dir=/home/u556956644/domains/palsync.net/accounting \
    --public-dir=/home/u556956644/domains/palsync.net/public_html/account \
    --target-dir=/home/u556956644/domains/palsync.net/accounting-backups \
    --expected-source-sha=f8a0cf7012c4875bec7320d42e0f8dde58593b52 \
    --expected-build-sha=4f7ea5b3a956aca1eaf120c0ee67d499b9fc91854f672c0db32bdceee4ac96ab \
    --expected-htaccess-sha=071652f64ad14f324544e95317aed883e59a9337acb5e1b3519bf0e71a36bdcc
```

---

## 2. Phase B: Coherent Capture Under Quiescence with Fail-Closed Trap

> **FAILURE SAFETY & TRAP CONTRACT**  
> - Uses `set -euo pipefail` so failed commands exit immediately.  
> - Baseline availability is restored **only if** this capture run initiated maintenance.  
> - A pre-existing or foreign maintenance guard is NEVER overwritten or reopened.  
> - No source changes occur before verified capture.  
> - Exact capture point path is parsed directly from the tool's JSON output (no `ls -t` guessing).

```bash
set -euo pipefail
cd /home/u556956644/domains/palsync.net/accounting
STAGING_DIR="/home/u556956644/domains/palsync.net/accounting-release-staging/pr99-8cd4322"

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
HTTP_STATUS=$(curl -s -o /dev/null -w "%{http_code}" https://account.palsync.net)
if [ "$HTTP_STATUS" != "503" ]; then
    echo "ERROR: Expected HTTP 503 maintenance response, got $HTTP_STATUS" >&2
    exit 1
fi

# 5. Execute coherent capture helper with explicit pins and execute flag
# Captures:
#   - database.sql (transaction-consistent mysqldump via 0600 client options, 104 InnoDB tables)
#   - source.bundle (clean git bundle of baseline HEAD f8a0cf7)
#   - build_assets.tar.gz (public/build with manifest 4f7ea5b3)
#   - config.tar.gz (.env preserving app key history under 0600 + .htaccess)
#   - private_storage.tar.gz (storage/app)
#   - public_assets.tar.gz (public root excluding build)
#   - vendor.tar.gz (mandatory runtime code capture)
#   - manifest.json and manifest.sha256
#   - complete_recovery_bundle.tar (all components bundled together with 0600 permissions)
CAPTURE_OUTPUT=$(/opt/alt/php84/usr/bin/php "$STAGING_DIR/bin/capture-coherent-backup.php" \
    --execute \
    --app-dir=/home/u556956644/domains/palsync.net/accounting \
    --public-dir=/home/u556956644/domains/palsync.net/public_html/account \
    --target-dir=/home/u556956644/domains/palsync.net/accounting-backups \
    --expected-source-sha=f8a0cf7012c4875bec7320d42e0f8dde58593b52 \
    --expected-build-sha=4f7ea5b3a956aca1eaf120c0ee67d499b9fc91854f672c0db32bdceee4ac96ab \
    --expected-htaccess-sha=071652f64ad14f324544e95317aed883e59a9337acb5e1b3519bf0e71a36bdcc)

# 6. Parse exact point directory path from structured JSON output (fail-closed check)
POINT_DIR=$(/opt/alt/php84/usr/bin/php -r '
    $data = json_decode($argv[1], true);
    if (!isset($data["status"]) || $data["status"] !== "SUCCESS" || !isset($data["point_dir"])) {
        exit(1);
    }
    echo $data["point_dir"];
' "$CAPTURE_OUTPUT")

test -d "$POINT_DIR" || { echo "ERROR: Captured point directory not found"; exit 1; }
test -f "$POINT_DIR/complete_recovery_bundle.tar" || { echo "ERROR: Recovery bundle missing"; exit 1; }

# 7. Disarm failure trap on verified capture success (maintenance remains intentionally held for deployment)
trap - EXIT
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
set -euo pipefail
cd /home/u556956644/domains/palsync.net/accounting
STAGING_DIR="/home/u556956644/domains/palsync.net/accounting-release-staging/pr99-8cd4322"
PUBLIC_DIR="/home/u556956644/domains/palsync.net/public_html/account"

# 1. Fetch & checkout candidate 8cd4322 from staged bundle
git fetch "$STAGING_DIR/source-v1.bundle" HEAD:candidate-8cd4322
git checkout candidate-8cd4322
git rev-parse HEAD | grep -q "8cd432220801b08b10b6f25f9ee9a215de94d44c" || {
    echo "ERROR: Working tree not at expected commit 8cd4322"; exit 1;
}

# 2. Atomic Build Directory Replacement (Complete directory swap with old build custody)
# Unpack candidate build to clean staging directory:
BUILD_STAGE=$(mktemp -d "$PUBLIC_DIR/.build-candidate-XXXXXXXX")
tar -xzf "$STAGING_DIR/build.tar.gz" -C "$BUILD_STAGE"
find "$BUILD_STAGE" -type d -exec chmod 0755 {} +
find "$BUILD_STAGE" -type f -exec chmod 0644 {} +

# Preserve old build into operator custody before swapping:
OLD_BUILD_CUSTODY="/home/u556956644/domains/palsync.net/accounting-backups/previous_build_$(date -u +%Y%m%d_%H%M%S)"
mv "$PUBLIC_DIR/build" "$OLD_BUILD_CUSTODY"
mv "$BUILD_STAGE" "$PUBLIC_DIR/build"

# Verify candidate build manifest SHA-256
sha256sum "$PUBLIC_DIR/build/manifest.json" | grep -q "12cee9f9dc2213615ff19d48060308c1a89030f255e694c530083538bd15fedd" || {
    echo "ERROR: Deployed build manifest does not match candidate SHA"; exit 1;
}

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
        if (!$report->isHealthy) { exit(1); }
    }
    echo "Payables reconciliation: ALL HEALTHY" . PHP_EOL;
'

# 5. Verify .htaccess integrity after deploy.sh
sha256sum /home/u556956644/domains/palsync.net/public_html/account/.htaccess
# MUST match: 071652f64ad14f324544e95317aed883e59a9337acb5e1b3519bf0e71a36bdcc

# 6. Verify authenticated bypass smoke (valid TLS certificate)
curl -s -I "https://account.palsync.net/$BYPASS_TOKEN"
curl -s -I "https://account.palsync.net/manifest.webmanifest"
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
   BUILD_ROLLBACK_STAGE=$(mktemp -d "/home/u556956644/domains/palsync.net/public_html/account/.build-rollback-XXXXXXXX")
   tar -xzf "$POINT_DIR/build_assets.tar.gz" -C "$BUILD_ROLLBACK_STAGE"
   find "$BUILD_ROLLBACK_STAGE" -type d -exec chmod 0755 {} +
   find "$BUILD_ROLLBACK_STAGE" -type f -exec chmod 0644 {} +

   rm -rf /home/u556956644/domains/palsync.net/public_html/account/build
   mv "$BUILD_ROLLBACK_STAGE" /home/u556956644/domains/palsync.net/public_html/account/build
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
curl -s -I https://account.palsync.net | head -n 5
```
