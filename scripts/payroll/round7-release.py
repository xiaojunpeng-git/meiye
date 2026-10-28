"""第七轮工资发布编排。只发布已验收Git提交，严格瑞昊验证→push→008→010，不上传小程序。"""
from pathlib import Path
import subprocess, json, hashlib, shlex, tarfile, io, sys, re
from datetime import datetime

ROOT = Path(__file__).resolve().parents[2]
E = ROOT / 'tests/customer-010/evidence/round7-salary-release'
SOURCE = 'e888c787e5d921284d18879167015d3431ce1a5c'
HOSTS = {'rh': 'rh-server', '008': 'mohe-server', '010': 'mohe-server'}


def run(args, **kwargs):
    return subprocess.check_output(args, cwd=ROOT, stderr=subprocess.PIPE, **kwargs)


def sha(data):
    return hashlib.sha256(data).hexdigest()


def read(name):
    return json.loads((E / name).read_text())


def write(name, data):
    (E / name).write_text(json.dumps(data, ensure_ascii=False, indent=2))


def ssh(site, command, data=None):
    return run(['ssh', '-q', '-o', 'BatchMode=yes', HOSTS[site], command], input=data, timeout=180)


def source_files():
    """验收文件逐项对应提交内blob；部署准备不得临时改写业务文件。"""
    accepted = json.loads((ROOT / 'tests/customer-010/evidence/round7-salary-edit-freeze/fixed-candidate.json').read_text())
    for path, digest in accepted['files'].items():
        assert sha(run(['git', 'show', SOURCE + ':' + path])) == digest, path
        assert sha((ROOT / path).read_bytes()) == digest, 'Working source drift: ' + path
    return accepted


def prepare():
    accepted = source_files()
    build = json.loads((ROOT / 'tests/customer-010/evidence/round7-salary-edit-freeze/build.json').read_text())
    output = Path('/tmp/beauty-round7-cashier-build')
    files = {p.removeprefix('后端代码/'): run(['git', 'show', SOURCE + ':' + p])
             for p in accepted['files'] if p.startswith('后端代码/') and '/database/' not in p}
    for path, digest in build['assets'].items():
        data = (output / path).read_bytes()
        assert sha(data) == digest
        files['public/view_cashier_v3/' + path] = data
    schema = run(['git', 'show', SOURCE + ':后端代码/database/upgrades/2026-10-27-工资人工项与编辑冻结/01-结构升级.sql'])
    # 重现旧包主资源，证明当前统一构建保留了现网上轮功能，而非从旧dist拼装新包。
    baseline = Path('/tmp/beauty-round7-baseline-output')
    for site in HOSTS:
        prior = read(site + '-preflight.json')
        for path, digest in prior['assets'].items():
            if path.startswith('public/view_cashier_v3/'):
                assert sha((baseline / path.removeprefix('public/view_cashier_v3/')).read_bytes()) == digest
        code = 'import pathlib,json,hashlib,sys;r=pathlib.Path("/www/wwwroot/' + site + '.cc3798.com");print(json.dumps({p:hashlib.sha256((r/p).read_bytes()).hexdigest() if (r/p).is_file() else None for p in json.load(sys.stdin)}))'
        live = json.loads(ssh(site, 'python3 -c ' + shlex.quote(code), json.dumps(list(files)).encode()))
        for path, digest in prior['files'].items():
            if path in live:
                assert live[path] == digest, 'Inspection baseline changed: ' + site + ':' + path
        changed = {p: data for p, data in files.items() if live[p] != sha(data)}
        manifest = {'site': site, 'source_commit': SOURCE, 'files': {p: sha(data) for p, data in changed.items()},
                    'before': {'files': {p: live[p] for p in changed}}, 'bounds': prior['bounds'], 'digests': prior['digests'],
                    'schema_sha256': sha(schema), 'mode': prior['mode'], 'position_model': prior['position_model'],
                    'preserved_entries': {p: h for p, h in prior['files'].items() if p.startswith('public/') and p != 'public/view_cashier_v3/index.html'}}
        write(site + '-manifest.json', manifest)
        package = E / (site + '-payload.tar.gz')
        with tarfile.open(package, 'w:gz') as archive:
            for name, data in [('manifest.json', json.dumps(manifest).encode()), ('schema.sql', schema)] + [('files/' + p, data) for p, data in changed.items()]:
                info = tarfile.TarInfo('payload/' + name)
                info.size = len(data)
                info.mode = 0o600
                archive.addfile(info, io.BytesIO(data))
        write(site + '-package.json', {'sha256': sha(package.read_bytes()), 'files': len(changed), 'source_commit': SOURCE})
        print(site, 'PREPARED', len(changed), flush=True)


