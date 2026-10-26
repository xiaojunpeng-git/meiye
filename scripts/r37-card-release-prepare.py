# coding: utf-8
"""R37补漏：平台现网基线=已验收R19平台+R68三文件；010保留已验收公共菜单样式。只应用两文件补丁。"""
import subprocess,json,hashlib,tempfile,os
from pathlib import Path
E=Path('tests/cashier-v3/evidence/R37-card-labor-followup');R=E/'release'
def g(*a,**kw):return subprocess.check_output(['git',*a],**kw)
def sha(b):return hashlib.sha256(b).hexdigest()
f=json.loads((E/'fixed-files.json').read_text());old=json.loads((E/'initial.json').read_text());assert all(sha(Path(n).read_bytes())==h for n,h in f['files'].items());assert sha((E/'scope.patch').read_bytes())==f['patchSha256'];head=g('rev-parse','HEAD').decode().strip();prior=g('rev-parse','release/r37-labor-fee-display-20261027').decode().strip();r19='8e1789ebc310f575d2b45d4dcc3e62f5bfbaa3d5';r68='eca9c914f1a00ddb2ccde1e41f416a2a40f0a6e7';style='79f0c95d';shared=Path(g('rev-parse','--git-path','index').decode().strip());indexSHA=sha(shared.read_bytes());plans={}
for variant,site in [('standard','rh'),('styled','010')]:
 live=json.loads((R/(site+'-before.json')).read_text());backend='后端代码/app/services/product/product/StoreProductServices.php';assert live['backend'][backend.removeprefix('后端代码/')]==old['files'][backend]['sha256']
 fd,idx=tempfile.mkstemp(prefix='r37-card-index-');os.close(fd);os.unlink(idx);env=dict(os.environ,GIT_INDEX_FILE=idx)
 try:
  g('read-tree',prior,env=env)
  g('update-index','--force-remove','-z','--stdin',input=g('ls-files','-z','前端代码/admin',env=env),env=env)
  entries=[]
  for row in g('ls-tree','-rz',r19,'--','前端代码/admin').split(b'\0'):
   if not row:continue
   meta,n=row.split(b'\t',1);mode,kind,blob=meta.split();assert kind==b'blob';entries.append(mode+b' '+blob+b'\t'+n+b'\n')
  g('update-index','--index-info',input=b''.join(entries),env=env)
  for n in ['前端代码/admin/src/components/linkaddress/index.vue','前端代码/admin/src/store/modules/admin/modules/fresh.js','前端代码/admin/src/store/modules/admin/modules/goodSelect.js']:
   g('update-index','--add','--cacheinfo','100644',g('rev-parse',r68+':'+n).decode().strip(),n,env=env)
  if variant=='styled':
   for n in ['前端代码/admin/public/index.html','前端代码/admin/public/basic-report-menu.css']:g('update-index','--add','--cacheinfo','100644',g('rev-parse',style+':'+n).decode().strip(),n,env=env)
  g('update-index','--add','--cacheinfo','100644',old['files'][backend]['blob'],backend,env=env);baseline=g('write-tree',env=env).decode().strip();g('apply','--cached','--whitespace=nowarn','-',input=(E/'scope.patch').read_bytes(),env=env);candidate=g('write-tree',env=env).decode().strip();paths=g('diff','--name-only',baseline,candidate).decode().splitlines();assert sorted(paths)==sorted(old['files']);g('diff','--check',baseline,candidate)
  plans[variant]={'canonicalGit':str(Path.cwd()),'baselineTree':baseline,'candidateTree':candidate,'paths':paths,'backendPaths':[backend],'sourceParents':[prior,r68,style],'preserved':'R19已验收平台完整子树+R68我的拼团链接配置；010保留已验收菜单CSS源码，不编辑生成物'};(R/variant).mkdir(exist_ok=True);(R/variant/'trees.json').write_text(json.dumps(plans[variant],ensure_ascii=False,indent=2))
 finally:Path(idx).unlink(missing_ok=True)
base=g('commit-tree',plans['standard']['baselineTree'],'-p',prior,'-p',r68,'-p',style,input='R37补漏前置基线：保留平台现网菜单、权限及我的拼团入口\n'.encode()).decode().strip();(R/'trees.json').write_text(json.dumps({'head':head,'baselineCommit':base,'plans':plans,'siteVariants':{'rh':'standard','008':'standard','010':'styled'},'paths':plans['standard']['paths'],'sharedIndexSHA256':indexSHA},ensure_ascii=False,indent=2));assert sha(shared.read_bytes())==indexSHA;print('R37 card follow-up baseline/candidate fixed')
