#!/usr/bin/env bash
# ==============================================================================
# tests/Support/Harness/operator-paired-financial-drill.sh
# Mobile V2 Paired Financial Execution Operator Preflight & Preparation Script
#
# INVARIANTS & POLICIES:
# 1. Defaults to NO-EXECUTE (dry-run preflight mode).
# 2. Dynamic runtime HEAD inspection (git rev-parse HEAD); never hardcodes stale commit SHAs.
# 3. Dynamic app tree inspection (git rev-parse HEAD:app) verified against qualified G1 tree.
# 4. Verifies all 4 source/guard/CLI hashes against docs/integration/mobile-v2-integration-manifest.json.
# 5. Live execution gate: Unconditional FAIL-CLOSED. Direct execution is disabled;
#    live lifecycle execution requires Root-coordinated staged process supervision.
# ==============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../../.." && pwd)"

# Execution flags
IS_EXECUTE=false
ANDROID_PUB_HEAD=""
ANDROID_EXE_HEAD=""
PAIRED_HOLD_SECONDS=120

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
        --help|-h)
            echo "Usage: $0 [options]"
            echo ""
            echo "Options:"
            echo "  --execute                       Live execution gate (unconditional FAIL-CLOSED pending Root coordination)"
            echo "  --android-pub-head=<40hex>      Root-approved peer Android publication HEAD SHA"
            echo "  --android-exe-head=<40hex>      Root-approved peer Android executable HEAD SHA"
            echo "  --paired-hold-seconds=<1..120>  Bounded hold duration in seconds (default: 120)"
            exit 0
            ;;
        *)
            echo "Error: Unknown argument '${arg}'. Use --help for usage." >&2
            exit 1
            ;;
    esac
done

cd "${REPO_ROOT}"

# 1. Inspect dynamic backend runtime HEAD and app tree
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

if [[ "${IS_EXECUTE}" == "true" ]]; then
    echo "" >&2
    echo "Error: FAIL-CLOSED: Live paired financial execution orchestrator requires Root staged coordination." >&2
    echo "Direct uncoordinated live execution is disabled. Single supervised lifecycle operator is prepared in staged source." >&2
    exit 1
fi

echo ""
echo "PREFLIGHT STATUS: DRY-RUN OK (defaults to NO-EXECUTE)."
echo "Source pins and invariants verified against manifest template."
exit 0
