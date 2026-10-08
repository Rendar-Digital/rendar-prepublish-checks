#!/usr/bin/env python3
"""Negative controls on a separate disposable scaffold; never mutate the live fixture."""
import importlib.util
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

source = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('guard', source / 'guard.py')
g = importlib.util.module_from_spec(spec)
spec.loader.exec_module(g)


class GuardTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        base = Path(self.tmp.name).resolve()
        self.sentinel = base / 'sentinel'
        self.sentinel.write_text('untouched')
        self.root = base / 'checkout'
        scripts = self.root / 'tests/integration'
        scripts.mkdir(parents=True)
        (self.root / 'rendar-prepublish-checks.php').write_text('<?php // fixture\n')
        for name in ('run.sh', 'guard.py'):
            (scripts / name).write_bytes((source / name).read_bytes())
        self.runtime = self.root / 'tests/.runtime'
        self.version = '6.6.2'
        subprocess.run(['git', '-C', str(self.root), 'init', '-q'], check=True)
        subprocess.run(['git', '-C', str(self.root), 'add', '.'], check=True)
        subprocess.run(['git', '-C', str(self.root), '-c', 'user.name=Guard Test', '-c', 'user.email=guard@example.invalid', 'commit', '-qm', 'fixture'], check=True)
        # Positive control: the identical guard CLI invoked by run.sh.
        self.runtime.mkdir()
        self.entry(True)

    def entry(self, allowed=False, reason=None):
        if allowed:
            # Positive baseline: the identical guard CLI invoked by run.sh.
            p = subprocess.run(['python3', str(self.root / 'tests/integration/guard.py'),
                                str(self.root), self.version], capture_output=True, text=True)
            self.assertEqual(p.returncode, 0, p.stderr)
            return
        p = subprocess.run(['bash', str(self.root / 'tests/integration/run.sh'), '--wp', self.version],
                           capture_output=True, text=True, timeout=10)
        self.assertNotEqual(p.returncode, 0)
        self.assertIn('[guard] refused: ' + reason, p.stderr)
        self.assertEqual(self.sentinel.read_text(), 'untouched')

    def test_paths(self):
        for name in ('evidence-' + self.version + '.json', 'wp-' + self.version):
            with self.subTest(name=name):
                path = self.runtime / name
                path.symlink_to(self.sentinel)
                try:
                    self.entry(reason='linked or non-directory fixture path')
                finally:
                    path.unlink()
        # Results are now per-version: guard must reject this actual destination.
        path = self.runtime / ('results-' + self.version + '.jsonl')
        path.symlink_to(self.sentinel)
        try:
            self.entry(reason='linked or non-directory fixture path')
        finally:
            path.unlink()
        self.runtime.rmdir()
        self.runtime.symlink_to(self.sentinel.parent, target_is_directory=True)
        self.entry(reason='linked or non-directory fixture path')

    def test_dirty_source_rejected_before_fixture_creation(self):
        (self.root / 'rendar-prepublish-checks.php').write_text('<?php // dirty fixture\n')
        self.entry(reason='source provenance: tracked shipping or integration harness file differs from HEAD')
        self.assertFalse((self.runtime / ('wp-' + self.version)).exists())

    def test_marker(self):
        wp = self.runtime / ('wp-' + self.version)
        wp.mkdir()
        self.entry(reason='unowned WordPress directory')
        (wp / '.rpc-fixture').write_text('wrong\n')
        self.entry(reason='unowned WordPress directory')
        (wp / '.rpc-fixture').write_text('rpc-disposable-fixture\n')
        self.entry(True)

    def test_urls(self):
        for url in ('https://127.0.0.1:50001', 'http://localhost:50001',
                    'http://127.0.0.1.evil:50001', 'http://127.0.0.1:50001/path'):
            with self.subTest(url=url), self.assertRaisesRegex(ValueError, 'not a task loopback URL'):
                g.guard(self.root, self.version, url)
        wp = self.runtime / ('wp-' + self.version)
        wp.mkdir()
        (wp / '.rpc-fixture').write_text('rpc-disposable-fixture\n')
        (wp / 'wp-config.php').write_text('<?php // fake fixture only\n')
        fakebin = self.root / 'fakebin'
        fakebin.mkdir()
        fakewp = fakebin / 'wp'
        fakewp.write_text('#!/bin/sh\nprintf "http://127.0.0.1:50001\\n"\n')
        fakewp.chmod(0o700)
        env = dict(os.environ, PATH=str(fakebin) + os.pathsep + os.environ['PATH'])
        for url, reason in [('http://127.0.0.1:50002', 'URL differs from fixture siteurl'),
                            ('https://127.0.0.1:50001', 'not a task loopback URL')]:
            p = subprocess.run(['python3', str(self.root / 'tests/integration/guard.py'),
                                str(self.root), self.version, url], env=env, capture_output=True, text=True)
            self.assertNotEqual(p.returncode, 0)
            self.assertIn('[guard] refused: ' + reason, p.stderr)
            self.assertEqual(self.sentinel.read_text(), 'untouched')
        p = subprocess.run(['python3', str(self.root / 'tests/integration/guard.py'),
                            str(self.root), self.version, 'http://127.0.0.1:50001'],
                           env=env, capture_output=True, text=True)
        self.assertEqual(p.returncode, 0, p.stderr)


if __name__ == '__main__':
    unittest.main()
