<?php
/** R51 Host -> registered Provider -> real UnifiedQueryExecutionServices, with isolated local MySQL fixtures. */
require (getenv('BACKEND_ROOT') ?: getcwd()).'/vendor/autoload.php';
(new \think\App())->initialize();
use think\facade\Db;
use app\services\report\BasicReportHostServices as Host;
use app\services\report\BasicActivityReportServices as Activity;

set_exception_handler(static function ($e) { file_put_contents('php://stderr',(string)$e.PHP_EOL); exit(1); });
if (getenv('R51_LOCAL_TEST') !== '1' || !in_array(config('database.connections.mysql.hostname'),['mysql','mohe-mysql','localhost','127.0.0.1'],true)) {
    throw new RuntimeException('Explicit local-only integration environment required');
}
$checks = 0;
function host51check($condition, string $label): void {
    global $checks;
    if (!$condition) throw new RuntimeException($label);
    ++$checks;
}
function host51near($left, $right, string $label): void { host51check(abs((float)$left-(float)$right)<0.000001,$label); }
function host51reject(callable $call, string $label): void {
    try { $call(); } catch (\InvalidArgumentException $e) { host51check(true,$label); return; }
    catch (\app\services\query\UnifiedQueryException $e) { host51check(true,$label); return; }
    throw new RuntimeException('Expected rejection: '.$label);
}

