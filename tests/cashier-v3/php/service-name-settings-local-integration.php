<?php

declare(strict_types=1);

use app\services\store\ServiceNameSettingsServices;
use think\facade\Db;

$backend = getenv('MOHE_BACKEND_ROOT') ?: '/var/www/html';
require rtrim($backend, '/\\') . '/vendor/autoload.php';
(new think\App(rtrim($backend, '/\\') . '/'))->initialize();

$service = new ServiceNameSettingsServices();
$nonce = substr(bin2hex(random_bytes(12)), 0, 20);
$storeA = 990000001;
$storeB = 990000002;
$name = '服务名称集成测试-' . $nonce;
$updatedName = $name . '-已编辑';
$secondName = $name . '-第二项';
$operator = ['id' => 900001, 'name' => 'Codex本地验证'];
$createdIds = [];

$expectFailure = static function (callable $operation, string $expectedMessage): void {
    try {
        $operation();
    } catch (Throwable $exception) {
        if (strpos($exception->getMessage(), $expectedMessage) === false) {
            throw new RuntimeException('unexpected failure: ' . $exception->getMessage(), 0, $exception);
        }
        return;
    }
    throw new RuntimeException('expected failure was not raised: ' . $expectedMessage);
};

try {
    $defaultSwitchA = $service->page($storeA, ['page' => 1, 'limit' => 20])['switch'];
    $defaultSwitchB = $service->page($storeB, ['page' => 1, 'limit' => 20])['switch'];
    if ($defaultSwitchA !== ['enabled' => true, 'version' => 0]
        || $defaultSwitchB !== ['enabled' => true, 'version' => 0]) {
        throw new RuntimeException('default switch state mismatch');
    }

    $createToken = 'service-name-create-' . $nonce;
    $created = $service->create($storeA, $operator, $name, 0, $createToken);
    $createdIds[] = (int)$created['id'];
    if ((int)$created['version'] !== 1 || $created['name'] !== $name) {
        throw new RuntimeException('create result mismatch');
    }

    // 同一请求重复到达必须返回原结果，不能新增第二条记录或第二条审计。
    $replayed = $service->create($storeA, $operator, $name, 0, $createToken);
    if ((int)$replayed['id'] !== (int)$created['id'] || (int)$replayed['version'] !== 1) {
        throw new RuntimeException('idempotent replay mismatch');
    }

    $updated = $service->update(
        $storeA,
        $operator,
        (int)$created['id'],
        $updatedName,
        1,
        'service-name-update-' . $nonce
    );
    if ((int)$updated['version'] !== 2 || $updated['name'] !== $updatedName) {
        throw new RuntimeException('update result mismatch');
    }

    $expectFailure(static function () use ($service, $storeA, $operator, $created, $nonce): void {
        $service->update(
            $storeA,
            $operator,
            (int)$created['id'],
            '过期版本覆盖-' . $nonce,
            1,
            'service-name-stale-' . $nonce
        );
    }, '已被其他人修改');

    $second = $service->create(
        $storeA,
        $operator,
        $secondName,
        0,
        'service-name-second-' . $nonce
    );
    $createdIds[] = (int)$second['id'];
    $sortResult = $service->reorder($storeA, $operator, [
        ['id' => (int)$second['id'], 'expected_version' => (int)$second['version']],
        ['id' => (int)$updated['id'], 'expected_version' => (int)$updated['version']],
    ], 'service-name-sort-' . $nonce);
    if ((int)$sortResult['saved'] !== 2) {
        throw new RuntimeException('sort result mismatch');
    }
    $sorted = $service->page($storeA, ['page' => 1, 'limit' => 20]);
    if ((int)$sorted['records'][0]['id'] !== (int)$second['id'] || (int)$sorted['records'][1]['id'] !== (int)$updated['id']) {
        throw new RuntimeException('sort order was not persisted');
    }
    $disabled = $service->setEnabled(
        $storeA,
        $operator,
        (int)$updated['id'],
        false,
        (int)$sorted['records'][1]['version'],
        'service-name-status-' . $nonce
    );
    if ($disabled['enabled'] !== false) {
        throw new RuntimeException('disabled status mismatch');
    }

    $listA = $service->page($storeA, ['keyword' => $updatedName, 'status' => '0', 'page' => 1, 'limit' => 20]);
    $listB = $service->page($storeB, ['keyword' => $updatedName, 'page' => 1, 'limit' => 20]);
    if ((int)$listA['total'] !== 1 || (int)$listB['total'] !== 0) {
        throw new RuntimeException('store data scope mismatch');
    }

    // 总开关只保存当前门店状态；幂等、乐观版本与门店隔离和名称写入采用同一边界。
    $switchToken = 'service-switch-disable-' . $nonce;
    $switchOff = $service->setSwitch($storeA, $operator, false, 0, $switchToken);
    if ($switchOff !== ['enabled' => false, 'version' => 1]) {
        throw new RuntimeException('switch disable result mismatch');
    }
    $switchReplay = $service->setSwitch($storeA, $operator, false, 0, $switchToken);
    if ($switchReplay !== $switchOff) {
        throw new RuntimeException('switch idempotent replay mismatch');
    }
    $expectFailure(static function () use ($service, $storeA, $operator, $nonce): void {
        $service->setSwitch($storeA, $operator, true, 0, 'service-switch-stale-' . $nonce);
    }, '已被其他人修改');
    $switchOn = $service->setSwitch(
        $storeA,
        $operator,
        true,
        1,
        'service-switch-enable-' . $nonce
    );
    if ($switchOn !== ['enabled' => true, 'version' => 2]) {
        throw new RuntimeException('switch enable result mismatch');
    }
    $lateReplay = $service->setSwitch($storeA, $operator, false, 0, $switchToken);
    if ($lateReplay !== $switchOff) {
        throw new RuntimeException('late switch replay must return its original snapshot');
    }
    $otherStoreSwitch = $service->page($storeB, ['page' => 1, 'limit' => 20])['switch'];
    if ($otherStoreSwitch !== ['enabled' => true, 'version' => 0]) {
        throw new RuntimeException('switch store scope mismatch');
    }

    // 门店内唯一、门店间独立：另一个门店可保存同名资料。
    $otherStore = $service->create(
        $storeB,
        $operator,
        $updatedName,
        0,
        'service-name-other-' . $nonce
    );
    $createdIds[] = (int)$otherStore['id'];
    $expectFailure(static function () use ($service, $storeA, $operator, $updatedName, $nonce): void {
        $service->create(
            $storeA,
            $operator,
            $updatedName,
            0,
            'service-name-duplicate-' . $nonce
        );
    }, '已存在相同的服务名称');

    $auditCount = (int)Db::name('cashier_v3_service_name_audit')
        ->whereIn('store_id', [$storeA, $storeB])
        ->where('operator_id', (int)$operator['id'])
        ->whereLike('request_token', '%' . $nonce . '%')
        ->count();
    if ($auditCount !== 8) {
        throw new RuntimeException('audit count mismatch: ' . $auditCount);
    }

    echo "SERVICE_NAME_SETTINGS_LOCAL_INTEGRATION_OK\n";
} finally {
    Db::name('cashier_v3_service_name_audit')
        ->whereIn('store_id', [$storeA, $storeB])
        ->where('operator_id', (int)$operator['id'])
        ->whereLike('request_token', '%' . $nonce . '%')
        ->delete();
    Db::name('cashier_v3_service_name_setting')->whereIn('store_id', [$storeA, $storeB])->delete();
    if ($createdIds) {
        Db::name('cashier_v3_service_name')->whereIn('id', $createdIds)->delete();
    }
}
