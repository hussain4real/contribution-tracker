"""Approved, one-shot SSH-to-Worker identity transfer; production keys stay in RAM."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import stat
import subprocess

import requests

ROOT = Path(__file__).resolve().parents[2]
PRIVATE = ROOT / 'storage/app/private/production-candidate'
ACCOUNT = '40344948b73fa9da3008958fdc3faf12'
WORKER = 'familyfunds-production-managed'
MODULES = ['production-managed-worker.mjs', 'production-worker.mjs', 'container-worker.mjs',
           'container-bootstrap-assets.mjs', 'container-env.mjs', 'production-webhooks.mjs', 'production-sql-worker.mjs',
           'production-integration-check.mjs', 'production-container-target.mjs']
IDENTITY = {'APP_KEY', 'PASSPORT_PRIVATE_KEY', 'PASSPORT_PUBLIC_KEY',
            'VAPID_PRIVATE_KEY', 'VAPID_PUBLIC_KEY', 'VAPID_SUBJECT'}

SOURCE = r'''<?php
$phase = 'bootstrap';
try {
    if (PHP_SAPI !== 'cli' || posix_geteuid() !== 0) { throw new RuntimeException(); }
    chdir('/var/www/contribution-tracker');
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (!app()->environment('production') || config('database.default') !== 'pgsql') { throw new RuntimeException(); }
    $values = ['APP_KEY' => config('app.key'),
        'PASSPORT_PRIVATE_KEY' => config('passport.private_key') ?: file_get_contents(storage_path('oauth-private.key')),
        'PASSPORT_PUBLIC_KEY' => config('passport.public_key') ?: file_get_contents(storage_path('oauth-public.key')),
        'VAPID_PRIVATE_KEY' => config('webpush.vapid.private_key'), 'VAPID_PUBLIC_KEY' => config('webpush.vapid.public_key'),
        'VAPID_SUBJECT' => config('webpush.vapid.subject') ?: 'https://familyfunds.app'];
    $phase = 'identity';
    foreach ($values as $value) { if (!is_string($value) || $value === '' || strlen($value) > 16000) { throw new RuntimeException(); } }
    $signature = ''; $challenge = random_bytes(32);
    if (!openssl_sign($challenge, $signature, $values['PASSPORT_PRIVATE_KEY'], OPENSSL_ALGO_SHA256)
        || openssl_verify($challenge, $signature, $values['PASSPORT_PUBLIC_KEY'], OPENSSL_ALGO_SHA256) !== 1) { throw new RuntimeException(); }
    $providers = []; $backupKey = null;
    if ($includeProviders ?? false) {
        $phase = 'provider_fields';
        foreach (['PAYSTACK_SECRET_KEY'=>'services.paystack.secret_key', 'PAYSTACK_PUBLIC_KEY'=>'services.paystack.public_key',
            'PAYSTACK_WEBHOOK_SECRET'=>'services.paystack.webhook_secret', 'WHATSAPP_ACCESS_TOKEN'=>'services.whatsapp.access_token',
            'WHATSAPP_PHONE_NUMBER_ID'=>'services.whatsapp.phone_number_id', 'WHATSAPP_BUSINESS_ACCOUNT_ID'=>'services.whatsapp.business_account_id',
            'WHATSAPP_API_VERSION'=>'services.whatsapp.api_version', 'WHATSAPP_APP_SECRET'=>'services.whatsapp.app_secret',
            'WHATSAPP_WEBHOOK_VERIFY_TOKEN'=>'services.whatsapp.webhook_verify_token',
            'NIGHTWATCH_TOKEN'=>'nightwatch.token'] as $name=>$path) {
            $value = config($path); if (!is_string($value) || $value === '') { throw new RuntimeException(); } $providers[$name] = $value;
        }
        $phase = 'meta_app'; $id = '26405719292393073';
        $appCheck = Illuminate\Support\Facades\Http::withToken($id.'|'.$providers['WHATSAPP_APP_SECRET'])->connectTimeout(5)->timeout(15)
            ->get('https://graph.facebook.com/'.$providers['WHATSAPP_API_VERSION'].'/'.$id, ['fields'=>'id']);
        if (!$appCheck->successful() || $appCheck->json('id')!==$id) { throw new RuntimeException(); }
        $providers['FACEBOOK_APP_ID'] = $id;
        $phase = 'mail';
        $mail = json_decode(file_get_contents('/var/www/familyfunds-d1-staging/storage/app/private/prepared-production-sending/cloudflare-email.json'), true, flags:JSON_THROW_ON_ERROR);
        $providers += ['CLOUDFLARE_MAIL_TOKEN'=>$mail['token'], 'CLOUDFLARE_MAIL_ACCOUNT_ID'=>$mail['account_id'],
            'MAIL_FROM_ADDRESS'=>$mail['sender'], 'MAIL_FROM_NAME'=>config('mail.from.name'), 'MAIL_MAILER'=>'cloudflare'];
        $phase = 'backup_recovery_key';
        $root = '/var/www/familyfunds-d1-staging/storage/app/private/prepared-production-runtime';
        if (is_link($root) || fileowner($root)!==0 || (fileperms($root)&0777)!==0700) { throw new RuntimeException(); }
        $path = $root.'/actual-production-backup-key.json'; umask(0077);
        if (!file_exists($path)) { $h=fopen($path,'x'); if ($h===false) { throw new RuntimeException(); }
            fwrite($h,json_encode(['backup_key'=>'base64:'.base64_encode(random_bytes(32))],JSON_THROW_ON_ERROR)); fclose($h); }
        if (is_link($path) || fileowner($path)!==0 || (fileperms($path)&0777)!==0600) { throw new RuntimeException(); }
        $backupKey = json_decode(file_get_contents($path),true,flags:JSON_THROW_ON_ERROR)['backup_key'];
        if (!is_string($backupKey) || !str_starts_with($backupKey,'base64:') || strlen(base64_decode(substr($backupKey,7),true))!==32) { throw new RuntimeException(); }
    }
    echo json_encode(['identity'=>$values,'providers'=>$providers,'backup_key'=>$backupKey], JSON_THROW_ON_ERROR);
} catch (Throwable $error) { fwrite(STDERR, 'SOURCE_FAILURE:'.$phase.':'.get_class($error)."\n"); exit(1); }
'''


def private_json(path):
    if path.is_symlink() or not path.is_file() or stat.S_IMODE(path.stat().st_mode) != 0o600:
        raise RuntimeError('A regular mode-0600 private input is required')
    return json.loads(path.read_text())


def run():
    parser = argparse.ArgumentParser()
    parser.add_argument('--identity-transfer-approved', action='store_true', required=True)
    parser.add_argument('--temporary-token-approved', action='store_true', required=True)
    parser.add_argument('--provider-credentials-approved', action='store_true')
    args = parser.parse_args()
    if not (args.identity_transfer_approved and args.temporary_token_approved):
        raise RuntimeError('Both recorded approvals are required')
    token = private_json(PRIVATE / 'migration-api-token.json')['value']
    environment = private_json(PRIVATE / 'migration-synthetic-env.json')
    access = private_json(PRIVATE / 'migration-access.json')
    proof = json.loads((ROOT / 'deployment/d1-proof/production-migration-paused-native-result.json').read_text())
    image = proof['runtime']['image']
    if proof['status'] != 'passed' or proof['runtime']['production']['queue_worker_processes'] != 0:
        raise RuntimeError('Native queue-paused proof must pass before identity transfer')
    package = json.loads((ROOT / 'deployment/d1-proof/production-flat-image-package-result.json').read_text())
    if not image.endswith('@' + package['digest']):
        raise RuntimeError('The native proof does not match the current image')
    for worker in [WORKER, 'familyfunds-d1-capacity']:
        response = requests.get(f'https://api.cloudflare.com/client/v4/accounts/{ACCOUNT}/workers/scripts/{worker}/subdomain',
                                headers={'Authorization': 'Bearer ' + token}, timeout=20)
        status = response.json()
        if response.status_code != 200 or status.get('success') is not True or any(status['result'][k] for k in ['enabled', 'previews_enabled']):
            raise RuntimeError('Both public endpoints must be disabled before identity transfer')
    program = SOURCE.replace("    $values =", "    $includeProviders = true;\n    $values =", 1) if args.provider_credentials_approved else SOURCE
    source = subprocess.run(['ssh', '-o', 'BatchMode=yes', 'root@49.12.236.101', 'php'],
                            input=program.encode(), stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=45)
    if source.returncode != 0 or len(source.stdout) > 32000:
        import re
        diagnostic = re.search(rb'SOURCE_FAILURE:[a-z_]+:[A-Za-z\\]+', source.stderr)
        raise RuntimeError(diagnostic.group().decode() if diagnostic else 'Source identity validation failed; output suppressed')
    captured = json.loads(source.stdout)
    values = captured['identity']
    if set(values) != IDENTITY or any(not isinstance(v, str) or not v or '\0' in v for v in values.values()):
        raise RuntimeError('Unexpected identity fields; output suppressed')
    expected = json.loads((ROOT / 'deployment/d1-proof/production-actual-source-identity-audit-result.json').read_text())['key_fingerprints']
    for field, name in [('APP_KEY', 'app_key'), ('PASSPORT_PUBLIC_KEY', 'passport_public_key'), ('VAPID_PUBLIC_KEY', 'vapid_public_key')]:
        if hashlib.sha256(values[field].encode()).hexdigest() != expected[name]:
            raise RuntimeError('Source identity changed after the snapshot audit; repeat preparation')
    environment.update(values)
    if args.provider_credentials_approved:
        providers = captured['providers']
        if any(not isinstance(v, str) or not v or '\0' in v for v in providers.values()):
            raise RuntimeError('Invalid source provider configuration')
        environment.update(providers)
        r2 = private_json(PRIVATE / 'actual-r2-credential.json')
        if r2['bucket'] != 'familyfunds-production-private':
            raise RuntimeError('Unexpected application bucket')
        environment.update(R2_ACCESS_KEY_ID=r2['access_key_id'], R2_SECRET_ACCESS_KEY=r2['secret_access_key'], R2_BUCKET=r2['bucket'])
    environment.update(QUEUE_WORKER_ENABLED='false', D1_MIGRATION_CHECKS='true',
                       D1_READ_ONLY_PROVIDER_CHECKS='true' if args.provider_credentials_approved else 'false', D1_PRODUCTION_DELIVERY_ENABLED='false',
                       D1_PRODUCTION_WEBHOOKS_ENABLED='false', NIGHTWATCH_ENABLED='false')
    encoded = json.dumps(environment, ensure_ascii=True, separators=(',', ':'))
    parts = [encoded[i:i + 3500] for i in range(0, len(encoded), 3500)]
    if not 1 <= len(parts) <= 16:
        raise RuntimeError('Environment exceeds bounded secret-part budget')
    bindings = [{'type': 'secret_text', 'name': f'CONTAINER_ENV_{i}', 'text': part} for i, part in enumerate(parts)]
    bindings += [{'type': 'plain_text', 'name': name, 'text': value} for name, value in {
        'CONTAINER_ENV_PARTS': str(len(parts)), 'CANDIDATE_DIAGNOSTICS': '1', 'TRAFFIC_ENABLED': 'false',
        'WEBHOOKS_ENABLED': 'false', 'RUNTIME_PROFILE': 'production', 'CONTAINER_IMAGE': image,
        'RUNTIME_REVISION': proof['runtime']['build'], 'APP_CONCURRENCY': '20'}.items()]
    bindings += [{'type': 'secret_text', 'name': 'CANDIDATE_AUTHORIZATION', 'text': access['basic_authorization']},
                 {'type': 'secret_text', 'name': 'CANDIDATE_TOKEN', 'text': access['preview_token']}]
    if args.provider_credentials_approved:
        webhook = {'paystack_secret': providers['PAYSTACK_WEBHOOK_SECRET'], 'meta_app_id': providers['FACEBOOK_APP_ID'],
                   'meta_app_secret': providers['WHATSAPP_APP_SECRET'], 'meta_waba': providers['WHATSAPP_BUSINESS_ACCOUNT_ID'],
                   'meta_phone': providers['WHATSAPP_PHONE_NUMBER_ID'], 'meta_verify_token': providers['WHATSAPP_WEBHOOK_VERIFY_TOKEN']}
        if webhook['meta_app_id'] != '26405719292393073':
            raise RuntimeError('Production Meta app identity differs from the reviewed integration')
        bindings.append({'type':'secret_text', 'name':'WEBHOOK_SECRETS', 'text':json.dumps(webhook)})
    metadata = {'main_module': MODULES[0], 'compatibility_date': '2026-10-27', 'compatibility_flags': ['nodejs_compat'],
                'bindings': bindings, 'keep_bindings': ['plain_text', 'secret_text', 'durable_object_namespace', 'd1', 'r2_bucket', 'queue', 'service'],
                'containers': [{'class_name': 'FamilyFundsManagedProductionContainer', 'images': {'base': image}}],
                'observability': {'enabled': True, 'head_sampling_rate': 1}}
    files = [('metadata', (None, json.dumps(metadata), 'application/json'))]
    files += [(name, (name, (ROOT / 'deployment/d1-proof' / name).read_bytes(), 'application/javascript+module')) for name in MODULES]
    response = requests.put(f'https://api.cloudflare.com/client/v4/accounts/{ACCOUNT}/workers/scripts/{WORKER}',
                            headers={'Authorization': 'Bearer ' + token}, files=files, timeout=60)
    if response.status_code != 200 or response.json().get('success') is not True:
        raise RuntimeError(f'Worker upload failed (HTTP {response.status_code}); response suppressed')
    if args.provider_credentials_approved:
        response = requests.put(f'https://api.cloudflare.com/client/v4/accounts/{ACCOUNT}/workers/scripts/familyfunds-production-maintenance/secrets',
                                headers={'Authorization': 'Bearer ' + token},
                                json={'name':'BACKUP_KEY', 'type':'secret_text', 'text':captured['backup_key']}, timeout=30)
        if response.status_code != 200 or response.json().get('success') is not True:
            raise RuntimeError('Maintenance backup-key secret upload failed; response suppressed')
    fingerprints = {name: hashlib.sha256(values[name].encode()).hexdigest()
                    for name in ['APP_KEY', 'PASSPORT_PUBLIC_KEY', 'VAPID_PUBLIC_KEY']}
    result = {'status': 'uploaded-native-verification-pending', 'worker': WORKER, 'image': image,
              'fields_transferred': sorted(IDENTITY), 'key_fingerprints': fingerprints,
              'production_key_file_created_locally': False, 'source_writes': False,
              'provider_credentials_transferred': args.provider_credentials_approved,
              'backup_key_transferred_to_maintenance_only': args.provider_credentials_approved,
              'backup_key_recovery_copy_on_hetzner': args.provider_credentials_approved,
              'delivery_enabled': False, 'queue_worker_enabled': False}
    (ROOT / 'deployment/d1-proof/production-identity-transfer-result.json').write_text(json.dumps(result, indent=2) + '\n')
    print(json.dumps({k: v for k, v in result.items() if k != 'key_fingerprints'}))


if __name__ == '__main__':
    try:
        run()
    except Exception as error:
        # Transport exception text can contain request details; never print it.
        print(json.dumps({'status': 'failed', 'error_type': type(error).__name__,
                          'reason': str(error) if isinstance(error, RuntimeError) else 'Details suppressed'}))
        raise SystemExit(1)
