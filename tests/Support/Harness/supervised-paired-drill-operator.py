#!/usr/bin/env python3
"""
Mobile V2 Supervised Paired Financial Drill Operator Driver
===========================================================
Repo-tracked, reviewable operator runner replacing ad-hoc scratch scripts.

Strict Adversarial Execution Invariants:
1. Zero Git Mutations:
   NEVER executes git checkout, reset, clean, or any command that mutates
   worktree state, index, or HEAD in either repository.
2. Protected Active Lane Refusal:
   Strictly refuses to target active development lanes (e.g. *7ac8* / t3-7ac8b73d).
   Fails closed immediately if an active lane path is supplied.
3. Pre-Action Dual Repo State Capture:
   Captures complete PRE state (HEAD, status, tracked file SHA-256) for BOTH
   backend and Android repositories BEFORE executing any child commands.
4. Mismatch Fail-Closed:
   If Android repository HEAD differs from the required publication pin,
   or if uncommitted tracked modifications exist, fails closed immediately.
5. Child Process Supervision & Reaping:
   Supervises child processes in isolated process groups (setsid), monitors
   timeouts, and cleanly reaps entire process trees on signal or deadline.
6. Secure External Artifact Retention:
   All outputs, logs, and witness files are persisted to an external private
   directory (0700 dir, 0600 files) outside repository checkouts.
7. Post-Execution Dual Repo State Capture:
   Captures complete POST state for both repositories on success, failure, or signal.
"""

import os
import sys
import json
import time
import signal
import hashlib
import datetime
import argparse
import subprocess

def get_default_repo_dir():
    candidate = os.path.dirname(os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__)))))
    try:
        git_root = subprocess.check_output(
            ['git', 'rev-parse', '--show-toplevel'],
            cwd=os.path.dirname(os.path.abspath(__file__))
        ).decode().strip()
        if os.path.isdir(git_root):
            return git_root
    except Exception:
        pass
    return candidate

DEFAULT_BACKEND_DIR = get_default_repo_dir()

def assert_not_protected_worktree(path):
    norm = os.path.normpath(os.path.abspath(path))
    if '7ac8' in norm or 't3-7ac8b73d' in norm:
        raise ValueError(
            f"Protected active Android lane path refusal ({norm}). "
            "Paired execution requires an isolated, Root-approved worktree."
        )

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

def verify_clean_tracked_files(repo_dir, name):
    try:
        diff_proc = subprocess.run(
            ['git', 'diff-index', '--quiet', 'HEAD', '--'],
            cwd=repo_dir
        )
        if diff_proc.returncode != 0:
            modified = subprocess.check_output(
                ['git', 'diff', '--name-only', 'HEAD'],
                cwd=repo_dir
            ).decode().strip()
            raise RuntimeError(f"{name} has modified tracked files vs HEAD:\n{modified}")
    except subprocess.CalledProcessError as e:
        raise RuntimeError(f"Failed to check git diff for {name}: {e}")

def run_supervised_child(cmd, cwd, env, log_file_handle, timeout_seconds):
    """
    Spawns child in new process group and streams output to log file.
    Reaps entire process group on timeout or signal.
    """
    proc = subprocess.Popen(
        cmd,
        cwd=cwd,
        env=env,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        start_new_session=True
    )

    start_time = time.time()
    timed_out = False

    def sig_handler(signum, frame):
        try:
            os.killpg(proc.pid, signal.SIGTERM)
            time.sleep(1.0)
            os.killpg(proc.pid, signal.SIGKILL)
        except ProcessLookupError:
            pass
        raise RuntimeError(f"Supervised child aborted by signal {signum}")

    old_sigint = signal.signal(signal.SIGINT, sig_handler)
    old_sigterm = signal.signal(signal.SIGTERM, sig_handler)

    try:
        while True:
            line = proc.stdout.readline()
            if not line:
                break
            line_str = line.decode('utf-8', errors='replace')
            sys.stdout.write(line_str)
            sys.stdout.flush()
            log_file_handle.write(line_str)
            log_file_handle.flush()

            if (time.time() - start_time) > timeout_seconds:
                timed_out = True
                try:
                    os.killpg(proc.pid, signal.SIGTERM)
                    time.sleep(1.0)
                    os.killpg(proc.pid, signal.SIGKILL)
                except ProcessLookupError:
                    pass
                break

        proc.stdout.close()
        proc.wait()
    finally:
        signal.signal(signal.SIGINT, old_sigint)
        signal.signal(signal.SIGTERM, old_sigterm)

    if timed_out:
        raise TimeoutError(f"Child process timed out after {timeout_seconds} seconds")

    return proc.returncode

