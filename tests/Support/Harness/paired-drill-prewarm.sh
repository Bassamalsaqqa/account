#!/usr/bin/env bash
set -euo pipefail

# ==============================================================================
# Mobile V2 Paired Financial Drill Prewarm Script
# ==============================================================================
# Pre-builds all debug unit test sources and dependencies ahead of the
# time-bounded supervisor drill hold window.
#
# Constraints & Guarantees:
# - Zero financial HTTP traffic: No backend server is active; no financial endpoints touched.
# - Explicit parameterization: Pub and exe pins must be explicitly provided.
# - Protected lane refusal: Never targets active development lanes (e.g. *7ac8*).
# - Zero worktree mutations: Never performs git checkout, reset, or clean.
# - Tracked clean enforcement: Fails closed if tracked files differ from HEAD.
# - Untracked allowlist: Fails closed if unknown untracked files are present.
# ==============================================================================

ANDROID_REPO=""
EXPECTED_PUB_HEAD=""
EXPECTED_EXE_HEAD=""
OFFLINE_FLAG=""

while [ $# -gt 0 ]; do
    case "$1" in
        --offline)
            OFFLINE_FLAG="--offline"
            shift
            ;;
        --android-repo-dir=*)
            ANDROID_REPO="${1#*=}"
            shift
            ;;
        --android-pub-head=*)
            EXPECTED_PUB_HEAD="${1#*=}"
            shift
            ;;
        --android-exe-head=*)
            EXPECTED_EXE_HEAD="${1#*=}"
            shift
            ;;
        --pub-head=*)
            EXPECTED_PUB_HEAD="${1#*=}"
            shift
            ;;
        --exe-head=*)
            EXPECTED_EXE_HEAD="${1#*=}"
            shift
            ;;
        *)
            if [ -z "${ANDROID_REPO}" ] && [ -d "$1" ]; then
                ANDROID_REPO="$1"
                shift
            else
                echo "ERROR: Unknown or unexpected argument: $1" >&2
                exit 1
            fi
            ;;
    esac
done

# Fallback to environment variables if not passed via CLI
ANDROID_REPO="${ANDROID_REPO:-${ANDROID_REPO_DIR:-}}"
EXPECTED_PUB_HEAD="${EXPECTED_PUB_HEAD:-${ANDROID_PUB_HEAD:-}}"
EXPECTED_EXE_HEAD="${EXPECTED_EXE_HEAD:-${ANDROID_EXE_HEAD:-}}"

if [ -z "${ANDROID_REPO}" ]; then
    echo "ERROR: Android repository path must be provided via --android-repo-dir or positional directory argument" >&2
    exit 1
fi

# Protected active lane refusal (e.g. active a29 development lane)
case "${ANDROID_REPO}" in
    *7ac8*|*t3-7ac8b73d*)
        echo "ERROR: Protected active Android lane path refusal (${ANDROID_REPO}). Paired prewarm requires an isolated, Root-approved worktree." >&2
        exit 1
        ;;
esac

if [ ! -d "${ANDROID_REPO}" ]; then
    echo "ERROR: Directory does not exist: ${ANDROID_REPO}" >&2
    exit 1
fi

if [ -z "${EXPECTED_PUB_HEAD}" ]; then
    echo "ERROR: Expected publication HEAD pin is required (--android-pub-head=<sha> or ANDROID_PUB_HEAD)" >&2
    exit 1
fi

if [ -z "${EXPECTED_EXE_HEAD}" ]; then
    echo "ERROR: Expected executable HEAD pin is required (--android-exe-head=<sha> or ANDROID_EXE_HEAD)" >&2
    exit 1
fi

cd "${ANDROID_REPO}"

# Read actual HEAD without mutation
ACTUAL_HEAD=$(git rev-parse HEAD)

if [ "${ACTUAL_HEAD}" != "${EXPECTED_PUB_HEAD}" ]; then
    echo "ERROR: Android repository HEAD (${ACTUAL_HEAD}) does not match expected publication pin (${EXPECTED_PUB_HEAD})" >&2
    echo "FAIL-CLOSED: Worktree checkout must not be mutated. Provide a worktree at the expected publication pin." >&2
    exit 1
fi

# Enforce clean tracked files vs HEAD
if ! git diff-index --quiet HEAD --; then
    echo "ERROR: Android repository has modified tracked files vs HEAD:" >&2
    git diff --name-only HEAD >&2
    exit 1
fi

# Enforce strict dirty allowlist for untracked files
UNTRACKED=$(git ls-files --others --exclude-standard)
if [ -n "${UNTRACKED}" ]; then
    DIRTY_DISALLOWED=""
    while IFS= read -r untracked_file; do
        [ -z "${untracked_file}" ] && continue
        case "${untracked_file}" in
            build/*|.gradle/*|app/build/*|docs/integration/v2-online-cash-evidence/*)
                # Allowed owned build/evidence untracked artifacts
                ;;
            *)
                DIRTY_DISALLOWED="${DIRTY_DISALLOWED}\n  ${untracked_file}"
                ;;
        esac
    done <<< "${UNTRACKED}"

    if [ -n "${DIRTY_DISALLOWED}" ]; then
        echo -e "ERROR: Disallowed untracked files in Android repository:${DIRTY_DISALLOWED}" >&2
        exit 1
    fi
fi

echo "=== Mobile V2 Paired Drill Android Prewarm ==="
echo "Android Worktree : ${ANDROID_REPO}"
echo "Publication HEAD : ${ACTUAL_HEAD}"
echo "Executable Pin   : ${EXPECTED_EXE_HEAD}"
if [ -n "${OFFLINE_FLAG}" ]; then
    echo "Mode             : Strict Offline (--offline)"
else
    echo "Mode             : Online Cache / Resolve (No Financial HTTP)"
fi

START_EPOCH=$(date +%s.%N)

# Compile debug unit test sources DAG ahead of paired drill hold
./gradlew :app:compileDebugUnitTestSources \
  ${OFFLINE_FLAG} \
  --max-workers=1 \
  -Dorg.gradle.workers.max=1 \
  -Dorg.gradle.jvmargs="-XX:ActiveProcessorCount=2 -XX:+UseSerialGC -Xmx1536m" \
  -Pkotlin.compiler.execution.strategy=in-process \
  --no-daemon

END_EPOCH=$(date +%s.%N)
DURATION=$(python3 -c "print(f'{$END_EPOCH - $START_EPOCH:.3f}')" 2>/dev/null || echo "completed")

echo "=== Prewarm Finished (${DURATION}s) ==="
