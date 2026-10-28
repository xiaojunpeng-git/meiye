"""R39发布证据归档：三站逐站验证后，只在现有分支保存本轮证据和工程脚本。"""
import pathlib,json,subprocess,datetime,tempfile,os,hashlib
P=pathlib.Path;E=P('tests/report-group-dashboard/evidence/R39-rank-scroll-toggle');R=E/'release'
def g(*a,**kw):return subprocess.check_output(['git',*a],**kw)
def read(n):return json.loads((R/n).read_text())
i=read('commit.json');source=i['commit'];sites={s:read(s+'-verified.json')for s in ['rh','008','010']};assert all(v['result']=='PASS'and v['sourceCommit']==source for v in sites.values());assert read('push.json')['commit']==source
(R/'complete.json').write_text(json.dumps({'round':39,'result':'PASS','sourceCommit':source,'sites':sites,'businessFiles':i['businessPaths'],'default':'三个排行榜默认滚动，按钮独立暂停/继续，继续从当前查看位置开始','existingFeaturesPreserved':['当前已上线会员管理及登录信息','工资报表','团长与团员搜索','库存统计补漏','既有收银功能'],'baselineProof':'正本固定基线构建入口及主JS/CSS与三站现网逐字匹配，本轮候选仅一个Vue文件差异。','backendChanges':False,'databaseWrites':0,'configurationChanges':False,'serviceRestart':False,'newBranch':False,'browserValidationLimit':'010当前账号访问集团看板为403，保留权限保护；没有声称完成010登录态榜单交互，相同产物在瑞昊和008已完成真实交互验证。','finishedUTC':datetime.datetime.now(datetime.timezone.utc).isoformat()},ensure_ascii=False,indent=2)+'\n')
head=g('rev-parse','HEAD').decode().strip();branch=g('symbolic-ref','HEAD').decode().strip();assert head==source and branch=='refs/heads/'+i['branch'];shared=P(g('rev-parse','--git-path','index').decode().strip());digest=hashlib.sha256(shared.read_bytes()).hexdigest();fd,idx=tempfile.mkstemp(prefix='r39-archive-');os.close(fd);os.unlink(idx);env=dict(os.environ,GIT_INDEX_FILE=idx)
try:
 g('read-tree',head,env=env);paths=sorted([p for p in E.rglob('*')if p.is_file()]+[P('scripts/'+n)for n in ['r39-release-build.cjs','r39-release-install.php','r39-release.py','r39-release-archive.py']])
 for p in paths:g('update-index','--add','--cacheinfo','100644',g('hash-object','-w',str(p)).decode().strip(),str(p),env=env)
 tree=g('write-tree',env=env).decode().strip();changed=g('diff','--name-only',head,tree).decode().splitlines();assert set(changed).issubset({str(p)for p in paths})
 # 归档不再修改已验收的业务源码，也不能把工作区其他任务带进来。
 for directory in ['前端代码','后端代码']:assert g('rev-parse',tree+':'+directory)==g('rev-parse',head+':'+directory)
 g('diff','--check',head,tree,'--',*[p for p in changed if not p.endswith('.diff')]);c=g('commit-tree',tree,'-p',head,input='R39-00001 三站排行榜滚动控制发布验证归档\n'.encode()).decode().strip();g('update-ref',branch,c,head);assert hashlib.sha256(shared.read_bytes()).hexdigest()==digest;g('push','origin',branch+':'+branch);assert g('ls-remote','origin',branch).decode().split()[0]==c
 print(json.dumps({'result':'PASS','sourceCommit':source,'archiveCommit':c,'branch':i['branch'],'sites':list(sites),'sharedIndexUntouched':True}))
finally:P(idx).unlink(missing_ok=True)
