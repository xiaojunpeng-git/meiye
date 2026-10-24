"""Prove the accepted live admin graph against a fresh canonical baseline build.

Webpack4 may change one pre-minification chunk filename while emitting identical
bytes. Only that verified name substitution is allowed in the runtime map/HTML;
no build artifact is edited or used as a business-source input.
"""
import gzip,hashlib,json,subprocess
from pathlib import Path
ROOT=Path(__file__).resolve().parent.parent;E=ROOT/'tests/store-permission-r28/evidence/release'
new=json.loads((E/'baseline-admin-build.json').read_text())
old=json.loads((ROOT/'../任务管理/进行中/美业大学成长管理/证据/发布/candidate-admin-build.json').read_text())
def sha(b):return hashlib.sha256(b).hexdigest()
if subprocess.check_output(['git','diff','--name-only',old['tree'],new['tree'],'--','前端代码/admin','前端代码/shared','前端代码/cashier-v3','前端代码/inventory-vue3'],cwd=ROOT):raise RuntimeError('Accepted baseline source differs')
for p,h in old['assets'].items():
 if sha((Path(old['output'])/p).read_bytes())!=h:raise RuntimeError('Historical evidence artifact drift')
old_chunk=next(p for p in old['assets']if p.startswith('view_admin/js/chunk-0f56252a.')and p.endswith('.js'))
new_chunk=next(p for p in new['assets']if p.startswith('view_admin/js/chunk-0f56252a.')and p.endswith('.js'))
old_app=next(p for p in old['assets']if p.startswith('view_admin/js/app.')and p.endswith('.js'))
new_app=next(p for p in new['assets']if p.startswith('view_admin/js/app.')and p.endswith('.js'))
old_bytes=(Path(old['output'])/old_chunk).read_bytes();new_bytes=(Path(new['output'])/new_chunk).read_bytes()
assert old_bytes==new_bytes
replace=lambda b:b.replace(old_chunk.split('.')[-2].encode(),new_chunk.split('.')[-2].encode())
assert replace((Path(old['output'])/old_app).read_bytes())==(Path(new['output'])/new_app).read_bytes()
assert (Path(old['output'])/'system.html').read_bytes().replace(old_app.encode(),new_app.encode())==(Path(new['output'])/'system.html').read_bytes()
old_hashes=set(old['assets'].values());exceptions={new_app,new_app+'.gz','system.html'}
for p,h in new['assets'].items():
 if p in exceptions:continue
 if h not in old_hashes:raise RuntimeError('Unexplained compiled content difference: '+p)
assert replace(gzip.decompress((Path(old['output'])/(old_app+'.gz')).read_bytes()))==gzip.decompress((Path(new['output'])/(new_app+'.gz')).read_bytes())
proof={'result':'PASS','acceptedTree':old['tree'],'rebuiltTree':new['tree'],'sourcePackagesIdentical':True,'identicalChunkBytes':sha(old_bytes),'allowedChunkFilenameChange':[old_chunk,new_chunk],'allowedRuntimeReferenceChange':[old_app,new_app],'liveHtmlSha256':old['assets']['system.html'],'liveAssets':old['assets'],'allOtherCompiledContent':'identical','artifactMutations':0}
(E/'admin-baseline-proof.json').write_text(json.dumps(proof,ensure_ascii=False,indent=2)+'\n');print('PASS exact source + compiled graph equivalence; only verified immutable filename/reference differences')
