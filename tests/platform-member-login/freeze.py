"""Freeze scoped member changes onto the accepted release without touching the shared index."""
import hashlib,json,os,pathlib,subprocess
root=pathlib.Path(__file__).resolve().parents[2];os.chdir(root)
e=root/'tests/platform-member-login/evidence';b=json.loads((e/'baseline.json').read_text());parent=b['previousAcceptedCommit']
def g(*a,env=None,input=None):return subprocess.check_output(['git',*a],env=env,input=input)
assert g('rev-parse','HEAD').decode().strip()==b['head']
idx=pathlib.Path(g('rev-parse','--git-path','index').decode().strip());before=hashlib.sha256(idx.read_bytes()).hexdigest()
env=os.environ.copy();env['GIT_INDEX_FILE']='/private/tmp/platform-member-login-fixed.index';g('read-tree',parent,env=env)
files=[f for f in b['files'] if not f.endswith('/LoginServices.php')]
files+=['前端代码/cashier-v3/src/router/index.js','后端代码/route/admin.php','后端代码/route/cashier-v3.php','后端代码/config/unified_query.php',
'前端代码/cashier-v3/src/services/memberLoginApi.js','前端代码/cashier-v3/src/components/member/MemberLoginPanel.vue','前端代码/cashier-v3/src/views/PlatformMemberListView.vue',
'后端代码/app/services/user/MemberWechatBindingReadServices.php','后端代码/app/services/user/MemberManagementCommandServices.php','后端代码/app/services/user/MemberLoginManagementServices.php','后端代码/app/services/user/PlatformMemberScopeServices.php','后端代码/app/services/user/PlatformMemberCenterServices.php','后端代码/app/controller/admin/v1/user/MemberCenter.php','后端代码/app/controller/cashier/v3/MemberLogin.php',
'后端代码/app/services/query/provider/PlatformMemberUnifiedQueryProvider.php','后端代码/app/services/query/provider/PlatformMemberUnifiedQueryRegistrar.php','后端代码/app/services/query/provider/PlatformMemberUnifiedQueryWorkerContextResolver.php']
files+= [str(p) for p in sorted(pathlib.Path('后端代码/database/upgrades/2026-10-28-平台门店会员管理').iterdir())]
for f in files:
 data=pathlib.Path(f).read_bytes()
 # Shared report experiments are unrelated and unaccepted: layer only this task's insertions.
 if f.endswith('/router/index.js'):
  accepted=g('show',parent+':'+f).decode();work=data.decode()
  accepted=accepted.replace("import MemberListView from '@/views/MemberListView.vue'", "import MemberListView from '@/views/MemberListView.vue'\nimport PlatformMemberListView from '@/views/PlatformMemberListView.vue'")
  route=next(x for x in work.splitlines() if "path: '/platform/member-list'" in x)
  data=accepted.replace('const routes = [','const routes = [\n'+route).encode()
 elif f in ['后端代码/route/admin.php','后端代码/route/cashier-v3.php']:
  accepted=g('show',parent+':'+f).decode();work=data.decode()
  if f.endswith('/admin.php'):
   start=work.index('        // 平台会员模块明确白名单');end=work.index('        // 顾客资料中的推荐人',start)
   needle='        // 顾客资料中的推荐人'
  else:
   start=work.index("        Route::get('member-login/:id'");end=work.index("        Route::post('hang-drafts/delete'",start)
   needle="        Route::post('hang-drafts/delete'"
  assert accepted.count(needle)==1
  data=accepted.replace(needle,work[start:end]+needle,1).encode()
 blob=g('hash-object','-w','--stdin',input=data).decode().strip();g('update-index','--add','--cacheinfo','100644',blob,f,env=env)
tree=g('write-tree',env=env).decode().strip();patch=g('diff','--binary',parent,tree)
(e/'fixed.patch').write_bytes(patch)
plan={'head':b['head'],'parent':parent,'sourceTree':tree,'patchSHA256':hashlib.sha256(patch).hexdigest(),'files':{f:hashlib.sha256(g('show',tree+':'+f)).hexdigest() for f in files},'worktreeFiles':{f:hashlib.sha256(pathlib.Path(f).read_bytes()).hexdigest() for f in files},'tests':{str(p):hashlib.sha256(p.read_bytes()).hexdigest() for p in pathlib.Path('tests/platform-member-login').iterdir() if p.is_file()},'excludedUnacceptedChanges':['router/index.js: existing basic report permission experiments','route/admin.php and route/cashier-v3.php: existing report route deletions'],'acceptedFunctionsPreserved':['member phone-change reset commit 4f63da06','existing basic-report routes and per-feature authorization from accepted parent','member query metrics / profile / restore / transfer services'],'remote':'not applicable: local development only','acceptedByUser':False,'sharedIndexSHA256':before}
(e/'fixed.json').write_text(json.dumps(plan,ensure_ascii=False,indent=2)+'\n')
assert before==hashlib.sha256(idx.read_bytes()).hexdigest()
print(json.dumps({'tree':tree,'patch':plan['patchSHA256'],'files':len(files),'sharedIndexPreserved':True}))
