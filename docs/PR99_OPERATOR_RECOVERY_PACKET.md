# PR 99 Controlled Web Release: Root Operator Preflight, Coherent Capture & Recovery Runbook

> **OPERATIONAL LANE & ROLE BOUNDARY**  
> - **Preparation Lane**: Source-only recovery preparation, validation utilities, and runbook authoring.  
> - **Operator Authority**: **Root is the sole production operator.**  
> - **Agent Constraint**: Strictly source-only implementation. No remote SSH commands, no database queries/writes, no build execution, no child agents.  
> - **Release Candidate (PR 99)**: Git commit `8cd432220801b08b10b6f25f9ee9a215de94d44c` (0 migrations).  
> - **Live Production Baseline**: Git commit `f8a0cf7012c4875bec7320d42e0f8dde58593b52` (deployed on `account.palsync.net`).  
> - **Current Live State**: Availability restored to baseline `f8a0cf7` (maintenance OFF at 08:06:03Z).  
> - **Verified Production Capture Point**:  
>   `coherent_recovery_20261114_080534Z_f8a0cf7_bdcbd0448a45`  
>   Manifest SHA-256: `5d9dd7208c876a626b4ea13708b6ba2d89d3a8078edb748eb8b4bb8068e0ba02`  
>   All 7 components verified (`database.sql` 736,679 bytes, `complete_recovery_bundle.tar` 76,277,760 bytes).  
> - **Off-Host Custody**: Transferred to Owner custody at `/home/t3dev/.secure-recovery/account-palsync/` (0700 dir, 0600 files).  
> - **Actual Restore Verification**: **VERIFIED SUCCESSFUL AT 08:22:07Z** by Root on source `c4e6e6d5fcb6f7c9ea98e37455eeaf3877dc78cd`.  
>   - Owned disposable schema: `accounting_p8_tmp_6f912f8371f0` via schema-restricted ephemeral user.  
>   - Restored metrics: 104 tables, 79 migrations, 2,681 rows, structure SHA: `2fb72ba50815240d10099e6b8f2a2c9c34d86cb1518ae16dc5630badf699cdc6`.  
>   - Independent cleanup: Completed at 08:22:17Z (0 exact owned schemas or restore accounts remaining).  
>   - Private restore report: Stored at `~/.secure-recovery/account-palsync/restore-c4e6-report.json`.  
>   - Attestation Notice: Contemporaneous QA evidence is recorded as limited root attestation. PR 101 is **DISPOSABLE-ONLY** and is never run live or approved as production tooling.  
> - **Symlink Containment Notice**: Existing `public_assets ./storage` symlink targets `/home/u556956644/domains/palsync.net/accounting/storage/app/public`. It is preserved as metadata only and MUST NOT be followed or activated during isolated restore (actual data is preserved in `private_storage.tar.gz`).  
> - **Preserved Live Untracked Assets**: `storage/framework/asset-stage.Rod9Vi`, `storage/framework/deepseek-assets.cCmzPJ`, `storage/framework/deepseek-assets.fYgzxl`, `storage/framework/deploy.lock`. Strictly retain; do not prune or clean up.  

---

## 1. Phase A: Safe Read-Only Preflight & Metadata Inspection

Root executes these read-only inspection commands over SSH (`hostinger-accounting`).  
**Safety Invariants**:
- **NO Laravel application bootstrap** (avoids triggering read-write database connection pools or service provider boot).
- **NO database queries or connections** in Phase A.
- **NO passwords or secrets** in command-line arguments, process table (`ps`), or `.env` grep output.
- **Zero-write dry-run preflight**: Helper validates tools, paths, pins, `.env`, and permissions with 0 disk mutations.
- **Operator private deployment directory**: The capture utility is staged in the private operator staging directory (`$STAGING_DIR/bin/capture-coherent-backup.php`) rather than assuming it is already live in baseline `bin/`.

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

# Confirm maintenance mode status (MUST NOT have pre-existing down marker before capture)
test -f storage/framework/down && echo "STATUS: MAINTENANCE_ACTIVE (Refuse capture until clean state)" || echo "STATUS: MAINTENANCE_OFF (Clean)"

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

