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
# - Network Note: Without the optional --offline flag, Gradle may resolve
#   dependencies against remote Maven repositories if not already cached.
#   Supply --offline to enforce strict local-cache-only resolution.
# ==============================================================================

ANDROID_REPO="${1:-${ANDROID_REPO_DIR:-}}"
OFFLINE_FLAG=""

for arg in "$@"; do
    if [ "$arg" = "--offline" ]; then
        OFFLINE_FLAG="--offline"
    elif [ -d "$arg" ]; then
        ANDROID_REPO="$arg"
    fi
done

if [ -z "${ANDROID_REPO}" ]; then
    echo "ERROR: Android repository path must be provided as first argument or via ANDROID_REPO_DIR" >&2
    exit 1
fi

if [ ! -d "${ANDROID_REPO}" ]; then
    echo "ERROR: Directory does not exist: ${ANDROID_REPO}" >&2
    exit 1
fi

cd "${ANDROID_REPO}"

EXPECTED_PUB_HEAD="b0d5965e7dc5d0e483880afa0bf3ca6a025ee3e6"
EXPECTED_EXE_HEAD="360ce78408f09ecff451381e595a4ffe2f116969"
ACTUAL_HEAD=$(git rev-parse HEAD)

if [ "${ACTUAL_HEAD}" != "${EXPECTED_PUB_HEAD}" ]; then
    echo "ERROR: Android repository HEAD (${ACTUAL_HEAD}) does not match expected publication pin (${EXPECTED_PUB_HEAD})" >&2
    exit 1
fi

if [ -n "$(git status --porcelain)" ]; then
    echo "ERROR: Android repository has uncommitted changes" >&2
    exit 1
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
