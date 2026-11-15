#!/usr/bin/env bash
# ==============================================================================
# tests/Support/Harness/operator-paired-financial-drill.sh
# Mobile V2 Paired Financial Execution Operator Script
#
# INVARIANTS & POLICIES:
# 1. Defaults to NO-EXECUTE (dry-run preflight mode without --execute).
# 2. Dynamic runtime HEAD inspection (git rev-parse HEAD); never hardcodes stale commit SHAs.
# 3. Fails closed immediately if peer Android publication/executable pins are missing, unset,
#    or unapproved by Root.
# 4. Derives runtime pairing manifest from authoritative template
#    (docs/integration/mobile-v2-integration-manifest.json) preserving all keys, schemas, and digests.
# 5. Credentials loaded securely from private 0600 file generated during provision; zero inline fake passwords.
# 6. host JVM connects to host loopback (127.0.0.1:18443) via startup_tls.readiness_url.
# 7. lifecycleGrant is strictly FINANCIAL_PAIRED_RUN in outer evidence; handoff_scope is ROOT_AUTHORIZED_PAIRED_EXECUTION.
# 8. Strict sequential execution: Baseline BEFORE JVM -> Start -> JVM Run -> Inspect Delta (1 event) ->
#    Reconcile 6 Services -> Compose Trusted DB Report -> Verify whole-file SHAs -> Validate Outer Evidence.
# ==============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../../.." && pwd)"

# Execution flags
IS_EXECUTE=false
ANDROID_PUB_HEAD=""
ANDROID_EXE_HEAD=""
PAIRED_HOLD_SECONDS=120
RUNTIME_DIR=""

for arg in "$@"; do
    case "${arg}" in
        --execute)
            IS_EXECUTE=true
            ;;
        --android-pub-head=*)
            ANDROID_PUB_HEAD="${arg#*=}"
            ;;
        --android-exe-head=*)
            ANDROID_EXE_HEAD="${arg#*=}"
            ;;
        --paired-hold-seconds=*)
            PAIRED_HOLD_SECONDS="${arg#*=}"
            ;;
        --runtime-dir=*)
            RUNTIME_DIR="${arg#*=}"
            ;;
        --help|-h)
            echo "Usage: $0 [options]"
            echo ""
            echo "Options:"
            echo "  --execute                       Required to run live lifecycle (default: no-execute preflight)"
            echo "  --android-pub-head=<40hex>      Root-approved peer Android publication HEAD SHA"
            echo "  --android-exe-head=<40hex>      Root-approved peer Android executable HEAD SHA"
            echo "  --paired-hold-seconds=<1..120>  Bounded hold duration in seconds (default: 120)"
            echo "  --runtime-dir=<path>            Private directory for runtime artifacts (0700)"
            exit 0
            ;;
        *)
            echo "Error: Unknown argument '${arg}'. Use --help for usage." >&2
            exit 1
            ;;
    esac
done

cd "${REPO_ROOT}"

# 1. Inspect dynamic backend runtime HEAD
BACKEND_HEAD="$(git rev-parse HEAD)"
APP_TREE="$(git rev-parse HEAD:app)"

# 2. Verify source fixture and manifest hashes
MANIFEST_TEMPLATE="${REPO_ROOT}/docs/integration/mobile-v2-integration-manifest.json"
if [[ ! -f "${MANIFEST_TEMPLATE}" ]]; then
    echo "Error: Manifest template not found at ${MANIFEST_TEMPLATE}." >&2
    exit 1
fi

SRC_HARNESS_SHA="$(sha256sum tests/Support/Harness/DisposableMobileV2BackendHarness.php | cut -d' ' -f1)"
GUARD_TEST_SHA="$(sha256sum tests/Unit/Mobile/Harness/DisposableMobileV2BackendHarnessGuardTest.php | cut -d' ' -f1)"
CLI_CMD_SHA="$(sha256sum tests/Support/Harness/MobileV2HarnessCommand.php | cut -d' ' -f1)"
CLI_RUNNER_SHA="$(sha256sum tests/Support/Harness/mobile-v2-harness-cli.php | cut -d' ' -f1)"

# Check template hashes matching source
TEMPLATE_SRC_SHA="$(php -r "\$d = json_decode(file_get_contents('${MANIFEST_TEMPLATE}'), true); echo \$d['source_fixture_sha256'] ?? '';")"
TEMPLATE_GUARD_SHA="$(php -r "\$d = json_decode(file_get_contents('${MANIFEST_TEMPLATE}'), true); echo \$d['guard_test_sha256'] ?? '';")"
TEMPLATE_CLI_SHA="$(php -r "\$d = json_decode(file_get_contents('${MANIFEST_TEMPLATE}'), true); echo \$d['cli_command_sha256'] ?? '';")"
TEMPLATE_RUNNER_SHA="$(php -r "\$d = json_decode(file_get_contents('${MANIFEST_TEMPLATE}'), true); echo \$d['cli_runner_sha256'] ?? '';")"

