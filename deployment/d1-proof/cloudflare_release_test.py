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
        result = image.package_config({'Env': ['PHPIZE_DEPS=autoconf\tmake', r'EXAMPLE=literal\t']})
        self.assertIn('ENV PHPIZE_DEPS="autoconf\tmake"', result)
        self.assertIn(r'ENV EXAMPLE="literal\\t"', result)

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

    def test_schema_acceptance_is_independent_for_each_deployment(self):
        for accepted, pending in [('staging', 'production'), ('production', 'staging')]:
            with (
                self.subTest(accepted=accepted),
                patch.dict(release.CONFIG[accepted], {'accepted_migrations_sha256': 'a' * 64}),
                patch.object(release, 'migration_fingerprint', return_value='a' * 64),
            ):
                self.assertEqual(release.validate(accepted)['validated_targets'], [accepted])
                with self.assertRaisesRegex(RuntimeError, 'accepted D1 schema for ' + pending):
                    release.validate(pending)
                with self.assertRaisesRegex(RuntimeError, 'accepted D1 schema for ' + pending):
                    release.validate()

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
            old_maintenance_raw, old_maintenance_mime = release.multipart('maintenance-worker.mjs', {'maintenance-worker.mjs': b'old maintenance code'}, [], None)
            writes = []
            commands = []

            def api(method, url, data=None, **kwargs):
                if url.startswith('/zones/'):
                    return {'id': release.CONFIG['staging']['route_id'], 'pattern': 'd1-staging.familyfunds.app/*',
                            'script': 'familyfunds-d1-web-v2', 'request_limit_fail_open': False}
                if 'credentials' in url:
                    return {'password': 'synthetic', 'username': 'synthetic', 'registry_host': 'registry.cloudflare.com'}
                if url.endswith('/settings'):
                    return {'bindings': []}
                if method == 'GET' and '/containers/applications/' in url:
                    return {'configuration': {'image': 'old@' + DIGEST}}
                if method == 'GET':
                    if url.endswith('/familyfunds-d1-maintenance-staging'):
                        return old_maintenance_raw, old_maintenance_mime
                    return old_raw, old_mime
                if method == 'PUT':
                    message = release.email.parser.BytesParser(policy=release.email.policy.default).parsebytes(
                        ('Content-Type: ' + kwargs['content_type'] + '\r\n\r\n').encode() + data)
                    writes.append((url, json.loads(next(message.iter_parts()).get_payload(decode=True))))
                    if url.endswith('/familyfunds-d1-web-v2') and len([entry for entry in writes if entry[0] == url]) == 1:
                        raise RuntimeError('simulated unknown update outcome')
                    return {}
                raise AssertionError('Unexpected or database API call: ' + url)

            def command(args, **kwargs):
                commands.append(args)

            def output(args, **kwargs):
                return DIGEST if args[-1] == '{{.Id}}' else json.dumps([
                    'registry.cloudflare.com/' + release.ACCOUNT + '/familyfunds-d1-staging@' + DIGEST])

            with patch.dict(os.environ, {'GITHUB_SHA': SHA, 'GITHUB_REF': 'refs/heads/cloudflare-staging', 'GITHUB_ACTIONS': 'true', 'DOCKER_CONFIG': directory}), patch.object(release, 'validate'), patch.object(release, 'public_checks'), patch.object(release, 'prepared') as prepared, patch.object(release, 'rollout_image'), patch.object(release, 'api', side_effect=api), patch.object(release.subprocess, 'run', side_effect=command), patch.object(release.subprocess, 'check_output', side_effect=output), contextlib.redirect_stdout(io.StringIO()):
                with self.assertRaisesRegex(RuntimeError, 'previous code/image restored'):
                    release.publish('staging', path)
                prepared.assert_not_called()
            self.assertEqual([url.rsplit('/', 1)[-1] for url, _ in writes], [
                'familyfunds-d1-maintenance-staging', 'familyfunds-d1-web-v2',
                'familyfunds-d1-web-v2', 'familyfunds-d1-maintenance-staging',
            ])
            self.assertTrue(any(args[:3] == ['docker', 'image', 'load'] for args in commands))
            self.assertTrue(any(args[:2] == ['docker', 'push'] for args in commands))
            self.assertFalse(any('buildx' in args for args in commands))
            for url, metadata in writes:
                self.assertEqual(metadata.get('containers'), [{'class_name': 'FamilyFundsWebStagingContainer'}] if url.endswith('/familyfunds-d1-web-v2') else None)

    def test_staging_route_must_match_the_selected_destination(self):
        with patch.dict(release.CONFIG['staging'], {'destination': 'replacement'}):
            target = release.target_config('staging')
            route = {'id': target['route_id'], 'pattern': 'd1-staging.familyfunds.app/*',
                     'script': target['worker'], 'request_limit_fail_open': False}
            with patch.object(release, 'api', return_value=route):
                release.check_staging_route(target)
            for mismatch in [{'script': 'familyfunds-d1-web-v2'}, {'pattern': 'familyfunds.app/*'}, {'request_limit_fail_open': True}]:
                with patch.object(release, 'api', return_value=route | mismatch), self.assertRaisesRegex(RuntimeError, 'live staging route'):
                    release.check_staging_route(target)

    def test_replacement_publish_restores_both_previous_versions_after_uncertain_web_update(self):
        version = 'aacc1716-7924-4014-9c09-dc07c17fdd37'
        replacement = release.CONFIG['staging_destinations']['replacement']
        web = replacement['worker']
        maintenance = replacement['companions'][0]['worker']
        image = 'registry.cloudflare.com/' + release.ACCOUNT + '/' + replacement['image_repository'] + '@' + DIGEST
        mutations = []

        def api(method, path, data=None, **kwargs):
            if path.startswith('/zones/'):
                return {'id': release.CONFIG['staging']['route_id'], 'pattern': 'd1-staging.familyfunds.app/*',
                        'script': web, 'request_limit_fail_open': False}
            if path.endswith('/credentials'):
                return {'registry_host': 'registry.cloudflare.com', 'username': 'fixture', 'password': 'fixture'}
            if path.endswith('/deployments') and method == 'GET':
                return {'deployments': [{'created_on': '2026-11-03', 'versions': [{'version_id': version, 'percentage': 100}]}]}
            if path.endswith('/settings'):
                if maintenance in path:
                    return {'bindings': [{'name': 'REPLACEMENT_DB', 'type': 'd1', 'id': '43e11f90-07d7-49fc-930f-2a33a474d930'},
                                         {'name': 'STAGING_GATEWAY_ADMISSION_OBJECT', 'type': 'durable_object_namespace', 'namespace_id': '62fa75c5c7c9490eab127ae45d4c7213'},
                                         {'name': 'REPLACEMENT_WEB_MAINTENANCE', 'type': 'service', 'service': web, 'entrypoint': 'ReplacementMaintenance'}]}
                return {'bindings': [
                    {'name': 'REPLACEMENT_CONTAINER', 'type': 'durable_object_namespace', 'namespace_id': replacement['application_id']},
                    {'name': 'REPLACEMENT_SQL', 'type': 'service', 'service': 'familyfunds-d1-replacement-sql'},
                    {'name': 'REPLACEMENT_WEBHOOK_QUEUE', 'type': 'queue', 'queue_name': 'familyfunds-replacement-webhook'},
                    {'name': 'REPLACEMENT_FILES_CREDENTIAL', 'type': 'secrets_store_secret', 'store_id': '5e78cc1e865144e4ab982aeae254ed96', 'secret_name': 'FF_STAGING_REPLACEMENT_R2_20261103T092540Z'},
                    {'name': 'REPLACEMENT_APP_KEY', 'type': 'secret_text'}, {'name': 'REPLACEMENT_SQL_TOKEN', 'type': 'secret_text'},
                    {'name': 'BASIC_AUTHORIZATION', 'type': 'secret_text'},
                    *[{'name': k, 'type': 'plain_text', 'text': v} for k, v in {
                        'CONTAINER_ENV': '{}', 'STAGING_ADMISSION_CONTROL': '1', 'CONTAINER_IMAGE': image,
                        'RUNTIME_PROFILE': 'replacement-live'}.items()]]}
            mutations.append((method, path))
            if method == 'PUT':
                if path.endswith(web):
                    message = release.email.parser.BytesParser(policy=release.email.policy.default).parsebytes(
                        ('Content-Type: ' + kwargs['content_type'] + '\r\n\r\n').encode() + data)
                    meta = json.loads(next(message.iter_parts()).get_payload(decode=True))
                    self.assertEqual(meta['containers'][0]['images']['base'], image)
                    self.assertEqual(meta['keep_bindings'], ['secret_text'])
                    self.assertIn('RUNTIME_REVISION', {b['name'] for b in meta['bindings']})
                    raise RuntimeError('unknown web outcome')
                return {}
            if method == 'POST' and path.endswith('/deployments'):
                self.assertEqual(data['versions'], [{'version_id': version, 'percentage': 100}])
                return {}
            self.fail('Unexpected API call ' + path)

        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory)
            (path / 'rootfs.tar').write_bytes(b'inspected')
            (path / 'release.json').write_text(json.dumps({'target': 'staging', 'revision': SHA, 'unstarted_export': True,
                'runtime_credentials_packaged': False, 'rootfs_sha256': hashlib.sha256(b'inspected').hexdigest()}))

            def docker(args, **kwargs):
                if 'buildx' in args:
                    self.assertIn('--provenance=false', args)
                    (path / 'pushed-image.json').write_text(json.dumps({'containerimage.digest': DIGEST}))
                self.assertNotIn('load', args)

            with (patch.dict(release.CONFIG['staging'], {'destination': 'replacement'}),
                  patch.dict(os.environ, {'GITHUB_SHA': SHA, 'GITHUB_REF': 'refs/heads/cloudflare-staging', 'GITHUB_ACTIONS': 'true', 'DOCKER_CONFIG': directory}),
                  patch.object(release, 'validate'), patch.object(release, 'public_checks'), patch.object(release, 'prepared') as prepare,
                  patch.object(release, 'api', side_effect=api), patch.object(release.subprocess, 'run', side_effect=docker),
                  contextlib.redirect_stdout(io.StringIO()), self.assertRaisesRegex(RuntimeError, 'previous code/image restored')):
                release.publish('staging', path)
            prepare.assert_called_once_with(image)
            self.assertEqual(mutations, [(m, '/workers/scripts/' + worker) for m, worker in [
                ('PUT', maintenance), ('PUT', web), ('POST', web + '/deployments'), ('POST', maintenance + '/deployments')]])

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
