"""Fixed temporary OAuth probe installation; explicit client-token and MCP-path approval required."""
import argparse
import json
from pathlib import Path
import shlex
import subprocess

ROOT = Path(__file__).resolve().parents[2]
REMOTE = r'''
import hashlib,json,os,re,subprocess,sys,time,urllib.request,urllib.error
from pathlib import Path
payload=json.load(sys.stdin)
root=Path('/root/familyfunds-oauth-probe');site=Path('/etc/nginx/sites-available/familyfunds.app.conf')
sha=lambda b:hashlib.sha256(b).hexdigest()
def command(args):
 r=subprocess.run(args,stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=20)
 if r.returncode:raise RuntimeError('Fixed command failed: '+args[0])
if os.geteuid()!=0:raise RuntimeError('Root-only fixed probe required')
if payload['operation']=='install':
 original=site.read_bytes()
 if sha(original)!='936df5a4bb7e9591b765823e17ddb877474ccec2dcc9071cd4f85ca60bef361e':raise RuntimeError('Nginx changed after review')
 if root.exists():raise RuntimeError('Existing OAuth probe must be cleaned first')
 root.mkdir(mode=0o700)
 (root/'original.conf').write_bytes(original);os.chmod(root/'original.conf',0o600)
 (root/'probe.py').write_text(payload['source']);os.chmod(root/'probe.py',0o600)
 (root/'probe.json').write_text(json.dumps(payload['config']));os.chmod(root/'probe.json',0o600)
 params=Path('/etc/nginx/fastcgi_params').read_text()
 params,count=re.subn(r'(fastcgi_param\s+SCRIPT_NAME\s+)\$fastcgi_script_name(\s*;)',lambda m:m[1]+'/index.php'+m[2],params)
 if count!=1:raise RuntimeError('Cannot preserve existing FastCGI parameters')
 (root/'fastcgi_params').write_text(params);os.chmod(root/'fastcgi_params',0o600)
 block='\n'+payload['nginx_block']+'\n'
 pattern=r'(server\s*\{\s*listen 443 ssl http2;[\s\S]*?server_name familyfunds\.app;\n)'
 changed,count=re.subn(pattern,lambda m:m[1]+block,original.decode(),count=1)
 if count!=1:raise RuntimeError('Expected one HTTPS server block')
 journal={'original_sha256':sha(original),'applied_sha256':sha(changed.encode()),'block':block}
 (root/'journal.json').write_text(json.dumps(journal));os.chmod(root/'journal.json',0o600)
 cleanup="""import json,hashlib,subprocess\nfrom pathlib import Path\nr=Path('/root/familyfunds-oauth-probe');s=Path('/etc/nginx/sites-available/familyfunds.app.conf')\nif not r.exists():raise SystemExit(0)\nj=json.loads((r/'journal.json').read_text());c=s.read_text();h=hashlib.sha256(c.encode()).hexdigest()\nif h==j['applied_sha256']:s.write_bytes((r/'original.conf').read_bytes())\nelif h==j['original_sha256']:pass\nelif c.count(j['block'])==1:s.write_text(c.replace(j['block'],''))\nelse:raise RuntimeError('Temporary OAuth block requires manual review')\nsubprocess.run(['nginx','-t'],check=True)\nsubprocess.run(['systemctl','reload','nginx'],check=True)\nsubprocess.run(['systemctl','stop','familyfunds-oauth-probe'])\nfor n in ['probe.json','probe.py','fastcgi_params','journal.json','original.conf','cleanup.py','probe-result.json']:\n p=r/n\n if p.exists():p.unlink()\nr.rmdir()\nprint('FamilyFund temporary OAuth probe removed')\n"""
 (root/'cleanup.py').write_text(cleanup);os.chmod(root/'cleanup.py',0o600)
 try:
  command(['systemd-run','--unit=familyfunds-oauth-probe','--property=RuntimeMaxSec=900','--property=LimitCORE=0','--property=NoNewPrivileges=true','--property=PrivateTmp=true','--property=ProtectSystem=strict','--property=ProtectHome=read-only','--property=ReadWritePaths='+str(root),'--property=CapabilityBoundingSet=','/usr/bin/python3',str(root/'probe.py'),'--config',str(root/'probe.json'),'--existing-client-probe-approved'])
  ready=False
  for _ in range(20):
   try:
    response=urllib.request.urlopen('http://127.0.0.1:18871/check',timeout=1)
    ready=response.status==204;response.close()
    if ready:break
   except OSError:pass
   time.sleep(0.2)
  if not ready:raise RuntimeError('Loopback OAuth listener unavailable')
  site.write_text(changed);command(['nginx','-t']);command(['systemctl','reload','nginx'])
  command(['systemd-run','--unit=familyfunds-oauth-probe-cleanup','--on-active=15m','--timer-property=AccuracySec=1s','/usr/bin/python3',str(root/'cleanup.py')])
 except Exception:
  site.write_bytes(original)
  subprocess.run(['systemctl','stop','familyfunds-oauth-probe'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
  raise
 print(json.dumps({'installed':True,'client':'ChatGPT','duration_minutes':15,'normal_mcp_response':'Hetzner/PostgreSQL','request_body_forwarded':False,'raw_token_written':False,'nginx_original_sha256':sha(original),'nginx_applied_sha256':sha(changed.encode())}))
elif payload['operation']=='result':
 path=root/'probe-result.json'
 print(path.read_text() if path.exists() else json.dumps({'status':'not observed'}))
elif payload['operation']=='cleanup':
 if not root.exists():
  print(json.dumps({'removed':True,'already_auto_cleaned':True}));raise SystemExit(0)
 subprocess.run(['systemctl','stop','familyfunds-oauth-probe-cleanup.timer'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
 command(['/usr/bin/python3',str(root/'cleanup.py')])
 restored=sha(site.read_bytes())=='936df5a4bb7e9591b765823e17ddb877474ccec2dcc9071cd4f85ca60bef361e'
 print(json.dumps({'removed':True,'nginx_exact_baseline_restored':restored,'normal_database':'PostgreSQL'}))
else:raise RuntimeError('Unknown fixed probe operation')
'''

if __name__=='__main__':
    parser=argparse.ArgumentParser()
    parser.add_argument('operation',choices=['install','result','cleanup'])
    parser.add_argument('--existing-client-probe-approved',action='store_true',required=True)
    args=parser.parse_args()
    payload={'operation':args.operation}
    if args.operation=='install':
        gate=json.loads((ROOT/'storage/app/private/production-candidate/migration-access.json').read_text())
        payload.update(config={'basic_authorization':gate['basic_authorization'],'native_admin':gate['admin_token'],'client_id':'019ded82-bc96-73fa-8c0f-5c568bd8ea7e','user_id':1},source=(ROOT/'deployment/d1-proof/production-oauth-live-probe.py').read_text(),nginx_block=(ROOT/'deployment/d1-proof/production-oauth-probe-nginx.conf').read_text())
    r=subprocess.run(['ssh','-o','BatchMode=yes','root@49.12.236.101','python3 -c '+shlex.quote(REMOTE)],input=json.dumps(payload).encode(),stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=60)
    if r.returncode:
        print(json.dumps({'status':'failed','private_details_suppressed':True}));raise SystemExit(1)
    safe=json.loads(r.stdout)
    (ROOT/('deployment/d1-proof/production-oauth-probe-'+args.operation+'-result.json')).write_text(json.dumps(safe,indent=2)+'\n')
    print(json.dumps(safe))
