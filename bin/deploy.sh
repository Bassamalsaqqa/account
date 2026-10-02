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
SKIP_MIGRATE=0

for arg in "$@"; do
    case "$arg" in
        --skip-migrate)
            SKIP_MIGRATE=1
            ;;
        *)
            echo "ERROR: Unknown deployment option: $arg" >&2
            exit 1
            ;;
    esac
done

MAINTENANCE_ACTIVE=0

cleanup_on_error() {
    local exit_code=$?
    if [ $exit_code -ne 0 ]; then
        echo "!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!" >&2
        echo "DEPLOYMENT FAILED at line $1 with exit code $exit_code." >&2
        if [ $MAINTENANCE_ACTIVE -eq 1 ]; then
            echo "WARNING: Application was left in maintenance mode due to a deployment failure." >&2
            echo "To bring the application back online manually, run:" >&2
            echo "  \"$PHP_BIN\" artisan up" >&2
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

echo "===> [2/7] Entering maintenance mode..."
"$PHP_BIN" artisan down --retry=60 || true
MAINTENANCE_ACTIVE=1

echo "===> [3/7] Installing production PHP dependencies (under PHP 8.4)..."
"${COMPOSER_CMD[@]}" install --no-dev --prefer-dist --optimize-autoloader --no-interaction

if [ "$SKIP_MIGRATE" -eq 1 ]; then
    echo "===> [4/7] Skipping database migrations (--skip-migrate active)..."
else
    echo "===> [4/7] Running database migrations..."
    "$PHP_BIN" artisan migrate --force
fi

echo "===> [5/7] Warming production caches..."
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan route:cache
"$PHP_BIN" artisan view:cache
"$PHP_BIN" artisan event:cache

echo "===> [6/7] Synchronizing public web root assets..."
if [ ! -d "$PUBLIC_DIR" ]; then
    mkdir -p "$PUBLIC_DIR"
fi

# Preserve Hostinger's placeholder in case it contains site-specific changes.
if [ -f "$PUBLIC_DIR/default.php" ]; then
    PLACEHOLDER_BACKUP="$APP_DIR/storage/app/default.php.pre-deploy-$(date +%Y%m%d%H%M%S)"
    if [ -e "$PLACEHOLDER_BACKUP" ]; then
        echo "ERROR: Placeholder backup already exists at $PLACEHOLDER_BACKUP" >&2
        exit 1
    fi
    mv "$PUBLIC_DIR/default.php" "$PLACEHOLDER_BACKUP"
fi

# If PUBLIC_DIR is not a symlink to public/, synchronize public assets non-destructively
if [ ! -L "$PUBLIC_DIR" ]; then
    echo "Non-destructively updating public assets in $PUBLIC_DIR..."
    cp -p "$APP_DIR/public/index.php" "$PUBLIC_DIR/"
    cp -p "$APP_DIR/public/.htaccess" "$PUBLIC_DIR/"
    [ -f "$APP_DIR/public/robots.txt" ] && cp -p "$APP_DIR/public/robots.txt" "$PUBLIC_DIR/" || true
    [ -f "$APP_DIR/public/favicon.ico" ] && cp -p "$APP_DIR/public/favicon.ico" "$PUBLIC_DIR/" || true

    # Preserve or configure Hostinger CloudLinux PHP 8.4 handler when on Hostinger
    if [ -x "/opt/alt/php84/usr/bin/php" ] && [ -f "$PUBLIC_DIR/.htaccess" ]; then
        if ! grep -q "x-httpd-alt-php84" "$PUBLIC_DIR/.htaccess"; then
            sed -i '1s/^/<FilesMatch "\\.(php4|php5|php3|php2|php|phtml)$">\n    SetHandler application\/x-httpd-alt-php84\n<\/FilesMatch>\n\n/' "$PUBLIC_DIR/.htaccess"
        fi
    fi

    mkdir -p "$PUBLIC_DIR/build"
    cp -rp "$APP_DIR/public/build/"* "$PUBLIC_DIR/build/"
    normalize_static_build_permissions "$PUBLIC_DIR/build"
fi

echo "===> [7/7] Exiting maintenance mode..."
"$PHP_BIN" artisan up
MAINTENANCE_ACTIVE=0

echo "===> Deployment completed successfully!"
