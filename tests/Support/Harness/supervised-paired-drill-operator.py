#!/usr/bin/env python3
"""
Mobile V2 Supervised Paired Financial Drill Operator Driver
===========================================================
Repo-tracked, reviewable operator runner replacing ad-hoc scratch scripts.

Strict Adversarial Execution Invariants (Remediated per Root Full Audit):
1. Zero Git Mutations:
   NEVER executes git checkout, reset, clean, or any command that mutates
   worktree state, index, or HEAD in either repository.
2. Original Path Symlink Rejection & Canonical Protected Lane Refusal:
   Validates original input path components against symlinks BEFORE resolving realpath.
   Resolves canonical realpath and strictly refuses any path matching *7ac8* or t3-7ac8b73d.
3. Secure Canonical Output Directory:
   Validates all path components against symlinks, verifies external location relative
   to repository checkouts, enforces 0700 UID ownership, and refuses existing non-empty
   directories to ensure a fresh, unpolluted audit directory per execution.
4. Non-Blocking I/O & Complete Process Group Reaping:
   Uses selectors with non-blocking stdout and monotonic deadline checks.
   Silent or unterminated output cannot hang the runner. Child process groups
   are checked for ANY surviving processes via PGID kill(0) checks, terminated with
   SIGTERM, bounded grace period (2.0s), and SIGKILL. Reaps leader and all descendants.
   Log write exceptions propagate immediately and trigger full process group reaping.
5. Exact Initial Clean Status & Executable Source Parity:
   Enforces exact clean status (git status --porcelain empty) on both repos.
   Validates 40-hex lowercase regex and commit object existence (git cat-file -e).
   Mandates Root-verified binding JSON and verifies that pub_head..exe_head contains
   ONLY approved documentation files (zero source code deviations).
6. Pre/Post Drift Verification & Non-Zero Failures:
   Compares pre and post repository states (HEAD, status, tracked file hashes).
   Any detected drift fails non-zero (exit 1). Failure to capture post state or write
   metadata fails non-zero. Writes exit_metadata.json on all paths.
7. Truthful Execution Modes & Decoupled Prepare/Execute Directory Handshake:
   Mutually exclusive modes: --prewarm-only, --prepare-only, or --execute.
   --execute requires an external Root-prepared --manifest with --manifest-sha, an
   external --root-approval-file, and a private FUTURE PATH --root-live-approval-file
   that must NOT exist prior to child startup.
"""

import os
import re
import sys
import json
import time
import signal
import hashlib
import selectors
import datetime
import argparse
import subprocess

HEX40_REGEX = re.compile(r'^[0-9a-f]{40}$')
HEX64_REGEX = re.compile(r'^[0-9a-f]{64}$')

# Approved 10 docs-only delta paths between publication and executable heads
APPROVED_DOCS_DELTA_PREFIXES = (
    'docs/',
    'README.md',
    'CHANGELOG.md',
)

def get_default_repo_dir():
    # 4 dirname levels: Harness -> Support -> tests -> repo root
    candidate = os.path.dirname(os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__)))))
    try:
        git_root = subprocess.check_output(
            ['git', 'rev-parse', '--show-toplevel'],
            cwd=os.path.dirname(os.path.abspath(__file__))
        ).decode().strip()
        if os.path.isdir(git_root):
            return os.path.realpath(git_root)
    except Exception:
        pass
    return os.path.realpath(candidate)

DEFAULT_BACKEND_DIR = get_default_repo_dir()

def validate_no_symlink_components(path, label="Path"):
    """Validates that no component in the original path is a symlink."""
    norm = os.path.normpath(path)
    curr = norm
    while curr and curr != '/' and curr != os.path.dirname(curr):
        if os.path.islink(curr):
            raise ValueError(f"{label} contains a symlink component: {curr}")
        curr = os.path.dirname(curr)

def assert_not_protected_worktree(path):
    """
    Validates path:
    1. Checks original path for symlinks
    2. Resolves canonical realpath
    3. Rejects active development lane (7ac8 / t3-7ac8b73d)
    """
    validate_no_symlink_components(path, "Worktree path")
    canonical = os.path.realpath(path)
    if '7ac8' in canonical or 't3-7ac8b73d' in canonical:
        raise ValueError(
            f"Protected active Android lane path refusal ({canonical}). "
            "Paired execution requires an isolated, Root-approved worktree."
        )

def validate_secure_output_dir(output_dir, backend_dir, android_dir):
    """
    Validates output directory:
    - No symlinks in original path components
    - Canonical path strictly outside backend and android checkouts
    - Mode 0700 owned by current UID
    - Refuses existing non-empty directory (ensures fresh unpolluted audit directory)
    """
    validate_no_symlink_components(output_dir, "Output directory")
    canonical_out = os.path.realpath(output_dir)
    canonical_backend = os.path.realpath(backend_dir)
    canonical_android = os.path.realpath(android_dir)

    if canonical_out == canonical_backend or canonical_out.startswith(canonical_backend + os.sep):
        raise ValueError(f"Output directory must be external to backend repository: {canonical_out}")
    if canonical_out == canonical_android or canonical_out.startswith(canonical_android + os.sep):
        raise ValueError(f"Output directory must be external to Android repository: {canonical_out}")

    current_uid = os.getuid() if hasattr(os, 'getuid') else -1

    if os.path.exists(canonical_out):
        st = os.stat(canonical_out)
        if (st.st_mode & 0o777) != 0o700:
            raise ValueError(f"Output directory permissions insecure: expected 0700, got {oct(st.st_mode & 0o777)}")
        if current_uid != -1 and st.st_uid != current_uid:
            raise ValueError(f"Output directory owner mismatch: expected UID {current_uid}, got {st.st_uid}")
        entries = os.listdir(canonical_out)
        if entries:
            raise ValueError(
                f"Output directory already exists and is not empty: {canonical_out} "
                f"({len(entries)} items). Fresh output directory required."
            )
    else:
        os.makedirs(canonical_out, mode=0o700, exist_ok=False)
        st = os.stat(canonical_out)
        if (st.st_mode & 0o777) != 0o700:
            os.chmod(canonical_out, 0o700)

    return canonical_out

def validate_future_live_approval_path(path):
    """
    Validates future live approval path:
    - Path must NOT already exist at startup
    - Parent directory must exist, be mode 0700 owned by current UID, and contain no symlinks
    - Target path itself must contain no symlinks
    """
    validate_no_symlink_components(path, "Live approval target path")
    if os.path.exists(path):
        raise ValueError(
            f"Live approval file must NOT exist prior to candidate execution: {path}. "
            "Root creates live approval after inspecting candidate handoff."
        )
    parent = os.path.dirname(os.path.abspath(path))
    if not os.path.isdir(parent):
        raise ValueError(f"Parent directory of live approval file does not exist: {parent}")
    validate_no_symlink_components(parent, "Live approval parent directory")
    st = os.stat(parent)
    if (st.st_mode & 0o777) != 0o700:
        raise ValueError(f"Live approval parent directory permissions insecure: expected 0700, got {oct(st.st_mode & 0o777)}")
    current_uid = os.getuid() if hasattr(os, 'getuid') else -1
    if current_uid != -1 and st.st_uid != current_uid:
        raise ValueError(f"Live approval parent directory owner mismatch: expected UID {current_uid}, got {st.st_uid}")

