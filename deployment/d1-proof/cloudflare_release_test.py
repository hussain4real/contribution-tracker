import contextlib
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import tarfile
import tempfile
import unittest
from unittest.mock import patch


def load(name):
    spec = importlib.util.spec_from_file_location(name.replace('-', '_'), Path(__file__).with_name(name + '.py'))
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


release = load('cloudflare-release')
image = load('cloudflare-image')
SHA = '1' * 40
DIGEST = 'sha256:' + '2' * 64


class ReleaseSafetyTest(unittest.TestCase):
    def test_image_configuration_rejects_credentials_and_preserves_entrypoint(self):
        for name in ['APP_KEY', 'OPENAI_API_KEY', 'SQL_TOKEN', 'DB_PASSWORD', 'PASSPORT_PRIVATE_KEY']:
            with self.assertRaises(ValueError):
                image.package_config({'Env': [name + '=must-stay-out']})
        result = image.package_config({'Env': ['APP_ENV=production'], 'Entrypoint': ['/entry.sh'], 'Cmd': ['serve']})
        self.assertIn('ENTRYPOINT ["/entry.sh"]', result)
        self.assertIn('CMD ["serve"]', result)

    def test_root_filesystem_rejects_environment_cached_config_and_private_files(self):
        for name in ['var/www/html/.env', 'var/www/html/.env.production', 'var/www/html/bootstrap/cache/config.php', 'var/www/html/storage/app/private/key.bin']:
            with tempfile.NamedTemporaryFile(suffix='.tar') as file:
                with tarfile.open(file.name, 'w') as archive:
                    entry = tarfile.TarInfo(name)
                    entry.size = 1
                    archive.addfile(entry, io.BytesIO(b'x'))
                with self.assertRaises(ValueError):
                    image.inspect_archive(file.name)

    def test_metadata_preserves_secret_bindings_and_repeats_pinned_container(self):
        container = {'class_name': 'Container', 'images': {'base': 'repo@' + DIGEST}}
        raw, mime = release.multipart('main.mjs', {'main.mjs': b'export default {}'}, [], container)
        message = release.email.parser.BytesParser(policy=release.email.policy.default).parsebytes(('Content-Type: ' + mime + '\r\n\r\n').encode() + raw)
        metadata = json.loads(next(message.iter_parts()).get_payload(decode=True))
        self.assertEqual(metadata['keep_bindings'], ['secret_text'])
        self.assertEqual(metadata['containers'], [container])
        self.assertEqual(list(message.iter_parts())[1].get_filename(), 'main.mjs')
        self.assertNotIn('secret value', raw.decode())
        self.assertEqual(release.script_sources(raw, mime), {'main.mjs': b'export default {}'})

    def test_schema_change_is_refused_without_touching_cloudflare(self):
        with patch.object(release, 'migration_fingerprint', return_value='unverified'), patch.object(release, 'api') as api:
            with self.assertRaisesRegex(RuntimeError, 'accepted D1 schema'):
                release.validate()
            api.assert_not_called()

    def test_production_entry_pins_the_exact_image_revision_and_active_phase(self):
        sources = release.modules('cloudflare-production-entry.mjs', 'repo@' + DIGEST, 'github-test')
        entry = sources['cloudflare-production-entry.mjs'].decode()
        self.assertIn('repo@' + DIGEST, entry)
        self.assertIn('github-test', entry)
        self.assertIn("const MANAGED_ACTIVATION = 'active'", entry)
        self.assertIn('production-sql-worker.mjs', sources)

    def test_offsite_worker_cannot_inherit_database_or_application_credentials(self):
        for name in ['DB', 'CONTAINER_ENV_0', 'APP_KEY', 'BACKUP_KEY']:
            with self.assertRaisesRegex(RuntimeError, 'isolation'):
                release.checked_bindings('familyfunds-production-offsite', {'bindings': [{'name': name, 'type': 'secret_text'}]})

    def test_native_inspection_requires_running_status_and_exact_image(self):
        with patch.object(release, 'api', return_value=[{'status': {'state': 'stopped'}, 'image': 'expected'}, {'status': {'state': 'running'}, 'image': 'old'}]):
            self.assertFalse(release.instance_image('production', 'expected'))
        with patch.object(release, 'api', return_value=[{'status': {'state': 'running'}, 'image': 'expected', 'name': release.CONFIG['production']['instance_name']}]):
            self.assertTrue(release.instance_image('production', 'expected'))

    def test_scheduler_backed_staging_releases_only_the_image_through_a_rollout(self):
        with patch.object(release, 'api', return_value={}) as api:
            release.rollout_image('/containers/applications/staging', 'repo@' + DIGEST)
        self.assertEqual(api.call_args.args[0:2], ('POST', '/containers/applications/staging/rollouts'))
        self.assertEqual(api.call_args.args[2]['target_configuration'], {'image': 'repo@' + DIGEST})
        self.assertEqual(api.call_args.args[2]['strategy'], 'rolling')

    def test_failed_preparation_never_reaches_worker_mutation(self):
        with patch.object(release, 'api', return_value={'status': 'error'}) as api:
            with self.assertRaisesRegex(RuntimeError, 'preparation failed'):
                release.prepared('repo@' + DIGEST)
            self.assertEqual(api.call_args.args[1], '/containers/image-preparations')

    def test_staging_deployment_and_rollback_both_preserve_container_enablement(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory)
            (path / 'rootfs.tar').write_bytes(b'inspected image')
            (path / 'original-image.tar').write_bytes(b'inspected original image')
            (path / 'release.json').write_text(json.dumps({'target': 'staging', 'revision': SHA, 'unstarted_export': True,
                                                        'runtime_credentials_packaged': False, 'rootfs_sha256': hashlib.sha256(b'inspected image').hexdigest(),
                                                        'image_archive_sha256': hashlib.sha256(b'inspected original image').hexdigest(), 'source_image_id': DIGEST}))
            old_raw, old_mime = release.multipart('container-worker.mjs', {'container-worker.mjs': b'old reviewed code'}, [], None)
            writes = []
            commands = []

            def api(method, url, data=None, **kwargs):
                if 'credentials' in url:
                    return {'password': 'synthetic', 'username': 'synthetic', 'registry_host': 'registry.cloudflare.com'}
                if url.endswith('/settings'):
                    return {'bindings': []}
                if method == 'GET' and '/containers/applications/' in url:
                    return {'configuration': {'image': 'old@' + DIGEST}}
                if method == 'GET':
                    return old_raw, old_mime
                if method == 'PUT':
                    message = release.email.parser.BytesParser(policy=release.email.policy.default).parsebytes(
                        ('Content-Type: ' + kwargs['content_type'] + '\r\n\r\n').encode() + data)
                    writes.append(json.loads(next(message.iter_parts()).get_payload(decode=True)))
                    if len(writes) == 1:
                        raise RuntimeError('simulated unknown update outcome')
                    return {}
                raise AssertionError('Unexpected or database API call: ' + url)

            def command(args, **kwargs):
                commands.append(args)

            def output(args, **kwargs):
                return DIGEST if args[-1] == '{{.Id}}' else json.dumps([
                    'registry.cloudflare.com/' + release.ACCOUNT + '/familyfunds-d1-staging@' + DIGEST])

            target = {**release.CONFIG['staging'], 'companions': []}
            with patch.dict(release.CONFIG, {'staging': target}), patch.dict(os.environ, {'GITHUB_SHA': SHA, 'GITHUB_REF': 'refs/heads/cloudflare-staging', 'GITHUB_ACTIONS': 'true', 'DOCKER_CONFIG': directory}), patch.object(release, 'validate'), patch.object(release, 'public_checks'), patch.object(release, 'prepared') as prepared, patch.object(release, 'rollout_image'), patch.object(release, 'api', side_effect=api), patch.object(release.subprocess, 'run', side_effect=command), patch.object(release.subprocess, 'check_output', side_effect=output), contextlib.redirect_stdout(io.StringIO()):
                with self.assertRaisesRegex(RuntimeError, 'previous code/image restored'):
                    release.publish('staging', path)
                prepared.assert_not_called()
            self.assertEqual(len(writes), 2)
            self.assertTrue(any(args[:3] == ['docker', 'image', 'load'] for args in commands))
            self.assertTrue(any(args[:2] == ['docker', 'push'] for args in commands))
            self.assertFalse(any('buildx' in args for args in commands))
            for metadata in writes:
                self.assertEqual(metadata['containers'], [{'class_name': 'FamilyFundsWebStagingContainer'}])

    def test_staging_gateway_requires_reviewed_previous_or_matching_candidate_source(self):
        reviewed = hashlib.sha256(b'reviewed').hexdigest()
        release.verify_gateway_source(b'reviewed', b'new', reviewed)
        release.verify_gateway_source(b'new', b'new', reviewed)
        with self.assertRaisesRegex(RuntimeError, 'differs from the reviewed'):
            release.verify_gateway_source(b'unexpected', b'new', reviewed)
        with self.assertRaisesRegex(RuntimeError, 'fingerprint'):
            release.verify_gateway_source(b'reviewed', b'new', None)

    def test_staging_gateway_requires_version_urls_disabled(self):
        with patch.object(release, 'api', return_value={'enabled': True, 'previews_enabled': False}) as api:
            release.require_disabled_gateway_version_urls('/workers/scripts/example')
            api.assert_called_once_with('GET', '/workers/scripts/example/subdomain')
        for state in [True, None]:
            with self.subTest(previews_enabled=state), patch.object(release, 'api', return_value={'previews_enabled': state}):
                with self.assertRaisesRegex(RuntimeError, 'version URLs must be disabled'):
                    release.require_disabled_gateway_version_urls('/workers/scripts/example')

    def test_staging_public_checks_require_gateway_health(self):
        class Response:
            def __init__(self, body):
                self.status = 200
                self.headers = {}
                self.body = body
            def __enter__(self):
                return self
            def __exit__(self, *args):
                return False
            def read(self, limit):
                return self.body

        responses = [Response(b'ok'), Response(b'<html>password</html>')]
        with patch.dict(os.environ, {'STAGING_BASIC_AUTHORIZATION': 'Basic fixture'}), patch.object(release.urllib.request, 'urlopen', side_effect=responses) as urlopen:
            release.public_checks('staging')
            self.assertEqual(urlopen.call_count, 2)

        for health in [b'{"ok":false}', b'{"ok":1}', b'{"ok":true,"extra":1}', b'not json', b' ' * 4097]:
            responses = [Response(b'ok'), Response(b'<html>password</html>'), Response(health)]
            with self.subTest(health=health), patch.dict(os.environ, {'STAGING_BASIC_AUTHORIZATION': 'Basic fixture'}), patch.object(release.urllib.request, 'urlopen', side_effect=responses):
                with self.assertRaisesRegex(RuntimeError, 'gateway health'):
                    release.public_checks('staging', 'github-fixture')
        failure = release.urllib.error.HTTPError('https://example.invalid', 503, 'failure', {}, io.BytesIO(b'{"ok":false,"stage":"gateway_token"}'))
        responses = [Response(b'ok'), Response(b'<html>password</html>'), failure]
        with patch.dict(os.environ, {'STAGING_BASIC_AUTHORIZATION': 'Basic fixture'}), patch.object(release.urllib.request, 'urlopen', side_effect=responses):
            with self.assertRaises(release.StagingGatewayHealthError) as raised:
                release.public_checks('staging', 'github-fixture')
            self.assertEqual(raised.exception.stage, 'gateway_token')

        responses = [Response(b'ok'), Response(b'<html>password</html>'), Response(b'{"ok":true}')]
        with patch.dict(os.environ, {'STAGING_BASIC_AUTHORIZATION': 'Basic fixture'}), patch.object(release.urllib.request, 'urlopen', side_effect=responses) as urlopen:
            release.public_checks('staging', 'github-fixture')
            request = urlopen.call_args.args[0]
            self.assertTrue(request.full_url.endswith('/__staging_health/gateway'))
            self.assertEqual(request.get_header('Authorization'), 'Basic fixture')

    def test_gateway_failure_stage_accepts_only_fixed_nonsecret_stages(self):
        def error(status, body):
            return release.urllib.error.HTTPError('https://example.invalid', status, 'failure', {}, io.BytesIO(body))

        for stage in release.GATEWAY_FAILURE_STAGES:
            body = json.dumps({'ok': False, 'stage': stage}).encode()
            self.assertEqual(release.gateway_failure_stage(error(503, body)), stage)
        for status, body in [
            (401, b'{"ok":false,"stage":"gateway_authentication"}'),
            (503, b'{"ok":false,"stage":"secret value"}'),
            (503, b'{"ok":false,"stage":"gateway_http","extra":"secret"}'),
            (503, b'{"ok":true,"stage":"gateway_http"}'),
            (503, b'{"ok":false,"stage":[]}'),
            (503, b' ' * 4097),
            (503, b'invalid'),
        ]:
            with self.subTest(status=status, body=body):
                self.assertIsNone(release.gateway_failure_stage(error(status, body)))

    def test_staging_web_gateway_binding_must_target_the_expected_service(self):
        expected = {'name': 'STAGING_SQL_GATEWAY', 'type': 'service', 'service': 'familyfunds-d1-staging-sql'}
        self.assertEqual(release.checked_bindings(release.CONFIG['staging']['worker'], {'bindings': [expected]}), [expected])
        for change in [{'service': 'other'}, {'type': 'plain_text'}, {'environment': 'preview'}, {'entrypoint': 'Other'}]:
            with self.subTest(change=change), self.assertRaisesRegex(RuntimeError, 'Unexpected staging gateway service binding'):
                release.checked_bindings(release.CONFIG['staging']['worker'], {'bindings': [{**expected, **change}]})

    def test_gateway_target_checks_reject_unexpected_bindings(self):
        expected = [
            {'name': 'DB', 'type': 'd1', 'id': 'f96058f1-0aa3-42f3-9ee2-f796554d6ef4'},
            {'name': 'DATABASE_NAME', 'type': 'plain_text', 'text': 'familyfunds-d1-web-staging'},
            {'name': 'PROOF_TOKEN', 'type': 'secret_text'},
        ]
        self.assertEqual(release.checked_bindings('familyfunds-d1-staging-sql', {'bindings': expected}), expected[:2])
        for broken in [expected[1:], [{**expected[0], 'id': 'different'}, *expected[1:]], expected + [{'name': 'STAGING_GATEWAY_ADMISSION', 'type': 'plain_text', 'text': '1'}]]:
            with self.subTest(bindings=broken), self.assertRaises(RuntimeError):
                release.checked_bindings('familyfunds-d1-staging-sql', {'bindings': broken})

    def test_staging_gateway_code_only_update_restores_old_module_on_web_failure(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory)
            (path / 'rootfs.tar').write_bytes(b'inspected image')
            (path / 'original-image.tar').write_bytes(b'inspected original image')
            (path / 'release.json').write_text(json.dumps({'target': 'staging', 'revision': SHA, 'unstarted_export': True,
                'runtime_credentials_packaged': False, 'rootfs_sha256': hashlib.sha256(b'inspected image').hexdigest(),
                'image_archive_sha256': hashlib.sha256(b'inspected original image').hexdigest(), 'source_image_id': DIGEST}))
            old_gateway = release.multipart('worker.mjs', {'worker.mjs': b'old gateway code'}, [], content_only=True)
            old_web = release.multipart('container-worker.mjs', {'container-worker.mjs': b'old web code'}, [], None)
            writes = []
            gateway_bindings = [
                {'name': 'DB', 'type': 'd1', 'id': 'f96058f1-0aa3-42f3-9ee2-f796554d6ef4'},
                {'name': 'DATABASE_NAME', 'type': 'plain_text', 'text': 'familyfunds-d1-web-staging'},
                {'name': 'PROOF_TOKEN', 'type': 'secret_text'},
            ]

            def api(method, url, data=None, **kwargs):
                if 'credentials' in url:
                    return {'password': 'synthetic', 'username': 'synthetic', 'registry_host': 'registry.cloudflare.com'}
                if method == 'GET' and url.endswith('/subdomain'):
                    return {'enabled': True, 'previews_enabled': False}
                if method == 'GET' and url.endswith('/settings'):
                    return {'bindings': gateway_bindings if 'staging-sql' in url else []}
                if method == 'GET' and url.endswith('/content/v2'):
                    return old_gateway
                if method == 'GET' and '/containers/applications/' in url:
                    return {'configuration': {'image': 'old@' + DIGEST}}
                if method == 'GET':
                    return old_web
                if method == 'PUT':
                    message = release.email.parser.BytesParser(policy=release.email.policy.default).parsebytes(
                        ('Content-Type: ' + kwargs['content_type'] + '\r\n\r\n').encode() + data)
                    metadata = json.loads(next(message.iter_parts()).get_payload(decode=True))
                    writes.append((url, metadata, release.script_sources(data, kwargs['content_type'])))
                    if len(writes) == 2:
                        raise RuntimeError('simulated unknown web update outcome')
                    return {}
                raise AssertionError('Unexpected or database API call: ' + url)

            def output(args, **kwargs):
                return DIGEST if args[-1] == '{{.Id}}' else json.dumps([
                    'registry.cloudflare.com/' + release.ACCOUNT + '/familyfunds-d1-staging@' + DIGEST])

            companion = {**release.CONFIG['staging']['companions'][0], 'accepted_previous_sha256': hashlib.sha256(b'old gateway code').hexdigest()}
            target = {**release.CONFIG['staging'], 'companions': [companion]}
            with patch.dict(release.CONFIG, {'staging': target}), patch.dict(os.environ, {'GITHUB_SHA': SHA, 'GITHUB_REF': 'refs/heads/cloudflare-staging', 'GITHUB_ACTIONS': 'true', 'DOCKER_CONFIG': directory}), patch.object(release, 'validate'), patch.object(release, 'public_checks'), patch.object(release, 'rollout_image'), patch.object(release, 'api', side_effect=api), patch.object(release.subprocess, 'run'), patch.object(release.subprocess, 'check_output', side_effect=output), contextlib.redirect_stdout(io.StringIO()):
                with self.assertRaisesRegex(RuntimeError, 'previous code/image restored'):
                    release.publish('staging', path)
            self.assertEqual(len(writes), 4)
            self.assertTrue(writes[0][0].endswith('/familyfunds-d1-staging-sql/content'))
            self.assertEqual(writes[0][1], {'main_module': 'staging-worker.mjs'})
            self.assertTrue(writes[3][0].endswith('/familyfunds-d1-staging-sql/content'))
            self.assertEqual(writes[3][1], {'main_module': 'worker.mjs'})
            self.assertEqual(writes[3][2], {'worker.mjs': b'old gateway code'})
            self.assertEqual(writes[1][1]['containers'], [{'class_name': 'FamilyFundsWebStagingContainer'}])
            self.assertEqual(writes[2][1]['containers'], [{'class_name': 'FamilyFundsWebStagingContainer'}])
            self.assertIn({'name': 'STAGING_SQL_GATEWAY', 'type': 'service', 'service': 'familyfunds-d1-staging-sql'}, writes[1][1]['bindings'])
            self.assertFalse(any(binding['name'] == 'STAGING_SQL_GATEWAY' for binding in writes[2][1]['bindings']))

    def test_modified_original_staging_archive_is_refused_before_credentials_or_docker(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory)
            (path / 'rootfs.tar').write_bytes(b'inspected image')
            (path / 'original-image.tar').write_bytes(b'changed after inspection')
            (path / 'release.json').write_text(json.dumps({'target': 'staging', 'revision': SHA, 'unstarted_export': True,
                                                        'runtime_credentials_packaged': False, 'rootfs_sha256': hashlib.sha256(b'inspected image').hexdigest(),
                                                        'image_archive_sha256': hashlib.sha256(b'inspected original image').hexdigest()}))
            with patch.dict(os.environ, {'GITHUB_SHA': SHA, 'GITHUB_REF': 'refs/heads/cloudflare-staging', 'GITHUB_ACTIONS': 'true', 'DOCKER_CONFIG': directory}), patch.object(release, 'validate'), patch.object(release, 'api') as api, patch.object(release.subprocess, 'run') as run:
                with self.assertRaisesRegex(RuntimeError, 'archive checksum mismatch'):
                    release.publish('staging', path)
                api.assert_not_called()
                run.assert_not_called()

    def test_feature_branches_and_mismatched_artifacts_never_get_deploy_credentials(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory)
            (path / 'release.json').write_text(json.dumps({'revision': SHA, 'target': 'production'}))
            with patch.dict(os.environ, {'GITHUB_SHA': SHA, 'GITHUB_REF': 'refs/heads/feature'}), patch.object(release, 'validate'), patch.object(release, 'api') as api:
                with self.assertRaisesRegex(RuntimeError, 'configured branch'):
                    release.publish('production', path)
                api.assert_not_called()

    def test_worker_write_failure_restores_original_code_and_image_without_database_calls(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory)
            (path / 'rootfs.tar').write_bytes(b'inspected image')
            (path / 'release.json').write_text(json.dumps({'target': 'production', 'revision': SHA, 'unstarted_export': True,
                                                        'runtime_credentials_packaged': False, 'rootfs_sha256': hashlib.sha256(b'inspected image').hexdigest()}))
            target = {**release.CONFIG['production'], 'companions': []}
            settings = {'bindings': [{'name': 'CONTAINER_IMAGE', 'type': 'plain_text', 'text': 'old@' + DIGEST}]}
            old_raw, old_mime = release.multipart('production-managed-worker.mjs', {'production-managed-worker.mjs': b'old reviewed code'}, [], None)
            writes = []

            def api(method, url, data=None, **kwargs):
                if 'credentials' in url:
                    return {'password': 'synthetic', 'username': 'synthetic', 'registry_host': 'registry.cloudflare.com'}
                if url.endswith('/settings'):
                    return settings
                if method == 'GET':
                    return old_raw, old_mime
                if method == 'PUT':
                    writes.append(data)
                    if len(writes) == 1:
                        raise RuntimeError('simulated unknown update outcome')
                    return {}
                raise AssertionError('Unexpected or database API call: ' + url)

            def command(args, **kwargs):
                if args[:3] == ['docker', 'buildx', 'build']:
                    self.assertIn('oci-mediatypes=false', args[args.index('--output') + 1])
                    (path / 'pushed-image.json').write_text(json.dumps({'containerimage.digest': DIGEST}))

            with patch.dict(release.CONFIG, {'production': target}), patch.dict(os.environ, {'GITHUB_SHA': SHA, 'GITHUB_REF': 'refs/heads/main', 'GITHUB_ACTIONS': 'true', 'DOCKER_CONFIG': directory}), patch.object(release, 'validate'), patch.object(release, 'checked_bindings', return_value=settings['bindings']), patch.object(release, 'public_checks'), patch.object(release, 'prepared'), patch.object(release, 'api', side_effect=api), patch.object(release.subprocess, 'run', side_effect=command), contextlib.redirect_stdout(io.StringIO()):
                with self.assertRaisesRegex(RuntimeError, 'previous code/image restored'):
                    release.publish('production', path)
            self.assertEqual(len(writes), 2)
            self.assertIn(b'old reviewed code', writes[-1])
            self.assertIn(('old@' + DIGEST).encode(), writes[-1])


if __name__ == '__main__':
    unittest.main()
