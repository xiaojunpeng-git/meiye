<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/后端代码/app/services/mobile/merchant/MobileMerchantAnalyticsEntryPolicy.php';
require_once dirname(__DIR__, 3) . '/后端代码/app/services/mobile/warehouse/MobileWarehouseHierarchyProjector.php';
require_once dirname(__DIR__, 3) . '/后端代码/app/services/mobile/warehouse/MobileWarehouseServices.php';

use app\services\mobile\merchant\MobileMerchantAnalyticsEntryPolicy;
use app\services\mobile\warehouse\MobileWarehouseHierarchyProjector;
use app\services\mobile\warehouse\MobileWarehouseServices;

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$organizations = [
    ['id' => 1, 'pid' => 0, 'name' => '集团'],
    ['id' => 2, 'pid' => 1, 'name' => '东区'],
    ['id' => 3, 'pid' => 1, 'name' => '西区'],
    ['id' => 4, 'pid' => 1, 'name' => '未授权区'],
];
$stores = [
    ['id' => 101, 'name' => '东区授权店'],
    ['id' => 102, 'name' => '西区授权店'],
    ['id' => 103, 'name' => '未授权店'],
    ['id' => 104, 'name' => '仅未授权区门店'],
];
$bindings = [
    ['org_id' => 2, 'store_id' => 101],
    ['org_id' => 3, 'store_id' => 102],
    ['org_id' => 3, 'store_id' => 103],
    ['org_id' => 4, 'store_id' => 104],
];
$policy = new MobileMerchantAnalyticsEntryPolicy();
$org = $policy->resolve(
    [['id' => 1, 'scope_mode' => 'org', 'org_ids' => '[1]', 'source_type' => 'hq', 'source_store_id' => 0]],
    [101, 102], 101, 7, $organizations, $bindings
);
check($org['entryType'] === 'organization' && $org['showOrganizationRanking'] && $org['showStoreRanking'], 'organization permission must expose both rankings');

$selected = $policy->resolve(
    [['id' => 2, 'scope_mode' => 'store_selected', 'source_type' => 'hq', 'source_store_id' => 0]],
    [101, 102], 101, 7, $organizations, $bindings
);
check($selected['entryType'] === 'store' && $selected['showOrganizationRanking'] && $selected['showStoreRanking'], 'selected-store permission must expose both rankings');
check($selected['authorizedStoreIds'] === [101, 102], 'selected-store rankings must retain all authorized stores, not only the active store');

// 总部指定门店可从初始单店排行下钻，普通本店权限仍锁定在当前门店。
$requestedNode = new ReflectionMethod(MobileWarehouseServices::class, 'requestedWarehouseNode');
check($requestedNode->invoke(null, $selected, []) === ['store', 101, true], 'selected-store entry must start at the active store and rank all grants');
check($requestedNode->invoke(null, $selected, ['nodeType' => 'organization', 'nodeId' => 2]) === ['organization', 2, false], 'selected-store branch drill must reach its organization node');
check($requestedNode->invoke(null, $selected, ['nodeType' => 'store', 'nodeId' => 102]) === ['store', 102, false], 'selected-store drill may reach another authorized store');

// 指定门店的组织排行以授权门店集合另建投影，不沿用单店工作台的空子节点。
$projector = new MobileWarehouseHierarchyProjector();
$activeStore = $projector->project($organizations, $stores, $bindings, [101, 102], 'store', 101);
$rankingRoot = $projector->project($organizations, $stores, $bindings, [101, 102]);
check($activeStore['rows'] === [] && count($rankingRoot['rows']) === 2, 'organization ranking must aggregate both selected stores');
check(array_sum(array_column($rankingRoot['rows'], 'storeCount')) === 2, 'organization ranking must exclude the unauthorized store');
$westBranch = $projector->project($organizations, $stores, $bindings, [101, 102], 'organization', 3);
check(count($westBranch['rows']) === 1 && (int)$westBranch['rows'][0]['entityId'] === 102, 'branch drill must keep only its authorized store');
try {
    $projector->project($organizations, $stores, $bindings, [101, 102], 'store', 103);
    throw new RuntimeException('unauthorized store drill must fail');
} catch (InvalidArgumentException $exception) {
    check(strpos($exception->getMessage(), '权限') !== false, 'unauthorized store drill must be rejected by server scope');
}
try {
    $projector->project($organizations, $stores, $bindings, [101, 102], 'organization', 4);
    throw new RuntimeException('unauthorized organization drill must fail');
} catch (InvalidArgumentException $exception) {
    check(strpos($exception->getMessage(), '权限') !== false, 'unauthorized organization drill must be rejected by server scope');
}

$self = $policy->resolve(
    [['id' => 3, 'scope_mode' => 'store_self', 'source_type' => 'store', 'source_store_id' => 101]],
    [101], 101, 7, $organizations, $bindings
);
$personal = $policy->resolve(
    [['id' => 4, 'scope_mode' => 'personal', 'source_type' => 'hq', 'source_store_id' => 0]],
    [101], 101, 7, $organizations, $bindings
);
check(!$self['showOrganizationRanking'] && !$self['showStoreRanking'], 'self-store permission must hide both rankings');
check(!$personal['showOrganizationRanking'] && !$personal['showStoreRanking'], 'personal permission must hide both rankings');
check($requestedNode->invoke(null, $self, ['nodeType' => 'organization', 'nodeId' => 2]) === ['store', 101, false], 'self-store entry must reject organization navigation');

// 总部个人设置高于历史本店来源；个人入口只能指向本人，不能展示组织/门店排行。
$personalWithLegacyStore = $policy->resolve([
    ['id' => 5, 'scope_mode' => 'personal', 'source_type' => 'hq', 'source_store_id' => 0],
    ['id' => 6, 'scope_mode' => 'store_self', 'source_type' => 'store', 'source_store_id' => 101],
], [101], 101, 7, $organizations, $bindings);
check($personalWithLegacyStore['entryType'] === 'personal' && $personalWithLegacyStore['employeeId'] === 7, 'hq personal must take precedence over store-source grant');
check(!$personalWithLegacyStore['showOrganizationRanking'] && !$personalWithLegacyStore['showStoreRanking'], 'hq personal must expose no cross-employee ranking');

// 23 家门店应按服务端稳定顺序分为 10、10、3 家，越界页仍返回完整命中总数。
$ranked = array_map(static fn(int $id): array => ['entityId' => $id], range(1, 23));
$pager = new ReflectionMethod(MobileWarehouseServices::class, 'paginateRankingRows');
$first = $pager->invoke(null, $ranked, 1);
$second = $pager->invoke(null, $ranked, 2);
$last = $pager->invoke(null, $ranked, 3);
$outOfRange = $pager->invoke(null, $ranked, 4);
check(array_column($first['rankingRows'], 'entityId') === range(1, 10), 'first page must contain exactly the first ten stores');
check(array_column($second['rankingRows'], 'entityId') === range(11, 20), 'second page must continue without overlap');
check(array_column($last['rankingRows'], 'entityId') === range(21, 23), 'last page must retain the remainder');
check($first['rankingTotal'] === 23 && $second['rankingHasMore'] && !$last['rankingHasMore'], 'all pages must report the same total and correct continuation state');
check($outOfRange['rankingRows'] === [] && $outOfRange['rankingTotal'] === 23, 'out-of-range page must stay empty without changing total');

echo "mobile warehouse ranking scope contract: PASS\n";
