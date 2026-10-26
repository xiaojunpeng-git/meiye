<?php
/** 本地只读对账：平台和商家首页使用同一分类、销售类型与客情投影，不写业务数据。 */
declare(strict_types=1);
require '/var/www/html/vendor/autoload.php';
use app\services\report\GroupManagementDashboardServices;
use app\services\mobile\dashboard\MobileMerchantDashboardServices;
use think\facade\Db;
(new think\App('/var/www/html/'))->initialize();
if (config('database.connections.mysql.hostname') !== 'mysql' || config('database.connections.mysql.database') !== 'ruihao') {
    throw new RuntimeException('Only local ruihao Docker database is allowed');
}
$assert = static function (bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException('FAIL ' . $label);
    echo 'PASS ' . $label . PHP_EOL;
};
Db::execute('SET TRANSACTION READ ONLY');
Db::startTrans();
try {
    $stores = array_map('intval', Db::name('system_store')->where('is_del', 0)->where('name', '<>', '总部')->column('id'));
    $employeeId = (int)Db::name('employee')->where('is_del', 0)->value('id');
    $date = '2026-10-22';
    $context = ['employeeId' => $employeeId, 'visibleStoreIds' => $stores, 'dataScopeMode' => 'STORES', 'availableActions' => ['CUSTOMER_VIEW']];
    $input = ['projectionVersion' => 'sections-v2', 'periodMode' => 'custom', 'startDate' => $date, 'endDate' => $date];
    $groupService = app()->make(GroupManagementDashboardServices::class);
    $mobileService = app()->make(MobileMerchantDashboardServices::class);
    $group = $groupService->dashboard(['tenant_id' => '0', 'store_ids' => $stores], ['start_date' => $date, 'end_date' => $date]);
    $mobile = $mobileService->home($context, $input);
    $assert($group['cards'] === $mobile['overview']['cards'], 'six operating metrics match platform exactly');
    foreach (['sale_type_cards', 'categories'] as $key) $assert($group[$key] === $mobile[$key], $key . ' matches platform exactly');
    $assert($group['sources']['records'] === $mobile['sources']['records'], 'source metrics match platform exactly');
    $assert(array_column($mobile['sale_type_cards'], 'code') === ['normal_card', 'project', 'product'], 'fixed sale types preserve their existing metric contract');
    foreach ($mobile['categories'] as $category) {
        $assert(count($category['project_rankings']) <= 5 && count($category['product_rankings']) <= 5, 'category ' . $category['category_id'] . ' rank contract');
    }
    foreach ($mobile['sources']['records'] as $source) {
        $assert(array_key_exists('active_customer_count', $source) === ($source['source_code'] === 'A'), 'only A has active customer count');
    }
    $visible = array_values(array_filter($mobile['categories'], static fn(array $c): bool => $c['visible']));
    if ($visible !== []) {
        $detail = $mobileService->home($context, array_merge($input, ['view' => 'category', 'categoryId' => $visible[0]['category_id']]));
        $assert($detail['categories'] === [$visible[0]] && !isset($detail['trend']) && !isset($detail['sources']), 'category view keeps exact category and skips unrelated home projections');
    }
    $missing = $mobileService->home($context, array_merge($input, ['view' => 'category', 'categoryId' => 2147483647]));
    $assert($missing['categories'] === [], 'unknown category never guesses a result');
    $legacyInput = $input;
    unset($legacyInput['projectionVersion']);
    $legacy = $mobileService->home($context, $legacyInput);
    $assert(isset($legacy['customer']['metrics'], $legacy['product']['metrics']) && !isset($legacy['sources']), 'installed legacy client keeps its original customer and product fields');
    $denied = $mobileService->home(array_merge($context, ['availableActions' => []]), $input);
    $assert($denied['sources']['state'] === 'unavailable' && $denied['sources']['records'] === [], 'customer permission is enforced on server');
    $restricted = array_merge($context, ['visibleStoreIds' => [$stores[0]]]);
    $narrow = $mobileService->home($restricted, array_merge($input, ['store_ids' => implode(',', $stores)]));
    $expected = $groupService->homeSections(['tenant_id' => '0', 'store_ids' => [$stores[0]]], ['start_date' => $date, 'end_date' => $date]);
    $assert($narrow['categories'] === $expected['categories'] && $narrow['sources']['records'] === $expected['sources']['records'], 'client scope cannot enlarge authorized stores');
    try {
        $mobileService->home(array_merge($context, ['dataScopeMode' => 'PERSONAL_SELF']), $input);
        throw new RuntimeException('Personal scope unexpectedly allowed');
    } catch (\app\services\mobile\protocol\MobileApiException $error) {
        $assert(true, 'personal scope cannot read store dashboard');
    }
    echo json_encode(['stores' => count($stores), 'visible_categories' => count(array_filter($mobile['categories'], static fn(array $c): bool => $c['visible'])), 'sources' => count($mobile['sources']['records']), 'date' => $date], JSON_UNESCAPED_UNICODE), PHP_EOL;
} finally { Db::rollback(); }
