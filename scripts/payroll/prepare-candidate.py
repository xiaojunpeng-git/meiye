"""固定010三端已上线源码树，并叠加本轮清单；独立索引，不修改共享暂存区。"""
from pathlib import Path
import subprocess,os,tempfile,json,hashlib
root=Path(__file__).resolve().parents[2];e=root/'tests/customer-010/evidence/round6-payroll-algorithm';e.mkdir(parents=True,exist_ok=True)
def git(*args,**kw):return subprocess.check_output(['git',*args],cwd=root,**kw)
terminals={'admin':'d6a4ca75e16386e2c78dafe182f979e6aa04aacd','cashier-v3':'47b8305cca1f7f0e7525a8b012617eec0b3afd43','mobile-vue3':'f76e0494c9f659dd79bc4677a7a1f85f92a27fa5'}
files=['后端代码/config/payroll.php','后端代码/app/services/report/BasicSalaryReportServices.php','后端代码/app/services/report/BasicActivityReportServices.php','后端代码/app/services/mobile/warehouse/MobileWarehouseServices.php','后端代码/app/services/position/PositionServices.php','后端代码/app/services/position/PositionYejiServices.php','后端代码/app/controller/admin/v1/position/PositionLevel.php','后端代码/app/services/system/config/SystemConfigServices.php','后端代码/database/upgrades/2026-10-27-独立职位工资规则/01-结构升级.sql','前端代码/admin/src/api/position.js','前端代码/admin/src/pages/position/position.vue','前端代码/admin/src/components/positionLevel/index.vue','前端代码/cashier-v3/src/views/BasicReportsView.vue','前端代码/mobile-vue3/src/merchant/pages/employee-performance/index.uvue']
files += [str(p.relative_to(root)) for p in sorted((root/'后端代码/app/services/payroll').glob('*.php'))]
files += [str(p.relative_to(root)) for p in sorted((root/'scripts/payroll').glob('*')) if p.is_file() and p.suffix in ['.py','.php','.cjs']]
files += ['tests/customer-010/payroll-calculator.php','tests/customer-010/payroll-local.php','tests/report-basic/php/salary-contract.php']
fd,index=tempfile.mkstemp(prefix='010-payroll-index-');os.close(fd);os.unlink(index);env=dict(os.environ,GIT_INDEX_FILE=index)
def replace_prefix(tree,prefix):
 old=git('ls-files','-z','--',prefix,env=env)
 if old:git('update-index','--force-remove','-z','--stdin',input=old,env=env)
 entries=git('ls-tree','-r','-z',tree,'--',prefix)
 git('update-index','-z','--index-info',input=entries,env=env)
try:
 git('read-tree','HEAD',env=env)
 for terminal,tree in terminals.items():replace_prefix(tree,'前端代码/'+terminal)
 # 已上线R37后端源码来自已验收Git树；没有服务器反向复制或其他任务脏代码。
 for path in ['后端代码/app/services/system/config/SystemConfigServices.php','后端代码/app/services/cashier/v3/projection/CashierV3RootDomainAssembler.php','后端代码/app/services/cashier/v3/projection/CashierV3RootStateContract.php','后端代码/app/validate/admin/setting/SystemConfigValidate.php']:
  blob=git('rev-parse',terminals['cashier-v3']+':'+path).decode().strip();git('update-index','--add','--cacheinfo','100644',blob,path,env=env)
 baseline=git('write-tree',env=env).decode().strip()
 hashes={}
 for path in files:
  data=(root/path).read_bytes();hashes[path]=hashlib.sha256(data).hexdigest();blob=git('hash-object','-w','--stdin',input=data).decode().strip();git('update-index','--add','--cacheinfo','100644',blob,path,env=env)
 candidate=git('write-tree',env=env).decode().strip()
 git('diff','--check',baseline,candidate)
 actual=git('diff','--name-only',baseline,candidate).decode().splitlines();assert sorted(actual)==sorted(files),(actual,files)
 result={'head':git('rev-parse','HEAD').decode().strip(),'baselineTree':baseline,'candidateTree':candidate,'acceptedTerminalSources':terminals,'files':hashes,'businessDataMigration':False,'activation':'010 existing cashier_labor_fee_mode=1; independent position model instance configuration','authorization':'用户明确要求自测后部署010测试实例，再交产品经理验收；未获本轮业务提交或push验收'}
 (e/'fixed-source.json').write_text(json.dumps(result,ensure_ascii=False,indent=2));print(json.dumps({'baselineTree':baseline,'candidateTree':candidate,'files':len(files)}))
finally:Path(index).unlink(missing_ok=True)
