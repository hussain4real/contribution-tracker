"""Install/remove the exact reviewed temporary Hetzner authentication path after approval."""
import argparse
import json
from pathlib import Path
import shlex
import subprocess

ROOT = Path(__file__).resolve().parents[2]
PRIVATE = ROOT/'storage/app/private/production-candidate'
REMOTE = r'''
import base64,hashlib,json,os,re,subprocess,sys,time,urllib.request,urllib.error
from pathlib import Path
payload=json.load(sys.stdin)
root=Path('/root/familyfunds-origin-auth-uat')
site=Path('/etc/nginx/sites-available/familyfunds.app.conf')
sha=lambda b:hashlib.sha256(b).hexdigest()
def command(args):
 r=subprocess.run(args,stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=20)
 if r.returncode:raise RuntimeError('Command failed: '+args[0])
if os.geteuid()!=0:raise RuntimeError('Root-only fixed helper required')
if payload['operation']=='install':
 original=site.read_bytes()
 if sha(original)!='936df5a4bb7e9591b765823e17ddb877474ccec2dcc9071cd4f85ca60bef361e':raise RuntimeError('Nginx configuration changed after review')
 if root.exists():raise RuntimeError('Existing origin test must be cleaned first')
 config=payload['config'];prefix=config['prefix']
 if not re.fullmatch(r'/__familyfunds_auth_uat_[a-f0-9]{16}/',prefix):raise RuntimeError('Invalid fixed test path')
 root.mkdir(mode=0o700)
 (root/'original.conf').write_bytes(original);os.chmod(root/'original.conf',0o600)
 (root/'bridge.py').write_text(payload['source']);os.chmod(root/'bridge.py',0o600)
 (root/'bridge.json').write_text(json.dumps(config));os.chmod(root/'bridge.json',0o600)
 block='\n    # BEGIN FamilyFund isolated origin authentication\n    location ^~ '+prefix+' {\n        proxy_pass http://127.0.0.1:18870;\n        proxy_set_header Host $host;\n        proxy_set_header Authorization $http_authorization;\n        proxy_read_timeout 50s;\n        access_log off;\n    }\n    # END FamilyFund isolated origin authentication\n'
 pattern=r'(server\s*\{\s*listen 443 ssl http2;[\s\S]*?server_name familyfunds\.app;\n)'
 changed,count=re.subn(pattern,lambda m:m[1]+block,original.decode(),count=1)
 if count!=1:raise RuntimeError('Expected one HTTPS server block')
 journal={'prefix':prefix,'original_sha256':sha(original),'applied_sha256':sha(changed.encode()),'block':block}
 (root/'journal.json').write_text(json.dumps(journal));os.chmod(root/'journal.json',0o600)
 cleanup="""import json,hashlib,subprocess
from pathlib import Path
r=Path('/root/familyfunds-origin-auth-uat');s=Path('/etc/nginx/sites-available/familyfunds.app.conf')
if not r.exists():raise SystemExit(0)
j=json.loads((r/'journal.json').read_text());c=s.read_text();h=hashlib.sha256(c.encode()).hexdigest()
if h==j['applied_sha256']:s.write_bytes((r/'original.conf').read_bytes())
elif h==j['original_sha256']:pass
elif c.count(j['block'])==1:s.write_text(c.replace(j['block'],''))
else:raise RuntimeError('Temporary Nginx block requires manual review')
subprocess.run(['nginx','-t'],check=True)
subprocess.run(['systemctl','reload','nginx'],check=True)
subprocess.run(['systemctl','stop','familyfunds-origin-auth-uat'])
for n in ['bridge.json','bridge.py','journal.json','original.conf','cleanup.py']:(r/n).unlink()
r.rmdir()
print('FamilyFund temporary origin path removed; normal PostgreSQL routing retained')
"""
 (root/'cleanup.py').write_text(cleanup);os.chmod(root/'cleanup.py',0o600)
 try:
  command(['systemd-run','--unit=familyfunds-origin-auth-uat','--property=RuntimeMaxSec=1800','--property=NoNewPrivileges=true','--property=PrivateTmp=true','--property=ProtectSystem=strict','--property=ProtectHome=read-only','--property=CapabilityBoundingSet=','/usr/bin/python3',str(root/'bridge.py'),'--config',str(root/'bridge.json'),'--origin-path-installation-approved'])
  ready=False
  for _ in range(20):
   try:
    urllib.request.urlopen('http://127.0.0.1:18870'+prefix,timeout=1)
   except urllib.error.HTTPError as e:
    if e.code==401:ready=True;break
   except OSError:pass
   time.sleep(0.2)
  if not ready:raise RuntimeError('Protected loopback listener unavailable')
  site.write_text(changed);command(['nginx','-t']);command(['systemctl','reload','nginx'])
  command(['systemd-run','--unit=familyfunds-origin-auth-cleanup','--on-active=30m','--timer-property=AccuracySec=1s','/usr/bin/python3',str(root/'cleanup.py')])
 except Exception:
  site.write_bytes(original)
  subprocess.run(['systemctl','stop','familyfunds-origin-auth-uat'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
  raise
 print(json.dumps({'installed':True,'prefix':prefix,'duration_minutes':30,'ordinary_production_database':'PostgreSQL','source_app_changed':False,'nginx_original_sha256':sha(original),'nginx_applied_sha256':sha(changed.encode())}))
elif payload['operation']=='cleanup':
 if not root.exists():
  print(json.dumps({'removed':True,'already_auto_cleaned':True}));raise SystemExit(0)
 subprocess.run(['systemctl','stop','familyfunds-origin-auth-cleanup.timer'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
 journal=json.loads((root/'journal.json').read_text());current=site.read_text()
 if sha(current.encode())==journal['original_sha256']:
  pass
 elif sha(current.encode())==journal['applied_sha256']:
  site.write_bytes((root/'original.conf').read_bytes())
 elif current.count(journal['block'])==1:
  site.write_text(current.replace(journal['block'],''))
 else:raise RuntimeError('Cannot safely identify the temporary Nginx block')
 command(['nginx','-t']);command(['systemctl','reload','nginx'])
 subprocess.run(['systemctl','stop','familyfunds-origin-auth-uat'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
 subprocess.run(['systemctl','reset-failed','familyfunds-origin-auth-uat'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
 restored=sha(site.read_bytes())==journal['original_sha256']
 for name in ['bridge.json','bridge.py','journal.json','original.conf','cleanup.py']:(root/name).unlink()
 root.rmdir()
 print(json.dumps({'removed':True,'nginx_exact_baseline_restored':restored,'source_app_changed':False,'ordinary_production_database':'PostgreSQL'}))
else:raise RuntimeError('Unknown fixed operation')
'''

if __name__=='__main__':
    parser=argparse.ArgumentParser()
    parser.add_argument('operation',choices=['install','cleanup'])
    parser.add_argument('--origin-path-installation-approved',action='store_true',required=True)
    args=parser.parse_args()
    payload={'operation':args.operation}
    if args.operation=='install':
        payload.update(config=json.loads((PRIVATE/'origin-auth-bridge.json').read_text()),source=(ROOT/'deployment/d1-proof/production-origin-auth-bridge.py').read_text())
    result=subprocess.run(['ssh','-o','BatchMode=yes','root@49.12.236.101','python3 -c '+shlex.quote(REMOTE)],input=json.dumps(payload).encode(),stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=60)
    if result.returncode:
        print(json.dumps({'status':'failed','private_details_suppressed':True}));raise SystemExit(1)
    safe=json.loads(result.stdout)
    (ROOT/('deployment/d1-proof/production-origin-auth-'+args.operation+'-result.json')).write_text(json.dumps(safe,indent=2)+'\n')
    print(json.dumps(safe))
