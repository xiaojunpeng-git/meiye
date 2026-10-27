"""010 候选算法只读预检：源码通过stdin解释，不上传文件，不改配置或业务表。"""
from pathlib import Path
import subprocess,json,base64
root=Path(__file__).resolve().parents[2];e=root/'tests/customer-010/evidence/round6-payroll-algorithm'
body="<?php chdir('/www/wwwroot/010.cc3798.com');require 'vendor/autoload.php';(new think\\App())->initialize();\n"
for p in sorted((root/'后端代码/app/services/payroll').glob('*.php')):
 body+="eval(substr(base64_decode('"+base64.b64encode(p.read_bytes()).decode()+"'),5));\n"
body+='''think\\facade\\Db::execute('SET TRANSACTION READ ONLY');think\\facade\\Db::startTrans();
try {
 $stores=array_map('intval',think\\facade\\Db::name('system_store')->where('is_del',0)->column('id'));
 $begin=microtime(true);$read=(new app\\services\\payroll\\ProjectLaborCommissionReadServices())->read($stores,['start'=>'2026-10-01','end'=>'2026-10-27']);
 $gaps=[];$amount=0;$complete=0;foreach($read['totals'] as $total){if($total['complete']){$complete++;$amount+=$total['amount_cents'];}else foreach($total['gaps'] as $gap)$gaps[$gap]=($gaps[$gap]??0)+1;}
 $sample=[];foreach($read['details'] as $id=>$detail)if(count($sample)<3&&$detail['amount_cents']!==null)$sample[]=['fact_id'=>$id,'project_id'=>$detail['project_id'],'basis_cents'=>$detail['basis_cents'],'threshold_cents'=>$detail['threshold_cents'],'rate_percent'=>$detail['rate_percent'],'amount_cents'=>$detail['amount_cents']];
 echo json_encode(['result'=>'PASS','mode'=>(int)sys_config('cashier_labor_fee_mode',0),'detail_count'=>count($read['details']),'employee_store_count'=>count($read['totals']),'complete_rows'=>$complete,'complete_total_cents'=>$amount,'gaps'=>$gaps,'sample'=>$sample,'elapsed_seconds'=>round(microtime(true)-$begin,3),'persistent_writes'=>0],JSON_UNESCAPED_UNICODE);
}finally{think\\facade\\Db::rollback();}
'''
p=subprocess.run(['ssh','-q','-o','BatchMode=yes','-o','ConnectTimeout=10','mohe-server','cd /www/wwwroot/010.cc3798.com && /www/server/php/74/bin/php'],input=body,text=True,capture_output=True,timeout=90)
if p.returncode:raise RuntimeError(p.stderr[-4000:]+p.stdout[-2000:])
data=json.loads(p.stdout);(e/'010-candidate-readonly.json').write_text(json.dumps(data,ensure_ascii=False,indent=2));print(json.dumps(data,ensure_ascii=False))