def validate_commit_sha(sha, label):
    """Validates 40-character lowercase hexadecimal SHA string."""
    cleaned = sha.strip().lower()
    if not HEX40_REGEX.match(cleaned):
        raise ValueError(f"{label} must be a 40-character lowercase hex SHA: got '{sha}'")
    return cleaned

def validate_manifest_sha(sha, label="Manifest SHA-256"):
    """Validates 64-character lowercase hexadecimal SHA-256 string."""
    cleaned = sha.strip().lower()
    if not HEX64_REGEX.match(cleaned):
        raise ValueError(f"{label} must be a 64-character lowercase hex SHA: got '{sha}'")
    return cleaned

FORBIDDEN_SOURCE_EXTENSIONS = (
    '.kt', '.java', '.gradle', '.kts', '.sh', '.py', '.c', '.cpp', '.so', '.jar', '.aar',
)

def get_git_branch(repo_dir):
    """Retrieves current checkout branch name, supporting both symbolic refs and detached HEAD."""
    try:
        return subprocess.check_output(
            ['git', 'symbolic-ref', '--short', 'HEAD'], cwd=repo_dir, stderr=subprocess.DEVNULL
        ).decode().strip()
    except subprocess.CalledProcessError:
        return subprocess.check_output(
            ['git', 'rev-parse', '--abbrev-ref', 'HEAD'], cwd=repo_dir
        ).decode().strip()

def verify_commit_exists(repo_dir, sha, label):
    """Verifies that a commit object exists in the repository object store."""
    try:
        subprocess.check_call(
            ['git', 'cat-file', '-e', f'{sha}^{{commit}}'],
            cwd=repo_dir,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL
        )
    except subprocess.CalledProcessError:
        raise ValueError(f"{label} commit object does not exist in repository: {sha}")

def verify_source_parity(android_dir, pub_head, exe_head, approved_paths):
    """
    Verifies that pub_head..exe_head diff:
    1. Matches the exact Root-approved approved_docs_delta_paths list from the SHA-pinned binding record.
    2. Contains ONLY documentation files starting with approved prefixes and having no forbidden extensions.
    Fails closed with SOURCE_PARITY_VIOLATION on any mismatch or unauthorized change.
    """
    diff_output = subprocess.check_output(
        ['git', 'diff', '--name-only', pub_head, exe_head],
        cwd=android_dir
    ).decode().strip()

    actual_diff = sorted([line.strip() for line in diff_output.splitlines() if line.strip()])
    expected_diff = sorted([p.strip() for p in approved_paths if p.strip()])

    if actual_diff != expected_diff:
        missing = set(expected_diff) - set(actual_diff)
        unexpected = set(actual_diff) - set(expected_diff)
        details = []
        if missing:
            details.append(f"Missing expected diff files: {sorted(missing)}")
        if unexpected:
            details.append(f"Unexpected diff files: {sorted(unexpected)}")
        raise RuntimeError(
            f"SOURCE_PARITY_VIOLATION: Actual diff does not match Root-approved delta paths:\n  "
            + "\n  ".join(details)
        )

    for f in actual_diff:
        if not any(f.startswith(prefix) for prefix in APPROVED_DOCS_DELTA_PREFIXES):
            raise RuntimeError(f"SOURCE_PARITY_VIOLATION: Disallowed prefix in diff file '{f}'")
        if any(f.endswith(ext) for ext in FORBIDDEN_SOURCE_EXTENSIONS):
            raise RuntimeError(f"SOURCE_PARITY_VIOLATION: Forbidden source code extension in diff file '{f}'")

