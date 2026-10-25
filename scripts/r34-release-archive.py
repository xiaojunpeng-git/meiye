# coding: utf-8
"""归档R34三站独立核验结果，不改共享索引/HEAD；归档推送只发生在三站成功后。"""
import subprocess,json,hashlib,tempfile,os,datetime
from pathlib import Path
E=Path('tests/store-admin/evidence/R34-delivery-defaults');R=E/'release'
def g(*a,**kw):return subprocess.check_output(['git',*a],**kw)
def sha(b):return hashlib.sha256(b).hexdigest()
info=json.loads((R/'commit.json').read_text());c=info['commit'];branch=info['branch'];results={s:json.loads((R/(s+'-verified.json')).read_text()) for s in ['rh','008','010']};assert all(v['result']=='PASS' and v['commit']==c for v in results.values())
for s in results:
 assert json.loads((R/(s+'-public.json')).read_text())['result']=='PASS';assert all(v['result']=='PASS' for v in json.loads((R/(s+'-public-browser.json')).read_text()))
summary={'completedAtUTC':datetime.datetime.now(datetime.timezone.utc).isoformat(),'round':34,'sourceCommit':c,'result':'PASS','instances':results,'productionStoreOldValuesBackedUp':False,'userInstruction':'不用备份原值，直接改','scope':'Only admin defaults and undeleted-store delivery_type=2/default_delivery=2; no backend or financial facts changed','existingFunctions':'All other platform source identical to accepted live R62 baseline; all instance backend files, permissions, upgrades and other terminal entries unchanged','frontendRollback':'Per-instance manifests and file-only backup paths in verified receipts; no store-value rollback copy per user instruction'}
(R/'complete.json').write_text(json.dumps(summary,ensure_ascii=False,indent=2)+'\n')
(E/'发布结果.md').write_text('# 第34轮发布完成\n\n产品经理已验收。业务源码提交：`'+c+'`。瑞昊部署和公网资源/浏览器启动验证通过后已push，随后008、010依次部署与回读通过。\n\n'+ '\n'.join('- '+s+'：现有门店'+str(v['storeCount'])+'家，实际修正'+str(v['storesChanged'])+'家；均为到店自提／到店，缓存和详情回读通过。' for s,v in results.items())+'\n\n按产品经理最新指令未备份配送字段原值；仅平台文件保留逐站回滚材料。停用但未删除门店包含在范围内，已删除门店及其他资料列保持不变。门店端及其他终端入口、后端、迁移、权限规则保持不变。生成物只作为固定树构建结果，未加入源码；证据见release目录。\n')
shared=Path(g('rev-parse','--git-path','index').decode().strip());indexSHA=sha(shared.read_bytes());head=g('rev-parse','HEAD');assert g('rev-parse','refs/heads/'+branch).decode().strip()==c
fd,idx=tempfile.mkstemp(prefix='r34-archive-index-');os.close(fd);os.unlink(idx);env=dict(os.environ,GIT_INDEX_FILE=idx)
try:
 g('read-tree',c,env=env)
 files=[p for p in E.rglob('*') if p.is_file() and p.suffix not in ['.pyc']]+[Path('scripts/r34-release-archive.py')]
 for p in sorted(files):
  blob=g('hash-object','-w','--stdin',input=p.read_bytes()).decode().strip();g('update-index','--add','--cacheinfo','100644',blob,str(p),env=env)
 tree=g('write-tree',env=env).decode().strip();assert g('rev-parse',tree+':前端代码/admin')==g('rev-parse',c+':前端代码/admin');paths=g('diff','--name-only',c,tree).decode().splitlines();assert all(p.startswith(str(E)+'/') or p=='scripts/r34-release-archive.py' for p in paths)
 archive=g('commit-tree',tree,'-p',c,input='R34-00001 三站配送默认值发布与现有门店回读核验归档\n'.encode()).decode().strip();g('update-ref','refs/heads/'+branch,archive,c);assert g('rev-parse','HEAD')==head and sha(shared.read_bytes())==indexSHA
 g('push','origin','refs/heads/'+branch+':refs/heads/'+branch);assert g('ls-remote','origin','refs/heads/'+branch).decode().split()[0]==archive
 print(json.dumps({'source':c,'archive':archive,'branch':branch,'result':'PASS','stores':{s:{'verified':v['storeCount'],'changed':v['storesChanged']} for s,v in results.items()}},ensure_ascii=False))
finally:Path(idx).unlink(missing_ok=True)
