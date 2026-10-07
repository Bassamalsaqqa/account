"""Real separate-process MariaDB races. Run only against a new disposable accounting_phase6_* database."""
import json, os, pathlib, subprocess, sys, time

root = pathlib.Path(__file__).resolve().parents[3]
out = root / '.ai/delegations/20261007-phase6'
php = os.environ.get('PHASE6_PHP', 'php')
env = os.environ.copy()
database = env.get('DB_DATABASE', '')
assert env.get('APP_ENV') == 'testing' and database.startswith('accounting_phase6_')
out = out / database
out.mkdir(parents=True, exist_ok=False)
script = str(root / 'tests/Support/Phase6/money-scenarios.php')
fixture = out / 'race-fixture.json'
subprocess.run([php, script, 'fixture', str(fixture)], env=env, cwd=root, check=True, stdout=subprocess.DEVNULL)
results = {}
for case in ['transfer', 'incoming', 'outgoing', 'clear', 'clear-return', 'cross-payment', 'apply-same', 'apply-different']:
    barrier = out / ('barrier-' + case)
    processes = [subprocess.Popen([php, script, 'run', str(fixture), case, str(i), str(barrier)], cwd=root, env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE) for i in range(2)]
    deadline = time.monotonic() + 20
    while not all(pathlib.Path(str(barrier)+'.'+str(i)).exists() for i in range(2)):
        if time.monotonic() > deadline: raise RuntimeError('Workers did not reach barrier')
        time.sleep(.01)
    barrier.write_text('go', encoding='utf-8')
    rows = []
    for p in processes:
        stdout, stderr = p.communicate(timeout=40)
        if p.returncode or stderr: raise RuntimeError(stderr.decode())
        rows.append(json.loads(stdout))
    successes = [r for r in rows if r['ok']]
    if case in ['transfer','incoming','outgoing','cross-payment']:
        assert len(successes) == 2 and successes[0]['id'] == successes[1]['id'], rows
    elif case == 'clear-return': assert any(r['ok'] for r in rows), rows
    else: assert len(successes) == 1, rows
    results[case] = rows
    (out / 'race-cases.json').write_text(json.dumps(results, indent=2), encoding='utf-8')
audit = json.loads(subprocess.check_output([php, script, 'audit', str(fixture)], env=env, cwd=root))
assert all(r['healthy'] for r in audit['reconciliations'].values()), audit
assert audit['counts']['money_transfers'] == 1 and audit['counts']['checks'] == 4, audit
assert all(r['unallocated'] == '148.000000' for r in audit['residuals']), audit
report = {'database': database, 'races': results, 'audit': audit}
(out / 'race-results.json').write_text(json.dumps(report, indent=2), encoding='utf-8')
print(json.dumps(report, indent=2))