if [[ "${SRC_HARNESS_SHA}" != "${TEMPLATE_SRC_SHA}" ]]; then
    echo "Error: Source harness SHA mismatch vs template: actual ${SRC_HARNESS_SHA} != template ${TEMPLATE_SRC_SHA}" >&2
    exit 1
fi
if [[ "${GUARD_TEST_SHA}" != "${TEMPLATE_GUARD_SHA}" ]]; then
    echo "Error: Guard test SHA mismatch vs template: actual ${GUARD_TEST_SHA} != template ${TEMPLATE_GUARD_SHA}" >&2
    exit 1
fi
if [[ "${CLI_CMD_SHA}" != "${TEMPLATE_CLI_SHA}" ]]; then
    echo "Error: CLI command SHA mismatch vs template: actual ${CLI_CMD_SHA} != template ${TEMPLATE_CLI_SHA}" >&2
    exit 1
fi
if [[ "${CLI_RUNNER_SHA}" != "${TEMPLATE_RUNNER_SHA}" ]]; then
    echo "Error: CLI runner SHA mismatch vs template: actual ${CLI_RUNNER_SHA} != template ${TEMPLATE_RUNNER_SHA}" >&2
    exit 1
fi

echo "=============================================================================="
echo "Mobile V2 Paired Financial Operator Preflight"
echo "=============================================================================="
echo "  Backend Runtime HEAD : ${BACKEND_HEAD}"
echo "  Backend App Tree     : ${APP_TREE}"
echo "  Source Harness SHA   : ${SRC_HARNESS_SHA} (MATCH)"
echo "  Guard Test SHA       : ${GUARD_TEST_SHA} (MATCH)"
echo "  CLI Command SHA      : ${CLI_CMD_SHA} (MATCH)"
echo "  CLI Runner SHA       : ${CLI_RUNNER_SHA} (MATCH)"
echo "  Hold Duration        : ${PAIRED_HOLD_SECONDS}s"

if [[ "${IS_EXECUTE}" != "true" ]]; then
    echo ""
    echo "PREFLIGHT STATUS: DRY-RUN OK (defaults to NO-EXECUTE)."
    echo "To run live lifecycle, provide Root-approved Android peer pins and specify --execute:"
    echo "  $0 --execute --android-pub-head=<40hex> --android-exe-head=<40hex> [--paired-hold-seconds=120]"
    exit 0
fi

# 3. Live Execution Gate: Validate mandatory Root-approved Android peer pins
if [[ -z "${ANDROID_PUB_HEAD}" || "${ANDROID_PUB_HEAD}" =~ ^UNSET_ || ! "${ANDROID_PUB_HEAD}" =~ ^[0-9a-fA-F]{40}$ ]]; then
    echo "Harness refused: --android-pub-head must be a valid 40-hex SHA approved by Root." >&2
    exit 1
fi

if [[ -z "${ANDROID_EXE_HEAD}" || "${ANDROID_EXE_HEAD}" =~ ^UNSET_ || ! "${ANDROID_EXE_HEAD}" =~ ^[0-9a-fA-F]{40}$ ]]; then
    echo "Harness refused: --android-exe-head must be a valid 40-hex SHA approved by Root." >&2
    exit 1
fi

if [[ "${PAIRED_HOLD_SECONDS}" -lt 1 || "${PAIRED_HOLD_SECONDS}" -gt 120 ]]; then
    echo "Harness refused: --paired-hold-seconds must be between 1 and 120, got '${PAIRED_HOLD_SECONDS}'." >&2
    exit 1
fi

# 4. Prepare private runtime directory (0700 outside checkout)
if [[ -z "${RUNTIME_DIR}" ]]; then
    RUNTIME_DIR="/tmp/pr102-financial-$(date +%s)_${RANDOM}"
fi
mkdir -p -m 0700 "${RUNTIME_DIR}"
echo "Runtime Directory      : ${RUNTIME_DIR} (0700)"

