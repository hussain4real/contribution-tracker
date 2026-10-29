"""Copy only manifest-referenced production files directly from Hetzner to R2."""
import json
from pathlib import Path
import shlex
import stat
import subprocess

ROOT = Path(__file__).resolve().parents[2]
REMOTE = r'''
import json,os,sys,subprocess,hashlib,re
from pathlib import Path
phase='manifest'; diagnostic=[]
try:
    credential=json.load(sys.stdin)
    assert os.geteuid()==0 and credential['bucket']=='familyfunds-production-private'
    root=Path('/var/www/familyfunds-d1-staging/storage/app/private/prepared-production-runtime')
    manifest=json.loads((root/'source-files-20261027.json').read_text())
    assert manifest['missing']==0 and len(manifest['files'])==7
    assert manifest['snapshot_sha256']=='22d370f90673095e345bbc3876a5dc5c92ede4677a995bcab643ccb46a8d4863'
    env={**os.environ,'RCLONE_CONFIG_CF_TYPE':'s3','RCLONE_CONFIG_CF_PROVIDER':'Cloudflare',
         'RCLONE_CONFIG_CF_ENDPOINT':'https://40344948b73fa9da3008958fdc3faf12.r2.cloudflarestorage.com',
         'RCLONE_CONFIG_CF_ACCESS_KEY_ID':credential['access_key_id'],'RCLONE_CONFIG_CF_SECRET_ACCESS_KEY':credential['secret_access_key'],
         'RCLONE_CONFIG_CF_REGION':'auto','RCLONE_CONFIG_CF_NO_CHECK_BUCKET':'true'}
    def call(args):
        return subprocess.run(['rclone',*args,'--config','/dev/null','--s3-acl','','--s3-bucket-acl','','--s3-no-check-bucket',
                               '--retries','1','--low-level-retries','1','--log-level','ERROR'],env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=45)
    checked=[]
    for item in manifest['files']:
        phase='source_file'
        assert item['disk'] in ('local','public') and not any(x in item['path'] for x in ('..','\\')) and not item['path'].startswith('/')
        source=Path(item['source']).resolve();allowed=Path('/var/www/contribution-tracker/storage/app/'+('public' if item['disk']=='public' else 'private')).resolve()
        assert source.is_relative_to(allowed)
        data=source.read_bytes(); assert len(data)==item['bytes'] and hashlib.sha256(data).hexdigest()==item['sha256']
        key=('public/' if item['disk']=='public' else 'private/')+item['path']; destination='cf:'+credential['bucket']+'/'+key
        phase='existing_object';previous=call(['lsjson',destination,'--stat'])
        diagnostic=[('exit='+str(previous.returncode)+' bytes='+str(len(previous.stdout))).encode()]
        sanitized=previous.stderr.decode(errors='replace')
        for value in [credential['access_key_id'],credential['secret_access_key'],str(source),destination,key,item['path']]:sanitized=sanitized.replace(value,'[redacted]')
        diagnostic.append(sanitized[:600].encode())
        missing=False
        if previous.returncode==0:
            info=json.loads(previous.stdout)
            if info.get('IsDir') is not True:
                assert info['Size']==len(data), 'Existing private object has a different size'
                existing=call(['cat',destination]);assert existing.returncode==0 and hashlib.sha256(existing.stdout).hexdigest()==item['sha256'], 'Existing private object has different contents'
            else:
                listing=call(['lsjson',destination,'--files-only'])
                assert listing.returncode==0 and json.loads(listing.stdout)==[], 'Unexpected nonempty directory at private file path'
                missing=True
        else:
            assert b'not found' in previous.stderr.lower() or b'nosuchkey' in previous.stderr.lower(), 'Existing object check failed'
            missing=True
        if missing:
            phase='upload';copied=call(['copyto',str(source),destination,'--immutable','--checksum'])
            sanitized=copied.stderr.decode(errors='replace')
            for value in [credential['access_key_id'],credential['secret_access_key'],str(source),destination,key,item['path']]:sanitized=sanitized.replace(value,'[redacted]')
            diagnostic=[('exit='+str(copied.returncode)).encode(),sanitized[:800].encode()]
            assert copied.returncode==0, 'Private file upload failed'
        phase='verification';current=call(['cat',destination]); assert current.returncode==0 and len(current.stdout)==len(data) and hashlib.sha256(current.stdout).hexdigest()==item['sha256']
        checked.append({'path_sha256':hashlib.sha256((item['disk']+':'+item['path']).encode()).hexdigest(),'bytes':len(data),'sha256':item['sha256']})
    phase='negative_scope';denied=call(['lsf','cf:familyfunds-production-backups','--max-depth','1'])
    scope_denied=denied.returncode!=0 and (b'403' in denied.stderr or b'AccessDenied' in denied.stderr)
    assert scope_denied, 'Bucket isolation check failed'
    print(json.dumps({'status':'passed','files':checked,'count':len(checked),'total_bytes':sum(x['bytes'] for x in checked),
                      'out_of_scope_backup_bucket_denied':True,'production_source_written':False,'private_files_saved_locally':False}))
except Exception as error:
    print(json.dumps({'status':'failed','phase':phase,'error_type':type(error).__name__,'reason':str(error),'diagnostic':[x.decode() for x in diagnostic]}));sys.exit(1)
'''

path = ROOT / 'storage/app/private/production-candidate/actual-r2-credential.json'
if path.is_symlink() or stat.S_IMODE(path.stat().st_mode) != 0o600:
    raise SystemExit('Mode-0600 regular credential file required')
process = subprocess.run(['ssh', '-o', 'BatchMode=yes', 'root@49.12.236.101', 'python3 -c ' + shlex.quote(REMOTE)],
                         input=path.read_bytes(), stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=600)
try:
    result = json.loads(process.stdout)
except (ValueError, UnicodeError):
    raise SystemExit('File migration failed; private output suppressed')
(ROOT / 'deployment/d1-proof/production-actual-file-migration-result.json').write_text(json.dumps(result, indent=2) + '\n')
print(json.dumps({k:v for k,v in result.items() if k != 'files'}))
raise SystemExit(process.returncode)
