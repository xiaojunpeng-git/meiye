"""Prepare R53's accepted six-file patch on each traced deployed baseline.

Only the canonical Git object database is used. Exported inputs are immutable
build inputs, never an editable worktree; the shared index and HEAD are untouched.
This preparation does not commit, deploy, change config, or migrate a database.
"""
import hashlib, io, json, os, pathlib, re, subprocess, sys, tarfile, tempfile

ROOT = pathlib.Path(__file__).resolve().parents[1]
E = ROOT.parent / '任务管理/第53轮-六维分析数值明细-20261107'
OUT = E / 'release'
BASES = {s:v['tree'] for s,v in json.loads((ROOT.parent/'任务管理/第51轮-人效看板院店分级-20261106/release/candidates.json').read_text())['sites'].items()}
FRONT = ['前端代码/cashier-v3', '前端代码/inventory-vue3', '前端代码/shared']
NODE = '/Users/xiaojunpeng/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin/node'

def git(*args, data=None, env=None):
    return subprocess.check_output(['git', *args], cwd=ROOT, input=data, env=env)

def sha(data): return hashlib.sha256(data).hexdigest()
def save(name, value): (OUT / name).write_text(json.dumps(value, ensure_ascii=False, indent=2) + '\n')

def freeze():
    OUT.mkdir(exist_ok=True)
    accepted = json.loads((E/'candidate.json').read_text())
    index_hash = sha((ROOT/'.git/index').read_bytes())
    patch = (E/'candidate.patch').read_bytes()
    assert sha(patch) == accepted['patchSha256']
    # Keep the current live shared report UI. Only the accepted R53 hunks are
    # applied, excluding other tasks' draft changes in the shared worktree.
    parts = patch.split(b'--- a/')
    ui = next(b'--- a/'+part for part in parts if part.startswith('前端代码/cashier-v3/src/views/StoreBusinessReportView.vue'.encode()))
    candidates = {}
    for site, base in BASES.items():
        env = dict(os.environ, GIT_INDEX_FILE=tempfile.mktemp(prefix='r53-detail-index-'))
        try:
            git('read-tree', base, env=env)
            git('apply','--cached','--whitespace=nowarn','-',data=ui,env=env)
            # The approved local table includes the refund display column. Its
            # unchanged source is part of the fixed accepted service file.
            for path, expected in accepted['files'].items():
                if path.startswith('前端代码/'): continue
                data = (ROOT/path).read_bytes()
                assert sha(data) == expected, ('ACCEPTED_CHANGED',path)
                blob=git('hash-object','-w','--stdin',data=data).decode().strip()
                git('update-index','--add','--cacheinfo','100644',blob,path,env=env)
            tree=git('write-tree',env=env).decode().strip()
        finally:
            pathlib.Path(env['GIT_INDEX_FILE']).unlink(missing_ok=True)
        changed=git('diff','--name-only','-z',base,tree).decode().split('\0')
        assert set(filter(None,changed))==set(accepted['files'])
        candidates[site]={'base':base,'tree':tree,'files':{p:sha(git('show',tree+':'+p)) for p in accepted['files']},'inputs':{}}
        for kind,ref in [('baseline',base),('candidate',tree)]:
            dest=pathlib.Path(tempfile.mkdtemp(prefix=f'r53-detail-{site}-{kind}-'))
            extra=['tests/cashier-v3/js','tests/report-phase-four','后端代码/app/services/report']
            with tarfile.open(fileobj=io.BytesIO(git('archive',ref,*FRONT,*extra))) as archive:
                archive.extractall(dest)
            hashes={}
            for f in dest.rglob('*'):
                if f.is_file():hashes[f.relative_to(dest).as_posix()]=sha(f.read_bytes());f.chmod(0o444)
            for project in ['cashier-v3','inventory-vue3']:
                (dest/'前端代码'/project/'node_modules').symlink_to(ROOT/'前端代码'/project/'node_modules',target_is_directory=True)
            candidates[site]['inputs'][kind]=str(dest)
            save(f'{site}-{kind}-source.json',hashes)
    assert index_hash==sha((ROOT/'.git/index').read_bytes()),'SHARED_INDEX_CHANGED'
    save('candidates.json',{'acceptedFingerprint':accepted['patchSha256'],'sites':candidates,'sharedHead':git('rev-parse','HEAD').decode().strip(),'sharedIndex':index_hash})
    print(json.dumps({s:c['tree'] for s,c in candidates.items()}))