def verify_binding_record(binding_path, expected_sha, android_dir, pub_head, exe_head):
    """
    Validates Root-verified binding JSON record matches supplied parameters:
    - Path has no symlink components
    - Parent directory exists, is 0700 owned by current UID, no symlinks
    - File exists, is 0600 owned by current UID
    - File SHA-256 matches expected_sha exactly
    - root_verified is True
    - peer_android_attached is True
    - peer_android_worktree matches canonical android_dir
    - peer_android_branch matches actual checkout branch from git
    - pins.android_publication_head matches pub_head
    - pins.android_executable_head matches exe_head
    - pins.pub_vs_exe_delta matches '<N>_DOCS_ONLY_FILES' pattern
    - pins.approved_docs_delta_paths is a non-empty list of documentation paths matching count N
    Returns approved_docs_delta_paths list.
    """
    validate_no_symlink_components(binding_path, "Binding record path")
    canonical_binding = os.path.realpath(binding_path)
    if not os.path.isfile(canonical_binding):
        raise ValueError(f"Binding record file not found: {canonical_binding}")

    # Parent directory security check (mode 0700, UID match, no symlinks)
    parent = os.path.dirname(canonical_binding)
    validate_no_symlink_components(parent, "Binding record parent directory")
    st_p = os.stat(parent)
    if (st_p.st_mode & 0o777) != 0o700:
        raise ValueError(f"Binding record parent directory insecure permissions: expected 0700, got {oct(st_p.st_mode & 0o777)}")
    current_uid = os.getuid() if hasattr(os, 'getuid') else -1
    if current_uid != -1 and st_p.st_uid != current_uid:
        raise ValueError(f"Binding record parent directory owner mismatch: expected UID {current_uid}, got {st_p.st_uid}")

    # File security check (mode 0600, UID match)
    st = os.stat(canonical_binding)
    if (st.st_mode & 0o777) != 0o600:
        raise ValueError(f"Binding record permissions insecure: expected 0600, got {oct(st.st_mode & 0o777)}")
    if current_uid != -1 and st.st_uid != current_uid:
        raise ValueError(f"Binding record owner mismatch: expected UID {current_uid}, got {st.st_uid}")

    # Hash check vs Root's expected SHA pin
    expected_clean_sha = validate_manifest_sha(expected_sha, "Expected binding SHA")
    actual_sha = sha256_file(canonical_binding)
    if actual_sha != expected_clean_sha:
        raise ValueError(
            f"Binding record SHA mismatch: expected '{expected_clean_sha}', got '{actual_sha}'. "
            "Record has been modified or tampered with."
        )

    with open(canonical_binding, 'r') as f:
        data = json.load(f)

    if data.get('root_verified') is not True:
        raise ValueError("Binding record root_verified must be boolean True")
    if data.get('peer_android_attached') is not True:
        raise ValueError("Binding record peer_android_attached must be boolean True")

    expected_worktree = os.path.realpath(data.get('peer_android_worktree', ''))
    actual_worktree = os.path.realpath(android_dir)
    if expected_worktree != actual_worktree:
        raise ValueError(
            f"Binding record worktree mismatch: record specifies '{expected_worktree}', "
            f"supplied '{actual_worktree}'"
        )

    expected_branch = data.get('peer_android_branch', '').strip()
    if not expected_branch:
        raise ValueError("Binding record peer_android_branch must not be empty")

    actual_branch = get_git_branch(actual_worktree)
    if actual_branch != expected_branch:
        raise ValueError(
            f"Binding record branch mismatch: record expects '{expected_branch}', "
            f"actual checkout branch is '{actual_branch}'"
        )

    rec_pub = data.get('pins', {}).get('android_publication_head', '').lower()
    rec_exe = data.get('pins', {}).get('android_executable_head', '').lower()
    if rec_pub != pub_head:
        raise ValueError(f"Binding record pub pin mismatch: record '{rec_pub}' != supplied '{pub_head}'")
    if rec_exe != exe_head:
        raise ValueError(f"Binding record exe pin mismatch: record '{rec_exe}' != supplied '{exe_head}'")

    delta = data.get('pins', {}).get('pub_vs_exe_delta', '').strip()
    delta_match = re.match(r'^(\d+)_DOCS_ONLY_FILES$', delta)
    if not delta_match:
        raise ValueError(f"Binding record pub_vs_exe_delta format invalid: '{delta}' (expected '<N>_DOCS_ONLY_FILES')")
    expected_file_count = int(delta_match.group(1))

    approved_paths = data.get('pins', {}).get('approved_docs_delta_paths')
    if not isinstance(approved_paths, list) or len(approved_paths) == 0:
        raise ValueError("Binding record approved_docs_delta_paths must be a non-empty list of relative paths")
    if len(approved_paths) != expected_file_count:
        raise ValueError(
            f"Binding record count mismatch: pub_vs_exe_delta specifies {expected_file_count} files, "
            f"but approved_docs_delta_paths has {len(approved_paths)}"
        )

    for p in approved_paths:
        if not isinstance(p, str) or not p.strip():
            raise ValueError(f"Invalid path entry in approved_docs_delta_paths: {p}")
        if not any(p.startswith(prefix) for prefix in APPROVED_DOCS_DELTA_PREFIXES):
            raise ValueError(f"Binding record approved path '{p}' does not start with approved documentation prefix")
        if any(p.endswith(ext) for ext in FORBIDDEN_SOURCE_EXTENSIONS):
            raise ValueError(f"Binding record approved path '{p}' contains forbidden source extension")

    return approved_paths

APPROVED_PEER_OUTPUT_ARTIFACT_RELPATHS = (
    'app/docs/integration/v2-online-cash-evidence/paired-online-cash-pilot-evidence.json',
)

DECLARED_OUTPUT_ARTIFACTS = {
    'android': list(APPROVED_PEER_OUTPUT_ARTIFACT_RELPATHS),
}

def get_git_state(repo_dir, declared_artifacts=None):
    head = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=repo_dir).decode().strip()
    status = subprocess.check_output(['git', 'status', '--porcelain', '-uall'], cwd=repo_dir).decode().strip()
    files = subprocess.check_output(['git', 'ls-files'], cwd=repo_dir).decode().splitlines()
    hashes = {}
    for rel_path in files:
        full_path = os.path.join(repo_dir, rel_path)
        if os.path.isfile(full_path) and not os.path.islink(full_path):
            with open(full_path, 'rb') as f:
                hashes[rel_path] = hashlib.sha256(f.read()).hexdigest()

    # Pre-capture baseline for declared expected output artifacts (if any)
    artifact_baseline = {}
    if declared_artifacts:
        for rel_artifact in declared_artifacts:
            art_full = os.path.join(repo_dir, rel_artifact)
            if os.path.isfile(art_full) and not os.path.islink(art_full):
                st = os.stat(art_full)
                artifact_baseline[rel_artifact] = {
                    'exists': True,
                    'sha256': sha256_file(art_full),
                    'mtime': st.st_mtime,
                    'size': st.st_size
                }
            else:
                artifact_baseline[rel_artifact] = {
                    'exists': False,
                    'sha256': None,
                    'mtime': None,
                    'size': 0
                }

    return {
        'head': head,
        'status': status,
        'sha256': hashes,
        'declared_artifacts': artifact_baseline,
        'captured_at': datetime.datetime.now(datetime.timezone.utc).isoformat(),
    }

def sha256_file(filepath):
    h = hashlib.sha256()
    with open(filepath, 'rb') as f:
        while chunk := f.read(65536):
            h.update(chunk)
    return h.hexdigest()

def verify_exact_clean_status(repo_dir, name):
    """Verifies that git status --porcelain -uall is strictly empty (no modified or untracked files)."""
    status = subprocess.check_output(['git', 'status', '--porcelain', '-uall'], cwd=repo_dir).decode().strip()
    if status != '':
        raise RuntimeError(f"{name} is not in clean state (expected empty status):\n{status}")

