# coding: utf-8
"""R37 三站通过后归档逐站证据；仅更新本轮发布分支，保持共享HEAD与索引原样。"""
import json,subprocess,hashlib,tempfile,os,datetime,sys
from pathlib import Path
E=Path('tests/cashier-v3/evidence/R37-card-labor-followup');R=E/'release'
def g(*a,**kw):return subprocess.check_output(['git',*a],**kw)
def sha(b):return hashlib.sha256(b).hexdigest()
# 逐站归档实际完成结果；010补验未完成时允许归档瑞昊/008，不能宣称三站完成。
assert sys.argv[1:] == ['rh','008'], 'THIS_ARCHIVE_REQUIRES_EXPLICIT_COMPLETED_SITE_SCOPE'
i=json.loads((R/'commit.json').read_text());c=i['commit'];branch=i['branch'];results={s:json.loads((R/(s+'-verified.json')).read_text())for s in sys.argv[1:]};assert all(v['result']=='PASS'and v['commit']==c for v in results.values())
(R/'complete.json').write_text(json.dumps({'round':37,'closure':'R37-00002','sourceCommit':c,'result':'PARTIAL_010_REACCEPTANCE_PENDING','pending':['010'],'instances':results,'finishedAtUTC':datetime.datetime.now(datetime.timezone.utc).isoformat(),'configuration':'配置值不变，无数据库写入'},ensure_ascii=False,indent=2))
(E/'发布结果.md').write_text('# 第37轮卡内手工列补漏发布结果\n\n代码提交：`'+c+'`。仅本次两文件显隐补漏及对应测试、工程脚本和证据。已完成瑞昊部署验证→push→008部署验证。010未部署，待合并候选d6a4ca75产品经理补验。\n\n保留所有卡规则与组合规则的其他列、卡内手工费金额和保存逻辑；配置及业务库无写入。瑞昊/008平台保留R19功能和R68我的拼团链接，门店端包未更新。主资源与变化懒加载资源公网校验通过；正式登录页浏览器正常呈现；线上otherInfo只读验证覆盖新增/编辑及配置0/1/缺省，响应与原金额保持不变。\n\n010在原验收后上线31aafac2默认手艺人业绩与ea743c57旧提成字段退役；已保留并重新构建、自测、只读验证通过，未创建010业务提交或部署。需按AGENTS验收后基线变化规则取得补验后再上线。\n')
shared=Path(g('rev-parse','--git-path','index').decode().strip());ih=sha(shared.read_bytes());head=g('rev-parse','HEAD');assert g('rev-parse','refs/heads/'+branch).decode().strip()==c
fd,idx=tempfile.mkstemp(prefix='r37-archive-');os.close(fd);os.unlink(idx);env=dict(os.environ,GIT_INDEX_FILE=idx)
try:
 g('read-tree',c,env=env)
 for p in sorted([x for x in E.rglob('*')if x.is_file()]+[Path('scripts/r37-card-release-archive.py'),Path('scripts/r37-card-final-build.cjs')]):g('update-index','--add','--cacheinfo','100644',g('hash-object','-w','--stdin',input=p.read_bytes()).decode().strip(),str(p),env=env)
 tree=g('write-tree',env=env).decode().strip();assert all(p.startswith(str(E)+'/') or p in ['scripts/r37-card-release-archive.py','scripts/r37-card-final-build.cjs'] for p in g('diff','--name-only',c,tree).decode().splitlines());assert g('rev-parse',tree+':前端代码/cashier-v3')==g('rev-parse',c+':前端代码/cashier-v3')
 a=g('commit-tree',tree,'-p',c,input='R37-00002 瑞昊008补漏发布归档及010合并候选待补验\n'.encode()).decode().strip();g('update-ref','refs/heads/'+branch,a,c);assert g('rev-parse','HEAD')==head and sha(shared.read_bytes())==ih;g('push','origin','refs/heads/'+branch+':refs/heads/'+branch);assert g('ls-remote','origin','refs/heads/'+branch).decode().split()[0]==a
 print(json.dumps({'sourceCommit':c,'archiveCommit':a,'branch':branch,'result':'PASS','sites':['rh','008'],'pending':['010']}))
finally:Path(idx).unlink(missing_ok=True)
