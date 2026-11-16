#!/usr/bin/env bash
# ==============================================================================
# tests/Support/Harness/operator-paired-financial-drill.sh
# Mobile V2 Paired Financial Execution Operator Entrypoint
#
# Delegates directly to the single parent-supervised PHP operator:
#   tests/Support/Harness/supervised-financial-drill.php
#
# INVARIANTS & POLICIES:
# 1. Defaults to NO-EXECUTE (dry-run preflight mode).
# 2. Uses structured argument arrays exclusively (no shell string interpolation).
# 3. Dynamic runtime HEAD inspection (git rev-parse HEAD); never hardcodes stale commit SHAs.
# 4. Dynamic app tree inspection (git rev-parse HEAD:app) verified against qualified trees.
# 5. Verifies all 4 source/guard/CLI hashes against manifest template.
# 6. Live execution gate: Unconditional FAIL-CLOSED without explicit Root coordination approval file.
# ==============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../../.." && pwd)"

exec php "${SCRIPT_DIR}/supervised-financial-drill.php" "$@"
