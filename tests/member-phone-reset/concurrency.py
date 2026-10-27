"""Run the checked-in PHP against two actual local worker connections, then clean only owned fixtures."""
import json
import pathlib
import subprocess

source = pathlib.Path(__file__).with_suffix('.php').read_bytes()

def call(*args):
    result = subprocess.run(['docker', 'exec', '-i', 'mohe-app', 'php', '--', *map(str, args)], input=source, capture_output=True, timeout=20)
    result.check_returncode()
    return result.stdout.decode() + result.stderr.decode()

for mode, expected in [('commit', 'DENIED'), ('rollback', 'ALLOWED')]:
    fixture = json.loads(call('seed'))
    args = [fixture['uid'], fixture['old'], fixture['new']]
    try:
        writer = subprocess.Popen(['docker', 'exec', '-i', 'mohe-app', 'php', '--', mode, *map(str, args)], stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        writer.stdin.write(source)
        writer.stdin.close()
        assert writer.stdout.readline().decode().strip() == 'LOCKED'
        outcome = call('issue', *args)
        assert 'ISSUE_' + expected + '_AFTER_LOCK_PASS' in outcome, outcome
        writer.wait(timeout=20)
        assert 'WRITER_' + mode.upper() + '_PASS' in writer.stdout.read().decode()
        assert writer.returncode == 0
        print('PASS concurrent phone change ' + mode + ': token issuance waits for lock then ' + expected.lower())
    finally:
        assert 'OWNED_FIXTURE_CLEANUP_PASS' in call('cleanup', *args)
        print('PASS owned local concurrent fixture removed')
