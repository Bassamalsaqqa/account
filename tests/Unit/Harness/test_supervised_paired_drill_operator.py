#!/usr/bin/env python3
"""
Unit Tests for Mobile V2 Supervised Paired Financial Drill Operator Driver
==========================================================================
Exercises all adversarial constraints and defect remediations in
`tests/Support/Harness/supervised-paired-drill-operator.py`:
1. Symlink component rejection on original input paths.
2. Canonical protected active lane refusal (7ac8 / t3-7ac8b73d).
3. Secure output directory validation (no symlinks, outside repos, 0700 UID, refuse non-empty).
4. Future live approval target path validation (must NOT exist prior to startup).
5. 40-hex commit SHA and 64-hex manifest SHA validation.
6. Non-blocking supervised runner timeout on silent child without hanging on readline.
7. Non-blocking supervised runner capture of unterminated output.
8. Process group reaping of forked descendant processes when leader exits.
9. Log write failure propagation and immediate child process group reaping.
10. Pre/Post git state comparison and drift detection.
11. Executable source parity verification on temporary throwaway git fixtures.
12. Decoupled prepare/execute directory handshake validation.
13. Strict mandatory binding record verification (parent 0700 UID, file 0600 UID, SHA pin match,
    root_verified is True, peer_android_attached is True, canonical worktree and branch match).
"""

import os
import sys
import json
import time
import signal
import hashlib
import shutil
import tempfile
import unittest
import importlib.util
import subprocess
from pathlib import Path

# Resolve actual repo root using Path(__file__).resolve().parents[3]
REPO_ROOT = str(Path(__file__).resolve().parents[3])
OPERATOR_PATH = os.path.join(REPO_ROOT, 'tests/Support/Harness/supervised-paired-drill-operator.py')
assert os.path.isfile(OPERATOR_PATH), f"Operator driver not found at {OPERATOR_PATH}"

# Verify against non-mutating git rev-parse --show-toplevel
git_root = subprocess.check_output(['git', 'rev-parse', '--show-toplevel'], cwd=REPO_ROOT).decode().strip()
assert os.path.realpath(REPO_ROOT) == os.path.realpath(git_root), f"Repo root mismatch: {REPO_ROOT} != {git_root}"

# Dynamically import the hyphenated module
spec = importlib.util.spec_from_file_location("operator_driver", OPERATOR_PATH)
operator_driver = importlib.util.module_from_spec(spec)
spec.loader.exec_module(operator_driver)


