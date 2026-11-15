#!/usr/bin/env python3
"""
Root Operator Paired Drill Preparation & Attestation Tool
=========================================================
Encapsulates preparation, prestart witness, candidate witness recording,
and post-execution provenance logging for bounded paired drills.

Root remains the sole independent witness:
- This tool does NOT self-approve candidates.
- Root inspects candidate handoff exposed in child supervisor stdout.
- Subcommand `record-live-approval` writes live.json with ALL exact required fields
  including `root_live_handoff_approved: true`.
- Zero worktree mutations: NEVER executes git checkout, reset, or clean.
- Invariant: NEVER overwrites initial immutable pre.json snapshot.
"""

import os
import re
import sys
import json
import stat
import hashlib
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

DEFAULT_REPO_DIR = get_default_repo_dir()
REQUIRED_SCOPE = 'ROOT_AUTHORIZED_PAIRED_EXECUTION'

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

def make_prestart_json(manifest_sha, backend_head, pub_head, exe_head, repo_dir, expires_seconds=600):
    exp = datetime.datetime.now(datetime.timezone.utc) + datetime.timedelta(seconds=expires_seconds)
    return {
        'root_coordination_granted': True,
        'manifest_sha256': manifest_sha,
        'backend_head': backend_head,
        'android_publication_head': pub_head,
        'android_executable_head': exe_head,
        'cwd': repo_dir,
        'expires_at': exp.isoformat()
    }

def make_live_approval_json(
    manifest_sha,
    backend_head,
    pub_head,
    exe_head,
    schema_name,
    handoff_path,
    whole_handoff_sha256,
    repo_dir,
    expires_seconds=300
):
    exp = datetime.datetime.now(datetime.timezone.utc) + datetime.timedelta(seconds=expires_seconds)
    return {
        'root_live_handoff_approved': True,
        'root_coordination_granted': True,
        'manifest_sha256': manifest_sha,
        'backend_head': backend_head,
        'android_publication_head': pub_head,
        'android_executable_head': exe_head,
        'cwd': repo_dir,
        'expires_at': exp.isoformat(),
        'handoff_path': handoff_path,
        'whole_handoff_sha256': whole_handoff_sha256,
        'scope': REQUIRED_SCOPE,
        'schema_name': schema_name
    }

def prepare_manifest(output_dir, backend_head, pub_head, exe_head, repo_dir):
    template_path = os.path.join(repo_dir, 'docs/integration/mobile-v2-integration-manifest.json')
    with open(template_path, 'r') as f:
        manifest = json.load(f)

    manifest['backend_execution_head_sha'] = backend_head
    manifest['peer_android_approved_head_sha'] = pub_head
    manifest['peer_android_executable_head_sha'] = exe_head
    manifest['status'] = REQUIRED_SCOPE

    manifest_path = os.path.join(output_dir, 'pairing-manifest.json')
    with open(manifest_path, 'w') as f:
        json.dump(manifest, f, indent=2)
    os.chmod(manifest_path, 0o600)

    manifest_sha = sha256_file(manifest_path)
    return manifest_path, manifest_sha

