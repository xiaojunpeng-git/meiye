# coding: utf-8
"""只把R36两文件同一补丁应用到各站可追溯的已验收基线；既有报表差异原样保留，不夹带工作区改动。"""
import subprocess,json,hashlib,tempfile,os
from pathlib import Path
E=Path('tests/cashier-v3/evidence/R36-member-debt-visibility');R=E/'release'
def g(*a,**kw):return subprocess.check_output(['git',*a],**kw)
def sha(b):return hashlib.sha256(b).hexdigest()
f=json.loads((E/'fixed-files.json').read_text());assert all(sha(Path(p).read_bytes())==h for p,h in f['files'].items());assert sha((E/'scope.patch').read_bytes())==f['patchSHA256'];head=g('rev-parse','HEAD').decode().strip()
refs={'rh':'d1adcc61f2640f948e4a1a12f4e7c37e7a4844e4','008':'82e29bdd3ba29165d6933ed024d468ae3342496e','010':g('rev-parse','010/basic-report-menu^{tree}').decode().strip()}
expected={'rh':'5eeafc9750ab0594ee0e5f33fa6d8b044840c2bfb79a0b03a07ef78f6d8234fc','008':'74d5dc13c115c840516c8a08e88a0dcb91520efb8aea058649107a8dc2e0d900','010':'c8ae987eeb291a97c317559379e9c87f47af236c4db464c32b8200aa36790703'}
shared=Path(g('rev-parse','--git-path','index').decode().strip());indexSHA=sha(shared.read_bytes());plans={}
for site,ref in refs.items():
 live=json.loads((R/(site+'-before.json')).read_text());front=next(x for x in live['frontends']if x['kind']=='cashier'and x['root']==live['root']);assert front['sha256']==expected[site]
 fd,idx=tempfile.mkstemp(prefix='r36-index-');os.close(fd);os.unlink(idx);env=dict(os.environ,GIT_INDEX_FILE=idx)
 try:
  g('read-tree',head,env=env)
  for package in ['cashier-v3','shared','inventory-vue3']:
   prefix='前端代码/'+package
   # Remove only the private index subtree before importing the accepted Git objects.
   for p in g('ls-files','-z',prefix,env=env).split(b'\0'):
    if p:g('update-index','--force-remove',p.decode(),env=env)
   for row in g('ls-tree','-rz',ref,'--',prefix).split(b'\0'):
    if not row:continue
    meta,p=row.split(b'\t',1);mode,kind,blob=meta.split();assert kind==b'blob';g('update-index','--add','--cacheinfo',mode.decode(),blob.decode(),p.decode(),env=env)
  baseline=g('write-tree',env=env).decode().strip();g('apply','--cached','--whitespace=nowarn','-',input=(E/'scope.patch').read_bytes(),env=env);tree=g('write-tree',env=env).decode().strip();paths=g('diff','--name-only',baseline,tree).decode().splitlines();assert sorted(paths)==sorted(f['files']);g('diff','--check',baseline,tree)
  plans[site]={'canonicalGit':str(Path.cwd()),'head':head,'baselineTree':baseline,'candidateTree':tree,'liveAcceptedSourceTree':ref,'paths':paths,'candidateFiles':{p:sha(g('show',tree+':'+p))for p in paths},'preserved':'All other source blobs identical to this site accepted baseline; no report differences synchronized','userAcceptance':'same R36 two-file patch; pre-existing Shell edits excluded'}
  (R/site).mkdir(exist_ok=True);(R/site/'trees.json').write_text(json.dumps(plans[site],ensure_ascii=False,indent=2))
 finally:Path(idx).unlink(missing_ok=True)
assert len({json.dumps(p['candidateFiles'],sort_keys=True)for p in plans.values()})==1,'R36 business source must match on every site'
base=g('commit-tree',plans['rh']['baselineTree'],'-p',head,'-p',g('rev-parse','release/m012-reservation-shortcuts-20261026').decode().strip(),'-p',g('rev-parse','010/basic-report-menu').decode().strip(),input='R36 前置基线：追溯各站已上线收银版本，保留各站既有报表差异\n'.encode()).decode().strip()
(R/'trees.json').write_text(json.dumps({'canonicalGit':str(Path.cwd()),'head':head,'baselineCommit':base,'baselineTree':plans['rh']['baselineTree'],'candidateTree':plans['rh']['candidateTree'],'sites':plans,'paths':list(f['files']),'sharedIndexSHA256':indexSHA,'standardChange':'One R36 patch and identical two changed Vue files on all sites; immutable per-site builds retain already accepted report differences'},ensure_ascii=False,indent=2));assert sha(shared.read_bytes())==indexSHA;print('R36 precise patch and 3 accepted baseline plans fixed')
