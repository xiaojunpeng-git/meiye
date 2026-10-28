"""R41仅发布平台独立库存包；逐实例备份/CAS切换，保留门店包与所有服务端配置。"""
import base64, datetime, hashlib, importlib.util, io, json, os, pathlib, subprocess, sys, tarfile, tempfile, urllib.request
P = pathlib.Path
R = P('tests/inventory/evidence/R41-transfer-source-query/release')
spec = importlib.util.spec_from_file_location('r38', 'scripts/r38-release.py')
core = importlib.util.module_from_spec(spec); spec.loader.exec_module(core)
def read(n): return json.loads((R / n).read_text())
def save(n, value): (R / n).write_text(json.dumps(value, ensure_ascii=False, indent=2) + '\n')
def sha(value): return hashlib.sha256(value).hexdigest()
def git(*args, **kwargs): return subprocess.check_output(['git', *args], **kwargs)

def fixed():
    """验收文件、固定构建树与当前工作区一致；其他任务暂存区不参与提交。"""
    f = json.loads(P('tests/inventory/evidence/R41-transfer-source-query/fixed-version.json').read_text())
    assert all(sha(P(n).read_bytes()) == h for n, h in f['files'].items()), 'ACCEPTED_FILES_CHANGED'
    t = read('trees.json'); b = read('candidate-inventory-vue3-build.json')
    assert b['result'] == 'PASS' and b['sourceStable'] and b['tree'] == t['candidateTree']
    return t, b

def baseline(site):
    """发布前复查实例状态；只对照受影响库存包，门店端包按现网原样保留。"""
    assert site in ['rh', '008', '010']
    fixed()
    assert core.probe(site) == read(site + '-before.json'), 'LIVE_BASELINE_CHANGED'
    inv = core.inventory(site)
    assert inv == read(site + '-inventory-before.json'), 'INVENTORY_BASELINE_CHANGED'
    b = read('baseline-inventory-vue3-build.json')
    assert b['result'] == 'PASS' and b['sourceStable']
    assert all(inv.get(n) == h for n, h in b['assets'].items()), 'UNREPRODUCED_INVENTORY_BASELINE'

def commit():
    t, _ = fixed()
    for site in ['rh', '008', '010']: baseline(site)
    head = git('rev-parse', 'HEAD').decode().strip()
    # 共享HEAD若仅新增不参与库存构建的文件，保留新提交；库存及共享依赖变化必须重新固定验收。
    if head != t['head']:
        git('merge-base', '--is-ancestor', t['head'], head)
        assert not git('diff', '--name-only', t['head'], head, '--', '前端代码/inventory-vue3', '前端代码/shared'), 'INVENTORY_DEPENDENCY_CHANGED'
    branch = git('symbolic-ref', 'HEAD').decode().strip()
    index = P(git('rev-parse', '--git-path', 'index').decode().strip()); before = sha(index.read_bytes())
    fd, name = tempfile.mkstemp(prefix='r41-index-'); os.close(fd); os.unlink(name)
    env = dict(os.environ, GIT_INDEX_FILE=name)
    try:
        git('read-tree', head, env=env)
        for n in t['paths']:
            git('update-index', '--add', '--cacheinfo', '100644', git('rev-parse', t['candidateTree'] + ':' + n).decode().strip(), n, env=env)
        extra = ['scripts/r41-release.py', 'scripts/r41-release-build.cjs', 'scripts/r41-release-install.php',
                 'tests/inventory/evidence/R41-transfer-source-query/initial.json',
                 'tests/inventory/evidence/R41-transfer-source-query/fixed-version.json']
        for n in extra:
            git('update-index', '--add', '--cacheinfo', '100644', git('hash-object', '-w', n).decode().strip(), n, env=env)
        tree = git('write-tree', env=env).decode().strip()
        paths = git('diff', '--name-only', head, tree).decode().splitlines()
        assert set(paths) == set(t['paths'] + extra)
        git('diff', '--check', head, tree)
        c = git('commit-tree', tree, '-p', head, input='R41-00001 修复平台调拨草稿查询仓库不同步\n\n只修复调出仓查询上下文；产品经理已测试确认并授权瑞昊、push、008、010按序发布。无数据库迁移或写入。\n'.encode()).decode().strip()
        git('update-ref', branch, c, head)
        assert sha(index.read_bytes()) == before
        save('commit.json', {'commit': c, 'branch': branch, 'tree': tree, 'sourceTree': t['candidateTree'],
                             'paths': paths, 'sharedIndexUntouched': True, 'parent': head})
        print('COMMIT', c, flush=True)
    finally: P(name).unlink(missing_ok=True)