$original = (string)Db::query('SELECT DATABASE() name')[0]['name'];
if (!preg_match('/^[a-zA-Z0-9_]+$/D',$original)) throw new RuntimeException('Unexpected original database name');
$isolated = 'r51_activity_host_test_'.bin2hex(random_bytes(6));
$created = false; $schemas = []; $day = '2026-10-20';
try {
    // Real schemas, no copied business data. Only this random database receives fixture writes.
    Db::execute('CREATE DATABASE `'.$isolated.'` CHARACTER SET utf8mb4'); $created = true;
    foreach (Db::query('SHOW TABLES') as $entry) {
        $table = (string)reset($entry);
        if (preg_match('/^eb_(cashier_v3_|cash_source|store_product|store_order|system_store|system_business_product|staff_job_position|position)/',$table)) {
            Db::execute('CREATE TABLE `'.$isolated.'`.`'.$table.'` LIKE `'.$original.'`.`'.$table.'`');
        }
    }
    Db::execute('USE `'.$isolated.'`');
    /** Fill schema-required fixture columns only, leaving generated/auto columns to MySQL. */
    $insert = static function (string $table,array $values) use (&$schemas,$day): void {
        if (!isset($schemas[$table])) $schemas[$table] = Db::query('SHOW COLUMNS FROM `eb_'.$table.'`');
        $row = [];
        foreach ($schemas[$table] as $column) {
            if (strpos($column['Extra'],'auto_increment')!==false || strpos($column['Extra'],'GENERATED')!==false) continue;
            $field = $column['Field'];
            if ($column['Default']!==null) $row[$field] = $column['Default'];
            elseif ($column['Null']==='YES') $row[$field] = null;
            elseif (preg_match('/int|decimal|float|double/',$column['Type'])) $row[$field] = 0;
            elseif (strpos($column['Type'],'date')!==false) $row[$field] = $day;
            else $row[$field] = '';
        }
        $token = bin2hex(random_bytes(12));
        foreach (['natural_key','command_idempotency_key','immutable_fingerprint','business_event_no','unique'] as $field) if (array_key_exists($field,$row)) $row[$field] = $token;
        $allowed = array_fill_keys(array_column($schemas[$table],'Field'),true);
        Db::name($table)->insert(array_replace($row,array_intersect_key($values,$allowed)));
    };
    foreach ([901=>'执行门店',902=>'当前所属门店',903=>'无权门店'] as $id=>$name) $insert('system_store',['id'=>$id,'name'=>$name]);
    foreach ([61=>'技师',62=>'顾问'] as $id=>$name) $insert('position',['id'=>$id,'name'=>$name,'status'=>1]);
    foreach ([71=>'高级',72=>'初级'] as $id=>$name) $insert('position_level',['id'=>$id,'name'=>$name,'status'=>1]);
    $insert('store_product_category',['id'=>70,'pid'=>0,'type'=>0,'relation_id'=>0,'cate_name'=>'当前分类']);
    foreach ([600=>6,601=>0,602=>5] as $id=>$type) {
        $insert('store_product',['id'=>$id,'pid'=>0,'type'=>0,'relation_id'=>0,'cate_id'=>'70','product_type'=>$type]);
        $insert('store_product',['id'=>$id+100,'pid'=>$id,'type'=>1,'relation_id'=>901,'cate_id'=>'70','product_type'=>$type]);
    }
    for ($i=0;$i<45;++$i) {
        $employee = 100+$i; $staff = 500+$i; $line = 'host-line-'.$i; $order = 'host-order-'.$i;
        $kind = $i<15?'product':($i<30?'project':'card');
        $product = ['product'=>701,'project'=>700,'card'=>702][$kind];
        $base = ['tenant_id'=>'0','store_id'=>901,'store_name_snapshot'=>'历史执行门店','member_id'=>200+$i,
            'member_name_snapshot'=>'客户'.$i,'business_date'=>$day,'business_timezone'=>'Asia/Shanghai',
            'status'=>'effective','fact_direction'=>'forward','organization_id'=>'0','organization_path_snapshot'=>'/',
            'checkout_request_id'=>'host-checkout-'.$i,'order_id'=>$order,'order_no_snapshot'=>'SALE-HOST-'.$i,
            'source_document_type'=>'cashier_snapshot'];
        $insert('system_store_staff',['id'=>$staff,'employee_id'=>$employee,'store_id'=>902,'staff_name'=>'跟单'.$i,'status'=>1,'is_del'=>0,'position_level'=>$i%2===0?71:72]);
        $insert('staff_job_position',['id'=>300+$i,'employee_id'=>$employee,'store_id'=>902,'staff_id'=>$staff,
            'position_id'=>$i%2===0?61:62,'status'=>1,'is_del'=>0,'end_time'=>0,'request_token'=>'host-job-'.$i]);
        $insert('cashier_v3_sale_fact',$base+['fact_id'=>'host-sale-'.$i,'source_line_id'=>$line,'source_type'=>$kind,
            'item_id'=>$product,'item_name_snapshot'=>'历史'.$kind,'quantity'=>1,'sale_amount_cents'=>10001]);
        $insert('cashier_v3_report_sale_dimension_fact',['tenant_id'=>'0','sale_fact_id'=>'host-sale-'.$i,'item_id'=>$product,
            'item_name_snapshot'=>'历史'.$kind,'product_type_snapshot'=>$kind,'category_id_snapshot'=>99,'category_path_snapshot'=>'过时分类']);
        $insert('cashier_v3_payment_sale_allocation_fact',$base+['allocation_fact_id'=>'host-payment-'.$i,'payment_fact_id'=>'host-pay-'.$i,
            'source_line_id'=>$line,'sale_fact_id'=>'host-sale-'.$i,'payment_method'=>'cash','amount_cents'=>8001]);
        $insert('cashier_v3_performance_fact',$base+['fact_id'=>'host-sales-person-'.$i,'source_line_id'=>$line,
            'performance_type'=>'sales_performance_allocated','employee_id'=>$employee,'employee_name_snapshot'=>'历史员工'.$i,
            'amount_cents'=>8001,'allocation_weight_numerator'=>1,'allocation_weight_denominator'=>1]);
        $insert('cashier_v3_sales_order_line',['tenant_id'=>'0','store_id'=>901,'order_id'=>$order,'order_line_id'=>'host-ol-'.$i,
            'checkout_line_id'=>$line,'line_direction'=>'forward','item_type'=>$kind,'item_id'=>$product]);
        $insert('cashier_v3_order_balance_allocation',['tenant_id'=>'0','store_id'=>901,'order_id'=>$order,'order_line_id'=>'host-ol-'.$i,'amount_cents'=>2000]);
        if ($kind==='card') {
            $insert('cashier_v3_card_sale_category_allocation_fact',$base+['allocation_fact_id'=>'host-component-'.$i,
                'card_receipt_id'=>'host-receipt-'.$i,'sale_fact_id'=>'host-sale-'.$i,'component_product_id'=>700,'component_count'=>1,'sale_amount_cents'=>10001,'product_type_snapshot'=>'project']);
            $insert('store_order',['id'=>1000+$i,'store_id'=>901,'uid'=>200+$i,'order_id'=>'OLD-HOST-'.$i,'is_gendan'=>1,'gendan_staff_id'=>$staff,'pay_price'=>999999]);
            $insert('store_order_cart_info',['id'=>2000+$i,'oid'=>1000+$i,'cart_type'=>0]);
            $insert('cashier_v3_card_purchase_receipt',['receipt_id'=>'host-receipt-'.$i,'tenant_id'=>'0','store_id'=>901,'member_id'=>200+$i,
                'sales_order_id'=>$order,'sales_order_line_id'=>'host-ol-'.$i,'issue_no'=>1,'legacy_order_id'=>1000+$i,'base_cart_id'=>2000+$i,'card_holder_id'=>3000+$i,'status'=>'completed']);
        }
        $serviceBase = $base; unset($serviceBase['order_id'],$serviceBase['order_no_snapshot'],$serviceBase['status'],$serviceBase['fact_direction']);
        $insert('cashier_v3_entitlement_service_fact',$serviceBase+['service_fact_id'=>'host-service-'.$i,'source_line_id'=>'host-service-line-'.$i,
            'project_id'=>700,'project_name_snapshot'=>'历史服务项目','service_status'=>'completed','quantity'=>2,
            'document_no_snapshot'=>'SERVICE-HOST-'.$i,'service_object'=>'self','friend_counts_as_customer'=>1,'craftsmen_snapshot_json'=>'[]']);
        $insert('cashier_v3_performance_fact',$base+['fact_id'=>'host-consumption-'.$i,'source_line_id'=>'host-service-line-'.$i,
            'performance_type'=>'consumption_performance_recorded','amount_cents'=>10001]);
        $insert('cashier_v3_performance_fact',$base+['fact_id'=>'host-labor-'.$i,'source_line_id'=>'host-service-line-'.$i,
            'performance_type'=>'labor_performance_allocated','employee_id'=>$employee,'employee_name_snapshot'=>'历史员工'.$i,
            'amount_cents'=>8001,'labor_fee_amount_cents'=>105,'project_count_decimal'=>'1.5','role_snapshot'=>$i%2===0?'craftsman:point':'craftsman:round']);
    }
    // Unrelated effective rows prove that the full sums are authorized sums, not all-store sums.
    $insert('cashier_v3_performance_fact',['tenant_id'=>'0','store_id'=>903,'business_date'=>$day,'fact_id'=>'host-foreign',
        'order_id'=>'foreign','source_line_id'=>'foreign','status'=>'effective','fact_direction'=>'forward',
        'performance_type'=>'sales_performance_allocated','employee_id'=>999,'amount_cents'=>999999]);

    $input = ['start_date'=>$day,'end_date'=>$day,'as_of_date'=>$day];
    $totals = [];
    foreach (Activity::codes() as $code) {
        // 服务店与业绩明细按查询时本店任职；夹具原属店 902，逐表模拟现任 901。
        if (in_array($code,['basic_employee_service_store','basic_employee_performance_detail'],true))
            Db::name('system_store_staff')->where('store_id',902)->update(['store_id'=>901]);
        $context = Host::context([901,902],'stores',999901,'platform',false,0,$code);
        $query = static function (array $extra = []) use ($context,$input,$code) {
            return Host::query($context,array_replace($input,['pageCode'=>$code],$extra));
        };
        $first = $query(); $total = (int)$first['pagination']['total']; $totals[$code] = $total;
        if (in_array($code,['basic_employee_item_sales','basic_employee_service_store','basic_employee_service_home_store'],true)) {
            $visible=array_values(array_filter($first['queryFields'],static function($field){return $field['defaultVisible'];}));
            host51check(array_column($visible,'label')===($code==='basic_employee_item_sales'
                ? ['门店','员工','项目','销售数量'] : ['门店','员工','项目','项目数']),
                '007 four-column employee item layout '.$code);
            host51check($first['table_layout']['summary_position']==='none' && $visible[3]['display_precision']===2
                && $visible[3]['display_min_precision']===2,'No false total and fixed two-decimal count '.$code);
        }
        if ($code==='basic_employee_performance_detail') {
            $visible=array_values(array_filter($first['queryFields'],static function($field){return $field['defaultVisible'];}));
            host51check(array_column($visible,'label')===['门店','员工','项目','分类','现金业绩','订单号','销售日期'],
                '007 seven-column performance detail layout');
            host51check($first['table_layout']['summary_position']==='none','007 performance detail has no total row');
        }
        host51check($total>0,'Real Runtime has fixture data: '.$code);
        host51check(count($first['rows'])===min(20,$total),'First page fixed at 20: '.$code);
        host51check($first['pagination']['limit']===20,'Provider uses ordinary-list page size: '.$code);
        $rows = [];
        for ($page=1;$page<=(int)ceil($total/20);++$page) {
            $part = $page===1?$first:$query(['page'=>$page]);
            host51check((int)$part['pagination']['total']===$total,'Complete total stable across pages: '.$code);
            $rows = array_merge($rows,$part['rows']);
        }
        host51check(count($rows)===$total && count(array_unique(array_column($rows,'row_id')))===$total,'All pages exact and nonoverlapping: '.$code);
        $last = $query(['page'=>(int)ceil($total/20)]);
        host51check(count($last['rows'])===($total%20?:20),'Last page remainder: '.$code);
        $metricFields = array_column(array_filter($first['queryFields'],static function ($field) { return $field['summable']; }),'key');
        foreach ($metricFields as $metric) host51near($first['summaries'][$metric.':sum'],array_sum(array_column($rows,$metric)),'Full-result summary '.$code.' '.$metric);
        $exportFields = array_merge(['row_id','store_id'],$metricFields);
        $export = $query(['page'=>2,'export'=>['scope'=>'query','fields'=>$exportFields]]);
        host51check(count($export['exportRows'])===$total,'Query export not current page: '.$code);
        host51check(array_column($export['exportRows'],'row_id')===array_column($rows,'row_id'),'Export and paged list stable identities: '.$code);
        foreach ($metricFields as $metric) host51near(array_sum(array_column($export['exportRows'],$metric)),$first['summaries'][$metric.':sum'],'Export sum equals complete summary '.$code.' '.$metric);
        $selected = $rows[0]; $filters = [['field'=>'row_id','operator'=>'eq','value'=>$selected['row_id']]];
        $filtered = $query(['filters'=>$filters,'export'=>['scope'=>'query','fields'=>$exportFields]]);
        host51check((int)$filtered['pagination']['total']===1 && count($filtered['exportRows'])===1,'Exact stable-ID filter shared with export: '.$code);
        foreach ($metricFields as $metric) host51near($filtered['summaries'][$metric.':sum'],$selected[$metric],'Filtered summary '.$code.' '.$metric);
        $empty = $query(['filters'=>[['field'=>'row_id','operator'=>'eq','value'=>'absent-fixture-row']], 'export'=>['scope'=>'query','fields'=>$exportFields]]);
        host51check($empty['rows']===[] && $empty['exportRows']===[] && (int)$empty['pagination']['total']===0,'Empty filter and export: '.$code);
        if ($metricFields) {
            $metric = $metricFields[0];
            $sorted = $query(['sorts'=>[['field'=>$metric,'direction'=>'desc'],['field'=>'row_id','direction'=>'desc']],
                'export'=>['scope'=>'query','fields'=>$exportFields]]);
            $previous = null;
            foreach ($sorted['exportRows'] as $row) {
                if ($previous!==null) host51check((float)$previous[$metric]>=(float)$row[$metric],'Numeric descending sort '.$code);
                if ($previous!==null && (float)$previous[$metric]===(float)$row[$metric]) host51check(strcmp($previous['row_id'],$row['row_id'])>0,'Stable secondary sort '.$code);
                $previous = $row;
            }
        }
        $narrowed = $query(['filters'=>[['field'=>'store_id','operator'=>'eq','value'=>903]],'export'=>['scope'=>'query','fields'=>$exportFields]]);
        host51check($narrowed['rows']===[] && $narrowed['exportRows']===[],'Filter cannot widen Host store authority: '.$code);
        $storeContext = Host::context([901,902],'stores',999902,'store',false,0,$code);
        $storeResult = Host::query($storeContext,array_replace($input,['pageCode'=>$code]));
        host51check((int)$storeResult['pagination']['total']===$total && $storeResult['summaries']===$first['summaries'],'Platform/store share identical authorized metrics: '.$code);
        $quick = $query(['quickFilters'=>$filters]);
        host51check((int)$quick['pagination']['total']===1,'Framework quick filter uses same row cohort: '.$code);
        $keyword = $query(['keyword'=>'definitely-absent-host-fixture']);
        host51check((int)$keyword['pagination']['total']===0,'Framework keyword filter runs: '.$code);
        host51reject(static function () use ($query) { $query(['filters'=>[['field'=>'not_registered','operator'=>'eq','value'=>'x']]]); },'Unknown field rejected: '.$code);
        host51reject(static function () use ($query) { $query(['start_date'=>'2026-02-30']); },'Invalid fact range rejected: '.$code);
        if (in_array($code,['basic_employee_service_store','basic_employee_performance_detail'],true))
            Db::name('system_store_staff')->where('store_id',901)->update(['store_id'=>902]);
    }
    host51check($totals['basic_writeoff_detail']===45 && $totals['basic_employee_summary']===45 && $totals['basic_employee_performance_detail']===45,'Different actual report grains through Host');
    $context = Host::context([901],'stores',999901,'platform',false,0,'basic_employee_summary');
    $summary = Host::query($context,$input+['pageCode'=>'basic_employee_summary']);
    host51near($summary['summaries']['sales_performance:sum'],3600.45,'Real Host complete sales cents, unauthorized fact excluded');
    host51near($summary['summaries']['labor_performance:sum'],3600.45,'Real Host complete labor cents');
    host51near($summary['summaries']['project_count:sum'],67.5,'Non-money decimal project counts through Runtime');
    host51near($summary['summaries']['service_visits:sum'],45,'Tenths visits through Runtime full summary');
    host51near($summary['summaries']['point_visits:sum'],23,'Point visitors through Runtime full summary');
    host51check(!isset($summary['summaries']['labor_unit_price:sum']),'Non-additive labor unit price absent from summary');
    $gradeQuery = $input+['pageCode'=>'basic_employee_summary','filters'=>[['field'=>'position_level_name','operator'=>'eq','value'=>'高级']],
        'export'=>['scope'=>'query','fields'=>['row_id','employee_id','position_name','position_level_name','service_visits','point_visits','labor_unit_price','labor_performance']]];
    $grade = Host::query($context,$gradeQuery);
    host51check($grade['pagination']['total']===23 && count($grade['rows'])===20 && count($grade['exportRows'])===23,'Current grade filtering uses full Runtime cohort and export');
    host51near($grade['summaries']['labor_performance:sum'],1840.23,'Grade-filtered money full sum');
    host51near($grade['summaries']['service_visits:sum'],23,'Grade-filtered visits are non-money counts');
    foreach ($grade['exportRows'] as $row) {
        host51check($row['position_name']==='技师' && $row['position_level_name']==='高级','Current dimension names exported');
        host51near($row['labor_unit_price'],80.01,'Derived cents-precision labor unit price exported');
        host51check($row['service_visits']==1 && $row['point_visits']==1,'Point and ordinary counts retain separate units');
    }
    Db::name('cashier_v3_entitlement_service_fact')->where('service_fact_id','host-service-0')->update(['friend_counts_as_customer'=>0]);
    $noVisit = Host::query($context,$input+['pageCode'=>'basic_employee_summary',
        'filters'=>[['field'=>'employee_id','operator'=>'eq','value'=>100]],
        'export'=>['scope'=>'query','fields'=>['employee_id','service_visits','point_visits','labor_unit_price','labor_performance']]]);
    host51check($noVisit['rows'][0]['labor_unit_price']===null && $noVisit['exportRows'][0]['labor_unit_price']===null,'Zero-denominator null survives Runtime and export');
    host51near($noVisit['summaries']['labor_performance:sum'],80.01,'Count exclusion does not change labor summary');
    $home = Host::query($context,$input+['pageCode'=>'basic_employee_service_home_store']);
    host51check($home['rows']===[],'Current home store outside authority cannot be exposed');
    echo json_encode(['pass'=>true,'checks'=>$checks,'reports'=>count($totals),'totals'=>$totals,
        'path'=>'real_host_registered_providers_unified_runtime_mysql', 'persistent_business_writes'=>false],JSON_UNESCAPED_UNICODE),PHP_EOL;
} finally {
    Db::execute('USE `'.$original.'`');
    if ($created) Db::execute('DROP DATABASE `'.$isolated.'`');
}
