"""R12 release evidence and sequential instance guards; never mutate the shared Git index.

The read-only probe binds every result to the named independent database.  Source
hashes are comparison evidence only, never downloaded as editable business code.
"""
import concurrent.futures
import hashlib
import json
import pathlib
import io
import tarfile
import re
import urllib.parse
import subprocess
import sys

ROOT = pathlib.Path(__file__).resolve().parents[1]
E = ROOT.parent / '_local_evidence/2026-11-06-r12-data-permissions/release'
SITES = {
    'rh': ('rh-server', '/www/wwwroot/rh.cc3798.com', 'ruihao', 3306),
    '008': ('mohe-server', '/www/wwwroot/008.cc3798.com', 'mohe_008', 3307),
    '010': ('mohe-server', '/www/wwwroot/010.cc3798.com', 'mohe_010_app', 3307),
}

INIT = r'''require $root.'/vendor/autoload.php';
$app=new think\App($root.'/');$app->env->load($root.'/.env');$app->initialize();
set_exception_handler(static function($e){file_put_contents('php://stderr',$e->getMessage());exit(1);});
$cfg=think\facade\Db::connect()->getConfig();
if($cfg['database']!==$expected||(int)$cfg['hostport']!==$expectedPort)throw new Exception('Wrong independent instance');
'''


def save(name, value):
    (E / name).write_text(json.dumps(value, ensure_ascii=False, indent=2) + '\n')


def ssh(site, php):
    host, root, database, port = SITES[site]
    body = '<?php\n$root=' + json.dumps(root) + ';$expected=' + json.dumps(database) + ';$expectedPort=' + str(port) + ';\n' + INIT + php
    result = subprocess.run(['ssh', '-o', 'BatchMode=yes', host, '/www/server/php/74/bin/php'], input=body, text=True, capture_output=True, timeout=120)
    if result.returncode:
        raise RuntimeError(site + ': ' + result.stderr[-2000:] + result.stdout[-1000:])
    return json.loads(result.stdout)


def inspect(site):
    paths = json.loads((E / 'backend-paths.json').read_text())
    php = '$paths=json_decode(' + json.dumps(json.dumps(paths)) + ',true);' + r'''
$files=[];foreach($paths as $p)$files[$p]=is_file($root.'/'.$p)?hash_file('sha256',$root.'/'.$p):null;
$entries=[];foreach(['public/system.html','public/index.html','public/view_cashier_v3/index.html','public/view_mobile_v3/index.html','public/view_inventory_v3/index.html','public/view_fund_v3/index.html'] as $p){
 if(!is_file($root.'/'.$p))continue;
 preg_match_all('/(?:src|href)=["\x27]([^"\x27]+\.(?:js|css))(?:[?][^"\x27]*)?["\x27]/',file_get_contents($root.'/'.$p),$matches);
 $assets=[];foreach($matches[1] as $u){$asset='public/'.ltrim($u,'/');$assets[$asset]=is_file($root.'/'.$asset)?hash_file('sha256',$root.'/'.$asset):null;}
 $entries[$p]=['sha256'=>hash_file('sha256',$root.'/'.$p),'assets'=>$assets];
}
$graphs=[];foreach(['public/view_admin','public/view_mobile_v3'] as $dir){
 $graph=[];if(is_dir($root.'/'.$dir))foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir,FilesystemIterator::SKIP_DOTS)) as $f)if($f->isFile())$graph[substr($f->getPathname(),strlen($root)+1)]=hash_file('sha256',$f->getPathname());
 $graphs[$dir]=$graph;
}
$tables=think\facade\Db::query('SHOW TABLES');$tableNames=array_map(static function($x){return array_values($x)[0];},$tables);
$rules=[];foreach(['merchant_data_permission_catalog','merchant_data_permission_audit'] as $t){$full=$cfg['prefix'].$t;$rules[$t]=in_array($full,$tableNames,true)?['count'=>think\facade\Db::name($t)->count(),'schema'=>think\facade\Db::query('SHOW CREATE TABLE `'.$full.'`')[0]]:null;}
$migrations=array_values(array_filter($tableNames,static function($t){return preg_match('/upgrade|migration/',$t);}));
echo json_encode(['site'=>basename($root),'database'=>$expected,'port'=>(int)$cfg['hostport'],'envHash'=>hash_file('sha256',$root.'/.env'),'files'=>$files,'entries'=>$entries,'graphs'=>$graphs,'permissionSchema'=>$rules,'migrationTables'=>$migrations]);
'''
    value = ssh(site, php)
    return value


