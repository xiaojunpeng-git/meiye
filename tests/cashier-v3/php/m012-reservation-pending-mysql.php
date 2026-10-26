<?php
declare(strict_types=1);

/** Local rollback-only fixtures exercise the real partition and custom-service reader.
 * Never run against a customer connection or invoke reservation lifecycle commands.
 */
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
});
use think\facade\Db;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\reservation\CashierV3ReservationPartitionProvider;
use app\services\cashier\v3\reservation\CashierV3ReservationLifecycleServices;

$config = config('database.connections.mysql');
if (getenv('M012_LOCAL_TEST') !== '1' || $config['hostname'] !== 'mysql' || $config['database'] !== 'ruihao') throw new RuntimeException('LOCAL_DATABASE_REQUIRED');
$check = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS {$message}\n";
};
$header = Db::name('cashier_v3_reservation')->order('id desc')->find();
if (!$header) throw new RuntimeException('Local fixture header required');
$store = (int)$header['store_id'];
$tenant = 'm012-' . bin2hex(random_bytes(6));
$operator = new CashierV3OperatorScope($store, 1, '', $tenant);
$scope = new CashierV3DataScopeContext(1, 1, $store, $tenant, '', [$store], 'stores', [], false, '', 'm012-local', ['cashier.v3.reservation'], []);
$read = static function (array $hints) use ($operator, $scope): array {
    return (new CashierV3ReservationPartitionProvider())->readPartition('', '', $operator, $scope, $hints)['payload'];
};
$countsBefore = [Db::name('cashier_v3_reservation')->count(), Db::name('user_card_holder')->count()];
Db::startTrans();
try {
    unset($header['id']);
    $header['tenant_id'] = $tenant;
    $header['lifecycle_generation'] = CashierV3ReservationLifecycleServices::GENERATION;
    $header['service_order_id'] = 0;
    $header['actual_service_started_at'] = 0;
    $header['actual_service_ended_at'] = 0;
    $ids = [];
    $zone = new DateTimeZone('Asia/Shanghai');
    for ($i = 0; $i < 27; $i++) {
        $row = $header;
        $row['reservation_no'] = 'M012-' . bin2hex(random_bytes(8));
        $row['reservation_id'] = 'M012-' . bin2hex(random_bytes(8));
        $row['member_name_snapshot'] = 'M012 fixture ' . $i;
        $row['appointment_start_at'] = (new DateTimeImmutable('2030-01-0' . (1 + $i % 3) . ' 12:00', $zone))->getTimestamp();
        $row['appointment_end_at'] = $row['appointment_start_at'] + 3600;
        $row['status'] = $i === 23 ? 'COMPLETED' : 'PENDING_CONFIRMATION';
        if ($i === 24) $row['store_id'] = $store + 100000;
        if ($i === 25) $row['tenant_id'] = $tenant . '-other';
        if ($i === 26) $row['lifecycle_generation'] = 'M012_LEGACY';
        $ids[] = (int)Db::name('cashier_v3_reservation')->insertGetId($row);
    }
    Db::name('cashier_v3_reservation_custom_service')->insert([
        'tenant_id' => $tenant, 'store_id' => $store, 'reservation_id' => $ids[0],
        'service_name_id' => 1, 'name_snapshot' => '历史定制服务', 'quantity' => 1, 'created_at' => time(),
    ]);
    $hints = ['calendarDate' => '2030-01-01', 'quickFilter' => 'all_pending_confirmation', 'pageSize' => 20];
    $all = $read($hints);
    $check($all['total'] === 23 && count($all['records']) === 20, 'all-date pending returns total 23 and first page 20');
    $check($all['quickCounts']['allPendingConfirmation'] === 23, 'global count excludes other store, tenant, generation and completed');
    $second = $read($hints + ['page' => 2]);
    $check(count($second['records']) === 3 && $second['total'] === 23, 'last page contains remaining three records');
    $beyond = $read($hints + ['page' => 99]);
    $check($beyond['page'] === 2 && count($beyond['records']) === 3, 'out-of-range page is clamped to final page');
    $check(!array_intersect(array_column($all['records'], 'id'), array_column($second['records'], 'id')), 'stable page order has no duplicate IDs');
    $check(count(array_unique(array_map(static function ($r) { return substr($r['appointmentTime'], 0, 10); }, $all['records']))) > 1, 'results span multiple business dates');
    $day = $read(['calendarDate' => '2030-01-01', 'pageSize' => 100]);
    $check($day['total'] === 8 && $day['quickCounts']['pendingConfirmation'] === 8, 'normal calendar retains its single-day scope');
    $check($day['quickCounts']['allPendingConfirmation'] === 23, 'shortcut count remains cross-date in normal calendar');
    $marked = array_values(array_filter($day['calendar']['blocks'], static function ($b) use ($ids) { return $b['reservationId'] === $ids[0]; }));
    $check(count($marked) >= 2 && !in_array(false, array_column($marked, 'hasCustomServices'), true), 'staff and room blocks both retain custom-service indicator');
    $plain = array_values(array_filter($day['calendar']['blocks'], static function ($b) use ($ids) { return $b['reservationId'] !== $ids[0]; }));
    $check(!in_array(true, array_column($plain, 'hasCustomServices'), true), 'ordinary booking blocks have no indicator');
    $filtered = $read($hints + ['topFilters' => [['field' => 'member_name', 'operator' => 'eq', 'value' => 'M012 fixture 0']]]);
    $check($filtered['total'] === 1 && $filtered['quickCounts']['allPendingConfirmation'] === 23, 'explicit subsequent member search narrows list but not global shortcut count');
    $empty = $read($hints + ['topFilters' => [['field' => 'member_name', 'operator' => 'eq', 'value' => 'no-such-member']]]);
    $check($empty['total'] === 0 && $empty['records'] === [], 'empty result retains authoritative zero total');
    $check(Db::name('user_card_holder')->count() === $countsBefore[1], 'card rights are untouched');
} finally { Db::rollback(); }
$check([Db::name('cashier_v3_reservation')->count(), Db::name('user_card_holder')->count()] === $countsBefore, 'all local appointment fixtures rolled back');
echo "M012_RESERVATION_MYSQL=PASS\n";
