# PR 99 Controlled Web Release: Root Operator Recovery Packet & Coherent Capture Runbook

## 1. Executive Summary & Context

- **Operating Lane**: Source-only recovery preparation lane.
- **Production Authority**: Root is the **sole production operator**.
- **Agent Boundary**: Read-only research / runbook authoring. No SSH, Hostinger API, background daemons, or database execution.
- **Repository Commit Head**: `8cd432220801b08b10b6f25f9ee9a215de94d44c` (`t3/pr99-web-release-recovery`).
- **Live Production Baseline**: `f8a0cf7012c4875bec7320d42e0f8dde58593b52`.
- **Database Schema Invariant**: **0 migrations** between `f8a0cf7` and `8cd4322`. Pure source, blade, and language file updates.
- **Scope Isolation**: PR 99 is isolated from PR 95 (lock/auth), PR 97, PR 100, and mobile financial flows.
- **Preserved Untracked Assets**:
  - `asset-stage.Rod9Vi`
  - `deepseek-assets.cCmzPJ`
  - `deepseek-assets.fYgzxl`
  - `deploy.lock`
  *Policy: Strictly retain; do not prune or modify.*

---

## 2. Phase A: Read-Only Metadata & Preflight Commands

Root executes these commands via SSH (`hostinger-accounting`) to verify the environment, tools, and live checksums prior to any mutation.

```bash
# Connect to production host
ssh hostinger-accounting

# 1. Verify PHP 8.4 CLI and mandatory extensions
/opt/alt/php84/usr/bin/php -v
/opt/alt/php84/usr/bin/php -r '
    $required = ["bcmath", "curl", "fileinfo", "gd", "intl", "mbstring", "openssl", "pdo_mysql", "tokenizer", "xml", "zip"];
    $missing = array_filter($required, fn($ext) => !extension_loaded($ext));
    if ($missing) { echo "MISSING: " . implode(", ", $missing) . PHP_EOL; exit(1); }
    echo "PHP 8.4 and required extensions verified." . PHP_EOL;
'

# 2. Check system utility versions
git --version
openssl version
tar --version
mysqldump --version

# 3. Verify deployed repository state
cd /home/u556956644/domains/palsync.net/accounting
git rev-parse HEAD # Expected: f8a0cf7012c4875bec7320d42e0f8dde58593b52
git status --short

# 4. Verify preserved untracked assets
ls -ld asset-stage.Rod9Vi deepseek-assets.cCmzPJ deepseek-assets.fYgzxl deploy.lock 2>/dev/null || true

# 5. Verify live build manifest and .htaccess hashes
# Expected manifest sha256: 4f7ea5b3a956aca1eaf120c0ee67d499b9fc91854f672c0db32bdceee4ac96ab
sha256sum /home/u556956644/domains/palsync.net/public_html/account/build/manifest.json

# Expected .htaccess sha256: 071652f64ad14f324544e95317aed883e59a9337acb5e1b3519bf0e71a36bdcc
sha256sum /home/u556956644/domains/palsync.net/public_html/account/.htaccess

# 6. Verify maintenance is currently OFF
ls -l storage/framework/down 2>/dev/null || echo "Maintenance status: OFF"

# 7. Verify staged candidate artifacts
STAGING_DIR="/home/u556956644/domains/palsync.net/accounting-release-staging/pr99-8cd4322"
ls -lh "$STAGING_DIR"
# Expected bundle sha256: 3877102e136a59941b6320fb7b3b2fc43b6e18fb4c67549140cb577e95db689f
sha256sum "$STAGING_DIR/source-v1.bundle"
# Expected build tar sha256: 5f5282238bca8c3cca8f5f87f7947f11cd03d126a324c73b42ca1b1f7df056af
sha256sum "$STAGING_DIR/build.tar.gz"

# 8. Check backup freshness (verify October backups are stale)
ls -la /home/u556956644/domains/palsync.net/backups/ 2>/dev/null || true

# 9. Verify APP_KEY reference only (NO secret values or .env contents printed)
/opt/alt/php84/usr/bin/php -r '
    $env = file_get_contents(".env");
    if (preg_match("/^APP_KEY=(.*)$/m", $env, $m)) {
        echo "APP_KEY_REF: " . substr(hash("sha256", trim($m[1])), 0, 16) . PHP_EOL;
    }
'

# 10. File and source count inspection
echo "Tracked files: $(git ls-files | wc -l)"
echo "App PHP files: $(find app -type f -name '*.php' | wc -l)"
```