def probe(site):
    value = inspect(site)
    save(site + '-before.json', value)
    return {'site': site, 'database': value['database'], 'backend': len(value['files']), 'adminAssets': len(value['graphs']['public/view_admin']), 'mobileAssets': len(value['graphs']['public/view_mobile_v3']), 'permissionSchema': {k: v is not None for k, v in value['permissionSchema'].items()}}


def read(name):
    return json.loads((E / name).read_text())


def git(*args):
    return subprocess.check_output(['git', '-C', str(ROOT), *args])


def sha(body):
    return hashlib.sha256(body).hexdigest()


def package():
    plan = read('precise-candidate.json')
    tree = plan['candidateTree']
    payload = {}
    for p in plan['roundChangedPaths']:
        if p.startswith('后端代码/'):
            payload[p[len('后端代码/'):]] = git('show', tree + ':' + p)
    for kind, prefix in [('admin', 'public/'), ('mobile', 'public/view_mobile_v3/')]:
        build = read(kind + '-precise-build.json')
        package_path = '前端代码/' + ('admin' if kind == 'admin' else 'mobile-vue3')
        # Engineering controls may be added after compilation; every compiled business input must still be byte-identical.
        if git('rev-parse', tree + ':' + package_path) != git('rev-parse', build['tree'] + ':' + package_path):
            raise RuntimeError('Compiled frontend source drift')
        for p, fingerprint in build['assets'].items():
            # The instance's favicon is customer branding, not part of this permissions/report change.
            if kind == 'admin' and p == 'favicon.ico':
                continue
            body = (pathlib.Path(build['output']) / p).read_bytes()
            if sha(body) != fingerprint:
                raise RuntimeError('Build artifact changed')
            payload[prefix + p] = body
    manifest = {'tree': tree, 'files': {p: sha(b) for p, b in payload.items()}, 'entries': ['public/system.html', 'public/view_mobile_v3/index.html'], 'migration': 'database/upgrades/2026-11-06-商家端数据权限/02-正式升级.sql'}
    archive = E / ('payload-' + tree[:12] + '.tar.gz')
    with tarfile.open(archive, 'w:gz') as output:
        for p, body in [('manifest.json', json.dumps(manifest, ensure_ascii=False).encode())] + list(payload.items()):
            entry = tarfile.TarInfo(p); entry.size = len(body); entry.mode = 0o600
            output.addfile(entry, io.BytesIO(body))
    archive.chmod(0o600)
    save('payload.json', {'manifest': manifest, 'archive': str(archive), 'sha256': sha(archive.read_bytes())})
    # Capture exact current preimages for all incoming fixed-name assets as well as protected entries and backend dependencies.
    for site in SITES:
        before = read(site + '-before.json')
        current = inspect(site)
        if any(current[k] != before[k] for k in ['files', 'entries', 'envHash']):
            raise RuntimeError(site + ' live baseline changed during preparation')
        paths = json.dumps(list(payload))
        extra = ssh(site, '$paths=json_decode(' + json.dumps(paths) + ',true);$out=[];foreach($paths as $p)$out[$p]=is_file($root."/".$p)?hash_file("sha256",$root."/".$p):null;echo json_encode($out);')
        guard = {'envHash': before['envHash'], 'files': dict(before['files'])}
        for entry, data in before['entries'].items():
            guard['files'][entry] = data['sha256']; guard['files'].update(data['assets'])
        guard['files'].update(extra)
        collisions = [p for p, h in extra.items() if p.startswith('public/') and p not in manifest['entries'] and h is not None and h != manifest['files'][p]]
        if collisions:
            raise RuntimeError(site + ' immutable asset collisions: ' + ', '.join(collisions))
        save(site + '-guard.json', guard)
        origin = 'https://' + ('rh' if site == 'rh' else site) + '.cc3798.com'
        statuses = {}
        for path in ['/admin/login', '/view_mobile_v3/']:
            response = subprocess.check_output(['curl', '--compressed', '-sS', '--max-time', '45', '-o', '/dev/null', '-w', '%{http_code}', origin + path]).decode()
            if response not in ['200', '401']:
                raise RuntimeError(site + ' public entry unavailable: ' + response)
            statuses[path] = response
        save(site + '-public-before.json', statuses)
    print('PAYLOAD PASS', tree, len(payload), flush=True)


