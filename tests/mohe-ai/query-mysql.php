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
    'cashier_v3_payment_sale_allocation_fact' => 'id INT PRIMARY KEY AUTO_INCREMENT,tenant_id VARCHAR(64),allocation_fact_id VARCHAR(64),sale_fact_id VARCHAR(64),reversal_of VARCHAR(64) NULL,store_id INT,business_date DATE,status VARCHAR(32),amount_cents BIGINT,order_id VARCHAR(64)',
    'cashier_v3_sale_fact' => 'tenant_id VARCHAR(64),fact_id VARCHAR(64)',
    'cashier_v3_performance_fact' => 'id INT PRIMARY KEY AUTO_INCREMENT,tenant_id VARCHAR(64),store_id INT,business_date DATE,status VARCHAR(32),performance_type VARCHAR(64),amount_cents BIGINT,order_id VARCHAR(64),checkout_request_id VARCHAR(64),source_line_id VARCHAR(64)',
    'cashier_v3_entitlement_service_fact' => 'tenant_id VARCHAR(64),checkout_request_id VARCHAR(64),source_line_id VARCHAR(64),service_status VARCHAR(32)',
    'cashier_v3_order_lifecycle_operation' => 'tenant_id VARCHAR(64),source_order_id VARCHAR(64),source_type VARCHAR(32),operation_type VARCHAR(32),status VARCHAR(32)',
    'cashier_v3_payment_fact' => "id INT PRIMARY KEY AUTO_INCREMENT,fact_id VARCHAR(64),tenant_id VARCHAR(64),store_id INT,member_id INT,business_date DATE,status VARCHAR(32),fact_type VARCHAR(64),source_document_type VARCHAR(64),payment_method VARCHAR(32),amount_cents BIGINT,order_id VARCHAR(64),source_line_id VARCHAR(64),organization_id VARCHAR(64),organization_path_snapshot VARCHAR(128),store_name_snapshot VARCHAR(128),business_source_primary_id INT DEFAULT 0,business_source_label_snapshot VARCHAR(128) DEFAULT ''",
    'cashier_v3_recharge_debt_repayment' => 'tenant_id VARCHAR(64),repayment_id VARCHAR(64),recharge_id INT,store_id INT,member_id INT,status VARCHAR(32)',
    'cashier_v3_order_center_void_operation' => 'tenant_id VARCHAR(64),source_id VARCHAR(64),source_kind VARCHAR(64),status VARCHAR(32)',
];
foreach ($schemas as $name => $schema) $pdo->exec('CREATE TABLE eb_' . $name . ' (' . $schema . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$pdo->exec("INSERT INTO eb_system_store VALUES (1,'测试甲店'),(2,'测试乙店'),(3,'无权店')");
$pdo->exec("INSERT INTO eb_cashier_v3_sale_fact VALUES ('0','sale'),('other','sale')");
$pdo->exec("INSERT INTO eb_cashier_v3_payment_sale_allocation_fact (tenant_id,allocation_fact_id,sale_fact_id,reversal_of,store_id,business_date,status,amount_cents,order_id) VALUES
('0','cash','sale',NULL,1,'2026-09-08','effective',900001,'order'),
('0','refund','sale','cash',1,'2026-09-08','effective',-10000,'order'),
('0','yesterday','sale',NULL,1,'2026-09-07','effective',5000,'order'),
('0','otherstore','sale',NULL,2,'2026-09-08','effective',30000,'order'),
('0','pending','sale',NULL,1,'2026-09-08','pending',777,'order'),
('0','void','sale',NULL,1,'2026-09-08','effective',100000,'voided'),
('other','foreign','sale',NULL,1,'2026-09-08','effective',999999,'order')");
$pdo->exec("INSERT INTO eb_cashier_v3_order_lifecycle_operation VALUES ('0','voided','sales','void','succeeded')");
$pdo->exec("INSERT INTO eb_cashier_v3_entitlement_service_fact VALUES ('0','checkout','line','completed'),('0','pending-checkout','line','pending')");
$pdo->exec("INSERT INTO eb_cashier_v3_performance_fact (tenant_id,store_id,business_date,status,performance_type,amount_cents,order_id,checkout_request_id,source_line_id) VALUES
('0',1,'2026-09-08','effective','consumption_performance_recorded',10000,'order','checkout','line'),
('0',1,'2026-09-08','effective','consumption_performance_recorded',-500,'order','checkout','line'),
('0',1,'2026-09-08','effective','consumption_performance_recorded',900,'order','pending-checkout','line'),
('0',1,'2026-09-08','effective','consumption_performance_recorded',100000,'voided','checkout','line'),
('0',2,'2026-09-08','effective','consumption_performance_recorded',2000,'order','checkout','line'),
('0',1,'2026-09-08','effective','actual_performance_recorded',12000,'order','checkout','line')");
$checks = 0;
function mysqlCheck($ok, $label) { global $checks; if (!$ok) throw new RuntimeException($label); ++$checks; }
function mysqlReject(callable $call, $code) { try { $call(); } catch (app\services\query\metric\MetricQueryContractException $e) { mysqlCheck($e->getErrorCode() === $code, 'error ' . $e->getErrorCode()); return; } throw new RuntimeException('expected rejection'); }
$reader = new app\services\query\metric\GroupPerformanceMetricReadServices();
$range = ['start' => '2026-09-08', 'end' => '2026-09-08'];
mysqlCheck($reader->cashTotals('0', [1], $range) === ['gross_cents' => 900001, 'refund_cents' => -10000], 'cash signs, scope, pending and void exclusion');
mysqlCheck($reader->performanceTotal('0', [1], $range, 'consumption_performance_recorded') === 9500, 'signed consumed complete service only');
mysqlCheck($reader->cashTotals('0', [1, 2], $range)['gross_cents'] === 930001, 'complete multistore sum');
// Exercise the existing report's actual delegated methods, without constructor/application boot.
$reflection = new ReflectionClass(app\services\report\GroupManagementDashboardServices::class);
$report = $reflection->newInstanceWithoutConstructor();
foreach (['cashTotals' => ['0', [1], $range], 'performanceTotalScalar' => ['0', [1], $range, 'consumption_performance_recorded']] as $method => $args) {
    $m = $reflection->getMethod($method); $m->setAccessible(true);
    $actual = $m->invokeArgs($report, $args);
    mysqlCheck($actual === ($method === 'cashTotals' ? ['gross_cents' => 900001, 'refund_cents' => -10000] : 9500), 'real report delegated value ' . $method);
}
$transaction = new app\services\query\metric\MetricReadTransaction();
$db->connect()->execute('SET SESSION max_execution_time = 1234');
$bounded = new app\services\query\metric\MetricReadTransaction(40);
$timeoutCode = 0; $startedAt = microtime(true);
try {
    $bounded->run(function ($r) use ($db, $range) {
        $r->performanceTotal('0', [1], $range, 'consumption_performance_recorded');
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
mysqlCheck($consistent[0] === $consistent[1] && $consistent[0]['gross_cents'] === 900001, 'concurrent commit invisible inside repeatable read');
mysqlCheck($reader->cashTotals('0', [1], $range)['gross_cents'] === 900101, 'new query sees committed update');
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
    mysqlCheck(array_column($view['results'], 'amount_cents') === [900101,9500], 'real combined summary');
    $pdo->exec("UPDATE eb_cashier_v3_payment_sale_allocation_fact SET amount_cents=901101 WHERE allocation_fact_id='cash'");
    mysqlCheck($service->replay([], $query, $view['read_consistency_ref']) === $view, 'original exact view survives new fact commit');
    $comparison = $query; $comparison['query_shape']='comparison'; $comparison['compare_range']=['start'=>'2026-09-07','end'=>'2026-09-07'];
    mysqlCheck(array_column($service->create([], $comparison)['results'], 'amount_cents') === [901101,9500,5000,0], 'real comparison periods');
    $trend=$query; $trend['query_shape']='trend'; $trend['start_date']='2026-09-07';
    $tr=$service->create([], $trend)['results'];
    mysqlCheck(array_column($tr[0]['rows'],'amount_cents') === [5000,901101], 'real daily cash trend complete');
    mysqlCheck(array_column($tr[1]['rows'],'amount_cents') === [0,9500], 'real daily consumption zero fill');
    $rank=$query; $rank['query_shape']='ranking'; $rank['store_ids']=[]; $rank['ranking']=['direction'=>'top_and_bottom','limit'=>5];
    $rankView=$service->create([], $rank); $rr=$rankView['results'];
    mysqlCheck(array_column($rr[0]['rows']['top'],'store_id') === [1,2] && array_column($rr[0]['rows']['bottom'],'store_id') === [2,1], 'full authorized ranking both directions');
    mysqlCheck(array_column($rr[0]['rows']['top'],'store_name') === ['测试甲店','测试乙店'], 'authorized authoritative store labels');
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
    mysqlCheck($exportResult['exportRows'][0]['amount_yuan']==='95.00' && $exportResult['result_hash']===$exportView['result_hash'],'export reuses exact view cents and evidence hash');
    $pageRejected=false; try {$exportProvider->executeFrozenPlan($exportContext,$exportPlan,'page',$exportFields);} catch(Throwable $e) {$pageRejected=true;}
    mysqlCheck($pageRejected,'shared metric export refuses page scope');
    $binding['store_ids']=[2];
    mysqlReject(function () use ($service,$query,$view) { $service->replay([],$query,$view['read_consistency_ref']); }, 'METRIC_PERMISSION_DENIED');
    $binding['store_ids']=[1,2]; $binding['scope_mode']='self_participant';
    mysqlReject(function () use ($service,$query) { $service->create([],$query); }, 'METRIC_PERMISSION_GRAIN_UNAVAILABLE');
    $binding['scope_mode']='stores';
    $invalid=$query; $invalid['metric_codes']=['actual_performance'];
    mysqlReject(function () use ($service,$invalid) { $service->create([],$invalid); }, 'METRIC_NOT_REGISTERED');
    $now+=86400; mysqlCheck($store->cleanup()===5,'real views TTL cleanup');
} finally {
    foreach(new DirectoryIterator($tmp) as $file) if(!$file->isDot() && $file->isFile() && !$file->isLink()) unlink($file->getPathname());
    rmdir($tmp);
}
require __DIR__.'/query-export-runtime-mysql.php';
require __DIR__.'/cash-recharge-mysql.php';
if (getenv('MOHE_QUERY_TEST_SCALE')==='yes') require __DIR__.'/cash-recharge-scale-mysql.php';
echo 'query-mysql: PASS (' . $checks . ' real MySQL ' . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . " checks; disposable fixture, no customer DB)\n";