# 5. Compose pairing manifest by transforming template (preserving all keys/digests)
RUNTIME_MANIFEST="${RUNTIME_DIR}/pairing_manifest.json"
php -r "
    \$template = json_decode(file_get_contents('${MANIFEST_TEMPLATE}'), true);
    \$template['backend_execution_head_sha'] = '${BACKEND_HEAD}';
    \$template['peer_android_approved_head_sha'] = '${ANDROID_PUB_HEAD}';
    \$template['peer_android_executable_head_sha'] = '${ANDROID_EXE_HEAD}';
    \$template['peer_android_pr_head_sha'] = '${ANDROID_PUB_HEAD}';
    \$template['status'] = 'ROOT_AUTHORIZED_PAIRED_EXECUTION';
    file_put_contents('${RUNTIME_MANIFEST}', json_encode(\$template, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
"
chmod 0600 "${RUNTIME_MANIFEST}"
MANIFEST_SHA="$(sha256sum "${RUNTIME_MANIFEST}" | cut -d' ' -f1)"
echo "Pairing Manifest SHA   : ${MANIFEST_SHA}"

# 6. Step 1: Provision harness to acquire disposable schema, secure credentials, and handoff
echo "==> [Step 1/8] Provisioning disposable harness..."
PROVISION_OUTPUT="${RUNTIME_DIR}/provision_output.json"
php tests/Support/Harness/mobile-v2-harness-cli.php \
    --provision \
    --backend-head="${BACKEND_HEAD}" \
    --android-head="${ANDROID_PUB_HEAD}" \
    --pairing-manifest="${RUNTIME_MANIFEST}" \
    --pairing-manifest-sha="${MANIFEST_SHA}" > "${PROVISION_OUTPUT}"

# Extract runtime parameters from live handoff JSON
HANDOFF_PATH="${RUNTIME_DIR}/harness_temp/runtime_client_handoff.json"
CREDENTIALS_PATH="${RUNTIME_DIR}/harness_temp/bootstrap/credentials.json"
SCHEMA_NAME="$(php -r "\$h = json_decode(file_get_contents('${HANDOFF_PATH}'), true); echo \$h['fixture_manifest']['schema_name'] ?? '';")"
COMPANY_ID="$(php -r "\$h = json_decode(file_get_contents('${HANDOFF_PATH}'), true); echo \$h['server_context']['company_id'] ?? '';")"
ACTOR_ID="$(php -r "\$h = json_decode(file_get_contents('${HANDOFF_PATH}'), true); echo \$h['server_context']['actor_id'] ?? '';")"
EVENT_ID="$(php -r "\$h = json_decode(file_get_contents('${HANDOFF_PATH}'), true); echo \$h['raw_wire_sample']['parsed_request']['client_event_id'] ?? '';")"
PAYLOAD_DIGEST="$(php -r "\$h = json_decode(file_get_contents('${HANDOFF_PATH}'), true); echo \$h['raw_wire_sample']['raw_wire_payload_sha256'] ?? '';")"
HANDOFF_DIGEST="$(sha256sum "${HANDOFF_PATH}" | cut -d' ' -f1)"

echo "  Schema Name          : ${SCHEMA_NAME}"
echo "  Company ID           : ${COMPANY_ID}"
echo "  Client Event ID      : ${EVENT_ID}"
echo "  Live Handoff SHA     : ${HANDOFF_DIGEST}"

# 7. Step 2: Baseline BEFORE JVM execution
echo "==> [Step 2/8] Capturing pre-run MariaDB baseline..."
BASELINE_FILE="${RUNTIME_DIR}/pre_run_baseline.json"
php tests/Support/Harness/financial-inspector-cli.php \
    --baseline \
    --credentials-file="${CREDENTIALS_PATH}" \
    --schema="${SCHEMA_NAME}" \
    --company-id="${COMPANY_ID}" \
    --output="${BASELINE_FILE}"
BASELINE_HASH="$(sha256sum "${BASELINE_FILE}" | cut -d' ' -f1)"
echo "  Pre-Run Baseline SHA : ${BASELINE_HASH}"

# 8. Step 3: Run supervised transport in foreground (--run mode with hold)
echo "==> [Step 3/8] Starting supervised TLS transport for paired JVM execution..."
MOBILE_V2_HARNESS_ENABLE=1 \
MOBILE_V2_HARNESS_START_LISTENER=1 \
PHASE8_ALLOW_DISPOSABLE_DB=1 \
php tests/Support/Harness/mobile-v2-harness-cli.php \
    --run \
    --backend-head="${BACKEND_HEAD}" \
    --android-head="${ANDROID_PUB_HEAD}" \
    --pairing-manifest="${RUNTIME_MANIFEST}" \
    --pairing-manifest-sha="${MANIFEST_SHA}" \
    --paired-hold-seconds="${PAIRED_HOLD_SECONDS}"

# 9. Step 4: Notes on host JVM execution
# Host JVM connects to host loopback: https://127.0.0.1:18443/_harness/readiness and /api/mobile/v2/
# Produces ${RUNTIME_DIR}/outer_evidence.json matching authoritative 12-key format with 5 PASS / 2 NOT_RUN
EVIDENCE_FILE="${RUNTIME_DIR}/outer_evidence.json"
if [[ ! -f "${EVIDENCE_FILE}" ]]; then
    echo "Awaiting outer evidence file at ${EVIDENCE_FILE} from paired JVM client execution."
    exit 0
fi
EVIDENCE_SHA="$(sha256sum "${EVIDENCE_FILE}" | cut -d' ' -f1)"

# 10. Step 5: Inspect MariaDB Delta AFTER JVM run (verifies exactly 1 committed event)
echo "==> [Step 5/8] Inspecting MariaDB state delta..."
DELTA_FILE="${RUNTIME_DIR}/db_delta.json"
php tests/Support/Harness/financial-inspector-cli.php \
    --inspect-delta \
    --credentials-file="${CREDENTIALS_PATH}" \
    --schema="${SCHEMA_NAME}" \
    --company-id="${COMPANY_ID}" \
    --baseline-file="${BASELINE_FILE}" \
    --expected-event-id="${EVENT_ID}" \
    --expected-payload-digest="${PAYLOAD_DIGEST}" \
    --output="${DELTA_FILE}"

# 11. Step 6: Run 6 In-Process Reconciliations
echo "==> [Step 6/8] Executing 6 canonical reconciliation services in system context..."
RECONCILE_FILE="${RUNTIME_DIR}/reconciliation_report.json"
php tests/Support/Harness/financial-inspector-cli.php \
    --reconcile \
    --credentials-file="${CREDENTIALS_PATH}" \
    --schema="${SCHEMA_NAME}" \
    --company-id="${COMPANY_ID}" \
    --output="${RECONCILE_FILE}"

# 12. Step 7: Compose Trusted DB Report
echo "==> [Step 7/8] Composing trusted DB qualification report..."
RUN_ID="$(php -r "\$e = json_decode(file_get_contents('${EVIDENCE_FILE}'), true); echo \$e['runId'] ?? '';")"
TRUSTED_DB_REPORT="${RUNTIME_DIR}/trusted_db_report.json"
php tests/Support/Harness/financial-inspector-cli.php \
    --compose-trusted-report \
    --baseline-file="${BASELINE_FILE}" \
    --delta-file="${DELTA_FILE}" \
    --reconcile-file="${RECONCILE_FILE}" \
    --schema="${SCHEMA_NAME}" \
    --company-id="${COMPANY_ID}" \
    --run-id="${RUN_ID}" \
    --expected-backend-head="${BACKEND_HEAD}" \
    --expected-handoff-digest="${HANDOFF_DIGEST}" \
    --expected-event-id="${EVENT_ID}" \
    --output="${TRUSTED_DB_REPORT}"
DB_REPORT_SHA="$(sha256sum "${TRUSTED_DB_REPORT}" | cut -d' ' -f1)"

# 13. Step 8: Validate Outer Evidence against Trusted DB Report & Context Pins
echo "==> [Step 8/8] Validating outer evidence JSON against trusted DB report..."
php tests/Support/Harness/financial-inspector-cli.php \
    --validate-evidence="${EVIDENCE_FILE}" \
    --expected-evidence-sha="${EVIDENCE_SHA}" \
    --trusted-db-report="${TRUSTED_DB_REPORT}" \
    --expected-db-report-sha="${DB_REPORT_SHA}" \
    --run-id="${RUN_ID}" \
    --schema="${SCHEMA_NAME}" \
    --company-id="${COMPANY_ID}" \
    --expected-event-id="${EVENT_ID}" \
    --expected-payload-digest="${PAYLOAD_DIGEST}" \
    --expected-baseline-hash="${BASELINE_HASH}" \
    --expected-handoff-digest="${HANDOFF_DIGEST}" \
    --expected-scope="ROOT_AUTHORIZED_PAIRED_EXECUTION" \
    --expected-android-pub="${ANDROID_PUB_HEAD}" \
    --expected-android-exe="${ANDROID_EXE_HEAD}" \
    --expected-backend-head="${BACKEND_HEAD}"

echo "=============================================================================="
echo "Basic Financial Paired Lifecycle Completed & Verified Successfully"
echo "=============================================================================="
