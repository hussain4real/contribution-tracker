"""Attest an isolated release before allowing cutover operations."""
import argparse
import json
from pathlib import Path
import time

import requests

ROOT = Path(__file__).resolve().parents[2]
URL = 'https://familyfunds-d1-capacity.familyfunds-lab.workers.dev'


def verify(release, stage, inspect=False, activation_phase='private'):
    access = json.loads((ROOT / 'storage/app/private/production-candidate/cutover-access.json').read_text())
    headers = {'Authorization': access['basic_authorization'], 'X-Native-Admin': access['native_admin']}
    session = requests.Session()
    attempts = []
    deadline = time.monotonic() + 300
    while True:
        response = session.get(URL + '/up', headers=headers, timeout=90)
        edge_revision = response.headers.get('X-FamilyFunds-Runtime-Revision')
        edge_phase = response.headers.get('X-FamilyFunds-Activation-Phase')
        response = session.get(URL + '/__native/startup', headers=headers, timeout=30)
        try:
            ready = response.json()
        except ValueError:
            ready = {}
        attempts.append({'http': response.status_code, 'revision': ready.get('revision'), 'edge_revision': edge_revision, 'activation_phase': edge_phase})
        if response.status_code == 200 and ready.get('revision') == release and edge_revision == release and edge_phase == activation_phase:
            break
        if time.monotonic() >= deadline:
            raise RuntimeError('Expected native release did not converge')
        time.sleep(2)
    start = time.monotonic()
    response = session.get(URL + '/up', headers=headers, timeout=90)
    cold_seconds = time.monotonic() - start
    if response.status_code != 200 or response.headers.get('X-FamilyFunds-Runtime-Revision') != release or response.headers.get('X-FamilyFunds-Activation-Phase') != activation_phase:
        raise RuntimeError('Native PHP readiness or edge revision failed')
    response = session.get(URL + '/__native/runtime', headers=headers, timeout=45)
    if response.status_code != 200:
        raise RuntimeError('Native configuration inspection failed')
    runtime = response.json()
    expected = {'release': release, 'stage': stage, 'stored_generation': release, 'configuration_matches': True}
    if runtime.get('managed') != expected or runtime.get('build') != release:
        raise RuntimeError('Native generation and cached configuration do not match')
    response = session.get(URL + '/login', headers=headers, timeout=90)
    if response.status_code != 200 or 'FamilyFund' not in response.text or response.headers.get('X-FamilyFunds-Runtime-Revision') != release:
        raise RuntimeError('Laravel login-page acceptance failed')
    result = {'status': 'passed', 'release': release, 'stage': stage, 'activation_phase': activation_phase, 'startup_propagation': attempts,
              'readiness_seconds': round(cold_seconds, 3), 'runtime': runtime, 'login_http': response.status_code,
              'financial_writes': False, 'production_source_changed': False}
    if activation_phase != 'active':
        response = session.post(URL + '/__cutover_write_guard_probe', headers=headers, timeout=30)
        if response.status_code != 423:
            raise RuntimeError('Read-only request guard failed')
        result['write_guard_http'] = response.status_code
    if inspect:
        response = session.post(URL + '/__native/inspect-migration', headers=headers, timeout=180)
        if response.status_code != 200:
            raise RuntimeError('Private migration inspection rejected the release')
        inspection = response.json()
        if inspection.get('exit_code') != 0 or inspection.get('foreign_key_violations') != 0:
            raise RuntimeError('Migration integrity inspection failed')
        if inspection['providers']['paystack_http'] != 200 or inspection['providers']['meta_http'] != 200:
            raise RuntimeError('Read-only provider inspection failed')
        result['inspection'] = inspection
    return result


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--release', required=True)
    parser.add_argument('--stage', choices=['read-only', 'live'], required=True)
    parser.add_argument('--inspect', action='store_true')
    parser.add_argument('--activation-phase', choices=['private', 'read-only', 'active'], default='private')
    args = parser.parse_args()
    try:
        result = verify(args.release, args.stage, args.inspect, args.activation_phase)
        (ROOT / ('deployment/d1-proof/' + args.release + '-native-result.json')).write_text(json.dumps(result, indent=2) + '\n')
        print(json.dumps({k: v for k, v in result.items() if k not in ['runtime', 'inspection']}))
    except Exception as error:
        print(json.dumps({'status': 'failed', 'release': args.release, 'error_type': type(error).__name__,
                          'reason': str(error) if isinstance(error, RuntimeError) else 'Private transport details suppressed'}))
        raise SystemExit(1)
