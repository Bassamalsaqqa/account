#!/usr/bin/env bash
# Execute the real deployment script against a disposable, stubbed runtime.
set -euo pipefail

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
TEMP_ROOT="$(cd "${TMPDIR:-/tmp}" && pwd -P)"
FIXTURE="$(mktemp -d "$TEMP_ROOT/accounting-deploy-test.XXXXXX")"
FIXTURE="$(cd "$FIXTURE" && pwd -P)"
case "$FIXTURE" in
    "$TEMP_ROOT"/accounting-deploy-test.*) trap 'rm -rf -- "$FIXTURE"' EXIT ;;
    *) echo 'Unexpected fixture path' >&2; exit 1 ;;
esac

mkdir -p "$FIXTURE/app/storage/framework" "$FIXTURE/app/bootstrap/cache" "$FIXTURE/app/public/build/assets" "$FIXTURE/app/public/icons" "$FIXTURE/web"
touch "$FIXTURE/app/.env" "$FIXTURE/app/composer.phar" "$FIXTURE/app/public/index.php"
printf 'AddType application/manifest+json .webmanifest\n' > "$FIXTURE/app/public/.htaccess"
for asset in manifest.webmanifest sw.js offline.html favicon.svg og-image.png \
    icons/apple-touch-icon.png icons/favicon-16x16.png icons/favicon-32x32.png \
    icons/icon-192.png icons/icon-512.png icons/icon-maskable.png \
    icons/icon-maskable.svg icons/icon.svg; do
    touch "$FIXTURE/app/public/$asset"
done
printf '{}\n' > "$FIXTURE/app/public/build/manifest.json"
printf 'body{}\n' > "$FIXTURE/app/public/build/assets/app.css"

cat > "$FIXTURE/php" <<'PHP'
#!/usr/bin/env bash
set -euo pipefail
if [ "$1" = '-r' ]; then
    # Satisfy version/extension preflight without invoking a real PHP runtime.
    case "$2" in *PHP_VERSION_ID*) printf '80425' ;; esac
    exit 0
fi
if [ "$1" != 'artisan' ]; then exit 0; fi
shift
printf '%s\n' "$*" >> "$COMMAND_TRACE"
if [ "$1" = 'down' ]; then
    mkdir -p "$APP_DIR/storage/framework"
    printf '{"retry":60}\n' > "$APP_DIR/storage/framework/down"
fi
if [ "$1" = 'up' ]; then
    rm -f "$APP_DIR/storage/framework/down" "$APP_DIR/storage/framework/maintenance.php"
fi
if [ "$1" = 'purchasing:bootstrap' ] && [ "${FAIL_PROVISIONING:-0}" = 1 ]; then exit 23; fi
if [ "$1" = 'documents:bootstrap' ] && [ "${FAIL_DOCUMENTS:-0}" = 1 ]; then exit 24; fi
PHP
chmod 0755 "$FIXTURE/php"

export APP_DIR="$FIXTURE/app" PUBLIC_DIR="$FIXTURE/web"
export PHP_BIN="$FIXTURE/php" COMPOSER_BIN="$FIXTURE/app/composer.phar"
export COMMAND_TRACE="$FIXTURE/commands"
export RELEASE_EXPECTED_CODE=1111111111111111111111111111111111111111

verify_success() {
    local mode="$1"
    rm -rf "$FIXTURE/web" && mkdir -p "$FIXTURE/web"
    rm -f "$FIXTURE/app/storage/framework/down" "$FIXTURE/app/storage/framework/maintenance.php"
    : > "$COMMAND_TRACE"
    if [ "$mode" = skip ]; then
        bash "$REPO_DIR/bin/deploy.sh" --skip-migrate --run-bootstrap > "$FIXTURE/output" 2>&1
    else
        bash "$REPO_DIR/bin/deploy.sh" --run-migrations --run-bootstrap > "$FIXTURE/output" 2>&1
    fi
    local expected="$FIXTURE/expected"
    printf '%s\n' 'down --retry=60 --render=errors.503' > "$expected"
    if [ "$mode" = normal ]; then printf '%s\n' 'migrate --force' >> "$expected"; fi
    printf '%s\n' 'purchasing:bootstrap --all' 'documents:bootstrap --all' 'config:cache' 'route:cache' 'view:cache' 'event:cache' >> "$expected"
    diff -u "$expected" "$COMMAND_TRACE"
    [ -f "$FIXTURE/web/build/manifest.json" ]
    [ -f "$FIXTURE/web/index.php" ]
    [ -f "$FIXTURE/app/storage/framework/down" ]
    grep -q 'CONTROLLED RELEASE STATUS: Application remains in MAINTENANCE MODE.' "$FIXTURE/output"
    printf 'PASS: %s deployment provisions once before cache warming and remains fail-closed\n' "$mode"
}

