"""Approved direct transfer of the four remaining application integration credentials."""
import hashlib
import json
from pathlib import Path
import stat
import subprocess

import requests

ROOT = Path(__file__).resolve().parents[2]
PRIVATE = ROOT / 'storage/app/private/production-candidate'
SOURCE = r'''<?php
try {
    if (posix_geteuid()!==0) { throw new RuntimeException(); }
    chdir('/var/www/contribution-tracker');require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (!app()->environment('production') || config('database.default')!=='pgsql') { throw new RuntimeException(); }
    $mapping=['OLLAMA_API_KEY'=>'ai.providers.ollama.key','OLLAMA_BASE_URL'=>'ai.providers.ollama.url',
        'OPENAI_API_KEY'=>'ai.providers.openai.key','OPENAI_URL'=>'ai.providers.openai.url','GEMINI_API_KEY'=>'ai.providers.gemini.key',
        'AI_AGENT_PROVIDER'=>'ai.agent.provider','AI_AGENT_MODEL'=>'ai.agent.model','AI_TRANSCRIPTION_PROVIDER'=>'ai.default_for_transcription',
        'GEMINI_TRANSCRIPTION_MODEL'=>'ai.providers.gemini.models.transcription.default','OPENAI_TRANSCRIPTION_MODEL'=>'ai.providers.openai.models.transcription.default',
        'GITHUB_RELEASES_TOKEN'=>'services.github.releases.token','GITHUB_RELEASES_OWNER'=>'services.github.releases.owner','GITHUB_RELEASES_REPO'=>'services.github.releases.repo',
        'GITHUB_RELEASES_CACHE_FRESH_TTL'=>'services.github.releases.cache_fresh_ttl','GITHUB_RELEASES_CACHE_STALE_TTL'=>'services.github.releases.cache_stale_ttl',
        'GITHUB_RELEASES_MAX'=>'services.github.releases.max','GITHUB_RELEASES_INCLUDE_PRERELEASES'=>'services.github.releases.include_prereleases','GITHUB_RELEASES_INCLUDE_BODY'=>'services.github.releases.include_body'];
    $out=[];foreach($mapping as $name=>$path){$v=config($path);if(!is_scalar($v)){throw new RuntimeException();}$out[$name]=is_bool($v)?($v?'true':'false'):(string)$v;}
    foreach(['OLLAMA_API_KEY','OPENAI_API_KEY','GEMINI_API_KEY','GITHUB_RELEASES_TOKEN'] as $name){if($out[$name]===''){throw new RuntimeException();}}
    echo json_encode($out,JSON_THROW_ON_ERROR);
} catch(Throwable) { fwrite(STDERR,"Integration source validation failed\n");exit(1); }
'''


def run():
    import argparse
    parser = argparse.ArgumentParser()
    parser.add_argument('--four-integrations-and-temporary-token-approved', action='store_true', required=True)
    parser.add_argument('--source-fingerprints-only', action='store_true')
    args = parser.parse_args()
    result = subprocess.run(['ssh', '-o', 'BatchMode=yes', 'root@49.12.236.101', 'php'], input=SOURCE.encode(),
                            stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=40)
    if result.returncode != 0 or len(result.stdout) > 4096:
        raise RuntimeError('Source validation failed; private output suppressed')
    config = json.loads(result.stdout)
    if not isinstance(config, dict) or len(config) != 18 or any(not isinstance(v, str) or '\0' in v for v in config.values()):
        raise RuntimeError('Unexpected integration configuration')
    if config['AI_AGENT_PROVIDER'] != 'ollama' or config['AI_TRANSCRIPTION_PROVIDER'] != 'gemini':
        raise RuntimeError('Integration providers changed after the source audit')
    if not args.source_fingerprints_only:
        token_path = PRIVATE / 'migration-api-token.json'
        if token_path.is_symlink() or stat.S_IMODE(token_path.stat().st_mode) != 0o600:
            raise RuntimeError('Private regular temporary token input required')
        token = json.loads(token_path.read_text())['value']
        endpoint = 'https://api.cloudflare.com/client/v4/accounts/40344948b73fa9da3008958fdc3faf12/workers/scripts/familyfunds-production-managed/secrets'
        response = requests.put(endpoint, headers={'Authorization': 'Bearer ' + token},
                                json={'name':'APPLICATION_INTEGRATION_ENV','type':'secret_text','text':json.dumps(config)}, timeout=30)
        if response.status_code not in (200, 201) or response.json().get('success') is not True:
            codes = [item.get('code') for item in response.json().get('errors', [])]
            raise RuntimeError('Integration secret upload failed: HTTP ' + str(response.status_code) + '; codes ' + str(codes))
    keys = ['OLLAMA_API_KEY', 'OPENAI_API_KEY', 'GEMINI_API_KEY', 'GITHUB_RELEASES_TOKEN']
    audit = {'status':'source-fingerprints-collected' if args.source_fingerprints_only else 'uploaded-native-verification-pending','worker':'familyfunds-production-managed','credential_names':keys,
             'key_fingerprints':{k:hashlib.sha256(config[k].encode()).hexdigest() for k in keys},
             'agent_provider':config['AI_AGENT_PROVIDER'],'agent_model':config['AI_AGENT_MODEL'],'transcription_provider':config['AI_TRANSCRIPTION_PROVIDER'],
             'private_key_file_created_locally':False,'source_written':False,'generation_or_transcription_requested':False}
    (ROOT/'deployment/d1-proof/production-additional-integration-transfer-result.json').write_text(json.dumps(audit,indent=2)+'\n')
    print(json.dumps({k:v for k,v in audit.items() if k!='key_fingerprints'}))


if __name__=='__main__':
    try:
        run()
    except Exception as error:
        print(json.dumps({'status':'failed','error_type':type(error).__name__,'reason':str(error) if isinstance(error,RuntimeError) else 'Private details suppressed'}))
        raise SystemExit(1)
