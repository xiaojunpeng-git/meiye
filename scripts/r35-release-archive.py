# coding: utf-8
"""三站完成后归档本轮发布证据并推送；不修改共享索引/HEAD或业务源码。"""
import subprocess,json,hashlib,tempfile,os,datetime
from pathlib import Path
E=Path('tests/organization-store-v3/evidence/R35-organization-edit');R=E/'release'
def g(*a,**kw):return subprocess.check_output(['git',*a],**kw)
def sha(b):return hashlib.sha256(b).hexdigest()
info=json.loads((R/'commit.json').read_text());c=info['commit'];branch=info['branch'];results={s:json.loads((R/(s+'-verified.json')).read_text())for s in ['rh','008','010']};assert all(v['result']=='PASS'and v['commit']==c for v in results.values())
(R/'complete.json').write_text(json.dumps({'completedAtUTC':datetime.datetime.now(datetime.timezone.utc).isoformat(),'round':35,'sourceCommit':c,'result':'PASS','instances':results,'scope':'R35 organization edit functionality and scope; no database migration or production role/business data writes','existingFeatures':'R34 platform defaults preserved; prior can_write/other operations preserved; other terminals unchanged'},ensure_ascii=False,indent=2)+'\n')
(E/'发布结果.md').write_text('# 第35轮发布完成\n\n产品经理已验收固定版本。代码提交：`'+c+'`。瑞昊部署与只读业务/公网/浏览器验证通过后push，再依次完成008和010。\n\n组织编辑按已有功能权限和既有组织范围校验，保存复用结构锁、事务、幂等与审计；其他写操作原门禁保持不变。已上线第34轮默认配送配置及其他平台功能保留。三站独立文件备份与回滚点、部署前后指纹、只读业务验证及公网证据均见release目录。无迁移、无生产组织修改或账号角色授权写入。\n')
shared=Path(g('rev-parse','--git-path','index').decode().strip());indexSHA=sha(shared.read_bytes());head=g('rev-parse','HEAD');assert g('rev-parse','refs/heads/'+branch).decode().strip()==c
fd,idx=tempfile.mkstemp(prefix='r35-archive-');os.close(fd);os.unlink(idx);env=dict(os.environ,GIT_INDEX_FILE=idx)
try:
 g('read-tree',c,env=env)
 for p in sorted(p for p in E.rglob('*')if p.is_file()):
  blob=g('hash-object','-w','--stdin',input=p.read_bytes()).decode().strip();g('update-index','--add','--cacheinfo','100644',blob,str(p),env=env)
 tree=g('write-tree',env=env).decode().strip();paths=g('diff','--name-only',c,tree).decode().splitlines();assert all(p.startswith(str(E)+'/')for p in paths);assert g('rev-parse',tree+':前端代码/admin')==g('rev-parse',c+':前端代码/admin')
 archive=g('commit-tree',tree,'-p',c,input='R35-00001 三站组织编辑权限发布核验与回滚材料归档\n'.encode()).decode().strip();g('update-ref','refs/heads/'+branch,archive,c);assert g('rev-parse','HEAD')==head and sha(shared.read_bytes())==indexSHA
 g('push','origin','refs/heads/'+branch+':refs/heads/'+branch);assert g('ls-remote','origin','refs/heads/'+branch).decode().split()[0]==archive
 print(json.dumps({'source':c,'archive':archive,'branch':branch,'instances':['rh','008','010'],'result':'PASS'}))
finally:Path(idx).unlink(missing_ok=True)
