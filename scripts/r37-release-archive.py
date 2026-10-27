# coding: utf-8
"""R37 三站通过后归档逐站证据；仅更新本轮发布分支，保持共享HEAD与索引原样。"""
import json,subprocess,hashlib,tempfile,os,datetime
from pathlib import Path
E=Path('tests/cashier-v3/evidence/R37-labor-fee-display');R=E/'release'
def g(*a,**kw):return subprocess.check_output(['git',*a],**kw)
def sha(b):return hashlib.sha256(b).hexdigest()
i=json.loads((R/'commit.json').read_text());c=i['commit'];branch=i['branch'];results={s:json.loads((R/(s+'-verified.json')).read_text())for s in ['rh','008','010']};assert all(v['result']=='PASS'and v['commit']==c for v in results.values())
(R/'complete.json').write_text(json.dumps({'round':37,'sourceCommit':c,'result':'PASS','instances':results,'finishedAtUTC':datetime.datetime.now(datetime.timezone.utc).isoformat(),'configuration':'仅幂等新增本轮显示定义，默认可修改；既有值不覆盖，业务金额不变'},ensure_ascii=False,indent=2))
(E/'发布结果.md').write_text('# 第37轮发布结果\n\n源代码提交：`'+c+'`。产品经理验收后，已按瑞昊部署/验证→push→008部署/验证→010部署/验证顺序完成。新增手工费算法（可修改/计算法）显示设置，计算法隐藏明确指定的收银及全部订单、消费订单手工费字段；金额、算法、唯一结账快照不变。\n\n以各站实际已上线R20前端为基线，只应用R37补丁；其他领用、报表、欠款、定制卡功能的源码完整保留。基线重构主资源与现网一致，候选SFC显隐及确认/应用全部人快照一致性测试通过。后台静态包、其他终端和无关后端文件保持原指纹。\n\n本轮仅新增一个默认0显示配置定义，保留既有值及其他配置。配置迁移已逐站备份、读取验证，备份/文件回滚点均见对应release/*-verified.json及*-config-verify.json。根组装器集成测试使用只读测试分区，避免执行结账或创建工作台；三站没有财务业务写入。正式入口、公网资源及浏览器启动验证见release目录。\n')
shared=Path(g('rev-parse','--git-path','index').decode().strip());ih=sha(shared.read_bytes());head=g('rev-parse','HEAD');assert g('rev-parse','refs/heads/'+branch).decode().strip()==c
fd,idx=tempfile.mkstemp(prefix='r37-archive-');os.close(fd);os.unlink(idx);env=dict(os.environ,GIT_INDEX_FILE=idx)
try:
 g('read-tree',c,env=env)
 for p in sorted(x for x in E.rglob('*')if x.is_file()):g('update-index','--add','--cacheinfo','100644',g('hash-object','-w','--stdin',input=p.read_bytes()).decode().strip(),str(p),env=env)
 tree=g('write-tree',env=env).decode().strip();assert all(p.startswith(str(E)+'/')for p in g('diff','--name-only',c,tree).decode().splitlines());assert g('rev-parse',tree+':前端代码/cashier-v3')==g('rev-parse',c+':前端代码/cashier-v3')
 a=g('commit-tree',tree,'-p',c,input='R37-00001 三站手工费显示选项发布验证归档\n'.encode()).decode().strip();g('update-ref','refs/heads/'+branch,a,c);assert g('rev-parse','HEAD')==head and sha(shared.read_bytes())==ih;g('push','origin','refs/heads/'+branch+':refs/heads/'+branch);assert g('ls-remote','origin','refs/heads/'+branch).decode().split()[0]==a
 print(json.dumps({'sourceCommit':c,'archiveCommit':a,'branch':branch,'result':'PASS','sites':['rh','008','010']}))
finally:Path(idx).unlink(missing_ok=True)
