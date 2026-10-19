<?php
declare(strict_types=1);

/** Historical reservation scope checks against the disposable 008 rights fixture. */
error_reporting(E_ALL & ~E_DEPRECATED);
if (!function_exists('swoole_cpu_num')) { function swoole_cpu_num(): int { return 1; } }
$backend = getenv('M008_RIGHTS_TEST_BACKEND') ?: dirname(__DIR__, 3) . '/后端代码';
$testHost = getenv('M008_RIGHTS_TEST_HOST') ?: '127.0.0.1';
$testPort = getenv('M008_RIGHTS_TEST_PORT') ?: '13318';
if (!in_array($testHost, ['127.0.0.1', 'm008-rights-isolate'], true)
    || !in_array($testPort, ['13318', '3306'], true)) {
    throw new RuntimeException('Disposable database endpoint guard changed');
}
require $backend . '/vendor/autoload.php';
if (!class_exists('Swoole\\Table')) {
    eval('namespace Swoole; final class Table { public const TYPE_STRING = 7; public const TYPE_INT = 1; }');
}

use app\services\cashier\v3\reservation\ImportedEntitlementReservationStoreServices;
use app\services\cashier\v3\reservation\MemberV3ReservationServices;
use app\services\order\StoreReservationOrderServices;
use think\exception\ValidateException;
use think\facade\Db;

$app = new \think\App($backend . '/');
$app->env->load($backend . '/.env');
foreach ([
    'database.hostname' => $testHost, 'DATABASE_HOSTNAME' => $testHost,
    'database.hostport' => $testPort, 'DATABASE_HOSTPORT' => $testPort,
    'database.database' => 'mohe_008', 'DATABASE_DATABASE' => 'mohe_008',
    'database.username' => 'root', 'DATABASE_USERNAME' => 'root',
    'database.password' => 'rights-test-local', 'DATABASE_PASSWORD' => 'rights-test-local',
] as $key => $value) $app->env->set($key, $value);
$envName = new ReflectionProperty($app, 'envName');
$envName->setAccessible(true);
$envName->setValue($app, 'historical_reservation_isolated_skip_dotenv_reload');
$app->initialize();
set_exception_handler(static function (Throwable $error): void {
    file_put_contents('php://stderr', $error->getMessage() . PHP_EOL . $error->getTraceAsString() . PHP_EOL);
    exit(1);
});
Db::connect('mysql', true)->query('SELECT 1');