verify_success normal
verify_success skip

export FAIL_PROVISIONING=1
export FAIL_DOCUMENTS=0
for mode in normal skip; do
    rm -rf "$FIXTURE/web" && mkdir -p "$FIXTURE/web"
    rm -f "$FIXTURE/app/storage/framework/down" "$FIXTURE/app/storage/framework/maintenance.php"
    : > "$COMMAND_TRACE"
    if [ "$mode" = skip ]; then options=(--skip-migrate --run-bootstrap); else options=(--run-migrations --run-bootstrap); fi
    set +e
    bash "$REPO_DIR/bin/deploy.sh" "${options[@]}" > "$FIXTURE/output" 2>&1
    result=$?
    set -e
    [ "$result" -eq 23 ]
    grep -qx 'purchasing:bootstrap --all' "$COMMAND_TRACE"
    if grep -qx 'documents:bootstrap --all' "$COMMAND_TRACE"; then
        echo 'Purchasing provisioning failure allowed document provisioning to continue' >&2
        exit 1
    fi
    if grep -Eq '^(config:cache|route:cache|view:cache|event:cache|up)$' "$COMMAND_TRACE"; then
        echo 'Provisioning failure allowed deployment to continue' >&2
        exit 1
    fi
    if [ -d "$FIXTURE/web/build" ] || [ -f "$FIXTURE/web/index.php" ]; then
        echo 'Purchasing provisioning failure allowed asset synchronization' >&2
        exit 1
    fi
    grep -q 'left in maintenance mode' "$FIXTURE/output"
    [ -f "$FIXTURE/app/storage/framework/down" ]
    printf 'PASS: %s provisioning failure aborts deployment before caches/up\n' "$mode"
done

export FAIL_PROVISIONING=0
export FAIL_DOCUMENTS=1
for mode in normal skip; do
    rm -rf "$FIXTURE/web" && mkdir -p "$FIXTURE/web"
    rm -f "$FIXTURE/app/storage/framework/down" "$FIXTURE/app/storage/framework/maintenance.php"
    : > "$COMMAND_TRACE"
    if [ "$mode" = skip ]; then options=(--skip-migrate --run-bootstrap); else options=(--run-migrations --run-bootstrap); fi
    set +e
    bash "$REPO_DIR/bin/deploy.sh" "${options[@]}" > "$FIXTURE/output" 2>&1
    result=$?
    set -e
    [ "$result" -eq 24 ]
    grep -qx 'purchasing:bootstrap --all' "$COMMAND_TRACE"
    grep -qx 'documents:bootstrap --all' "$COMMAND_TRACE"
    if grep -Eq '^(config:cache|route:cache|view:cache|event:cache|up)$' "$COMMAND_TRACE"; then
        echo 'Document provisioning failure allowed deployment to continue' >&2
        exit 1
    fi
    if [ -d "$FIXTURE/web/build" ] || [ -f "$FIXTURE/web/index.php" ]; then
        echo 'Document provisioning failure allowed asset synchronization' >&2
        exit 1
    fi
    grep -q 'left in maintenance mode' "$FIXTURE/output"
    printf 'PASS: %s document provisioning failure aborts deployment before caches/assets/up\n' "$mode"
done

export FAIL_PROVISIONING=0
export FAIL_DOCUMENTS=0
rm -f "$FIXTURE/app/public/build/manifest.json"
for mode in normal skip; do
    rm -rf "$FIXTURE/web" && mkdir -p "$FIXTURE/web"
    rm -f "$FIXTURE/app/storage/framework/down" "$FIXTURE/app/storage/framework/maintenance.php"
    : > "$COMMAND_TRACE"
    options=()
    if [ "$mode" = skip ]; then options=(--skip-migrate); fi
    set +e
    bash "$REPO_DIR/bin/deploy.sh" "${options[@]}" > "$FIXTURE/output" 2>&1
    result=$?
    set -e
    [ "$result" -eq 1 ]
    grep -q 'Compiled production frontend assets not found at' "$FIXTURE/output"
    if [ -s "$COMMAND_TRACE" ]; then
        echo 'Missing manifest executed artisan commands unexpectedly' >&2
        exit 1
    fi
    if grep -q 'left in maintenance mode' "$FIXTURE/output"; then
        echo 'Missing manifest triggered maintenance mode unexpectedly' >&2
        exit 1
    fi
    if [ -d "$FIXTURE/web/build" ] || [ -f "$FIXTURE/web/index.php" ]; then
        echo 'Missing manifest allowed asset synchronization' >&2
        exit 1
    fi
    printf 'PASS: %s missing manifest aborts before maintenance\n' "$mode"
done
printf '{}\n' > "$FIXTURE/app/public/build/manifest.json"
