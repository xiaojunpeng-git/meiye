"""Freeze accepted R51 changes on a traceable live baseline without touching the shared index."""
import hashlib, json, os, pathlib, subprocess, tempfile

ROOT = pathlib.Path(__file__).resolve().parents[2]
E = ROOT.parent / '任务管理/验收证据/R51-库存发布-2026-11-06'
def git(*args, data=None, env=None):
    return subprocess.check_output(['git', *args], cwd=ROOT, input=data, env=env)
def sha(data): return hashlib.sha256(data).hexdigest()

accepted = json.loads((E / 'accepted-files.json').read_text())
head = git('rev-parse', 'HEAD').decode().strip()
index = pathlib.Path(git('rev-parse', '--git-path', 'index').decode().strip())
if not index.is_absolute(): index = ROOT / index
index_before = sha(index.read_bytes())
fd, private = tempfile.mkstemp(prefix='r51-index-'); os.close(fd); os.unlink(private)
env = dict(os.environ, GIT_INDEX_FILE=private)
live_front = '64b2b61e9'
live_backend = '532e564d64edbe3ebb148412ef863a28d66db2ac'
services = ['后端代码/app/services/product/inventory/InventoryPlatformHq'+kind+'Services.php' for kind in ['Inbound','Outbound']]
def put(path, content):
    blob = git('hash-object','-w','--stdin', data=content).decode().strip()
    git('update-index','--add','--cacheinfo','100644',blob,path,env=env)

try:
    git('read-tree',head,env=env)
    # Preserve already-published staff controls and dashboard behavior from canonical commits.
    paths = git('diff','--name-only','-z',head,live_front,'--','前端代码/cashier-v3','前端代码/inventory-vue3','前端代码/shared').decode().split('\0')
    for path in filter(None,paths): put(path,git('show',live_front+':'+path))
    # These two live services predate a separate copy-detail change; it is not part of R51.
    for path in services: put(path,git('show',live_backend+':'+path))
    base = git('write-tree',env=env).decode().strip()
    for path, expected in accepted.items():
        content = (ROOT/path).read_bytes()
        if sha(content) != expected: raise RuntimeError('Accepted source changed: '+path)
        if path in services:
            # Apply only this round's hunks to the verified live canonical service.
            patch = git('diff','--binary',head,'--',path)
            git('apply','--cached','--unidiff-zero','-',data=patch,env=env)
        else: put(path,content)
    for path in ['scripts/release/r51-build-fixed.cjs','scripts/release/r51-inventory-preflight.py','scripts/release/r51-fix-candidate.py']:
        put(path,(ROOT/path).read_bytes())
    tree = git('write-tree',env=env).decode().strip()
    assert sha(index.read_bytes()) == index_before
    assert git('rev-parse','HEAD').decode().strip() == head
    files = {p:sha(git('show',tree+':'+p)) for p in accepted}
    result = dict(head=head,baseTree=base,tree=tree,acceptedFiles=files,liveFrontCommit=live_front,
                  liveBackendCommit=live_backend,sharedIndexUntouched=True,
                  baselineChanges=list(filter(None,paths))+services)
    (E/'candidate.json').write_text(json.dumps(result,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps(result,ensure_ascii=False,indent=2))
finally:
    if os.path.exists(private): os.unlink(private)
