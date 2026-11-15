#!/usr/bin/env python3
"""
Mobile V2 Supervised Paired Financial Drill Operator Driver
===========================================================
Repo-tracked, reviewable operator runner replacing ad-hoc scratch scripts.

Strict Adversarial Execution Invariants (Remediated per Root Audit):
1. Zero Git Mutations:
   NEVER executes git checkout, reset, clean, or any command that mutates
   worktree state, index, or HEAD in either repository.
2. Canonical Protected Active Lane Refusal:
   Resolves canonical realpath (no symlinks) and strictly refuses any path
   matching *7ac8* or t3-7ac8b73d.
3. Secure Canonical Output Directory:
   Validates all path components against symlinks, verifies external location
   relative to repository checkouts, enforces 0700 UID ownership, and refuses
   existing non-empty directories to prevent overwrite.
4. Non-Blocking I/O & Bounded Process Reaping:
   Uses selectors with non-blocking stdout and monotonic deadline checks.
   Silent or unterminated output cannot hang the runner. Child process groups
   are terminated with SIGTERM, bounded grace period (2.0s), and SIGKILL.
5. Exact Initial Clean Status & Commit Existence:
   Enforces exact clean status (git status --porcelain empty) on both repos.
   Validates 40-hex format and commit object existence (git cat-file -e) for
   publication, executable, and expected backend HEAD pins.
6. Pre/Post Drift Verification & Comprehensive Exit Metadata:
   Compares pre and post repository states (HEAD, status, tracked file hashes).
   Any detected drift fails non-zero. Writes exit_metadata.json on all paths.
7. Truthful Execution Mode:
   Explicit mutually exclusive modes: --prewarm-only, --prepare-only, or --execute.
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
    """Validates that no component in the path is a symlink."""
    curr = os.path.abspath(path)
    while curr and curr != '/' and curr != os.path.dirname(curr):
        if os.path.islink(curr):
            raise ValueError(f"{label} component is a symlink: {curr}")
        curr = os.path.dirname(curr)

def assert_not_protected_worktree(path):
    """Enforces canonical realpath check refusing active 7ac8 development lane."""
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
    - No symlinks in any component
    - Canonical path strictly outside backend and android checkouts
    - Mode 0700 owned by current UID
    - Refuses existing non-empty directory
    """
    validate_no_symlink_components(output_dir, "Output directory")
    canonical_out = os.path.realpath(output_dir)
    canonical_backend = os.path.realpath(backend_dir)
    canonical_android = os.path.realpath(android_dir)

    # Ensure strictly external to repository checkouts
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
        # Refuse existing non-empty directory to prevent arbitrary pre/post overwrite
        entries = os.listdir(canonical_out)
        if entries:
            raise ValueError(f"Output directory already exists and is not empty: {canonical_out} (contains {len(entries)} items)")
    else:
        os.makedirs(canonical_out, mode=0o700, exist_ok=False)
        st = os.stat(canonical_out)
        if (st.st_mode & 0o777) != 0o700:
            os.chmod(canonical_out, 0o700)

    return canonical_out

def validate_commit_sha(sha, label):
    """Validates 40-character lowercase hexadecimal SHA string."""
    cleaned = sha.strip().lower()
    if not HEX40_REGEX.match(cleaned):
        raise ValueError(f"{label} must be a 40-character lowercase hex SHA: got '{sha}'")
    return cleaned

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

def get_git_state(repo_dir):
    head = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=repo_dir).decode().strip()
    status = subprocess.check_output(['git', 'status', '--porcelain'], cwd=repo_dir).decode().strip()
    files = subprocess.check_output(['git', 'ls-files'], cwd=repo_dir).decode().splitlines()
    hashes = {}
    for rel_path in files:
        full_path = os.path.join(repo_dir, rel_path)
        if os.path.isfile(full_path) and not os.path.islink(full_path):
            with open(full_path, 'rb') as f:
                hashes[rel_path] = hashlib.sha256(f.read()).hexdigest()
    return {
        'head': head,
        'status': status,
        'sha256': hashes,
        'captured_at': datetime.datetime.now(datetime.timezone.utc).isoformat(),
    }

