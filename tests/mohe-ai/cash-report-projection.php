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
    final class MetricDefinitionRegistry {
        public static function get($code): array { return ['storage_unit'=>$code==='completed_service_item_count'?'count':'fen']; }
    }
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
    final class RegisteredMetricReadServices {
        private function cashRows($tenant, $stores, $range): array {
            $rows = array_merge(\think\facade\Db::$sales, GroupPerformanceMetricReadServices::$recharges);
            return array_values(array_filter($rows, static fn($r) => $r['tenant_id'] === $tenant
                && in_array($r['store_id'], $stores, true)
                && $r['business_date'] >= $range['start'] && $r['business_date'] <= $range['end']));
        }
        public function summary($metric, $tenant, $stores, $range): int {
            $rows = $this->cashRows($tenant, $stores, $range);
            if ($metric === 'cash_performance') return array_sum(array_map(static fn($r) => max(0, $r['amount_cents']), $rows));
            if ($metric === 'refund_performance') return array_sum(array_map(static fn($r) => max(0, -$r['amount_cents']), $rows));
            if ($metric === 'actual_performance') return array_sum(array_column($rows, 'amount_cents'));
            return 0;
        }
        public function dailyStoreTotals($metric, $tenant, $stores, $range): array {
            $points = [];
            foreach ($this->cashRows($tenant, $stores, $range) as $row) {
                $amount = $row['amount_cents'];
                if ($metric === 'cash_performance') $amount = max(0, $amount);
                elseif ($metric === 'refund_performance') $amount = max(0, -$amount);
                elseif ($metric !== 'actual_performance') $amount = 0;
                $key = $row['store_id'] . ':' . $row['business_date'];
                if (!isset($points[$key])) $points[$key] = ['store_id' => $row['store_id'], 'business_date' => $row['business_date'], 'amount_cents' => 0];
                $points[$key]['amount_cents'] += $amount;
            }
            return array_values($points);
        }
        public function dailyTotals($metric, $tenant, $stores, $range): array {
            $points=[];
            foreach($this->dailyStoreTotals($metric,$tenant,$stores,$range) as $row) {
                $day=$row['business_date']; $points[$day]=($points[$day]??0)+(int)$row['amount_cents'];
            }
            ksort($points); $out=[]; foreach($points as $day=>$value)$out[]=['business_date'=>$day,'metric_value'=>$value]; return $out;
        }
        public function categoryRows($metric, $tenant, $stores, $range, $categoryIds=[]): array {
            $rows=[];
            foreach($this->cashRows($tenant,$stores,$range) as $row) {
                $category=(int)($row['category_id']??0);
                if($categoryIds!==[] && !in_array($category,$categoryIds,true)) continue;
                $amount=(int)$row['amount_cents'];
                if($metric==='cash_performance' && $amount<=0) continue;
                if($metric==='refund_performance') { if($amount>=0) continue; $amount=-$amount; }
                if(!in_array($metric,['cash_performance','refund_performance','actual_performance'],true)) continue;
                $row['amount_cents']=$amount;
                if(($row['source_type']??'')==='card' && $category===0) $row['classification_coverage']='missing_card_components';
                $rows[]=$row;
            }
            return $rows;
        }
        public function categoryDashboardCards($metric, $tenant, $stores, $range, $roots, $children, $categoryIds = []): array {
            $rows = $this->categoryRows($metric, $tenant, $stores, $range, $categoryIds);
            $total = array_sum(array_column($rows, 'amount_cents')); $cards = []; $assigned = 0;
            foreach ($roots as $root) {
                $id = (int)$root['id'];
                $matched = array_values(array_filter($rows, static fn($row) => (int)($row['category_id'] ?? 0) === $id));
                $amount = array_sum(array_column($matched, 'amount_cents')); $assigned += $amount;
                $cards[] = ['category_id'=>$id,'name'=>(string)$root['name'],'cash_performance_cents'=>$amount,'share'=>$total===0?null:round($amount/$total*100,1),'drilldown'=>['metric_code'=>$metric,'category_id'=>$id],'project_rankings'=>[],'product_rankings'=>[],'source_explanation'=>'fixture'];
            }
            if ($total !== $assigned) $cards[] = ['category_id'=>0,'name'=>'未分类','cash_performance_cents'=>$total-$assigned,'share'=>$total===0?null:round(($total-$assigned)/$total*100,1),'drilldown'=>['metric_code'=>$metric],'project_rankings'=>[],'product_rankings'=>[],'source_explanation'=>'fixture'];
            return $cards;
        }
        public function categoryDailyTotals($metric, $tenant, $stores, $range, $categoryIds=[]): array {
            $points=[]; foreach($this->categoryRows($metric,$tenant,$stores,$range,$categoryIds) as $row) {
                $day=$row['business_date']; $points[$day]=($points[$day]??0)+(int)$row['amount_cents'];
            }
            ksort($points); $out=[]; foreach($points as $day=>$value)$out[]=['business_date'=>$day,'metric_value'=>$value]; return $out;
        }
    }
}
namespace app\services\metric {
    final class MetricDictionaryServices {
        public function getTooltip($code): array {
            $items=[
                'cash_performance'=>['现金业绩','成功记账收款总额，包含充值及充值欠款补交。'],
                'refund_performance'=>['退款业绩','成功退回的实际现金退款。'],
                'actual_performance'=>['实际业绩','现金业绩减退款业绩。'],
                'consume_amount'=>['消耗业绩','完成服务形成的消耗业绩。'],
                'completed_service_item_count'=>['完成服务项目数量','完成服务项目数量。'],
            ];
            $item=$items[$code]??[$code,$code];return ['name'=>$item[0],'summary'=>$item[1],'user_ready'=>true];
        }
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
    check(invoke($dashboard,'cashRows','test',[1,2],$range,[7])===[],'category filtered recharge stays excluded');
    check(invoke($dashboard,'actualCashTotal','test',[1,2],$range,[])===17000,'net cash total');
    check(invoke($dashboard,'dailyCashPerformance','test',[1,2],$range,[])===['2026-09-08'=>17000],'daily trend signed amount');
    check(invoke($dashboard,'cashByStore','test',[1,2],$range,[])===[1=>12000,2=>5000],'store ranking source');
    foreach(['cash_performance'=>18000,'refund_performance'=>1000,'actual_performance'=>17000] as $metric=>$expected) {
        $result=$dashboard->drilldown($ctx,$input+['metric_code'=>$metric]);
        check(array_sum(array_column($result['records'],'amount_cents'))===$expected,$metric.' drill total');
    }
    $sale=row('sale:1',2000); $sale['source_type']='product'; $sale['category_id']=7; \think\facade\Db::$sales=[$sale];
    check(array_sum(array_column(invoke($dashboard,'cashRows','test',[1,2],$range,[]),'amount_cents'))===19000,'mixed sales and recharge counted once');
    check(array_sum(array_column(invoke($dashboard,'cashRows','test',[1,2],$range,[7]),'amount_cents'))===2000,'category query retains only assigned sale');
    $summary=$dashboard->dashboard($ctx,$input+['summary_only'=>true]);
    $overview=$dashboard->dashboard($ctx,$input);
    $values=static fn($r)=>array_column($r['cards'],'value_cents','metric_code');
    foreach(['cash_performance'=>20000,'refund_performance'=>1000,'actual_performance'=>19000] as $metric=>$expected) {
        check($values($summary)[$metric]===$expected,$metric.' scalar summary not double added');
        check($values($overview)[$metric]===$expected,$metric.' full overview matches scalar');
    }
    check($summary['metric_version']===Dashboard::METRIC_VERSION,'summary version propagated');
    check(strpos($overview['field_explanations']['cash_performance'],'充值欠款补交')!==false,'business explanation includes recharge repayment');
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
    check(strpos(file_get_contents(dirname(__DIR__,2).'/后端代码/app/services/query/metric/RegisteredMetricReadServices.php'), 'private function allocate')!==false,
        'card allocation belongs to the registered Reader rather than the dashboard');
    echo "cash report projection: {$checks} PASS\n";
}
