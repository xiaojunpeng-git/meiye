<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/后端代码/app/services/mobile/warehouse/MobileWarehouseHierarchyProjector.php';

use app\services\mobile\warehouse\MobileWarehouseHierarchyProjector;

function expect_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$organizations = [
    ['id' => 1, 'pid' => 0, 'name' => '集团'],
    ['id' => 2, 'pid' => 1, 'name' => '安徽区域'],
    ['id' => 3, 'pid' => 1, 'name' => '江苏区域'],
    ['id' => 4, 'pid' => 2, 'name' => '合肥区域'],
    ['id' => 5, 'pid' => 1, 'name' => '无门店培训中心'],
];
$stores = [
    ['id' => 101, 'name' => '安徽直属店'],
    ['id' => 102, 'name' => '合肥万达店'],
    ['id' => 103, 'name' => '南京店'],
    ['id' => 104, 'name' => '未授权店'],
];
$bindings = [
    ['org_id' => 2, 'store_id' => 101],
    ['org_id' => 4, 'store_id' => 102],
    ['org_id' => 3, 'store_id' => 103],
    ['org_id' => 5, 'store_id' => 104],
];

$projector = new MobileWarehouseHierarchyProjector();
$root = $projector->project($organizations, $stores, $bindings, [101, 102, 103]);
expect_true($root['currentNode']['name'] === '集团', 'root must be the common authorized group');
expect_true(count($root['rows']) === 2, 'root must only expose immediate child orgs with authorized stores');
expect_true(array_column($root['rows'], 'name') === ['安徽区域', '江苏区域'], 'empty and unauthorized organizations must be hidden');
expect_true(array_sum(array_column($root['rows'], 'storeCount')) === 3, 'root rows must not double count stores');

$anhui = $projector->project($organizations, $stores, $bindings, [101, 102, 103], 'organization', 2);
expect_true(array_column($anhui['rows'], 'name') === ['合肥区域', '安徽直属店'], 'current level must mix immediate child orgs and directly attached stores');
expect_true(array_column($anhui['rows'], 'entityType') === ['organization', 'store'], 'mixed rows must identify their entity type');
expect_true(array_sum(array_column($anhui['rows'], 'storeCount')) === 2, 'mixed rows must remain disjoint');

$store = $projector->project($organizations, $stores, $bindings, [101, 102, 103], 'store', 102);
expect_true($store['currentNode']['entityType'] === 'store' && $store['rows'] === [], 'store drill-down must terminate the organization list');
expect_true(array_column($store['breadcrumbs'], 'name') === ['集团', '安徽区域', '合肥区域', '合肥万达店'], 'store breadcrumb must retain the authorized organization path');

$denied = false;
try {
    $projector->project($organizations, $stores, $bindings, [101, 102, 103], 'organization', 5);
} catch (InvalidArgumentException $exception) {
    $denied = true;
}
expect_true($denied, 'an organization without an authorized store must be denied');

$singleStoreGroupScope = $projector->project($organizations, $stores, $bindings, [102], '', 0, 1);
expect_true($singleStoreGroupScope['currentNode']['name'] === '集团', 'an explicit group scope must remain the root even when only one store is attached below it');
expect_true(array_column($singleStoreGroupScope['rows'], 'name') === ['安徽区域'], 'a preferred group root must still return only its visible immediate children');

$missingBindingDenied = false;
try {
    $projector->project($organizations, array_merge($stores, [['id' => 105, 'name' => '未归属门店']]), $bindings, [101, 105]);
} catch (InvalidArgumentException $exception) {
    $missingBindingDenied = true;
}
expect_true($missingBindingDenied, 'every authorized active store must have an organization binding');

echo "mobile warehouse hierarchy contract: PASS\n";