def sha256_file(filepath):
    h = hashlib.sha256()
    with open(filepath, 'rb') as f:
        while chunk := f.read(65536):
            h.update(chunk)
    return h.hexdigest()

def verify_exact_clean_status(repo_dir, name):
    """Verifies that git status --porcelain is strictly empty (no modified or untracked files)."""
    status = subprocess.check_output(['git', 'status', '--porcelain'], cwd=repo_dir).decode().strip()
    if status != '':
        raise RuntimeError(f"{name} is not in clean state (expected empty status):\n{status}")

def compare_git_states(pre_state, post_state):
    """Compares pre and post git states; returns dict of drifts if any detected."""
    drifts = {}
    for repo in ['backend', 'android']:
        if repo not in pre_state or repo not in post_state:
            drifts[repo] = "Missing pre or post state record"
            continue
        pre_repo = pre_state[repo]
        post_repo = post_state[repo]
        if pre_repo['head'] != post_repo['head']:
            drifts[f"{repo}_head_drift"] = {
                'pre': pre_repo['head'],
                'post': post_repo['head']
            }
        if pre_repo['status'] != post_repo['status']:
            drifts[f"{repo}_status_drift"] = {
                'pre': pre_repo['status'],
                'post': post_repo['status']
            }
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
    return drifts

def run_supervised_child(cmd, cwd, env, log_file_handle, timeout_seconds):
    """
    Non-blocking supervised child runner with selectors:
    - Sets child stdout to non-blocking.
    - Monitors monotonic deadline continuously even on silent / unterminated child output.
    - Reaps process group with SIGTERM, 2.0s grace period, then SIGKILL and wait.
    - Reaps process group on any log write exception or signal.
    """
    proc = subprocess.Popen(
        cmd,
        cwd=cwd,
        env=env,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        start_new_session=True
    )

    sel = selectors.DefaultSelector()
    os.set_blocking(proc.stdout.fileno(), False)
    sel.register(proc.stdout, selectors.EVENT_READ)

    start_monotonic = time.monotonic()
    timed_out = False

    def reap_process_group():
        try:
            os.killpg(proc.pid, signal.SIGTERM)
        except ProcessLookupError:
            return
        # Grace period bounded wait
        grace_end = time.monotonic() + 2.0
        while time.monotonic() < grace_end:
            if proc.poll() is not None:
                return
            time.sleep(0.05)
        # Escalate to SIGKILL
        try:
            os.killpg(proc.pid, signal.SIGKILL)
        except ProcessLookupError:
            pass
        try:
            proc.wait(timeout=2.0)
        except subprocess.TimeoutExpired:
            pass

    def sig_handler(signum, frame):
        reap_process_group()
        raise RuntimeError(f"Supervised child aborted by signal {signum}")

    old_sigint = signal.signal(signal.SIGINT, sig_handler)
    old_sigterm = signal.signal(signal.SIGTERM, sig_handler)

    try:
        while True:
            now = time.monotonic()
            rem = (start_monotonic + timeout_seconds) - now
            if rem <= 0:
                timed_out = True
                reap_process_group()
                break

            events = sel.select(timeout=min(rem, 0.2))
            for key, mask in events:
                try:
                    chunk = proc.stdout.read()
                except Exception:
                    chunk = None
                if chunk:
                    text = chunk.decode('utf-8', errors='replace')
                    sys.stdout.write(text)
                    sys.stdout.flush()
                    log_file_handle.write(text)
                    log_file_handle.flush()

            if proc.poll() is not None:
                # Drain remaining output
                try:
                    chunk = proc.stdout.read()
                    if chunk:
                        text = chunk.decode('utf-8', errors='replace')
                        sys.stdout.write(text)
                        sys.stdout.flush()
                        log_file_handle.write(text)
                        log_file_handle.flush()
                except Exception:
                    pass
                break
    except Exception:
        reap_process_group()
        raise
    finally:
        sel.close()
        try:
            proc.stdout.close()
        except Exception:
            pass
        signal.signal(signal.SIGINT, old_sigint)
        signal.signal(signal.SIGTERM, old_sigterm)
        if proc.poll() is None:
            reap_process_group()

    if timed_out:
        raise TimeoutError(f"Child process timed out after {timeout_seconds} seconds")

    return proc.returncode

