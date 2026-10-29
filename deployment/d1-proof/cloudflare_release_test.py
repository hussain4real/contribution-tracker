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
            (path / 'release.json').write_text(json.dumps({'target': 'staging', 'revision': SHA, 'unstarted_export': True,
                                                        'runtime_credentials_packaged': False, 'rootfs_sha256': hashlib.sha256(b'inspected image').hexdigest()}))
            old_raw, old_mime = release.multipart('container-worker.mjs', {'container-worker.mjs': b'old reviewed code'}, [], None)
            writes = []

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
                if args[:3] == ['docker', 'buildx', 'build']:
                    self.assertIn('oci-mediatypes=true', args[args.index('--output') + 1])
                    (path / 'pushed-image.json').write_text(json.dumps({'containerimage.digest': DIGEST}))

            with patch.dict(os.environ, {'GITHUB_SHA': SHA, 'GITHUB_REF': 'refs/heads/cloudflare-staging', 'GITHUB_ACTIONS': 'true', 'DOCKER_CONFIG': directory}), patch.object(release, 'validate'), patch.object(release, 'public_checks'), patch.object(release, 'prepared'), patch.object(release, 'rollout_image'), patch.object(release, 'api', side_effect=api), patch.object(release.subprocess, 'run', side_effect=command), contextlib.redirect_stdout(io.StringIO()):
                with self.assertRaisesRegex(RuntimeError, 'previous code/image restored'):
                    release.publish('staging', path)
            self.assertEqual(len(writes), 2)
            for metadata in writes:
                self.assertEqual(metadata['containers'], [{'class_name': 'FamilyFundsWebStagingContainer'}])

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