def verify_peer_baseline_record(record_path, expected_sha, android_dir, pub_head=None):
    """
    Validates Root-verified peer baseline record JSON matching security constraints:
    - Path has no symlink components
    - Parent directory exists, is 0700 owned by current UID, no symlinks
    - File exists, is 0600 owned by current UID
    - File SHA-256 matches expected_sha exactly
    - root_verified is True
    - peer_android_worktree matches canonical android_dir
    - baseline_artifacts is a non-empty list of approved output artifacts
    Returns list of baseline artifact specs.
    """
    validate_no_symlink_components(record_path, "Peer baseline record path")
    canonical_record = os.path.realpath(record_path)
    if not os.path.isfile(canonical_record):
        raise ValueError(f"Peer baseline record file not found: {canonical_record}")

    # Parent directory security check (mode 0700, UID match, no symlinks)
    parent = os.path.dirname(canonical_record)
    validate_no_symlink_components(parent, "Peer baseline record parent directory")
    st_p = os.stat(parent)
    if (st_p.st_mode & 0o777) != 0o700:
        raise ValueError(f"Peer baseline record parent directory insecure permissions: expected 0700, got {oct(st_p.st_mode & 0o777)}")
    current_uid = os.getuid() if hasattr(os, 'getuid') else -1
    if current_uid != -1 and st_p.st_uid != current_uid:
        raise ValueError(f"Peer baseline record parent directory owner mismatch: expected UID {current_uid}, got {st_p.st_uid}")

    # File security check (mode 0600, UID match)
    st = os.stat(canonical_record)
    if (st.st_mode & 0o777) != 0o600:
        raise ValueError(f"Peer baseline record permissions insecure: expected 0600, got {oct(st.st_mode & 0o777)}")
    if current_uid != -1 and st.st_uid != current_uid:
        raise ValueError(f"Peer baseline record owner mismatch: expected UID {current_uid}, got {st.st_uid}")

    # Hash check vs Root's expected SHA pin
    expected_clean_sha = validate_manifest_sha(expected_sha, "Expected peer baseline record SHA")
    actual_sha = sha256_file(canonical_record)
    if actual_sha != expected_clean_sha:
        raise ValueError(
            f"Peer baseline record SHA mismatch: expected '{expected_clean_sha}', got '{actual_sha}'."
        )

    try:
        with open(canonical_record, 'r', encoding='utf-8') as f:
            data = json.load(f)
    except Exception as e:
        raise ValueError(f"Failed to parse peer baseline record JSON: {e}")

    if data.get('root_verified') is not True:
        raise ValueError("Peer baseline record must specify 'root_verified': true")

    declared_worktree = data.get('peer_android_worktree')
    if not declared_worktree or os.path.realpath(declared_worktree) != os.path.realpath(android_dir):
        raise ValueError(
            f"Peer baseline record worktree mismatch: declared '{declared_worktree}', actual '{android_dir}'"
        )

    if pub_head and data.get('peer_android_head'):
        rec_head = data.get('peer_android_head').strip().lower()
        if rec_head != pub_head.lower():
            raise ValueError(f"Peer baseline record HEAD pin mismatch: record '{rec_head}' != supplied '{pub_head}'")

    artifacts = data.get('baseline_artifacts')
    if not isinstance(artifacts, list) or len(artifacts) == 0:
        raise ValueError("Peer baseline record must define non-empty 'baseline_artifacts' list")

    for art in artifacts:
        if not isinstance(art, dict):
            raise ValueError("Peer baseline record artifacts must be JSON objects")
        rel_path = art.get('relative_path')
        if not rel_path or not isinstance(rel_path, str):
            raise ValueError("Peer baseline artifact missing 'relative_path'")
        if rel_path not in APPROVED_PEER_OUTPUT_ARTIFACT_RELPATHS:
            raise ValueError(
                f"Peer baseline artifact '{rel_path}' is not in approved peer output artifact allowlist: "
                f"{APPROVED_PEER_OUTPUT_ARTIFACT_RELPATHS}"
            )
        sha = art.get('sha256')
        if not sha or not isinstance(sha, str) or len(sha) != 64 or not all(c in '0123456789abcdef' for c in sha.lower()):
            raise ValueError(f"Peer baseline artifact '{rel_path}' invalid sha256: '{sha}'")

    return artifacts

def verify_peer_clean_or_baseline_status(android_dir, baseline_record_path=None, expected_baseline_sha=None, pub_head=None):
    """
    Verifies that the peer Android repository status matches strict clean-or-baseline constraints:
    1. If status is strictly empty, passes (refusing any unexpected baseline record requirement).
    2. Any modified, deleted, staged, or renamed tracked files cause immediate failure ("tracked clean remains absolute").
    3. If untracked files are present:
       - Fails closed if baseline_record_path is None.
       - Validates baseline record and expected SHA.
       - Enforces EXACT untracked set match (no extra untracked files, no missing baseline files).
       - Validates each baseline artifact on disk (no symlink components, regular file, current UID, mode 0600/0644, non-empty, matching SHA-256 and expected size).
    Returns list of admitted baseline artifact dicts (or empty list if clean).
    """
    status = subprocess.check_output(['git', 'status', '--porcelain', '-uall'], cwd=android_dir).decode().strip()
    current_uid = os.getuid() if hasattr(os, 'getuid') else -1

    if status == '':
        if baseline_record_path is not None:
            raise RuntimeError(
                "Peer baseline record was specified, but Android repository status is completely clean "
                "(expected baseline artifacts are missing from working tree)."
            )
        return []

    # Check for tracked file modifications - tracked clean is absolute
    status_lines = [line.strip() for line in status.splitlines() if line.strip()]
    non_untracked = [line for line in status_lines if not line.startswith('?? ')]
    if non_untracked:
        raise RuntimeError(
            f"Android repository has modified/staged tracked files (tracked clean is absolute):\n"
            + "\n".join(non_untracked)
        )

    # Untracked files present: require explicit baseline record
    if not baseline_record_path:
        raise RuntimeError(
            f"Android repository is not in clean state (untracked files present, but no --peer-baseline-record provided):\n{status}"
        )
    if not expected_baseline_sha:
        raise ValueError("--peer-baseline-record requires --expected-peer-baseline-sha")

    baseline_specs = verify_peer_baseline_record(baseline_record_path, expected_baseline_sha, android_dir, pub_head=pub_head)

    # Enforce EXACT untracked set match
    actual_untracked = set(line[3:].strip() for line in status_lines)
    expected_untracked = set(item['relative_path'] for item in baseline_specs)

    if actual_untracked != expected_untracked:
        extra = sorted(list(actual_untracked - expected_untracked))
        missing = sorted(list(expected_untracked - actual_untracked))
        msg = "Android repository untracked files do not match baseline record."
        if extra:
            msg += f" Extra untracked: {extra}."
        if missing:
            msg += f" Missing expected baseline: {missing}."
        raise RuntimeError(msg)

    # Secure disk validation for each baseline artifact
    admitted = []
    for item in baseline_specs:
        rel_path = item['relative_path']
        full_path = os.path.join(android_dir, rel_path)

        # 1. No symlink components in path
        validate_no_symlink_components(full_path, f"Peer baseline artifact {rel_path}")

        # 2. Must be regular file and not a symlink
        if not os.path.isfile(full_path) or os.path.islink(full_path):
            raise RuntimeError(f"Peer baseline artifact '{rel_path}' is not a regular file")

        # 3. UID check
        st = os.stat(full_path)
        if current_uid != -1 and st.st_uid != current_uid:
            raise RuntimeError(
                f"Peer baseline artifact '{rel_path}' UID mismatch: expected {current_uid}, got {st.st_uid}"
            )

        # 4. Mode check: must be 0600 or 0644
        mode = st.st_mode & 0o777
        if mode not in (0o600, 0o644):
            raise RuntimeError(
                f"Peer baseline artifact '{rel_path}' insecure mode: expected 0600 or 0644, got {oct(mode)}"
            )

        # 5. Non-empty & size check
        if st.st_size == 0:
            raise RuntimeError(f"Peer baseline artifact '{rel_path}' is empty (0 bytes)")

        if item.get('size') is not None and st.st_size != item['size']:
            raise RuntimeError(
                f"Peer baseline artifact '{rel_path}' size mismatch: expected {item['size']}, got {st.st_size}"
            )

        # 6. SHA-256 match
        actual_sha = sha256_file(full_path)
        expected_sha = item['sha256'].lower()
        if actual_sha != expected_sha:
            raise RuntimeError(
                f"Peer baseline artifact '{rel_path}' SHA mismatch: expected {expected_sha}, got {actual_sha}"
            )

        admitted.append({
            'relative_path': rel_path,
            'full_path': full_path,
            'sha256': actual_sha,
            'size': st.st_size,
            'mode': oct(mode),
        })

    return admitted