$service = new ImportedEntitlementReservationStoreServices();
$orderInfo = $app->make(StoreReservationOrderServices::class)->getOrderInfo(421, 5338, 13967);
if (empty($orderInfo['historical_store_picker'])
    || array_map('intval', array_column($orderInfo['reservation_store_options'], 'id')) !== [11]) {
    throw new RuntimeException('Member reservation response omitted historical store choices');
}
$options = $service->availableStores(421, 5338, 13967);
if (array_map('intval', array_column($options, 'id')) !== [11]) {
    throw new RuntimeException('Historical right did not resolve only the operating Meiyue store');
}
$service->assertSelectedStore(421, 5338, 13967, 11);
$booking = $app->make(MemberV3ReservationServices::class);
try {
    $booking->create(421, 5338, [
        'cart_num' => 1, 'cart_info_id' => 13967, 'store_id' => 22,
        'reservation_time' => '2026-10-16', 'reservation_start' => '10:00',
    ]);
    throw new RuntimeException('Booking API accepted a Geni store for a Meiyue right');
} catch (ValidateException $expected) {
}
// A successful V3 booking is exercised inside an outer rollback. This proves
// the migrated carrier reaches the authoritative reservation write path while
// keeping the disposable member's right unchanged for repeatable runs.
Db::startTrans();
try {
    $bookingInput = [
        'cart_num' => 1, 'cart_info_id' => 13967, 'store_id' => 11,
        'reservation_time' => '2026-10-16', 'reservation_start' => '10:00',
        'reservation_end' => '11:00',
    ];
    $created = $booking->create(421, 5338, $bookingInput);
    if ((int)($created['reservationId'] ?? 0) <= 0) {
        throw new RuntimeException('Historical right did not create a V3 reservation');
    }
    $replay = $booking->create(421, 5338, $bookingInput);
    if ((int)($replay['reservationId'] ?? 0) !== (int)$created['reservationId']) {
        throw new RuntimeException('Repeated historical booking was not idempotent');
    }
    if ((int)Db::name('cashier_v3_reservation')->where('id', (int)$created['reservationId'])->count() !== 1) {
        throw new RuntimeException('Authoritative V3 reservation write was missing');
    }
    try {
        $booking->create(421, 5338, array_merge($bookingInput, [
            'reservation_start' => '12:00', 'reservation_end' => '13:00',
        ]));
        throw new RuntimeException('One remaining visit was booked twice');
    } catch (ValidateException $expected) {
    }
} finally {
    Db::rollback();
}
if ((int)Db::name('store_order_cart_info')->where('id', 13967)->value('write_surplus_times') !== 1) {
    throw new RuntimeException('Booking rehearsal changed the remaining right');
}
foreach ([[421, 5338, 13967, 22], [420, 5338, 13967, 11], [421, 5338, 13966, 11]] as $case) {
    try {
        $service->assertSelectedStore(...$case);
        throw new RuntimeException('Unauthorized historical reservation store was accepted');
    } catch (ValidateException $expected) {
    }
}
Db::startTrans();
try {
    Db::name('system_store')->where('id', 11)->update(['business_status' => 0]);
    if ($service->availableStores(421, 5338, 13967) !== []) {
        throw new RuntimeException('Closed store remained in reservation picker');
    }
    try {
        $service->assertSelectedStore(421, 5338, 13967, 11);
        throw new RuntimeException('Closed store was accepted for reservation');
    } catch (ValidateException $expected) {
    }
} finally {
    Db::rollback();
}
Db::startTrans();
try {
    Db::name('organization')->where('id', 3)->update(['status' => 0]);
    if ($service->availableStores(421, 5338, 13967) !== []) {
        throw new RuntimeException('Disabled owner organization retained reservation stores');
    }
} finally {
    Db::rollback();
}
// Multi-project fixtures are derived only inside the disposable transaction.
// No customer right is consumed: creating reservations merely occupies visits.
Db::startTrans();
try {
    $baseDetail = Db::name('store_order_cart_info')->where('id', 13967)->find();
    $newDetail = static function (array $changes = []) use ($baseDetail): int {
        $row = array_merge($baseDetail, ['write_surplus_times' => 2, 'write_start' => 0, 'write_end' => 0], $changes);
        unset($row['id']);
        $row['unique'] = bin2hex(random_bytes(16));
        return (int)Db::name('store_order_cart_info')->insertGetId($row);
    };
    $addonId = $newDetail(['write_surplus_times' => 1]);
    $mainId = $newDetail();
    $expectDenied = static function (callable $operation, string $reason): void {
        try {
            $operation();
        } catch (ValidateException $expected) {
            return;
        }
        throw new RuntimeException('Missing rejection: ' . $reason);
    };
    $options = $service->addonOptions(421, 5338, $mainId, 11);
    $optionIds = [];
    foreach ($options['list'] as $group) $optionIds = array_merge($optionIds, array_column($group['cart_info'], 'id'));
    if (!in_array($addonId, array_map('intval', $optionIds), true)
        || in_array($mainId, array_map('intval', $optionIds), true)) {
        throw new RuntimeException('Historical addon picker omitted the available right or included the main right');
    }
    $expectDenied(static function () use ($service, $mainId): void {
        $service->addonOptions(420, 5338, $mainId, 11);
    }, 'foreign member main context');
    $expectDenied(static function () use ($service, $mainId): void {
        $service->addonOptions(421, 5338, $mainId, 22);
    }, 'foreign organization store');
    $expiredId = $newDetail(['write_end' => time() - 86400]);
    $depletedId = $newDetail(['write_surplus_times' => 0]);
    $input = [
        'cart_num' => 1, 'cart_info_id' => $mainId, 'store_id' => 11,
        'reservation_time' => date('Y-m-d', time() + 2 * 86400),
        'reservation_start' => '10:00', 'reservation_end' => '10:01',
        'service_duration_minutes' => 1,
        'addon_items' => [['cart_info_id' => $addonId, 'addon_service_duration' => 1]],
    ];
    foreach ([$expiredId, $depletedId, $mainId] as $badId) {
        $expectDenied(static function () use ($booking, $input, $badId): void {
            $booking->create(421, 5338, array_merge($input, [
                'addon_items' => [['cart_info_id' => $badId]],
            ]));
        }, 'expired, depleted or duplicate main addon');
    }
    $beforeCount = (int)Db::name('cashier_v3_reservation')->count();
    $created = $booking->create(421, 5338, $input);
    $header = Db::name('cashier_v3_reservation')->where('id', (int)$created['reservationId'])->find();
    $lines = Db::name('cashier_v3_reservation_line')->where('reservation_id', (int)$created['reservationId'])
        ->order('id asc')->select()->toArray();
    $mainRight = $service->reservationDetail(421, $mainId, 11);
    $addonRight = $service->reservationDetail(421, $addonId, 11);
    $expectedMinutes = (int)$mainRight['_project_duration'] + (int)$addonRight['_duration'];
    if (count($lines) !== 2 || (string)$lines[1]['role_code'] !== 'ADDON'
        || (int)$lines[1]['entitlement_source_detail_id'] !== $addonId
        || ((int)$header['appointment_end_at'] - (int)$header['appointment_start_at']) !== $expectedMinutes * 60
        || array_sum(array_column($lines, 'service_duration_minutes')) !== $expectedMinutes) {
        throw new RuntimeException('Historical multi-project lines or authoritative duration differ');
    }
    $replayed = $booking->create(421, 5338, $input);
    if ($replayed['reservationId'] !== $created['reservationId']
        || (int)Db::name('cashier_v3_reservation')->count() !== $beforeCount + 1) {
        throw new RuntimeException('Historical multi-project replay duplicated a booking');
    }
    $expectDenied(static function () use ($booking, $input): void {
        $booking->create(421, 5338, array_merge($input, ['reservation_start' => '14:00']));
    }, 'addon already occupied by pending appointment');
    if ((int)Db::name('cashier_v3_reservation')->count() !== $beforeCount + 1
        || (int)Db::name('store_order_cart_info')->where('id', $addonId)->value('write_surplus_times') !== 1) {
        throw new RuntimeException('Failed addon attempt left a partial booking or deducted a visit');
    }
    // A second source carrier belongs to an unrelated import scope, not to
    // the selected store; knowing its detail ID never authorizes the addon.
    $otherOrder = Db::name('store_order')->where('id', 5338)->find();
    unset($otherOrder['id']);
    $otherOrder['unique'] = bin2hex(random_bytes(16));
    $otherOrder['order_id'] = 'LOCAL-ADDON-' . bin2hex(random_bytes(5));
    $otherOid = (int)Db::name('store_order')->insertGetId($otherOrder);
    $holder = Db::name('user_card_holder')->where('oid', 5338)->find();
    $oldHolderId = (int)$holder['id'];
    unset($holder['id']);
    $holder['oid'] = $otherOid;
    $holder['card_no'] = null;
    $holderId = (int)Db::name('user_card_holder')->insertGetId($holder);
    $scope = Db::name('migration_entitlement_scope')->where('card_holder_id', $oldHolderId)->find();
    unset($scope['id']);
    $scope['card_holder_id'] = $holderId;
    $scope['owner_org_id'] = 2;
    $scope['source_system'] = 'local-addon-test';
    $scope['source_detail_id'] = $holderId;
    Db::name('migration_entitlement_scope')->insert($scope);
    $foreignScopeId = $newDetail(['oid' => $otherOid]);
    $expectDenied(static function () use ($booking, $input, $foreignScopeId): void {
        $booking->create(421, 5338, array_merge($input, [
            'addon_items' => [['cart_info_id' => $foreignScopeId]],
        ]));
    }, 'addon scope must independently allow the chosen store');
    Db::name('store_order')->where('id', $otherOid)->update(['uid' => 420]);
    $expectDenied(static function () use ($booking, $input, $foreignScopeId): void {
        $booking->create(421, 5338, array_merge($input, [
            'addon_items' => [['cart_info_id' => $foreignScopeId]],
        ]));
    }, 'foreign member addon');
    // An ordinary owned right at this store remains a valid add-on to a
    // historical main project; hidden carriers alone require import policy.
    Db::name('store_order')->where('id', $otherOid)->update([
        'uid' => 421, 'is_system_del' => 0, 'store_id' => 11,
    ]);
    $mixed = $booking->create(421, 5338, array_merge($input, [
        'reservation_start' => '15:00',
        'addon_items' => [['cart_info_id' => $foreignScopeId]],
    ]));
    if ((int)Db::name('cashier_v3_reservation_line')->where('reservation_id', (int)$mixed['reservationId'])->count() !== 2) {
        throw new RuntimeException('Owned ordinary add-on was not retained in historical booking');
    }
} finally {
    Db::rollback();
}
echo json_encode(['ok' => true, 'visible_store_ids' => [11], 'cross_brand_denied' => true,
    'wrong_member_denied' => true, 'closed_store_denied' => true,
    'disabled_organization_denied' => true, 'booking_write_rolled_back' => true,
    'replay_idempotent' => true, 'double_booking_denied' => true,
    'historical_multi_project_rolled_back' => true, 'addon_scope_denied' => true,
    'addon_quota_atomic' => true, 'server_duration_authoritative' => true]) . PHP_EOL;