def deploy(site):
    commit = read('commit.json')
    plan = read('precise-candidate.json')
    if git('rev-parse', commit['commit'] + '^{tree}').decode().strip() != plan['candidateTree']:
        raise RuntimeError('Committed fixed tree mismatch')
    if site != 'rh':
        if read('rh-verified.json')['result'] != 'PASS' or read('push.json')['result'] != 'PASS':
            raise RuntimeError('RH verification and push must precede other instances')
        if site == '010' and (read('008-verified.json')['result'] != 'PASS' or read('008-public.json')['result'] != 'PASS'):
            raise RuntimeError('008 verification must precede 010')
    payload = read('payload.json'); archive = pathlib.Path(payload['archive'])
    if sha(archive.read_bytes()) != payload['sha256']:
        raise RuntimeError('Payload drift')
    host, root, _, _ = SITES[site]
    backup = '/www/backups/' + site + '/20261106-r12-management-' + commit['commit'][:12]
    subprocess.run(['ssh', '-o', 'BatchMode=yes', host, 'test ! -e ' + backup + ' && umask 077 && mkdir -p ' + backup], check=True)
    subprocess.run(['scp', '-q', str(archive), str(E / (site + '-guard.json')), host + ':' + backup + '/'], check=True)
    subprocess.run(['ssh', host, 'set -e; cd ' + backup + '; mv ' + site + '-guard.json guard.json; mkdir incoming; tar -xzf ' + archive.name + ' -C incoming'], check=True)
    control = (ROOT / 'scripts/r12-management-install.php').read_text()[5:]
    prefix = '$backup=' + json.dumps(backup) + ';$tree=' + json.dumps(plan['candidateTree']) + ';$commit=' + json.dumps(commit['commit']) + ';'
    invoke = lambda mode: ssh(site, prefix + '$mode=' + json.dumps(mode) + ';' + control)
    restart = '/etc/init.d/ruihao_swoole restart' if site == 'rh' else 'systemctl restart mohe-' + site + '-swoole.service && systemctl is-active --quiet mohe-' + site + '-swoole.service'
    started = False
    try:
        save(site + '-backup.json', invoke('backup')); started = True
        save(site + '-install.json', invoke('install'))
        result = subprocess.run(['ssh', host, restart], capture_output=True, text=True)
        (E / (site + '-restart.log')).write_text(result.stdout + result.stderr)
        if result.returncode:
            raise RuntimeError('Customer process restart failed')
        save(site + '-switch.json', invoke('switch'))
        verified = invoke('verify')
        save(site + '-business.json', invoke('business'))
        after = inspect(site); before = read(site + '-before.json')
        for p, entry in before['entries'].items():
            if p not in payload['manifest']['entries'] and after['entries'][p] != entry:
                raise RuntimeError('Unrelated frontend changed: ' + p)
        if after['envHash'] != before['envHash']:
            raise RuntimeError('Customer configuration changed')
        save(site + '-after.json', after)
        verified.update(commit=commit['commit'], backup=backup, businessWrites=0, permissionGrantWrites=0)
        save(site + '-verified.json', verified)
        print(site, 'DEPLOYED_AND_VERIFIED', flush=True)
    except Exception:
        if started:
            save(site + '-rollback.json', invoke('rollback'))
            subprocess.run(['ssh', host, restart], check=True)
        raise


