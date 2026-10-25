# coding: utf-8
"""只将现网已验收平台子树纳入前置基线，再应用R34两文件补丁；共享索引与HEAD保持不变。"""
import subprocess,json,hashlib,tempfile,os
from pathlib import Path
E=Path('tests/store-admin/evidence/R34-delivery-defaults');R=E/'release'
def g(*a,**kw):return subprocess.check_output(['git',*a],**kw)
def sha(b):return hashlib.sha256(b).hexdigest()
f=json.loads((E/'fixed-files.json').read_text());assert all(sha(Path(p).read_bytes())==h for p,h in f['files'].items());assert sha((E/'scope.patch').read_bytes())==f['patchSHA256']
head=g('rev-parse','HEAD').decode().strip();accepted=g('rev-parse','release/r62-combination-20261025').decode().strip();live=json.loads((R/'rh-before.json').read_text());previous=json.loads(Path('tests/combination-r62/evidence/fixed-version.json').read_text())
front=next(x for x in live['frontends'] if x['kind']=='admin' and x['root']==live['root']);assert front['sha256']==previous['build']['entrySHA256'] and front['assets']==previous['build']['entryAssetsSHA256'];assert g('rev-parse',accepted+':前端代码/admin')==g('rev-parse',previous['candidateTree']+':前端代码/admin')
index=Path(g('rev-parse','--git-path','index').decode().strip());indexSHA=sha(index.read_bytes());fd,idx=tempfile.mkstemp(prefix='r34-index-');os.close(fd);os.unlink(idx);env=dict(os.environ,GIT_INDEX_FILE=idx)
try:
 g('read-tree',head,env=env)
 # Use Git objects, not a dirty checkout or server files, for every platform source blob.
 for line in g('ls-tree','-rz',accepted,'--','前端代码/admin').split(b'\0'):
  if not line:continue
  meta,p=line.split(b'\t',1);mode,kind,blob=meta.split();assert kind==b'blob';g('update-index','--add','--cacheinfo',mode.decode(),blob.decode(),p.decode(),env=env)
 baseline=g('write-tree',env=env).decode().strip();base=g('commit-tree',baseline,'-p',head,'-p',accepted,input='R34 前置基线：保留现网已验收R62平台功能\n'.encode()).decode().strip()
 g('apply','--cached','--whitespace=nowarn','-',input=(E/'scope.patch').read_bytes(),env=env);tree=g('write-tree',env=env).decode().strip();paths=g('diff','--name-only',base,tree).decode().splitlines();assert sorted(paths)==sorted(f['files']);assert sha(index.read_bytes())==indexSHA
 plan={'canonicalGit':str(Path.cwd()),'head':head,'baselineCommit':base,'baselineTree':baseline,'acceptedLiveSource':accepted,'candidateTree':tree,'paths':paths,'sharedIndexSHA256':indexSHA,'pmAccepted':True,'scope':'R34 defaults only; existing-store two-field update authorized, no old-value backup per latest instruction'}
 (R/'trees.json').write_text(json.dumps(plan,ensure_ascii=False,indent=2));(R/'candidate.diff').write_bytes(g('diff',base,tree));print(json.dumps(plan,ensure_ascii=False))
finally:Path(idx).unlink(missing_ok=True)
