"""Freeze only this change onto the last accepted member release; preserve shared HEAD/index."""
import hashlib
import json
import os
import pathlib
import subprocess

root = pathlib.Path(__file__).resolve().parents[2]
os.chdir(root)
d = pathlib.Path('tests/member-phone-reset/evidence')
baseline = json.loads((d / 'baseline.json').read_text())
parent = baseline['acceptedRelease']
env = os.environ.copy()
env['GIT_INDEX_FILE'] = '/private/tmp/member-phone-reset-fixed.index'

def git(*args):
    return subprocess.check_output(['git', *args], env=env).decode().strip()

assert git('rev-parse', 'HEAD') == baseline['head']
shared_index = pathlib.Path(subprocess.check_output(['git', 'rev-parse', '--git-path', 'index']).decode().strip())
assert hashlib.sha256(shared_index.read_bytes()).hexdigest() == baseline['indexSHA256']
git('read-tree', parent)
base = '后端代码/'
files = [base + f for f in [
    'app/services/BaseServices.php', 'app/services/user/CanonicalUserIdentityServices.php',
    'app/services/user/UserAuthServices.php', 'app/services/user/MemberPhoneResetServices.php',
    'app/services/wechat/WechatUserServices.php', 'app/services/wechat/RoutineServices.php',
    'app/services/wechat/WechatServices.php', 'mohe/utils/JwtAuth.php',
]] + [str(f) for f in sorted(pathlib.Path(base + 'database/upgrades/2026-10-28-会员改号撤销登录').glob('*.sql'))]
for f in files:
    data = pathlib.Path(f).read_bytes()
    if f.endswith('/RoutineServices.php'):
        # The shared file already had three unaccepted phone-gate changes. Keep
        # them in the shared worktree but package only this task's proof forwarding.
        accepted = subprocess.check_output(['git', 'show', parent + ':' + f]).decode()
        work = data.decode()
        old = "        $token = $this->createToken($uid, $userType, $user['pwd']);"
        start = work.index('        // proof ')
        end = work.index("        $token = $this->createToken($uid, $userType, $user['pwd'], $proof);", start)
        replacement = work[start:work.index(';', end) + 1]
        assert accepted.count(old) == 1
        data = accepted.replace(old, replacement).encode()
    blob = subprocess.check_output(['git', 'hash-object', '-w', '--stdin'], input=data).decode().strip()
    git('update-index', '--add', '--cacheinfo', '100644', blob, f)
tree = git('write-tree')
patch = subprocess.check_output(['git', 'diff', '--binary', parent, tree])
(d / 'fixed.patch').write_bytes(patch)
plan = {
    'head': baseline['head'], 'parent': parent, 'sourceTree': tree,
    'patchSHA256': hashlib.sha256(patch).hexdigest(),
    'files': {f: hashlib.sha256(subprocess.check_output(['git', 'show', tree + ':' + f])).hexdigest() for f in files},
    'tests': {str(f): hashlib.sha256(f.read_bytes()).hexdigest() for f in sorted(pathlib.Path('tests/member-phone-reset').iterdir()) if f.is_file()},
    'excludedPreexistingChanges': ['RoutineServices: three preexisting unaccepted mandatory-phone gate changes'],
    'remote': 'not applicable; no production connection or writes', 'acceptedByUser': False,
}
(d / 'fixed.json').write_text(json.dumps(plan, indent=2) + '\n')
assert hashlib.sha256(shared_index.read_bytes()).hexdigest() == baseline['indexSHA256']
print(json.dumps({'sourceTree': tree, 'patch': plan['patchSHA256'], 'businessPHP': 8, 'migrationSQL': 3, 'sharedIndexPreserved': True}))