def public(site):
    commit = read('commit.json'); plan = read('precise-candidate.json')
    if read(site + '-verified.json')['result'] != 'PASS':
        raise RuntimeError('Instance runtime verification required')
    manifest = read('payload.json')['manifest']
    origin = 'https://' + ('rh' if site == 'rh' else site) + '.cc3798.com'
    before = read(site + '-public-before.json'); checked = []
    for path in ['/admin/login', '/view_mobile_v3/']:
        raw = subprocess.check_output(['curl', '--compressed', '-sS', '--max-time', '45', '-w', '\n%{http_code}', origin + path])
        body, status = raw.rsplit(b'\n', 1)
        if status.decode() != before[path]:
            raise RuntimeError('Public protection/status changed')
        if status == b'401':
            continue  # Preserve the instance's Basic Auth; never bypass it or extract browser credentials.
        refs = re.findall(rb'(?:src|href)=["\x27]([^"\x27]+\.(?:js|css))(?:[?][^"\x27]*)?["\x27]', body)
        if not refs:
            raise RuntimeError('No public entry resources found')
        for ref in refs:
            url = urllib.parse.urljoin(origin + path, ref.decode())
            asset = 'public/' + urllib.parse.urlparse(url).path.lstrip('/')
            expected = manifest['files'].get(asset)
            if expected is None:
                raise RuntimeError('Public entry points outside candidate: ' + asset)
            data = subprocess.check_output(['curl', '--compressed', '-fsS', '--max-time', '45', url])
            if sha(data) != expected:
                raise RuntimeError('Public resource mismatch: ' + asset)
            checked.append(asset)
    backup = read(site + '-verified.json')['backup']
    code = (ROOT / 'scripts/r12-management-install.php').read_text()[5:]
    receipt = ssh(site, '$backup=' + json.dumps(backup) + ';$tree=' + json.dumps(plan['candidateTree']) + ';$commit=' + json.dumps(commit['commit']) + ';$mode="finalize";' + code)
    save(site + '-migration.json', receipt)
    save(site + '-public.json', {'result': 'PASS', 'commit': commit['commit'], 'checkedAssets': checked, 'publicProtectionPreserved': before, 'realReadOnlyBusiness': read(site + '-business.json')['result']})
    print(site, 'PUBLIC_AND_MIGRATION_PASS', len(checked), flush=True)


def push():
    commit = read('commit.json')
    if read('rh-verified.json')['result'] != 'PASS' or read('rh-public.json')['result'] != 'PASS':
        raise RuntimeError('RH live business and public verification required before push')
    ref = 'refs/heads/' + commit['branch']
    subprocess.run(['git', '-C', str(ROOT), 'push', 'origin', ref + ':' + ref], check=True)
    if git('ls-remote', 'origin', ref).decode().split()[0] != commit['commit']:
        raise RuntimeError('Remote ref mismatch')
    save('push.json', {'result': 'PASS', 'commit': commit['commit'], 'branch': commit['branch']})


if __name__ == '__main__':
    action = sys.argv[1]
    if action == 'probe':
        # Independent read-only inventories may run concurrently; writes remain sequential.
        with concurrent.futures.ThreadPoolExecutor(max_workers=3) as pool:
            for receipt in pool.map(probe, SITES):
                print(json.dumps(receipt, ensure_ascii=False), flush=True)
    elif action == 'package':
        package()
    elif action == 'deploy':
        deploy(sys.argv[2])
    elif action == 'push':
        push()
    elif action == 'public':
        public(sys.argv[2])
    else:
        raise ValueError('probe|package|deploy|push')