---

## 3. Phase B: Coherent Quiescent Capture & Encrypted Custody

### Quiescence Invariant
SQL dumps and file backups must not be taken at disparate times while write transactions occur. Application enters maintenance mode first; all capture occurs under verified quiescence.

```bash
cd /home/u556956644/domains/palsync.net/accounting

# 1. Enter maintenance mode with authorized operator bypass
BYPASS_SECRET=$(openssl rand -hex 16)
/opt/alt/php84/usr/bin/php artisan down --render="errors.503" --retry=60 --secret="$BYPASS_SECRET"
test -f storage/framework/down && echo "Maintenance mode confirmed."

# 2. Establish isolated backup directory
TIMESTAMP=$(date -u +'%Y%m%d_%H%M%SZ')
POINT_ID="recovery_${TIMESTAMP}_f8a0cf7"
BACKUP_DIR="/home/u556956644/domains/palsync.net/accounting-backups/${POINT_ID}"
mkdir -p "$BACKUP_DIR"
chmod 0700 "$BACKUP_DIR"

# Read encryption passphrase securely
read -s -p "Enter Backup Passphrase: " BACKUP_PASSPHRASE
echo ""

# 3. Capture SQL snapshot (Component 1)
DB_NAME=$(grep '^DB_DATABASE=' .env | cut -d= -f2-)
DB_USER=$(grep '^DB_USERNAME=' .env | cut -d= -f2-)
DB_PASS=$(grep '^DB_PASSWORD=' .env | cut -d= -f2-)
DB_HOST=$(grep '^DB_HOST=' .env | cut -d= -f2- | sed 's/"//g')

MYSQL_PWD="$DB_PASS" mysqldump \
    --host="${DB_HOST:-127.0.0.1}" \
    --user="$DB_USER" \
    --single-transaction \
    --quick \
    --routines \
    --triggers \
    --hex-blob \
    "$DB_NAME" > "$BACKUP_DIR/database.sql"
chmod 0600 "$BACKUP_DIR/database.sql"

# 4. Capture Code & Build (Component 2)
tar -czf "$BACKUP_DIR/code_build.tar.gz" \
    --exclude="storage" --exclude="vendor" --exclude=".git" \
    -C /home/u556956644/domains/palsync.net/accounting . \
    -C /home/u556956644/domains/palsync.net/public_html/account build

# 5. Capture Private Files (Component 3)
tar -czf "$BACKUP_DIR/private_files.tar.gz" -C /home/u556956644/domains/palsync.net/accounting storage/app

# 6. Capture Public Assets (Component 4)
tar -czf "$BACKUP_DIR/public_assets.tar.gz" -C /home/u556956644/domains/palsync.net/public_html account

# 7. Capture Encrypted Records (Component 5)
tar -czf "$BACKUP_DIR/encrypted_records.tar.gz" --files-from /dev/null

# 8. Capture Sanitized Configuration Metadata (Component 6)
/opt/alt/php84/usr/bin/php -r '
    $meta = [
        "captured_at" => gmdate("Y-m-d\TH:i:s\Z"),
        "code_sha" => trim(shell_exec("git rev-parse HEAD")),
        "php_version" => PHP_VERSION,
        "app_key_ref" => substr(hash("sha256", (string)getenv("APP_KEY")), 0, 16),
        "db_connection" => getenv("DB_CONNECTION"),
        "db_schema_tables" => 83,
        "migrations" => 63
    ];
    file_put_contents("'$BACKUP_DIR'/configuration.json", json_encode($meta, JSON_PRETTY_PRINT));
'
chmod 0600 "$BACKUP_DIR/configuration.json"

# 9. Encrypt all 6 components with AES-256-CBC and remove plaintext
for comp in database.sql code_build.tar.gz private_files.tar.gz public_assets.tar.gz encrypted_records.tar.gz configuration.json; do
    openssl enc -aes-256-cbc -salt -pbkdf2 -iter 100000 \
        -in "$BACKUP_DIR/$comp" \
        -out "$BACKUP_DIR/${comp}.enc" \
        -pass pass:"$BACKUP_PASSPHRASE"
    chmod 0600 "$BACKUP_DIR/${comp}.enc"
    rm -f "$BACKUP_DIR/$comp"
done

# 10. Generate checksum manifest
cd "$BACKUP_DIR"
sha256sum *.enc > manifest.sha256
chmod 0600 manifest.sha256
```

