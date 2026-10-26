<?php
declare(strict_types=1);

require __DIR__ . '/mysql-bootstrap.php';

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\service\CashierV3ServiceOrderOccupationAuthorityProvider;
use app\services\cashier\v3\service\ThinkPhpCashierV3ServiceOrderRepository;
use think\facade\Db;

c3MysqlBoot();
$mode = $argv[1] ?? '';
$repository = new ThinkPhpCashierV3ServiceOrderRepository();

if ($mode === 'scan') {
    Db::startTrans();
    try {
        $authority = new CashierV3ServiceOrderOccupationAuthorityProvider($repository);
        $contributors = $authority->lockContributorsInTx(
            c3MysqlRequest('direct', 999),
            c3MysqlScope(CashierV3DataScopeContext::MODE_ALL)
        );
        if ($contributors !== []) {
            throw new RuntimeException('C3_EMPTY_SCAN_NOT_EMPTY');
        }
        echo "C3_SCAN_GUARD_LOCKED\n";
        fflush(STDOUT);
        sleep(5);
        Db::commit();
        echo "C3_SCAN_COMMITTED\n";
    } catch (Throwable $throwable) {
        Db::rollback();
        throw $throwable;
    }
    exit(0);
}

if ($mode === 'writer') {
    $started = microtime(true);
    Db::startTrans();
    try {
        $guard = $repository->lockOrCreateEntitlementGuard('tenant-1', 999);
        $repository->lockOccupationSet('tenant-1', 999);
        $order = $repository->insertServiceOrder(c3MysqlOrderRow('FW-C3-CONCURRENT', 7, 5999, 'OPEN', 1, [101]));
        $repository->insertLine(c3MysqlLineRow((int)$order['id'], 'concurrent-line', 999, 1));
        $repository->bumpEntitlementGuardCas(
            'tenant-1', 999, (int)$guard['current_version'], 'occupation_inserted', 1785287000
        );
        Db::commit();
    } catch (Throwable $throwable) {
        Db::rollback();
        throw $throwable;
    }
    $elapsed = microtime(true) - $started;
    echo 'C3_WRITER_ELAPSED=' . number_format($elapsed, 3, '.', '') . "\n";
    exit($elapsed >= 3.0 ? 0 : 2);
}

throw new InvalidArgumentException('C3_WORKER_MODE_INVALID');
