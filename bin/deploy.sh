#!/usr/bin/env bash
# ==============================================================================
# Small Trader Accounting — Production Deployment Script for Hostinger
# Domain: account.palsync.net
# Private Application Root: /home/u556956644/domains/palsync.net/accounting
# Public Web Root: /home/u556956644/domains/palsync.net/public_html/account
# ==============================================================================

set -eo pipefail

APP_DIR="${APP_DIR:-/home/u556956644/domains/palsync.net/accounting}"
PUBLIC_DIR="${PUBLIC_DIR:-/home/u556956644/domains/palsync.net/public_html/account}"
PHP_BIN="${PHP_BIN:-php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"
HOSTINGER_ALT_PHP_BIN="${HOSTINGER_ALT_PHP_BIN:-/opt/alt/php84/usr/bin/php}"
RUN_MIGRATIONS=0
RUN_PURCHASING_BOOTSTRAP=0
RUN_DOCUMENTS_BOOTSTRAP=0

for arg in "$@"; do
    case "$arg" in
        --skip-migrate)
            RUN_MIGRATIONS=0
            ;;
        --run-migrations|--migrate)
            RUN_MIGRATIONS=1
            ;;
        --run-bootstrap)
            RUN_PURCHASING_BOOTSTRAP=1
            RUN_DOCUMENTS_BOOTSTRAP=1
            ;;
        --run-purchasing-bootstrap)
            RUN_PURCHASING_BOOTSTRAP=1
            ;;
        --run-documents-bootstrap)
            RUN_DOCUMENTS_BOOTSTRAP=1
            ;;
        *)
            echo "ERROR: Unknown deployment option: $arg" >&2
            exit 1
            ;;
    esac
done

PREEXISTING_MAINTENANCE=0
MAINTENANCE_ACTIVE=0

cleanup_on_error() {
    local exit_code=$?
    if [ $exit_code -ne 0 ]; then
        echo "!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!" >&2
        echo "DEPLOYMENT FAILED at line $1 with exit code $exit_code." >&2
        if [ $MAINTENANCE_ACTIVE -eq 1 ]; then
            if [ $PREEXISTING_MAINTENANCE -eq 1 ]; then
                echo "WARNING: Pre-existing maintenance mode ownership is preserved; application remains held in maintenance mode." >&2
            else
                echo "WARNING: Application was left in maintenance mode due to a deployment failure." >&2
            fi
            echo "Maintenance mode remains held. Do NOT blindly bring online without owner review and rollback verification." >&2
        fi
        echo "!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!" >&2
    fi
    exit $exit_code
}
trap 'cleanup_on_error $LINENO' ERR

normalize_static_build_permissions() {
    local build_dir="$1"

    if [ ! -d "$build_dir" ]; then
        echo "ERROR: Build directory not found: $build_dir" >&2
        return 1
    fi

    find "$build_dir" -type d -exec chmod 0755 {} +
    find "$build_dir" -type f -exec chmod 0644 {} +
}

echo "===> [1/7] Pre-flight verification..."

# 1. Directory and environment checks
if [ ! -d "$APP_DIR" ]; then
    echo "ERROR: Application directory not found at $APP_DIR" >&2
    exit 1
fi

if [ ! -f "$APP_DIR/.env" ]; then
    echo "ERROR: Production .env not found in $APP_DIR. Copy and configure .env.production.example first." >&2
    exit 1
fi

cd "$APP_DIR"

# 2. PHP CLI version check (must be PHP 8.4+)
if ! command -v "$PHP_BIN" >/dev/null 2>&1; then
    echo "ERROR: PHP binary '$PHP_BIN' was not found in PATH." >&2
    echo "Hint: Specify the full path, e.g.: PHP_BIN=/opt/alt/php84/usr/bin/php ./bin/deploy.sh" >&2
    exit 1
fi

PHP_VERSION_ID=$("$PHP_BIN" -r 'echo PHP_VERSION_ID;' 2>/dev/null || echo 0)
if [ "$PHP_VERSION_ID" -lt 80400 ]; then
    echo "ERROR: PHP CLI version must be >= 8.4.0. Found: $("$PHP_BIN" -v 2>/dev/null | head -n 1)" >&2
    echo "Hint: On Hostinger SSH, default 'php' may point to PHP 8.3 or earlier." >&2
    echo "      Provide the PHP 8.4 path: PHP_BIN=/opt/alt/php84/usr/bin/php ./bin/deploy.sh" >&2
    exit 1
