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
"""

import os
import sys
import json
import stat
import hashlib
import datetime
import argparse
import subprocess

DEFAULT_REPO_DIR = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
PUB_HEAD = 'b0d5965e7dc5d0e483880afa0bf3ca6a025ee3e6'
EXE_HEAD = '360ce78408f09ecff451381e595a4ffe2f116969'
REQUIRED_SCOPE = 'ROOT_AUTHORIZED_PAIRED_EXECUTION'

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

def make_prestart_json(manifest_sha, backend_head, repo_dir, expires_seconds=600):
    exp = datetime.datetime.now(datetime.timezone.utc) + datetime.timedelta(seconds=expires_seconds)
    return {
        'root_coordination_granted': True,
        'manifest_sha256': manifest_sha,
        'backend_head': backend_head,
        'android_publication_head': PUB_HEAD,
        'android_executable_head': EXE_HEAD,
        'cwd': repo_dir,
        'expires_at': exp.isoformat()
    }

def make_live_approval_json(
    manifest_sha,
    backend_head,
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
        'android_publication_head': PUB_HEAD,
        'android_executable_head': EXE_HEAD,
        'cwd': repo_dir,
        'expires_at': exp.isoformat(),
        'handoff_path': handoff_path,
        'whole_handoff_sha256': whole_handoff_sha256,
        'scope': REQUIRED_SCOPE,
        'schema_name': schema_name
    }

def prepare_manifest(output_dir, backend_head, repo_dir, pub_head=PUB_HEAD, exe_head=EXE_HEAD):
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
    repo_dir = os.path.abspath(args.repo_dir)
    run_dir = os.path.abspath(args.output_dir)
    android_dir = os.path.abspath(args.android_dir)

    os.makedirs(run_dir, mode=0o700, exist_ok=True)
    os.chmod(run_dir, 0o700)

    backend_head = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=repo_dir).decode().strip()
    print(f"[Prepare] Target Directory: {run_dir}")
    print(f"[Prepare] Backend HEAD:     {backend_head}")

    # 1. Capture PRE state
    pre_state = {'backend': get_git_state(repo_dir)}
    pre_path = os.path.join(run_dir, 'pre.json')
    with open(pre_path, 'w') as f:
        json.dump(pre_state, f, indent=2)
    os.chmod(pre_path, 0o600)
    print(f"[Prepare] Wrote pre.json (SHA: {sha256_file(pre_path)})")

    # 2. Prepare pairing manifest with both pub and exe pins
    manifest_path, manifest_sha = prepare_manifest(run_dir, backend_head, repo_dir)
    print(f"[Prepare] Wrote pairing-manifest.json (SHA: {manifest_sha})")

    # 3. Write prestart approval
    prestart_data = make_prestart_json(manifest_sha, backend_head, repo_dir, expires_seconds=args.expires)
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
        f'--android-pub-head={PUB_HEAD}',
        f'--android-exe-head={EXE_HEAD}',
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
    run_dir = os.path.abspath(args.output_dir)
    if not os.path.isdir(run_dir):
        print(f"Error: directory {run_dir} does not exist", file=sys.stderr)
        sys.exit(1)

    prestart_path = os.path.join(run_dir, 'prestart.json')
    with open(prestart_path, 'r') as f:
        prestart = json.load(f)

    manifest_sha = prestart['manifest_sha256']
    backend_head = prestart['backend_head']
    repo_dir = prestart.get('cwd', DEFAULT_REPO_DIR)

    live_data = make_live_approval_json(
        manifest_sha=manifest_sha,
        backend_head=backend_head,
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
    repo_dir = os.path.abspath(args.repo_dir)
    run_dir = os.path.abspath(args.output_dir)
    post_state = {'backend': get_git_state(repo_dir)}
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
    p_post.add_argument("--output-dir", required=True, help="Target drill directory")
    p_post.set_defaults(func=cmd_post_log)

    args = parser.parse_args()
    args.func(args)

if __name__ == '__main__':
    main()