> **FAILURE SAFETY & QUIESCENCE CONTRACT**  
> - Uses `set -euo pipefail` so failed commands exit immediately.  
> - **Pre-existing Maintenance Refusal**: If `storage/framework/down` already exists before capture, abort immediately. A clean quiescence transition is mandatory.  
> - Baseline availability is restored **only if** this capture run initiated maintenance.  
> - A pre-existing or foreign maintenance guard is NEVER overwritten or reopened.  
> - Before disarming maintenance, Root verifies the down marker hash matches the expected hash.  

```bash
set -euo pipefail
cd /home/u556956644/domains/palsync.net/accounting
STAGING_DIR="/home/u556956644/domains/palsync.net/accounting-release-staging/pr99-8cd4322"

# 1. Refuse pre-existing maintenance: must start from clean quiescence transition
if [ -f storage/framework/down ]; then
    echo "ERROR: Refusing capture: pre-existing maintenance marker detected. A clean quiescence transition is required." >&2
    exit 1
fi

# 2. Define fail-safe cleanup trap
cleanup_capture_failure() {
    local exit_code=$?
    if [ $exit_code -ne 0 ]; then
        echo "CAPTURE FAILED (exit code $exit_code)." >&2
        if [ -f storage/framework/down ]; then
            echo "Restoring baseline availability..." >&2
            /opt/alt/php84/usr/bin/php artisan up
        fi
    fi
}
trap cleanup_capture_failure EXIT

# 3. Enter maintenance mode with unguessable operator bypass token
BYPASS_TOKEN=$(openssl rand -hex 16)
/opt/alt/php84/usr/bin/php artisan down --render="errors.503" --retry=60 --secret="$BYPASS_TOKEN"

# Record root-owned marker hash
EXPECTED_DOWN_HASH=$(sha256sum storage/framework/down | awk '{print $1}')

# 4. Verify 503 response and maintenance marker
test -f storage/framework/down || { echo "ERROR: Maintenance marker missing"; exit 1; }
HTTP_STATUS=$(curl -s -o /dev/null -w "%{http_code}" https://account.palsync.net)
if [ "$HTTP_STATUS" != "503" ]; then
    echo "ERROR: Expected HTTP 503 maintenance response, got $HTTP_STATUS" >&2
    exit 1
fi

# 5. Execute coherent capture helper with mandatory pins and execute flag
CAPTURE_OUTPUT=$(/opt/alt/php84/usr/bin/php "$STAGING_DIR/bin/capture-coherent-backup.php" \
    --execute \
    --app-dir=/home/u556956644/domains/palsync.net/accounting \
    --public-dir=/home/u556956644/domains/palsync.net/public_html/account \
    --target-dir=/home/u556956644/domains/palsync.net/accounting-backups \
    --expected-source-sha=f8a0cf7012c4875bec7320d42e0f8dde58593b52 \
    --expected-build-sha=4f7ea5b3a956aca1eaf120c0ee67d499b9fc91854f672c0db32bdceee4ac96ab \
    --expected-htaccess-sha=071652f64ad14f324544e95317aed883e59a9337acb5e1b3519bf0e71a36bdcc)

# 6. Parse exact point directory path from structured JSON output
POINT_DIR=$(/opt/alt/php84/usr/bin/php -r '
    $data = json_decode($argv[1], true);
    if (!isset($data["status"]) || $data["status"] !== "SUCCESS" || !isset($data["point_dir"])) {
        exit(1);
    }
    echo $data["point_dir"];
' "$CAPTURE_OUTPUT")

test -d "$POINT_DIR" || { echo "ERROR: Captured point directory not found"; exit 1; }
test -f "$POINT_DIR/complete_recovery_bundle.tar" || { echo "ERROR: Recovery bundle missing"; exit 1; }

# 7. Disarm failure trap on verified capture success
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
```

---

## 4. Phase C: Bounded Restore Verification (Executed & Passed)

> **RESTORATION PROOF ATTESTATION**  
> - Executed by Root on 2026-11-14 at 08:22:07Z.  
> - Verified on MariaDB loopback socket `127.0.0.1:3342`.  
> - Disposable Schema: `accounting_p8_tmp_6f912f8371f0` with ephemeral user.  
> - Imported dump verified: 104 tables, 79 migrations, 2,681 rows, structure SHA: `2fb72ba50815240d10099e6b8f2a2c9c34d86cb1518ae16dc5630badf699cdc6`.  
> - Cleanup: Fully dropped and verified absent at 08:22:17Z.  

