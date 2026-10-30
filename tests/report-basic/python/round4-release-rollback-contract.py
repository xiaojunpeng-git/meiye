"""A lost apply receipt must still roll back the menu and independently restore files."""
from importlib.util import module_from_spec, spec_from_file_location
from pathlib import Path

source = Path(__file__).resolve().parents[3] / 'scripts/010-round4-release.py'
spec = spec_from_file_location('round4_release_recovery_contract', source)
release = module_from_spec(spec)
spec.loader.exec_module(release)

original = {name: getattr(release, name) for name in ('ssh', 'menu_command', 'restart', 'snapshot')}
before = {'backend': {}, 'front': {'index.html': 'old'}}


def exercise(menu_fails=False, file_fails=False):
    calls = []

    def ssh(site, command, data=None, timeout=90):
        if 'menu-backup.json' in command:
            calls.append('backup_presence')
            return b'present'
        calls.append('file_restore')
        if file_fails:
            raise RuntimeError('file channel interrupted')
        return b''

    def menu(site, backup, mode):
        assert mode == 'rollback'
        calls.append('menu_rollback')
        if menu_fails:
            raise RuntimeError('menu channel interrupted')

    def restart(site):
        calls.append('restart')

    def snapshot(site):
        calls.append('verify')
        return before

    release.ssh, release.menu_command = ssh, menu
    release.restart, release.snapshot = restart, snapshot
    try:
        errors = release.rollback_failed_switch('rh', '/backup', '/site', before)
    finally:
        for name, value in original.items():
            setattr(release, name, value)
    assert calls == ['backup_presence', 'menu_rollback', 'file_restore', 'restart', 'verify'], calls
    assert len(errors) == int(menu_fails) + int(file_fails), errors
    return errors


assert exercise() == []
errors = exercise(menu_fails=True, file_fails=True)
assert errors[0].startswith('menu rollback:') and errors[1].startswith('file rollback:')

# A stale PASS from another commit cannot authorize a new push.
original_read, original_run = release.read, release.run
release.read = lambda name: {'commit': 'new'} if name == 'commit.json' else {'result': 'PASS', 'commit': 'old'}
release.run = lambda *args, **kwargs: (_ for _ in ()).throw(RuntimeError('push must not run'))
try:
    try:
        release.push()
        raise RuntimeError('stale verification authorized push')
    except AssertionError:
        pass
finally:
    release.read, release.run = original_read, original_run
print('round4-release-rollback-contract PASS')
