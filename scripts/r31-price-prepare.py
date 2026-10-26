"""Compose only accepted R31 patch over traceable deployed sources, using a private index."""
import subprocess,json,os,tempfile,hashlib
from pathlib import Path
E=Path('tests/cashier-v3/evidence/R31-sale-price-range');R=E/'release'
def git(*a,**kw):return subprocess.check_output(['git',*a],**kw)
def sha(b):return hashlib.sha256(b).hexdigest()
fixed=json.loads((E/'fixed-files.json').read_text());initial=json.loads((E/'initial.json').read_text())
for f,h in fixed['files'].items():assert sha(Path(f).read_bytes())==h,f
head=git('rev-parse','HEAD').decode().strip();index=Path(git('rev-parse','--git-path','index').decode().strip());ih=sha(index.read_bytes())
base=head
for ref in ['release/r51-basic-reports-20261023','release/r29-fund-summary-20261024','release/r30-third-party-008-20261024']:
 parent=git('rev-parse',ref).decode().strip();tree=git('merge-tree','--write-tree',base,parent).decode().strip();assert len(tree)==40
 base=git('commit-tree',tree,'-p',base,'-p',parent,input=('R31-BASE 保留既有已验收发布 '+ref+'\n').encode()).decode().strip()
for project in ['admin','cashier-v3','shared','inventory-vue3']:
 assert git('rev-parse',base+':前端代码/'+project)==git('rev-parse','45bd1d6231d76669e3449cd9dcd065ba3ce51246:前端代码/'+project),'published frontend mismatch '+project
for f in initial['files']:
 if not f.startswith('后端代码/'):continue
 h=sha(git('show',base+':'+f))
 for site in ['rh','008','010']:assert json.loads((R/(site+'-before.json')).read_text())['backend'][f.removeprefix('后端代码/')]==h,(site,f)
fd,idx=tempfile.mkstemp(prefix='r31-index-');os.close(fd);os.unlink(idx);env=dict(os.environ,GIT_INDEX_FILE=idx)
try:
 git('read-tree',base,env=env)
 git('apply','--cached','--whitespace=nowarn','-',input=(E/'round.patch').read_bytes(),env=env)
 new=[f for f in fixed['files'] if f not in initial['files']]
 files=new+[str(p)for p in E.iterdir()if p.is_file()]+['scripts/r31-price-prepare.py']
 for f in files:
  blob=git('hash-object','-w','--stdin',input=Path(f).read_bytes()).decode().strip();git('update-index','--add','--cacheinfo','100644',blob,f,env=env)
 tree=git('write-tree',env=env).decode().strip();paths=git('diff','--name-only',base,tree).decode().splitlines();assert set(paths)<=set(fixed['files'])|set(files)
 assert sha(index.read_bytes())==ih and git('rev-parse','HEAD').decode().strip()==head
 (R/'trees.json').write_text(json.dumps({'head':head,'baselineCommit':base,'baselineTree':git('rev-parse',base+'^{tree}').decode().strip(),'candidateTree':tree,'paths':paths,'publishedFrontendTree':'45bd1d6231d76669e3449cd9dcd065ba3ce51246','sharedIndexUntouched':True},ensure_ascii=False,indent=2)+'\n')
 print('CANDIDATE',tree)
finally:Path(idx).unlink(missing_ok=True)