def deploy(site):
    """资源先于HTML发布；任何校验失败仅回滚本次清单，绝不重启或改写业务库。"""
    if site == '008': assert read('rh-verified.json')['result'] == 'PASS' and read('push.json')['result'] == 'PASS'
    if site == '010': assert read('008-verified.json')['result'] == 'PASS'
    baseline(site)
    c = read('commit.json'); _, b = fixed(); root = read(site + '-before.json')['root']
    assert git('rev-parse', 'HEAD').decode().strip() == c['commit'], 'SOURCE_BASELINE_CHANGED'
    inv = read(site + '-inventory-before.json'); files = {}
    for n, h in b['assets'].items():
        data = (P(b['output']) / n).read_bytes(); assert sha(data) == h
        if inv.get(n) != h: files['public/view_inventory_v3/' + n] = data
    targets = dict(sorted({n: {'before': inv.get(n.removeprefix('public/view_inventory_v3/')), 'after': sha(data)}
                           for n, data in files.items()}.items(), key=lambda x: x[0].endswith('/index.html')))
    d = '/www/backups/' + site + '/r41-transfer-query-' + c['commit'][:8]
    manifest = {'site': site, 'commit': c['commit'], 'sourceTree': c['sourceTree'], 'targets': targets, 'backup': d, 'noDBWrites': True}
    payload = io.BytesIO()
    with tarfile.open(fileobj=payload, mode='w:gz') as tar:
        for n, data in [('payload/' + n, v) for n, v in files.items()] + [('payload/manifest.json', json.dumps(manifest).encode()), ('install.php', P('scripts/r41-release-install.php').read_bytes())]:
            info = tarfile.TarInfo(n); info.mode = 0o600; info.size = len(data); tar.addfile(info, io.BytesIO(data))
    core.ssh(site, 'test ! -e ' + d + ' && mkdir -p -m 700 ' + d)
    core.ssh(site, 'tar -xzf - -C ' + d, payload.getvalue(), 120)
    baseline(site); save(site + '-manifest.json', manifest)
    try:
        (R / (site + '-install.log')).write_bytes(core.ssh(site, '/www/server/php/74/bin/php ' + d + '/install.php ' + d + ' install'))
        after = core.probe(site); expected = read(site + '-before.json')
        expected['protected']['public/view_inventory_v3/index.html'] = b['assets']['index.html']
        assert after == expected, 'OTHER_CODE_OR_CONFIGURATION_CHANGED'
        expectedInv = dict(inv); expectedInv.update(b['assets'])
        actual = core.inventory(site); assert actual == expectedInv, 'UNEXPECTED_INVENTORY_FILES'
        checks = []
        for n, x in targets.items():
            data = urllib.request.urlopen('https://' + site + '.cc3798.com/' + n.removeprefix('public/'), timeout=30).read()
            assert sha(data) == x['after']; checks.append(n)
        save(site + '-after.json', after); save(site + '-inventory-after.json', actual)
        save(site + '-public.json', {'result': 'PASS', 'paths': checks, 'cashierBackendConfigUnchanged': True})
        print('DEPLOY/PUBLIC PASS', site, flush=True)
    except BaseException:
        core.ssh(site, '/www/server/php/74/bin/php ' + d + '/install.php ' + d + ' rollback')
        raise

def finish(site):
    assert read(site + '-public.json')['result'] == 'PASS' and read(site + '-browser.json')['result'] == 'PASS'
    assert core.probe(site) == read(site + '-after.json') and core.inventory(site) == read(site + '-inventory-after.json'), 'LIVE_CHANGED'
    save(site + '-verified.json', {'result': 'PASS', 'commit': read('commit.json')['commit'],
                                  'rollback': read(site + '-manifest.json')['backup'], 'databaseWrites': 0,
                                  'timeUTC': datetime.datetime.now(datetime.timezone.utc).isoformat()})
    print('VERIFIED', site, flush=True)

def push():
    c = read('commit.json'); assert read('rh-verified.json')['result'] == 'PASS'
    assert git('rev-parse', c['branch']).decode().strip() == c['commit']
    subprocess.check_call(['git', 'push', 'origin', c['branch'] + ':' + c['branch']])
    assert git('ls-remote', 'origin', c['branch']).decode().split()[0] == c['commit']
    save('push.json', {'result': 'PASS', 'commit': c['commit'], 'force': False})
    print('PUSH PASS', flush=True)

if __name__ == '__main__':
    mode = sys.argv[1]
    if mode == 'commit': commit()
    elif mode == 'deploy': deploy(sys.argv[2])
    elif mode == 'finish': finish(sys.argv[2])
    elif mode == 'push': push()
    else: raise RuntimeError('EXPLICIT_MODE_REQUIRED')
