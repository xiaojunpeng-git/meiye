# coding: utf-8
"""R37：以各站已上线、可追溯 Git 前端为基线，只应用已验收显隐补丁；共享索引保持原样。"""
import subprocess,json,hashlib,tempfile,os
from pathlib import Path
E=Path('tests/cashier-v3/evidence/R37-labor-fee-display');R=E/'release'
def g(*a,**kw):return subprocess.check_output(['git',*a],**kw)
def sha(b):return hashlib.sha256(b).hexdigest()
f=json.loads((E/'fixed-files.json').read_text());old=json.loads((E/'initial.json').read_text());prior=json.loads(Path('tests/cashier-v3/evidence/R20-claim-void-release-20261027/release-result.json').read_text());assert all(sha(Path(p).read_bytes())==h for p,h in f['files'].items());assert sha((E/'scope.patch').read_bytes())==f['patchSha256']
head=g('rev-parse','HEAD').decode().strip();index=Path(g('rev-parse','--git-path','index').decode().strip());indexSHA=sha(index.read_bytes());plans={}
for site in ['rh','008','010']:
 live=json.loads((R/(site+'-before.json')).read_text());accepted=prior['sites'][site]['candidateTree'];front=next(x for x in live['frontends']if x['root']==live['root']and x['kind']=='cashier');assert front['sha256']==prior['sites'][site]['indexSHA256']
 fd,idx=tempfile.mkstemp(prefix='r37-index-');os.close(fd);os.unlink(idx);env=dict(os.environ,GIT_INDEX_FILE=idx)
 try:
  g('read-tree',head,env=env)
  for package in ['cashier-v3','shared','inventory-vue3']:
   prefix='前端代码/'+package
   for p in g('ls-files','-z',prefix,env=env).split(b'\0'):
    if p:g('update-index','--force-remove',p.decode(),env=env)
   for row in g('ls-tree','-rz',accepted,'--',prefix).split(b'\0'):
    if not row:continue
    meta,p=row.split(b'\t',1);mode,kind,blob=meta.split();assert kind==b'blob';g('update-index','--add','--cacheinfo',mode.decode(),blob.decode(),p.decode(),env=env)
  for p,x in old['files'].items():
   if p.startswith('后端代码/'):
    assert live['backend'][p.removeprefix('后端代码/')]==x['sha256'];g('update-index','--add','--cacheinfo','100644',x['blob'],p,env=env)
  baseline=g('write-tree',env=env).decode().strip();g('apply','--cached','--whitespace=nowarn','-',input=(E/'scope.patch').read_bytes(),env=env)
  migration='后端代码/database/upgrades/2026-10-27-手工费算法显隐/01-正式升级.sql';blob=g('hash-object','-w','--stdin',input=Path(migration).read_bytes()).decode().strip();g('update-index','--add','--cacheinfo','100644',blob,migration,env=env)
  candidate=g('write-tree',env=env).decode().strip();paths=g('diff','--name-only',baseline,candidate).decode().splitlines();assert sorted(paths)==sorted(list(old['files'])+[migration]);g('diff','--check',baseline,candidate)
  plans[site]={'canonicalGit':str(Path.cwd()),'baselineTree':baseline,'candidateTree':candidate,'acceptedFrontendTree':accepted,'paths':paths,'backendPaths':[p for p in old['files']if p.startswith('后端代码/')],'migration':migration,'candidateFiles':{p:sha(g('show',candidate+':'+p))for p in paths},'preserved':'All other frontend blobs retain this site accepted R20 release; only R37 patch applied, existing report/claim differences retained'}
  (R/site).mkdir(exist_ok=True);(R/site/'trees.json').write_text(json.dumps(plans[site],ensure_ascii=False,indent=2))
 finally:Path(idx).unlink(missing_ok=True)
parents=['-p',head]
for ref in ['release/r20-claim-void-after-source-20261027','release/r19-store-display-20261027','release/r36-member-debt-visibility-20261026']:
 c=g('rev-parse',ref).decode().strip()
 if c!=head:parents+=['-p',c]
base=g('commit-tree',plans['rh']['baselineTree'],*parents,input='R37 前置基线：保留三站已上线门店端与现网显示配置后端\n'.encode()).decode().strip()
(R/'trees.json').write_text(json.dumps({'head':head,'baselineCommit':base,'sites':plans,'paths':plans['rh']['paths'],'sharedIndexSHA256':indexSHA,'acceptance':'2026-10-27 产品经理测试无问题；仅R37提交，瑞昊部署验证后push，再008、010'},ensure_ascii=False,indent=2));assert sha(index.read_bytes())==indexSHA;print('R37 canonical baseline and candidate trees prepared')
