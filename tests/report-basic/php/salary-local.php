<?php
/** R51 真实 Reader + 独立 MySQL 夹具库；复制结构不复制客户数据，finally 删除本轮库。 */
$root = getenv('BACKEND_ROOT') ?: getcwd();
require $root.'/vendor/autoload.php';
(new \think\App())->initialize();
use think\facade\Db;
use app\services\report\BasicSalaryReportServices as Salary;
$connection = config('database.connections.mysql');
if (!in_array($connection['hostname'] ?? '', ['mohe-mysql','mysql'], true)) throw new RuntimeException('Local-only salary test');
$checks = 0;
function verify($pass, string $label): void { global $checks; ++$checks; if (!$pass) throw new RuntimeException($label); }
set_exception_handler(static function ($e) { file_put_contents('php://stderr',(string)$e.PHP_EOL); exit(1); });
/** 填充真实 schema 的必填列，业务相关值均在调用处显式指定；只写本轮夹具库。 */
function fixture(string $table, array $data): void {
    static $columns = [];
    if (!isset($columns[$table])) $columns[$table] = Db::query('SHOW COLUMNS FROM eb_'.$table);
    foreach ($columns[$table] as $column) {
        $key = $column['Field'];
        if (array_key_exists($key,$data) || $column['Null'] === 'YES' || $column['Default'] !== null || strpos($column['Extra'],'auto_increment') !== false) continue;
        $type = $column['Type'];
        $data[$key] = preg_match('/int|decimal|float|double/', $type) ? 0
            : (strpos($type,'date') !== false ? '2026-10-01' : (strpos($type,'json') !== false ? '{}' : ''));
    }
    Db::name($table)->insert($data);
}
$tables = ['employee','system_store','system_store_staff','position','position_level','staff_job_position','position_yeji',
    'salary_info','salary_field','salary_agent','salary_freeze','cashier_v3_performance_fact','cashier_v3_entitlement_service_fact',
    'cashier_v3_sale_fact','cashier_v3_order_lifecycle_operation','cashier_v3_order_center_void_operation',
    'cashier_v3_service_record_void_operation','cashier_v3_service_customer_group_fact',
    'cashier_v3_payment_sale_allocation_fact','cashier_v3_payment_fact','cashier_v3_recharge_debt_repayment',
    'cashier_v3_balance_fact','cashier_v3_customer_lifecycle_fact','cashier_v3_business_source',
    'cashier_v3_sales_order','cashier_v3_checkout_payment_draft','cashier_v3_entitlement_writeoff_fact',
    'cashier_v3_card_purchase_receipt','cashier_v3_card_operation_settlement',
    'cashier_v3_card_sale_item_allocation_fact','cashier_v3_card_sale_category_allocation_fact','store_product','store_product_category',
    'cashier_v3_refund_adjustment','system_business_product','cashier_v3_report_sale_dimension_fact','cashier_v3_sales_order_line'];
