<?php
declare(strict_types=1);

// Pure projection fixture. No framework bootstrap, .env, database or model.
namespace think\facade {
    final class Db {
        public static array $sales = [];
        public static array $components = [];
        public static function name($name) { return new \CashProjectionQuery($name); }
    }
}
namespace app\services\report {
    class StoreUnifiedReportOrganizationDimensionServices { public function project(&$row, ...$args) { $row['company']='公司'; $row['city_manager']='经理'; } }
    class GroupManagementDashboardTargetServices {
        public function totals(...$args) { return [1=>100000,2=>100000]; }
        public function yearTargets(...$args) { return []; }
    }
    class GroupManagementDashboardDailyAggregateServices {
        public function status(...$args) { return ['aggregation_caught_up'=>false]; }
    }
    class StoreReportNormalDataScopeServices {
        public function excludeVoidedSalesOrderFacts(...$args) {}
        public function excludeVoidedSalesOrderServices(...$args) {}
    }
}
namespace app\services\query\metric {
    final class GroupPerformanceMetricReadServices {
        public static array $recharges = [];
        public static int $calls = 0;
        public function rechargeCashRows($tenant, $stores, $range): array {
            self::$calls++;
            return array_values(array_filter(self::$recharges, static fn($r)=>$r['tenant_id']===$tenant && in_array($r['store_id'],$stores,true) && $r['business_date'] >= $range['start'] && $r['business_date'] <= $range['end']));
        }
        public function cashTotals($tenant,$stores,$range): array {
            $rows=array_merge(\think\facade\Db::$sales,$this->rechargeCashRows($tenant,$stores,$range));
            $out=['gross_cents'=>0,'refund_cents'=>0];
            foreach($rows as $r) if($r['tenant_id']===$tenant && in_array($r['store_id'],$stores,true) && $r['business_date'] >= $range['start'] && $r['business_date'] <= $range['end']) $out[$r['amount_cents']>0?'gross_cents':'refund_cents']+=$r['amount_cents'];
            return $out;
        }
        public function performanceTotal(...$args): int { return 0; }
    }
}
namespace {
    final class CashProjectionQuery {
        private string $table;
        private array $filters=[];
        public function __construct($table) { $this->table=$table; }
        public function __call($name,$args) { return $this; }
        public function where($key,$operator,$value=null) { $this->filters[]=[$key,$value===null?'=':$operator,$value??$operator]; return $this; }
        public function whereIn($key,$value) { $this->filters[]=[$key,'in',$value]; return $this; }
        public function whereBetween($key,$value) { $this->filters[]=[$key,'between',$value]; return $this; }
        public function select() { return $this; }
        public function column(...$args) { return [1=>'一店',2=>'二店']; }
        public function toArray():array {
            if($this->table==='store_product_category') return [['id'=>7,'pid'=>0,'cate_name'=>'服务']];
            if(!in_array($this->table,['cashier_v3_payment_sale_allocation_fact','cashier_v3_card_sale_item_allocation_fact'],true)) return [];
            $source=$this->table==='cashier_v3_card_sale_item_allocation_fact'?\think\facade\Db::$components:\think\facade\Db::$sales;
            return array_values(array_filter($source,function($r) {
                foreach($this->filters as [$key,$op,$v]) {
                    $key=preg_replace('/^[^.]+\./','',$key);
                    if($key==='category_id_snapshot') $key='category_id';
                    $actual=$r[$key]??null;
                    if(($op==='=' && $actual!==$v) || ($op==='<>' && $actual===$v) || ($op==='in' && !in_array($actual,$v,true)) || ($op==='between' && ($actual<$v[0] || $actual>$v[1]))) return false;
                }
                return true;
            }));
        }
    }
    require dirname(__DIR__,2).'/后端代码/app/services/report/GroupManagementDashboardServices.php';
    use app\services\report\GroupManagementDashboardServices as Dashboard;
    use app\services\query\metric\GroupPerformanceMetricReadServices as Reader;
    $checks=0;
    function check($ok,$label):void { global $checks; $checks++; if(!$ok) throw new \RuntimeException($label); }
    function invoke($object,$name,...$args) { $method=new \ReflectionMethod($object,$name); if (PHP_VERSION_ID<80100) $method->setAccessible(true); return $method->invoke($object,...$args); }
    function row($id,$amount,$store=1,$date='2026-09-08',$tenant='test'):array { return ['id'=>$id,'tenant_id'=>$tenant,'store_id'=>$store,'business_date'=>$date,'amount_cents'=>$amount,'category_id'=>0,'item_name'=>'充值','source_type'=>'recharge','status'=>'effective','member_id'=>1,'order_id'=>$id,'business_source_primary_id'=>0]; }
    Reader::$recharges=[row('cash:1',10000),row('cash:2',3000),row('cash:3',-1000),row('cash:4',5000,2),row('cash:5',9900,3),row('cash:6',8800,1,'2026-09-07'),row('cash:7',7700,1,'2026-09-08','other')];
    $dashboard=new Dashboard(); $range=['start'=>'2026-09-08','end'=>'2026-09-08'];
    $ctx=['tenant_id'=>'test','store_ids'=>[1,2]]; $input=['start_date'=>$range['start'],'end_date'=>$range['end']];
    $rows=invoke($dashboard,'cashRows','test',[1,2],$range,[]);
    check(count($rows)===4,'no sale/card rows must still include recharge and debt repayment');
    check(array_sum(array_column($rows,'amount_cents'))===17000,'signed net excludes out-of-scope date/store/tenant');
    check(count(array_unique(array_column($rows,'id')))===4,'stable distinct source row identities');
    $before=Reader::$calls;
    check(invoke($dashboard,'cashRows','test',[1,2],$range,[7])===[],'category filtered recharge stays excluded');
    check(Reader::$calls===$before,'category filter never reads recharge');
    check(invoke($dashboard,'actualCashTotal','test',[1,2],$range,[])===17000,'net cash total');
    check(invoke($dashboard,'dailyCashPerformance','test',[1,2],$range,[])===['2026-09-08'=>17000],'daily trend signed amount');
    check(invoke($dashboard,'cashByStore','test',[1,2],$range,[])===[1=>12000,2=>5000],'store ranking source');
    foreach(['cash_performance'=>18000,'refund_amount'=>-1000,'actual_performance'=>17000] as $metric=>$expected) {
        $result=$dashboard->drilldown($ctx,$input+['metric_code'=>$metric]);
        check(array_sum(array_column($result['records'],'amount_cents'))===$expected,$metric.' drill total');
    }
    $sale=row('sale:1',2000); $sale['source_type']='product'; $sale['category_id']=7; \think\facade\Db::$sales=[$sale];
    check(array_sum(array_column(invoke($dashboard,'cashRows','test',[1,2],$range,[]),'amount_cents'))===19000,'mixed sales and recharge counted once');
    check(array_sum(array_column(invoke($dashboard,'cashRows','test',[1,2],$range,[7]),'amount_cents'))===2000,'category query retains only assigned sale');
    $summary=$dashboard->dashboard($ctx,$input+['summary_only'=>true]);
    $overview=$dashboard->dashboard($ctx,$input);
    $values=static fn($r)=>array_column($r['cards'],'value_cents','metric_code');
    foreach(['cash_performance'=>20000,'refund_amount'=>1000,'actual_performance'=>19000] as $metric=>$expected) {
        check($values($summary)[$metric]===$expected,$metric.' scalar summary not double added');
        check($values($overview)[$metric]===$expected,$metric.' full overview matches scalar');
    }
    check($summary['metric_version']===Dashboard::METRIC_VERSION,'summary version propagated');
    check(str_contains($overview['field_explanations']['cash_performance'],'充值欠款补交'),'business explanation includes recharge repayment');
    check(array_sum(array_column($overview['categories'],'cash_performance_cents'))===20000,'positive category projections preserve total including unclassified recharge');
    $restricted=$dashboard->drilldown($ctx,$input+['store_ids'=>'2','metric_code'=>'cash_performance']);
    check(array_sum(array_column($restricted['records'],'amount_cents'))===5000,'requested store only narrows permission');
    // A successful card receipt remains authoritative even when its legacy
    // category projection is missing. No fact repair or invented category.
    Reader::$recharges=[];
    $card=row('card:missing',210000);$card['source_type']='card';$card['sale_fact_id']='sale:missing';
    \think\facade\Db::$sales=[$card];
    $missing=invoke($dashboard,'cashRows','test',[1,2],$range,[]);
    check(count($missing)===1 && $missing[0]['amount_cents']===210000,'missing card components cannot erase receipt');
    check($missing[0]['category_id']===0 && $missing[0]['classification_coverage']==='missing_card_components','missing classification explicitly marked not invented');
    check(invoke($dashboard,'cashRows','test',[1,2],$range,[7])===[],'unknown card classification does not satisfy category filter');
    check(invoke($dashboard,'dailyCashPerformance','test',[1,2],$range,[])===['2026-09-08'=>210000],'missing card daily total retains cash');
    check(invoke($dashboard,'cashByStore','test',[1,2],$range,[])===[1=>210000],'missing card store total retains cash');
    check($values($dashboard->dashboard($ctx,$input))['cash_performance']===210000,'missing card overview matches scalar');
    $weights=[['configured_amount_cents'=>12000],['configured_amount_cents'=>0],['configured_amount_cents'=>0]];
    foreach([298000,-298000] as $amount) {
        $parts=array_column(invoke($dashboard,'allocate',$amount,$weights),'amount_cents');
        check($parts===[$amount,0,0],'zero weight cannot create opposite signed refund/receipt');
        check(array_sum($parts)===$amount,'signed allocation preserves cents');
    }
    check(array_column(invoke($dashboard,'allocate',101,[['configured_amount_cents'=>0],['configured_amount_cents'=>0],['configured_amount_cents'=>0]]),'amount_cents')===[33,33,35],'all zero weights use equal allocation with last remainder');
    check(array_column(invoke($dashboard,'allocate',101,[['configured_amount_cents'=>1],['configured_amount_cents'=>2]]),'amount_cents')===[33,68],'normal weighted exact remainder unchanged');
    foreach([101,-101] as $amount) {
        $parts=array_column(invoke($dashboard,'allocate',$amount,[['configured_amount_cents'=>1],['configured_amount_cents'=>1],['configured_amount_cents'=>0]]),'amount_cents');
        check($parts===($amount>0?[50,51,0]:[-50,-51,0]),'trailing zero weight receives no positive or negative remainder');
        check(array_sum($parts)===$amount,'last positive weight remainder preserves signed total');
    }
    $card['amount_cents']=298000;$card['sale_fact_id']='sale:configured';\think\facade\Db::$sales=[$card];
    foreach($weights as $i=>$w) \think\facade\Db::$components[]=array_merge($w,['tenant_id'=>'test','sale_fact_id'=>'sale:configured','status'=>'effective','component_product_id'=>$i+1,'item_name_snapshot'=>'项目','category_id'=>7,'category_id_snapshot'=>7,'category_path_snapshot'=>'服务']);
    $full=$values($dashboard->dashboard($ctx,$input));
    check($full['cash_performance']===298000 && $full['refund_amount']===0,'positive configured card creates no false refund');
    check(array_sum(array_column($dashboard->drilldown($ctx,$input+['metric_code'=>'cash_performance'])['records'],'amount_cents'))===298000,'configured card cash drilldown reconciles');
    check($dashboard->drilldown($ctx,$input+['metric_code'=>'refund_amount'])['records']===[],'refund drilldown empty without negative source');
    echo "cash report projection: {$checks} PASS\n";
}