def cmd_prepare(args):
    # Validate original input paths for symlinks BEFORE realpath resolution (Defect 4 fix)
    try:
        validate_no_symlink_components(args.repo_dir, "Backend repository path")
        validate_no_symlink_components(args.android_dir, "Android repository path")
        assert_not_protected_worktree(args.repo_dir)
        assert_not_protected_worktree(args.android_dir)
    except ValueError as e:
        print(f"ERROR: {e}", file=sys.stderr)
        sys.exit(1)

    repo_dir = os.path.realpath(args.repo_dir)
    android_dir = os.path.realpath(args.android_dir)

    if not os.path.isdir(android_dir):
        print(f"ERROR: Android repo directory not found: {android_dir}", file=sys.stderr)
        sys.exit(1)

    # Validate output dir
    try:
        run_dir = validate_secure_output_dir(args.output_dir, repo_dir, android_dir)
    except ValueError as e:
        print(f"ERROR: {e}", file=sys.stderr)
        sys.exit(1)

    try:
        pub_head = validate_commit_sha(args.android_pub_head, "Android publication HEAD")
        exe_head = validate_commit_sha(args.android_exe_head, "Android executable HEAD")
        verify_commit_exists(android_dir, pub_head, "Android publication HEAD")
        verify_commit_exists(android_dir, exe_head, "Android executable HEAD")
    except ValueError as e:
        print(f"ERROR: {e}", file=sys.stderr)
        sys.exit(1)

    # Read Android HEAD and verify against expected pub pin (read-only)
    try:
        actual_android_head = subprocess.check_output(
            ['git', 'rev-parse', 'HEAD'],
            cwd=android_dir
        ).decode().strip()
    except Exception as e:
        print(f"ERROR: Failed to read Android repo HEAD: {e}", file=sys.stderr)
        sys.exit(1)

    if actual_android_head != pub_head:
        print(f"ERROR: Android repository HEAD ({actual_android_head}) does not match expected publication pin ({pub_head})", file=sys.stderr)
        print("FAIL-CLOSED: Worktree checkout must not be mutated. Provide a worktree at the expected publication pin.", file=sys.stderr)
        sys.exit(1)

    backend_head = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=repo_dir).decode().strip()
    print(f"[Prepare] Target Directory: {run_dir}")
    print(f"[Prepare] Backend HEAD:     {backend_head}")
    print(f"[Prepare] Android Dir:      {android_dir}")
    print(f"[Prepare] Android Pub HEAD: {pub_head}")
    print(f"[Prepare] Android Exe HEAD: {exe_head}")

    # 1. Capture PRE state - do NOT overwrite existing pre.json (Defect 7 fix)
    pre_state = {
        'backend': get_git_state(repo_dir),
        'android': get_git_state(android_dir),
        'metadata': {
            'captured_by': 'root-operator-tool.py',
            'timestamp': datetime.datetime.now(datetime.timezone.utc).isoformat(),
        }
    }
    pre_path = os.path.join(run_dir, 'pre.json')
    if os.path.exists(pre_path):
        # Initial pre.json already exists (written by operator driver); write distinct prepare witness
        tool_prep_path = os.path.join(run_dir, 'tool_prepare.json')
        flags = os.O_WRONLY | os.O_CREAT | os.O_EXCL
        with os.fdopen(os.open(tool_prep_path, flags, 0o600), 'w') as f:
            json.dump(pre_state, f, indent=2)
        print(f"[Prepare] Initial pre.json preserved. Wrote tool_prepare.json (SHA: {sha256_file(tool_prep_path)})")
    else:
        flags = os.O_WRONLY | os.O_CREAT | os.O_EXCL
        with os.fdopen(os.open(pre_path, flags, 0o600), 'w') as f:
            json.dump(pre_state, f, indent=2)
        print(f"[Prepare] Wrote pre.json (SHA: {sha256_file(pre_path)})")

    # 2. Prepare pairing manifest with both pub and exe pins
    manifest_path, manifest_sha = prepare_manifest(run_dir, backend_head, pub_head, exe_head, repo_dir)
    print(f"[Prepare] Wrote pairing-manifest.json (SHA: {manifest_sha})")

    # 3. Write prestart approval
    prestart_data = make_prestart_json(
        manifest_sha,
        backend_head,
        pub_head,
        exe_head,
        repo_dir,
        expires_seconds=args.expires
    )
    prestart_path = os.path.join(run_dir, 'prestart.json')
    with open(prestart_path, 'w') as f:
        json.dump(prestart_data, f, indent=2)
    os.chmod(prestart_path, 0o600)
    print(f"[Prepare] Wrote prestart.json (expires_at: {prestart_data['expires_at']})")

    # 4. Generate exact execution command
    live_path = os.path.join(run_dir, 'live.json')
    cmd = [
        'php',
        'tests/Support/Harness/supervised-financial-drill.php',
        '--execute',
        f'--root-approval-file={prestart_path}',
        f'--root-live-approval-file={live_path}',
        f'--android-pub-head={pub_head}',
        f'--android-exe-head={exe_head}',
        f'--paired-hold-seconds={args.paired_hold}',
        f'--manifest={manifest_path}',
        f'--manifest-sha={manifest_sha}',
        f'--android-repo-dir={android_dir}',
        f'--output-dir={run_dir}'
    ]
    argv_path = os.path.join(run_dir, 'argv.json')
    with open(argv_path, 'w') as f:
        json.dump(cmd, f, indent=2)
    os.chmod(argv_path, 0o600)
    print(f"[Prepare] Wrote argv.json")

    print("\n" + "="*70)
    print("READY FOR BOUNDED DRILL EXECUTION BY ROOT:")
    print("Command Line:")
    print("  " + " \\\n    ".join(cmd))
    print("="*70 + "\n")
    print(f"When candidate is exposed in stdout, Root creates live approval via:")
    print(f"  python3 {__file__} record-live-approval \\\n"
          f"    --output-dir {run_dir} \\\n"
          f"    --schema <candidate_schema> \\\n"
          f"    --handoff-path <candidate_handoff_path> \\\n"
          f"    --handoff-sha <candidate_whole_sha256>\n")