For subsequent verification runs:
```bash
export APP_ENV=testing
export PHASE8_ALLOW_DISPOSABLE_DB=1
export PHASE8_TEST_DB_HOST=127.0.0.1
export PHASE8_TEST_DB_PORT=3342
export PHASE8_TEST_DB_USERNAME="root"
export PHASE8_TEST_DB_PASSWORD=""

# Preflight dry-run (validates point perms, SQL safety, manifest hashes, 0 mutations)
php bin/verify-backup-import.php \
    --point-dir="$LOCAL_STORE" \
    --expected-manifest-sha=5d9dd7208c876a626b4ea13708b6ba2d89d3a8078edb748eb8b4bb8068e0ba02 \
    --dry-run

# Execute disposable schema import & sanitized metric verification
php bin/verify-backup-import.php \
    --point-dir="$LOCAL_STORE" \
    --expected-manifest-sha=5d9dd7208c876a626b4ea13708b6ba2d89d3a8078edb748eb8b4bb8068e0ba02 \
    --execute
```

---

## 5. Phase D: Read-Only Pre-Release Snapshot & Reconciliation Verification

Before modifying production code, Root verifies service contracts, runs the 6 core reconciliation services context-free, and captures a deterministic baseline database fingerprint:

```bash
cd /home/u556956644/domains/palsync.net/accounting

# 1. Zero-socket / zero-connection dry-run preflight (default without --execute)
php bin/final-release-check.php \
    --mode=snapshot \
    --app-dir=/home/u556956644/domains/palsync.net/accounting \
    --public-dir=/home/u556956644/domains/palsync.net/public_html/account \
    --expected-source-sha=f8a0cf7012c4875bec7320d42e0f8dde58593b52 \
    --expected-build-sha=4f7ea5b3a956aca1eaf120c0ee67d499b9fc91854f672c0db32bdceee4ac96ab \
    --expected-htaccess-sha=071652f64ad14f324544e95317aed883e59a9337acb5e1b3519bf0e71a36bdcc

# 2. Verify reflection signatures of all 6 reconciliation services
php bin/final-release-check.php \
    --mode=reconcile-contracts \
    --app-dir=/home/u556956644/domains/palsync.net/accounting \
    --public-dir=/home/u556956644/domains/palsync.net/public_html/account

# 3. Execute all 6 reconciliation services context-free across all companies on SAME read-only PDO
# (Runs within START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY; rolls back cleanly)
php bin/final-release-check.php \
    --mode=reconcile \
    --app-dir=/home/u556956644/domains/palsync.net/accounting \
    --public-dir=/home/u556956644/domains/palsync.net/public_html/account \
    --expected-source-sha=f8a0cf7012c4875bec7320d42e0f8dde58593b52 \
    --expected-build-sha=4f7ea5b3a956aca1eaf120c0ee67d499b9fc91854f672c0db32bdceee4ac96ab \
    --expected-htaccess-sha=071652f64ad14f324544e95317aed883e59a9337acb5e1b3519bf0e71a36bdcc \
    --output-file=/home/u556956644/domains/palsync.net/accounting-backups/baseline_reconcile_report.json \
    --execute

# 4. Capture read-only baseline database fingerprint (PK-ordered, length-framed, normalized DDL)
# (Preserves AUTO_INCREMENT on preservation tables; MariaDB max_statement_time=5s)
php bin/final-release-check.php \
    --mode=snapshot \
    --app-dir=/home/u556956644/domains/palsync.net/accounting \
    --public-dir=/home/u556956644/domains/palsync.net/public_html/account \
    --expected-source-sha=f8a0cf7012c4875bec7320d42e0f8dde58593b52 \
    --expected-build-sha=4f7ea5b3a956aca1eaf120c0ee67d499b9fc91854f672c0db32bdceee4ac96ab \
    --expected-htaccess-sha=071652f64ad14f324544e95317aed883e59a9337acb5e1b3519bf0e71a36bdcc \
    --output-file=/home/u556956644/domains/palsync.net/accounting-backups/baseline_fingerprint.json \
    --execute
```

---

## 6. Phase E: Candidate 8cd4322 Deployment & Fingerprint Verification (Maintenance Held)

Root executes candidate deployment while maintenance mode is held:

