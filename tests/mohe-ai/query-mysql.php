<?php
// This test is launched only by run-query-mysql.sh against its disposable local MySQL.
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('zend.exception_ignore_args', '1');
$root = dirname(__DIR__, 2);
$vendor = $root . '/后端代码/vendor';
// Load class maps only: never Composer's application helpers, App, .env or project config.
require_once $vendor . '/composer/ClassLoader.php';
$loader = new Composer\Autoload\ClassLoader();
foreach (require $vendor . '/composer/autoload_psr4.php' as $prefix => $paths) $loader->addPsr4($prefix, $paths);
$loader->addClassMap(require $vendor . '/composer/autoload_classmap.php');
$loader->register();
$port = getenv('MOHE_QUERY_TEST_PORT'); $password = getenv('MOHE_QUERY_TEST_PASSWORD');
if (!is_string($port) || !preg_match('/^[0-9]{4,5}$/D', $port) || !is_string($password) || strlen($password) < 32 || getenv('MOHE_QUERY_TEST_DISPOSABLE') !== 'yes') throw new RuntimeException('Disposable fixture environment required');
$config = ['type' => 'mysql', 'hostname' => '127.0.0.1', 'hostport' => (int)$port, 'database' => 'mohe_query_fixture', 'username' => 'root', 'password' => $password, 'charset' => 'utf8mb4', 'prefix' => 'eb_', 'debug' => false, 'fields_strict' => true, 'break_reconnect' => false];
$db = new think\DbManager();
$db->setConfig(['default' => 'fixture', 'connections' => ['fixture' => $config]]);
think\Container::getInstance()->instance('think\DbManager', $db);
$pdo = new PDO('mysql:host=127.0.0.1;port=' . $port . ';dbname=mohe_query_fixture;charset=utf8mb4', 'root', $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$schemas = [
    'system_store' => 'id INT PRIMARY KEY,name VARCHAR(128)',
    'user' => 'uid INT PRIMARY KEY,real_name VARCHAR(128) DEFAULT "",nickname VARCHAR(128) DEFAULT "",phone VARCHAR(32) DEFAULT ""',
    'organization' => 'id INT PRIMARY KEY,pid INT,is_del TINYINT DEFAULT 0',
    'organization_store' => 'store_id INT,org_id INT',
    'cashier_v3_report_organization_dimension' => 'id INT PRIMARY KEY AUTO_INCREMENT,tenant_id VARCHAR(64),dimension_code VARCHAR(64),organization_id VARCHAR(64),organization_name_snapshot VARCHAR(128),enabled TINYINT DEFAULT 1,valid_from DATE NULL,display_order INT DEFAULT 0',
    'cashier_v3_payment_sale_allocation_fact' => 'id INT PRIMARY KEY AUTO_INCREMENT,tenant_id VARCHAR(64),allocation_fact_id VARCHAR(64),sale_fact_id VARCHAR(64),reversal_of VARCHAR(64) NULL,payment_fact_id VARCHAR(64) DEFAULT "",store_id INT,member_id INT DEFAULT 0,business_date DATE,status VARCHAR(32),amount_cents BIGINT,order_id VARCHAR(64),order_no_snapshot VARCHAR(64) DEFAULT "",source_line_id VARCHAR(64) DEFAULT "",organization_id VARCHAR(64) DEFAULT "",occurred_at INT DEFAULT 0,settled_at INT DEFAULT 0,recorded_at INT DEFAULT 0',
    'cashier_v3_sale_fact' => 'id INT PRIMARY KEY AUTO_INCREMENT,tenant_id VARCHAR(64),fact_id VARCHAR(64),store_id INT,member_id INT DEFAULT 0,member_name_snapshot VARCHAR(128) DEFAULT "",business_date DATE,status VARCHAR(32),sale_amount_cents BIGINT,quantity BIGINT DEFAULT 0,item_id INT DEFAULT 0,item_name_snapshot VARCHAR(128) DEFAULT "",order_id VARCHAR(64),source_line_id VARCHAR(64),source_type VARCHAR(32) DEFAULT "product",organization_id VARCHAR(64) DEFAULT "",organization_path_snapshot VARCHAR(128) DEFAULT "",store_name_snapshot VARCHAR(128) DEFAULT "",business_source_primary_id INT DEFAULT 0,business_source_label_snapshot VARCHAR(128) DEFAULT "",operator_id INT DEFAULT 0,operator_name_snapshot VARCHAR(128) DEFAULT ""',
    'cashier_v3_performance_fact' => 'id INT PRIMARY KEY AUTO_INCREMENT,fact_id VARCHAR(64) NULL UNIQUE,fact_direction VARCHAR(32) DEFAULT "forward",tenant_id VARCHAR(64),store_id INT,member_id INT DEFAULT 0,member_name_snapshot VARCHAR(128) DEFAULT "",business_date DATE,status VARCHAR(32),performance_type VARCHAR(64),amount_cents BIGINT,labor_fee_amount_cents BIGINT DEFAULT 0,project_count_half_units INT DEFAULT 0,project_count_decimal DECIMAL(20,6) NULL DEFAULT NULL,rule_name_snapshot VARCHAR(128) DEFAULT "",order_id VARCHAR(64),order_no_snapshot VARCHAR(128) DEFAULT "",checkout_request_id VARCHAR(64),source_line_id VARCHAR(64),organization_id VARCHAR(64) DEFAULT "",organization_path_snapshot VARCHAR(128) DEFAULT "",store_name_snapshot VARCHAR(128) DEFAULT "",employee_id INT DEFAULT 0,employee_name_snapshot VARCHAR(128) DEFAULT "",operator_id INT DEFAULT 0,operator_name_snapshot VARCHAR(128) DEFAULT ""',
    'cashier_v3_entitlement_service_fact' => 'id INT PRIMARY KEY AUTO_INCREMENT,tenant_id VARCHAR(64),store_id INT,business_date DATE,checkout_request_id VARCHAR(64),source_line_id VARCHAR(64),service_status VARCHAR(32),quantity INT DEFAULT 1,member_id INT DEFAULT 0,order_id VARCHAR(64) DEFAULT "",operator_id INT DEFAULT 0,operator_name_snapshot VARCHAR(128) DEFAULT "",settled_at INT DEFAULT 0,project_id INT DEFAULT 0,project_name_snapshot VARCHAR(128) DEFAULT "",project_category_id_snapshot INT DEFAULT 0,project_category_name_snapshot VARCHAR(128) DEFAULT "",project_category_path_snapshot VARCHAR(512) DEFAULT "",is_experience TINYINT DEFAULT 0',
    'cashier_v3_report_sale_dimension_fact' => 'id INT PRIMARY KEY AUTO_INCREMENT,tenant_id VARCHAR(64),store_id INT,order_id VARCHAR(64),source_line_id VARCHAR(64),sale_fact_id VARCHAR(64),partner_name_snapshot VARCHAR(128),item_id INT DEFAULT 0,item_name_snapshot VARCHAR(128) DEFAULT "",category_id_snapshot INT DEFAULT 0,category_path_snapshot VARCHAR(512) DEFAULT "",product_type_snapshot VARCHAR(32) DEFAULT "project",is_experience TINYINT DEFAULT 0',
    'cashier_v3_card_sale_category_allocation_fact' => 'id INT PRIMARY KEY AUTO_INCREMENT,tenant_id VARCHAR(64),store_id INT,order_id VARCHAR(64),source_line_id VARCHAR(64),sale_fact_id VARCHAR(64),status VARCHAR(32),partner_name_snapshot VARCHAR(128),category_id_snapshot INT DEFAULT 0,category_path_snapshot VARCHAR(512) DEFAULT "",product_type_snapshot VARCHAR(32) DEFAULT "project",component_product_id INT DEFAULT 0,category_name_snapshot VARCHAR(128) DEFAULT "",component_count INT DEFAULT 1,sale_amount_cents BIGINT DEFAULT 0,configured_amount_cents BIGINT DEFAULT 0',
    'cashier_v3_customer_guide_round_fact' => 'id INT PRIMARY KEY AUTO_INCREMENT,tenant_id VARCHAR(64),store_id INT,order_id VARCHAR(64),checkout_request_id VARCHAR(64) DEFAULT "",source_line_id VARCHAR(64) DEFAULT "",guide_employee_id INT,guide_employee_name_snapshot VARCHAR(128) DEFAULT "",status VARCHAR(32)',
    'cashier_v3_sales_manager_fact' => 'id INT PRIMARY KEY AUTO_INCREMENT,tenant_id VARCHAR(64),store_id INT,order_id VARCHAR(64),checkout_request_id VARCHAR(64) DEFAULT "",source_line_id VARCHAR(64) DEFAULT "",sales_manager_employee_id INT,sales_manager_name_snapshot VARCHAR(128) DEFAULT "",status VARCHAR(32)',
    'cashier_v3_balance_fact' => 'id INT PRIMARY KEY AUTO_INCREMENT,tenant_id VARCHAR(64),store_id INT,business_date DATE,status VARCHAR(32),balance_change_type VARCHAR(64),principal_delta_cents BIGINT,bonus_delta_cents BIGINT,order_id VARCHAR(64),operator_id INT DEFAULT 0,operator_name_snapshot VARCHAR(128) DEFAULT "",settled_at INT DEFAULT 0',
    'cashier_v3_order_lifecycle_operation' => 'tenant_id VARCHAR(64),source_order_id VARCHAR(64),source_type VARCHAR(32),operation_type VARCHAR(32),status VARCHAR(32)',
    'cashier_v3_payment_fact' => "id INT PRIMARY KEY AUTO_INCREMENT,fact_id VARCHAR(64),tenant_id VARCHAR(64),store_id INT,member_id INT,member_name_snapshot VARCHAR(128) DEFAULT '',business_date DATE,status VARCHAR(32),fact_type VARCHAR(64),source_document_type VARCHAR(64),payment_method VARCHAR(32),amount_cents BIGINT,order_id VARCHAR(64),order_no_snapshot VARCHAR(64) DEFAULT '',source_line_id VARCHAR(64),organization_id VARCHAR(64),organization_path_snapshot VARCHAR(128),store_name_snapshot VARCHAR(128),business_source_primary_id INT DEFAULT 0,business_source_label_snapshot VARCHAR(128) DEFAULT '',operator_id INT DEFAULT 0,operator_name_snapshot VARCHAR(128) DEFAULT '',occurred_at INT DEFAULT 0,settled_at INT DEFAULT 0,recorded_at INT DEFAULT 0",
    'cashier_v3_recharge_debt_repayment' => 'tenant_id VARCHAR(64),repayment_id VARCHAR(64),recharge_id INT,store_id INT,member_id INT,status VARCHAR(32)',
    'cashier_v3_order_center_void_operation' => 'tenant_id VARCHAR(64),source_id VARCHAR(64),source_kind VARCHAR(64),status VARCHAR(32)',
];
foreach ($schemas as $name => $schema) $pdo->exec('CREATE TABLE eb_' . $name . ' (' . $schema . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$pdo->exec("INSERT INTO eb_system_store VALUES (1,'测试甲店'),(2,'测试乙店'),(3,'无权店')");
$pdo->exec("INSERT INTO eb_user VALUES (101,'测试会员','',''),(102,'历史欠款会员','','')");
$pdo->exec("INSERT INTO eb_organization (id,pid,is_del) VALUES (1,0,0)");
$pdo->exec("INSERT INTO eb_organization_store (store_id,org_id) VALUES (1,1),(2,1),(3,1)");
$pdo->exec("INSERT INTO eb_cashier_v3_sale_fact (tenant_id,fact_id,store_id,member_id,member_name_snapshot,business_date,status,sale_amount_cents,quantity,item_id,item_name_snapshot,order_id,source_line_id,operator_id,operator_name_snapshot) VALUES
('0','sale',1,101,'测试会员','2026-09-08','effective',12000,3,91,'测试产品','order','line',7,'测试操作人'),
('other','sale-other',1,0,'','2026-09-08','effective',99999,99,92,'外部产品','foreign','foreign-line',8,'外部操作人')");
$pdo->exec("INSERT INTO eb_cashier_v3_payment_fact (fact_id,tenant_id,store_id,member_id,member_name_snapshot,business_date,status,fact_type,source_document_type,payment_method,amount_cents,order_id,source_line_id,organization_id,organization_path_snapshot,store_name_snapshot,operator_id,operator_name_snapshot) VALUES
('payment-cash','0',1,101,'测试会员','2026-09-08','effective','payment_collected','cashier_snapshot','wechat',900001,'order','line','org','org','测试甲店',7,'测试操作人'),
('payment-refund','0',1,101,'测试会员','2026-09-08','effective','payment_collected','cashier_snapshot','wechat',-10000,'order','line','org','org','测试甲店',7,'测试操作人'),
('historical-debt-cash','0',1,102,'历史欠款会员','2026-09-08','effective','payment_collected','debt_repayment','wechat',3000,'historical-debt','historical-debt:payment:1','org','org','测试甲店',8,'历史欠款收银员'),
('historical-debt-refund','0',1,102,'历史欠款会员','2026-09-08','effective','payment_collected','debt_repayment','wechat',-200,'historical-debt','historical-debt:payment:2','org','org','测试甲店',8,'历史欠款收银员')");
$pdo->exec("INSERT INTO eb_cashier_v3_payment_sale_allocation_fact (tenant_id,allocation_fact_id,sale_fact_id,reversal_of,payment_fact_id,store_id,business_date,status,amount_cents,order_id) VALUES
('0','cash','sale',NULL,'payment-cash',1,'2026-09-08','effective',900001,'order'),
('0','refund','sale','cash','payment-refund',1,'2026-09-08','effective',-10000,'order'),
('0','yesterday','sale',NULL,'',1,'2026-09-07','effective',5000,'order'),
('0','otherstore','sale',NULL,'',2,'2026-09-08','effective',30000,'order'),
('0','pending','sale',NULL,'',1,'2026-09-08','pending',777,'order'),
('0','void','sale',NULL,'',1,'2026-09-08','effective',100000,'voided'),
('other','foreign','sale',NULL,'',1,'2026-09-08','effective',999999,'order')");
$pdo->exec("INSERT INTO eb_cashier_v3_order_lifecycle_operation VALUES ('0','voided','sales','void','succeeded')");
$pdo->exec("INSERT INTO eb_cashier_v3_entitlement_service_fact (tenant_id,store_id,business_date,checkout_request_id,source_line_id,service_status,quantity,member_id,order_id,operator_id,operator_name_snapshot,project_id,project_name_snapshot) VALUES
('0',1,'2026-09-08','checkout','line','completed',2,101,'order',7,'测试操作人',31,'护理项目'),
('0',1,'2026-09-08','checkout','line-2','completed',1,101,'order',7,'测试操作人',31,'护理项目'),
('0',1,'2026-09-08','pending-checkout','line','pending',9,102,'order',7,'测试操作人',32,'未完成项目')");
$pdo->exec("INSERT INTO eb_cashier_v3_performance_fact (tenant_id,store_id,business_date,status,performance_type,amount_cents,order_id,checkout_request_id,source_line_id,employee_id,employee_name_snapshot) VALUES
('0',1,'2026-09-08','effective','consumption_performance_recorded',10000,'order','checkout','line',0,''),
('0',1,'2026-09-08','effective','consumption_performance_recorded',-500,'order','checkout','line',0,''),
('0',1,'2026-09-08','effective','consumption_performance_recorded',900,'order','pending-checkout','line',0,''),
('0',1,'2026-09-08','effective','consumption_performance_recorded',100000,'voided','checkout','line',0,''),
('0',2,'2026-09-08','effective','consumption_performance_recorded',2000,'order','checkout','line',0,''),
('0',1,'2026-09-08','effective','actual_performance_recorded',12000,'order','checkout','line',0,''),
('0',1,'2026-09-08','effective','sales_performance_allocated',7000,'order','checkout','line',11,'销售甲'),
('0',1,'2026-09-08','effective','sales_performance_allocated',3000,'order','checkout','line',12,'销售乙'),
('0',1,'2026-09-08','effective','labor_performance_allocated',4500,'order','checkout','line',21,'技师甲')");
$pdo->exec("UPDATE eb_cashier_v3_entitlement_service_fact SET project_category_id_snapshot=71,project_category_name_snapshot='护理',project_category_path_snapshot='护理 / 项目',is_experience=1 WHERE source_line_id='line' AND service_status='completed'");
$pdo->exec("INSERT INTO eb_cashier_v3_report_sale_dimension_fact (tenant_id,store_id,order_id,source_line_id,sale_fact_id,partner_name_snapshot,category_id_snapshot,category_path_snapshot,product_type_snapshot,is_experience) VALUES ('0',1,'order','line','sale','合作方甲',71,'护理 / 项目','project',1)");
$pdo->exec("INSERT INTO eb_cashier_v3_customer_guide_round_fact (id,tenant_id,store_id,order_id,checkout_request_id,source_line_id,guide_employee_id,guide_employee_name_snapshot,status) VALUES (1,'0',1,'order','checkout','line',41,'导购甲','effective')");
$pdo->exec("INSERT INTO eb_cashier_v3_sales_manager_fact (id,tenant_id,store_id,order_id,checkout_request_id,source_line_id,sales_manager_employee_id,sales_manager_name_snapshot,status) VALUES (1,'0',1,'order','checkout','line',51,'销售经理甲','effective')");
$pdo->exec("INSERT INTO eb_cashier_v3_balance_fact (tenant_id,store_id,business_date,status,balance_change_type,principal_delta_cents,bonus_delta_cents,order_id) VALUES
('0',1,'2026-09-08','effective','order_payment',-2000,-500,'order'),
('0',1,'2026-09-08','effective','recharge_credit',8000,1000,'recharge'),
('0',1,'2026-09-08','effective','balance_restored',500,100,'refund')");
$checks = 0;
function mysqlCheck($ok, $label) { global $checks; if (!$ok) throw new RuntimeException($label); ++$checks; }
function mysqlReject(callable $call, $code) { try { $call(); } catch (app\services\query\metric\MetricQueryContractException $e) { mysqlCheck($e->getErrorCode() === $code, 'error ' . $e->getErrorCode()); return; } throw new RuntimeException('expected rejection'); }
$reader = new app\services\query\metric\GroupPerformanceMetricReadServices();
$range = ['start' => '2026-09-08', 'end' => '2026-09-08'];
mysqlCheck($reader->cashTotals('0', [1], $range) === ['gross_cents' => 903001, 'refund_cents' => -10200], 'cash signs, scope, pending and void exclusion');
mysqlCheck($reader->metricTotal('0', [1], $range, 'consume_amount') === 9500, 'signed consumed complete service only');
mysqlCheck($reader->cashTotals('0', [1, 2], $range)['gross_cents'] === 933001, 'complete multistore sum');
$registered = new app\services\query\metric\RegisteredMetricReadServices();
$expectedRegistered = [
    'cash_performance' => 903001,
    'refund_performance' => 10200,
    'actual_performance' => 892801,
    'consume_amount' => 9500,
    'staff_sales_yeji' => 10000,
    'staff_labor_yeji' => 4500,
    'sales_amount' => 12000,
    'sales_collected_amount' => 890001,
    'sales_quantity' => 3,
    'balance_deduction_amount' => 2500,
    'recharge_amount' => 8000,
    'completed_service_item_count' => 3,
    'customer_active' => 1,
];
foreach ($expectedRegistered as $metricCode => $expectedValue) {
    mysqlCheck($registered->summary($metricCode, '0', [1], $range) === $expectedValue, 'registered exact value ' . $metricCode);
}
$memberThreshold=['subject'=>'member','aggregation'=>'period_total','operator'=>'gte','amount_cents'=>498000];
mysqlCheck($registered->thresholdCount('sales_collected_amount', '0', [1], $range, $memberThreshold)===1,
    'registered member cumulative actual-sales collection threshold excludes recharge, debt collection and void allocation facts');
$memberPopulation=$registered->thresholdMembers('sales_collected_amount','0',[1],$range,$memberThreshold);
mysqlCheck($memberPopulation['count']===1 && $memberPopulation['has_more']===false
    && $memberPopulation['rows']===[['member_id'=>101,'member_name'=>'测试会员','metric_value'=>890001]],
    'member threshold list and exact count share the registered sales-allocation population and frozen member label');
$memberConditions=['subject'=>'member','relation'=>'all','conditions'=>[
    ['metric_code'=>'member_service_visit_count','operator'=>'gte','value'=>1],
    ['metric_code'=>'sales_collected_amount','operator'=>'gte','value'=>890001],
]];
$memberConditionPopulation=$registered->conditionMembers('0',[1],$range,$memberConditions);
mysqlCheck($memberConditionPopulation['count']===1 && $memberConditionPopulation['has_more']===false
    &&$memberConditionPopulation['rows']===[['member_id'=>101,'member_name'=>'测试会员','metrics'=>[
        'member_service_visit_count'=>1,'sales_collected_amount'=>890001,
    ]]],'member multi-condition list intersects separately aggregated registered service and sales facts');
$memberConditionCountOnly=$registered->conditionMembers('0',[1],$range,$memberConditions,0);
mysqlCheck($memberConditionCountOnly===['count'=>1,'rows'=>[],'limit'=>0,'has_more'=>true],
    'member condition count does not execute or disclose a hidden list page');
$memberConditions['conditions'][0]['value']=2;
mysqlCheck($registered->conditionMembers('0',[1],$range,$memberConditions)['count']===0,
    'member visit count de-duplicates multiple completed service rows on the same member, store and business date');
$memberThreshold['operator']='gt';$memberThreshold['amount_cents']=890001;
mysqlCheck($registered->thresholdCount('sales_collected_amount', '0', [1], $range, $memberThreshold)===0,
    'registered member threshold applies the typed comparison operator to the signed sales-allocation total');
$productQuantityRanking = $registered->dimensionRanking('sales_quantity', 'product', '0', [1], $range);
mysqlCheck($productQuantityRanking === [['entity_id'=>91, 'entity_name'=>'测试产品', 'metric_value'=>3]],
    'a partial cash refund leaves the registered completed-sale quantity unchanged');
$guideRanking=$registered->dimensionRanking('sales_amount','guide','0',[1],$range,20,'desc');
mysqlCheck($guideRanking===[['entity_id'=>41,'entity_name'=>'导购甲','metric_value'=>12000]],'guide relationship ranks associated order sales without a guide performance metric');
$managerRanking=$registered->dimensionRanking('sales_amount','sales_manager','0',[1],$range,20,'desc');
mysqlCheck($managerRanking===[['entity_id'=>51,'entity_name'=>'销售经理甲','metric_value'=>12000]],'sales-manager relationship ranks associated order sales without a manager performance metric');
$groupedStores = $registered->groupedStoreTotals('cash_performance', '0', [1, 2], $range, [1 => 'north', 2 => 'north']);
mysqlCheck($groupedStores === [['group_key' => 'north', 'metric_value' => 933001, 'store_count' => 2]],
    'registered reader owns store-group metric aggregation');
mysqlCheck(
    $registered->summary('actual_performance', '0', [1], $range)
        === $registered->summary('cash_performance', '0', [1], $range)
        - $registered->summary('refund_performance', '0', [1], $range),
    'actual performance exact registered identity'
);
$cashDetails=$registered->detailPage('cash_performance','0',[1],$range,1,100);
mysqlCheck($cashDetails['total']===2 && array_sum(array_column($cashDetails['rows'],'metric_value'))
    === $registered->summary('cash_performance','0',[1],$range)
    && in_array('historical-debt-cash', array_column($cashDetails['rows'], 'fact_id'), true),
    'historical sales-debt payment is visible once in the registered cash detail');
$unclassifiedDebtRows = array_values(array_filter(
    $registered->categoryRows('cash_performance', '0', [1], $range),
    static fn(array $row): bool => ($row['product_type_snapshot'] ?? '') === 'sales_debt_repayment_unallocated'
));
mysqlCheck(count($unclassifiedDebtRows) === 1
    && (int)$unclassifiedDebtRows[0]['category_id'] === 0
    && (int)$unclassifiedDebtRows[0]['amount_cents'] === 3000,
    'unallocated historical sales-debt payment is visible without inventing an item category');
$refundDetails=$registered->detailPage('refund_performance','0',[1],$range,1,100);
mysqlCheck($refundDetails['total']===2 && array_sum(array_column($refundDetails['rows'],'metric_value'))===10200
    && in_array('historical-debt-refund', array_column($refundDetails['rows'], 'fact_id'), true),
    'debt-repayment reversal stays in the same registered refund population');
$actualDetails=$registered->detailPage('actual_performance','0',[1],$range,1,100);
mysqlCheck(array_sum(array_column($actualDetails['rows'],'metric_value'))===892801,
    'actual detail preserves signed cash-minus-refund identity');
$cashOperatorRanking=$registered->defaultRanking('cash_performance','0',[1],$range,20,'desc');
mysqlCheck($cashOperatorRanking['dimension']==='operator' && $cashOperatorRanking['rows']===[
    ['entity_id'=>7,'entity_name'=>'测试操作人','metric_value'=>900001],
    ['entity_id'=>8,'entity_name'=>'历史欠款收银员','metric_value'=>3000],
], 'cash operator ranking includes unallocated historical debt exactly once');
$cashMemberRanking=$registered->dimensionRanking('cash_performance','member','0',[1],$range,20,'desc');
mysqlCheck($cashMemberRanking === [
    ['entity_id'=>101,'entity_name'=>'测试会员','metric_value'=>900001],
    ['entity_id'=>102,'entity_name'=>'历史欠款会员','metric_value'=>3000],
], 'cash member ranking includes the same historical-debt payment population as summary');
mysqlCheck(
    $registered->dailyStoreTotals('cash_performance', '0', [1], $range) === [['store_id'=>1, 'business_date'=>'2026-09-08', 'amount_cents'=>903001]],
    'historical sales-debt payment is merged once into the registered daily total'
);
$beforeAllocatedDebtProbe = $registered->summary('cash_performance', '0', [1], $range);
$pdo->exec("INSERT INTO eb_cashier_v3_payment_fact (fact_id,tenant_id,store_id,member_id,member_name_snapshot,business_date,status,fact_type,source_document_type,payment_method,amount_cents,order_id,source_line_id,organization_id,organization_path_snapshot,store_name_snapshot,operator_id,operator_name_snapshot) VALUES ('allocated-debt-payment','0',1,101,'测试会员','2026-09-08','effective','payment_collected','debt_repayment','wechat',4000,'order','allocated-debt:payment:1','org','org','测试甲店',7,'测试操作人')");
$pdo->exec("INSERT INTO eb_cashier_v3_payment_sale_allocation_fact (tenant_id,allocation_fact_id,sale_fact_id,reversal_of,payment_fact_id,store_id,business_date,status,amount_cents,order_id) VALUES ('0','allocated-debt-allocation','sale',NULL,'allocated-debt-payment',1,'2026-09-08','effective',4000,'order')");
mysqlCheck(
    $registered->summary('cash_performance', '0', [1], $range) === $beforeAllocatedDebtProbe + 4000,
    'a debt repayment with an allocation stays in the allocation source and is never double counted'
);
$pdo->exec("DELETE FROM eb_cashier_v3_payment_sale_allocation_fact WHERE allocation_fact_id='allocated-debt-allocation'");
$pdo->exec("DELETE FROM eb_cashier_v3_payment_fact WHERE fact_id='allocated-debt-payment'");
$personRanking = $registered->personnelRanking('staff_labor_yeji', '0', [1], $range);
mysqlCheck(count($personRanking) === 1 && $personRanking[0]['employee_name'] === '技师甲'
    && $personRanking[0]['amount_cents'] === 4500, 'registered technician ranking');
$technicianDetails = $registered->personnelDetailPage('staff_labor_yeji', '0', [1], $range, 21, 1, 20);
mysqlCheck($technicianDetails['total'] === 1 && (int)$technicianDetails['rows'][0]['metric_value'] === 4500,
    'registered technician details do not expose a mutable source query');
$technicianDaily = $registered->personnelDailyTotals('staff_labor_yeji', '0', [1], $range, [21]);
mysqlCheck(count($technicianDaily) === 1 && (int)$technicianDaily[0]['amount_cents'] === 4500,
    'registered technician daily projection owns report aggregation');
$technicianMatrix = $registered->personnelDayMatrix('staff_labor_yeji', '0', [1], $range, [21]);
mysqlCheck(count($technicianMatrix['records']) === 1
    && (int)$technicianMatrix['records'][0]['total_metric_value'] === 4500
    && (int)$technicianMatrix['summary']['total_metric_value'] === 4500,
    'registered technician matrix owns report row and summary totals');
$technicianResult = $registered->personnelDetailResult('staff_labor_yeji', '0', [1], $range, [21]);
$technicianRows = $technicianResult['rows'];
mysqlCheck($technicianResult['total_metric_value'] === 4500, 'registered personnel detail owns complete total');
mysqlCheck(count($technicianRows) === 1 && (string)$technicianRows[0]['order_id'] === 'order',
    'registered technician detail projection retains only fixed display identifiers');
$projectRanking = $registered->dimensionRanking('completed_service_item_count', 'project', '0', [1], $range);
mysqlCheck(count($projectRanking) === 1 && $projectRanking[0]['entity_name'] === '护理项目'
    && $projectRanking[0]['metric_value'] === 3, 'registered completed-service project ranking');
$completeFilters = ['category_path'=>'护理','product_type'=>'project','partner_name'=>'合作方甲','salesperson_id'=>11,'guide_id'=>41,'sales_manager_id'=>51,'is_experience'=>1];
$lineCategoryTotals = $registered->sourceLineCategoryTotals('consume_amount', '0', [1], $range, ['line', 'line-2'], $completeFilters);
mysqlCheck($lineCategoryTotals === ['line|71' => 9500], 'registered source-line/category aggregation prevents cross-category duplication');
$saleLineCategoryTotals = $registered->sourceLineCategoryTotals('sales_amount', '0', [1], $range, ['line'], $completeFilters);
mysqlCheck($saleLineCategoryTotals === ['line|71' => 12000], 'registered sales category aggregation owns direct-sale amount and filters');
$filteredRows = $registered->categoryRows('consume_amount', '0', [1], $range, [71], $completeFilters);
mysqlCheck(array_sum(array_column($filteredRows, 'amount_cents')) === 9500, 'all item-analysis filters reach registered consumption query');
$consumeBuckets = $registered->categoryReportBuckets('consume_amount', '0', [1], $range, [71], $completeFilters);
mysqlCheck(count($consumeBuckets) === 1 && (int)$consumeBuckets[0]['metric_value'] === 9500,
    'registered category report bucket owns consumption aggregation');
$participantBuckets = $registered->categoryReportBucketsForParticipant('consume_amount', '0', [1], $range, [71], $completeFilters, 11);
mysqlCheck(count($participantBuckets) === 1 && (int)$participantBuckets[0]['metric_value'] === 9500,
    'registered category reader applies participating-person population before aggregation');
$unmatchedParticipantBuckets = $registered->categoryReportBucketsForParticipant('consume_amount', '0', [1], $range, [71], $completeFilters, 99);
mysqlCheck($unmatchedParticipantBuckets === [],
    'unmatched participant fails closed inside the registered reader');
mysqlReject(function () use ($registered, $range) {
    $registered->categoryRows('consume_amount', '0', [1], $range, [71], ['participant_employee_id' => 11]);
}, 'METRIC_SOURCE_SCOPE_INVALID');
foreach ([['partner_name'=>'其他合作方'],['salesperson_id'=>99],['guide_id'=>99],['sales_manager_id'=>99],['category_path'=>'其他'],['product_type'=>'product'],['is_experience'=>0]] as $wrongFilter) {
    mysqlCheck($registered->categoryRows('consume_amount', '0', [1], $range, [], $wrongFilter) === [], 'unmatched registered consumption filter fails closed');
}
$pdo->exec("INSERT INTO eb_cashier_v3_sale_fact (tenant_id,fact_id,store_id,business_date,status,sale_amount_cents,order_id,source_line_id,source_type,store_name_snapshot) VALUES ('0','sale-old',1,'2026-09-01','effective',1234,'order-old','line-old','product','测试甲店')");
$pdo->exec("INSERT INTO eb_cashier_v3_payment_sale_allocation_fact (tenant_id,allocation_fact_id,sale_fact_id,reversal_of,store_id,member_id,business_date,status,amount_cents,order_id,source_line_id) VALUES ('0','cash-old-sale','sale-old',NULL,1,101,'2026-09-08','effective',1234,'order-old','line-old')");
$pdo->exec("INSERT INTO eb_cashier_v3_report_sale_dimension_fact (tenant_id,store_id,order_id,source_line_id,sale_fact_id,partner_name_snapshot,item_id,item_name_snapshot,category_id_snapshot,category_path_snapshot,product_type_snapshot,is_experience) VALUES ('0',1,'order-old','line-old','sale-old','',72,'跨日项目',72,'跨日 / 项目','project',0)");
$cashCategoryRows = $registered->categoryRows('cash_performance', '0', [1], $range, [72]);
mysqlCheck(array_sum(array_column($cashCategoryRows, 'amount_cents')) === 1234, 'cash category follows payment business date even when sale occurred earlier');
mysqlCheck($registered->categorySummary('cash_performance', '0', [1], $range, [72]) === 1234,
    'registered category total owns filtered cash aggregation');
$filteredCards = $registered->categoryDashboardCards('cash_performance', '0', [1], $range,
    [['id'=>71, 'name'=>'护理'], ['id'=>72, 'name'=>'跨日']], [], [72]);
mysqlCheck(array_column($filteredCards, 'cash_performance_cents') === [0, 1234],
    'category cards preserve selected category and exclude other categories and recharge');
$emptyCards = $registered->categoryDashboardCards('cash_performance', '0', [1], $range,
    [['id'=>71, 'name'=>'护理'], ['id'=>72, 'name'=>'跨日']], [], [999999]);
mysqlCheck(array_column($emptyCards, 'cash_performance_cents') === [0, 0],
    'unmatched category cannot fall back to full-scope cards');
mysqlCheck($registered->categoryDailyStoreTotals('cash_performance', '0', [1], $range, [72]) === [['store_id' => 1, 'business_date' => '2026-09-08', 'amount_cents' => 1234]],
    'registered category daily/store projection owns filtered trend aggregation');
mysqlCheck($registered->categoryDailyTotals('cash_performance', '0', [1], $range, [72]) === [['business_date' => '2026-09-08', 'metric_value' => 1234]],
    'registered category daily projection owns cross-store trend aggregation');
mysqlCheck($registered->categoryStoreTotals('cash_performance', '0', [1], $range, [72]) === [['store_id' => 1, 'amount_cents' => 1234]],
    'registered category store projection owns filtered ranking aggregation');
$pdo->exec("DELETE FROM eb_cashier_v3_report_sale_dimension_fact WHERE sale_fact_id='sale-old'");
$pdo->exec("DELETE FROM eb_cashier_v3_payment_sale_allocation_fact WHERE allocation_fact_id='cash-old-sale'");
$pdo->exec("DELETE FROM eb_cashier_v3_sale_fact WHERE fact_id='sale-old'");

// Real category-allocation schema has category_name_snapshot, not item_name_snapshot.
$pdo->exec("INSERT INTO eb_cashier_v3_sale_fact (tenant_id,fact_id,store_id,business_date,status,sale_amount_cents,order_id,source_line_id,source_type) VALUES ('0','card-probe',1,'2026-09-08','effective',210000,'card-probe','card-line','card')");
$pdo->exec("INSERT INTO eb_cashier_v3_payment_sale_allocation_fact (tenant_id,allocation_fact_id,sale_fact_id,store_id,business_date,status,amount_cents,order_id) VALUES ('0','card-probe','card-probe',1,'2026-09-08','effective',210000,'card-probe')");
mysqlCheck($registered->categoryRows('cash_performance', '0', [1], $range, [], ['partner_name' => 'no-match']) === [], 'unclassified card cannot bypass partner filter');
mysqlCheck($registered->categoryRows('cash_performance', '0', [1], $range, [], ['category_path' => 'no-match']) === [], 'unclassified card cannot bypass category filter');
$pdo->exec("INSERT INTO eb_cashier_v3_card_sale_category_allocation_fact (tenant_id,store_id,order_id,source_line_id,sale_fact_id,status,partner_name_snapshot,category_id_snapshot,category_path_snapshot,category_name_snapshot,sale_amount_cents) VALUES ('0',1,'card-probe','card-line','card-probe','effective','卡合作方',88,'卡分类','卡分类',210000)");
$cardProbe = $registered->categoryRows('cash_performance', '0', [1], $range, [88]);
mysqlCheck(count($cardProbe) === 1 && (int)$cardProbe[0]['amount_cents'] === 210000 && $cardProbe[0]['item_name'] === '卡分类', 'card category projection uses real schema and cents');
$salesCardProbe = $registered->sourceLineCategoryTotals('sales_amount', '0', [1], $range, ['card-line'], ['partner_name' => '卡合作方']);
mysqlCheck($salesCardProbe === ['card-line|88' => 210000], 'registered sales category aggregation uses frozen card allocation');
mysqlCheck($registered->categoryRows('cash_performance', '0', [1], $range, [], ['partner_name' => 'no-match']) === [], 'classified card honors partner filter');
$pdo->exec("DELETE FROM eb_cashier_v3_card_sale_category_allocation_fact WHERE sale_fact_id='card-probe'");
$pdo->exec("DELETE FROM eb_cashier_v3_payment_sale_allocation_fact WHERE allocation_fact_id='card-probe'");
$pdo->exec("DELETE FROM eb_cashier_v3_sale_fact WHERE fact_id='card-probe'");
// Exercise the existing report's actual delegated methods, without constructor/application boot.
$reflection = new ReflectionClass(app\services\report\GroupManagementDashboardServices::class);
$report = $reflection->newInstanceWithoutConstructor();
foreach (['performanceTotal' => ['0', [1], $range, []]] as $method => $args) {
    $m = $reflection->getMethod($method); $m->setAccessible(true);
    $actual = $m->invokeArgs($report, $args);
    mysqlCheck($actual === 9500, 'real report delegated value ' . $method);
}
$transaction = new app\services\query\metric\MetricReadTransaction();
$db->connect()->execute('SET SESSION max_execution_time = 1234');
$bounded = new app\services\query\metric\MetricReadTransaction(40);
$timeoutCode = 0; $startedAt = microtime(true);
try {
    $bounded->run(function ($r) use ($db, $range) {
        $r->metricTotal('0', [1], $range, 'consume_amount');
        $db->connect()->query('SELECT COUNT(*) FROM eb_cashier_v3_performance_fact a CROSS JOIN eb_cashier_v3_performance_fact b WHERE SLEEP(0.02)=0', [], true);
    });
} catch (Throwable $e) {
    // ThinkPHP preserves PDO errorInfo in its exception chain.
    do { if (isset($e->errorInfo[1])) $timeoutCode = (int)$e->errorInfo[1]; if (strpos($e->getMessage(), '3024') !== false) $timeoutCode = 3024; } while ($e = $e->getPrevious());
}
mysqlCheck($timeoutCode === 3024 && microtime(true)-$startedAt < 1.0, 'MySQL physically interrupts SELECT at execution budget');
mysqlCheck((int)$db->connect()->query('SELECT @@SESSION.max_execution_time AS lim', [], true)[0]['lim'] === 1234, 'session timeout restored after interrupted SQL');
$cancelled = new app\services\query\metric\MetricReadTransaction(100, function () { throw new app\services\query\metric\MetricQueryContractException('FIXTURE_CANCELLED', 'cancel'); });
mysqlReject(function () use ($cancelled) { $cancelled->run(function () { throw new RuntimeException('must not execute'); }); }, 'FIXTURE_CANCELLED');
mysqlCheck((int)$db->connect()->query('SELECT @@SESSION.max_execution_time AS lim', [], true)[0]['lim'] === 1234 && !$db->connect()->getPdo()->inTransaction(), 'cancel checkpoint restores session and does not start transaction');
$db->connect()->execute('SET SESSION max_execution_time = 0');
$consistent = $transaction->run(function ($r) use ($pdo, $range) {
    $before = $r->cashTotals('0', [1], $range);
    $pdo->exec("UPDATE eb_cashier_v3_payment_sale_allocation_fact SET amount_cents=900101 WHERE allocation_fact_id='cash'");
    $after = $r->cashTotals('0', [1], $range);
    return [$before, $after];
});
mysqlCheck($consistent[0] === $consistent[1] && $consistent[0]['gross_cents'] === 903001, 'concurrent commit invisible inside repeatable read');
mysqlCheck($reader->cashTotals('0', [1], $range)['gross_cents'] === 903101, 'new query sees committed update');
$writeRejected = false;
try { $transaction->run(function () use ($db) { $db->connect()->execute("UPDATE eb_cashier_v3_payment_sale_allocation_fact SET amount_cents=1 WHERE allocation_fact_id='cash'"); }); }
catch (Throwable $e) { $writeRejected = true; }
mysqlCheck($writeRejected && !$db->connect()->getPdo()->inTransaction(), 'read-only transaction rejects writes and rolls back');
$db->connect()->startTrans();
try { mysqlReject(function () use ($transaction) { $transaction->run(function () {}); }, 'METRIC_READ_TRANSACTION_NESTED'); }
finally { $db->connect()->rollback(); }
$pdo->exec('ALTER TABLE eb_cashier_v3_order_lifecycle_operation ENGINE=MyISAM');
mysqlReject(function () use ($transaction) { $transaction->run(function () {}); }, 'METRIC_READ_ENGINE_UNVERIFIED');
$pdo->exec('ALTER TABLE eb_cashier_v3_order_lifecycle_operation ENGINE=InnoDB');
require __DIR__ . '/query-export-lifecycle-mysql.php';
$now = time(); $tmp = sys_get_temp_dir() . '/mohe-real-query-fixture-' . bin2hex(random_bytes(8));
$clock = function () use (&$now) { return $now; };
$store = new app\services\query\metric\MetricReadViewStore($tmp, str_repeat('fixture-signing-', 3), $clock);
$binding = ['instance_id'=>'fixture-instance','subject_ref'=>'fixture-user','terminal'=>'platform','tenant_id'=>'0','permission_version'=>'fixture-v1','report_capability_code'=>'group_management_dashboard','scope_provider_code'=>'fixture-only','scope_mode'=>'stores','store_ids'=>[1,2]];
$service = new app\services\query\metric\MetricReadViewServices($store, function () use (&$binding) { return $binding; }, null, $clock);
$query = ['query_shape'=>'summary','metric_codes'=>['cash_performance','consume_amount'],'start_date'=>'2026-09-08','end_date'=>'2026-09-08','compare_range'=>null,'store_ids'=>[1],'business_filters'=>[]];
try {
    $view = $service->create([], $query);
    mysqlCheck(array_column($view['results'], 'amount_cents') === [903101,9500], 'real combined summary');
    $pdo->exec("UPDATE eb_cashier_v3_payment_sale_allocation_fact SET amount_cents=901101 WHERE allocation_fact_id='cash'");
    mysqlCheck($service->replay([], $query, $view['read_consistency_ref']) === $view, 'original exact view survives new fact commit');
    $comparison = $query; $comparison['query_shape']='comparison'; $comparison['compare_range']=['start'=>'2026-09-07','end'=>'2026-09-07'];
    mysqlCheck(array_column($service->create([], $comparison)['results'], 'amount_cents') === [904101,9500,5000,0], 'real comparison periods');
    $trend=$query; $trend['query_shape']='trend'; $trend['start_date']='2026-09-07';
    $tr=$service->create([], $trend)['results'];
    mysqlCheck(array_column($tr[0]['rows'],'amount_cents') === [5000,904101], 'real daily cash trend complete');
    mysqlCheck(array_column($tr[1]['rows'],'amount_cents') === [0,9500], 'real daily consumption zero fill');
    $rank=$query; $rank['query_shape']='ranking'; $rank['store_ids']=[]; $rank['ranking']=['direction'=>'top_and_bottom','limit'=>5];
    $rankView=$service->create([], $rank); $rr=$rankView['results'];
    mysqlCheck(array_column($rr[0]['rows']['top'],'store_id') === [1,2] && array_column($rr[0]['rows']['bottom'],'store_id') === [2,1], 'full authorized ranking both directions');
    mysqlCheck(array_column($rr[0]['rows']['top'],'store_name') === ['测试甲店','测试乙店'], 'authorized authoritative store labels');
    $memberRank = $rank;
    $memberRank['store_ids'] = [1];
    $memberRank['metric_codes'] = ['cash_performance'];
    $memberRank['business_filters'] = ['object_kind' => 'member'];
    $memberView = $service->create([], $memberRank);
    // The signed view canonicalizes associative key order. Compare canonical
    // payloads while preserving list order, exact integers and all fields.
    mysqlCheck(app\services\query\UnifiedQueryJson::encode($memberView['results'][0]['rows']['top'])
        === app\services\query\UnifiedQueryJson::encode([
            ['entity_id' => 101, 'entity_name' => '测试会员', 'amount_cents' => 901101],
            ['entity_id' => 102, 'entity_name' => '历史欠款会员', 'amount_cents' => 3000],
        ]), 'metric read view includes both allocated and historical debt-member cash without a separate member gate');
    $memberExport=app\services\query\metric\MetricReadViewExportProvider::project($memberView);
    $memberExportDirections=array_column($memberExport,null,'ranking_direction');
    mysqlCheck(count($memberExport)===4 && isset($memberExportDirections['前列'],$memberExportDirections['后列'])
        && array_column($memberExport,'metric_value')===['30.00','9011.01','9011.01','30.00']
        && array_column($memberExport,'store_name')===['历史欠款会员；范围：当前授权范围','测试会员；范围：当前授权范围','测试会员；范围：当前授权范围','历史欠款会员；范围：当前授权范围'],
        'member export preserves allocated and historical-debt identities in both ranking directions');
    $projectRank=$rank;
    $projectRank['store_ids']=[1];
    $projectRank['metric_codes']=['completed_service_item_count'];
    $projectRank['business_filters']=['object_kind'=>'project'];
    $projectRank['ranking']=['direction'=>'top','limit'=>5];
    $projectView=$service->create([],$projectRank);
    mysqlCheck(app\services\query\UnifiedQueryJson::encode($projectView['results'][0]['rows']['top'])
        === app\services\query\UnifiedQueryJson::encode([['entity_id'=>31,'entity_name'=>'护理项目','amount_cents'=>3]])
        && ($projectView['results'][0]['object_label']??null)==='项目',
        'metric read view executes a registered project dimension without an object-specific reader');
    $projectExport=app\services\query\metric\MetricReadViewExportProvider::project($projectView);
    mysqlCheck(count($projectExport)===1 && $projectExport[0]['store_name']==='护理项目；范围：当前授权范围'
        && $projectExport[0]['metric_value']==='3' && $projectExport[0]['unit']==='项',
        'registered project ranking export keeps the object identity and count unit');
    $memberUnrestricted=$memberRank;$memberUnrestricted['store_ids']=[];
    mysqlReject(function()use($service,$memberUnrestricted,$memberView){$service->replay([],$memberUnrestricted,$memberView['read_consistency_ref']);},'METRIC_READ_BINDING_MISMATCH');
    $binding['permission_version']='member-revoked';
    mysqlReject(function()use($service,$memberRank,$memberView){$service->replay([],$memberRank,$memberView['read_consistency_ref']);},'METRIC_READ_BINDING_MISMATCH');
    $binding['permission_version']='fixture-v1';
    $pdo->exec("UPDATE eb_system_store SET name='改名后' WHERE id=1");
    mysqlCheck($service->replay([], $rank, $rankView['read_consistency_ref'])['results'] === $rr, 'replay preserves names with original amounts');
    $exportQuery=$query; $exportQuery['metric_codes']=['consume_amount'];
    $exportView=$service->create([],$exportQuery);
    $pageRegistry=app\services\query\UnifiedQueryPageRegistry::fromRegistrars([], [new app\services\query\metric\MetricReadViewExportRegistrar()]);
    mysqlCheck($pageRegistry->pageCodes()===['metric_read_view_export'],'export registrar isolated from ordinary pages');
    $exportProvider=new app\services\query\metric\MetricReadViewExportProvider($service,function ($context,$ref) use ($exportQuery,$exportView) {
        if ($ref!==$exportView['read_consistency_ref']) throw new RuntimeException('bad fixture ref');
        return ['principal'=>[],'query'=>$exportQuery];
    });
    $exportFields=array_keys(app\services\query\metric\MetricReadViewExportRegistrar::fields());
    $exportCompiler=new app\services\query\UnifiedQueryExecutionServices($pageRegistry,new app\services\query\StructuredExpressionValidator($pageRegistry),new app\services\query\StructuredExpressionEvaluator());
    $exportPlan=$exportCompiler->validatedPlan('metric_read_view_export',[],['visibleFields'=>$exportFields],['permissions'=>['mohe.ai.export'],'query_cutoff_date'=>'2026-09-08']);
    $exportPlan=app\services\query\UnifiedQueryJson::decode(app\services\query\UnifiedQueryJson::encode($exportPlan));
    $exportContext=['scope_dimensions'=>['metric_read_ref'=>[$exportView['read_consistency_ref']]]];
    $exportResult=$exportProvider->executeFrozenPlan($exportContext,$exportPlan,'query',$exportFields);
    mysqlCheck($exportResult['exportRows'][0]['metric_value']==='95.00' && $exportResult['exportRows'][0]['unit']==='元' && $exportResult['result_hash']===$exportView['result_hash'],'export reuses exact view cents and evidence hash');
    $pageRejected=false; try {$exportProvider->executeFrozenPlan($exportContext,$exportPlan,'page',$exportFields);} catch(Throwable $e) {$pageRejected=true;}
    mysqlCheck($pageRejected,'shared metric export refuses page scope');
    $binding['store_ids']=[2];
    mysqlReject(function () use ($service,$query,$view) { $service->replay([],$query,$view['read_consistency_ref']); }, 'METRIC_PERMISSION_DENIED');
    $binding['store_ids']=[1,2]; $binding['scope_mode']='self_participant';
    mysqlReject(function () use ($service,$query) { $service->create([],$query); }, 'METRIC_PERMISSION_GRAIN_UNAVAILABLE');
    $binding['scope_mode']='stores';
    $actualQuery=$query; $actualQuery['metric_codes']=['actual_performance'];
    $actualView=$service->create([],$actualQuery);
    mysqlCheck($actualView['results'][0]['amount_cents']
        === $registered->summary('actual_performance','0',[1],$range), 'AI view consumes registered actual performance');
    $now+=86400; mysqlCheck($store->cleanup()===8,'real views TTL cleanup includes member and project ranking views');
} finally {
    foreach(new DirectoryIterator($tmp) as $file) if(!$file->isDot() && $file->isFile() && !$file->isLink()) unlink($file->getPathname());
    rmdir($tmp);
}
require __DIR__.'/query-export-runtime-mysql.php';
require __DIR__.'/cash-recharge-mysql.php';
// A distinct member is a cross-store population, not a sum of per-store
// distinct values. Keep this in the standard MySQL regression fixture.
$connection = $db->connect('fixture');
$connection->startTrans();
try {
    $connection->execute("INSERT INTO eb_cashier_v3_entitlement_service_fact (tenant_id,store_id,business_date,checkout_request_id,source_line_id,service_status,quantity,member_id,order_id) VALUES
        ('0',1,'2026-09-09','audit-1','audit-1','completed',1,900001,'audit-order-1'),
        ('0',2,'2026-09-09','audit-2','audit-2','completed',1,900001,'audit-order-2')");
    $auditRange = ['start'=>'2026-09-09','end'=>'2026-09-09'];
    $summary = $registered->summary('customer_active', '0', [1,2], $auditRange);
    $daily = $registered->dailyTotals('customer_active', '0', [1,2], $auditRange);
    mysqlCheck($summary === 1 && $daily === [['business_date'=>'2026-09-09','metric_value'=>1]], 'cross-store daily distinct matches summary');
} finally {
    $connection->rollback();
}
if (getenv('MOHE_QUERY_TEST_SCALE')==='yes') require __DIR__.'/cash-recharge-scale-mysql.php';
echo 'query-mysql: PASS (' . $checks . ' real MySQL ' . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . " checks; disposable fixture, no customer DB)\n";
