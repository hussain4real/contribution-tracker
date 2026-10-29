"""Copy manifest-referenced files directly from Hetzner using its installed S3 SDK."""
import argparse
import json
from pathlib import Path
import shlex
import re
import subprocess

ROOT = Path(__file__).resolve().parents[2]
REMOTE = r'''
$phase='manifest';
try {
    if (posix_geteuid()!==0) { throw new RuntimeException(); }
    require '/var/www/familyfunds-d1-staging/vendor/autoload.php';
    $request=json_decode(stream_get_contents(STDIN),true,flags:JSON_THROW_ON_ERROR);
    $root='/var/www/familyfunds-d1-staging/storage/app/private/prepared-production-runtime';
    if (!preg_match('/^file-sync-r2-credential-[a-z0-9-]+\.json$/D',$request['credential'])
        || !preg_match('/^(source-files-[0-9]+|cutover-[a-z0-9-]+-files)\.json$/D',$request['manifest'])
        || !preg_match('/^[a-f0-9]{64}$/D',$request['snapshot_sha256'])
        || !in_array($request['mode'],['probe','verify','copy'],true)) { throw new RuntimeException(); }
    $credentialPath=$root.'/'.$request['credential'];
    if (is_link($credentialPath) || !is_file($credentialPath) || fileowner($credentialPath)!==0 || (fileperms($credentialPath)&0777)!==0600) { throw new RuntimeException(); }
    $credential=json_decode(file_get_contents($credentialPath),true,flags:JSON_THROW_ON_ERROR);
    if ($credential['bucket']!=='familyfunds-production-private' || strtotime($credential['expires_on'])<time()+$request['minimum_valid_seconds']) { throw new RuntimeException(); }
    $manifestPath=$root.'/'.$request['manifest'];
    if (is_link($manifestPath) || !is_file($manifestPath) || fileowner($manifestPath)!==0 || (fileperms($manifestPath)&0077)!==0) { throw new RuntimeException(); }
    $manifest=json_decode(file_get_contents($manifestPath),true,flags:JSON_THROW_ON_ERROR);
    if ($manifest['missing']!==0 || count($manifest['files'])!==$request['files'] || $manifest['snapshot_sha256']!==$request['snapshot_sha256']) { throw new RuntimeException(); }
    $s3=new Aws\S3\S3Client(['version'=>'latest','region'=>'auto',
        'endpoint'=>'https://40344948b73fa9da3008958fdc3faf12.r2.cloudflarestorage.com','use_path_style_endpoint'=>true,
        'request_checksum_calculation'=>'when_required','response_checksum_validation'=>'when_required',
        'credentials'=>['key'=>$credential['access_key_id'],'secret'=>$credential['secret_access_key']],
        'http'=>['connect_timeout'=>5,'timeout'=>25],'retries'=>0]);
    $phase='credential_scope';$denied=false;
    try { $s3->listObjectsV2(['Bucket'=>'familyfunds-production-backups','MaxKeys'=>1]); }
    catch (Aws\S3\Exception\S3Exception $error) { $denied=$error->getStatusCode()===403; }
    if (!$denied) { throw new RuntimeException(); }
    if ($request['mode']==='probe') {
        $phase='credential_probe';$key='private/migration-file-sync-probe/'.bin2hex(random_bytes(16));$data=random_bytes(64);
        $parameters=['Bucket'=>$credential['bucket'],'Key'=>$key];$created=false;
        try {
            $s3->putObject($parameters+['Body'=>$data,'IfNoneMatch'=>'*']);$created=true;
            $actual=(string)$s3->getObject($parameters)['Body'];
            if (!hash_equals(hash('sha256',$data),hash('sha256',$actual))) { throw new RuntimeException(); }
        } finally { if ($created) { $s3->deleteObject($parameters); } }
        if ($s3->doesObjectExistV2($parameters['Bucket'],$key)) { throw new RuntimeException(); }
        echo json_encode(['status'=>'passed','credential_root_only'=>true,'minimum_valid_seconds'=>$request['minimum_valid_seconds'],
            'expires_on'=>$credential['expires_on'],'write_read_delete_probe'=>true,'out_of_scope_backup_bucket_denied'=>true,'source_written'=>false],JSON_THROW_ON_ERROR);exit(0);
    }
    $checked=[];
    foreach ($manifest['files'] as $item) {
        $phase='source_file';
        if (!in_array($item['disk'],['local','public'],true) || str_starts_with($item['path'],'/') || str_contains($item['path'],'..') || str_contains($item['path'],'\\')) { throw new RuntimeException(); }
        $allowed='/var/www/contribution-tracker/storage/app/'.($item['disk']==='public'?'public':'private').'/';
        $source=realpath($item['source']);
        if (!is_string($source) || !str_starts_with($source,$allowed)) { throw new RuntimeException(); }
        $data=file_get_contents($source);
        if (!is_string($data) || strlen($data)!==$item['bytes'] || hash('sha256',$data)!==$item['sha256']) { throw new RuntimeException(); }
        $key=($item['disk']==='public'?'public/':'private/').$item['path'];
        $parameters=['Bucket'=>$credential['bucket'],'Key'=>$key];
        $phase='existing_object';$missing=false;
        try {
            $existing=(string)$s3->getObject($parameters)['Body'];
            if (strlen($existing)!==strlen($data) || hash('sha256',$existing)!==$item['sha256']) { throw new RuntimeException(); }
        } catch (Aws\S3\Exception\S3Exception $error) {
            if ($error->getStatusCode()!==404) { throw $error; } $missing=true;
        }
        if ($missing && $request['mode']!=='copy') { throw new RuntimeException(); }
        if ($missing) {
            $phase='upload';
            $s3->putObject($parameters+['Body'=>$data,'ContentLength'=>strlen($data),'ContentType'=>'application/pdf','IfNoneMatch'=>'*']);
        }
        $phase='verify';$actual=(string)$s3->getObject($parameters)['Body'];
        if (strlen($actual)!==strlen($data) || hash('sha256',$actual)!==$item['sha256']) { throw new RuntimeException(); }
        $checked[]=['path_sha256'=>hash('sha256',$item['disk'].':'.$item['path']),'bytes'=>strlen($data),'sha256'=>$item['sha256']];
    }
    $phase='negative_scope';$denied=false;
    try { $s3->listObjectsV2(['Bucket'=>'familyfunds-production-backups','MaxKeys'=>1]); }
    catch (Aws\S3\Exception\S3Exception $error) { $denied=$error->getStatusCode()===403; }
    if (!$denied) { throw new RuntimeException(); }
    echo json_encode(['status'=>'passed','files'=>$checked,'count'=>count($checked),'total_bytes'=>array_sum(array_column($checked,'bytes')),
        'out_of_scope_backup_bucket_denied'=>true,'production_source_written'=>false,'private_files_saved_locally'=>false,'client'=>'Existing isolated Hetzner S3 SDK 3.402.0'],JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    echo json_encode(['status'=>'failed','phase'=>$phase,'error_type'=>get_class($error),
        'http'=>$error instanceof Aws\Exception\AwsException?$error->getStatusCode():null,
        'code'=>$error instanceof Aws\Exception\AwsException?$error->getAwsErrorCode():null]);exit(1);
}
'''

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--credential', required=True)
    parser.add_argument('--manifest', required=True)
    parser.add_argument('--snapshot-sha256', required=True)
    parser.add_argument('--files', type=int, required=True)
    parser.add_argument('--mode', choices=['probe', 'verify', 'copy'], default='verify')
    parser.add_argument('--minimum-valid-seconds', type=int, default=1800)
    parser.add_argument('--result', required=True)
    args = parser.parse_args()
    if not re.fullmatch(r'file-sync-r2-credential-[a-z0-9-]+\.json', args.credential):
        raise SystemExit('A prepared root-only credential filename is required')
    if not re.fullmatch(r'(source-files-[0-9]+|cutover-[a-z0-9-]+-files)\.json', args.manifest):
        raise SystemExit('A prepared file manifest filename is required')
    if not re.fullmatch('[a-f0-9]{64}', args.snapshot_sha256) or not 1 <= args.files <= 2000 or not 300 <= args.minimum_valid_seconds <= 7200:
        raise SystemExit('Bounded manifest identity and credential validity are required')
    result_path = Path(args.result).resolve()
    if result_path.parent != ROOT / 'deployment/d1-proof' or not re.fullmatch('production-[a-z0-9-]+-result.json', result_path.name) or result_path.exists():
        raise SystemExit('A fresh proof result path is required')
    request = {'credential': args.credential, 'manifest': args.manifest, 'snapshot_sha256': args.snapshot_sha256,
               'files': args.files, 'mode': args.mode, 'minimum_valid_seconds': args.minimum_valid_seconds}
    process = subprocess.run(['ssh', '-o', 'BatchMode=yes', 'root@49.12.236.101', 'php -r ' + shlex.quote(REMOTE)],
                             input=json.dumps(request).encode(), stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=600)
    try:
        result = json.loads(process.stdout)
    except (ValueError, UnicodeError):
        raise SystemExit('File migration failed; private output suppressed')
    result_path.write_text(json.dumps(result, indent=2) + '\n')
    print(json.dumps({k: v for k, v in result.items() if k != 'files'}))
    raise SystemExit(process.returncode)


if __name__ == '__main__':
    main()
