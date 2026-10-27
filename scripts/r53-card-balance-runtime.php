<?php
declare(strict_types=1);

// Instance-bound read-only schema/permission/history compatibility probe. This
// never dispatches a financial command, creates a session or writes a grant.
$sites = ['rh' => ['rh.cc3798.com', 'ruihao', '3306'],
    '008' => ['008.cc3798.com', 'mohe_008', '3307'], '010' => ['010.cc3798.com', 'mohe_010_app', '3307']];
$site = $argv[1] ?? ''; $mode = $argv[2] ?? 'before';
if (PHP_SAPI !== 'cli' || !isset($sites[$site]) || !in_array($mode, ['before', 'after'], true)) exit(2);
set_exception_handler(static function (Throwable $error): void {
    file_put_contents('php://stderr', get_class($error) . ': ' . $error->getMessage() . PHP_EOL); exit(1);
});
[$domain, $database, $port] = $sites[$site]; $root = '/www/wwwroot/' . $domain;
$env = parse_ini_file($root . '/.env', true, INI_SCANNER_RAW);
$config = array_change_key_case($env['DATABASE'] ?? $env['database'] ?? []);
if ($config['database'] !== $database || (string)$config['hostport'] !== $port || $config['prefix'] !== 'eb_'
    || !in_array($config['hostname'], ['localhost', '127.0.0.1'], true)) throw new RuntimeException('Instance database mismatch');