$original = Db::connect();
$database = 'r51_salary_test_'.bin2hex(random_bytes(6));
$sourceDb = $connection['database'];
if (!preg_match('/^[A-Za-z0-9_]+$/D',$sourceDb)) throw new RuntimeException('Unsafe local schema name');
$created = false;
try {
    $original->execute('CREATE DATABASE `'.$database.'`'); $created = true;
    foreach ($tables as $table) {
        $original->execute('CREATE TABLE `'.$database.'`.`eb_'.$table.'` LIKE `'.$sourceDb.'`.`eb_'.$table.'`');
    }
    $dbConfig = config('database');
    $dbConfig['connections']['r51_salary_test'] = array_replace($connection,['database'=>$database]);
    $dbConfig['default'] = 'r51_salary_test';
    config($dbConfig, 'database');
    $manualSql=$GLOBALS['salary_manual_schema_sql'] ?? file_get_contents($root.'/database/upgrades/2026-10-27-工资人工项与编辑冻结/01-结构升级.sql');
    foreach (array_filter(array_map('trim',explode(';',$manualSql))) as $sql) Db::execute($sql);
    foreach ([901,902] as $store) fixture('system_store',['id'=>$store,'name'=>'R51测试门店'.$store]);
    fixture('position',['id'=>991,'name'=>'R51测试岗位','status'=>1]);
    fixture('position_level',['id'=>992,'name'=>'R51测试职级','status'=>1]);
    foreach ([[901,99101,88101],[901,99102,88102],[902,99101,88103]] as [$store,$employee,$staff]) {
        if (!Db::name('employee')->where('id',$employee)->count()) fixture('employee',['id'=>$employee,'phone'=>'139000'.$employee,'name'=>'测试员工'.$employee,'status'=>1,'is_del'=>0]);
        fixture('system_store_staff',['id'=>$staff,'employee_id'=>$employee,'store_id'=>$store,'position_level'=>992,'status'=>1,'is_del'=>0]);
        fixture('staff_job_position',['employee_id'=>$employee,'store_id'=>$store,'staff_id'=>$staff,'position_id'=>991,'start_time'=>1,'end_time'=>0,'status'=>1,'is_del'=>0,'request_token'=>'r51-job-'.$staff]);
    }
    fixture('position_yeji',['id'=>991,'position_id'=>991,'position_level_id'=>992,'status'=>1,'commission'=>'10',
        'cate_ids'=>'','range'=>'0-1000','type'=>1,'range_type'=>2,'time_type'=>1,'has_recharge'=>1]);
    fixture('position_yeji',['id'=>992,'position_id'=>991,'position_level_id'=>993,'status'=>1,'commission'=>'99',
        'cate_ids'=>'','range'=>'0-1000','type'=>1,'range_type'=>2,'time_type'=>1,'has_recharge'=>1]);
    fixture('position_yeji',['id'=>993,'position_id'=>991,'position_level_id'=>992,'status'=>1,'commission'=>'20',
        'cate_ids'=>'','range'=>'0-1000','type'=>2,'range_type'=>2,'time_type'=>1,'has_recharge'=>0]);
    $manual = ['haoqingshu'=>2,'canbu'=>'12.34','jiaban'=>0,'jiangweibutie'=>0,'qitatiaozheng'=>0,'kaoqin'=>0,
        'gongjijin'=>0,'geshui'=>0,'qitakoukuan'=>0,'yuzhigongzi'=>0,'yishebaokoukuan'=>0,'beizhu'=>'人工保留',
        'xiao_yeji'=>9999999,'xiao_money'=>9999999,'shifagongzi'=>9999999];
    fixture('salary_info',['staff_id'=>88101,'store_id'=>901,'date'=>strtotime('2026-10-01 Asia/Shanghai'),'salary_info'=>json_encode($manual)]);
    foreach (['jixiao'=>'haoqingshu*35+zhidingke*3','yingfagongzi'=>'jixiao+xiao_money+work_get+canbu',
        'shifagongzi'=>'yingfagongzi-kaoqin','jiangjin'=>'xinkeshu*30'] as $key=>$formula)
        fixture('salary_field',['key'=>$key,'name'=>$key,'type'=>2,'info'=>$formula,'table_ids'=>'1']);
    $seq = 0;
    $fact = function (array $changes = []) use (&$seq): array {
        ++$seq;
        $row = array_replace(['tenant_id'=>'0','fact_id'=>'r51-salary-'.$seq,'natural_key'=>'r51-salary-'.$seq,'id'=>$seq,'store_id'=>901,'employee_id'=>99101,
            'employee_name_snapshot'=>'测试员工99101','store_name_snapshot'=>'R51测试门店901',
            'order_id'=>'r51-order-'.$seq,'source_line_id'=>'r51-line-'.$seq,'checkout_request_id'=>'r51-request-'.$seq,
            'performance_type'=>'sales_performance_allocated','amount_cents'=>10005,'business_date'=>'2026-10-10',
            'status'=>'effective','fact_direction'=>'forward'], $changes);
        fixture('cashier_v3_performance_fact',$row); return $row;
    };
    $salary = new Salary();
    $input = ['start_date'=>'2026-10-01','end_date'=>'2026-10-31','as_of_date'=>'2026-10-23','_report_scope'=>['mode'=>'self_participant','employee_id'=>99101]];
    $read = function (array $stores=[901], array $changes=[]) use ($salary,$input) { return $salary->read('basic_salary_realtime',$stores,array_replace($input,$changes)); };
    $before = $read();
    verify(count($before['rows']) === 1 && $before['rows'][0]['sales_performance'] === 0.0, 'No settled fact before success');
    $sale = $fact(['status'=>'pending']);
    verify($read()['rows'][0]['sales_performance'] === 0.0, 'Pending fact excluded');
    Db::name('cashier_v3_performance_fact')->where('id',$sale['id'])->update(['status'=>'effective']);
    $after = $read(); $row = $after['rows'][0];
    verify($row['sales_performance'] === 100.05 && $row['xiao_money'] === 10.01, 'Success visible on fresh query with cent rounding');
    verify($row['canbu'] === 12.34 && $row['beizhu'] === '人工保留' && $row['shifagongzi'] === 92.35, 'Independent manual values and safe formula');
    verify($row['manual_values']['canbu'] === '12.34' && $row['editable'] === false, 'Manual values remain read-only');
    verify($after['complete'] === true && $row['xinkeshu'] === 0 && $row['jiangjin'] === 0.0 && in_array('jiangjin',$after['sum_fields'],true),
        'Confirmed first-full-course definition returns genuine zero when there is no qualifying event');
    $fact(['employee_id'=>99102,'amount_cents'=>12345]);
    $fact(['store_id'=>902,'amount_cents'=>88888]);
    verify(count($read()['rows']) === 1 && $read()['rows'][0]['sales_performance'] === 100.05, 'Personal and store boundaries do not widen');
    $both = $read([901,902]);
    verify(count($both['rows']) === 2 && count(array_unique(array_column($both['rows'],'row_id'))) === 2, 'Cross-store person has distinct stable rows');
    $fact(['amount_cents'=>-2005,'business_date'=>'2026-10-20','fact_direction'=>'reversal','reversal_of'=>$sale['fact_id']]);
    verify($read()['rows'][0]['sales_performance'] === 80.0, 'Refund signed fact changes fresh monthly query');
    verify($read([901],['as_of_date'=>'2026-10-15'])['rows'][0]['sales_performance'] === 100.05, 'As-of business-date cutoff');
    $fact(['amount_cents'=>-1000,'business_date'=>'2026-11-01','fact_direction'=>'reversal','reversal_of'=>$sale['fact_id']]);
    verify($read()['rows'][0]['sales_performance'] === 80.0, 'Cross-month reversal does not rewrite prior month');
    fixture('cashier_v3_order_lifecycle_operation',['tenant_id'=>'0','source_order_id'=>$sale['order_id'],'source_type'=>'sales','operation_type'=>'void','status'=>'succeeded']);
    verify($read()['rows'][0]['sales_performance'] === -20.05, 'Successful void uses actual normal-data Reader guard');
    // 同一真实规则边界重复命中必须使整列不可合计，不能把两档相加。
    fixture('position_yeji',['id'=>994,'position_id'=>991,'position_level_id'=>992,'status'=>1,'commission'=>'20',
        'cate_ids'=>'','range'=>'0-1000','type'=>1,'range_type'=>2,'time_type'=>1,'has_recharge'=>1]);
    $fact(['amount_cents'=>10000]);
    $overlap = $read();
    verify($overlap['rows'][0]['xiao_money'] === null && !in_array('xiao_money',$overlap['sum_fields'],true), 'Overlapping tiers fail closed');
    Db::name('position_yeji')->where('id',994)->delete();
    Db::name('position_yeji')->where('id',991)->update(['cate_ids'=>'123456']);
    $category = $read();
    verify($category['rows'][0]['xiao_money'] === null && $category['rows'][0]['product_money'] === null, 'Unmapped category cannot become fake project or product commission');
    verify($category['rows'][0]['canbu'] === 12.34, 'Realtime rule failures cannot overwrite manual adjustments');
    Db::name('position_yeji')->where('id',991)->update(['cate_ids'=>'','time_type'=>2]);
    fixture('salary_agent',['staff_id'=>88101,'store_id'=>901,'date'=>'2026-10-10']);
    verify($read()['rows'][0]['xiao_money'] === 10.0, 'Substitute-day rule uses only registered business dates');
    Db::name('salary_field')->where('key','yingfagongzi')->update(['info'=>'shifagongzi+1']);
    $cycle = $read();
    verify($cycle['rows'][0]['yingfagongzi'] === null && $cycle['rows'][0]['shifagongzi'] === null, 'Cyclic formula configuration fails explicitly');
    verify(Db::name('salary_info')->value('salary_info') === json_encode($manual), 'No historical or manual record mutation');
    $all = $read([901],['_report_scope'=>['mode'=>'stores']]);
    verify(count($all['rows']) === 2, 'Store authority lists all people without own pagination');
    $emptyManual = $all['rows'][1];
    verify($emptyManual['canbu'] === 0.0 && $emptyManual['manual_values']['canbu'] === null,
        'Missing manual amount defaults to zero for calculation while raw null survives');
    verify(!array_filter($emptyManual['gaps'], static function ($gap) { return $gap['code'] === 'MANUAL_VALUE_MISSING'; }),
        'Missing optional manual values are not mapping gaps');
    Db::name('salary_field')->where('key','yingfagongzi')->update(['info'=>'jixiao+xiao_money+work_get+canbu']);
    Db::name('position_yeji')->where('id',991)->update(['time_type'=>1]);
    $labor = $fact(['performance_type'=>'labor_performance_allocated','amount_cents'=>33005,
        'labor_fee_amount_cents'=>155,'project_count_decimal'=>'0.300000','role_snapshot'=>'craftsman']);
    fixture('cashier_v3_entitlement_service_fact',['id'=>991,'tenant_id'=>'0','service_fact_id'=>'r51-service-991',
        'checkout_request_id'=>$labor['checkout_request_id'],'source_line_id'=>$labor['source_line_id'],
        'store_id'=>901,'member_id'=>77881,'member_name_snapshot'=>'测试会员','business_date'=>'2026-10-10',
        'service_status'=>'completed','service_object'=>'self','friend_counts_as_customer'=>1,
        'labor_amount_cents'=>33005,'labor_fee_amount_cents'=>155,'craftsmen_snapshot_json'=>'[]']);
    $worked = $read()['rows'][0];
    verify($worked['labor_performance'] === 330.05 && $worked['work_get'] === 66.01, 'Labour commission uses sales threshold and labour base');
    verify($worked['labor_fee'] === 1.55 && $worked['xiangmushu'] === 0.3, 'Independent labour fee and fractional project metric');
    verify($worked['keci'] === 1.0, 'Service visits come from actual service-customer Reader');
    fixture('cashier_v3_service_record_void_operation',['tenant_id'=>'0','store_id'=>901,'service_fact_id'=>991,'status'=>'succeeded']);
    $voidLabor = $read()['rows'][0];
    verify($voidLabor['labor_performance'] === 0.0 && $voidLabor['labor_fee'] === 0.0 && $voidLabor['keci'] === 0.0,
        'Service void removes labour, fee and visits via complete registered Reader');
    // 充值及其后续作废必须同样经过日维度 Reader 的充值身份过滤。
    $fact(['order_id'=>'RCH:991','amount_cents'=>44400]);
    verify($read()['rows'][0]['sales_performance'] === 523.95, 'Allocated recharge enters personal cash performance');
    fixture('cashier_v3_order_lifecycle_operation',['tenant_id'=>'0','source_order_id'=>'991','source_type'=>'recharge',
        'operation_type'=>'void','status'=>'succeeded','operation_id'=>'r51-recharge-void','operation_no'=>'r51-recharge-void','command_idempotency_key'=>'r51-recharge-void']);
    verify($read()['rows'][0]['sales_performance'] === 79.95, 'Recharge void cannot survive in personal wage basis');
    $fact(['order_id'=>'DEBT:R51-991','business_date'=>'2026-10-23','amount_cents'=>20000]);
    verify($read()['rows'][0]['sales_performance'] === 279.95, 'Later successful debt repayment enters its actual business day');
    verify($read([901],['as_of_date'=>'2026-10-22'])['rows'][0]['sales_performance'] === 79.95, 'Debt repayment does not leak into earlier cutoff');
    fixture('cashier_v3_balance_fact',['tenant_id'=>'0','fact_id'=>'r51-balance-991','natural_key'=>'r51-balance-991',
        'store_id'=>901,'order_id'=>'r51-balance-order','business_date'=>'2026-10-22','status'=>'effective',
        'balance_change_type'=>'order_payment','principal_delta_cents'=>-10000,'bonus_delta_cents'=>-2000]);
    fixture('position_yeji',['id'=>995,'position_id'=>991,'position_level_id'=>992,'status'=>1,'commission'=>'5',
        'cate_ids'=>'','range'=>'0-1000','type'=>3,'range_type'=>1,'time_type'=>1,'has_recharge'=>0]);
    $balance = $read()['rows'][0];
    verify($balance['mendiankoukayeji'] === 120.0 && $balance['mendiankoukaticheng'] === 6.0,
        'Store balance deduction includes principal and bonus once, independently of personal cash');
    verify($balance['sales_performance'] === 279.95, 'Balance payment cannot create cash performance');
    fixture('position_yeji',['id'=>996,'position_id'=>991,'position_level_id'=>992,'status'=>1,'commission'=>'5',
        'cate_ids'=>'','range'=>'0-1000','type'=>4,'range_type'=>1,'time_type'=>1,'has_recharge'=>0]);
    verify($read()['rows'][0]['mendianshijikoukaticheng'] === 6.0, 'Type4 keeps balance debit when no same-day member recharge exists');
    fixture('position_yeji',['id'=>997,'position_id'=>991,'position_level_id'=>992,'status'=>1,'commission'=>'5',
        'cate_ids'=>'','range'=>'0-1000','type'=>1,'range_type'=>1,'time_type'=>1,'has_recharge'=>1]);
    verify($read()['rows'][0]['mendianxianjinticheng'] === 0.0, 'Approved store cash uses actual registered cash rather than fabricated allocation facts');
    Db::name('salary_info')->where('staff_id',88101)->update(['salary_info'=>json_encode(array_replace($manual,['canbu'=>'bad']))]);
    $badManual = $read();
    verify($badManual['rows'][0]['canbu'] === null && !in_array('canbu',$badManual['sum_fields'],true), 'Malformed manual amount is not silently zero');
    // 独立人员/门店验证新增技术映射，避免以其他规则或缺失身份掩盖分类与充值数值。
    fixture('system_store',['id'=>903,'name'=>'分类和消耗验证店']);
    fixture('employee',['id'=>99103,'phone'=>'13900099103','name'=>'分类员工','status'=>1,'is_del'=>0]);
    fixture('position',['id'=>994,'name'=>'分类岗位','status'=>1]);
    fixture('system_store_staff',['id'=>88105,'employee_id'=>99103,'store_id'=>903,'position_level'=>992,'status'=>1,'is_del'=>0]);
    fixture('staff_job_position',['employee_id'=>99103,'store_id'=>903,'staff_id'=>88105,'position_id'=>994,
        'start_time'=>1,'end_time'=>0,'status'=>1,'is_del'=>0,'request_token'=>'r51-category-job']);
    foreach ([[501,0],[502,501],[503,501],[601,0]] as [$id,$parent])
        fixture('store_product_category',['id'=>$id,'pid'=>$parent,'type'=>0,'relation_id'=>0,'cate_name'=>'验证分类'.$id]);
    foreach ([[101,0,0,0,502,6],[102,0,0,0,503,6],[103,0,0,0,601,0],
        [201,101,1,903,0,6],[202,102,1,903,0,6],[203,103,1,903,0,0]] as [$id,$pid,$type,$owner,$category,$kind])
        fixture('store_product',['id'=>$id,'pid'=>$pid,'type'=>$type,'relation_id'=>$owner,'cate_id'=>(string)$category,'product_type'=>$kind]);
    $card = $fact(['store_id'=>903,'employee_id'=>99103,'amount_cents'=>3335,'business_date'=>'2026-10-12',
        'source_document_type'=>'sales_order','checkout_request_id'=>'r51-card-request','source_line_id'=>'r51-card-line']);
    $fact(['store_id'=>903,'employee_id'=>99102,'amount_cents'=>6672,'business_date'=>'2026-10-12',
        'source_document_type'=>'sales_order','checkout_request_id'=>'r51-card-request','source_line_id'=>'r51-card-line','order_id'=>$card['order_id']]);
    fixture('cashier_v3_sale_fact',['tenant_id'=>'0','fact_id'=>'r51-card-sale','natural_key'=>'r51-card-sale',
        'store_id'=>903,'order_id'=>$card['order_id'],'checkout_request_id'=>'r51-card-request','source_line_id'=>'r51-card-line',
        'business_date'=>'2026-10-12','status'=>'effective','fact_direction'=>'forward','source_type'=>'card','item_id'=>999999,'sale_amount_cents'=>10007]);
    foreach ([[201,502,3335],[202,503,6672]] as [$product,$categoryId,$amount]) {
        fixture('cashier_v3_card_sale_item_allocation_fact',['tenant_id'=>'0','store_id'=>903,'sale_fact_id'=>'r51-card-sale',
            'natural_key'=>'r51-card-item-'.$product,'allocation_fact_id'=>'r51-card-item-'.$product,
            'card_receipt_id'=>'r51-receipt','category_id_snapshot'=>$categoryId,'component_product_id'=>$product,
            'sale_amount_cents'=>$amount,'configured_amount_cents'=>$amount,'cash_performance_amount_cents'=>$amount,'status'=>'effective']);
        fixture('cashier_v3_card_sale_category_allocation_fact',['tenant_id'=>'0','store_id'=>903,'sale_fact_id'=>'r51-card-sale',
            'natural_key'=>'r51-card-category-'.$categoryId,'allocation_fact_id'=>'r51-card-category-'.$categoryId,
            'card_receipt_id'=>'r51-receipt','category_id_snapshot'=>$categoryId,'component_product_id'=>$product,
            'sale_amount_cents'=>$amount,'configured_amount_cents'=>$amount,'status'=>'effective']);
    }
    fixture('position_yeji',['id'=>1101,'position_id'=>994,'position_level_id'=>992,'status'=>1,'commission'=>'10',
        'cate_ids'=>'502','range'=>'0-999999','type'=>1,'range_type'=>2,'time_type'=>1,'has_recharge'=>0]);
    $categoryRead = function () use ($read) { return $read([903],['_report_scope'=>['mode'=>'self_participant','employee_id'=>99103]])['rows'][0]; };
    $split = $categoryRead();
    verify($split['xiao_yeji'] === 11.11 && $split['xiao_money'] === 1.11, 'Card component takes allocated personal cents, not whole order cash');
    Db::name('position_yeji')->where('id',1101)->update(['cate_ids'=>'503']);
    verify($categoryRead()['xiao_yeji'] === 22.24, 'Stable final component receives personal cent remainder');
    Db::name('position_yeji')->where('id',1101)->update(['cate_ids'=>'501']);
    verify($categoryRead()['xiao_yeji'] === 33.35, 'Parent categories expand without repeating component amounts');
    $refund = $fact(['store_id'=>903,'employee_id'=>99103,'amount_cents'=>-1001,'business_date'=>'2026-10-13',
        'source_document_type'=>'manual_refund','fact_direction'=>'reversal','reversal_of'=>$card['fact_id']]);
    verify($categoryRead()['xiao_yeji'] === 23.34, 'Signed category reversal uses original stable sale identity');
    $recharge = $fact(['store_id'=>903,'employee_id'=>99103,'amount_cents'=>10000,'source_document_type'=>'recharge']);
    $fact(['store_id'=>903,'employee_id'=>99103,'amount_cents'=>-2000,'source_document_type'=>'manual_refund',
        'fact_direction'=>'reversal','reversal_of'=>$recharge['fact_id']]);
    $priorRecharge = $fact(['store_id'=>903,'employee_id'=>99103,'amount_cents'=>7000,'source_document_type'=>'recharge_debt_repayment','business_date'=>'2026-09-30']);
    $fact(['store_id'=>903,'employee_id'=>99103,'amount_cents'=>-1000,'source_document_type'=>'manual_refund',
        'fact_direction'=>'reversal','reversal_of'=>$priorRecharge['fact_id']]);
    verify($categoryRead()['xiao_yeji'] === 23.34, 'Exclude recharge follows reversal chain including prior-month recharge supplement');
    Db::name('position_yeji')->where('id',1101)->update(['has_recharge'=>1]);
    verify($categoryRead()['xiao_yeji'] === 93.34, 'Include recharge adds only own signed allocation, independent of category');
    Db::name('position_yeji')->where('id',1101)->update(['has_recharge'=>0,'cate_ids'=>'']);
    verify($categoryRead()['xiao_yeji'] === 23.34, 'Personal exclusion also works without category filter');
    $productSale = $fact(['store_id'=>903,'employee_id'=>99103,'amount_cents'=>3005,'source_document_type'=>'sales_order']);
    fixture('cashier_v3_sale_fact',['tenant_id'=>'0','fact_id'=>'r51-product-sale','natural_key'=>'r51-product-sale','store_id'=>903,
        'order_id'=>$productSale['order_id'],'checkout_request_id'=>$productSale['checkout_request_id'],
        'source_line_id'=>$productSale['source_line_id'],'status'=>'effective','fact_direction'=>'forward','source_type'=>'product','item_id'=>203]);
    Db::name('position_yeji')->where('id',1101)->update(['cate_ids'=>'601']);
    verify($categoryRead()['product_yeji'] === 30.05 && $categoryRead()['product_money'] === 3.01, 'Product rule is classified by current configured product identity');
    Db::name('position_yeji')->where('id',1101)->update(['cate_ids'=>'502']);
    Db::name('store_product')->where('id',101)->update(['cate_id'=>'503']);
    verify($categoryRead()['xiao_yeji'] === 0.0, 'Current platform category change moves historical component without rewriting cash');
    Db::name('store_product')->where('id',101)->update(['cate_id'=>'502']);
    $consume = $fact(['store_id'=>903,'employee_id'=>0,'performance_type'=>'consumption_performance_recorded',
        'amount_cents'=>12005,'source_document_type'=>'service','business_date'=>'2026-10-12']);
    fixture('cashier_v3_entitlement_service_fact',['id'=>992,'tenant_id'=>'0','service_fact_id'=>'r51-consume-service','natural_key'=>'r51-consume-service',
        'checkout_request_id'=>$consume['checkout_request_id'],'source_line_id'=>$consume['source_line_id'],
        'store_id'=>903,'project_id'=>201,'member_id'=>77882,'service_object'=>'self','friend_counts_as_customer'=>1,
        'business_date'=>'2026-10-12','service_status'=>'completed','craftsmen_snapshot_json'=>'[]']);
    $fact(['store_id'=>903,'employee_id'=>99103,'performance_type'=>'labor_performance_allocated','amount_cents'=>3505,
        'source_document_type'=>'service','checkout_request_id'=>$consume['checkout_request_id'],
        'source_line_id'=>$consume['source_line_id'],'business_date'=>'2026-10-12']);
    fixture('position_yeji',['id'=>1102,'position_id'=>994,'position_level_id'=>992,'status'=>1,'commission'=>'10',
        'cate_ids'=>'','range'=>'0-999999','type'=>2,'range_type'=>1,'time_type'=>1,'has_recharge'=>1]);
    fixture('position_yeji',['id'=>1103,'position_id'=>994,'position_level_id'=>992,'status'=>1,'commission'=>'20',
        'cate_ids'=>'','range'=>'0-999999','type'=>2,'range_type'=>2,'time_type'=>1,'has_recharge'=>1]);
    $approved = $categoryRead();
    verify($approved['mendianlaodongyeji'] === 120.05 && $approved['mendianlaodongticheng'] === 12.01,
        'Approved store type2 uses consumption, not personal labour or balance debit');
    verify($approved['work_yeji'] === 35.05 && $approved['work_get'] === 7.01,
        'Approved personal type2 ignores legacy recharge-mixing flag');
    Db::name('position_yeji')->where('id',1102)->update(['cate_ids'=>'502']);
    Db::name('position_yeji')->where('id',1103)->update(['cate_ids'=>'502']);
    $filteredConsumption = $categoryRead();
    verify($filteredConsumption['mendianlaodongyeji'] === 120.05 && $filteredConsumption['work_yeji'] === 35.05,
        'Store consumption and personal labour category filters preserve separate authoritative amounts');
    fixture('cashier_v3_service_record_void_operation',['tenant_id'=>'0','store_id'=>903,'service_fact_id'=>992,'status'=>'succeeded',
        'operation_id'=>'r51-consume-void','operation_no'=>'r51-consume-void','command_idempotency_key'=>'r51-consume-void']);
    verify($categoryRead()['mendianlaodongyeji'] === 0.0 && $categoryRead()['work_yeji'] === 0.0,
        'Voiding completed service removes store consumption and personal labour categories');
    $labels = array_column($salary->definitions('basic_salary_realtime'),'label','key');
    verify($labels['mendianlaodongyeji'] === '门店耗卡业绩' && $labels['mendianlaodongticheng'] === '门店耗卡提成',
        'Approved user-visible names retain compatible business field keys');
    fixture('employee',['id'=>99104,'phone'=>'13900099104','name'=>'补交新销售人','status'=>1,'is_del'=>0]);
    fixture('system_store_staff',['id'=>88106,'employee_id'=>99104,'store_id'=>903,'position_level'=>992,'status'=>1,'is_del'=>0]);
    fixture('staff_job_position',['employee_id'=>99104,'store_id'=>903,'staff_id'=>88106,'position_id'=>994,
        'start_time'=>1,'end_time'=>0,'status'=>1,'is_del'=>0,'request_token'=>'r51-repayment-job']);
    $repayment = $fact(['store_id'=>903,'employee_id'=>99104,'amount_cents'=>5003,'business_date'=>'2026-10-14',
        'source_document_type'=>'debt_repayment','order_id'=>'RP-R51-CATEGORY']);
    fixture('cashier_v3_payment_fact',['tenant_id'=>'0','fact_id'=>'r51-repayment-receipt','natural_key'=>'r51-repayment-receipt',
        'store_id'=>903,'order_id'=>'RP-R51-CATEGORY','source_document_type'=>'debt_repayment','fact_type'=>'payment_collected',
        'status'=>'effective','fact_direction'=>'forward','business_date'=>'2026-10-14','payment_method'=>'cash','amount_cents'=>10007]);
    fixture('cashier_v3_payment_sale_allocation_fact',['tenant_id'=>'0','store_id'=>903,'allocation_fact_id'=>'r51-repayment-allocation',
        'natural_key'=>'r51-repayment-allocation','payment_fact_id'=>'r51-repayment-receipt','sale_fact_id'=>'r51-card-sale',
        'order_id'=>$card['order_id'],'source_line_id'=>'r51-card-line','amount_cents'=>10007,'status'=>'effective',
        'business_date'=>'2026-10-14','payment_method'=>'cash']);
    $repaymentRead = function () use ($read) { return $read([903],['_report_scope'=>['mode'=>'self_participant','employee_id'=>99104]])['rows'][0]; };
    verify($repaymentRead()['xiao_yeji'] === 16.67, 'Debt repayment links original card components for newly assigned salesperson');
    Db::name('position_yeji')->where('id',1101)->update(['cate_ids'=>'501']);
    verify($repaymentRead()['xiao_yeji'] === 50.03, 'Debt repayment uses personal share rather than full receipt');
    Db::name('position_yeji')->where('id',1101)->update(['cate_ids'=>'502']);
    $fact(['store_id'=>903,'employee_id'=>99104,'amount_cents'=>-1001,'business_date'=>'2026-10-15',
        'fact_direction'=>'reversal','source_document_type'=>'manual_refund','reversal_of'=>$repayment['fact_id']]);
    verify($repaymentRead()['xiao_yeji'] === 13.34, 'Debt repayment reversal retains original item weights but its own business day');
    fixture('position_yeji',['id'=>1104,'position_id'=>994,'position_level_id'=>992,'status'=>1,'commission'=>'10',
        'cate_ids'=>'','range'=>'0-999999','type'=>1,'range_type'=>1,'time_type'=>1,'has_recharge'=>1]);
    $fact(['store_id'=>903,'employee_id'=>99104,'employee_type_snapshot'=>'partner','amount_cents'=>9000,'source_document_type'=>'sales_order']);
    verify($categoryRead()['mendianxianjinyeji'] === 100.07, 'Approved store cash does not subtract allocated partner performance');
    fixture('store_product',['id'=>104,'type'=>0,'relation_id'=>0,'product_type'=>6,'cate_id'=>'502','is_del'=>0]);
    fixture('system_business_product',['business_key'=>'member_balance','product_id'=>104]);
    fixture('cashier_v3_payment_fact',['tenant_id'=>'0','fact_id'=>'r51-store-recharge','natural_key'=>'r51-store-recharge',
        'store_id'=>903,'member_id'=>77790,'order_id'=>'RCH:800','source_document_type'=>'recharge','fact_type'=>'payment_collected',
        'status'=>'effective','fact_direction'=>'forward','business_date'=>'2026-10-15','payment_method'=>'cash','amount_cents'=>10000]);
    fixture('cashier_v3_payment_fact',['tenant_id'=>'0','fact_id'=>'r51-store-recharge-reverse','natural_key'=>'r51-store-recharge-reverse',
        'store_id'=>903,'member_id'=>77790,'order_id'=>'RCH:800','source_document_type'=>'recharge','fact_type'=>'payment_collected',
        'status'=>'effective','fact_direction'=>'reversal','reversal_of'=>'r51-store-recharge','business_date'=>'2026-10-16','payment_method'=>'cash','amount_cents'=>-2000]);
    verify($categoryRead()['mendianxianjinyeji'] === 200.07 && $categoryRead()['mendianxianjinticheng'] === 20.01,
        'Store cash includes successful recharge using positive cash metric');
    Db::name('position_yeji')->where('id',1104)->update(['has_recharge'=>0]);
    verify($categoryRead()['mendianxianjinyeji'] === 100.07, 'Exclusion removes positive recharge receipts rather than net recharge or balance principal');
    Db::name('position_yeji')->where('id',1104)->update(['cate_ids'=>'502']);
    verify($categoryRead()['mendianxianjinyeji'] === 33.35, 'Store cash category uses registered component projection');
    Db::name('position_yeji')->where('id',1104)->update(['has_recharge'=>1]);
    verify($categoryRead()['mendianxianjinyeji'] === 133.35, 'Classified recharge follows registered balance project current category');
    fixture('position_yeji',['id'=>1105,'position_id'=>994,'position_level_id'=>992,'status'=>1,'commission'=>'5',
        'cate_ids'=>'','range'=>'0-999999','type'=>4,'range_type'=>1,'time_type'=>1,'has_recharge'=>0]);
    foreach ([[1,77791,'2026-10-18',10000,2000,201],[2,77791,'2026-10-19',5000,0,202],
        [3,77792,'2026-10-18',3000,0,201],[4,0,'2026-10-18',1000,0,201],[5,77791,'2026-10-18',2500,0,201]]
        as [$index,$member,$day,$principal,$bonus,$product]) {
        fixture('cashier_v3_balance_fact',['tenant_id'=>'0','fact_id'=>'r51-type4-debit-'.$index,'natural_key'=>'r51-type4-debit-'.$index,
            'store_id'=>903,'member_id'=>$member,'order_id'=>'r51-type4-order-'.$index,'checkout_request_id'=>'r51-type4-request-'.$index,
            'business_date'=>$day,'status'=>'effective','fact_direction'=>'forward','balance_change_type'=>'order_payment',
            'principal_delta_cents'=>-$principal,'bonus_delta_cents'=>-$bonus]);
        fixture('cashier_v3_sale_fact',['tenant_id'=>'0','fact_id'=>'r51-type4-sale-'.$index,'natural_key'=>'r51-type4-sale-'.$index,
            'store_id'=>903,'member_id'=>$member,'order_id'=>'r51-type4-order-'.$index,'checkout_request_id'=>'r51-type4-request-'.$index,
            'source_line_id'=>'r51-type4-line-'.$index,'status'=>'effective','fact_direction'=>'forward','source_type'=>'project',
            'item_id'=>$product,'sale_amount_cents'=>20000,'debt_amount_cents'=>0,'coupon_payment_cents'=>0]);
    }
    verify($categoryRead()['mendianshijikoukayeji'] === 235.0, 'Type4 starts from net principal plus bonus balance debits');
    fixture('cashier_v3_balance_fact',['tenant_id'=>'0','fact_id'=>'r51-zero-cash-recharge','natural_key'=>'r51-zero-cash-recharge',
        'store_id'=>903,'member_id'=>77791,'order_id'=>'RCH:801','business_date'=>'2026-10-18','status'=>'effective',
        'fact_direction'=>'forward','balance_change_type'=>'recharge_credit','principal_delta_cents'=>0,'bonus_delta_cents'=>2000]);
    verify($categoryRead()['mendianshijikoukayeji'] === 90.0, 'Zero-cash bonus recharge excludes both same-day member debits entirely, not recharge amount');
    fixture('cashier_v3_balance_fact',['tenant_id'=>'0','fact_id'=>'r51-pending-recharge','natural_key'=>'r51-pending-recharge',
        'store_id'=>903,'member_id'=>77792,'order_id'=>'RCH:802','business_date'=>'2026-10-18','status'=>'pending',
        'fact_direction'=>'forward','balance_change_type'=>'recharge_credit','principal_delta_cents'=>10000]);
    fixture('cashier_v3_balance_fact',['tenant_id'=>'0','fact_id'=>'r51-other-store-recharge','natural_key'=>'r51-other-store-recharge',
        'store_id'=>902,'member_id'=>77792,'order_id'=>'RCH:803','business_date'=>'2026-10-18','status'=>'effective',
        'fact_direction'=>'forward','balance_change_type'=>'recharge_credit','principal_delta_cents'=>10000]);
    fixture('cashier_v3_balance_fact',['tenant_id'=>'0','fact_id'=>'r51-other-day-recharge','natural_key'=>'r51-other-day-recharge',
        'store_id'=>903,'member_id'=>77792,'order_id'=>'RCH:804','business_date'=>'2026-10-19','status'=>'effective',
        'fact_direction'=>'forward','balance_change_type'=>'recharge_credit','principal_delta_cents'=>10000]);
    verify($categoryRead()['mendianshijikoukayeji'] === 90.0, 'Pending, other-store and other-day recharges do not exclude authorized debit');
    fixture('cashier_v3_balance_fact',['tenant_id'=>'0','fact_id'=>'r51-type4-reversal','natural_key'=>'r51-type4-reversal',
        'store_id'=>903,'member_id'=>77791,'order_id'=>'r51-type4-refund','business_date'=>'2026-10-19','status'=>'effective',
        'fact_direction'=>'reversal','reversal_of'=>'r51-type4-debit-1','balance_change_type'=>'order_payment','principal_delta_cents'=>2000]);
    verify($categoryRead()['mendianshijikoukayeji'] === 70.0, 'Type4 preserves signed balance reversal on its own business day');
    fixture('cashier_v3_order_lifecycle_operation',['tenant_id'=>'0','source_order_id'=>'801','source_type'=>'recharge',
        'operation_type'=>'void','status'=>'succeeded','operation_id'=>'r51-zero-cash-void','operation_no'=>'r51-zero-cash-void','command_idempotency_key'=>'r51-zero-cash-void']);
    verify($categoryRead()['mendianshijikoukayeji'] === 215.0, 'Voided recharge is no longer a same-day exclusion event');
    fixture('cashier_v3_balance_fact',['tenant_id'=>'0','fact_id'=>'r51-debt-recharge-credit','natural_key'=>'r51-debt-recharge-credit',
        'store_id'=>903,'member_id'=>77791,'order_id'=>'r51-debt-recharge','business_date'=>'2026-10-19','status'=>'effective',
        'fact_direction'=>'forward','balance_change_type'=>'recharge_debt_repayment_credit','principal_delta_cents'=>5000]);
    verify($categoryRead()['mendianshijikoukayeji'] === 185.0, 'Successful debt recharge credit is an event even without matching cash receipt');
    fixture('cashier_v3_balance_fact',['tenant_id'=>'0','fact_id'=>'r51-debt-credit-reversal','natural_key'=>'r51-debt-credit-reversal',
        'store_id'=>903,'member_id'=>77791,'order_id'=>'r51-debt-recharge-reverse','business_date'=>'2026-10-20','status'=>'effective',
        'fact_direction'=>'reversal','reversal_of'=>'r51-debt-recharge-credit','balance_change_type'=>'recharge_debt_repayment_credit','principal_delta_cents'=>-5000]);
    verify($categoryRead()['mendianshijikoukayeji'] === 215.0, 'Effective recharge reversal removes original recharge marker');
    Db::name('position_yeji')->where('id',1105)->update(['cate_ids'=>'502']);
    verify($categoryRead()['mendianshijikoukayeji'] === 165.0, 'Categorized type4 filters original sale lines and follows signed balance origin');
    fixture('salary_agent',['staff_id'=>88105,'store_id'=>903,'date'=>'2026-10-18']);
    Db::name('position_yeji')->where('id',1105)->update(['time_type'=>2]);
    verify($categoryRead()['mendianshijikoukayeji'] === 185.0, 'Categorized type4 respects registered substitute business days');
    Db::name('position_yeji')->where('id',1105)->update(['time_type'=>1]);
    // 新口径：全历史首次全款疗程卡，归原成交已记录销售人员，员工+会员去重。
    foreach ([905,906] as $store) fixture('system_store',['id'=>$store,'name'=>'首次全款验证店'.$store]);
    fixture('position',['id'=>995,'name'=>'新客岗位','status'=>1]);
    foreach ([99105,99106] as $employee) {
        fixture('employee',['id'=>$employee,'phone'=>'139000'.$employee,'name'=>'新客员工'.$employee,'status'=>1,'is_del'=>0]);
        fixture('system_store_staff',['id'=>$employee,'employee_id'=>$employee,'store_id'=>905,'position_level'=>992,'status'=>1,'is_del'=>0]);
        fixture('staff_job_position',['employee_id'=>$employee,'store_id'=>905,'staff_id'=>$employee,'position_id'=>995,
            'start_time'=>1,'end_time'=>0,'status'=>1,'is_del'=>0,'request_token'=>'r51-first-job-'.$employee]);
    }
    fixture('position_yeji',['id'=>1201,'position_id'=>995,'position_level_id'=>992,'status'=>1,'commission'=>'10',
        'cate_ids'=>'','range'=>'0-999999','type'=>1,'range_type'=>2,'time_type'=>1,'has_recharge'=>1]);
    Db::name('salary_field')->where('key','yingfagongzi')->update(['info'=>'jixiao+xiao_money+work_get+canbu+jiangjin']);
    $eventSequence = 0;
    $course = function (int $member, int $store, string $order, string $day, array $owners, string $kind='first_course_completed', string $status='effective') use ($fact,&$eventSequence): void {
        ++$eventSequence;
        fixture('cashier_v3_customer_lifecycle_fact',['tenant_id'=>'0','fact_id'=>'r51-first-'.$eventSequence,
            'natural_key'=>'r51-first-'.$eventSequence,'store_id'=>$store,'member_id'=>$member,'event_type'=>$kind,'status'=>$status,
            'related_order_id'=>$order,'business_date'=>$day,'occurred_at'=>strtotime($day.' 10:00:00 Asia/Shanghai')+$eventSequence]);
        fixture('cashier_v3_sale_fact',['tenant_id'=>'0','fact_id'=>'r51-first-sale-'.$eventSequence,'natural_key'=>'r51-first-sale-'.$eventSequence,
            'store_id'=>$store,'member_id'=>$member,'order_id'=>$order,'checkout_request_id'=>'r51-first-request-'.$order,
            'source_line_id'=>'r51-first-line-'.$order,'source_type'=>'card','item_id'=>99999,'status'=>'effective','fact_direction'=>'forward',
            'business_date'=>$day,'sale_amount_cents'=>10000,'debt_amount_cents'=>$kind==='course_pending_settlement'?2000:0]);
        foreach ($owners as $owner) $fact(['store_id'=>$store,'member_id'=>$member,'employee_id'=>$owner,'employee_name_snapshot'=>'新客员工'.$owner,
            'order_id'=>$order,'checkout_request_id'=>'r51-first-request-'.$order,'source_line_id'=>'r51-first-line-'.$order,
            'source_document_type'=>'sales_order','business_date'=>$day,'amount_cents'=>0]);
    };
    $newCustomerRead = function (int $employee=99105) use ($read) { return $read([905],['_report_scope'=>['mode'=>'self_participant','employee_id'=>$employee]]); };
    $course(88001,905,'R51-FIRST-1','2026-10-08',[99105,99105,99106]);
    verify($newCustomerRead()['rows'][0]['xinkeshu'] === 1 && $newCustomerRead(99106)['rows'][0]['xinkeshu'] === 1,
        'First fully paid course counts once per recorded employee and member despite duplicate personnel facts');
    verify($newCustomerRead()['rows'][0]['jiangjin'] === 30.0 && $newCustomerRead()['rows'][0]['shifagongzi'] === 30.0,
        'Confirmed new customer metric drives configured bonus and full salary formula');
    fixture('cashier_v3_customer_lifecycle_fact',['tenant_id'=>'0','fact_id'=>'r51-first-duplicate','natural_key'=>'r51-first-duplicate',
        'store_id'=>905,'member_id'=>88001,'event_type'=>'first_course_completed','status'=>'effective','related_order_id'=>'R51-FIRST-1',
        'business_date'=>'2026-10-09','occurred_at'=>strtotime('2026-10-09 Asia/Shanghai')]);
    verify($newCustomerRead()['rows'][0]['xinkeshu'] === 1, 'Repeated completed event does not repeat the same employee/member');
    $course(88002,906,'R51-FIRST-OTHER-STORE','2026-09-01',[99105]);
    $course(88002,905,'R51-LATER-CURRENT-STORE','2026-10-09',[99105]);
    $course(88003,905,'R51-FIRST-PRIOR-MONTH','2026-09-03',[99105]);
    $course(88003,905,'R51-LATER-CURRENT-MONTH','2026-10-10',[99105]);
    verify($newCustomerRead()['rows'][0]['xinkeshu'] === 1, 'Global historical first in prior month or another store blocks month-local first');
    $course(88004,905,'R51-FIRST-DEBT','2026-09-20',[99105],'course_pending_settlement');
    verify($newCustomerRead()['rows'][0]['xinkeshu'] === 1, 'Deposit-only pending course is not first fully paid customer');
    fixture('cashier_v3_customer_lifecycle_fact',['tenant_id'=>'0','fact_id'=>'r51-first-debt-completed','natural_key'=>'r51-first-debt-completed',
        'store_id'=>905,'member_id'=>88004,'event_type'=>'first_course_completed','status'=>'effective','related_order_id'=>'R51-FIRST-DEBT',
        'business_date'=>'2026-10-15','occurred_at'=>strtotime('2026-10-15 Asia/Shanghai')]);
    $fact(['store_id'=>905,'member_id'=>88004,'employee_id'=>99106,'order_id'=>'R51-DEBT-PAYMENT','source_document_type'=>'debt_repayment',
        'business_date'=>'2026-10-15','amount_cents'=>0]);
    verify($newCustomerRead()['rows'][0]['xinkeshu'] === 2 && $newCustomerRead(99106)['rows'][0]['xinkeshu'] === 1,
        'Full payment completion belongs to original sale personnel, not repayment personnel');
    verify($newCustomerRead()['rows'][0]['jiangjin'] === 60.0, 'Two distinct qualified members produce two bonuses');
    $course(88005,905,'R51-FIRST-REFUND','2026-10-11',[99105]);
    fixture('cashier_v3_order_lifecycle_operation',['tenant_id'=>'0','source_type'=>'sales','source_order_id'=>'R51-FIRST-REFUND',
        'operation_type'=>'refund','status'=>'pending','operation_id'=>'r51-first-refund','operation_no'=>'r51-first-refund','command_idempotency_key'=>'r51-first-refund']);
    verify($newCustomerRead()['rows'][0]['xinkeshu'] === 3, 'Pending refund is not a succeeded return');
    Db::name('cashier_v3_order_lifecycle_operation')->where('operation_id','r51-first-refund')->update(['status'=>'succeeded']);
    verify($newCustomerRead()['rows'][0]['xinkeshu'] === 3, 'Ordinary succeeded refund retains lifecycle first-course qualification');
    // 混合单只退产品不能取消疗程首客；独立退款单通过原销售行关联，覆盖旧额外 adjustment 排除路径。
    Db::name('cashier_v3_order_lifecycle_operation')->where('operation_id','r51-first-refund')->update(['status'=>'pending']);
    fixture('cashier_v3_sale_fact',['tenant_id'=>'0','fact_id'=>'r51-first-mixed-product','natural_key'=>'r51-first-mixed-product',
        'store_id'=>905,'member_id'=>88005,'order_id'=>'R51-FIRST-REFUND','source_line_id'=>'r51-first-product-line',
        'source_type'=>'product','status'=>'effective','fact_direction'=>'forward','business_date'=>'2026-10-11','sale_amount_cents'=>2000]);
    fixture('cashier_v3_order_lifecycle_operation',['tenant_id'=>'0','source_type'=>'sales','source_order_id'=>'R51-PRODUCT-REFUND-DOCUMENT',
        'operation_type'=>'refund','status'=>'succeeded','operation_id'=>'r51-first-product-refund','operation_no'=>'r51-first-product-refund',
        'command_idempotency_key'=>'r51-first-product-refund']);
    fixture('cashier_v3_refund_adjustment',['tenant_id'=>'0','store_id'=>905,'member_id'=>88005,
        'adjustment_id'=>'r51-first-product-refund','operation_id'=>'r51-first-product-refund','command_idempotency_key'=>'r51-first-product-refund',
        'target_type'=>'document','source_order_id'=>'R51-PRODUCT-REFUND-DOCUMENT','source_line_id'=>'r51-first-product-line',
        'source_sales_order_id'=>'R51-FIRST-REFUND','source_sales_line_id'=>'r51-first-product-line',
        'refund_amount_cents'=>2000,'business_date'=>'2026-10-18','status'=>'effective']);
    verify($newCustomerRead()['rows'][0]['xinkeshu'] === 3, 'Product-only refund on mixed original order retains card new customer');
    Db::name('cashier_v3_order_lifecycle_operation')->where('operation_id','r51-first-refund')->update(['status'=>'succeeded']);
    $refundedOwner = Db::name('cashier_v3_performance_fact')->where('store_id',905)->where('order_id','R51-FIRST-REFUND')
        ->where('employee_id',99105)->where('fact_direction','forward')->value('fact_id');
    Db::name('cashier_v3_performance_fact')->where('fact_id',$refundedOwner)->update(['amount_cents'=>10000]);
    $fact(['store_id'=>905,'member_id'=>88005,'employee_id'=>99105,'order_id'=>'R51-FIRST-REFUND',
        'source_line_id'=>'r51-first-line-R51-FIRST-REFUND','source_document_type'=>'manual_refund',
        'fact_direction'=>'reversal','reversal_of'=>$refundedOwner,'amount_cents'=>-1000]);
    verify($newCustomerRead()['rows'][0]['xinkeshu'] === 3, 'Partial refund reversal retains original recorded salesperson');
    $fact(['store_id'=>905,'member_id'=>88005,'employee_id'=>99105,'order_id'=>'R51-FIRST-REFUND',
        'source_line_id'=>'r51-first-line-R51-FIRST-REFUND','source_document_type'=>'manual_refund',
        'fact_direction'=>'reversal','reversal_of'=>$refundedOwner,'amount_cents'=>-9000]);
    verify($newCustomerRead()['rows'][0]['xinkeshu'] === 3 && $newCustomerRead()['rows'][0]['jiangjin'] === 90.0,
        'Fully refunded net-zero share retains lifecycle new customer and original salesperson bonus');
    $course(88006,905,'R51-FIRST-VOID','2026-10-12',[99105]);
    fixture('cashier_v3_order_lifecycle_operation',['tenant_id'=>'0','source_type'=>'sales','source_order_id'=>'R51-FIRST-VOID',
        'operation_type'=>'void','status'=>'succeeded','operation_id'=>'r51-first-void','operation_no'=>'r51-first-void','command_idempotency_key'=>'r51-first-void']);
    $course(88007,905,'R51-FIRST-PENDING','2026-10-13',[99105],'first_course_completed','pending');
    $course(0,905,'R51-FIRST-GUEST','2026-10-13',[99105]);
    verify($newCustomerRead()['rows'][0]['xinkeshu'] === 3, 'Voided sales, pending completion facts and guest identity do not qualify');
    $course(88008,905,'R51-FIRST-OTHER-PERSON','2026-10-14',[99106]);
    verify($newCustomerRead()['rows'][0]['xinkeshu'] === 3 && $newCustomerRead(99106)['rows'][0]['xinkeshu'] === 2,
        'Original recorded salesperson attribution does not leak new customers to coworkers');
    $oldPeople = Db::name('cashier_v3_performance_fact')->where('tenant_id','0')->where('store_id',905)->where('employee_id',99105)
        ->where('order_id','R51-FIRST-1')->where('fact_direction','forward')->column('fact_id');
    foreach ($oldPeople as $id) $fact(['store_id'=>905,'member_id'=>88001,'employee_id'=>99105,'order_id'=>'R51-FIRST-1',
        'source_line_id'=>'r51-first-line-R51-FIRST-1','source_document_type'=>'sales_order','fact_direction'=>'reversal','reversal_of'=>$id,'amount_cents'=>0]);
    verify($newCustomerRead()['rows'][0]['xinkeshu'] === 3, 'Reversed monetary allocations do not erase original recorded first-course ownership');
    $cohortScope = $newCustomerRead();
    verify(count($cohortScope['rows']) === 1 && $cohortScope['rows'][0]['store_id'] === 905,
        'Cross-store historical qualification never publishes unauthorized history rows');
    $onlyOwner = Db::name('cashier_v3_performance_fact')->where('store_id',905)->where('order_id','R51-FIRST-OTHER-PERSON')
        ->where('employee_id',99106)->where('fact_direction','forward')->value('fact_id');
    $fact(['store_id'=>905,'member_id'=>88008,'employee_id'=>99106,'order_id'=>'R51-FIRST-OTHER-PERSON',
        'source_line_id'=>'r51-first-line-R51-FIRST-OTHER-PERSON','source_document_type'=>'sales_order',
        'fact_direction'=>'reversal','reversal_of'=>$onlyOwner,'amount_cents'=>0]);
    verify($newCustomerRead(99106)['rows'][0]['xinkeshu'] === 2, 'Even sole salesperson reversed share retains original lifecycle attribution');
    $course(88010,906,'R51-OLD-RETURNED-FIRST','2026-09-02',[99105]);
    fixture('cashier_v3_order_lifecycle_operation',['tenant_id'=>'0','source_type'=>'sales','source_order_id'=>'R51-OLD-RETURNED-FIRST',
        'operation_type'=>'refund','status'=>'succeeded','operation_id'=>'r51-old-first-return','operation_no'=>'r51-old-first-return','command_idempotency_key'=>'r51-old-first-return']);
    $course(88010,905,'R51-NEW-VALID-FIRST','2026-10-16',[99105]);
    verify($newCustomerRead()['rows'][0]['xinkeshu'] === 3, 'Refunded prior-month historical first still blocks a later current-month first');
    fixture('cashier_v3_order_lifecycle_operation',['tenant_id'=>'0','source_type'=>'sales','source_order_id'=>'R51-FIRST-REFUND',
        'operation_type'=>'void','status'=>'succeeded','operation_id'=>'r51-refunded-first-void','operation_no'=>'r51-refunded-first-void',
        'command_idempotency_key'=>'r51-refunded-first-void']);
    verify($newCustomerRead()['rows'][0]['xinkeshu'] === 2, 'Successful void still excludes original course after ordinary full refund');
    // 更正与退款分开：只读真实反向事实+成功原单操作，不用旧 salespeople 快照判断当前归属。
    $course(88100,1906,'R51-OWNER-CORRECTION','2026-10-10',[99105]);
    $ownerReader = new ReflectionMethod(Salary::class,'firstCourseOwners');
    if (PHP_VERSION_ID < 80100) $ownerReader->setAccessible(true);
    $ownersNow = function () use ($ownerReader) {
        return $ownerReader->invoke(new Salary(),1906,['start'=>'2026-10-01','end'=>'2026-10-23']);
    };
    $ownerFact = function (int $employee) {
        return Db::name('cashier_v3_performance_fact')->where('store_id',1906)->where('order_id','R51-OWNER-CORRECTION')
            ->where('employee_id',$employee)->where('fact_direction','forward')->value('fact_id');
    };
    $replaceOwner = function (string $key, string $old, int $employee, string $status='succeeded', array $scope=[]) use ($fact) {
        fixture('cashier_v3_order_lifecycle_operation',array_replace(['tenant_id'=>'0','store_id'=>1906,'source_type'=>'sales',
            'source_order_id'=>'R51-OWNER-CORRECTION','operation_type'=>'personnel_adjustment','status'=>$status,
            'operation_id'=>$key,'operation_no'=>$key,'command_idempotency_key'=>$key],$scope));
        $fact(['store_id'=>1906,'member_id'=>88100,'employee_id'=>99105,'order_id'=>'R51-OWNER-CORRECTION',
            'source_line_id'=>'r51-first-line-R51-OWNER-CORRECTION','source_document_type'=>'sales_order',
            'fact_direction'=>'reversal','reversal_of'=>$old,'amount_cents'=>0,'command_idempotency_key'=>$key]);
        if ($employee > 0) $fact(['store_id'=>1906,'member_id'=>88100,'employee_id'=>$employee,'order_id'=>'R51-OWNER-CORRECTION',
            'source_line_id'=>'r51-first-line-R51-OWNER-CORRECTION','source_document_type'=>'sales_order','amount_cents'=>0,
            'command_idempotency_key'=>$key]);
    };
    $originalOwner = $ownerFact(99105);
    $replaceOwner('r51-owner-pending',$originalOwner,0,'pending');
    verify(array_keys($ownersNow()['owners']) === [99105], 'Pending personnel correction cannot remove original owner');
    $replaceOwner('r51-owner-failed',$originalOwner,0,'failed');
    verify(array_keys($ownersNow()['owners']) === [99105], 'Failed personnel correction cannot remove original owner');
    $replaceOwner('r51-owner-other-order',$originalOwner,0,'succeeded',['source_order_id'=>'R51-DIFFERENT-ORDER']);
    verify(array_keys($ownersNow()['owners']) === [99105], 'Same command key on another original order cannot remove owner');
    $replaceOwner('r51-owner-other-store',$originalOwner,0,'succeeded',['store_id'=>1907]);
    verify(array_keys($ownersNow()['owners']) === [99105], 'Other store correction cannot remove owner');
    $replaceOwner('r51-owner-other-tenant',$originalOwner,0,'succeeded',['tenant_id'=>'other']);
    verify(array_keys($ownersNow()['owners']) === [99105], 'Other tenant correction cannot remove owner');
    $replaceOwner('r51-owner-first',$originalOwner,99106);
    verify(array_keys($ownersNow()['owners']) === [99106], 'Successful original-sale correction replaces old salesperson');
    $replaceOwner('r51-owner-second',$ownerFact(99106),99107);
    verify(array_keys($ownersNow()['owners']) === [99107], 'Consecutive corrections retain only final recorded salesperson');
    $latestOwner = $ownerFact(99107);
    $replaceOwner('r51-owner-refund',$latestOwner,0,'succeeded',['operation_type'=>'refund']);
    verify(array_keys($ownersNow()['owners']) === [99107], 'Ordinary refund after correction retains final salesperson');
    $replaceOwner('r51-owner-empty',$latestOwner,0);
    $emptyOwners = $ownersNow();
    verify($emptyOwners['owners'] === [] && $emptyOwners['gaps'] === [], 'Explicit correction removing all owners yields zero people, not missing source');
    fixture('cashier_v3_customer_lifecycle_fact',['tenant_id'=>'0','fact_id'=>'r51-first-missing-source','natural_key'=>'r51-first-missing-source',
        'store_id'=>905,'member_id'=>88009,'event_type'=>'first_course_completed','status'=>'effective','related_order_id'=>'R51-MISSING-CARD-SOURCE',
        'business_date'=>'2026-10-17','occurred_at'=>strtotime('2026-10-17 Asia/Shanghai')]);
    $missingCohortSource = $newCustomerRead();
    verify($missingCohortSource['rows'][0]['xinkeshu'] === null && $missingCohortSource['rows'][0]['jiangjin'] === null
        && !in_array('jiangjin',$missingCohortSource['sum_fields'],true), 'Actual missing first-sale source remains explicit gap rather than fake zero bonus');
    for ($i=0;$i<23;$i++) {
        fixture('employee',['id'=>99200+$i,'phone'=>'138000'.(99200+$i),'name'=>'分页验证'.$i,'status'=>1,'is_del'=>0]);
        fixture('system_store_staff',['id'=>88200+$i,'employee_id'=>99200+$i,'store_id'=>901,'status'=>1,'is_del'=>0]);
    }
    verify(count($read([901],['_report_scope'=>['mode'=>'stores']])['rows']) === 25, 'Source returns more than 20 rows without pagination or silent truncation');
    fixture('system_store_staff',['id'=>88104,'employee_id'=>0,'store_id'=>901,'status'=>1,'is_del'=>0]);
    $blocked = false;
    try { $read([901],['_report_scope'=>['mode'=>'stores']]); } catch (RuntimeException $e) { $blocked = strpos($e->getMessage(),'稳定员工身份') !== false; }
    verify($blocked, 'Unmapped legacy staff blocks pseudo-empty salary success');
    // 真实 SQL 源保护：独立夹具库中批量构造，私有读取入口隔离测试哨兵与跨批预算，不跑一万次工资映射。
    $cohortReader = new ReflectionMethod(Salary::class,'firstCourseOwners');
    if (PHP_VERSION_ID < 80100) $cohortReader->setAccessible(true);
    $limitRange = ['start'=>'2026-10-01','end'=>'2026-10-23'];
    $bulkSequence = 0;
    $bulkEvents = function (int $store, int $member, string $day, int $count) use (&$bulkSequence): void {
        $rows = [];
        for ($i=0;$i<$count;++$i) {
            ++$bulkSequence;
            $rows[] = ['tenant_id'=>'0','fact_id'=>'r51-limit-'.$bulkSequence,'natural_key'=>'r51-limit-'.$bulkSequence,
                'store_id'=>$store,'member_id'=>$member,'event_type'=>'first_course_completed','status'=>'effective',
                'related_order_id'=>'r51-limit-missing-sale','business_date'=>$day,'occurred_at'=>strtotime($day.' Asia/Shanghai')+$i];
            if (count($rows) === 500) { Db::name('cashier_v3_customer_lifecycle_fact')->insertAll($rows); $rows = []; }
        }
        if ($rows) Db::name('cashier_v3_customer_lifecycle_fact')->insertAll($rows);
    };
    $rejectLimit = function (Salary $service, int $store, string $phase) use ($cohortReader,$limitRange): void {
        try { $cohortReader->invoke($service,$store,$limitRange); }
        catch (DomainException $e) { verify(strpos($e->getMessage(),$phase) !== false && strpos($e->getMessage(),'10000') !== false, 'Correct source overflow phase'); return; }
        throw new RuntimeException('Expected first-course source limit rejection');
    };
    $bulkEvents(1901,990001,'2026-10-05',10000);
    $boundedSalary = new Salary();
    $atLimit = $cohortReader->invoke($boundedSalary,1901,$limitRange);
    verify(count($atLimit['gaps']) === 1, 'Exactly 10000 candidates/history remain complete before mapping missing sale');
    verify($cohortReader->invoke($boundedSalary,1901,$limitRange) === $atLimit, 'Cached cohort does not consume source budget twice');
    $bulkEvents(1901,990001,'2026-10-05',1);
    $rejectLimit(new Salary(),1901,'候选');
    Db::name('cashier_v3_customer_lifecycle_fact')->where('store_id',1901)->delete();
    $bulkEvents(1902,990002,'2026-10-05',1);
    $bulkEvents(1902,990002,'2026-09-05',9999);
    verify($cohortReader->invoke(new Salary(),1902,$limitRange)['owners'] === [], 'Exactly 10000 history rows preserve prior-month first');
    $bulkEvents(1902,990002,'2026-09-05',1);
    $rejectLimit(new Salary(),1902,'全历史');
    Db::name('cashier_v3_customer_lifecycle_fact')->where('store_id',1902)->delete();
    for ($i=0;$i<301;++$i) {
        $bulkEvents(1903,990100+$i,'2026-10-05',1);
        $bulkEvents(1903,990100+$i,'2026-09-05',$i<300?19:4000);
    }
    $rejectLimit(new Salary(),1903,'全历史');
    Db::name('cashier_v3_customer_lifecycle_fact')->where('store_id',1903)->delete();
    $bulkEvents(1904,991000,'2026-10-05',6000);
    $bulkEvents(1905,991001,'2026-10-05',5000);
    $boundedSalary = new Salary();
    $cohortReader->invoke($boundedSalary,1904,$limitRange);
    $rejectLimit($boundedSalary,1905,'候选');
    echo json_encode(['pass'=>true,'checks'=>$checks,'reader'=>'RegisteredMetricReadServices','transaction'=>'MetricReadTransaction',
        'isolated_fixture_database'=>true,'remote_access'=>false,'persistent_business_writes'=>false]),PHP_EOL;
} finally { if ($created) $original->execute('DROP DATABASE `'.$database.'`'); }
