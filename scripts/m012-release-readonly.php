<?php
declare(strict_types=1);
/** Instance-bound, READ ONLY verification of the actual reservation partition.
 * No lifecycle command, card operation, business-data repair or customer PII output.
 */
$site = $argv[1] ?? '';
$targets = ['rh' => ['rh.cc3798.com', 'ruihao', 3306], '008' => ['008.cc3798.com', 'mohe_008', 3307], '010' => ['010.cc3798.com', 'mohe_010_app', 3307]];
if (!isset($targets[$site])) throw new RuntimeException('EXPLICIT_SITE_REQUIRED');
[$domain, $database, $port] = $targets[$site]; $root = '/www/wwwroot/' . $domain;
chdir($root); require $root . '/vendor/autoload.php'; (new think\App($root . '/'))->initialize();
set_exception_handler(static function (Throwable $error): void { fwrite(STDERR, $error->getMessage() . PHP_EOL); exit(1); });
$config = config('database.connections.mysql');
if ($config['database'] !== $database || (int)$config['hostport'] !== $port) throw new RuntimeException('DATABASE_MISMATCH');
use think\facade\Db;
use app\services\cashier\v3\reservation\CashierV3ReservationPartitionProvider;
use app\services\cashier\v3\reservation\CashierV3ReservationLifecycleServices;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3DataScopeContext;
Db::query('SET SESSION TRANSACTION READ ONLY'); Db::startTrans();
try {
    $seed = Db::name('cashier_v3_reservation')->where('lifecycle_generation', CashierV3ReservationLifecycleServices::GENERATION)->order('id desc')->find();
    $store = $seed ? (int)$seed['store_id'] : (int)Db::name('system_store')->order('id asc')->value('id');
    $tenant = $seed ? (string)$seed['tenant_id'] : '1';
    if ($store <= 0) throw new RuntimeException('STORE_FIXTURE_REQUIRED');
    $scope = new CashierV3DataScopeContext(1, 1, $store, $tenant, '', [$store], 'stores', [], false, '', 'm012-readonly', ['cashier.v3.reservation'], []);
    $operator = new CashierV3OperatorScope($store, 1, '', $tenant);
    $expected = (int)Db::name('cashier_v3_reservation')->where('tenant_id', $tenant)->where('store_id', $store)
        ->where('lifecycle_generation', CashierV3ReservationLifecycleServices::GENERATION)->where('status', 'PENDING_CONFIRMATION')->count();
    $provider = new CashierV3ReservationPartitionProvider();
    $payload = $provider->readPartition('', '', $operator, $scope, ['quickFilter' => 'all_pending_confirmation', 'pageSize' => 20])['payload'];
    $afterPatch = isset($payload['quickCounts']['allPendingConfirmation']);
    if ($afterPatch && ($payload['total'] !== $expected || $payload['quickCounts']['allPendingConfirmation'] !== $expected)) throw new RuntimeException('PENDING_COUNT_MISMATCH');
    foreach ($payload['records'] as $row) if ($afterPatch && $row['statusCode'] !== 'PENDING_CONFIRMATION') throw new RuntimeException('NON_PENDING_RESULT');
    $hints = ['pageSize' => 100, 'calendarDate' => $seed ? date('Y-m-d', (int)$seed['appointment_start_at']) : date('Y-m-d')];
    $calendar = $provider->readPartition('', '', $operator, $scope, $hints)['payload'];
    $byId = [];
    foreach ($calendar['records'] as $record) $byId[(int)$record['id']] = !empty($record['customServices']);
    $marked = 0;
    foreach ($calendar['calendar']['blocks'] as $block) {
        if ($afterPatch && ($block['hasCustomServices'] ?? null) !== ($byId[(int)$block['reservationId']] ?? false)) throw new RuntimeException('CUSTOM_INDICATOR_MISMATCH');
        if (!empty($block['hasCustomServices'])) $marked++;
    }
    echo json_encode(['site' => $site, 'database' => $database, 'readOnly' => true, 'patched' => $afterPatch,
        'pendingCount' => $expected, 'total' => $payload['total'], 'pageRows' => count($payload['records']),
        'calendarBlocks' => count($calendar['calendar']['blocks']), 'markedBlocks' => $marked,
        'customSnapshotMatch' => $afterPatch, 'realCommandExecuted' => false], JSON_THROW_ON_ERROR), PHP_EOL;
} finally { Db::rollback(); }