$pdo = new PDO('mysql:host=' . $config['hostname'] . ';port=' . $port . ';dbname=' . $database . ';charset=utf8mb4',
    $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('SET SESSION TRANSACTION READ ONLY');
$tables = ['user', 'user_card_holder', 'store_order', 'store_order_cart_info', 'store_debt', 'store_debt_repay',
    'store_order_terminal_operation', 'store_v3_permission_catalog', 'store_v3_permission_audit', 'job_position_channel_rule',
    'cashier_v3_card_operation', 'cashier_v3_card_state', 'cashier_v3_card_rule_state', 'cashier_v3_card_rule_component',
    'cashier_v3_card_rule_group_state', 'cashier_v3_balance_fact', 'cashier_v3_business_event', 'cashier_v3_command_receipt',
    'cashier_v3_reservation_entitlement_occupation', 'cashier_v3_reservation_line', 'cashier_v3_reservation',
    'cashier_v3_service_order_line', 'cashier_v3_service_order',
    'cashier_v3_card_purchase_receipt', 'cashier_v3_card_operation_settlement', 'cashier_v3_refund_adjustment',
    'cashier_v3_order_lifecycle_operation', 'cashier_v3_entitlement_service_fact', 'cashier_v3_entitlement_writeoff_fact',
    'cashier_v3_service_record_void_operation', 'cashier_v3_debt_repayment', 'cashier_v3_debt_repayment_collection',
    'cashier_v3_debt_restoration', 'cashier_v3_debt_cancellation', 'cashier_v3_order_center_void_operation',
    'cashier_v3_order_lifecycle_benefit_reversal', 'card_project_replacement', 'store_order_status', 'store_order_writeoff',
    'cashier_v3_multi_card_upgrade_source', 'cashier_v3_multi_card_upgrade', 'cashier_v3_sales_order', 'cashier_v3_sales_order_line'];
$schemas = [];
foreach ($tables as $table) {
    $schemas[$table] = $pdo->query('SHOW COLUMNS FROM `eb_' . $table . '`')->fetchAll();
}
foreach ($schemas['cashier_v3_card_operation'] as $column) {
    if (strpos(strtolower($column['Type']), 'enum(') === 0
        && (($column['Field'] === 'operation_type' && strpos($column['Type'], "'card_to_balance'") === false)
            || ($column['Field'] === 'operation_status' && strpos($column['Type'], "'completed'") === false))) {
        throw new RuntimeException('Existing operation enum cannot store conversion');
    }
}
$catalog = $pdo->query('SELECT * FROM eb_store_v3_permission_catalog WHERE id=1')->fetch();
if (!$catalog || !is_array($rules = json_decode($catalog['rules_json'], true))) throw new RuntimeException('Permission catalog missing');
$conversion = array_values(array_filter($rules, static fn(array $row): bool => (int)$row['id'] === 301051));
if ($conversion && ($conversion[0]['feature_code'] ?? '') !== 'cashier.v3.cashier.card.to_balance') throw new RuntimeException('Permission ID collision');
$channelRows = $pdo->query('SELECT * FROM eb_job_position_channel_rule ORDER BY id')->fetchAll();
$indexes = [];
foreach (['store_order', 'store_debt_repay'] as $table) $indexes[$table] = $pdo->query('SHOW INDEX FROM eb_' . $table)->fetchAll();
$out = ['site' => $site, 'mode' => $mode, 'result' => 'PASS', 'readOnly' => true, 'financialCommands' => 0,
    'database' => $database, 'port' => $port, 'schemaSHA256' => hash('sha256', json_encode($schemas)), 'schemas' => $schemas,
    'catalogSHA256' => hash('sha256', json_encode($catalog)), 'catalogRevision' => (int)$catalog['revision'],
    'catalogRows' => count($rules), 'conversionLeafPresent' => (bool)$conversion,
    'channelRulesSHA256' => hash('sha256', json_encode($channelRows)), 'channelRuleRows' => count($channelRows), 'indexes' => $indexes];
if ($mode === 'after') {
    require $root . '/vendor/autoload.php';
    (new \think\App($root . '/'))->initialize();
    // Default read-only autocommit protects this application connection too,
    // without making the catalog reader think a financial transaction is active.
    \think\facade\Db::execute('SET SESSION TRANSACTION READ ONLY');
    $identity = \think\facade\Db::query('SELECT DATABASE() AS db, @@port AS port')[0];
    if ($identity['db'] !== $database || (string)$identity['port'] !== $port) throw new RuntimeException('Autoload database mismatch');
    if (!$conversion) throw new RuntimeException('New permission leaf missing after migration');
    $actualStaffChecks = [];
    // Sample actual enabled tenures with the granted position, not the first
    // historical staff IDs; the normal resolver still independently rechecks it.
    $profiles = \think\facade\Db::name('system_store_staff')->alias('staff')
        ->join('staff_job_position job', 'job.staff_id=staff.id AND job.employee_id=staff.employee_id')
        ->join('position position', 'position.id=job.position_id')
        ->join('job_position_channel_rule rule', 'rule.position_id=job.position_id')
        ->where('staff.status', 1)->where('staff.is_del', 0)->where('staff.employee_id', '>', 0)
        ->where('job.status', 1)->where('job.is_del', 0)->where('job.end_time', 0)
        ->where('position.status', 1)->where('position.use_store', 1)
        ->where('rule.channel', 'store_v3')->where('rule.status', 1)
        ->whereRaw('FIND_IN_SET(301051,rule.rules)>0')
        ->field('staff.*')->distinct(true)->order('staff.id asc')->limit(100)->select()->toArray();
    $resolver = new \app\services\cashier\v3\permission\CashierV3FeatureResolver();
    $assembler = new ReflectionClass(\app\services\cashier\v3\projection\CashierV3RootDomainAssembler::class);
    $method = $assembler->getMethod('resolveOperationFeatures'); $method->setAccessible(true);
    $checkedStores = [];
    foreach ($profiles as $profile) {
        // Use the same model accessors and role normalization as login; raw
        // SQL profiles are not an authenticated permission context.
        $profile = \app\model\store\SystemStoreStaff::where('id', (int)$profile['id'])->find()->toArray();
        $profile['roles'] = \app\services\cashier\v3\CashierV3PermissionSnapshotServices::normalizeRoleIds($profile['roles'] ?? []);
        $features = $resolver->resolveGrantedFeatures($profile);
        if (!in_array('cashier.v3.cashier.card.to_balance', $features, true)) continue;
        $storeId = (int)$profile['store_id'];
        if (isset($checkedStores[$storeId])) continue;
        $checkedStores[$storeId] = true;
        $operator = (new \app\services\cashier\v3\CashierV3ScopeResolver())->operatorScope($storeId, (int)$profile['id']);
        $scope = app()->make(\app\services\cashier\v3\CashierV3DataScopeFactory::class)
            ->build($operator->storeId(), $operator->operatorId(), $profile, '0', $operator->organizationId());
        if (!in_array('cashier.v3.cashier.card.to_balance', $method->invoke($assembler->newInstanceWithoutConstructor(), $scope), true)) {
            throw new RuntimeException('Actual granted staff lost root operation permission');
        }
        $sample = ['actualStaffId' => (int)$profile['id'], 'storeId' => $storeId, 'operationProjection' => 'PASS'];
        $holder = \think\facade\Db::name('user_card_holder')->where('store_id', $storeId)->where('is_del', 0)->order('id desc')->find();
        if ($holder) {
            $snapshot = (new \app\services\cashier\v3\order\CashierV3UnifiedRefundServices())
                ->transferSnapshot((int)$holder['id'], (int)$holder['uid'], '0', false);
            if (!isset($snapshot['amountCents'], $snapshot['eligible'], $snapshot['unavailableReason'])) throw new RuntimeException('Amount reader schema mismatch');
            $sample['amountReader'] = 'PASS';
            // This is SQL compatibility, not an authenticated financial authorization test.
            $sample['amountReaderEligibleSample'] = (bool)$snapshot['eligible'];
            // Exercise real history SQL only when the actual session scope
            // allows member-detail access; never manufacture wider authority.
            if (in_array($scope->authorizationMode(), ['all', 'stores'], true)) {
                $history = (new \app\services\cashier\v3\member\CashierV3MemberDetailQueryServices())
                    ->cardHistory((int)$holder['uid'], (int)$holder['id'], 1, $operator, $scope);
                $sample['cardHistoryReader'] = 'PASS';
                $sample['cardHistoryResultKeys'] = array_keys($history);
            }
        }
        $actualStaffChecks[] = $sample;
        if (count($actualStaffChecks) >= 3) break;
    }
    $out['actualStaffPermissionChecks'] = $actualStaffChecks;
    $out['staffSampleLimit'] = $actualStaffChecks ? null : 'No currently granted staff in first100 matching active tenures; not positive staff acceptance';
    $out['codeSources'] = [];
    foreach ([\app\services\cashier\v3\card\CashierV3CardToBalanceServices::class,
        \app\services\cashier\v3\card\CashierV3CardConversionGuard::class,
        \app\services\cashier\v3\member\CashierV3MemberDetailQueryServices::class] as $class) {
        $file = (new ReflectionClass($class))->getFileName();
        if (strpos($file, $root . '/app/') !== 0) throw new RuntimeException('Foreign runtime source');
        $out['codeSources'][substr($file, strlen($root) + 1)] = hash_file('sha256', $file);
    }
}
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
