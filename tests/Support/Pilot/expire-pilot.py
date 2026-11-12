#!/usr/bin/env python3
"""Stop only matching owned pilot processes at expiry. Never drops the schema."""
import datetime,json,os,pathlib,signal,sys,time
state=pathlib.Path(sys.argv[1]); data=json.loads(state.read_text())
expiry=datetime.datetime.fromisoformat(data['ttlExpiresAt']).timestamp()
remaining=expiry-time.time()
if not 0 <= remaining <= 7200: raise SystemExit('Invalid bounded expiry')
identities=data['processIdentities']
time.sleep(remaining)
for name in ['caddy_pid','php_pid']:
    pid=int(data[name]); proc=pathlib.Path(f'/proc/{pid}')
    try:
        ticks=proc.joinpath('stat').read_text().rsplit(')',1)[1].split()[19]
        if ticks==identities[name]: os.kill(pid,signal.SIGTERM)
    except FileNotFoundError: pass
# Schema and private artifacts remain until the paired runner acknowledges completion.
