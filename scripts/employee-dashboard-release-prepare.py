"""Build a scoped immutable Git candidate without touching shared HEAD/index or editable sources."""
import os,subprocess,pathlib,json,hashlib,tempfile
R=pathlib.Path(__file__).resolve().parents[1];os.chdir(R)
E=R/'tests/employee-dashboard-drilldown/evidence/release';E.mkdir(exist_ok=True)
def git(*a,input=None,env=None):return subprocess.check_output(['git',*a],input=input,env=env)
def text(*a):return git(*a).decode()
base='20bb0102783f9217400eefe7babc7dca08344e4e'
index=R/text('rev-parse','--git-path','employee-dashboard-release-index').strip();env={**os.environ,'GIT_INDEX_FILE':str(index)}
indexHash=hashlib.sha256((R/text('rev-parse','--git-path','index').strip()).read_bytes()).hexdigest()
git('read-tree',base,env=env)
changes={}
def put(p,b):
 h=git('hash-object','-w','--stdin',input=b).decode().strip();git('update-index','--add','--cacheinfo','100644,'+h+','+p,env=env);changes[p]=hashlib.sha256(b).hexdigest()
# Preserve already released dashboard/staff behavior from its traced canonical baseline.
for p in ['前端代码/cashier-v3/src/services/staffManagementApi.js','前端代码/cashier-v3/src/views/BusinessDashboardView.vue','前端代码/cashier-v3/src/views/StaffListView.vue']:
 put(p,git('show','6547b4a:'+p))
# R51 inventory is now verified live on all three sites; preserve its entire committed dependency closure.
for p in text('diff','--name-only','6547b4a','151e02fe').splitlines():
 if p.startswith(('前端代码/','后端代码/')):put(p,git('show','151e02fe:'+p))
# Whole files here contain only this round relative to the verified R4 release baseline.
paths=['后端代码/app/services/report/EmployeeDashboardServices.php','后端代码/app/services/report/EmployeeDashboardPopulationServices.php','前端代码/admin/src/pages/report/data/employee_dashboard.vue','前端代码/admin/src/pages/setting/staff/index.vue','前端代码/admin/src/pages/setting/staff/EmployeeDashboardPeople.vue','后端代码/app/controller/admin/v1/merchant/SystemStoreStaff.php','后端代码/app/controller/admin/v1/other/export/ExportExcel.php','后端代码/app/services/employee/EmployeeProfileExportServices.php']
for p in paths:put(p,(R/p).read_bytes())
p='后端代码/app/controller/admin/v1/report/UnifiedReport.php';old=text('show',base+':'+p);new=(R/p).read_text();a=old.index('    public function employeeDashboard(');b=old.index('    public function scope(',a);na=new.index('    public function employeeDashboard(');nb=new.index('    public function scope(',na);put(p,(old[:a]+new[na:nb]+old[b:]).encode())
p='后端代码/route/admin.php';old=text('show',base+':'+p);new=(R/p).read_text();line=next(l for l in new.splitlines() if "Route::get('employee-dashboard/people'" in l);anchor=next(l for l in old.splitlines()if "Route::get('employee-dashboard'"in l);put(p,old.replace(anchor,anchor+'\n'+line).encode())
p='前端代码/cashier-v3/src/views/EmployeeDashboardView.vue';s=(R/p).read_text();a=s.index('    // An independent instance without authorized stores');b=s.index("    state.value = 'error'",a);s=s[:a]+s[b:]; original=text('show',base+':'+p); oldLayout=next(l for l in original.splitlines() if l.startswith('  <div class="layout"')); newLayout=next(l for l in s.splitlines() if l.startswith('  <div class="layout"')); s=s.replace(newLayout,oldLayout);put(p,s.encode())
for folder in ['tests/employee-dashboard-drilldown','tests/staff-profile-export']:
 for p in (R/folder).rglob('*'):
  if p.is_file() and p.suffix in ['.php','.mjs'] :put(str(p.relative_to(R)),p.read_bytes())
tree=text('write-tree') if False else git('write-tree',env=env).decode().strip()
record={'base':base,'tree':tree,'changes':changes,'sharedIndexSHA256':indexHash,'status':'candidate awaiting supplemental acceptance for profile export and isolated publication tree','preservedSource':'151e02fe13ed3754e689c7a3822899eb1d833221','excludedOtherWork':'unrelated report controller/routes and uncommitted no-scope UI branch'}
assert hashlib.sha256((R/text('rev-parse','--git-path','index').strip()).read_bytes()).hexdigest()==indexHash
(E/'unified-candidate.json').write_text(json.dumps(record,ensure_ascii=False,indent=2)+'\n');print(tree)
