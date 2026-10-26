# coding: utf-8
"""以三站实际R34平台包和已验收Git子树建立基线，只应用R35固定补丁；不动共享HEAD/索引。"""
import subprocess,json,hashlib,tempfile,os
from pathlib import Path
E=Path('tests/organization-store-v3/evidence/R35-organization-edit');R=E/'release'
def g(*a,**kw):return subprocess.check_output(['git',*a],**kw)
def sha(b):return hashlib.sha256(b).hexdigest()
f=json.loads((E/'fixed-files.json').read_text());assert all(sha(Path(p).read_bytes())==h for p,h in f['files'].items());assert sha((E/'scope.patch').read_bytes())==f['patchSHA256'];old=json.loads((E/'initial.json').read_text())
head=g('rev-parse','HEAD').decode().strip();accepted=g('rev-parse','release/r34-store-delivery-defaults-20261026').decode().strip();prior=Path('tests/store-admin/evidence/R34-delivery-defaults/release');build=json.loads((prior/'candidate-admin-build.json').read_text());assert g('rev-parse',accepted+':前端代码/admin')==g('rev-parse',build['tree']+':前端代码/admin')
for s in ['rh','008','010']:
 live=json.loads((R/(s+'-before.json')).read_text());front=next(x for x in live['frontends']if x['kind']=='admin'and x['root']==live['root']);assert front['sha256']==build['assets']['system.html'];assert all(build['assets'][p.lstrip('/')]==h for p,h in front['assets'].items())
 for p in old['files']:
  if p.startswith('后端代码/'):
   assert live['backend'][p.removeprefix('后端代码/')]==old['files'][p]['sha256']==sha(g('show',head+':'+p)),p
 assert 'app/services/organization/OrganizationEditAccessServices.php' not in live['backend']
index=Path(g('rev-parse','--git-path','index').decode().strip());indexSHA=sha(index.read_bytes());fd,idx=tempfile.mkstemp(prefix='r35-index-');os.close(fd);os.unlink(idx);env=dict(os.environ,GIT_INDEX_FILE=idx)
try:
 g('read-tree',head,env=env)
 for row in g('ls-tree','-rz',accepted,'--','前端代码/admin').split(b'\0'):
  if not row:continue
  meta,p=row.split(b'\t',1);mode,kind,blob=meta.split();assert kind==b'blob';g('update-index','--add','--cacheinfo',mode.decode(),blob.decode(),p.decode(),env=env)
 baseline=g('write-tree',env=env).decode().strip();assert g('rev-parse',baseline+':前端代码/admin')==g('rev-parse',accepted+':前端代码/admin')
 base=g('commit-tree',baseline,'-p',head,'-p',accepted,input='R35 前置基线：保留三站已验收R34平台功能与当前源码提交链\n'.encode()).decode().strip()
 g('apply','--cached','--whitespace=nowarn','-',input=(E/'scope.patch').read_bytes(),env=env);tree=g('write-tree',env=env).decode().strip();paths=g('diff','--name-only',base,tree).decode().splitlines();assert sorted(paths)==sorted(f['files'])
 for p,h in f['files'].items():assert sha(g('show',tree+':'+p))==h
 assert sha(index.read_bytes())==indexSHA
 plan={'canonicalGit':str(Path.cwd()),'head':head,'baselineCommit':base,'baselineTree':baseline,'acceptedLiveAdminSource':accepted,'candidateTree':tree,'paths':paths,'backendPaths':[p for p in f['files'] if p.startswith('后端代码/')],'sharedIndexSHA256':indexSHA,'pmAccepted':True,'scope':'R35 organization edit capability only; no database migration/data or role writes','preserved':'Full accepted R34 platform subtree; all unrelated worktree edits excluded'}
 (R/'trees.json').write_text(json.dumps(plan,ensure_ascii=False,indent=2));(R/'candidate.diff').write_bytes(g('diff',base,tree));print(json.dumps(plan,ensure_ascii=False))
finally:Path(idx).unlink(missing_ok=True)
