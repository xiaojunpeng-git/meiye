"""R38补漏逐站归档：沿用现有分支，只保存本轮证据和实际工程脚本，业务树不再变更。"""
import pathlib,json,subprocess,tempfile,os,datetime,hashlib
P=pathlib.Path;E=P('tests/inventory/evidence/R38-followup-statistics');R=E/'release'
def g(*a,**kw):return subprocess.check_output(['git',*a],**kw)
def read(n):return json.loads((R/n).read_text())
i=read('commit.json');source=i['commit'];sites={s:read(s+'-verified.json')for s in ['rh','008','010']};assert all(v['result']=='PASS'and v['commit']==source for v in sites.values());assert read('push.json')['result']=='PASS'
(R/'complete.json').write_text(json.dumps({'round':'38补漏','result':'PASS','sourceCommit':source,'sites':sites,'finishedUTC':datetime.datetime.now(datetime.timezone.utc).isoformat(),'noDatabaseOrConfigurationWrites':True,'scope':['首页统计仓库继承','单位白名单','返回首页','分页和旧响应隔离'],'operationalRepair':'仅rh/008现有工资类目录700→755，使运行账号可读；010原755保持。源码、工资算法、业务值不变，权限回滚元数据已独立保存。','baselinePreservation':'已上线库存、收银与当前补交分成修复在原分支提交链统一保留；与本轮新增项分开记录。','historyAlignment':'保留远端10月14日提交记录，历史对齐提交业务树零差异，正常push无强推。','browserLimits':'008总部仓无批次，验证真实空结果；010无授权总部仓，验证既有拒绝保护；瑞昊平台24批与门店80批真实下钻分页回归通过。'},ensure_ascii=False,indent=2)+'\n')
branch='refs/heads/'+i['branch'];head=g('rev-parse','HEAD').decode().strip();assert g('symbolic-ref','HEAD').decode().strip()==branch
# 工程归档只能改变证据及发布脚本，不得混入共享工作区的其他业务改动。
for sub in ['前端代码','后端代码']:assert g('rev-parse',head+':'+sub)==g('rev-parse',source+':'+sub)
shared=P(g('rev-parse','--git-path','index').decode().strip());indexHash=hashlib.sha256(shared.read_bytes()).hexdigest();fd,idx=tempfile.mkstemp(prefix='r38-followup-archive-');os.close(fd);os.unlink(idx);env=dict(os.environ,GIT_INDEX_FILE=idx)
try:
 g('read-tree',head,env=env);paths=sorted([p for p in E.rglob('*')if p.is_file()]+[P('scripts/'+n)for n in ['r38-followup-release.py','r38-followup-smoke.php','r38-followup-build.cjs','r38-followup-archive.py']])
 for p in paths:g('update-index','--add','--cacheinfo','100644',g('hash-object','-w',str(p)).decode().strip(),str(p),env=env)
 tree=g('write-tree',env=env).decode().strip();changed=g('diff','--name-only',head,tree).decode().splitlines();assert set(changed).issubset({str(p)for p in paths});g('diff','--check',head,tree)
 for sub in ['前端代码','后端代码']:assert g('rev-parse',tree+':'+sub)==g('rev-parse',head+':'+sub)
 c=g('commit-tree',tree,'-p',head,input='R38-00002 三站库存统计补漏发布验证归档\n'.encode()).decode().strip();g('update-ref',branch,c,head);assert hashlib.sha256(shared.read_bytes()).hexdigest()==indexHash
 g('push','origin',branch+':'+branch);assert g('ls-remote','origin',branch).decode().split()[0]==c
 print(json.dumps({'result':'PASS','sourceCommit':source,'archiveCommit':c,'branch':i['branch'],'newBranch':False,'sharedIndexUntouched':True,'sites':list(sites)}))
finally:P(idx).unlink(missing_ok=True)
