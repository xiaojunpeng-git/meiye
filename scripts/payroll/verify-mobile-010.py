"""010只读服务回归：使用已有授权身份执行真实权限检查，不签发登录会话或写业务数据。"""
from pathlib import Path
import subprocess,json

root=Path(__file__).resolve().parents[2]
php=r'''<?php
chdir('/www/wwwroot/010.cc3798.com');require 'vendor/autoload.php';$app=new think\App();$app->initialize();
set_exception_handler(function($e){file_put_contents('php://stderr',get_class($e).': '.$e->getMessage());exit(1);});
think\facade\Db::execute('SET SESSION TRANSACTION READ ONLY');
$auth=think\facade\Db::name('employee_mobile_auth')->where('employee_id',84)->where('status',1)->where('is_del',0)->field('employee_id,rules')->find();
if(!$auth || !in_array('401007',explode(',',$auth['rules']),true))throw new RuntimeException('Known authorized test actor unavailable');
$store=(int)think\facade\Db::name('system_store_staff')->where('employee_id',84)->where('status',1)->where('is_del',0)->value('store_id');
$actor=['employeeId'=>84,'storeId'=>$store];$input=['employeeId'=>84,'periodMode'=>'month','month'=>'2026-10','detailMode'=>'labor','page'=>1,'pageSize'=>20];
$reader=$app->make(app\services\mobile\warehouse\MobileWarehouseServices::class);
$a=$reader->employeePerformance($actor,$input)['employeePerformance'];
$b=$reader->employeePerformance($actor,array_replace($input,['page'=>2]))['employeePerformance'];
if($a['laborAlgorithm']['code']!=='project_tiers' || count($a['metrics'])!==8)throw new RuntimeException('Calculated mode missing');
// 分页总数必须始终是明细条数，不能被提成分组数组覆盖；第二页不能重复第一页。
if(!is_int($a['pagination']['total']) || $a['pagination']['total']!==17 || $a['pagination']['hasMore']!==false || $b['details']!==[] || $b['pagination']['total']!==17)throw new RuntimeException('Mobile pagination contract failed');
$first=$a['details'][0];
if($first['commissionDetail']['basis_cents']!==6000 || $first['commissionDetail']['rate_percent']!=='28' || $first['commissionDetail']['amount_cents']!==1680 || $first['displayLaborFee']!=='0')throw new RuntimeException('Known project preview or original fee changed');
$reportInput=['start_date'=>'2026-10-01','end_date'=>'2026-10-31','as_of_date'=>'2026-10-27','_report_scope'=>['mode'=>'stores']];
$salary=(new app\services\report\BasicSalaryReportServices())->read('basic_salary_realtime',[$store],$reportInput);
$summary=(new app\services\report\BasicActivityReportServices())->read('basic_employee_summary',[$store],$reportInput);
$matching=[];foreach(['salary'=>$salary,'summary'=>$summary] as $name=>$result)foreach($result['rows'] as $row)if((int)$row['employee_id']===84)$matching[$name]=$row;
if(count($matching)!==2 || $matching['salary']['work_get']!==627.2 || $matching['summary']['labor_commission']!==627.2)throw new RuntimeException('Cross-terminal commission mismatch');
echo json_encode(['result'=>'PASS','actor_employee_id'=>84,'service_authorization_checked'=>true,'algorithm'=>$a['laborAlgorithm'],'metrics'=>$a['metrics'],'commission'=>$a['commission'],'first_detail'=>$first,'pagination'=>$a['pagination'],'page2_count'=>count($b['details']),'platform_mobile_commission_yuan'=>627.2,'business_writes'=>0],JSON_UNESCAPED_UNICODE);
'''
result=subprocess.run(['ssh','-q','-o','BatchMode=yes','-o','ConnectTimeout=10','mohe-server','/www/server/php/74/bin/php'],input=php,text=True,capture_output=True,timeout=100)
if result.returncode:raise RuntimeError(result.stderr[-2000:]+result.stdout[-1000:])
data=json.loads(result.stdout)
(root/'tests/customer-010/evidence/round6-payroll-algorithm/010-mobile-service.json').write_text(json.dumps(data,ensure_ascii=False,indent=2))
print(json.dumps(data,ensure_ascii=False))
