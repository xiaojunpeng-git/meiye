<?php
/**
 * 只读回归：切店导航必须完整覆盖已授权候选门店；任职店使用真实组织归属，
 * 无组织的门店保持可选但不生成“未归属组织”分组。
 * 仅对本地测试库运行，由环境变量指定已有员工及其当前任职门店。
 */
require '/var/www/html/vendor/autoload.php';

(new think\App('/var/www/html/'))->initialize();

$employeeId = (int)(getenv('STORE_SWITCH_EMPLOYEE_ID') ?: 0);
$currentStoreId = (int)(getenv('STORE_SWITCH_CURRENT_STORE_ID') ?: 0);
$expectedCount = (int)(getenv('STORE_SWITCH_EXPECTED_COUNT') ?: 0);
if ($employeeId <= 0 || $currentStoreId <= 0 || $expectedCount <= 0) {
    throw new RuntimeException('请指定本地回归库的 STORE_SWITCH_EMPLOYEE_ID、CURRENT_STORE_ID、EXPECTED_COUNT');
}

$login = app()->make(app\services\cashier\v3\CashierV3StoreLoginServices::class);
$result = $login->switchableStores($employeeId, $currentStoreId);
$stores = (array)($result['stores'] ?? []);
$tree = (array)($result['organization_tree'] ?? []);
$candidateIds = array_map('intval', array_column($stores, 'store_id'));
$treeIds = [];
$treeNames = [];
$walk = static function (array $nodes) use (&$walk, &$treeIds, &$treeNames): void {
    foreach ($nodes as $node) {
        $treeNames[] = (string)($node['name'] ?? '');
        if (($node['node_type'] ?? '') === 'store') {
            $treeIds[] = (int)($node['store_id'] ?? 0);
        }
        $walk((array)($node['children'] ?? []));
    }
};
$walk($tree);
sort($candidateIds);
sort($treeIds);
if (count($stores) !== $expectedCount || $candidateIds !== $treeIds
    || count($candidateIds) !== count(array_unique($candidateIds))
    || in_array('未归属组织', $treeNames, true)) {
    throw new RuntimeException('授权候选与组织树不一致，或仍显示未归属组织');
}

// 任职店不能因其先于数据范围去重而丢失 organization_store 中的真实归属。
$binding = think\facade\Db::name('organization_store')->alias('link')
    ->join('organization org', 'org.id = link.org_id')
    ->where('link.store_id', $currentStoreId)->where('org.is_del', 0)
    ->field('org.id AS org_id,org.name AS org_name')->find();
$current = current(array_filter($stores, static fn(array $store): bool => (int)$store['store_id'] === $currentStoreId));
if (!$current || !$binding || (int)$current['org_id'] !== (int)$binding['org_id']
    || (string)$current['org_name'] !== (string)$binding['org_name']) {
    throw new RuntimeException('任职门店未使用真实组织归属');
}

// 真实无组织门店应直接出现在导航根节点，不能为了隐藏分组而剔除授权门店。
$treeMethod = new ReflectionMethod($login, 'presentOrganizationTree');
$treeMethod->setAccessible(true);
$unassigned = $treeMethod->invoke($login, [[
    'store_id' => 999999991, 'store_name' => '本地无归属回归门店', 'org_id' => 0,
]]);
if (count($unassigned) !== 1 || ($unassigned[0]['node_type'] ?? '') !== 'store'
    || (int)($unassigned[0]['store_id'] ?? 0) !== 999999991) {
    throw new RuntimeException('无组织的授权门店未以根节点保留');
}

echo 'PASS candidate_count=' . count($stores) . ' tree_count=' . count($treeIds)
    . ' direct_org=' . $current['org_id'] . ' unassigned_group=hidden' . PHP_EOL;
