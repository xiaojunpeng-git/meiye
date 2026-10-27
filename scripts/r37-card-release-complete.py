# coding: utf-8
"""R37补漏三站完成归档：记录010补验合并来源，主发布分支仅增加本轮证据与发布脚本。"""
import json,subprocess,tempfile,os,hashlib,datetime
from pathlib import Path
E=Path('tests/cashier-v3/evidence/R37-card-labor-followup');R=E/'release'
def g(*a,**kw):return subprocess.check_output(['git',*a],**kw)
def read(n):return json.loads((R/n).read_text())
def sha(b):return hashlib.sha256(b).hexdigest()
i=read('commit.json');c=i['commit'];v=read('010-source.json');results={s:read(s+'-verified.json')for s in ['rh','008','010']}
assert all(x['result']=='PASS'and x['commit']==c for x in results.values())
assert results['010']['deploymentCommit']==v['commit'] and results['010']['sourceTree']==v['candidateTree']
assert read('010-acceptance.json')['approved'] and read('010-acceptance.json')['candidateTree']==v['candidateTree']
(R/'complete.json').write_text(json.dumps({'round':37,'closure':'R37-00002','sourceCommit':c,'merged010Commit':v['commit'],'result':'PASS','pending':[],'instances':results,'finishedAtUTC':datetime.datetime.now(datetime.timezone.utc).isoformat(),'configuration':'配置及卡内金额不变，无数据库写入'},ensure_ascii=False,indent=2))
(E/'发布结果.md').write_text('# 第37轮卡内手工列补漏发布结果\n\n本次补漏标准提交：`'+c+'`；010补验合并提交：`'+v['commit']+'`。按瑞昊部署验证→push→008部署验证→010补验后部署验证完成。\n\n本轮新增仅两文件补齐卡内项目手工列显隐，普通卡、任选卡、时间卡及组合卡共用原算法配置；卡内金额和保存逻辑未改变。配置与业务数据库无写入，门店端包未更新。\n\n保留既有功能：瑞昊/008平台R19设置/岗位及R68我的拼团链接；010额外保留已上线默认手艺人业绩、旧提成字段退役与基础报表菜单样式，均不作为本轮新增。010合并候选d6a4ca75已取得产品经理明确补验，重新构建及显隐、170元、缺省、明确0元、既有手改值测试通过。\n\n三站逐文件CAS备份、部署后文件和懒加载资源公网SHA核对、实际otherInfo只读接口回归、配置/卡内金额摘要与正式后台登录页浏览器检查通过；未执行产品保存或真实收银操作。各站回滚点见release/*-verified.json。源码、构建与逐站验证记录均保存在release目录。\n')
branch=i['branch'];old=g('rev-parse','refs/heads/'+branch).decode().strip();head=g('rev-parse','HEAD');index=Path(g('rev-parse','--git-path','index').decode().strip());ih=sha(index.read_bytes())
fd,idx=tempfile.mkstemp(prefix='r37-complete-');os.close(fd);os.unlink(idx);env=dict(os.environ,GIT_INDEX_FILE=idx)
try:
 g('read-tree',old,env=env)
 scripts=[Path('scripts/r37-card-release.py'),Path('scripts/r37-card-release-complete.py')]
 for p in sorted([x for x in E.rglob('*')if x.is_file()]+scripts):
  g('update-index','--add','--cacheinfo','100644',g('hash-object','-w','--stdin',input=p.read_bytes()).decode().strip(),str(p),env=env)
 tree=g('write-tree',env=env).decode().strip();paths=g('diff','--name-only',old,tree).decode().splitlines();assert all(x.startswith(str(E)+'/')or x in [str(p)for p in scripts]for x in paths)
 # 合并来源作为第二父提交保留，主树业务代码不动，避免把010的既有差异发布到其他站点。
 for p in ['前端代码/admin','后端代码/app']:assert g('rev-parse',tree+':'+p)==g('rev-parse',old+':'+p)
 g('diff','--check',old,tree,'--',*[p for p in paths if not p.endswith('.patch')])
 a=g('commit-tree',tree,'-p',old,'-p',v['commit'],input='R37-00002 三站卡内手工列补漏发布完成归档\n'.encode()).decode().strip();g('update-ref','refs/heads/'+branch,a,old)
 assert g('rev-parse','HEAD')==head and sha(index.read_bytes())==ih
 g('push','origin','refs/heads/'+branch+':refs/heads/'+branch);assert g('ls-remote','origin','refs/heads/'+branch).decode().split()[0]==a
 print(json.dumps({'result':'PASS','archiveCommit':a,'sourceCommit':c,'merged010Commit':v['commit'],'branch':branch}))
finally:Path(idx).unlink(missing_ok=True)