def deploy(site):
    source_files()
    if site != 'rh':
        assert read('rh-verified.json')['result'] == 'PASS' and read('push.json')['result'] == 'PASS'
    if site == '010':
        assert read('008-verified.json')['result'] == 'PASS'
    manifest = read(site + '-manifest.json')
    package = E / (site + '-payload.tar.gz')
    assert sha(package.read_bytes()) == read(site + '-package.json')['sha256']
    backup = '/www/backups/' + site + '/round7-' + SOURCE[:12] + '-' + datetime.now().strftime('%Y%m%d%H%M%S')
    installer = ROOT / 'scripts/payroll/round7-release-install.php'
    ssh(site, 'umask 077; mkdir -p ' + shlex.quote(backup))
    run(['scp', '-q', '-O', str(package), str(installer), HOSTS[site] + ':' + backup + '/'])
    command = '/www/server/php/74/bin/php ' + shlex.quote(backup + '/round7-release-install.php') + ' ' + site + ' ' + shlex.quote(backup)
    restart = '/etc/init.d/ruihao_swoole restart' if site == 'rh' else 'systemctl restart mohe-' + site + '-swoole.service'
    health = "ss -lnt | grep -q ':20800 '" if site == 'rh' else 'systemctl is-active --quiet mohe-' + site + '-swoole.service'
    script = f'''set -euo pipefail
umask 077
cd {shlex.quote(backup)}
test "$(sha256sum {package.name} | cut -d' ' -f1)" = '{sha(package.read_bytes())}'
test "$(sha256sum round7-release-install.php | cut -d' ' -f1)" = '{sha(installer.read_bytes())}'
tar -xzf {package.name}
find payload/files -type f -name '*.php' -exec /www/server/php/74/bin/php -l {{}} \\;
{command} prepare > prepare.json
{command} parity > parity.json
rollback() {{ set +e; {command} rollback; {restart}; exit 1; }}
trap rollback ERR
{command} install > install.json
{restart}
{health}
{command} activate > activate.json
{command} verify > verify.json
trap - ERR
cat verify.json
'''
    write(site + '-started.json', {'backup': backup, 'source_commit': SOURCE, 'installer_sha256': sha(installer.read_bytes())})
    try:
        output = ssh(site, 'bash -s', script.encode())
        (E / (site + '-install.log')).write_bytes(output)
    except subprocess.CalledProcessError as error:
        (E / (site + '-install-error.log')).write_bytes((error.output or b'') + (error.stderr or b''))
        raise
    result = json.loads(ssh(site, command + ' verify'))
    assert result['result'] == 'PASS'
    parity = json.loads(ssh(site, 'cat ' + shlex.quote(backup + '/parity.json')))
    assert parity['result'] == 'PASS'
    write(site + '-parity.json', parity)
    for path, expected in manifest['preserved_entries'].items():
        command_hash = 'sha256sum ' + shlex.quote('/www/wwwroot/' + site + '.cc3798.com/' + path)
        assert ssh(site, command_hash).decode().split()[0] == expected, 'Unchanged platform/mobile entry drift'
    # 公网入口及引用资源逐项核验；010保留既有Basic Auth边界，不绕过保护。
    url = 'https://' + site + '.cc3798.com/view_cashier_v3/'
    body = run(['curl', '-sS', '--max-time', '30', '-w', '\n%{http_code}', url])
    html, status = body.rsplit(b'\n', 1)
    public_assets = []
    if status == b'200':
        for asset in re.findall(rb'(?:src|href)=["\x27]([^"\x27]+\.(?:js|css))["\x27]', html):
            path = 'public' + asset.decode()
            if path not in manifest['files']:
                continue
            data = run(['curl', '-fsS', '--max-time', '30', 'https://' + site + '.cc3798.com' + asset.decode()])
            assert sha(data) == manifest['files'][path]
            public_assets.append(path)
        assert len(public_assets) >= 2
    else:
        assert status == b'401', status
    if site == 'rh':
        for path in ['preview-8080/', 'store-v3/']:
            assert run(['curl', '-sS', '--max-time', '20', '-o', '/dev/null', '-w', '%{http_code}', 'https://rh.cc3798.com/' + path]) == b'410'
    result['backup'] = backup
    result['public_status'] = status.decode()
    result['public_assets_verified'] = public_assets
    write(site + '-verified.json', result)
    record = json.loads(ssh(site, command + ' record'))
    assert record['result'] == 'PASS'
    print(json.dumps(result, ensure_ascii=False), flush=True)


def push():
    assert read('rh-verified.json')['result'] == 'PASS'
    branch = run(['git', 'branch', '--show-current']).decode().strip()
    assert branch == '010/basic-report-menu'
    head = run(['git', 'rev-parse', 'HEAD']).decode().strip()
    run(['git', 'push', 'origin', 'HEAD:refs/heads/' + branch], timeout=180)
    remote = run(['git', 'ls-remote', 'origin', 'refs/heads/' + branch], timeout=60).decode().split()[0]
    assert remote == head
    write('push.json', {'result': 'PASS', 'commit': head, 'source_commit': SOURCE, 'branch': branch, 'remote': 'origin'})
    print('PUSH_PASS', head)


if __name__ == '__main__':
    if sys.argv[1] == 'prepare':
        prepare()
    elif sys.argv[1] == 'deploy' and sys.argv[2] in HOSTS:
        deploy(sys.argv[2])
    elif sys.argv[1] == 'push':
        push()
    else:
        raise RuntimeError('Explicit release phase required')