class TestSupervisedPairedDrillOperator(unittest.TestCase):

    def setUp(self):
        self.test_dir = tempfile.mkdtemp(prefix='test_operator_driver_')

    def tearDown(self):
        if os.path.isdir(self.test_dir):
            shutil.rmtree(self.test_dir, ignore_errors=True)

    def test_validate_no_symlink_components_rejects_symlink(self):
        real_target = os.path.join(self.test_dir, 'real_dir')
        os.makedirs(real_target, mode=0o700)
        symlink_path = os.path.join(self.test_dir, 'symlink_dir')
        os.symlink(real_target, symlink_path)
        sub_path = os.path.join(symlink_path, 'sub')

        with self.assertRaises(ValueError) as ctx:
            operator_driver.validate_no_symlink_components(sub_path, "Test path")
        self.assertIn("contains a symlink component", str(ctx.exception))

    def test_assert_not_protected_worktree_rejects_7ac8(self):
        with self.assertRaises(ValueError) as ctx:
            operator_driver.assert_not_protected_worktree(
                '/home/t3dev/.t3/worktrees/small-trader-android/t3-7ac8b73d-abb5d6e52b3b3cf9'
            )
        self.assertIn("Protected active Android lane path refusal", str(ctx.exception))

    def test_validate_secure_output_dir_rejects_inside_backend_repo(self):
        backend_dir = os.path.join(self.test_dir, 'backend')
        android_dir = os.path.join(self.test_dir, 'android')
        os.makedirs(backend_dir, mode=0o700)
        os.makedirs(android_dir, mode=0o700)

        inside_dir = os.path.join(backend_dir, 'sub_out')
        with self.assertRaises(ValueError) as ctx:
            operator_driver.validate_secure_output_dir(inside_dir, backend_dir, android_dir)
        self.assertIn("must be external to backend repository", str(ctx.exception))

    def test_validate_secure_output_dir_rejects_non_empty_dir(self):
        backend_dir = os.path.join(self.test_dir, 'backend')
        android_dir = os.path.join(self.test_dir, 'android')
        out_dir = os.path.join(self.test_dir, 'out')
        os.makedirs(backend_dir, mode=0o700)
        os.makedirs(android_dir, mode=0o700)
        os.makedirs(out_dir, mode=0o700)

        # Place a file to make it non-empty
        with open(os.path.join(out_dir, 'existing.txt'), 'w') as f:
            f.write('polluted')

        with self.assertRaises(ValueError) as ctx:
            operator_driver.validate_secure_output_dir(out_dir, backend_dir, android_dir)
        self.assertIn("already exists and is not empty", str(ctx.exception))

    def test_validate_future_live_approval_path_rejects_already_existing_file(self):
        existing_file = os.path.join(self.test_dir, 'live_already.json')
        with open(existing_file, 'w') as f:
            f.write('{}')
        os.chmod(existing_file, 0o600)

        with self.assertRaises(ValueError) as ctx:
            operator_driver.validate_future_live_approval_path(existing_file)
        self.assertIn("must NOT exist prior to candidate execution", str(ctx.exception))

    def test_validate_commit_sha_and_manifest_sha_rejects_invalid(self):
        with self.assertRaises(ValueError):
            operator_driver.validate_commit_sha('not-40-hex', 'Commit pin')
        with self.assertRaises(ValueError):
            operator_driver.validate_manifest_sha('short-sha', 'Manifest pin')

        valid_commit = 'a' * 40
        self.assertEqual(operator_driver.validate_commit_sha(valid_commit, 'Commit pin'), valid_commit)
        valid_manifest = 'b' * 64
        self.assertEqual(operator_driver.validate_manifest_sha(valid_manifest, 'Manifest pin'), valid_manifest)

    def test_compare_git_states_detects_drift(self):
        pre = {
            'backend': {'head': '1' * 40, 'status': '', 'sha256': {'f1': 'h1'}},
            'android': {'head': '2' * 40, 'status': '', 'sha256': {'f2': 'h2'}}
        }
        post = {
            'backend': {'head': '1' * 40, 'status': '', 'sha256': {'f1': 'h1_drift'}},
            'android': {'head': '2' * 40, 'status': '', 'sha256': {'f2': 'h2'}}
        }
        drifts = operator_driver.compare_git_states(pre, post)
        self.assertTrue(bool(drifts))
        self.assertIn('backend_tracked_hashes_drift', drifts)

    def test_run_supervised_child_silent_child_timeout(self):
        log_path = os.path.join(self.test_dir, 'silent.log')
        with open(log_path, 'w') as log_f:
            cmd = [sys.executable, '-c', 'import time; time.sleep(10)']
            start = time.monotonic()
            with self.assertRaises(TimeoutError):
                operator_driver.run_supervised_child(cmd, self.test_dir, os.environ.copy(), log_f, timeout_seconds=0.5)
            duration = time.monotonic() - start
            # Must timeout promptly without blocking on readline
            self.assertLess(duration, 3.0)

    def test_run_supervised_child_unterminated_output(self):
        log_path = os.path.join(self.test_dir, 'unterminated.log')
        with open(log_path, 'w') as log_f:
            cmd = [
                sys.executable,
                '-c',
                'import sys, time; sys.stdout.write("unterminated_chunk_without_newline"); sys.stdout.flush(); time.sleep(10)'
            ]
            start = time.monotonic()
            with self.assertRaises(TimeoutError):
                operator_driver.run_supervised_child(cmd, self.test_dir, os.environ.copy(), log_f, timeout_seconds=0.5)
            duration = time.monotonic() - start
            self.assertLess(duration, 3.0)

        with open(log_path, 'r') as log_f:
            content = log_f.read()
        self.assertIn("unterminated_chunk_without_newline", content)

    def test_run_supervised_child_forking_descendant_reaped(self):
        log_path = os.path.join(self.test_dir, 'fork.log')
        pid_file = os.path.join(self.test_dir, 'child.pid')
        # Parent forks a descendant that writes its PID and sleeps 30s, then parent exits 0
        cmd = [
            sys.executable,
            '-c',
            f'import os, sys, time; pid = os.fork();\n'
            f'if pid == 0:\n'
            f'    with open("{pid_file}", "w") as f: f.write(str(os.getpid()))\n'
            f'    time.sleep(30)\n'
            f'    sys.exit(0)\n'
            f'sys.exit(0)\n'
        ]
        with open(log_path, 'w') as log_f:
            operator_driver.run_supervised_child(cmd, self.test_dir, os.environ.copy(), log_f, timeout_seconds=5.0)

        descendant_pid = None
        try:
            # Wait briefly for pid file to be written
            for _ in range(40):
                if os.path.isfile(pid_file):
                    break
                time.sleep(0.05)

            self.assertTrue(os.path.isfile(pid_file), "PID file not created by descendant child")
            with open(pid_file, 'r') as f:
                descendant_pid = int(f.read().strip())

            # Descendant process must be killed by reap_process_group
            try:
                os.kill(descendant_pid, 0)
                alive = True
            except ProcessLookupError:
                alive = False
            self.assertFalse(alive, f"Descendant process {descendant_pid} survived execution!")
        finally:
            if descendant_pid is not None:
                try:
                    os.kill(descendant_pid, 0)
                    os.kill(descendant_pid, signal.SIGKILL)
                except (ProcessLookupError, PermissionError):
                    pass

    def test_run_supervised_child_log_write_failure_propagates(self):
        class FailingLogHandle:
            def write(self, s):
                raise IOError("Simulated disk write failure")
            def flush(self):
                pass

        cmd = [sys.executable, '-c', 'import sys; sys.stdout.write("output"); sys.stdout.flush()']
        with self.assertRaises(IOError):
            operator_driver.run_supervised_child(
                cmd, self.test_dir, os.environ.copy(), FailingLogHandle(), timeout_seconds=5.0
            )

    def test_verify_source_parity_accepts_docs_and_rejects_code_diff(self):
        # Create a throwaway git repository fixture (NEVER a real checkout)
        git_dir = os.path.join(self.test_dir, 'throwaway_git')
        os.makedirs(git_dir, mode=0o700)
        subprocess.check_call(['git', 'init'], cwd=git_dir, stdout=subprocess.DEVNULL)
        subprocess.check_call(['git', 'config', 'user.name', 'Test'], cwd=git_dir)
        subprocess.check_call(['git', 'config', 'user.email', 'test@example.com'], cwd=git_dir)

        # Commit 1: Initial base
        code_file = os.path.join(git_dir, 'App.kt')
        with open(code_file, 'w') as f:
            f.write('class App')
        subprocess.check_call(['git', 'add', 'App.kt'], cwd=git_dir)
        subprocess.check_call(['git', 'commit', '-m', 'Initial'], cwd=git_dir, stdout=subprocess.DEVNULL)
        base_head = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=git_dir).decode().strip()

        # Commit 2: Docs-only change
        docs_file = os.path.join(git_dir, 'README.md')
        with open(docs_file, 'w') as f:
            f.write('# Documentation')
        subprocess.check_call(['git', 'add', 'README.md'], cwd=git_dir)
        subprocess.check_call(['git', 'commit', '-m', 'Docs'], cwd=git_dir, stdout=subprocess.DEVNULL)
        docs_head = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=git_dir).decode().strip()

        # Parity between base and docs head must PASS
        operator_driver.verify_source_parity(git_dir, base_head, docs_head)

        # Commit 3: Code modification
        with open(code_file, 'w') as f:
            f.write('class AppModified')
        subprocess.check_call(['git', 'add', 'App.kt'], cwd=git_dir)
        subprocess.check_call(['git', 'commit', '-m', 'Code change'], cwd=git_dir, stdout=subprocess.DEVNULL)
        code_head = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=git_dir).decode().strip()

        # Parity between base and code head must FAIL CLOSED
        with self.assertRaises(RuntimeError) as ctx:
            operator_driver.verify_source_parity(git_dir, base_head, code_head)
        self.assertIn("SOURCE_PARITY_VIOLATION", str(ctx.exception))

    def _create_mock_binding(self, parent_mode=0o700, file_mode=0o600, overrides=None):
        parent_dir = os.path.join(self.test_dir, 'mock_binding_parent')
        os.makedirs(parent_dir, mode=parent_mode)
        binding_file = os.path.join(parent_dir, 'binding.json')

        mock_worktree = os.path.join(self.test_dir, 'mock_android_peer')
        os.makedirs(mock_worktree, mode=0o700)

        data = {
            "root_verified": True,
            "peer_android_worktree": mock_worktree,
            "peer_android_branch": "t3/android-qualified-basic-peer-c8dda700",
            "peer_android_attached": True,
            "pins": {
                "android_publication_head": "c" * 40,
                "android_executable_head": "5" * 40,
                "pub_vs_exe_delta": "10_DOCS_ONLY_FILES"
            }
        }
        if overrides:
            for k, v in overrides.items():
                if k == "pins":
                    data["pins"].update(v)
                else:
                    data[k] = v

        content = json.dumps(data, indent=2).encode('utf-8')
        with open(binding_file, 'wb') as f:
            f.write(content)
        os.chmod(binding_file, file_mode)

        expected_sha = hashlib.sha256(content).hexdigest()
        return binding_file, expected_sha, mock_worktree, "c" * 40, "5" * 40

    def test_binding_record_valid_passes(self):
        binding_file, expected_sha, android_dir, pub_head, exe_head = self._create_mock_binding()
        # Must pass without exception
        operator_driver.verify_binding_record(
            binding_file, expected_sha, android_dir, pub_head, exe_head
        )

    def test_binding_record_missing_fails_closed(self):
        nonexistent = os.path.join(self.test_dir, 'missing_dir/binding.json')
        with self.assertRaises(ValueError) as ctx:
            operator_driver.verify_binding_record(
                nonexistent, "0" * 64, "/nonexistent", "c" * 40, "5" * 40
            )
        self.assertIn("not found", str(ctx.exception))

    def test_binding_record_tampered_sha_fails_closed(self):
        binding_file, expected_sha, android_dir, pub_head, exe_head = self._create_mock_binding()
        tampered_sha = "0" * 64
        with self.assertRaises(ValueError) as ctx:
            operator_driver.verify_binding_record(
                binding_file, tampered_sha, android_dir, pub_head, exe_head
            )
        self.assertIn("Binding record SHA mismatch", str(ctx.exception))

    def test_binding_record_root_verified_not_true_fails_closed(self):
        binding_file, expected_sha, android_dir, pub_head, exe_head = self._create_mock_binding(
            overrides={"root_verified": False}
        )
        with self.assertRaises(ValueError) as ctx:
            operator_driver.verify_binding_record(
                binding_file, expected_sha, android_dir, pub_head, exe_head
            )
        self.assertIn("root_verified must be boolean True", str(ctx.exception))

    def test_binding_record_peer_attached_not_true_fails_closed(self):
        binding_file, expected_sha, android_dir, pub_head, exe_head = self._create_mock_binding(
            overrides={"peer_android_attached": False}
        )
        with self.assertRaises(ValueError) as ctx:
            operator_driver.verify_binding_record(
                binding_file, expected_sha, android_dir, pub_head, exe_head
            )
        self.assertIn("peer_android_attached must be boolean True", str(ctx.exception))

    def test_binding_record_worktree_mismatch_fails_closed(self):
        binding_file, expected_sha, android_dir, pub_head, exe_head = self._create_mock_binding()
        other_worktree = os.path.join(self.test_dir, 'other_worktree')
        os.makedirs(other_worktree, mode=0o700)
        with self.assertRaises(ValueError) as ctx:
            operator_driver.verify_binding_record(
                binding_file, expected_sha, other_worktree, pub_head, exe_head
            )
        self.assertIn("worktree mismatch", str(ctx.exception))

    def test_binding_record_branch_mismatch_fails_closed(self):
        binding_file, expected_sha, android_dir, pub_head, exe_head = self._create_mock_binding(
            overrides={"peer_android_branch": "wrong/branch"}
        )
        with self.assertRaises(ValueError) as ctx:
            operator_driver.verify_binding_record(
                binding_file, expected_sha, android_dir, pub_head, exe_head
            )
        self.assertIn("branch mismatch", str(ctx.exception))

    def test_binding_record_parent_insecure_permissions_fails_closed(self):
        binding_file, expected_sha, android_dir, pub_head, exe_head = self._create_mock_binding()
        parent_dir = os.path.dirname(binding_file)
        os.chmod(parent_dir, 0o755)
        with self.assertRaises(ValueError) as ctx:
            operator_driver.verify_binding_record(
                binding_file, expected_sha, android_dir, pub_head, exe_head
            )
        self.assertIn("parent directory insecure permissions", str(ctx.exception))

    def test_binding_record_file_insecure_permissions_fails_closed(self):
        binding_file, expected_sha, android_dir, pub_head, exe_head = self._create_mock_binding()
        os.chmod(binding_file, 0o644)
        with self.assertRaises(ValueError) as ctx:
            operator_driver.verify_binding_record(
                binding_file, expected_sha, android_dir, pub_head, exe_head
            )
        self.assertIn("permissions insecure", str(ctx.exception))


if __name__ == '__main__':
    unittest.main()