def cmd_record_live(args):
    run_dir = os.path.realpath(args.output_dir)
    if not os.path.isdir(run_dir):
        print(f"Error: directory {run_dir} does not exist", file=sys.stderr)
        sys.exit(1)

    prestart_path = os.path.join(run_dir, 'prestart.json')
    with open(prestart_path, 'r') as f:
        prestart = json.load(f)

    manifest_sha = prestart['manifest_sha256']
    backend_head = prestart['backend_head']
    pub_head = prestart['android_publication_head']
    exe_head = prestart['android_executable_head']
    repo_dir = prestart.get('cwd', DEFAULT_REPO_DIR)

    live_data = make_live_approval_json(
        manifest_sha=manifest_sha,
        backend_head=backend_head,
        pub_head=pub_head,
        exe_head=exe_head,
        schema_name=args.schema,
        handoff_path=args.handoff_path,
        whole_handoff_sha256=args.handoff_sha,
        repo_dir=repo_dir,
        expires_seconds=args.expires or 300
    )

    live_path = os.path.join(run_dir, 'live.json')
    with open(live_path, 'w') as f:
        json.dump(live_data, f, indent=2)
    os.chmod(live_path, 0o600)

    print(f"[Live Witness] Wrote live.json with strict root_live_handoff_approved=true to {live_path}:")
    print(json.dumps(live_data, indent=2))

def cmd_post_log(args):
    repo_dir = os.path.realpath(args.repo_dir)
    run_dir = os.path.realpath(args.output_dir)
    post_state = {'backend': get_git_state(repo_dir)}

    if args.android_dir:
        android_dir = os.path.realpath(args.android_dir)
        try:
            assert_not_protected_worktree(android_dir)
            if os.path.isdir(android_dir):
                post_state['android'] = get_git_state(android_dir)
        except Exception as e:
            print(f"[Post] Warning: Could not capture Android state: {e}", file=sys.stderr)

    post_path = os.path.join(run_dir, 'post.json')
    with open(post_path, 'w') as f:
        json.dump(post_state, f, indent=2)
    os.chmod(post_path, 0o600)
    print(f"[Post] Wrote post.json (SHA: {sha256_file(post_path)})")

def main():
    parser = argparse.ArgumentParser(description="Root Operator Prepared Execution & Witness Helper")
    subparsers = parser.add_subparsers(dest="subcommand", required=True)

    p_prepare = subparsers.add_parser("prepare", help="Prepare drill directory, pre.json, pairing-manifest.json, prestart.json, argv.json")
    p_prepare.add_argument("--repo-dir", default=DEFAULT_REPO_DIR, help="Backend repository directory")
    p_prepare.add_argument("--android-dir", required=True, help="Android repository directory")
    p_prepare.add_argument("--android-pub-head", required=True, help="Root-approved peer Android publication HEAD SHA")
    p_prepare.add_argument("--android-exe-head", required=True, help="Root-approved peer Android executable HEAD SHA")
    p_prepare.add_argument("--output-dir", required=True, help="Target drill output directory (0700)")
    p_prepare.add_argument("--expires", type=int, default=600, help="Prestart approval TTL in seconds (default: 600)")
    p_prepare.add_argument("--paired-hold", type=int, default=120, help="Paired hold seconds (default: 120)")
    p_prepare.set_defaults(func=cmd_prepare)

    p_live = subparsers.add_parser("record-live-approval", help="Record Root live approval JSON with root_live_handoff_approved=true")
    p_live.add_argument("--output-dir", required=True, help="Directory containing prestart.json")
    p_live.add_argument("--schema", required=True, help="Candidate schema name (e.g. accounting_p8_tmp_<12hex>)")
    p_live.add_argument("--handoff-path", required=True, help="Candidate handoff path (/tmp/v2_harness_<16hex>/runtime_client_handoff.json)")
    p_live.add_argument("--handoff-sha", required=True, help="Candidate whole handoff SHA256 (64 hex)")
    p_live.add_argument("--expires", type=int, default=300, help="Approval validity in seconds (default: 300s)")
    p_live.set_defaults(func=cmd_record_live)

    p_post = subparsers.add_parser("post-log", help="Capture post.json after drill execution")
    p_post.add_argument("--repo-dir", default=DEFAULT_REPO_DIR, help="Backend repository directory")
    p_post.add_argument("--android-dir", default=None, help="Android repository directory (optional)")
    p_post.add_argument("--output-dir", required=True, help="Target drill directory")
    p_post.set_defaults(func=cmd_post_log)

    args = parser.parse_args()
    args.func(args)

if __name__ == '__main__':
    main()
