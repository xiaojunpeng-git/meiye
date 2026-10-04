<?php
/** 本地事务回滚测试：同一冻结开关同步影响各报表客数，但不影响核销数量和金额。 */
if (getenv('R51_LOCAL_TEST') !== '1') throw new RuntimeException('explicit local flag required');
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require $backend . '/vendor/autoload.php';
$app = new \think\App($backend . '/'); $app->initialize();
use think\facade\Db;
use app\services\report\StoreUnifiedReportServices;
use app\services\report\StoreUnifiedReportPhaseThreeServices;
use app\services\report\StoreUnifiedReportPhaseFourServices;
use app\services\query\metric\ServiceCustomerEligibility;
use app\services\query\metric\ServiceCustomerMetricReadServices;
function unified51($ok, $message) { if (!$ok) throw new RuntimeException($message); echo "PASS $message\n"; }
$service = Db::name('cashier_v3_entitlement_service_fact')->where('service_status', 'completed')->find();
$payment = Db::name('cashier_v3_payment_fact')->where('status', 'effective')->where('amount_cents', '>', 0)->find();
$source = Db::name('cashier_v3_checkout_business_source_selection')->where('primary_source_id', '>', 0)->find();
$category = Db::name('store_product_category')->where('is_show', 1)->where('cate_name', '六维')->find();
unified51($service && $payment && $source && $category, 'local fixture prerequisites');
$store = (int)$service['store_id']; $date = '2026-09-26'; $member = 987654319;
$range = ['start'=>$date, 'end'=>$date]; $input = ['start_date'=>$date, 'end_date'=>$date, 'limit'=>100, '_internal_all'=>true];
$phase2 = new StoreUnifiedReportServices(); $phase3 = new StoreUnifiedReportPhaseThreeServices(); $phase4 = new StoreUnifiedReportPhaseFourServices();
$detail = static function ($code, $extra = []) use ($phase2, $store, $input) { return $phase2->query([$store], array_merge($input, ['report'=>$code], $extra)); };
$get = static function ($result, $key) use ($member) { foreach ($result['records'] as $r) if ((int)($r['member_id'] ?? 0) === $member) return (int)($r[$key] ?? 0); return 0; };
$annual = static function ($mode) use ($detail, $store) { foreach ($detail('member_visit_annual_summary', ['mode'=>$mode])['records'] as $r) if ($r['row_label'] === '9月') return (int)($r['store_'.$store] ?? 0); return 0; };
$experience = static function () use ($phase3, $store, $range, $input) { $n=0; foreach ($phase3->query('six_dimension_item_deal_analysis', [$store], $range, $input)['records'] as $r) $n+=(int)$r['experience_people']; return $n; };
$consult = static function () use ($phase4, $store, $range, $input) { $n=0; foreach ($phase4->query('six_dimension_analysis', [$store], $range, $input)['records'] as $r) $n+=(int)$r['total_visit_people']; return $n; };
$before = [$annual('count'), $annual('people'), $annual('project'), $experience(), $consult()];
Db::startTrans();
try {
    $random = bin2hex(random_bytes(12)); $checkout = 'CKR-U51-'.$random;
    unset($source['id']); $source['tenant_id']=$service['tenant_id']; $source['store_id']=$store; $source['checkout_request_id']=$checkout; $source['checkout_kind']='sale';
    Db::name('cashier_v3_checkout_business_source_selection')->insert($source);
    // 一笔现金事实只负责让现金会员进入会员进店表，不用页面临时构造人员名单。
    unset($payment['id']);
    foreach (['fact_id','natural_key','command_idempotency_key','business_event_no'] as $f) $payment[$f]='U51-pay-'.$random;
    $payment['immutable_fingerprint']=hash('sha256',$random.'pay'); $payment['store_id']=$store; $payment['tenant_id']=$service['tenant_id'];
    $payment['checkout_request_id']=$checkout; $payment['order_id']='U51-order-'.$random; $payment['member_id']=$member; $payment['member_name_snapshot']='U51事务夹具'; $payment['business_date']=$date;
    $payment['business_source_primary_id']=$source['primary_source_id']; $payment['amount_cents']=123456;
    Db::name('cashier_v3_payment_fact')->insert($payment);
    $ids=[];
    for ($i=0; $i<2; $i++) {
        $row=$service; unset($row['id']);
        foreach (['service_fact_id','natural_key','command_idempotency_key','business_event_no','source_line_id'] as $f) $row[$f]='U51-'.$random.'-'.$i;
        $row['immutable_fingerprint']=hash('sha256',$random.$i); $row['checkout_request_id']=$checkout; $row['service_record_no']='U51'.$random.$i;
        $row['member_id']=$member; $row['member_name_snapshot']='U51事务夹具'; $row['business_date']=$date;
        $row['project_id']=987654319; $row['project_name_snapshot']='U51六维体验'; $row['project_category_id_snapshot']=$category['id']; $row['project_category_path_snapshot']='六维';
        $row['quantity']=2; $row['is_experience']=1; $row['service_object']='friend'; $row['friend_counts_as_customer']=0;
        $ids[]=Db::name('cashier_v3_entitlement_service_fact')->insertGetId($row);
    }
    $money = $detail('market_detail');
    unified51($get($detail('market_detail'), 'visits')===0 && $get($detail('member_visit_analysis'), 'total_visits')===0, 'excluded friend: market and member visits zero');
    unified51($annual('count')===$before[0] && $annual('people')===$before[1], 'annual count/people share eligibility');
    unified51($annual('project')===$before[2]+4, 'actual project quantity retained');
    unified51($experience()===$before[3] && $consult()===$before[4], 'experience and consultation exclude friend');
    Db::name('cashier_v3_entitlement_service_fact')->where('id',$ids[0])->update(['friend_counts_as_customer'=>1]);
    $countedMarketVisits = $get($detail('market_detail'),'visits');
    $countedMemberVisits = $get($detail('member_visit_analysis'),'total_visits');
    // Market visits exclude experience services independently of the count flag.
    unified51($countedMarketVisits===0 && $countedMemberVisits===1, 'same frozen switch respects market experience exclusion: market='.$countedMarketVisits.' member='.$countedMemberVisits);
    unified51($annual('count')===$before[0]+1 && $annual('people')===$before[1]+1, 'annual reports enable same eligible service');
    unified51($experience()===$before[3]+1 && $consult()===$before[4]+1, 'experience and consultation enable same eligible service');
    Db::name('cashier_v3_entitlement_service_fact')->whereIn('id',$ids)->update(['service_object'=>'self','friend_counts_as_customer'=>0]);
    unified51($get($detail('member_visit_analysis'),'total_visits')===0 && $annual('count')===$before[0], 'self marked not counted excluded from member visits');
    unified51($experience()===$before[3] && $consult()===$before[4], 'self marked not counted excluded from experience/consultation');
    Db::name('cashier_v3_entitlement_service_fact')->whereIn('id',$ids)->update(['friend_counts_as_customer'=>1]);
    unified51($get($detail('member_visit_analysis'),'total_visits')===1 && $annual('count')===$before[0]+1, 'counted self multi-project member-day dedup preserved');
    $after=$detail('market_detail');
    unified51($after['summary_row']['amount']===$money['summary_row']['amount'], 'cash unchanged by customer eligibility');
    $export=$phase2->export([$store],array_merge($input,['report'=>'member_visit_analysis']));
    unified51($export['records']===$detail('member_visit_analysis')['records'], 'query/export same member-visit projection');
    $restricted=$phase3->query('six_dimension_item_deal_analysis',[999999999],$range,$input);
    unified51(empty($restricted['records']), 'store scope cannot leak fixture');
    unified51(!ServiceCustomerEligibility::allows(['service_object'=>'friend','friend_counts_as_customer'=>0]), 'central PHP eligibility');
    // 每种合法冻结状态验证 SQL 与投影资格完全一致，防止修一端漏另一端。
    foreach ([['self',0],['self',1],['friend',0],['friend',1]] as $state) {
        Db::name('cashier_v3_entitlement_service_fact')->whereIn('id',$ids)->update(['service_object'=>$state[0],'friend_counts_as_customer'=>$state[1]]);
        $sql = ServiceCustomerEligibility::apply(Db::name('cashier_v3_entitlement_service_fact')->alias('sv')->whereIn('sv.id',$ids), 'sv')->count();
        $php = count(ServiceCustomerEligibility::filter(Db::name('cashier_v3_entitlement_service_fact')->whereIn('id',$ids)->select()->toArray()));
        unified51((int)$sql===$php, 'SQL/PHP eligibility parity '.$state[0].'/'.$state[1]);
    }
    // 员工分配沿用十分之一单位与记录粒度，但客数资格由同一底层决定。
    foreach ([0,1] as $flag) {
        $factory=static function ($table) use ($flag,$store,$date,$member) {
            $rows=[['service_store_id'=>$store,'store_id'=>$store,'business_date'=>$date,'checkout_request_id'=>'U51-employee',
                'source_line_id'=>'U51-labor','service_fact_id'=>'U51-service','member_id'=>$member,'service_object'=>'FRIEND',
                'friend_counts_as_customer'=>$flag,'craftsmen_snapshot_json'=>'[]','labor_amount_cents'=>100,'labor_fee_amount_cents'=>10,
                'fact_id'=>'U51-performance','fact_direction'=>'forward','reversal_of'=>'','employee_id'=>987654319,
                'employee_name'=>'U51员工','role_snapshot'=>'craftsman:round']];
            return new class($rows) {
                private $rows;
                public function __construct(array $rows) { $this->rows=$rows; }
                public function __call($name,$args) { return $this; }
                public function select() { return $this; }
                public function toArray() { return $this->rows; }
            };
        };
        $metrics=(new ServiceCustomerMetricReadServices($factory,static function ($q,$alias): void {}))->employeeMetrics('0',[$store],$range);
        unified51((int)($metrics['summary_by_store_employee'][$store.'|987654319']['visit_tenths']??0)===$flag*10, 'employee allocation reuses eligibility '.$flag);
    }
} finally { Db::rollback(); }
echo "PASS unified R51 fixtures rolled back\n";
