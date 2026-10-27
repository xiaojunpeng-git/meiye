"""在各目标的只读事务内比较原算法行结果；候选类仅经stdin执行，不修改服务器文件。"""
from pathlib import Path
import subprocess,base64,json,shlex
r=Path(__file__).resolve().parents[2];e=r/'tests/customer-010/evidence/round6-payroll-release'
for site,host in [('rh','rh-server'),('008','mohe-server')]:
 php="<?php chdir('/www/wwwroot/"+site+".cc3798.com');require 'vendor/autoload.php';(new think\\App())->initialize();set_exception_handler(function($e){file_put_contents('php://stderr',$e->getMessage());exit(1);});"
 for p in sorted((r/'后端代码/app/services/payroll').glob('*.php')):
  php+="eval(substr(base64_decode('"+base64.b64encode(p.read_bytes()).decode()+"'),5));"
 for name in ['BasicSalaryReportServices','BasicActivityReportServices']:
  b=(r/'后端代码/app/services/report'/f'{name}.php').read_bytes().replace(('final class '+name).encode(),('final class Candidate'+name).encode());php+="eval(substr(base64_decode('"+base64.b64encode(b).decode()+"'),5));"
 php+=r'''think\facade\Db::execute('SET SESSION TRANSACTION READ ONLY');
 $identity=think\facade\Db::name('cashier_v3_performance_fact')->where('store_id','>',0)->field('store_id,COUNT(*) n')->group('store_id')->order('n','desc')->find();$store=(int)($identity['store_id']??0);
 if(!$store)$store=(int)think\facade\Db::name('system_store')->where('is_del',0)->value('id');
 $input=['start_date'=>'2026-10-01','end_date'=>'2026-10-27','as_of_date'=>'2026-10-27','_report_scope'=>['mode'=>'stores']];$results=[];
 foreach(['BasicSalaryReportServices'=>'basic_salary_realtime','BasicActivityReportServices'=>'basic_employee_summary'] as $name=>$code){$old='app\\services\\report\\'.$name;$new='app\\services\\report\\Candidate'.$name;$a=(new $old())->read($code,[$store],$input);$b=(new $new())->read($code,[$store],$input);if($a['rows']!==$b['rows']||$a['sum_fields']!==$b['sum_fields'])throw new RuntimeException('Existing rows changed:'.$code);$results[$code]=['rows'=>count($a['rows']),'sha256'=>hash('sha256',json_encode($a['rows']))];}
 echo json_encode(['result'=>'PASS','mode'=>(int)sys_config('cashier_labor_fee_mode',0),'store_id'=>$store,'comparisons'=>$results,'business_writes'=>0]);'''
 p=subprocess.run(['ssh','-q','-o','BatchMode=yes',host,'/www/server/php/74/bin/php'],input=php,text=True,capture_output=True,timeout=120)
 if p.returncode:raise RuntimeError(p.stderr[-2500:]+p.stdout[-1000:])
 d=json.loads(p.stdout);(e/(site+'-existing-algorithm-parity.json')).write_text(json.dumps(d,indent=2));print(site,json.dumps(d))