fi

# 3. Required PHP extensions check
REQUIRED_EXTENSIONS=("bcmath" "curl" "fileinfo" "gd" "intl" "mbstring" "openssl" "pdo_mysql" "tokenizer" "xml" "zip")
MISSING_EXTENSIONS=()
for ext in "${REQUIRED_EXTENSIONS[@]}"; do
    if ! "$PHP_BIN" -r "exit(extension_loaded('$ext') ? 0 : 1);" 2>/dev/null; then
        MISSING_EXTENSIONS+=("$ext")
    fi
done

if [ ${#MISSING_EXTENSIONS[@]} -gt 0 ]; then
    echo "ERROR: Missing required PHP extensions: ${MISSING_EXTENSIONS[*]}" >&2
    exit 1
fi

# 4. Resolve Composer executable and guarantee it executes under verified PHP 8.4
COMPOSER_TARGET=$(command -v "$COMPOSER_BIN" 2>/dev/null || true)
if [ -z "$COMPOSER_TARGET" ] && [ -f "$APP_DIR/composer.phar" ]; then
    COMPOSER_TARGET="$APP_DIR/composer.phar"
fi

if [ -z "$COMPOSER_TARGET" ]; then
    echo "ERROR: Composer executable was not found ($COMPOSER_BIN)." >&2
    echo "Hint: Install Composer or place composer.phar in $APP_DIR." >&2
    exit 1
fi

# On Hostinger SSH, invoking composer directly may invoke /usr/bin/env php (PHP 8.3 default).
# Run Composer directly with $PHP_BIN to guarantee PHP 8.4 runtime:
if ! "$PHP_BIN" "$COMPOSER_TARGET" --version >/dev/null 2>&1; then
    echo "ERROR: Composer cannot run under the verified PHP binary $PHP_BIN." >&2
    echo "       Set COMPOSER_BIN to a Composer PHP script or composer.phar." >&2
    exit 1
fi
COMPOSER_CMD=("$PHP_BIN" "$COMPOSER_TARGET")

# 5. Check write permissions for cache/storage
if [ ! -w "$APP_DIR/storage" ] || [ ! -w "$APP_DIR/bootstrap/cache" ]; then
    echo "ERROR: Write permissions missing on storage/ or bootstrap/cache/." >&2
    exit 1
fi

# 6. Verify built frontend assets exist (fail closed before maintenance mode)
if [ ! -d "$APP_DIR/public/build" ] || [ ! -f "$APP_DIR/public/build/manifest.json" ]; then
    echo "ERROR: Compiled production frontend assets not found at $APP_DIR/public/build/manifest.json." >&2
    echo "       A fresh Git clone does not include pre-compiled frontend assets." >&2
    echo "       You must supply 'public/build' (via 'npm run build' transferred to the server)" >&2
    echo "       before executing deployment." >&2
    exit 1
fi

normalize_static_build_permissions "$APP_DIR/public/build"

if [ -f "$APP_DIR/storage/framework/down" ] || [ -f "$APP_DIR/storage/framework/maintenance.php" ]; then
    PREEXISTING_MAINTENANCE=1
    echo "===> Notice: Pre-existing maintenance mode detected."
fi

echo "===> [2/7] Entering maintenance mode..."
if [ "$PREEXISTING_MAINTENANCE" -eq 0 ]; then
    "$PHP_BIN" artisan down --retry=60
else
    echo "Existing maintenance guard retained without replacing its bypass configuration."
fi

if [ ! -f "$APP_DIR/storage/framework/down" ] && [ ! -f "$APP_DIR/storage/framework/maintenance.php" ]; then
    echo "ERROR: 'artisan down' exited zero but no maintenance marker was found in storage/framework/." >&2
    exit 1
fi
MAINTENANCE_ACTIVE=1

# Preserve web configuration BEFORE dependencies, schema, caches or public assets change.
# A public-root symlink can already expose checkout mutations: it is never accepted here.
if [ -z "$PUBLIC_DIR" ] || [ "$PUBLIC_DIR" = "/" ] || [ "$PUBLIC_DIR" = "." ] || [ -L "$PUBLIC_DIR" ]; then
    echo "ERROR: Invalid or symlinked PUBLIC_DIR; Custody cannot be proved." >&2
    exit 1
fi
if [ ! -d "$PUBLIC_DIR" ]; then
    echo "ERROR: PUBLIC_DIR must be an independently prepared real directory." >&2
    exit 1
fi
PUBLIC_PARENT="$PUBLIC_DIR"
while [ "$PUBLIC_PARENT" != "/" ] && [ "$PUBLIC_PARENT" != "." ]; do
    if [ -L "$PUBLIC_PARENT" ]; then
        echo "ERROR: Symlinked public path; Custody cannot be proved." >&2
        exit 1
    fi
    PUBLIC_NEXT=$(dirname "$PUBLIC_PARENT")
    if [ "$PUBLIC_NEXT" = "$PUBLIC_PARENT" ]; then break; fi
    PUBLIC_PARENT="$PUBLIC_NEXT"
done
PUBLIC_REAL=$(cd "$PUBLIC_DIR" && pwd -P)
APP_REAL=$(pwd -P)
if [[ "$PUBLIC_REAL/" == "$APP_REAL/"* ]] || [[ "$APP_REAL/" == "$PUBLIC_REAL/"* ]]; then
    echo "ERROR: Public root must be separate from the private application tree." >&2
    exit 1
fi

validate_web_configuration() {
    local configuration="$1"
    # Ignore comments, require the correct extension, reject every conflicting mapping.
    if ! awk '
        { sub(/#.*/, ""); directive=tolower($1) }
        directive == "addtype" {
            for (i=3; i<=NF; i++) if (tolower($i)==".webmanifest" || tolower($i)=="webmanifest") {
                if (tolower($2)=="application/manifest+json") found=1; else conflict=1
            }
        }
        END { exit !(found && !conflict) }
    ' "$configuration"; then
        echo "ERROR: missing required PWA manifest MIME type or conflicting active .webmanifest mapping. Manual review required." >&2
        return 1
    fi
    # An active incompatible PHP handler requires operator review, never automatic replacement.
    if ! awk '
        { sub(/#.*/, ""); directive=tolower($1) }
        directive == "sethandler" && tolower($2) ~ /php/ {
            if (tolower($2) != "application/x-httpd-alt-php84") conflict=1; else found=1
        }
        directive == "addhandler" && tolower($2) ~ /php/ {
            if (tolower($2) != "application/x-httpd-alt-php84") conflict=1; else found=1
        }
        END { exit conflict }
    ' "$configuration"; then
        echo "ERROR: Conflicting active PHP handler. Manual review required." >&2
        return 1
    fi
    if [ -x "$HOSTINGER_ALT_PHP_BIN" ] && ! awk '
        { sub(/#.*/, ""); directive=tolower($1) }
        (directive == "sethandler" || directive == "addhandler") && tolower($2)=="application/x-httpd-alt-php84" { found=1 }
        END { exit !found }
    ' "$configuration"; then
        echo "ERROR: missing the Hostinger CloudLinux PHP 8.4 handler. Manual review required." >&2
        return 1
    fi
}

# Reject symlink ancestors: backup custody must remain inside private storage.
for directory in "$APP_DIR/storage" "$APP_DIR/storage/app" "$APP_DIR/storage/app/htaccess_backups"; do
    if [ -L "$directory" ]; then
        echo "ERROR: Symlinked web-configuration backup custody is refused." >&2
        exit 1
    fi
done
BACKUP_DIR="$APP_DIR/storage/app/htaccess_backups"
mkdir -p "$BACKUP_DIR"
BACKUP_REAL=$(cd "$BACKUP_DIR" && pwd -P)
if [[ "$BACKUP_REAL/" != "$APP_REAL/storage/app/"* ]]; then
    echo "ERROR: Web-configuration backup escaped private storage." >&2
    exit 1
fi
chmod 0700 "$BACKUP_DIR"

if [ -L "$PUBLIC_DIR/.htaccess" ]; then
    echo "ERROR: Symlinked .htaccess is refused." >&2
    exit 1
fi
if [ -e "$PUBLIC_DIR/.htaccess" ]; then
    if [ ! -f "$PUBLIC_DIR/.htaccess" ]; then
        echo "ERROR: Live .htaccess is not a regular file." >&2
        exit 1
    fi
    HTACCESS_BACKUP=$(mktemp "$BACKUP_DIR/.htaccess.pre-deploy-XXXXXXXX")
    cp -p "$PUBLIC_DIR/.htaccess" "$HTACCESS_BACKUP"
    chmod 0600 "$HTACCESS_BACKUP"
    LIVE_HASH=$(sha256sum "$PUBLIC_DIR/.htaccess" | awk '{print $1}')
    BACKUP_HASH=$(sha256sum "$HTACCESS_BACKUP" | awk '{print $1}')
    if [ -z "$LIVE_HASH" ] || [ "$LIVE_HASH" != "$BACKUP_HASH" ]; then
        echo "ERROR: sha256 checksum mismatch when backing up live .htaccess." >&2
        exit 1
    fi
    echo "Protected live .htaccess backup verified (sha256: $LIVE_HASH)."
    validate_web_configuration "$PUBLIC_DIR/.htaccess"
else
    if [ ! -f "$APP_DIR/public/.htaccess" ] || [ -L "$APP_DIR/public/.htaccess" ]; then
        echo "ERROR: Reviewed source .htaccess must be a regular file." >&2
        exit 1
    fi
    validate_web_configuration "$APP_DIR/public/.htaccess"
    FRESH_HTACCESS=1
fi

# cp follows destination file links; reject them before any release mutation.
STATIC_ASSETS=(index.php manifest.webmanifest sw.js offline.html icons/icon-192.png icons/icon-512.png icons/icon-maskable.png icons/icon-maskable.svg icons/icon.svg)
for destination in "$PUBLIC_DIR/robots.txt" "$PUBLIC_DIR/favicon.ico" "$PUBLIC_DIR/default.php" "$PUBLIC_DIR/build" "$PUBLIC_DIR/icons"; do
    if [ -L "$destination" ]; then
        echo "ERROR: Symlinked public asset destination is refused." >&2
        exit 1
    fi
done
for asset in "${STATIC_ASSETS[@]}"; do
    if [ -L "$PUBLIC_DIR/$asset" ] || [ -L "$APP_DIR/public/$asset" ] || [ ! -f "$APP_DIR/public/$asset" ]; then
        echo "ERROR: Static asset source must be regular and present; destination links are refused: $asset" >&2
        exit 1
    fi
done
if [ -L "$APP_DIR/public/icons" ] || [ -n "$(find "$APP_DIR/public/icons" -type l -print -quit)" ]; then
    echo "ERROR: Symlinked source icon artifact is refused." >&2
    exit 1
fi
if [ -d "$PUBLIC_DIR/icons" ] && [ -n "$(find "$PUBLIC_DIR/icons" -type l -print -quit)" ]; then
    echo "ERROR: Symlinked public icon destination is refused." >&2
    exit 1
fi
if [ -d "$PUBLIC_DIR/build" ] && [ -n "$(find "$PUBLIC_DIR/build" -type l -print -quit)" ]; then
    echo "ERROR: Symlinked public build destination is refused." >&2
    exit 1
fi
if [ -L "$APP_DIR/public/build" ] || [ -n "$(find "$APP_DIR/public/build" -type l -print -quit)" ]; then
    echo "ERROR: Symlinked source build artifact is refused." >&2
    exit 1
fi

echo "===> [3/7] Installing production PHP dependencies (under PHP 8.4)..."
"${COMPOSER_CMD[@]}" install --no-dev --prefer-dist --optimize-autoloader --no-interaction

if [ "$RUN_MIGRATIONS" -eq 1 ]; then
    echo "===> [4/7] Running database migrations (--run-migrations opt-in)..."
    "$PHP_BIN" artisan migrate --force
else
    echo "===> [4/7] Skipping database migrations by default (use --run-migrations to opt in)..."
fi

if [ "$RUN_PURCHASING_BOOTSTRAP" -eq 1 ]; then
    echo "===> [4/7] Provisioning purchasing foundation and static permissions (--run-bootstrap opt-in)..."
    "$PHP_BIN" artisan purchasing:bootstrap --all
else
    echo "===> [4/7] Skipping purchasing bootstrap by default (use --run-bootstrap to opt in)..."
fi

if [ "$RUN_DOCUMENTS_BOOTSTRAP" -eq 1 ]; then
    echo "===> [4/7] Provisioning documents foundation and static permissions (--run-bootstrap opt-in)..."
    "$PHP_BIN" artisan documents:bootstrap --all
else
    echo "===> [4/7] Skipping documents bootstrap by default (use --run-bootstrap to opt in)..."
fi

echo "===> [5/7] Warming production caches..."
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan route:cache
"$PHP_BIN" artisan view:cache
"$PHP_BIN" artisan event:cache

echo "===> [6/7] Synchronizing public web root assets..."

# Fresh installations use reviewed source only; existing web configuration stays untouched.
if [ "${FRESH_HTACCESS:-0}" -eq 1 ]; then
    cp -p "$APP_DIR/public/.htaccess" "$PUBLIC_DIR/.htaccess"
    chmod 0644 "$PUBLIC_DIR/.htaccess"
fi

# Preserve a Hostinger placeholder before removing it from the serving directory.
if [ -L "$PUBLIC_DIR/default.php" ]; then
    echo "ERROR: Symlinked public placeholder is refused." >&2
    exit 1
fi
if [ -f "$PUBLIC_DIR/default.php" ]; then
    PLACEHOLDER_BACKUP=$(mktemp "$BACKUP_DIR/default.php.pre-deploy-XXXXXXXX")
    cp -p "$PUBLIC_DIR/default.php" "$PLACEHOLDER_BACKUP"
    chmod 0600 "$PLACEHOLDER_BACKUP"
    cmp -s "$PUBLIC_DIR/default.php" "$PLACEHOLDER_BACKUP"
    rm -- "$PUBLIC_DIR/default.php"
fi

echo "Synchronizing entrypoint and static assets to $PUBLIC_DIR..."
mkdir -p "$PUBLIC_DIR/icons"
chmod 0755 "$PUBLIC_DIR/icons"
for asset in "${STATIC_ASSETS[@]}"; do
    cp -p "$APP_DIR/public/$asset" "$PUBLIC_DIR/$asset"
    chmod 0644 "$PUBLIC_DIR/$asset"
done

if [ -f "$APP_DIR/public/robots.txt" ]; then
    cp -p "$APP_DIR/public/robots.txt" "$PUBLIC_DIR/"
    chmod 0644 "$PUBLIC_DIR/robots.txt"
fi

if [ -f "$APP_DIR/public/favicon.ico" ]; then
    cp -p "$APP_DIR/public/favicon.ico" "$PUBLIC_DIR/"
    chmod 0644 "$PUBLIC_DIR/favicon.ico"
fi

mkdir -p "$PUBLIC_DIR/build"
chmod 0755 "$PUBLIC_DIR/build"
cp -rp "$APP_DIR/public/build/"* "$PUBLIC_DIR/build/"
normalize_static_build_permissions "$PUBLIC_DIR/build"

if [ -n "${LIVE_HASH:-}" ]; then
    AFTER_HASH=$(sha256sum "$PUBLIC_DIR/.htaccess" | awk '{print $1}')
    if [ "$LIVE_HASH" != "$AFTER_HASH" ]; then
        echo "ERROR: Live .htaccess checksum changed during asset sync! Expected: $LIVE_HASH, Found: $AFTER_HASH" >&2
        exit 1
    fi
fi

echo "===> [7/7] Deployment preparation completed."
echo "======================================================================="
echo "CONTROLLED RELEASE STATUS: Application remains in MAINTENANCE MODE."
echo "Fail-closed safety: No automatic up/reopen performed."
echo ""
echo "NOTICE: The following checks were NOT RUN by this deployment script:"
echo "  - Economic reconciliations (Accounting, Inventory, Sales, Payables, Money, Phase7)"
echo "  - Live HTTPS asset availability and webmanifest MIME response verification"
echo "  - Encrypted backup coherence certification"
echo "  - Live application error log audit"
echo ""
echo "REOPENING PREREQUISITES (Must be independently completed before live traffic):"
echo "  1. Verified coherent SQL/code/build/private/public-file backup, configuration dependencies and separately protected key history; rollback ready."
echo "  2. Full business-row, schema, configuration and private/public-file fingerprints matched."
echo "  3. Six economic reconciliations passed without imbalance."
echo "  4. Authenticated AR/EN and API/Livewire sessions verified error-free."
echo "  5. Live HTTPS asset responses and webmanifest MIME validated."
echo ""
echo "To reopen the application ONLY after explicit Owner authorization:"
echo "  \"$PHP_BIN\" artisan up"
echo "======================================================================="
