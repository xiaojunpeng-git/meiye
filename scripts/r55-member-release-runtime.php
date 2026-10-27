<?php
declare(strict_types=1);

/** Read-only capability/list compatibility proof for each authorized instance.
 * No login token, export task, preference write or member identity is returned.
 */
$sites = ['rh' => ['rh.cc3798.com', 'ruihao', '3306'], '008' => ['008.cc3798.com', 'mohe_008', '3307'], '010' => ['010.cc3798.com', 'mohe_010_app', '3307']];
$site = $argv[1] ?? '';
if (PHP_SAPI !== 'cli' || !isset($sites[$site])) throw new RuntimeException('Explicit target required');
[$domain, $database, $port] = $sites[$site]; $root = '/www/wwwroot/' . $domain;
require $root . '/vendor/autoload.php';
(new \think\App($root . '/'))->initialize();
set_exception_handler(static function (Throwable $error): void {
    file_put_contents('php://stderr', get_class($error) . ': ' . $error->getMessage() . PHP_EOL); exit(1);
});
$config = config('database.connections.mysql');
if ($config['database'] !== $database || (string)$config['hostport'] !== $port || $config['prefix'] !== 'eb_'
    || !in_array($config['hostname'], ['127.0.0.1', 'localhost'], true)) throw new RuntimeException('Wrong database');
\think\facade\Db::execute('SET SESSION TRANSACTION READ ONLY');
$pdo = \think\facade\Db::connect()->getPdo(); $pdo->beginTransaction();
try {
    $staff = \app\model\store\SystemStoreStaff::where('status', 1)->where('is_del', 0)->where('employee_id', '>', 0)->order('id')->find();
    if (!$staff) throw new RuntimeException('No active staff fixture');
    $staff = $staff->toArray();
    $staff['roles'] = \app\services\cashier\v3\CashierV3PermissionSnapshotServices::normalizeRoleIds($staff['roles'] ?? []);
    $operator = (new \app\services\cashier\v3\CashierV3ScopeResolver())->operatorScope((int)$staff['store_id'], (int)$staff['id']);
    $scope = app()->make(\app\services\cashier\v3\CashierV3DataScopeFactory::class)->build($operator->storeId(), $operator->operatorId(), $staff, '0', $operator->organizationId());
    $runtime = \app\services\cashier\v3\query\UnifiedQueryModule::runtime();
    $context = $runtime['contextFactory']->make($operator, $scope, ['pageCode' => 'member_list']);
    // Build with a positive read-only test version, never register/persist one.
    $capability = $runtime['capabilities']->build($context, 'member_list', 1);
    $keys = array_column($capability['fields'], 'key');
    foreach (['member_name', 'phone', 'member_no', 'member_tag', 'created_at', 'account_balance', 'latest_visit_date'] as $key) {
        if (!in_array($key, $keys, true)) throw new RuntimeException('Missing required capability field');
    }
    $filters = array_filter($capability['fields'], static function (array $field): bool { return !empty($field['capabilities']['filter']); });
    if (!$capability['enabled'] || !$filters) throw new RuntimeException('No dynamic filter capability');
    $first = $runtime['memberProvider']->query($context, ['page' => 1, 'limit' => 20]);
    if (!isset($first['total'], $first['records']) || count($first['records']) > 20) throw new RuntimeException('Invalid member projection');
    $empty = $runtime['memberProvider']->query($context, ['keyword' => 'R55-NO-SUCH-MEMBER-UNIQUE', 'page' => 1, 'limit' => 20]);
    if ($empty['records'] !== [] || (int)$empty['total'] !== 0) throw new RuntimeException('Empty search mismatch');
    echo json_encode(['result' => 'PASS', 'site' => $site, 'serverReadOnly' => true, 'businessWrites' => 0,
        'capabilityFields' => count($keys), 'filterFields' => count($filters), 'listTotal' => (int)$first['total'],
        'pageRows' => count($first['records']), 'emptySearch' => 'PASS',
        'limit' => 'CLI trusted-scope projection; no authenticated browser session or financial action.']), PHP_EOL;
} finally { if ($pdo->inTransaction()) $pdo->rollBack(); }
