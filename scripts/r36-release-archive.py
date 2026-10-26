# coding: utf-8
"""归档R36三站文件验证及010单配置修复；不修改共享索引/HEAD或业务代码。"""
import subprocess,json,hashlib,tempfile,os,datetime
from pathlib import Path
E=Path('tests/cashier-v3/evidence/R36-member-debt-visibility');R=E/'release'
def g(*a,**kw):return subprocess.check_output(['git',*a],**kw)
def sha(b):return hashlib.sha256(b).hexdigest()
info=json.loads((R/'commit.json').read_text());c=info['commit'];branch=info['branch'];results={s:json.loads((R/(s+'-verified.json')).read_text())for s in ['rh','008','010']};assert all(v['result']=='PASS'and v['commit']==c for v in results.values());repair=json.loads((E/'010-custom-card/verify.json').read_text());assert repair['result']=='PASS'
(R/'complete.json').write_text(json.dumps({'round':36,'sourceCommit':c,'completedAtUTC':datetime.datetime.now(datetime.timezone.utc).isoformat(),'instances':results,'customCard010':repair,'result':'PASS'},ensure_ascii=False,indent=2)+'\n')
(E/'发布结果.md').write_text('# 第36轮发布完成\n\n代码提交：`'+c+'`。已按瑞昊部署/验证→push→008部署/验证→010部署/验证顺序完成。欠款功能关闭时，会员摘要隐藏总欠款与该区域还款入口；开启时保持正常显示。三站采用同一两文件补丁，保留原有已验收报表差异，未夹带Shell其他未验收修改。三站原平台、后端和其他终端指纹保持不变。\n\n010另已获授权补齐一条缺失的定制卡配置，插入ID835、value=1；其他配置未变，仅刷新单键缓存。数据库/目录回读与真实浏览器均确认定制卡入口恢复。首次插入后缓存步骤中断，已在确认行及其他配置摘要后仅恢复缓存步骤，没有重复插入。\n\n逐站回滚文件目录、构建树与源码/主资源SHA、基线可重复构建及公网/浏览器证据均见release目录；010配置补齐备份和回滚范围见010-custom-card。没有修改订单、金额或收银事实。生成包在不可编辑构建输出中，未当作源码提交。\n')
shared=Path(g('rev-parse','--git-path','index').decode().strip());before=sha(shared.read_bytes());head=g('rev-parse','HEAD');assert g('rev-parse','refs/heads/'+branch).decode().strip()==c
fd,idx=tempfile.mkstemp(prefix='r36-archive-');os.close(fd);os.unlink(idx);env=dict(os.environ,GIT_INDEX_FILE=idx)
try:
 g('read-tree',c,env=env)
 for p in sorted(x for x in E.rglob('*')if x.is_file()):
  b=g('hash-object','-w','--stdin',input=p.read_bytes()).decode().strip();g('update-index','--add','--cacheinfo','100644',b,str(p),env=env)
 tree=g('write-tree',env=env).decode().strip();assert all(x.startswith(str(E)+'/')for x in g('diff','--name-only',c,tree).decode().splitlines());assert g('rev-parse',tree+':前端代码/cashier-v3')==g('rev-parse',c+':前端代码/cashier-v3')
 archive=g('commit-tree',tree,'-p',c,input='R36-00001 三站欠款显隐发布与010定制卡配置恢复核验归档\n'.encode()).decode().strip();g('update-ref','refs/heads/'+branch,archive,c);assert g('rev-parse','HEAD')==head and sha(shared.read_bytes())==before
 g('push','origin','refs/heads/'+branch+':refs/heads/'+branch);assert g('ls-remote','origin','refs/heads/'+branch).decode().split()[0]==archive
 print(json.dumps({'source':c,'archive':archive,'branch':branch,'sites':['rh','008','010'],'customCard010':'restored','result':'PASS'}))
finally:Path(idx).unlink(missing_ok=True)