def archive_peer_baseline_artifacts(admitted_artifacts, output_dir):
    """
    Archives admitted peer baseline artifacts with O_EXCL to output_dir before any child execution.
    Writes index metadata (peer_baseline_archive_metadata.json) with O_EXCL (0600).
    Never overwrites existing files.
    Returns metadata dict.
    """
    if not admitted_artifacts:
        return None

    archive_entries = []
    flags = os.O_WRONLY | os.O_CREAT | os.O_EXCL

    for art in admitted_artifacts:
        rel_path = art['relative_path']
        src_path = art['full_path']
        base_name = os.path.basename(rel_path)
        dest_filename = f"archived_peer_baseline_{base_name}"
        dest_path = os.path.join(output_dir, dest_filename)

        with open(src_path, 'rb') as f_src:
            payload = f_src.read()

        fd = os.open(dest_path, flags, 0o600)
        with os.fdopen(fd, 'wb') as f_dst:
            f_dst.write(payload)

        # Verify archive integrity immediately
        archived_sha = sha256_file(dest_path)
        if archived_sha != art['sha256']:
            raise RuntimeError(
                f"Archived baseline artifact integrity failure: expected {art['sha256']}, got {archived_sha}"
            )

        archive_entries.append({
            'source_relative_path': rel_path,
            'source_full_path': src_path,
            'archive_filename': dest_filename,
            'archive_path': dest_path,
            'sha256': art['sha256'],
            'size': len(payload),
            'archived_at': datetime.datetime.now(datetime.timezone.utc).isoformat(),
        })

    metadata = {
        'count': len(archive_entries),
        'archived_at': datetime.datetime.now(datetime.timezone.utc).isoformat(),
        'artifacts': archive_entries,
    }

    meta_path = os.path.join(output_dir, 'peer_baseline_archive_metadata.json')
    meta_fd = os.open(meta_path, flags, 0o600)
    with os.fdopen(meta_fd, 'w', encoding='utf-8') as f_meta:
        json.dump(metadata, f_meta, indent=2)

    return metadata

def compare_git_states(pre_state, post_state, declared_output_artifacts=None, repo_dirs=None):
    """
    Compares pre and post git states; returns dict of drifts if any detected.
    - Status differences consisting STRICTLY of newly created/updated declared output artifacts
      are validated (non-symlink, current UID, mode 0600/0644, valid SHA-256) and accounted for.
    - ANY other untracked file or directory difference triggers a status drift failure.
    - ANY tracked source file hash difference triggers a tracked_hashes_drift failure.
    - Broad wildcard allowlists are strictly rejected.
    """
    drifts = {}
    current_uid = os.getuid() if hasattr(os, 'getuid') else -1

    for repo in ['backend', 'android']:
        if repo not in pre_state or repo not in post_state:
            drifts[repo] = "Missing pre or post state record"
            continue
        pre_repo = pre_state[repo]
        post_repo = post_state[repo]
        repo_dir = repo_dirs.get(repo) if repo_dirs else None

        if pre_repo['head'] != post_repo['head']:
            drifts[f"{repo}_head_drift"] = {
                'pre': pre_repo['head'],
                'post': post_repo['head']
            }

        # Tracked hash comparison (source code invariance)
        pre_hashes = pre_repo.get('sha256', {})
        post_hashes = post_repo.get('sha256', {})
        file_drifts = {}
        for f in set(pre_hashes.keys()) | set(post_hashes.keys()):
            if pre_hashes.get(f) != post_hashes.get(f):
                file_drifts[f] = {
                    'pre': pre_hashes.get(f),
                    'post': post_hashes.get(f)
                }
        if file_drifts:
            drifts[f"{repo}_tracked_hashes_drift"] = file_drifts

        # Status comparison with explicit declared output artifact accounting
        if pre_repo['status'] != post_repo['status']:
            pre_lines = set(line.strip() for line in pre_repo['status'].splitlines() if line.strip())
            post_lines = set(line.strip() for line in post_repo['status'].splitlines() if line.strip())
            added_status_lines = post_lines - pre_lines
            removed_status_lines = pre_lines - post_lines

            allowed_relpaths = set((declared_output_artifacts or {}).get(repo, []))
            unaccounted_lines = []

            for line in added_status_lines:
                # Check if it's an untracked file matching a declared relative output artifact
                if line.startswith('?? '):
                    rel_file = line[3:].strip()
                    if rel_file in allowed_relpaths and repo_dir:
                        full_artifact_path = os.path.join(repo_dir, rel_file)
                        try:
                            # 1. No symlink components in path
                            validate_no_symlink_components(full_artifact_path, f"{repo} output artifact")
                            # 2. Must be a regular file
                            if not os.path.isfile(full_artifact_path) or os.path.islink(full_artifact_path):
                                unaccounted_lines.append(f"{line} (not a regular file)")
                                continue
                            # 3. Ownership check (must match current UID)
                            st = os.stat(full_artifact_path)
                            if current_uid != -1 and st.st_uid != current_uid:
                                unaccounted_lines.append(f"{line} (UID mismatch: expected {current_uid}, got {st.st_uid})")
                                continue
                            # 4. Mode check: must be 0600 or 0644
                            mode = st.st_mode & 0o777
                            if mode not in (0o600, 0o644):
                                unaccounted_lines.append(f"{line} (insecure mode: {oct(mode)})")
                                continue
                            # 5. Must have content
                            if st.st_size == 0:
                                unaccounted_lines.append(f"{line} (empty file)")
                                continue
                            # Artifact is verified and accounted for
                            continue
                        except Exception as e:
                            unaccounted_lines.append(f"{line} ({str(e)})")
                            continue
                # Any other line (modified tracked file or undeclared untracked file) is unaccounted
                unaccounted_lines.append(line)

            if unaccounted_lines or removed_status_lines:
                drifts[f"{repo}_status_drift"] = {
                    'pre': pre_repo['status'],
                    'post': post_repo['status'],
                    'unaccounted_added': unaccounted_lines,
                    'unexpected_removed': list(removed_status_lines)
                }

    return drifts

def is_pgid_alive(pgid):
    """Checks whether any processes in the given process group ID are alive."""
    try:
        os.killpg(pgid, 0)
        return True
    except ProcessLookupError:
        return False
    except PermissionError:
        return True