```bash
set -euo pipefail
cd /home/u556956644/domains/palsync.net/accounting
STAGING_DIR="/home/u556956644/domains/palsync.net/accounting-release-staging/pr99-8cd4322"
PUBLIC_DIR="/home/u556956644/domains/palsync.net/public_html/account"

# 1. Fetch Candidate Commit from Verified Bundle
git fetch "$STAGING_DIR/source-v1.bundle" refs/heads/release-candidate:refs/remotes/bundle/pr99
git checkout 8cd432220801b08b10b6f25f9ee9a215de94d44c

# Verify git HEAD
test "$(git rev-parse HEAD)" = "8cd432220801b08b10b6f25f9ee9a215de94d44c" || {
    echo "ERROR: HEAD mismatch after checkout"; exit 1;
}

# 2. Atomic Build Directory Replacement (Inspecting tar layout to prevent double nesting)
BUILD_STAGE=$(mktemp -d "$PUBLIC_DIR/.build-candidate-XXXXXXXX")
tar -xzf "$STAGING_DIR/build.tar.gz" -C "$BUILD_STAGE"
find "$BUILD_STAGE" -type d -exec chmod 0755 {} +
find "$BUILD_STAGE" -type f -exec chmod 0644 {} +

if [ -d "$BUILD_STAGE/build" ] && [ -f "$BUILD_STAGE/build/manifest.json" ]; then
    CANDIDATE_BUILD_DIR="$BUILD_STAGE/build"
else
    CANDIDATE_BUILD_DIR="$BUILD_STAGE"
fi

# Preserve old build in operator custody before swapping:
OLD_BUILD_CUSTODY="/home/u556956644/domains/palsync.net/accounting-backups/previous_build_$(date -u +%Y%m%d_%H%M%S)"
mv "$PUBLIC_DIR/build" "$OLD_BUILD_CUSTODY"
mv "$CANDIDATE_BUILD_DIR" "$PUBLIC_DIR/build"
rm -rf "$BUILD_STAGE"

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
        echo "Company {$company->id}: Discrepancies={$report->discrepancyCount}\n";
    }
'

# 5. Clear application caches and re-cache
/opt/alt/php84/usr/bin/php artisan optimize:clear
/opt/alt/php84/usr/bin/php artisan config:cache
/opt/alt/php84/usr/bin/php artisan route:cache
/opt/alt/php84/usr/bin/php artisan view:cache

# 6. Post-Deployment Fingerprint Snapshot & Preservation Comparison
POST_FINGERPRINT="/home/u556956644/domains/palsync.net/accounting-backups/post_fingerprint.json"
php bin/final-release-check.php \
    --mode=snapshot \
    --app-dir=/home/u556956644/domains/palsync.net/accounting \
    --public-dir=/home/u556956644/domains/palsync.net/public_html/account \
    --expected-source-sha=8cd432220801b08b10b6f25f9ee9a215de94d44c \
    --expected-build-sha=12cee9f9dc2213615ff19d48060308c1a89030f255e694c530083538bd15fedd \
    --expected-htaccess-sha=071652f64ad14f324544e95317aed883e59a9337acb5e1b3519bf0e71a36bdcc \
    --output-file="$POST_FINGERPRINT" \
    --execute

# Compare baseline vs post-deployment fingerprint (must have 0 preservation diffs)
php bin/final-release-check.php \
    --mode=compare \
    --baseline=/home/u556956644/domains/palsync.net/accounting-backups/baseline_fingerprint.json \
    --target="$POST_FINGERPRINT" \
    --app-dir=/home/u556956644/domains/palsync.net/accounting \
    --public-dir=/home/u556956644/domains/palsync.net/public_html/account \
    --expected-source-sha=8cd432220801b08b10b6f25f9ee9a215de94d44c \
    --expected-build-sha=12cee9f9dc2213615ff19d48060308c1a89030f255e694c530083538bd15fedd \
    --expected-htaccess-sha=071652f64ad14f324544e95317aed883e59a9337acb5e1b3519bf0e71a36bdcc \
    --execute

# 7. Verify Down Marker Hash Before Bringing Application Back Online
CURRENT_DOWN_HASH=$(sha256sum storage/framework/down | awk '{print $1}')
if [ "$CURRENT_DOWN_HASH" != "$EXPECTED_DOWN_HASH" ]; then
    echo "ERROR: Maintenance marker hash modified during deployment; aborting unguided release" >&2
    exit 1
fi

# Bring application back online
/opt/alt/php84/usr/bin/php artisan up

# 8. Post-Deployment Verification (Strictly non-destructive TLS check)
curl -fsS https://account.palsync.net/up
echo "RELEASE DEPLOYMENT AND VERIFICATION COMPLETED SUCCESSFULLY"
```
