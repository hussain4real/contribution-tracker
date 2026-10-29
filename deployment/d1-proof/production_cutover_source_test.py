"""Exercise scheduler inspection against an executable crontab fixture."""
import hashlib
import importlib.util
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

SPEC = importlib.util.spec_from_file_location('cutover_source', Path(__file__).with_name('production-cutover-source.py'))
SOURCE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(SOURCE)


class SourceFreezeTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.root = Path(self.directory.name)
        self.cron = self.root / 'installed-crontab'
        self.cron.write_text(SOURCE.CRON + '\n')
        program = self.root / 'crontab'
        program.write_text('''#!/usr/bin/env python3
import os,sys
from pathlib import Path
p=Path(os.environ['FF_TEST_CRONFILE'])
if sys.argv[1:]==['-u','deployer','-l']:
 sys.stdout.buffer.write(p.read_bytes())
elif sys.argv[1:]==['-u','deployer','-']:
 p.write_bytes(sys.stdin.buffer.read())
else:
 sys.exit(2)
''')
        program.chmod(0o700)
        environment = patch.dict(os.environ, {'FF_TEST_CRONFILE': str(self.cron), 'PATH': str(self.root) + os.pathsep + os.environ['PATH']})
        environment.start()
        self.addCleanup(environment.stop)

    def test_inspection_preserves_the_installed_scheduler(self):
        original = self.cron.read_bytes()
        self.assertEqual(SOURCE.read_scheduler(), original)
        self.assertEqual(self.cron.read_bytes(), original)

    def test_a_failed_scheduler_gate_leaves_source_configuration_unchanged(self):
        self.cron.write_text('different schedule\n')
        site = self.root / 'nginx.conf'
        site.write_text('original nginx fixture\n')
        original = site.read_bytes()
        destination = self.root / 'cutover-state'
        with patch.object(SOURCE, 'ROOT', destination), patch.object(SOURCE, 'SITE', site), patch.object(SOURCE, 'BASELINE', hashlib.sha256(original).hexdigest()):
            with self.assertRaisesRegex(RuntimeError, 'Unexpected production scheduler'):
                SOURCE.freeze()
        self.assertEqual(self.cron.read_text(), 'different schedule\n')
        self.assertEqual(site.read_bytes(), original)
        self.assertFalse(destination.exists())


if __name__ == '__main__':
    unittest.main()