def reap_process_group(proc, pgid, grace_seconds=2.0):
    """
    Reaps the entire process group:
    1. Sends SIGTERM to entire process group.
    2. Bounded wait for all processes in group to exit.
    3. Escalates to SIGKILL if any processes remain in PGID.
    4. Reaps leader process via proc.wait(timeout=2.0).
    5. Verifies no orphan processes survive.
    """
    try:
        os.killpg(pgid, signal.SIGTERM)
    except ProcessLookupError:
        pass

    grace_end = time.monotonic() + grace_seconds
    while time.monotonic() < grace_end:
        proc.poll()
        if proc.poll() is not None and not is_pgid_alive(pgid):
            break
        time.sleep(0.05)

    if is_pgid_alive(pgid):
        try:
            os.killpg(pgid, signal.SIGKILL)
        except ProcessLookupError:
            pass
        kill_wait_end = time.monotonic() + 1.0
        while time.monotonic() < kill_wait_end:
            proc.poll()
            if not is_pgid_alive(pgid):
                break
            time.sleep(0.05)

    try:
        proc.wait(timeout=2.0)
    except subprocess.TimeoutExpired:
        pass

    if is_pgid_alive(pgid):
        raise RuntimeError(f"METADATA_ORPHAN_PROCESS_DETECTED: Processes still alive in PGID {pgid}")

def run_supervised_child(cmd, cwd, env, log_file_handle, timeout_seconds):
    """
    Non-blocking supervised child runner:
    - Sets child stdout to non-blocking.
    - Monitors monotonic deadline continuously even on silent / unterminated child output.
    - Complete process group termination (leader + all descendants).
    - Unconditionally propagates log write errors and reaps process group.
    """
    proc = subprocess.Popen(
        cmd,
        cwd=cwd,
        env=env,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        start_new_session=True
    )
    pgid = proc.pid

    sel = selectors.DefaultSelector()
    os.set_blocking(proc.stdout.fileno(), False)
    sel.register(proc.stdout, selectors.EVENT_READ)

    start_monotonic = time.monotonic()
    timed_out = False

    def sig_handler(signum, frame):
        reap_process_group(proc, pgid)
        raise RuntimeError(f"Supervised child aborted by signal {signum}")

    old_sigint = signal.signal(signal.SIGINT, sig_handler)
    old_sigterm = signal.signal(signal.SIGTERM, sig_handler)

    try:
        while True:
            now = time.monotonic()
            rem = (start_monotonic + timeout_seconds) - now
            if rem <= 0:
                timed_out = True
                reap_process_group(proc, pgid)
                break

            events = sel.select(timeout=min(rem, 0.2))
            for key, mask in events:
                try:
                    chunk = proc.stdout.read()
                except (BlockingIOError, InterruptedError):
                    chunk = None
                if chunk:
                    text = chunk.decode('utf-8', errors='replace')
                    sys.stdout.write(text)
                    sys.stdout.flush()
                    # Log write failure must raise and propagate (Defect 5 fix)
                    log_file_handle.write(text)
                    log_file_handle.flush()

            # Check if leader has exited and no further process output
            if proc.poll() is not None:
                # Final drain of remaining output
                while True:
                    try:
                        chunk = proc.stdout.read()
                    except (BlockingIOError, InterruptedError):
                        chunk = None
                    if not chunk:
                        break
                    text = chunk.decode('utf-8', errors='replace')
                    sys.stdout.write(text)
                    sys.stdout.flush()
                    log_file_handle.write(text)
                    log_file_handle.flush()
                break
    except Exception:
        reap_process_group(proc, pgid)
        raise
    finally:
        sel.close()
        try:
            proc.stdout.close()
        except Exception:
            pass
        signal.signal(signal.SIGINT, old_sigint)
        signal.signal(signal.SIGTERM, old_sigterm)
        # Ensure any surviving descendants are reaped even on normal leader exit (Defect 2 fix)
        reap_process_group(proc, pgid)

    if timed_out:
        raise TimeoutError(f"Child process timed out after {timeout_seconds} seconds")

    return proc.returncode

def build_parser():
    """Constructs and returns the authoritative CLI argument parser."""
    parser = argparse.ArgumentParser(
        description="Mobile V2 Supervised Paired Financial Drill Operator Driver"
    )
    parser.add_argument("--output-dir", required=True, help="Fresh, empty private directory for artifacts and witness (0700)")
    parser.add_argument("--backend-dir", default=DEFAULT_BACKEND_DIR, help="Backend repository root")
    parser.add_argument("--android-dir", required=True, help="Android repository checkout root")
    parser.add_argument("--expected-backend-head", required=True, help="Expected backend HEAD SHA (40 hex)")
    parser.add_argument("--android-pub-head", required=True, help="Root-approved peer Android publication HEAD SHA (40 hex)")
    parser.add_argument("--android-exe-head", required=True, help="Root-approved peer Android executable HEAD SHA (40 hex)")
    parser.add_argument("--binding-record", required=True, help="Path to Root-verified binding JSON record (mandatory)")
    parser.add_argument("--expected-binding-sha", required=True, help="Expected 64-hex SHA-256 for Root-verified binding record (mandatory)")
    parser.add_argument("--peer-baseline-record", default=None, help="Path to Root-verified peer baseline record JSON (required if untracked artifacts present in peer repo)")
    parser.add_argument("--expected-peer-baseline-sha", default=None, help="Expected 64-hex SHA-256 for Root-verified peer baseline record (required when --peer-baseline-record is passed)")
    parser.add_argument("--offline", action="store_true", help="Pass --offline flag to Gradle prewarm")
    parser.add_argument("--paired-hold", type=int, default=120, help="Paired hold seconds (default: 120)")
    parser.add_argument("--timeout", type=int, default=180, help="Supervised execution timeout in seconds (default: 180)")

    # Truth in naming & execution mode: mutually exclusive explicit action flags
    mode_group = parser.add_mutually_exclusive_group(required=True)
    mode_group.add_argument("--prewarm-only", action="store_true", help="Execute compile-only prewarm task (paired-drill-prewarm.sh)")
    mode_group.add_argument("--prepare-only", action="store_true", help="Execute preparation witness only (root-operator-tool.py prepare)")
    mode_group.add_argument("--execute", action="store_true", help="Execute live supervised drill (supervised-financial-drill.php --execute)")

    # Execute mode arguments (separate external Root approval files; no self-approval)
    parser.add_argument("--manifest", default=None, help="External Root-prepared pairing manifest (required for --execute)")
    parser.add_argument("--manifest-sha", default=None, help="SHA-256 override pin for external pairing manifest (required for --execute)")
    parser.add_argument("--root-approval-file", default=None, help="Root prestart approval file (required for --execute)")
    parser.add_argument("--root-live-approval-file", default=None, help="Private future path for Root live approval (required for --execute; must NOT exist at startup)")
    parser.add_argument("--expected-scope", default=None, help="Expected authorization scope (e.g. ROOT_AUTHORIZED_PAIRED_EXECUTION or ROOT_AUTHORIZED_ADVANCED_PROBE_EXECUTION)")
    parser.add_argument("--advanced-probes", action="store_true", default=False, help="Enable advanced probe execution")

    return parser

