<?php
declare(strict_types=1);

/** Local-only rollback probe for selected-store presale access and typed operator identity. */
require '/var/www/html/vendor/autoload.php';

use app\services\cashier\v3\presale\CashierV3PresaleClaimServices;
use think\facade\Db;

$app = new think\App('/var/www/html/');
$app->initialize();
if ((string)config('database.connections.mysql.hostname') !== 'mysql'
    || (string)config('database.connections.mysql.database') !== 'ruihao') {
    throw new RuntimeException('Local ruihao DB only.');
}

$storeId = 126;
$employeeId = 490;
$staff = Db::name('system_store_staff')->where('store_id', $storeId)->where('status', 1)
    ->where('is_del', 0)->field('id')->find();
if (!$staff || !Db::name('employee')->where('id', $employeeId)->where('status', 1)
    ->where('is_del', 0)->find()) {
    throw new RuntimeException('Local selected-store fixtures unavailable.');
}
$service = new CashierV3PresaleClaimServices();
$criteria = ['source_kind' => 'PRESALE', 'status' => 'AVAILABLE', 'page' => 1, 'limit' => 20];
$direct = $service->listForStore($storeId, (int)$staff['id'], $criteria);
if ((int)$direct['count'] < 1) throw new RuntimeException('Direct-store fixture has no claimable lines.');

Db::startTrans();
try {
    $now = time();
    $sessionId = (int)Db::name('cashier_v3_store_session')->insertGetId([
        'employee_id' => $employeeId, 'store_id' => $storeId,
        'token_secret_hash' => md5('local-presale-scope-probe'), 'auth_version' => 1,
        'expire_time' => $now + 3600, 'status' => 1, 'is_del' => 0,
        'source' => 'data_scope', 'account_snapshot' => 'scope-probe',
        'staff_name_snapshot' => 'scope-probe', 'add_time' => $now, 'update_time' => $now,
    ]);
    $profile = [
        'id' => $sessionId, 'employee_id' => $employeeId, 'store_id' => $storeId,
        'type' => 'cashier_v3_organization', '_cashier_v3_organization' => 1,
        'session_mode' => 'store_organization', 'read_only' => false,
    ];
    $result = $service->listForStore($storeId, $sessionId, $criteria, $profile);
    if ((int)$result['count'] !== (int)$direct['count'] || count($result['list']) !== count($direct['list'])
        || array_filter($result['list'], static fn(array $row): bool => (int)$row['store_id'] !== $storeId)) {
        throw new RuntimeException('Selected-store list differs from authorized store data.');
    }
    $claimableId = (string)$result['list'][0]['claimable_line_id'];
    $detail = $service->detailForStore($storeId, $sessionId, $claimableId, $profile);
    if ((string)$detail['claimable']['claimable_line_id'] !== $claimableId) {
        throw new RuntimeException('Selected-store detail crossed the store boundary.');
    }
    $giftCriteria = array_merge($criteria, ['source_kind' => 'GIFT']);
    $directGift = $service->listForStore($storeId, (int)$staff['id'], $giftCriteria);
    $selectedGift = $service->listForStore($storeId, $sessionId, $giftCriteria, $profile);
    if ((int)$selectedGift['count'] !== (int)$directGift['count']) {
        throw new RuntimeException('Selected-store gift list differs from authorized store data.');
    }

    // A session ID is not a store-staff ID; write audit must use the employee's stable identity.
    $scope = new ReflectionMethod(CashierV3PresaleClaimServices::class, 'storeScope');
    $scope->setAccessible(true);
    foreach (['cashier.v3.inventory.presale_claim.create', 'cashier.v3.inventory.presale_claim.void'] as $feature) {
        $actor = $scope->invoke($service, $storeId, $sessionId, true, $profile, $feature);
        if ($actor['operatorType'] !== 'EMPLOYEE' || (int)$actor['operatorId'] !== $employeeId) {
            throw new RuntimeException('Organization write audit used the transient session ID.');
        }
    }
    $readOnly = $profile;
    $readOnly['type'] = 'cashier_v3_delegated';
    $readOnly['_cashier_v3_delegated'] = 1;
    unset($readOnly['_cashier_v3_organization']);
    $readOnly['session_mode'] = 'store_read_only';
    $readOnly['read_only'] = true;
    if ((int)$service->listForStore($storeId, $sessionId, $criteria, $readOnly)['count'] !== (int)$direct['count']) {
        throw new RuntimeException('Read-only selected-store list was denied.');
    }
    foreach ([
        static fn() => $service->listForStore(125, $sessionId, $criteria, $profile),
        static fn() => $service->detailForStore(125, $sessionId, $claimableId, $profile),
        static fn() => $service->listForStore($storeId, $sessionId + 1, $criteria, $profile),
        static fn() => $scope->invoke($service, $storeId, $sessionId, true, $readOnly,
            'cashier.v3.inventory.presale_claim.create'),
    ] as $probe) {
        try {
            $probe();
            throw new RuntimeException('Invalid selected-store access was allowed.');
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() !== 'presale_claim_scope_denied') throw $exception;
        }
    }
    Db::name('cashier_v3_store_session')->where('id', $sessionId)->update(['expire_time' => $now - 1]);
    try {
        $service->listForStore($storeId, $sessionId, $criteria, $profile);
        throw new RuntimeException('Expired selected-store session was allowed.');
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() !== 'presale_claim_scope_denied') throw $exception;
    }
    echo "Selected-store presale list, detail, actor, read-only and denial checks passed\n";
} finally {
    Db::rollback();
}
