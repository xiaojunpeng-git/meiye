# coding: utf-8
"""010在验收后发布同文件默认业绩功能；在唯一Git正本对象中合并保留，生成需产品经理补验的固定候选。"""
from pathlib import Path
import subprocess,tempfile,os,json,hashlib
E=Path('tests/cashier-v3/evidence/R37-card-labor-followup');R=E/'release';p=json.loads((R/'trees.json').read_text());old=p['plans']['styled'];ref='31aafac2aa966cc4244dd021c472d1cd687dc2fc'
def g(*a,**kw):return subprocess.check_output(['git',*a],**kw)
def sha(b):return hashlib.sha256(b).hexdigest()
latest=json.loads((R/'010-latest-observed.json').read_text());backend=old['backendPaths'][0];assert sha(g('show',ref+':'+backend))==latest['backend'][backend.removeprefix('后端代码/')]
fd,idx=tempfile.mkstemp(prefix='r37-010-merge-');os.close(fd);os.unlink(idx);env=dict(os.environ,GIT_INDEX_FILE=idx)
try:
 g('read-tree',old['baselineTree'],env=env)
 for n in old['paths']:g('update-index','--add','--cacheinfo','100644',g('rev-parse',ref+':'+n).decode().strip(),n,env=env)
 baseline=g('write-tree',env=env).decode().strip();g('apply','--cached','--whitespace=nowarn','-',input=(E/'scope.patch').read_bytes(),env=env);candidate=g('write-tree',env=env).decode().strip();paths=g('diff','--name-only',baseline,candidate).decode().splitlines();assert sorted(paths)==sorted(old['paths']);g('diff','--check',baseline,candidate)
 item={'canonicalGit':str(Path.cwd()),'baselineTree':baseline,'candidateTree':candidate,'paths':paths,'backendPaths':old['backendPaths'],'sourceParents':[ref]+old['sourceParents'],'preserved':'保留31aafac2已上线默认业绩两文件完整内容，仅应用R37-00002显示补丁；保留R19/R68既有平台功能和010菜单CSS','pmReacceptanceRequired':True};p['plans']['merged010']=item;p['siteVariants']['010']='merged010';p['sourceR68RuntimeBaseline']='c3e77bce39723e307377d089a22fedacebcc2972';(R/'trees.json').write_text(json.dumps(p,ensure_ascii=False,indent=2));(R/'merged010').mkdir(exist_ok=True);(R/'merged010/trees.json').write_text(json.dumps(item,ensure_ascii=False,indent=2));(R/'010-original-before.json').write_bytes((R/'010-before.json').read_bytes());(R/'010-before.json').write_bytes((R/'010-latest-observed.json').read_bytes());(R/'010-candidate-preservation.json').write_text(json.dumps({'result':'PASS','existingSource':ref,'baseline':baseline,'candidate':candidate,'onlyDifference':paths,'scopePatchSHA256':sha((E/'scope.patch').read_bytes()),'candidateFileSHA256':{n:sha(g('show',candidate+':'+n))for n in paths},'status':'需补验，未发布'},ensure_ascii=False,indent=2));print('010 merged candidate',candidate)
finally:Path(idx).unlink(missing_ok=True)
