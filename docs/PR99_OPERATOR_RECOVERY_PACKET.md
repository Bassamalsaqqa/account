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

## 2. Phase B: Proposed Coherent Capture & Recovery Runbook

> [!WARNING]
> ### STATUS: PROPOSED DESIGN / NOT EXECUTABLE UNTIL ROOT REVIEW
> The following commands represent the reviewed recovery architecture and capture procedure.  
> **DO NOT EXECUTE** until Root completes Phase A preflight, coordinates non-secret off-host custodian storage with the Owner, and explicitly authorizes the maintenance capture window.

### Quiescence Strategy
A transaction-consistent backup cannot be obtained while web traffic executes database writes.  
1. Root enters maintenance mode via the currently deployed codebase.
2. HTTP requests return `503 Service Unavailable` with an operator bypass token.
3. Database writes quiesce before taking the SQL dump.
4. Database, storage files, configuration metadata, and real encrypted DB records are captured in the same quiet maintenance window.

```bash
# ==============================================================================
# STEP B.1: Enter Maintenance Mode (Under Reviewed Quiescence)
# ==============================================================================
cd /home/u556956644/domains/palsync.net/accounting

# Generate an unguessable operator bypass token for authenticated verification
BYPASS_TOKEN=$(openssl rand -hex 16)

# Enter maintenance mode using current deployed code (no new code loaded yet)
/opt/alt/php84/usr/bin/php artisan down --render="errors.503" --retry=60 --secret="$BYPASS_TOKEN"

# Verify marker and public 503 response
test -f storage/framework/down && echo "Maintenance marker: PRESENT"
curl -s -k -I https://account.palsync.net | head -n 5

# ==============================================================================
# STEP B.2: Create Restricted Working Directory & Ingest Backup Passphrase
# ==============================================================================
TIMESTAMP=$(date -u +'%Y%m%d_%H%M%SZ')
POINT_ID="coherent_recovery_${TIMESTAMP}_f8a0cf7"
BACKUP_DIR="/home/u556956644/domains/palsync.net/accounting-backups/${POINT_ID}"

mkdir -p "$BACKUP_DIR"
chmod 0700 "$BACKUP_DIR"

# Securely prompt operator for backup encryption passphrase into a temporary 0600 file
# Passphrase NEVER appears in command arguments or shell history
PASSPHRASE_FILE="$BACKUP_DIR/.passphrase"
trap 'rm -f "$PASSPHRASE_FILE" "$BACKUP_DIR/.my.cnf"' EXIT
read -s -p "Enter dedicated backup encryption passphrase: " B_PASS
echo "$B_PASS" > "$PASSPHRASE_FILE"
chmod 0600 "$PASSPHRASE_FILE"
unset B_PASS
echo ""

# ==============================================================================
# STEP B.3: Coherent Capture of the 6 Components
# ==============================================================================

# ------------------------------------------------------------------------------
# Component 1: Consistent SQL Dump (Single Transaction, Credentials in 0600 file)
# ------------------------------------------------------------------------------
# Create temporary 0600 MySQL options file (NEVER pass -pPassword on command line)
MYSQL_CNF="$BACKUP_DIR/.my.cnf"
/opt/alt/php84/usr/bin/php -r '
    $env = file_get_contents(".env");
    $get = function($k) use ($env) {
        return preg_match("/^" . preg_quote($k, "/") . "=(.*)$/m", $env, $m) ? trim($m[1], "\"'\r\n") : "";
    };
    $cnf = "[client]\n"
         . "host=" . ($get("DB_HOST") ?: "127.0.0.1") . "\n"
         . "port=" . ($get("DB_PORT") ?: "3306") . "\n"
         . "user=" . $get("DB_USERNAME") . "\n"
         . "password=" . $get("DB_PASSWORD") . "\n";
    file_put_contents("'$MYSQL_CNF'", $cnf);
'
chmod 0600 "$MYSQL_CNF"
DB_NAME=$(/opt/alt/php84/usr/bin/php -r '$env=file_get_contents(".env"); preg_match("/^DB_DATABASE=(.*)$/m",$env,$m); echo trim($m[1],"\"'\r\n");')

mysqldump --defaults-extra-file="$MYSQL_CNF" \
    --single-transaction \
    --quick \
    --routines \
    --triggers \
    --hex-blob \
    "$DB_NAME" > "$BACKUP_DIR/database.sql"
chmod 0600 "$BACKUP_DIR/database.sql"
rm -f "$MYSQL_CNF"

# ------------------------------------------------------------------------------
# Component 2: Code and Build (Exact deployed source & build manifest 4f7ea5b3)
# ------------------------------------------------------------------------------
tar -czf "$BACKUP_DIR/code_build.tar.gz" \
    --exclude="storage" \
    --exclude="vendor" \
    --exclude=".git" \
    -C /home/u556956644/domains/palsync.net/accounting . \
    -C /home/u556956644/domains/palsync.net/public_html/account build

# ------------------------------------------------------------------------------
# Component 3: Private Storage Files
# ------------------------------------------------------------------------------
tar -czf "$BACKUP_DIR/private_files.tar.gz" \
    -C /home/u556956644/domains/palsync.net/accounting storage/app

# ------------------------------------------------------------------------------
# Component 4: Public Assets (Uploads, logos, web configuration, excluding build)
# ------------------------------------------------------------------------------
tar -czf "$BACKUP_DIR/public_assets.tar.gz" \
    --exclude="account/build" \
    -C /home/u556956644/domains/palsync.net/public_html account

# ------------------------------------------------------------------------------
# Component 5: Real Encrypted Database Records (Actual public_shares / issued records)
# ------------------------------------------------------------------------------
# Export actual encrypted records dependent on Laravel APP_KEY (NO dummy files)
/opt/alt/php84/usr/bin/php -r '
    require "vendor/autoload.php";
    $app = require_once "bootstrap/app.php";
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();
    
    // Dump actual encrypted records from database (public_shares table)
    $records = \Illuminate\Support\Facades\DB::table("public_shares")
        ->select(["public_id", "access_profile", "content_version", "encrypted_snapshot", "content_hash", "encrypted_token", "created_at"])
        ->get();
    file_put_contents("'$BACKUP_DIR'/encrypted_records.json", json_encode($records, JSON_PRETTY_PRINT));
'
chmod 0600 "$BACKUP_DIR/encrypted_records.json"
tar -czf "$BACKUP_DIR/encrypted_records.tar.gz" -C "$BACKUP_DIR" encrypted_records.json
rm -f "$BACKUP_DIR/encrypted_records.json"

# ------------------------------------------------------------------------------
# Component 6: Configuration Metadata Reference (Sanitized, NO plaintext secrets)
# ------------------------------------------------------------------------------
/opt/alt/php84/usr/bin/php -r '
    require "vendor/autoload.php";
    $app = require_once "bootstrap/app.php";
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();
    
    $key = config("app.key");
    $meta = [
        "captured_at" => gmdate("Y-m-d\TH:i:s\Z"),
        "code_sha" => trim(shell_exec("git rev-parse HEAD")),
        "php_version" => PHP_VERSION,
        "app_key_ref" => substr(hash("sha256", (string)$key), 0, 16),
        "db_connection" => config("database.default"),
        "tables_count" => count(\Illuminate\Support\Facades\DB::select("SHOW TABLES")),
        "migrations_count" => \Illuminate\Support\Facades\DB::table("migrations")->count()
    ];
    file_put_contents("'$BACKUP_DIR'/configuration.json", json_encode($meta, JSON_PRETTY_PRINT));
'
chmod 0600 "$BACKUP_DIR/configuration.json"

# ==============================================================================
# STEP B.4: Authenticated Encryption (AES-256 with HMAC-SHA256 Authentication)
# ==============================================================================
# Encrypt each component with AES-256 and compute an HMAC-SHA256 authentication tag
# Passphrase read from protected file (-pass file:...) NEVER from process arguments
for comp in database.sql code_build.tar.gz private_files.tar.gz public_assets.tar.gz encrypted_records.tar.gz configuration.json; do
    openssl enc -aes-256-cbc -salt -pbkdf2 -iter 100000 \
        -in "$BACKUP_DIR/$comp" \
        -out "$BACKUP_DIR/${comp}.enc" \
        -pass file:"$PASSPHRASE_FILE"
    chmod 0600 "$BACKUP_DIR/${comp}.enc"
    
    # Compute HMAC authentication tag
    openssl dgst -sha256 -hmac file:"$PASSPHRASE_FILE" "$BACKUP_DIR/${comp}.enc" > "$BACKUP_DIR/${comp}.enc.hmac"
    chmod 0600 "$BACKUP_DIR/${comp}.enc.hmac"
    
    # Wipe plaintext immediately
    rm -f "$BACKUP_DIR/$comp"
done

# Wipe passphrase file
rm -f "$PASSPHRASE_FILE"

# Generate SHA-256 checksum manifest
cd "$BACKUP_DIR"
sha256sum *.enc *.hmac > manifest.sha256
chmod 0600 manifest.sha256

echo "Coherent encrypted capture completed: $BACKUP_DIR"
cat manifest.sha256
```

---

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
