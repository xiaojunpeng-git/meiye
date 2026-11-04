"""只用正本Git的正式前端及本轮已验收补丁固定构建树；不切分支、不改共享索引。"""
from pathlib import Path
import subprocess,json,tempfile,os,hashlib
r=Path(__file__).resolve().parents[3]; e=r.parent/'任务管理/验收证据/R44-收银订单统一小票-2026-11-04'; rel=e/'release'
def git(*a,**kw):return subprocess.check_output(['git',*a],cwd=r,**kw)
f=json.loads((e/'fixed-version.json').read_text())
assert all(hashlib.sha256((r/p).read_bytes()).hexdigest()==h for p,h in f['files'].items()),'验收文件变化'
head=git('rev-parse','HEAD').decode().strip();live='876159633ca093c84ce69a6deff489404116ebe1'
with tempfile.TemporaryDirectory(prefix='r44-prepare-') as t:
 env={**os.environ,'GIT_INDEX_FILE':t+'/index'};git('read-tree',head,env=env)
 for prefix in ['前端代码/cashier-v3','前端代码/shared','前端代码/inventory-vue3']:
  for p in git('ls-files','-z',prefix,env=env).split(b'\0'):
   if p:git('update-index','--force-remove',p.decode(),env=env)
  for row in git('ls-tree','-rz',live,'--',prefix).split(b'\0'):
   if row:
    meta,p=row.split(b'\t');mode,kind,blob=meta.split();assert kind==b'blob'
    git('update-index','--add','--cacheinfo',mode.decode(),blob.decode(),p.decode(),env=env)
 baseline=git('write-tree',env=env).decode().strip();new='前端代码/cashier-v3/src/services/orderReceiptQuery.js'
 chunks=(e/'round44-only.patch').read_text().split('--- a/')[1:]
 patch=''.join('--- a/'+p for p in chunks if p.startswith('前端代码/') and not p.startswith(new+'\n'))
 git('apply','--cached','--whitespace=nowarn','-',input=patch.encode(),env=env)
 # 自动化测试采用本轮固定文件；新查询服务是本轮唯一新增生产源文件。
 for p in [new]+[p for p in f['files'] if p.startswith('tests/')]:
  git('update-index','--add','--cacheinfo','100644',git('hash-object','-w',str(r/p)).decode().strip(),p,env=env)
 candidate=git('write-tree',env=env).decode().strip();git('-c','core.whitespace=-blank-at-eof','diff','--check',baseline,candidate)
 x={'canonicalGit':str(r),'head':head,'publishedSource':live,'baselineTree':baseline,'candidateTree':candidate,'businessFiles':list(f['files']),'acceptance':'本轮页面已验收；隔离固定候选待补验','sourceHashes':{p:hashlib.sha256(git('show',candidate+':'+p)).hexdigest()for p in f['files']}}
 (rel/'trees.json').write_text(json.dumps(x,ensure_ascii=False,indent=2));print(json.dumps(x,ensure_ascii=False))