def main():
    parser = build_parser()
    args = parser.parse_args()

    if bool(args.peer_baseline_record) != bool(args.expected_peer_baseline_sha):
        raise ValueError("--peer-baseline-record and --expected-peer-baseline-sha must be provided together")

    start_iso = datetime.datetime.now(datetime.timezone.utc).isoformat()
    start_monotonic = time.monotonic()
    output_dir_validated = False
    exit_code = 1
    failure_reason = None
    pre_state = None
    post_state = None
    drifts = {}
    output_dir = None

    try:
        # 1. Validate original input paths for symlinks BEFORE realpath resolution (Defect 4 fix)
        validate_no_symlink_components(args.backend_dir, "Backend directory")
        validate_no_symlink_components(args.android_dir, "Android directory")
        validate_no_symlink_components(args.output_dir, "Output directory")

        # 2. Canonical realpath resolution & protected active lane refusal
        backend_dir = os.path.realpath(args.backend_dir)
        android_dir = os.path.realpath(args.android_dir)

        assert_not_protected_worktree(backend_dir)
        assert_not_protected_worktree(android_dir)

        if not os.path.isdir(backend_dir):
            raise ValueError(f"Backend repo directory not found: {backend_dir}")
        if not os.path.isdir(android_dir):
            raise ValueError(f"Android repo directory not found: {android_dir}")

        # 3. Validate pins format
        expected_backend = validate_commit_sha(args.expected_backend_head, "Expected backend HEAD")
        pub_head = validate_commit_sha(args.android_pub_head, "Android publication HEAD")
        exe_head = validate_commit_sha(args.android_exe_head, "Android executable HEAD")

        # 4. Verify commit existence in object store
        verify_commit_exists(backend_dir, expected_backend, "Backend expected HEAD")
        verify_commit_exists(android_dir, pub_head, "Android publication HEAD")
        verify_commit_exists(android_dir, exe_head, "Android executable HEAD")

        # 5. Verify actual backend HEAD matches expected pin
        actual_backend_head = subprocess.check_output(
            ['git', 'rev-parse', 'HEAD'], cwd=backend_dir
        ).decode().strip()
        if actual_backend_head != expected_backend:
            raise RuntimeError(f"Backend HEAD mismatch: actual {actual_backend_head} != expected {expected_backend}")

        # 6. Verify actual Android HEAD matches publication pin without mutation
        actual_android_head = subprocess.check_output(
            ['git', 'rev-parse', 'HEAD'], cwd=android_dir
        ).decode().strip()
        if actual_android_head != pub_head:
            raise RuntimeError(
                f"Android repository HEAD ({actual_android_head}) does not match expected publication pin ({pub_head}). "
                "FAIL-CLOSED: Worktree checkout must not be mutated."
            )

        # 7. Verify mandatory binding record and expected SHA
        approved_delta_paths = verify_binding_record(
            args.binding_record, args.expected_binding_sha, android_dir, pub_head, exe_head
        )

        # 8. Verify source parity between pub and exe pins against approved delta paths
        verify_source_parity(android_dir, pub_head, exe_head, approved_delta_paths)

        # 9. Verify exact clean status on backend (strictly empty) and clean-or-baseline on android
        verify_exact_clean_status(backend_dir, "Backend repository")
        admitted_peer_baseline = verify_peer_clean_or_baseline_status(
            android_dir,
            baseline_record_path=args.peer_baseline_record,
            expected_baseline_sha=args.expected_peer_baseline_sha,
            pub_head=pub_head,
        )

        # 10. Validate secure output directory (fresh, empty, 0700 UID)
        output_dir = validate_secure_output_dir(args.output_dir, backend_dir, android_dir)
        output_dir_validated = True

        # Archive admitted peer baseline artifacts (O_EXCL) BEFORE any child execution
        baseline_archive_meta = archive_peer_baseline_artifacts(admitted_peer_baseline, output_dir)
        if baseline_archive_meta:
            print(f"[Operator] Archived {baseline_archive_meta['count']} peer baseline artifact(s) to {output_dir} (O_EXCL)")

        # 11. Capture initial immutable PRE state for BOTH repos
        print(f"[Operator] Capturing initial PRE state for both repositories...")
        pre_state = {
            'backend': get_git_state(backend_dir, DECLARED_OUTPUT_ARTIFACTS.get('backend')),
            'android': get_git_state(android_dir, DECLARED_OUTPUT_ARTIFACTS.get('android')),
            'peer_baseline_archive': baseline_archive_meta,
            'metadata': {
                'expected_backend_head': expected_backend,
                'expected_pub_head': pub_head,
                'expected_exe_head': exe_head,
                'timestamp': start_iso,
            }
        }
        pre_path = os.path.join(output_dir, 'pre.json')
        flags = os.O_WRONLY | os.O_CREAT | os.O_EXCL
        with os.fdopen(os.open(pre_path, flags, 0o600), 'w') as f:
            json.dump(pre_state, f, indent=2)
        print(f"[Operator] Saved immutable pre.json (SHA: {sha256_file(pre_path)})")

        # Initialize execution log
        log_path = os.path.join(output_dir, 'operator_execution.log')
        with os.fdopen(os.open(log_path, flags, 0o600), 'w') as f:
            f.write(f"=== Operator Run Started at {start_iso} ===\n")

        with open(log_path, 'a') as log_file:
            if args.prewarm_only:
                # Mode: Phase B compile-only prewarm
                print(f"[Operator] Running compile-only paired prewarm in {android_dir}...")
                prewarm_script = os.path.join(backend_dir, 'tests/Support/Harness/paired-drill-prewarm.sh')
                cmd = [
                    prewarm_script,
                    f'--android-repo-dir={android_dir}',
                    f'--android-pub-head={pub_head}',
                    f'--android-exe-head={exe_head}',
                ]
                if args.offline:
                    cmd.append('--offline')

                argv_path = os.path.join(output_dir, 'argv.json')
                with open(argv_path, 'w') as f:
                    json.dump(cmd, f, indent=2)
                os.chmod(argv_path, 0o600)

                child_env = os.environ.copy()
                exit_code = run_supervised_child(cmd, backend_dir, child_env, log_file, args.timeout)
                if exit_code != 0:
                    raise RuntimeError(f"Prewarm failed with exit code {exit_code}")
                print(f"[Operator] Prewarm completed successfully (code 0).")

            elif args.prepare_only:
                # Mode: Drill witness and manifest preparation
                tool_path = os.path.join(backend_dir, 'tests/Support/Harness/root-operator-tool.py')
                prep_cmd = [
                    sys.executable,
                    tool_path,
                    'prepare',
                    f'--repo-dir={backend_dir}',
                    f'--android-dir={android_dir}',
                    f'--android-pub-head={pub_head}',
                    f'--android-exe-head={exe_head}',
                    f'--output-dir={output_dir}',
                    f'--paired-hold={args.paired_hold}',
                ]
                if args.expected_scope:
                    prep_cmd.append(f'--expected-scope={args.expected_scope}')
                if args.advanced_probes:
                    prep_cmd.append('--advanced-probes')
                argv_path = os.path.join(output_dir, 'argv.json')
                with open(argv_path, 'w') as f:
                    json.dump(prep_cmd, f, indent=2)
                os.chmod(argv_path, 0o600)

                print(f"[Operator] Preparing drill witness and manifest...")
                child_env = os.environ.copy()
                exit_code = run_supervised_child(prep_cmd, backend_dir, child_env, log_file, 60)
                if exit_code != 0:
                    raise RuntimeError(f"Preparation failed with exit code {prep_exit if 'prep_exit' in locals() else exit_code}")
                print(f"[Operator] Drill preparation completed successfully.")

            elif args.execute:
                # Mode: Live drill execution (Defect 1 fix: decoupled external inputs)
                if not args.manifest or not os.path.isfile(args.manifest):
                    raise ValueError("--execute mode requires a valid external --manifest file")
                if not args.manifest_sha:
                    raise ValueError("--execute mode requires an explicit --manifest-sha")

                manifest_sha = validate_manifest_sha(args.manifest_sha)
                actual_manifest_sha = sha256_file(args.manifest)
                if actual_manifest_sha != manifest_sha:
                    raise ValueError(
                        f"Manifest SHA mismatch: expected '{manifest_sha}', got actual '{actual_manifest_sha}'"
                    )

                if not args.root_approval_file or not os.path.isfile(args.root_approval_file):
                    raise ValueError("--execute mode requires a valid external --root-approval-file")

                if not args.root_live_approval_file:
                    raise ValueError("--execute mode requires --root-live-approval-file (private future target path)")
                validate_future_live_approval_path(args.root_live_approval_file)

                exec_cmd = [
                    'php',
                    os.path.join(backend_dir, 'tests/Support/Harness/supervised-financial-drill.php'),
                    '--execute',
                    f'--root-approval-file={args.root_approval_file}',
                    f'--root-live-approval-file={args.root_live_approval_file}',
                    f'--android-pub-head={pub_head}',
                    f'--android-exe-head={exe_head}',
                    f'--paired-hold-seconds={args.paired_hold}',
                    f'--manifest={args.manifest}',
                    f'--manifest-sha={manifest_sha}',
                    f'--android-repo-dir={android_dir}',
                    f'--output-dir={output_dir}'
                ]
                if args.expected_scope:
                    exec_cmd.append(f'--expected-scope={args.expected_scope}')
                if args.advanced_probes:
                    exec_cmd.append('--advanced-probes')
                argv_path = os.path.join(output_dir, 'argv.json')
                with open(argv_path, 'w') as f:
                    json.dump(exec_cmd, f, indent=2)
                os.chmod(argv_path, 0o600)

                print(f"[Operator] Executing supervised financial drill...")
                child_env = os.environ.copy()
                exit_code = run_supervised_child(exec_cmd, backend_dir, child_env, log_file, args.timeout)
                if exit_code != 0:
                    raise RuntimeError(f"Supervised drill failed with exit code {exit_code}")
                print(f"[Operator] Supervised drill completed successfully.")

    except Exception as e:
        print(f"\n[Operator] Execution failed: {e}", file=sys.stderr)
        failure_reason = str(e)
        exit_code = 1
    finally:
        end_monotonic = time.monotonic()
        end_iso = datetime.datetime.now(datetime.timezone.utc).isoformat()
        duration = round(end_monotonic - start_monotonic, 3)

        if output_dir_validated and output_dir is not None:
            print(f"[Operator] Capturing final POST state for both repositories...")
            try:
                post_state = {
                    'backend': get_git_state(backend_dir, DECLARED_OUTPUT_ARTIFACTS.get('backend')),
                    'android': get_git_state(android_dir, DECLARED_OUTPUT_ARTIFACTS.get('android')),
                    'metadata': {
                        'exit_code': exit_code,
                        'timestamp': end_iso,
                    }
                }
                post_path = os.path.join(output_dir, 'post.json')
                with open(post_path, 'w') as f:
                    json.dump(post_state, f, indent=2)
                os.chmod(post_path, 0o600)
                print(f"[Operator] Saved post.json (SHA: {sha256_file(post_path)})")

                # Drift comparison with explicit declared output artifact accounting
                if pre_state is not None:
                    drifts = compare_git_states(
                        pre_state, post_state,
                        declared_output_artifacts=DECLARED_OUTPUT_ARTIFACTS,
                        repo_dirs={'backend': backend_dir, 'android': android_dir}
                    )
                    if drifts:
                        print(f"[Operator] ERROR: Git repository drift detected between PRE and POST states:", file=sys.stderr)
                        print(json.dumps(drifts, indent=2), file=sys.stderr)
                        exit_code = 1
                        if failure_reason is None:
                            failure_reason = "GIT_DRIFT_DETECTED"
            except Exception as e:
                print(f"[Operator] ERROR: Failed to capture complete POST state: {e}", file=sys.stderr)
                exit_code = 1
                if failure_reason is None:
                    failure_reason = f"POST_CAPTURE_FAILED: {e}"

            # Always write exit_metadata.json; failure to write metadata sets exit_code = 1 (Defect 6 fix)
            try:
                metadata = {
                    'exit_code': exit_code,
                    'start_time': start_iso,
                    'end_time': end_iso,
                    'duration_seconds': duration,
                    'pre_post_matched': (len(drifts) == 0 and pre_state is not None and post_state is not None),
                    'drift_detected': bool(drifts),
                    'drift_details': drifts if drifts else None,
                    'failure_reason': failure_reason,
                }
                meta_path = os.path.join(output_dir, 'exit_metadata.json')
                with open(meta_path, 'w') as f:
                    json.dump(metadata, f, indent=2)
                os.chmod(meta_path, 0o600)
                print(f"[Operator] Saved exit_metadata.json (exit_code: {exit_code})")
            except Exception as e:
                print(f"[Operator] ERROR: Failed to write exit metadata: {e}", file=sys.stderr)
                exit_code = 1

    sys.exit(exit_code)

if __name__ == '__main__':
    main()
