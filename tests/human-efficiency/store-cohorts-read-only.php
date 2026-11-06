<?php
/** 本地真实数据验证：独立核对注册指标和月活客，禁止客户库或业务写入。 */
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();
use think\facade\Db;
use app\services\report\HumanEfficiencyDashboardServices as Dashboard;
use app\services\report\HumanEfficiencyStoreCohortServices as Cohorts;
use app\services\report\HumanEfficiencyFactReader as Reader;
use app\services\query\metric\RegisteredMetricReadServices as Registered;
use app\services\query\metric\StoreCustomerVisitEligibility as Visits;
if(config('database.connections.mysql.hostname')!=='mysql'||config('database.connections.mysql.database')!=='ruihao')throw new RuntimeException('Local Docker only');
function verifyC($ok, string $label): void { if (!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
Db::execute('SET TRANSACTION READ ONLY');Db::startTrans();
try {
 $stores=[23,24];$range=['start'=>'2026-10-01','end'=>'2026-11-05'];$at=microtime(true);
 $service=new Dashboard();
 $report=$service->read($stores,$range);$queryMs=round((microtime(true)-$at)*1000);
 $export=$service->read($stores,$range,['action'=>'export']);$cohort=$report['store_cohorts'];
 verifyC($cohort===$export['store_cohorts'],'查询导出完整分级、列、合计、口径完全一致');
 verifyC(array_sum(array_column($cohort['rows'],'stores'))===2 && $cohort['total']['stores']===2,'四类互斥覆盖授权院店');
 $registered=Registered::withCurrentReportProductCategory();
 verifyC($cohort['total']['cash_cents']===$registered->summary('cash_performance','0',$stores,$range),'整店现金与统一注册指标对账');
 verifyC($cohort['total']['consume_cents']===$registered->summary('consume_amount','0',$stores,$range),'消耗与统一注册指标对账');
 $reader=new Reader();$monthly=[];$visits=[];
 foreach($reader->services($stores,$range) as $f){$v=Visits::dailyVisitKey($f);if($v===null)continue;$visits[$v]=true;if((int)$f['member_id']>0)$monthly[$f['store_id'].':'.substr($f['business_date'],0,7).':'.$f['member_id']]=true;}
 verifyC($cohort['total']['active_month_count']===count($monthly),'月活客按门店月份会员去重');
 verifyC($cohort['total']['visits']===count($visits),'售后人次沿用门店会员日资格');
 verifyC(abs(array_sum($cohort['total']['category_values'])-$cohort['total']['average_cash'])<0.00001,'分类均值与总业绩均值守恒');
 verifyC($cohort['summary']['with_leader']+$cohort['summary']['without_leader']===2,'有无店长数量覆盖完整范围');
 // 同一服务对象连续换范围，验证请求内复用不会把前一次门店/权限结果带到下一次。
 $single=$service->read([23],$range)['store_cohorts'];
 verifyC($single['total']['stores']===1 && $single['total']['cash_cents']===$registered->summary('cash_performance','0',[23],$range),'单门店切换不残留其他店业绩');
 $restricted=$service->read([23],$range,['_employee_id'=>100000001]);
 verifyC($restricted['store_cohorts']['available']===false && !isset($restricted['store_cohorts']['summary']),'个人权限没有整店数据');
 $restrictedExport=$service->read([23],$range,['_employee_id'=>100000001,'action'=>'export']);
 verifyC($restrictedExport['store_cohorts']===$restricted['store_cohorts'],'个人权限导出不扩大范围');
 $old=$service->read([23],['start'=>'1990-01-01','end'=>'1990-01-31'])['store_cohorts'];
 verifyC($old['total']['cash_cents']===0 && $old['summary']['with_leader']===0 && $old['total']['per_beauty_active']===null,'历史空业务与无人均分母处理正确');
 // UI验证读取本次真实后端结果，不把夹具替换当成登录/API权限验证。
 if(getenv('COHORT_EVIDENCE')){
  file_put_contents(getenv('COHORT_EVIDENCE'),json_encode(['query'=>$report,'export'=>$export,'single'=>$single,'query_ms'=>$queryMs],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
 }
 echo json_encode(['query_ms'=>$queryMs,'stores'=>$stores,'range'=>$range,'summary'=>$cohort['summary'],'total'=>$cohort['total']],JSON_UNESCAPED_UNICODE),"\n";
} finally { Db::rollback(); }
