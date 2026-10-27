"""在现有本地PHP容器内注入固定候选类；仅测试夹具库允许写入，原工作区不复制或改写。"""
from pathlib import Path
import subprocess,base64,json,sys
root=Path(__file__).resolve().parents[2]
classes=['payroll/PayrollAlgorithmPolicy','payroll/ProjectLaborCommissionCalculator','payroll/ProjectLaborCommissionReadServices','payroll/PositionSalaryRuleResolver','payroll/IndependentPositionSalaryRuleServices','report/BasicSalaryReportServices','report/BasicActivityReportServices','mobile/warehouse/MobileWarehouseServices']
prelude="<?php chdir('/var/www/html'); require 'vendor/autoload.php';\n"
prelude+="eval('namespace app\\services\\payroll; function sys_config($key,$default=0) { return $GLOBALS[\"payroll_test_mode\"] ?? 0; }');\n"
for c in classes:
 b=(root/'后端代码/app/services'/f'{c}.php').read_bytes()
 prelude+="eval(substr(base64_decode('"+base64.b64encode(b).decode()+"'),5));\n"
prelude+="$GLOBALS['payroll_schema_sql']=base64_decode('"+base64.b64encode((root/'后端代码/database/upgrades/2026-10-27-独立职位工资规则/01-结构升级.sql').read_bytes()).decode()+"');\n"
results=[]
for relative in sys.argv[1:] or ['tests/report-basic/php/salary-local.php','tests/report-basic/php/activity-local.php']:
 code=(root/relative).read_text();code=code[code.index('<?php')+5:].replace('declare(strict_types=1);','')
 run=subprocess.run(['docker','exec','-i','-e','R51_LOCAL_TEST=1','mohe-app','php'],input=prelude+code,text=True,capture_output=True)
 results.append({'test':relative,'exit_code':run.returncode,'output':run.stdout[-4500:],'error':run.stderr[-2000:]})
 print(json.dumps(results[-1],ensure_ascii=False),flush=True)
 if run.returncode:sys.exit(run.returncode)
e=root/'tests/customer-010/evidence/round6-payroll-algorithm';e.mkdir(parents=True,exist_ok=True)
(e/'local-integration.json').write_text(json.dumps(results,ensure_ascii=False,indent=2))