def build(site, kind):
    candidate = json.loads((OUT/'candidates.json').read_text())['sites'][site]
    source = pathlib.Path(candidate['inputs'][kind])
    fingerprints = json.loads((OUT/f'{site}-{kind}-source.json').read_text())
    assert all(sha((source/p).read_bytes()) == h for p,h in fingerprints.items()), 'BUILD_INPUT_CHANGED'
    output = OUT/'build'/site/kind
    output.mkdir(parents=True, exist_ok=True)
    with (OUT/f'{site}-{kind}-build.log').open('w') as log:
        subprocess.run([NODE, str(source/'tests/cashier-v3/js/entitlement-experience-checkout-snapshot.mjs')], cwd=source, stdout=log, stderr=subprocess.STDOUT, check=True)
        subprocess.run([NODE, '--max-old-space-size=4096', str(ROOT/'前端代码/cashier-v3/node_modules/vite/bin/vite.js'), 'build', '--outDir', str(output), '--emptyOutDir'], cwd=source/'前端代码/cashier-v3', stdout=log, stderr=subprocess.STDOUT, check=True)
    assert all(sha((source/p).read_bytes()) == h for p,h in fingerprints.items()), 'BUILD_MUTATED_SOURCE'
    assets = {f.relative_to(output).as_posix():sha(f.read_bytes()) for f in output.rglob('*') if f.is_file()}
    save(f'{site}-{kind}-assets.json', assets)
    print(json.dumps({'site':site, 'kind':kind, 'files':len(assets), 'entry':(output/'index.html').read_text()}))

def probe(site):
    # Hash every reachable build resource and affected backend dependency; no
    # server source is downloaded or used as a new editable baseline.
    assets = json.loads((OUT/f'{site}-baseline-assets.json').read_text())
    deps = git('ls-tree','-r','--name-only',BASES[site],'后端代码/app/services/report','后端代码/app/services/query/metric').decode().splitlines()
    paths = [p.removeprefix('后端代码/') for p in deps if p.endswith('.php')]
    paths += ['public/view_cashier_v3/'+p for p in assets]
    paths += ['public/view_cashier_v3/index.html', 'app/services/report/SixDimensionAnalysisDetail.php', 'app/controller/admin/v1/report/UnifiedReport.php']
    root = '/www/wwwroot/'+site+'.cc3798.com'
    php = '<?php chdir('+json.dumps(root)+');$out=[];foreach('+json.dumps(paths)+" as $p)$out[$p]=is_file($p)?hash_file('sha256',$p):null;echo json_encode(['files'=>$out,'environment'=>hash_file('sha256','.env')]);"
    response = subprocess.run(['ssh', 'rh-server' if site=='rh' else 'mohe-server', 'php'],input=php,text=True,capture_output=True,check=True)
    result = json.loads(response.stdout)
    save(site+'-before.json', result)
    for p,h in assets.items(): assert result['files']['public/view_cashier_v3/'+p] == h, ('LIVE_FRONTEND_MISMATCH',site,p)
    checked = {}
    for p in deps:
        if not p.endswith('.php'): continue
        h = sha(git('show', BASES[site]+':'+p))
        checked[p] = result['files'][p.removeprefix('后端代码/')] == h
    save(site+'-dependency-comparison.json', checked)
    print(json.dumps({'site':site,'buildResourcesMatch':len(assets),'dependencyMatches':sum(checked.values()),'dependencyDifferences':[p for p,ok in checked.items() if not ok]}))

if __name__ == '__main__':
    if sys.argv[1]=='freeze': freeze()
    elif sys.argv[1]=='build': build(sys.argv[2],sys.argv[3])
    elif sys.argv[1]=='probe': probe(sys.argv[2])