def main():
    parser = argparse.ArgumentParser(
        description="Mobile V2 Supervised Paired Financial Drill Operator Driver"
    )
    parser.add_argument("--output-dir", required=True, help="Private directory for artifacts and witness (0700)")
    parser.add_argument("--backend-dir", default=DEFAULT_BACKEND_DIR, help="Backend repository root")
    parser.add_argument("--android-dir", required=True, help="Android repository checkout root")
    parser.add_argument("--android-pub-head", required=True, help="Root-approved peer Android publication HEAD SHA (40 hex)")
    parser.add_argument("--android-exe-head", required=True, help="Root-approved peer Android executable HEAD SHA (40 hex)")
    parser.add_argument("--prewarm-only", action="store_true", help="Execute compile-only prewarm task (no drill tests)")
    parser.add_argument("--offline", action="store_true", help="Pass --offline flag to Gradle prewarm")
    parser.add_argument("--paired-hold", type=int, default=120, help="Paired hold seconds (default: 120)")
    parser.add_argument("--timeout", type=int, default=180, help="Supervised execution timeout in seconds (default: 180)")
    args = parser.parse_args()

    output_dir = os.path.abspath(args.output_dir)
    backend_dir = os.path.abspath(args.backend_dir)
    android_dir = os.path.abspath(args.android_dir)

    # 1. Immediate protected lane check (fail-closed before any action)
    try:
        assert_not_protected_worktree(android_dir)
        assert_not_protected_worktree(backend_dir)
    except ValueError as e:
        print(f"FATAL: {e}", file=sys.stderr)
        sys.exit(1)

    if not os.path.isdir(backend_dir):
        print(f"FATAL: Backend repo directory not found: {backend_dir}", file=sys.stderr)
        sys.exit(1)

    if not os.path.isdir(android_dir):
        print(f"FATAL: Android repo directory not found: {android_dir}", file=sys.stderr)
        sys.exit(1)

    pub_head = args.android_pub_head.strip().lower()
    exe_head = args.android_exe_head.strip().lower()
    if len(pub_head) != 40 or len(exe_head) != 40:
        print("FATAL: --android-pub-head and --android-exe-head must be 40-character hex SHAs", file=sys.stderr)
        sys.exit(1)

    # 2. Verify Android HEAD without mutation
    try:
        actual_android_head = subprocess.check_output(
            ['git', 'rev-parse', 'HEAD'],
            cwd=android_dir
        ).decode().strip()
    except Exception as e:
        print(f"FATAL: Failed to read Android repo HEAD: {e}", file=sys.stderr)
        sys.exit(1)

    if actual_android_head != pub_head:
        print(f"FATAL: Android repository HEAD ({actual_android_head}) does not match expected publication pin ({pub_head})", file=sys.stderr)
        print("FAIL-CLOSED: Worktree checkout must not be mutated. Provide a worktree at the expected publication pin.", file=sys.stderr)
        sys.exit(1)

    # 3. Verify clean tracked status for both repos
    try:
        verify_clean_tracked_files(backend_dir, "Backend repository")
        verify_clean_tracked_files(android_dir, "Android repository")
    except RuntimeError as e:
        print(f"FATAL: {e}", file=sys.stderr)
        sys.exit(1)

    # 4. Initialize secure output directory (0700)
    os.makedirs(output_dir, mode=0o700, exist_ok=True)
    os.chmod(output_dir, 0o700)

    # 5. Capture PRE state for BOTH repositories before any other execution
    print(f"[Operator] Capturing initial PRE state for both repositories...")
    pre_state = {
        'backend': get_git_state(backend_dir),
        'android': get_git_state(android_dir),
        'metadata': {
            'expected_pub_head': pub_head,
            'expected_exe_head': exe_head,
            'timestamp': datetime.datetime.now(datetime.timezone.utc).isoformat(),
        }
    }
    pre_path = os.path.join(output_dir, 'pre.json')
    with open(pre_path, 'w') as f:
        json.dump(pre_state, f, indent=2)
    os.chmod(pre_path, 0o600)
    print(f"[Operator] Saved pre.json (SHA: {sha256_file(pre_path)})")

    # Open execution log file (0600)
    log_path = os.path.join(output_dir, 'operator_execution.log')
    with open(log_path, 'w') as f:
        f.write(f"=== Operator Run Started at {datetime.datetime.now(datetime.timezone.utc).isoformat()} ===\n")
    os.chmod(log_path, 0o600)

    post_state_captured = False
    exit_code = 0

    try:
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

                # Record argv
                argv_path = os.path.join(output_dir, 'argv.json')
                with open(argv_path, 'w') as f:
                    json.dump(cmd, f, indent=2)
                os.chmod(argv_path, 0o600)

                child_env = os.environ.copy()
                exit_code = run_supervised_child(cmd, backend_dir, child_env, log_file, args.timeout)
                if exit_code != 0:
                    raise RuntimeError(f"Prewarm failed with exit code {exit_code}")
                print(f"[Operator] Prewarm completed successfully (code 0).")
            else:
                # Mode: Full prepare + drill invocation
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
                print(f"[Operator] Preparing drill witness and manifest...")
                child_env = os.environ.copy()
                prep_exit = run_supervised_child(prep_cmd, backend_dir, child_env, log_file, 60)
                if prep_exit != 0:
                    raise RuntimeError(f"Preparation failed with exit code {prep_exit}")
                print(f"[Operator] Drill preparation completed successfully.")

    except Exception as e:
        print(f"\n[Operator] Execution failed: {e}", file=sys.stderr)
        exit_code = 1
    finally:
        # 6. Capture POST state for BOTH repositories in all conditions
        print(f"[Operator] Capturing final POST state for both repositories...")
        try:
            post_state = {
                'backend': get_git_state(backend_dir),
                'android': get_git_state(android_dir),
                'metadata': {
                    'exit_code': exit_code,
                    'timestamp': datetime.datetime.now(datetime.timezone.utc).isoformat(),
                }
            }
            post_path = os.path.join(output_dir, 'post.json')
            with open(post_path, 'w') as f:
                json.dump(post_state, f, indent=2)
            os.chmod(post_path, 0o600)
            print(f"[Operator] Saved post.json (SHA: {sha256_file(post_path)})")
            post_state_captured = True
        except Exception as e:
            print(f"[Operator] Warning: Failed to capture complete POST state: {e}", file=sys.stderr)

    sys.exit(exit_code)

if __name__ == '__main__':
    main()
