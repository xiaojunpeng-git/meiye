"""固定工作树集成验证；私有数据只送入本机隔离库，finally 删除，输出不含个人明细。"""
from pathlib import Path
import base64,json,subprocess
from round9_test_runtime import prelude
root=Path(__file__).resolve().parents[2]
e=root/'tests/customer-010/evidence/round9-salary-reconciliation'
boot=prelude(root)
for key,path in [('payroll_schema_sql','后端代码/database/upgrades/2026-10-27-独立职位工资规则/01-结构升级.sql'),('salary_manual_schema_sql','后端代码/database/upgrades/2026-10-27-工资人工项与编辑冻结/01-结构升级.sql')]:
 boot+="$GLOBALS['"+key+"']=base64_decode('"+base64.b64encode((root/path).read_bytes()).decode()+"');\n"
fixture=json.loads((e/'private/010-payroll-fixture.json').read_text())
tables=['salary_info','system_store_staff','system_store','position','position_level','salary_freeze']
small={k:{t:fixture[k].get(t,[]) for t in tables}for k in ['ddl','tables']}
boot+="$GLOBALS['cutover_fixture']=json_decode(base64_decode('"+base64.b64encode(json.dumps(small).encode()).decode()+"'),true);\n"
boot+="$GLOBALS['fixed_query_config']=eval(substr(base64_decode('"+base64.b64encode((root/'后端代码/config/unified_query.php').read_bytes()).decode()+"'),5));\n"
results=[]
for label,path in [('saved-history','tests/customer-010/round9-salary-cutover.php'),('november-original-algorithm-and-manual','tests/customer-010/payroll-local.php'),('blank-config-original-algorithm','tests/customer-010/payroll-local.php')]:
 code=(root/path).read_text().split('<?php',1)[1]
 if label!='saved-history':
  addon=(root/'tests/customer-010/payroll-manual-integration.php').read_text().split('<?php',1)[1]
  code=code.replace(" echo json_encode(['result'=>'PASS'",addon+"\n echo json_encode(['result'=>'PASS'")
  if label.startswith('november'):code=code.replace('2026-10','2026-11').replace("config(['position_model'=>'independent'],'payroll');","config(['position_model'=>'independent','realtime_start_date'=>'2026-11-01'],'payroll');")
 if label.startswith('november'):
  code=code.replace(' $sale=$fact([]);'," $fact(['business_date'=>'2026-10-31','amount_cents'=>99999999]);\n $sale=$fact([]);")
 run=subprocess.run(['docker','exec','-i','mohe-app','php','-d','memory_limit=768M'],input=boot+code,text=True,capture_output=True)
 if run.returncode==0 and label=='saved-history':
  value=json.loads(run.stdout)
  projection=value.pop('page');catalog=value.pop('catalog')
  for row in projection['rows']+projection.get('exportRows',[]):row['employee_name']='验证员工'
  projection_path=e/'private/browser-projection.json'
  projection_path.write_text(json.dumps({'page':projection,'catalog':catalog},ensure_ascii=False,indent=2));projection_path.chmod(0o600)
  run.stdout=json.dumps(value,ensure_ascii=False)
 item={'test':label,'exit_code':run.returncode,'output':run.stdout[-3500:],'error':run.stderr[-2500:]};results.append(item);print(json.dumps(item,ensure_ascii=False),flush=True)
 (e/'cutover-tests.json').write_text(json.dumps(results,ensure_ascii=False,indent=2))
 if run.returncode:raise SystemExit(run.returncode)
