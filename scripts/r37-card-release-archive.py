# coding: utf-8
"""R37 三站通过后归档逐站证据；仅更新本轮发布分支，保持共享HEAD与索引原样。"""
import json,subprocess,hashlib,tempfile,os,datetime
from pathlib import Path
E=Path('tests/cashier-v3/evidence/R37-card-labor-followup');R=E/'release'
def g(*a,**kw):return subprocess.check_output(['git',*a],**kw)
def sha(b):return hashlib.sha256(b).hexdigest()
i=json.loads((R/'commit.json').read_text());c=i['commit'];branch=i['branch'];results={s:json.loads((R/(s+'-verified.json')).read_text())for s in ['rh','008','010']};assert all(v['result']=='PASS'and v['commit']==c for v in results.values())
(R/'complete.json').write_text(json.dumps({'round':37,'closure':'R37-00002','sourceCommit':c,'result':'PASS','instances':results,'finishedAtUTC':datetime.datetime.now(datetime.timezone.utc).isoformat(),'configuration':'配置值不变，无数据库写入'},ensure_ascii=False,indent=2))
(E/'发布结果.md').write_text('# 第37轮卡内手工列补漏发布结果\n\n代码提交：`'+c+'`。产品经理验收后已按瑞昊部署/验证→push→008部署/验证→010部署/验证完成。只补齐平台卡内项目手工列显隐，普通卡与组合卡共用同一配置，原金额和保存逻辑不变。\n\n现网平台源码基线包含已验收R19设置/岗位功能与R68我的拼团链接配置，010保留独立基础报表菜单样式。原基线主HTML/JS/CSS可重现现网指纹；候选10组列计算及9组真实只读元数据验证通过。只更新一个PHP文件和相对原包变化的平台资源，门店端及其他终端未更新，无配置或业务库写入。\n\n逐站文件备份/回滚点、配置与卡内金额摘要、源码及主资源指纹、懒加载资源公网读取及浏览器启动证据见release目录。此前R37-00001已上线成果保持不变。\n')
shared=Path(g('rev-parse','--git-path','index').decode().strip());ih=sha(shared.read_bytes());head=g('rev-parse','HEAD');assert g('rev-parse','refs/heads/'+branch).decode().strip()==c
fd,idx=tempfile.mkstemp(prefix='r37-archive-');os.close(fd);os.unlink(idx);env=dict(os.environ,GIT_INDEX_FILE=idx)
try:
 g('read-tree',c,env=env)
 for p in sorted(x for x in E.rglob('*')if x.is_file()):g('update-index','--add','--cacheinfo','100644',g('hash-object','-w','--stdin',input=p.read_bytes()).decode().strip(),str(p),env=env)
 tree=g('write-tree',env=env).decode().strip();assert all(p.startswith(str(E)+'/')for p in g('diff','--name-only',c,tree).decode().splitlines());assert g('rev-parse',tree+':前端代码/cashier-v3')==g('rev-parse',c+':前端代码/cashier-v3')
 a=g('commit-tree',tree,'-p',c,input='R37-00002 三站卡内手工列显隐补漏发布验证归档\n'.encode()).decode().strip();g('update-ref','refs/heads/'+branch,a,c);assert g('rev-parse','HEAD')==head and sha(shared.read_bytes())==ih;g('push','origin','refs/heads/'+branch+':refs/heads/'+branch);assert g('ls-remote','origin','refs/heads/'+branch).decode().split()[0]==a
 print(json.dumps({'sourceCommit':c,'archiveCommit':a,'branch':branch,'result':'PASS','sites':['rh','008','010']}))
finally:Path(idx).unlink(missing_ok=True)
