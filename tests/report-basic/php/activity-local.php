<?php
/** R51 real Reader/ThinkORM/MySQL tests; all writes target a disposable isolated local database. */
require (getenv('BACKEND_ROOT') ?: getcwd()).'/vendor/autoload.php';
(new \think\App())->initialize();
use think\facade\Db;
use app\services\report\BasicActivityReportServices as Reports;

set_exception_handler(static function ($e) { file_put_contents('php://stderr',(string)$e.PHP_EOL); exit(1); });
if (!in_array(config('database.connections.mysql.hostname'),['mysql','mohe-mysql','127.0.0.1','localhost'],true)
    || getenv('R51_LOCAL_TEST') !== '1') throw new RuntimeException('Explicit local-only environment required');
$checks = 0;
function verify51($ok, string $label): void { global $checks; if (!$ok) throw new RuntimeException($label); ++$checks; }
function reject51(callable $fn, string $label): void {
    try { $fn(); } catch (InvalidArgumentException $e) { verify51(true,$label); return; }
    throw new RuntimeException('Expected rejection: '.$label);
}

// Metadata registration must work with no DB query, and only the framework's field types.
$service = new Reports();
verify51(count(Reports::codes()) === 10,'All ten executable report codes');
foreach (Reports::codes() as $code) foreach ($service->definitions($code) as $field) {
    verify51(count(array_intersect(['key','label','type','defaultVisible','defaultQuick','width','fixed','money','summable'],array_keys($field))) === 9,'Complete metadata '.$code);
    verify51(in_array($field['type'],['text','date','integer','decimal'],true),'Framework field type');
    verify51(is_bool($field['money']) && is_bool($field['summable']),'Explicit money/summability');
}
$tables = []; $schemas = [];
$date = '2026-10-20';
$originalDatabase = (string)Db::query('SELECT DATABASE() name')[0]['name'];
$testDatabase = 'r51_activity_test_'.bin2hex(random_bytes(6));
if (!preg_match('/^[a-zA-Z0-9_]+$/D',$originalDatabase)) throw new RuntimeException('Unexpected database name');
$created = false;
try {
    Db::execute('CREATE DATABASE `'.$testDatabase.'` CHARACTER SET utf8mb4');
    $created = true;
    // Clone schemas only, never fixture customer rows. Every queried domain table is shadowed.
    foreach (Db::query('SHOW TABLES') as $record) {
        $table = (string)reset($record);
        if (!preg_match('/^eb_(cashier_v3_|cash_source|store_product|store_order|system_store|system_business_product|staff_job_position|position)/',$table)) continue;
        Db::execute('CREATE TABLE `'.$testDatabase.'`.`'.$table.'` LIKE `'.$originalDatabase.'`.`'.$table.'`');
        $tables[] = $table;
    }
    Db::execute('USE `'.$testDatabase.'`');
    function insert51(string $table, array $values): void {
        global $schemas,$date;
        if (!isset($schemas[$table])) $schemas[$table] = Db::query('SHOW COLUMNS FROM `eb_'.$table.'`');
        $row = [];
        foreach ($schemas[$table] as $column) {
            $key = $column['Field'];
            if (strpos($column['Extra'],'auto_increment') !== false || strpos($column['Extra'],'GENERATED') !== false) continue;
            if ($column['Default'] !== null) $row[$key] = $column['Default'];
            elseif ($column['Null'] === 'YES') $row[$key] = null;
            elseif (preg_match('/int|decimal|float|double/',$column['Type'])) $row[$key] = 0;
            elseif (strpos($column['Type'],'date') !== false) $row[$key] = $date;
            else $row[$key] = '';
        }
        $token = bin2hex(random_bytes(12));
        foreach (['natural_key','command_idempotency_key','immutable_fingerprint','business_event_no'] as $key) if (array_key_exists($key,$row)) $row[$key] = $token;
        $allowed = array_fill_keys(array_column($schemas[$table],'Field'),true);
        Db::name($table)->insert(array_replace($row,array_intersect_key($values,$allowed)));
    }
    insert51('system_store',['id'=>901,'name'=>'服务店']);
    insert51('system_store',['id'=>902,'name'=>'所属店']);
    insert51('store_product_category',['id'=>70,'pid'=>0,'cate_name'=>'当前分类','type'=>0,'relation_id'=>0]);
    foreach ([600=>6,601=>0,602=>5] as $id=>$type) {
        insert51('store_product',['id'=>$id,'pid'=>0,'type'=>0,'relation_id'=>0,'cate_id'=>'70','product_type'=>$type]);
        insert51('store_product',['id'=>$id+100,'pid'=>$id,'type'=>1,'relation_id'=>901,'cate_id'=>'70','product_type'=>$type]);
    }
    foreach ([11,12] as $employee) insert51('system_store_staff',['id'=>$employee+100,'employee_id'=>$employee,'store_id'=>902,'staff_name'=>'员工'.$employee,'status'=>1,'is_del'=>0]);
    foreach ([61=>'技师',62=>'顾问'] as $id=>$name) insert51('position',['id'=>$id,'name'=>$name,'status'=>1]);
    foreach ([71=>'高级',72=>'初级'] as $id=>$name) insert51('position_level',['id'=>$id,'name'=>$name,'status'=>1]);
    foreach ([11=>[61,71],12=>[62,72]] as $employee=>$dimension) {
        Db::name('system_store_staff')->where('id',$employee+100)->update(['position_level'=>$dimension[1]]);
        insert51('staff_job_position',['id'=>$employee+300,'employee_id'=>$employee,'store_id'=>902,'staff_id'=>$employee+100,
            'position_id'=>$dimension[0],'status'=>1,'is_del'=>0,'end_time'=>0,'request_token'=>'job-'.$employee]);
    }
    $base = ['tenant_id'=>'0','store_id'=>901,'store_name_snapshot'=>'历史服务店','member_id'=>23,'member_name_snapshot'=>'历史顾客',
        'business_date'=>$date,'business_timezone'=>'Asia/Shanghai','status'=>'effective','fact_direction'=>'forward',
        'organization_id'=>'0','organization_path_snapshot'=>'/','checkout_request_id'=>'checkout-51','order_id'=>'order-51','order_no_snapshot'=>'SALE-51',
        'source_document_type'=>'cashier_snapshot'];
    foreach ([['project',700,20003,2],['product',701,10001,1],['card',702,30001,1]] as $index=>$data) {
        [$type,$item,$amount,$quantity] = $data;
        $fact = 'sale-'.$index; $line = 'line-'.$index;
        insert51('cashier_v3_sale_fact',$base+['fact_id'=>$fact,'source_line_id'=>$line,'source_type'=>$type,'item_id'=>$item,'item_name_snapshot'=>'历史'.$type,'quantity'=>$quantity,'sale_amount_cents'=>$amount]);
        insert51('cashier_v3_report_sale_dimension_fact',['tenant_id'=>'0','sale_fact_id'=>$fact,'item_id'=>$item,'item_name_snapshot'=>'历史'.$type,'product_type_snapshot'=>$type,'category_id_snapshot'=>9,'category_path_snapshot'=>'过时分类']);
        insert51('cashier_v3_payment_sale_allocation_fact',$base+['allocation_fact_id'=>'pay-'.$index,'sale_fact_id'=>$fact,'source_line_id'=>$line,'payment_fact_id'=>'payment-'.$index,'payment_method'=>'cash','amount_cents'=>$index===1?8001:$amount]);
        insert51('cashier_v3_sales_order_line',['tenant_id'=>'0','store_id'=>901,'order_id'=>'order-51','order_line_id'=>'ol-'.$index,'checkout_line_id'=>$line,'line_direction'=>'forward','item_type'=>$type,'item_id'=>$item]);
    }
    insert51('cashier_v3_card_sale_category_allocation_fact',$base+['allocation_fact_id'=>'card-component','sale_fact_id'=>'sale-2','component_product_id'=>700,'sale_amount_cents'=>30001,'component_count'=>1,'product_type_snapshot'=>'project']);
    insert51('cashier_v3_order_balance_allocation',['tenant_id'=>'0','store_id'=>901,'order_id'=>'order-51','order_line_id'=>'ol-1','amount_cents'=>2000]);
    foreach ([11=>10001,12=>10002] as $employee=>$amount) insert51('cashier_v3_performance_fact',$base+[
        'fact_id'=>'sales-person-'.$employee,'source_line_id'=>'line-0','performance_type'=>'sales_performance_allocated','employee_id'=>$employee,'employee_name_snapshot'=>'历史员工'.$employee,
        'allocation_weight_numerator'=>1,'allocation_weight_denominator'=>2,'amount_cents'=>$amount]);
    // Product staff are a dimension; two employees cannot double the sale amount or quantity.
    foreach ([11=>4000,12=>4001] as $employee=>$amount) insert51('cashier_v3_performance_fact',$base+[
        'fact_id'=>'product-person-'.$employee,'source_line_id'=>'line-1','performance_type'=>'sales_performance_allocated','employee_id'=>$employee,'employee_name_snapshot'=>'历史员工'.$employee,
        'allocation_weight_numerator'=>1,'allocation_weight_denominator'=>2,'amount_cents'=>$amount]);
    for ($i=0;$i<45;++$i) {
        $sv = $base; unset($sv['order_id'],$sv['order_no_snapshot'],$sv['status'],$sv['fact_direction']);
        insert51('cashier_v3_entitlement_service_fact',$sv+['service_fact_id'=>'service-'.$i,'source_line_id'=>'service-line-'.$i,'project_id'=>700,'project_name_snapshot'=>'历史服务项目',
            'service_status'=>'completed','quantity'=>2,'document_no_snapshot'=>'SERVICE-'.$i,'service_object'=>'self','friend_counts_as_customer'=>1,'craftsmen_snapshot_json'=>'[]']);
        insert51('cashier_v3_performance_fact',$base+['fact_id'=>'consume-'.$i,'source_line_id'=>'service-line-'.$i,'performance_type'=>'consumption_performance_recorded','amount_cents'=>10001]);
        foreach ([11=>6001,12=>4000] as $employee=>$amount) insert51('cashier_v3_performance_fact',$base+[
            'fact_id'=>'labor-'.$i.'-'.$employee,'source_line_id'=>'service-line-'.$i,'performance_type'=>'labor_performance_allocated','employee_id'=>$employee,
            'employee_name_snapshot'=>'历史员工'.$employee,'amount_cents'=>$amount,'labor_fee_amount_cents'=>105,'project_count_decimal'=>$employee===11?'0.3':'1.7',
            'role_snapshot'=>$employee===11?'craftsman:point':'craftsman:round']);
    }
    // Follow-up identity is explicitly linked by a receipt; deliberately wrong old money must be ignored.
    insert51('store_order',['id'=>800,'store_id'=>901,'uid'=>23,'is_gendan'=>1,'gendan_staff_id'=>111,'order_id'=>'OLD-51','pay_price'=>999999]);
    insert51('store_order_cart_info',['id'=>801,'oid'=>800,'cart_type'=>0]);
    insert51('cashier_v3_card_purchase_receipt',['receipt_id'=>'receipt-51','tenant_id'=>'0','store_id'=>901,'member_id'=>23,'sales_order_id'=>'order-51',
        'sales_order_line_id'=>'ol-2','issue_no'=>1,'legacy_order_id'=>800,'base_cart_id'=>801,'status'=>'completed']);
    // An effective fact at an unauthorized execution store must not enter any amount or count.
    insert51('cashier_v3_performance_fact',array_replace($base,['fact_id'=>'foreign-sales','store_id'=>903,'source_line_id'=>'foreign-line',
        'performance_type'=>'sales_performance_allocated','employee_id'=>11,'amount_cents'=>999999]));
    $input = ['start_date'=>$date,'end_date'=>$date,'as_of_date'=>$date,'page'=>2,'limit'=>1,'_report_scope'=>['mode'=>'stores']];
    $read = static function ($code,array $extra = [],array $stores = [901,902]) use ($service,$input) { return $service->read('basic_'.$code,$stores,array_replace($input,$extra)); };
    $detail = $read('writeoff_detail');
    verify51(count($detail['rows'])===45,'Complete 45 rows despite page/limit input');
    verify51(abs(array_sum(array_column($detail['rows'],'consumption_amount'))-4500.45)<0.000001,'Consumption project grain, cents preserved');
    verify51(array_sum(array_column($detail['rows'],'quantity'))==90,'Service quantity not repeated by two staff');
    $items = $read('item_consumption')['rows'];
    verify51(count($items)===1 && $items[0]['quantity']==90 && abs($items[0]['consumption_amount']-4500.45)<0.000001,'Item period grain');
    verify51($items[0]['category_path']==='当前分类','Current category, immutable service name');
    $employee = $read('employee_summary')['rows'];
    verify51(count($employee)===2 && abs(array_sum(array_column($employee,'sales_performance'))-280.04)<0.000001,'Employee sales aggregate in exact cents');
    verify51(abs(array_sum(array_column($employee,'labor_performance'))-4500.45)<0.000001,'Labor independent of project consumption');
    verify51(abs(array_sum(array_column($employee,'project_count'))-90)<0.000001,'Fractional allocated projects preserved');
    $employeeById = array_column($employee,null,'employee_id');
    verify51($employeeById[11]['position_name']==='技师' && $employeeById[12]['position_name']==='顾问','Current position uses shared active-appointment resolver');
    verify51($employeeById[11]['position_level_name']==='高级' && $employeeById[12]['position_level_name']==='初级','Current grade resolved from active staff appointment');
    verify51($employeeById[11]['service_visits']==0.5 && $employeeById[12]['service_visits']==0.5,'Member-day visits shared once, tenths divided by ten');
    verify51($employeeById[11]['point_visits']==1 && $employeeById[12]['point_visits']==0,'Point visits not divided by co-serving staff');
    verify51(abs($employeeById[11]['labor_unit_price']-5400.90)<0.000001 && $employeeById[12]['labor_unit_price']==3600,'Labor unit price uses labor amount over visits in yuan');
    $summaryFields = array_column($service->definitions('basic_employee_summary'),null,'key');
    verify51(!$summaryFields['labor_unit_price']['summable'] && $summaryFields['labor_unit_price']['money'],'Labor unit price is money but never additive');
    verify51(!$summaryFields['service_visits']['money'] && !$summaryFields['point_visits']['money'],'Tenths counts not mislabeled as money');
    Db::name('cashier_v3_entitlement_service_fact')->where('store_id',901)->update(['friend_counts_as_customer'=>0]);
    $noCount = $read('employee_summary')['rows'];
    verify51(array_column($noCount,'labor_unit_price')===[null,null] && array_sum(array_column($noCount,'service_visits'))==0,'Excluded customer counts yield null average, not zero or division error');
    verify51(abs(array_sum(array_column($noCount,'labor_performance'))-4500.45)<0.000001,'Count exclusion never erases labor money');
    Db::name('cashier_v3_entitlement_service_fact')->where('store_id',901)->update(['friend_counts_as_customer'=>1]);
    // Conflicting off-store current grades are not guessed; an exact store appointment takes precedence.
    insert51('system_store_staff',['id'=>210,'employee_id'=>11,'store_id'=>903,'position_level'=>72,'status'=>1,'is_del'=>0]);
    verify51(array_column($read('employee_summary')['rows'],null,'employee_id')[11]['position_level_name']===null,'Ambiguous current grade is explicitly missing');
    insert51('system_store_staff',['id'=>211,'employee_id'=>11,'store_id'=>901,'position_level'=>71,'status'=>1,'is_del'=>0]);
    verify51(array_column($read('employee_summary')['rows'],null,'employee_id')[11]['position_level_name']==='高级','Exact business-store grade wins');
    Db::name('system_store_staff')->whereIn('id',[210,211])->delete();
    $homeService = $read('employee_service_home_store')['rows'];
    verify51(count($homeService)===2 && array_unique(array_column($homeService,'store_id'))===[902],'Query-time home differs from service store');
    // 007 的当前员工主表：服务店的历史销售只在该员工仍任职此店时显示。
    foreach ([11,12] as $employee) insert51('system_store_staff',['id'=>$employee+201,'employee_id'=>$employee,
        'store_id'=>901,'staff_name'=>'员工'.$employee,'status'=>1,'is_del'=>0]);
    $storeService = $read('employee_service_store')['rows'];
    verify51(array_unique(array_column($storeService,'store_id'))===[901],'Current execution-store appointment retains service rows');
    // 卡销售与商品销售同属原成交行，数量按整单两名销售人员均分，而非业绩金额权重。
    foreach ([11,12] as $employee) insert51('cashier_v3_performance_fact',$base+[
        'fact_id'=>'card-person-'.$employee,'source_line_id'=>'line-2','performance_type'=>'sales_performance_allocated',
        'employee_id'=>$employee,'employee_name_snapshot'=>'历史员工'.$employee,
        'allocation_weight_numerator'=>1,'allocation_weight_denominator'=>2,'amount_cents'=>15000]);
    insert51('system_store_staff',['id'=>214,'employee_id'=>13,'store_id'=>901,'staff_name'=>'零销售员工','status'=>1,'is_del'=>0]);
    $employeeItems = $read('employee_item_sales')['rows'];
    $salesRows = array_values(array_filter($employeeItems,static function($row){return (int)$row['store_id']===901 && (int)$row['employee_id']!==13;}));
    verify51(count($salesRows)===6 && array_sum(array_column($salesRows,'sales_quantity'))==4,'All three original sale types split across full order participants');
    verify51(count(array_filter($salesRows,static function($row){return (float)$row['sales_quantity']===0.5;}))===4,'Two-person sale count is fixed at 0.50 per unit line');
    verify51(count(array_filter($employeeItems,static function($row){return (int)$row['employee_id']===13 && $row['item_name']==='' && $row['sales_quantity']==0; }))===1,
        'Current employee without sales receives one empty 0.00 row');
    $selfItems=$read('employee_item_sales',['_report_scope'=>['mode'=>'self_participant','employee_id'=>11]])['rows'];
    $allByRowId=array_column($employeeItems,null,'row_id');
    foreach($selfItems as $row) verify51($row['sales_quantity']===$allByRowId[$row['row_id']]['sales_quantity'],'Employee filter does not recalculate the order denominator');
    $product = $read('product_sales')['rows'];
    verify51(count($product)===1 && $product[0]['sale_amount']==100.01 && $product[0]['quantity']==1,'Product sale line money counted once');
    verify51($product[0]['cash_amount']==80.01 && $product[0]['balance_amount']==20,'Real cash and frozen balance allocation');
    verify51($product[0]['employee_balance_performance']===null && $product[0]['salesperson_ids']==='11,12','Unknown employee balance stays null');
    verify51(!in_array('employee_balance_performance',$read('product_sales')['sum_fields'],true)
        && !in_array('balance_amount',$read('product_sales')['sum_fields'],true),'Nullable coverage does not publish a false partial sum');
    $followup = $read('followup_employee_detail')['rows'];
    verify51(count($followup)===1 && $followup[0]['sale_amount']==300.01 && $followup[0]['followup_staff_id']===111,'Followup only identity, money from unified sale');
    // 010 历史有效 type=2 销售迁移后仍属于 007 现金业绩人口，且必须可追到品项。
    insert51('cashier_v3_performance_fact',array_replace($base,[
        'fact_id'=>'legacy-person-11','source_line_id'=>'line-0','source_document_type'=>'legacy_store_order',
        'performance_type'=>'sales_performance_allocated','employee_id'=>11,'employee_name_snapshot'=>'历史员工11',
        'amount_cents'=>10000]));
    $performanceRows=$read('employee_performance_detail')['rows'];
    verify51(count($performanceRows)===7 && array_unique(array_column($performanceRows,'performance_type'))===['销售分配'],
        '007 performance detail includes current employees and migrated original sales only');
    verify51(count(array_filter($performanceRows,static function($row){return $row['row_id']==='performance:legacy-person-11' && $row['item_name']==='历史project';}))===1,
        'Migrated sales performance retains source item identity');
    Db::name('cashier_v3_performance_fact')->where('fact_id','legacy-person-11')->delete();
    // 其他报表沿用原来唯一当前所属店的夹具，不让本表专用双店身份改变其合同。
    Db::name('system_store_staff')->whereIn('id',[212,213])->update(['is_del'=>1]);
    foreach (Reports::codes() as $code) {
        $result = $service->read($code,[901,902],$input);
        verify51($service->read($code,[], $input)['rows']===[],'Empty authorized scope '.$code);
        verify51($service->read($code,[901],array_replace($input,['store_id'=>999]))['rows']===[],'Unauthorized narrowing '.$code);
        foreach ($result['fields'] as $f) verify51(isset($result['field_sources'][$f['key']]),'Every column has business source');
        verify51(count(array_unique(array_column($result['rows'],'row_id')))===count($result['rows']),'Stable unique row ids '.$code);
        verify51(array_column($service->read($code,[901,902],$input)['rows'],'row_id')===array_column($result['rows'],'row_id'),'Refresh stability '.$code);
    }
    $self = $read('employee_summary',['_report_scope'=>['mode'=>'self_participant','employee_id'=>11]])['rows'];
    verify51(count($self)===1 && $self[0]['employee_id']===11,'Trusted personal scope');
    verify51($self[0]['service_visits']==0.5 && $self[0]['point_visits']==1,'Personal narrowing retains co-worker allocation divisor');
    verify51(count($read('writeoff_detail',['_report_scope'=>['mode'=>'self_participant','employee_id'=>11]])['rows'])===45,'Service participant scope applied by Reader');
    reject51(static function () use ($read) { $read('employee_summary',['_report_scope'=>['mode'=>'none']]); },'No access fails closed');
    reject51(static function () use ($read) { $read('writeoff_detail',['start_date'=>'2026-02-30']); },'Real calendar validation');
    reject51(static function () use ($read) { $read('writeoff_detail',['as_of_date'=>'2026-10-32']); },'Cutoff calendar validation');
    insert51('system_store_staff',['id'=>200,'employee_id'=>11,'store_id'=>901,'status'=>1,'is_del'=>0]);
    $dualHome=$read('employee_service_home_store')['rows'];
    verify51(count(array_filter($dualHome,static function($row){return (int)$row['employee_id']===11
        && (int)$row['store_id']===901 && $row['project_count']>0;}))===1,
        'Dual current appointment selects matching service store without duplicate service count');
    Db::name('system_store_staff')->where('id',200)->delete();
    Db::name('store_order')->where('id',800)->update(['is_gendan'=>0]);
    verify51($read('followup_employee_detail')['rows']===[],'Missing follow-up does not count');
    Db::name('cashier_v3_report_sale_dimension_fact')->where('sale_fact_id','sale-0')->update(['item_id'=>0]);
    $unknownItems = $read('store_item_sales')['rows'];
    verify51(abs(array_sum(array_column($unknownItems,'sale_amount'))-600.05)<0.000001,'Missing item identity retains original sale total');
    verify51(count(array_filter($unknownItems,static function ($row) { return $row['item_id']===0 && $row['item_name']==='未关联品项'; }))===1,'Unknown identity is explicit, never guessed by name');
    // A later service void removes original and reversal from every operating period.
    $serviceId = Db::name('cashier_v3_entitlement_service_fact')->where('service_fact_id','service-0')->value('id');
    insert51('cashier_v3_service_record_void_operation',['tenant_id'=>'0','store_id'=>901,'service_fact_id'=>$serviceId,'operation_id'=>'void-service','status'=>'succeeded']);
    verify51(count($read('writeoff_detail')['rows'])===44,'Service void uses shared normal scope');
    verify51(abs(array_sum(array_column($read('employee_summary')['rows'],'labor_performance'))-4400.44)<0.000001,'Service void removes personnel metrics too');
    // Zero-value services still own customer allocation; three participants receive 0.3/0.3/0.4.
    $zero = $sv;
    $zero['member_id']=24;
    insert51('cashier_v3_entitlement_service_fact',array_replace($zero,['service_fact_id'=>'zero-service','checkout_request_id'=>'zero-checkout','source_line_id'=>'zero-line',
        'project_id'=>700,'project_name_snapshot'=>'零金额服务','service_status'=>'completed','quantity'=>1,'service_object'=>'self','friend_counts_as_customer'=>1,
        'craftsmen_snapshot_json'=>json_encode([['employee_id'=>13,'name'=>'零额13','is_point_customer'=>true],['employee_id'=>14,'name'=>'零额14'],['employee_id'=>15,'name'=>'零额15']])]));
    insert51('cashier_v3_entitlement_service_fact',array_replace($zero,['service_fact_id'=>'zero-service-other-store','checkout_request_id'=>'zero-other','source_line_id'=>'zero-other-line',
        'store_id'=>902,'store_name_snapshot'=>'另一服务店','project_id'=>700,'service_status'=>'completed','quantity'=>1,'service_object'=>'self','friend_counts_as_customer'=>1,
        'craftsmen_snapshot_json'=>json_encode([['employee_id'=>13,'name'=>'零额13','is_point_customer'=>true]])]));
    $zeroRows = $read('employee_summary')['rows']; $zeroByPair = [];
    foreach ($zeroRows as $row) $zeroByPair[$row['store_id'].'|'.$row['employee_id']]=$row;
    verify51($zeroByPair['901|13']['service_visits']==0.3 && $zeroByPair['901|14']['service_visits']==0.3 && $zeroByPair['901|15']['service_visits']==0.4,'Zero-money service rows preserve stable tenth allocation');
    verify51($zeroByPair['901|13']['point_visits']==1 && $zeroByPair['902|13']['point_visits']==1 && $zeroByPair['902|13']['service_visits']==1,'Same employee across stores does not merge point visits');
    verify51($zeroByPair['901|13']['labor_unit_price']==0 && $zeroByPair['901|13']['position_name']===null && $zeroByPair['901|13']['position_level_name']===null,'Valid zero average and missing current dimensions remain distinct');
    // Keep the same three participants, now with real labor facts, to verify exact cent rounding of 1/0.3 and 1/0.4.
    foreach ([13=>10001,14=>1,15=>1] as $employee=>$amount) insert51('cashier_v3_performance_fact',array_replace($base,[
        'fact_id'=>'zero-repriced-'.$employee,'order_id'=>'zero-labor-order','checkout_request_id'=>'zero-checkout','source_line_id'=>'zero-line',
        'performance_type'=>'labor_performance_allocated','employee_id'=>$employee,'employee_name_snapshot'=>'员工'.$employee,
        'amount_cents'=>$amount,'role_snapshot'=>$employee===13?'craftsman:point':'craftsman:round']));
    $roundedRows = $read('employee_summary')['rows']; $roundedByPair = [];
    foreach ($roundedRows as $row) $roundedByPair[$row['store_id'].'|'.$row['employee_id']]=$row;
    verify51(abs($roundedByPair['901|13']['labor_unit_price']-333.37)<0.000001,'Derived labor ratio rounds repeating decimal to exact cents');
    verify51($roundedByPair['901|15']['labor_unit_price']==0.03,'Derived half-cent ratio rounds half up, never truncates');
    // Final sales allocation must replace old allocation even if its reversal is outside the queried day.
    insert51('cashier_v3_performance_fact',array_replace($base,['fact_id'=>'sales-reversal','source_line_id'=>'line-0','performance_type'=>'sales_performance_allocated',
        'employee_id'=>11,'fact_direction'=>'reversal','reversal_of'=>'sales-person-11','business_date'=>'2026-10-21','amount_cents'=>-10001]));
    verify51(abs(array_sum(array_column($read('employee_summary')['rows'],'sales_performance'))-480.03)<0.000001,'Future reversal excludes replaced final sales allocation');
    insert51('cashier_v3_order_lifecycle_operation',['tenant_id'=>'0','store_id'=>901,'operation_id'=>'refund-51','operation_no'=>'REF-51',
        'operation_type'=>'refund','source_type'=>'sales','source_order_id'=>'order-51','status'=>'succeeded']);
    insert51('cashier_v3_performance_fact',array_replace($base,['fact_id'=>'refund-person-12','order_id'=>'refund-51','source_line_id'=>'refund-line',
        'performance_type'=>'refund_sales_deduction','employee_id'=>12,'employee_name_snapshot'=>'历史员工12','amount_cents'=>-101]));
    verify51(abs(array_sum(array_column($read('employee_summary')['rows'],'sales_performance'))-479.02)<0.000001,'Successful refund deduction keeps cents and correct date');
    // 007 员工业绩明细只列原充值/销售，退款冲减仍由员工统计等经营净业绩表保留。
    Db::name('system_store_staff')->whereIn('id',[212,213])->update(['is_del'=>0]);
    $refunds = array_values(array_filter($read('employee_performance_detail')['rows'],static function ($row) { return $row['performance_type']==='退款冲减'; }));
    verify51($refunds===[],'007 performance detail excludes refund deductions');
    Db::name('system_store_staff')->whereIn('id',[212,213])->update(['is_del'=>1]);
    insert51('cashier_v3_order_lifecycle_operation',['tenant_id'=>'0','store_id'=>901,'operation_id'=>'void-sale-51','operation_no'=>'VOID-51',
        'operation_type'=>'void','source_type'=>'sales','source_order_id'=>'order-51','status'=>'succeeded']);
    verify51($read('store_item_sales')['rows']===[] && $read('product_sales')['rows']===[] && $read('followup_employee_detail')['rows']===[],'Shared sales void guard across all sale projections');
    // Build an oversized real source population; the service must fail before PHP grouping or publication.
    $template = Db::name('cashier_v3_performance_fact')->where('fact_id','sales-person-12')->find();
    unset($template['id']); $batch = [];
    for ($i=0;$i<10001;++$i) {
        $token = 'bulk-51-'.$i;
        $row = array_replace($template,['fact_id'=>$token,'natural_key'=>$token,'command_idempotency_key'=>$token,
            'immutable_fingerprint'=>hash('sha256',$token),'business_event_no'=>$token,'order_id'=>'bulk-order','source_line_id'=>$token]);
        $batch[] = $row;
        if (count($batch)===300 || $i===10000) { Db::name('cashier_v3_performance_fact')->insertAll($batch); $batch = []; }
    }
    reject51(static function () use ($read) { $read('employee_summary'); },'10001 raw allocations explicitly rejected, never truncated');
    echo json_encode(['pass'=>true,'checks'=>$checks,'reports'=>count(Reports::codes()),'source'=>'real_registered_readers_thinkorm_isolated_local_mysql','persistent_business_writes'=>false],JSON_UNESCAPED_UNICODE),PHP_EOL;
} finally {
    Db::execute('USE `'.$originalDatabase.'`');
    if ($created) Db::execute('DROP DATABASE `'.$testDatabase.'`');
}
