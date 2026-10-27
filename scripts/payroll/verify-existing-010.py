"""线上010当前事实上的原算法等价校验；候选只经stdin加载，配置只在本次PHP内存覆盖。"""
from pathlib import Path
import subprocess,json,base64
r=Path(__file__).resolve().parents[2];e=r/'tests/customer-010/evidence/round6-payroll-algorithm'
g=lambda p:subprocess.check_output(['git','show','HEAD:'+p],cwd=r)
body="<?php chdir('/www/wwwroot/010.cc3798.com');require 'vendor/autoload.php';(new think\\App())->initialize();\n"
for name in ['BasicSalaryReportServices','BasicActivityReportServices']:
 b=g('后端代码/app/services/report/'+name+'.php').replace(('final class '+name).encode(),('final class Baseline'+name).encode())
 body+="eval(substr(base64_decode('"+base64.b64encode(b).decode()+"'),5));\n"
for p in list(sorted((r/'后端代码/app/services/payroll').glob('*.php')))+[r/'后端代码/app/services/report/BasicSalaryReportServices.php',r/'后端代码/app/services/report/BasicActivityReportServices.php']:
 body+="eval(substr(base64_decode('"+base64.b64encode(p.read_bytes()).decode()+"'),5));\n"
body+='''eval('namespace app\\services\\payroll; function sys_config($key,$default=0) { return 0; }');
config(['position_model'=>'legacy'],'payroll');think\\facade\\Db::execute('SET SESSION TRANSACTION READ ONLY');
$store=(int)think\\facade\\Db::name('system_store_staff')->where('employee_id',84)->where('status',1)->where('is_del',0)->value('store_id');
if ($store<1)throw new RuntimeException('Known existing fixture employee missing');
$input=['start_date'=>'2026-10-01','end_date'=>'2026-10-27','as_of_date'=>'2026-10-27','_report_scope'=>['mode'=>'stores']];
$a=(new app\\services\\report\\BaselineBasicSalaryReportServices())->read('basic_salary_realtime',[$store],$input);
$b=(new app\\services\\report\\BasicSalaryReportServices())->read('basic_salary_realtime',[$store],$input);
if($a['rows']!==$b['rows'] || $a['sum_fields']!==$b['sum_fields'])throw new RuntimeException('Existing salary algorithm changed');
$c=(new app\\services\\report\\BaselineBasicActivityReportServices())->read('basic_employee_summary',[$store],$input);
$d=(new app\\services\\report\\BasicActivityReportServices())->read('basic_employee_summary',[$store],$input);
if($c['rows']!==$d['rows'] || $c['sum_fields']!==$d['sum_fields'])throw new RuntimeException('Existing employee summary changed');
echo json_encode(['result'=>'PASS','actual_saved_switch'=>(int)sys_config('cashier_labor_fee_mode',0),'existing_algorithm_forced_only_in_process'=>true,'salary_rows'=>count($a['rows']),'salary_rows_sha256'=>hash('sha256',json_encode($a['rows'])),'employee_rows'=>count($c['rows']),'employee_rows_sha256'=>hash('sha256',json_encode($c['rows'])),'source_business_writes'=>0]);
'''
p=subprocess.run(['ssh','-q','-o','BatchMode=yes','-o','ConnectTimeout=10','mohe-server','cd /www/wwwroot/010.cc3798.com && /www/server/php/74/bin/php'],input=body,text=True,capture_output=True,timeout=100)
if p.returncode:raise RuntimeError(p.stderr[-4000:]+p.stdout[-2000:])
data=json.loads(p.stdout);(e/'010-existing-algorithm-parity.json').write_text(json.dumps(data,ensure_ascii=False,indent=2));print(json.dumps(data))
