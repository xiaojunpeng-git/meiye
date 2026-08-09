<?php
declare(strict_types=1);

require __DIR__ . '/mysql-bootstrap.php';

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\service\CashierV3ServiceOrderAuthorityException;
use app\services\cashier\v3\service\CashierV3ServiceOrderOccupationAuthorityProvider;
use app\services\cashier\v3\service\ThinkPhpCashierV3ServiceOrderRepository;
use think\facade\Db;

c3MysqlBoot();
$passed = 0;
$failed = 0;
function c3MysqlOk(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) { $passed++; echo "PASS {$name}\n"; return; }
    $failed++; echo "FAIL {$name}" . ($detail === '' ? '' : ": {$detail}") . "\n";
}

foreach (['cashier_v3_service_order_operation','cashier_v3_service_order_line',
    'cashier_v3_service_order_entitlement_guard','cashier_v3_service_order'] as $table) {
    Db::name($table)->delete(true);
}

$ids = [];
$ids[11] = (int)Db::name('cashier_v3_service_order')->insertGetId(c3MysqlOrderRow('FW-C3-11', 7, 5011, 'IN_SERVICE', 3, [101]));
$ids[12] = (int)Db::name('cashier_v3_service_order')->insertGetId(c3MysqlOrderRow('FW-C3-12', 8, 5012, 'OPEN', 4, [202]));
$ids[13] = (int)Db::name('cashier_v3_service_order')->insertGetId(c3MysqlOrderRow('FW-C3-13', 7, 5013, 'COMPLETED', 5, [101]));
$touristId = (int)Db::name('cashier_v3_service_order')->insertGetId(c3MysqlOrderRow('FW-C3-TOURIST', 7, 0, 'OPEN', 1, []));
Db::name('cashier_v3_service_order_line')->insert(c3MysqlLineRow($ids[11], 'line-11-a', 501, 1));
Db::name('cashier_v3_service_order_line')->insert(c3MysqlLineRow($ids[11], 'line-11-b', 501, 2));
Db::name('cashier_v3_service_order_line')->insert(c3MysqlLineRow($ids[12], 'line-12-a', 501, 1));
Db::name('cashier_v3_service_order_line')->insert(c3MysqlLineRow($ids[12], 'line-12-released', 501, 0, 'RELEASED'));
Db::name('cashier_v3_service_order_line')->insert(c3MysqlLineRow($ids[13], 'line-13-a', 501, 4));

$repository = new ThinkPhpCashierV3ServiceOrderRepository();
$authority = new CashierV3ServiceOrderOccupationAuthorityProvider($repository);
$readiness = $authority->readinessStatus();
c3MysqlOk('production schema readiness is true', $readiness['ready'] === true, json_encode($readiness));
c3MysqlOk('tourist order is valid without entitlement lines', $touristId > 0);

$outsideRejected = false;
try {
    $authority->lockContributorsInTx(c3MysqlRequest('direct', 501), c3MysqlScope(CashierV3DataScopeContext::MODE_ALL));
} catch (CashierV3CommandException $exception) {
    $outsideRejected = $exception->getResultCode() === CashierV3ResultCode::COMMAND_TRANSACTION_REQUIRED;
}
c3MysqlOk('production authority rejects transactionless lock', $outsideRejected);

Db::startTrans();
try {
    $direct = $authority->lockContributorsInTx(
        c3MysqlRequest('direct', 501),
        c3MysqlScope(CashierV3DataScopeContext::MODE_ALL)
    );
    Db::commit();
} catch (Throwable $throwable) {
    Db::rollback();
    throw $throwable;
}
c3MysqlOk('direct aggregates active orders only', count($direct) === 2 && array_column($direct, 'id') === [$ids[11], $ids[12]]);
c3MysqlOk('same service order lines aggregate in MySQL', $direct[0]['occupiedTimes'] === 3 && $direct[0]['version'] === 3);
c3MysqlOk('cross store competitor remains globally occupied', $direct[1]['occupiedTimes'] === 1 && $direct[1]['convertibleTimes'] === 0);
c3MysqlOk('empty-range-safe guard persisted', Db::name('cashier_v3_service_order_entitlement_guard')->where('tenant_id', 'tenant-1')->where('entitlement_source_detail_id', 501)->count() === 1);

Db::startTrans();
try {
    $reservation = $authority->lockContributorsInTx(
        c3MysqlRequest('reservation', 501, 0, 71),
        c3MysqlScope(CashierV3DataScopeContext::MODE_STORES, [7])
    );
    $service = $authority->lockContributorsInTx(
        c3MysqlRequest('service_order', 501, $ids[11]),
        c3MysqlScope(CashierV3DataScopeContext::MODE_SELF_PARTICIPANT, [], 101)
    );
    Db::commit();
} catch (Throwable $throwable) {
    Db::rollback();
    throw $throwable;
}
c3MysqlOk('reservation includes service claims without converting them', count($reservation) === 2 && array_sum(array_column($reservation, 'convertibleTimes')) === 0);
c3MysqlOk('service order converts only current aggregated claim', $service[0]['id'] === $ids[11] && $service[0]['convertibleTimes'] === 3 && $service[1]['convertibleTimes'] === 0);

Db::startTrans();
try {
    $denied = false;
    try {
        $authority->lockContributorsInTx(
            c3MysqlRequest('service_order', 501, $ids[11]),
            c3MysqlScope(CashierV3DataScopeContext::MODE_SELF_PARTICIPANT, [], 999)
        );
    } catch (CashierV3ServiceOrderAuthorityException $exception) {
        $denied = $exception->reason() === 'service_order_authority_self_participant_denied';
    }
    $wrongStore = false;
    try {
        $authority->lockContributorsInTx(
            c3MysqlRequest('service_order', 501, $ids[12]),
            c3MysqlScope(CashierV3DataScopeContext::MODE_ALL)
        );
    } catch (CashierV3ServiceOrderAuthorityException $exception) {
        $wrongStore = $exception->reason() === 'current_service_order_store_mismatch';
    }
    Db::commit();
} catch (Throwable $throwable) {
    Db::rollback();
    throw $throwable;
}
c3MysqlOk('self participant is checked only against current order snapshot', $denied);
c3MysqlOk('current service order must match forced operation store', $wrongStore);
c3MysqlOk('authority is read only except concurrency guard', Db::name('cashier_v3_service_order')->count() === 4 && Db::name('cashier_v3_service_order_line')->count() === 5 && Db::name('cashier_v3_service_order_operation')->count() === 0);

echo "C3_MYSQL_INTEGRATION passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
