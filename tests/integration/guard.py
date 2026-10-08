#!/usr/bin/env python3
"""Refuse any writer/cleanup outside this checkout's disposable fixture."""
import ipaddress
from pathlib import Path
import re
import stat
import subprocess
import sys
from urllib.parse import urlsplit


def no_links(path):
    path = Path(path)
    for component in (path, *path.parents):
        try:
            mode = component.lstat().st_mode
        except FileNotFoundError:
            continue
        if stat.S_ISLNK(mode) or (component != path and not stat.S_ISDIR(mode)):
            raise ValueError('linked or non-directory fixture path')


def source_is_clean(root):
    shipping = ['rendar-prepublish-checks.php', 'README.md', 'CHANGELOG.md', 'inc', 'assets']
    harness = ['tests/integration']
    diff = subprocess.run(['git', '-C', str(root), 'diff', '--quiet', 'HEAD', '--', *shipping, *harness])
    if diff.returncode:
        raise ValueError('source provenance: tracked shipping or integration harness file differs from HEAD')
    untracked = subprocess.run(['git', '-C', str(root), 'ls-files', '--others', '--exclude-standard', '-z', '--', *shipping], capture_output=True)
    ignored = subprocess.run(['git', '-C', str(root), 'ls-files', '--others', '--ignored', '--exclude-standard', '-z', '--', *shipping], capture_output=True)
    if untracked.stdout or ignored.stdout:
        raise ValueError('source provenance: untracked shipping file')


def guard(root, version, url=None, check_source=False):
    root = Path(root)
    no_links(root)
    root = root.resolve(strict=True)
    if not (root / 'rendar-prepublish-checks.php').is_file() or not (root / 'tests/integration/guard.py').is_file():
        raise ValueError('not a plugin checkout')
    if check_source:
        source_is_clean(root)
    if not re.fullmatch(r'\d+\.\d+\.\d+', version):
        raise ValueError('invalid WP version')
    runtime = root / 'tests/.runtime'
    wpdir = runtime / ('wp-' + version)
    for path in (runtime, wpdir, wpdir / '.rpc-fixture', runtime / ('results-' + version + '.jsonl'), runtime / ('evidence-' + version + '.json'), runtime / 'http-code', runtime / 'server.log', runtime / 'server.pid'):
        no_links(path)
        if not path.resolve().is_relative_to(runtime.resolve()):
            raise ValueError('fixture path escaped scratch')
    if wpdir.exists():
        if not wpdir.is_dir():
            raise ValueError('unowned WordPress directory')
        marker = wpdir / '.rpc-fixture'
        if not marker.is_file() or marker.read_text() != 'rpc-disposable-fixture\n':
            raise ValueError('unowned WordPress directory')
    if url is not None:
        parsed = urlsplit(url)
        if parsed.scheme != 'http' or parsed.username or parsed.password or parsed.path not in ('', '/') or parsed.query or parsed.fragment or parsed.hostname != '127.0.0.1' or parsed.port is None or not 40000 <= parsed.port <= 59999 or not ipaddress.ip_address(parsed.hostname).is_loopback:
            raise ValueError('not a task loopback URL')
        # Once installed, bind requests to the actual site option, not an arbitrary port.
        if wpdir.exists() and (wpdir / 'wp-config.php').is_file():
            import subprocess
            actual = subprocess.run(['wp', '--path=' + str(wpdir), 'option', 'get', 'siteurl'], capture_output=True, text=True, timeout=15)
            if actual.returncode or actual.stdout.strip().rstrip('/') != url.rstrip('/'):
                raise ValueError('URL differs from fixture siteurl')


if __name__ == '__main__':
    try:
        args = sys.argv[1:]
        check_source = '--source-clean' in args
        if check_source:
            args.remove('--source-clean')
        guard(*args, check_source=check_source)
    except (ValueError, OSError, TypeError, IndexError) as exc:
        print('[guard] refused: ' + str(exc), file=sys.stderr)
        sys.exit(1)
