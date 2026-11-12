#!/usr/bin/env python3
"""Verify local pilot custody, production bindings, default-off HTTPS and TLS failures.

Never prints credentials or response bodies. Requires an existing owned state directory.
"""
import argparse, datetime, hashlib, http.client, json, os, pathlib, signal, socket, ssl, subprocess, time


def free_port():
    with socket.socket() as s:
        s.bind(('127.0.0.1', 0))
        return s.getsockname()[1]


def wire(url, ca):
    from urllib.parse import urlsplit
    u = urlsplit(url)
    c = http.client.HTTPSConnection(u.hostname, u.port, timeout=5, context=ssl.create_default_context(cafile=ca))
    try:
        c.request('POST', '/api/mobile/v1/auth/challenge', json.dumps({'device_id':'00000000-0000-4000-8000-000000000001','app_version':'1.0.0','platform':'android'}), {'Accept':'application/json','Content-Type':'application/json'})
        r = c.getresponse()
        body = r.read(65536)
        return r.status, json.loads(body)
    finally:
        c.close()


def main():
    a=argparse.ArgumentParser(); a.add_argument('state_dir'); args=a.parse_args()
    state_dir=pathlib.Path(args.state_dir).resolve(); root=pathlib.Path(__file__).resolve().parents[3]
    assert state_dir.stat().st_mode & 0o777 == 0o700
    state=json.loads((state_dir/'pilot-state.json').read_text())
    creds=pathlib.Path(state['credentialsPath']); assert creds.stat().st_mode & 0o777 == 0o600 and creds.stat().st_size < 65536
    expiry=datetime.datetime.fromisoformat(state['ttlExpiresAt']); assert expiry > datetime.datetime.now(datetime.timezone.utc)
    php_pid=int(state['php_pid']); cmd=pathlib.Path(f'/proc/{php_pid}/cmdline').read_bytes(); assert b'127.0.0.1:' in cmd
    env=dict(x.split('=',1) for x in pathlib.Path(f'/proc/{php_pid}/environ').read_bytes().decode().split('\0') if '=' in x)
    assert env['DB_HOST']=='127.0.0.1' and env['DB_PORT']=='3342' and env['DB_DATABASE']==state['schema_name']
    # Bootstrap the actual application with the live process environment, no adapter substitutions.
    php=r'''require 'vendor/autoload.php'; $app=require 'bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); $adapter=$app->make(App\Support\Mobile\Receipt\FinancialExecutionAdapterInterface::class); $denied=[]; foreach(['execute','replay','lookup'] as $op){try{$adapter->authorize(new App\Models\Company,new App\Models\User,new App\Models\MobileDevice,$op);$denied[$op]=false;}catch(App\Support\Mobile\Receipt\Exceptions\FinancialExecutionDeniedException){$denied[$op]=true;}} echo json_encode(['adapter'=>get_class($adapter),'denied'=>$denied,'flags'=>['mobile.enabled'=>config('mobile.enabled'),'mobile.v2_enabled'=>config('mobile.v2_enabled')]]);'''
    p=subprocess.run(['php','-r',php],cwd=root,env=env,capture_output=True,check=True)
    runtime=json.loads(p.stdout); assert all(runtime['denied'].values())
    assert runtime['adapter']=='App\\Support\\Mobile\\Receipt\\DenyFinancialExecutionAdapter'
    assert runtime['flags']=={'mobile.enabled':True,'mobile.v2_enabled':False}
    status, body=wire(state['baseUrl'],state['caCertPath']); assert status==200 and 'challenge_id' in body
    tls={}
    for key in ['wrongHostnameUrl','untrustedCertUrl']:
        try: wire(state[key],state['caCertPath'])
        except ssl.SSLCertVerificationError as e:
            if key=='wrongHostnameUrl': assert e.verify_code==64, e.verify_code
            else: assert e.verify_code in [18,19,20,21], e.verify_code
            tls[key]={'certificateVerifyCode':e.verify_code,'passed':True}
        else: raise AssertionError('Expected specific certificate verification failure')
    # Separate default-OFF application and HTTPS proxy. No changes to the live reader instance.
    hp,sp=free_port(),free_port(); offenv=dict(env);offenv.update(MOBILE_API_ENABLED='false',MOBILE_V2_API_ENABLED='false',APP_DEBUG='false')
    caddy=json.loads((state_dir/'caddy.json').read_text()); srv=caddy['apps']['http']['servers']['srv0'];srv['listen']=[f'127.0.0.1:{sp}'];srv['routes'][0]['handle'][0]['upstreams']=[{'dial':f'127.0.0.1:{hp}'}];caddy['apps']['http']['servers']={'off':srv}
    offcfg=state_dir/'default-off-caddy.json';offcfg.write_text(json.dumps(caddy));offcfg.chmod(0o600)
    processes=[]
    try:
        with open(state_dir/'default-off.log','ab') as log:
            processes.append(subprocess.Popen(['php','-S',f'127.0.0.1:{hp}',str(root/'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')],cwd=root/'public',env=offenv,stdout=log,stderr=log,start_new_session=True))
            processes.append(subprocess.Popen(['caddy','run','--config',str(offcfg)],stdout=log,stderr=log,start_new_session=True))
        for attempt in range(20):
            try: status,body=wire(f'https://127.0.0.1:{sp}',state['caCertPath']);break
            except (ConnectionError,OSError):
                if attempt==19: raise
                time.sleep(.1)
        assert status==404 and body['error']['code']=='MOBILE_API_DISABLED'
    finally:
        for proc in reversed(processes):
            proc.terminate();proc.wait(timeout=5)
    result={'runtime':runtime,'tls':tls,'defaultOffHttps':{'status':status,'errorCode':body['error']['code']},'verifiedAt':datetime.datetime.now(datetime.timezone.utc).isoformat()}
    (state_dir/'supervisor-boundary-evidence.json').write_text(json.dumps(result,indent=2))
    print(json.dumps(result))

if __name__=='__main__': main()
