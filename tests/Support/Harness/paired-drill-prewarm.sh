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
# - Explicit parameterization: Pub and exe pins must be strictly 40-hex lowercase.
# - Canonical protected lane refusal: Resolves realpath, rejects any *7ac8* path.
# - Zero worktree mutations: Never performs git checkout, reset, or clean.
# - Initial status: Must be strictly clean (git status --porcelain empty).
# - Post-compile allowlist: Restricts newly created artifacts strictly to owned Gradle directories.
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

ANDROID_REPO="${ANDROID_REPO:-${ANDROID_REPO_DIR:-}}"
EXPECTED_PUB_HEAD="${EXPECTED_PUB_HEAD:-${ANDROID_PUB_HEAD:-}}"
EXPECTED_EXE_HEAD="${EXPECTED_EXE_HEAD:-${ANDROID_EXE_HEAD:-}}"

if [ -z "${ANDROID_REPO}" ]; then
    echo "ERROR: Android repository path must be provided via --android-repo-dir or positional directory argument" >&2
    exit 1
fi

if [ ! -d "${ANDROID_REPO}" ]; then
    echo "ERROR: Directory does not exist: ${ANDROID_REPO}" >&2
    exit 1
fi

# Canonical realpath resolution & protected active lane refusal
CANONICAL_ANDROID_REPO="$(readlink -f "${ANDROID_REPO}")"
case "${CANONICAL_ANDROID_REPO}" in
    *7ac8*|*t3-7ac8b73d*)
        echo "ERROR: Protected active Android lane path refusal (${CANONICAL_ANDROID_REPO}). Paired prewarm requires an isolated, Root-approved worktree." >&2
        exit 1
        ;;
esac

# 40-hex regex validation
if [[ ! "${EXPECTED_PUB_HEAD}" =~ ^[0-9a-f]{40}$ ]]; then
    echo "ERROR: Expected publication HEAD pin must be a 40-hex lowercase SHA: '${EXPECTED_PUB_HEAD}'" >&2
    exit 1
fi

if [[ ! "${EXPECTED_EXE_HEAD}" =~ ^[0-9a-f]{40}$ ]]; then
    echo "ERROR: Expected executable HEAD pin must be a 40-hex lowercase SHA: '${EXPECTED_EXE_HEAD}'" >&2
    exit 1
fi

cd "${CANONICAL_ANDROID_REPO}"

# Verify commit existence in object store (read-only)
if ! git cat-file -e "${EXPECTED_PUB_HEAD}^{commit}" 2>/dev/null; then
    echo "ERROR: Publication commit object does not exist in repository: ${EXPECTED_PUB_HEAD}" >&2
    exit 1
fi

if ! git cat-file -e "${EXPECTED_EXE_HEAD}^{commit}" 2>/dev/null; then
    echo "ERROR: Executable commit object does not exist in repository: ${EXPECTED_EXE_HEAD}" >&2
    exit 1
fi

# Read actual HEAD without mutation
ACTUAL_HEAD=$(git rev-parse HEAD)

if [ "${ACTUAL_HEAD}" != "${EXPECTED_PUB_HEAD}" ]; then
    echo "ERROR: Android repository HEAD (${ACTUAL_HEAD}) does not match expected publication pin (${EXPECTED_PUB_HEAD})" >&2
    echo "FAIL-CLOSED: Worktree checkout must not be mutated. Provide a worktree at the expected publication pin." >&2
    exit 1
fi

# Initial state check: MUST be strictly clean (no modified or untracked files)
INITIAL_STATUS=$(git status --porcelain)
if [ -n "${INITIAL_STATUS}" ]; then
    echo "ERROR: Android repository must be strictly clean before prewarm. Found:" >&2
    echo "${INITIAL_STATUS}" >&2
    exit 1
fi

echo "=== Mobile V2 Paired Drill Android Prewarm ==="
echo "Android Worktree : ${CANONICAL_ANDROID_REPO}"
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

# Post-compile verification: Tracked files must remain untouched
if ! git diff-index --quiet HEAD --; then
    echo "ERROR: Prewarm compilation modified tracked git files:" >&2
    git diff --name-only HEAD >&2
    exit 1
fi

# Post-compile untracked allowlist: Restricted strictly to owned Gradle directories
POST_UNTRACKED=$(git ls-files --others --exclude-standard)
if [ -n "${POST_UNTRACKED}" ]; then
    DISALLOWED=""
    while IFS= read -r f; do
        [ -z "${f}" ] && continue
        case "${f}" in
            .gradle/*|build/*|app/build/*)
                # Strictly owned Gradle build artifact
                ;;
            *)
                DISALLOWED="${DISALLOWED}\n  ${f}"
                ;;
        esac
    done <<< "${POST_UNTRACKED}"

    if [ -n "${DISALLOWED}" ]; then
        echo -e "ERROR: Disallowed untracked files created outside owned build directories:${DISALLOWED}" >&2
        exit 1
    fi
fi

echo "=== Prewarm Finished (${DURATION}s) ==="
