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
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
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
echo json_encode(['ok' => true, 'visible_store_ids' => [11], 'cross_brand_denied' => true,
    'wrong_member_denied' => true, 'closed_store_denied' => true,
    'disabled_organization_denied' => true, 'booking_write_rolled_back' => true,
    'replay_idempotent' => true, 'double_booking_denied' => true]) . PHP_EOL;