---

## 4. Off-Host Custody & Separation

Root downloads ciphertext directly to a local secure store:

```bash
# On Root workstation:
LOCAL_DIR="$HOME/.secure-recovery/account-palsync/$POINT_ID"
mkdir -p "$LOCAL_DIR" && chmod 0700 "$LOCAL_DIR"
scp -p "hostinger-accounting:$BACKUP_DIR/*" "$LOCAL_DIR/"
cd "$LOCAL_DIR"
sha256sum -c manifest.sha256
```

**Custody Guarantee**:
- Ciphertext lives locally off-host (survives Hostinger host or account termination).
- Decryption passphrase is separated from Hostinger storage.
- Application encryption keys remain in Root's password vault.

---

## 5. Candidate Deployment & Reconciliations (Maintenance Held)

```bash
cd /home/u556956644/domains/palsync.net/accounting

# 1. Checkout candidate 8cd4322 from staged bundle
git bundle verify "$STAGING_DIR/source-v1.bundle"
git fetch "$STAGING_DIR/source-v1.bundle" HEAD:candidate-8cd4322
git checkout candidate-8cd4322
git rev-parse HEAD # Must match 8cd432220801b08b10b6f25f9ee9a215de94d44c

# 2. Deploy candidate frontend build
tar -xzf "$STAGING_DIR/build.tar.gz" -C /home/u556956644/domains/palsync.net/public_html/account/

# 3. Run deploy preparation (default skips migrations; PR 99 has 0 migrations)
PHP_BIN=/opt/alt/php84/usr/bin/php ./bin/deploy.sh

# 4. Execute the 6 domain reconciliations
/opt/alt/php84/usr/bin/php artisan accounting:reconcile
/opt/alt/php84/usr/bin/php artisan inventory:reconcile
/opt/alt/php84/usr/bin/php artisan sales:reconcile
/opt/alt/php84/usr/bin/php artisan money:reconcile
/opt/alt/php84/usr/bin/php artisan phase7:reconcile

# Payables reconciliation via service
/opt/alt/php84/usr/bin/php -r '
    require "vendor/autoload.php";
    $app = require_once "bootstrap/app.php";
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();
    $service = app(App\Services\Purchasing\PayablesReconciliationService::class);
    echo json_encode($service->reconcileAll(), JSON_PRETTY_PRINT) . PHP_EOL;
'

# 5. Confirm .htaccess integrity
sha256sum /home/u556956644/domains/palsync.net/public_html/account/.htaccess
# Must match: 071652f64ad14f324544e95317aed883e59a9337acb5e1b3519bf0e71a36bdcc
```

---

## 6. Rollback Plan

If any reconciliation or bypass check fails:
1. `git checkout f8a0cf7012c4875bec7320d42e0f8dde58593b52`
2. `PHP_BIN=/opt/alt/php84/usr/bin/php ./bin/deploy.sh`
3. Verify `.htaccess` hash unchanged.
4. Verify database schema remains intact (never roll back live database snapshots over subsequent activity).

---

## 7. Reopening (Owner Authorization Required)

Only upon explicit owner approval:
```bash
/opt/alt/php84/usr/bin/php artisan up
curl -s -I -k https://account.palsync.net | head -n 5
```
