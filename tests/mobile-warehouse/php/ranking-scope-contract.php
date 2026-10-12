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
];
$stores = [
    ['id' => 101, 'name' => '东区授权店'],
    ['id' => 102, 'name' => '西区授权店'],
    ['id' => 103, 'name' => '未授权店'],
];
$bindings = [
    ['org_id' => 2, 'store_id' => 101],
    ['org_id' => 3, 'store_id' => 102],
    ['org_id' => 3, 'store_id' => 103],
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

// 指定门店的组织排行以授权门店集合另建投影，不沿用单店工作台的空子节点。
$projector = new MobileWarehouseHierarchyProjector();
$activeStore = $projector->project($organizations, $stores, $bindings, [101, 102], 'store', 101);
$rankingRoot = $projector->project($organizations, $stores, $bindings, [101, 102]);
check($activeStore['rows'] === [] && count($rankingRoot['rows']) === 2, 'organization ranking must aggregate both selected stores');
check(array_sum(array_column($rankingRoot['rows'], 'storeCount')) === 2, 'organization ranking must exclude the unauthorized store');

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