def main():
    parser = argparse.ArgumentParser(
        description="Mobile V2 Supervised Paired Financial Drill Operator Driver"
    )
    parser.add_argument("--output-dir", required=True, help="Private unique directory for artifacts and witness (0700)")
    parser.add_argument("--backend-dir", default=DEFAULT_BACKEND_DIR, help="Backend repository root")
    parser.add_argument("--android-dir", required=True, help="Android repository checkout root")
    parser.add_argument("--expected-backend-head", required=True, help="Expected backend HEAD SHA (40 hex)")
    parser.add_argument("--android-pub-head", required=True, help="Root-approved peer Android publication HEAD SHA (40 hex)")
    parser.add_argument("--android-exe-head", required=True, help="Root-approved peer Android executable HEAD SHA (40 hex)")
    parser.add_argument("--offline", action="store_true", help="Pass --offline flag to Gradle prewarm")
    parser.add_argument("--paired-hold", type=int, default=120, help="Paired hold seconds (default: 120)")
    parser.add_argument("--timeout", type=int, default=180, help="Supervised execution timeout in seconds (default: 180)")

    # Truth in naming & execution mode: mutually exclusive explicit action flags
    mode_group = parser.add_mutually_exclusive_group(required=True)
    mode_group.add_argument("--prewarm-only", action="store_true", help="Execute compile-only prewarm task (paired-drill-prewarm.sh)")
    mode_group.add_argument("--prepare-only", action="store_true", help="Execute preparation witness only (root-operator-tool.py prepare)")
    mode_group.add_argument("--execute", action="store_true", help="Execute live supervised drill (supervised-financial-drill.php --execute)")

    # Execute mode arguments (separate Root approval files; no self-approval)
    parser.add_argument("--root-approval-file", default=None, help="Root prestart approval file (required for --execute)")
    parser.add_argument("--root-live-approval-file", default=None, help="Root live witness approval file (required for --execute)")

    args = parser.parse_args()

    start_iso = datetime.datetime.now(datetime.timezone.utc).isoformat()
    start_monotonic = time.monotonic()
    output_dir_validated = False
    exit_code = 1
    failure_reason = None
    pre_state = None
    post_state = None
    drifts = {}

    try:
        # 1. Validate pins format
        expected_backend = validate_commit_sha(args.expected_backend_head, "Expected backend HEAD")
        pub_head = validate_commit_sha(args.android_pub_head, "Android publication HEAD")
        exe_head = validate_commit_sha(args.android_exe_head, "Android executable HEAD")

        # 2. Validate paths & refuse protected active lane
        backend_dir = os.path.realpath(args.backend_dir)
        android_dir = os.path.realpath(args.android_dir)

        assert_not_protected_worktree(backend_dir)
        assert_not_protected_worktree(android_dir)

        if not os.path.isdir(backend_dir):
            raise ValueError(f"Backend repo directory not found: {backend_dir}")
        if not os.path.isdir(android_dir):
            raise ValueError(f"Android repo directory not found: {android_dir}")

        # 3. Verify commit existence in object store
        verify_commit_exists(backend_dir, expected_backend, "Backend expected HEAD")
        verify_commit_exists(android_dir, pub_head, "Android publication HEAD")
        verify_commit_exists(android_dir, exe_head, "Android executable HEAD")

        # 4. Verify actual backend HEAD matches expected pin
        actual_backend_head = subprocess.check_output(
            ['git', 'rev-parse', 'HEAD'], cwd=backend_dir
        ).decode().strip()
        if actual_backend_head != expected_backend:
            raise RuntimeError(f"Backend HEAD mismatch: actual {actual_backend_head} != expected {expected_backend}")

        # 5. Verify actual Android HEAD matches publication pin without mutation
        actual_android_head = subprocess.check_output(
            ['git', 'rev-parse', 'HEAD'], cwd=android_dir
        ).decode().strip()
        if actual_android_head != pub_head:
            raise RuntimeError(
                f"Android repository HEAD ({actual_android_head}) does not match expected publication pin ({pub_head}). "
                "FAIL-CLOSED: Worktree checkout must not be mutated."
            )

        # 6. Verify exact clean status on BOTH repos (no uncommitted or untracked changes)
        verify_exact_clean_status(backend_dir, "Backend repository")
        verify_exact_clean_status(android_dir, "Android repository")

        # 7. Validate secure output directory
        output_dir = validate_secure_output_dir(args.output_dir, backend_dir, android_dir)
        output_dir_validated = True

        # 8. Capture initial immutable PRE state for BOTH repos
        print(f"[Operator] Capturing initial PRE state for both repositories...")
        pre_state = {
            'backend': get_git_state(backend_dir),
            'android': get_git_state(android_dir),
            'metadata': {
                'expected_backend_head': expected_backend,
                'expected_pub_head': pub_head,
                'expected_exe_head': exe_head,
                'timestamp': start_iso,
            }
        }
        pre_path = os.path.join(output_dir, 'pre.json')
        # Exclusive file creation to guarantee immutability
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
                argv_path = os.path.join(output_dir, 'argv.json')
                with open(argv_path, 'w') as f:
                    json.dump(prep_cmd, f, indent=2)
                os.chmod(argv_path, 0o600)

                print(f"[Operator] Preparing drill witness and manifest...")
                child_env = os.environ.copy()
                exit_code = run_supervised_child(prep_cmd, backend_dir, child_env, log_file, 60)
                if exit_code != 0:
                    raise RuntimeError(f"Preparation failed with exit code {exit_code}")
                print(f"[Operator] Drill preparation completed successfully.")

            elif args.execute:
                # Mode: Live drill execution (requires external Root approval files)
                if not args.root_approval_file or not os.path.isfile(args.root_approval_file):
                    raise ValueError("--execute mode requires a valid --root-approval-file")
                if not args.root_live_approval_file or not os.path.isfile(args.root_live_approval_file):
                    raise ValueError("--execute mode requires a valid --root-live-approval-file")

                manifest_path = os.path.join(output_dir, 'pairing-manifest.json')
                if not os.path.isfile(manifest_path):
                    raise ValueError(f"pairing-manifest.json not found in {output_dir}; run --prepare-only first")
                manifest_sha = sha256_file(manifest_path)

                exec_cmd = [
                    'php',
                    os.path.join(backend_dir, 'tests/Support/Harness/supervised-financial-drill.php'),
                    '--execute',
                    f'--root-approval-file={args.root_approval_file}',
                    f'--root-live-approval-file={args.root_live_approval_file}',
                    f'--android-pub-head={pub_head}',
                    f'--android-exe-head={exe_head}',
                    f'--paired-hold-seconds={args.paired_hold}',
                    f'--manifest={manifest_path}',
                    f'--manifest-sha={manifest_sha}',
                    f'--android-repo-dir={android_dir}',
                    f'--output-dir={output_dir}'
                ]
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

        if output_dir_validated:
            print(f"[Operator] Capturing final POST state for both repositories...")
            try:
                post_state = {
                    'backend': get_git_state(backend_dir),
                    'android': get_git_state(android_dir),
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

                # Drift comparison
                if pre_state is not None:
                    drifts = compare_git_states(pre_state, post_state)
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

            # Always write exit_metadata.json
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
                print(f"[Operator] Warning: Failed to write exit metadata: {e}", file=sys.stderr)

    sys.exit(exit_code)

if __name__ == '__main__':
    main()
