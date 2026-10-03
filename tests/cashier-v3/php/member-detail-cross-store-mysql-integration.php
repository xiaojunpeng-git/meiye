<?php
declare(strict_types=1);

$backend = getenv('CASHIER_V3_BACKEND') ?: '/var/www/html';
require $backend . '/vendor/autoload.php';

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResourceVersionServices;
use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\cashier\v3\cashier\CashierV3CashierReadinessGuard;
use app\services\cashier\v3\cashier\CashierV3CashierWorkspaceServices;
use app\services\cashier\v3\cashier\CashierV3EntitlementProjectionServices;
use app\services\cashier\v3\cashier\CashierV3EntitlementResourceVersionProvider;
use app\services\cashier\v3\checkout\CashierV3DirectSnapshotEntitlementSettlementServices;
use app\services\cashier\v3\checkout\provider\CashierV3EntitlementDebtGuardProvider;
use app\services\cashier\v3\checkout\provider\CashierV3PerformanceRuleProvider;
use app\services\cashier\v3\checkout\provider\CashierV3StaffProfileProvider;
use app\services\cashier\v3\checkout\provider\CashierV3EmployeeTypeAuthority;
use app\services\cashier\v3\member\CashierV3MemberDetailQueryServices;
use app\services\cashier\v3\order\CashierV3SalesOrderQueryServices;
use think\facade\Config;
use think\facade\Db;

$app = new \think\App($backend . '/');
$app->env->load($backend . '/.env');
foreach ([
    'cache.driver' => 'file', 'CACHE_DRIVER' => 'file',
    'database.hostname' => getenv('DB_HOST') ?: 'mysql', 'DATABASE_HOSTNAME' => getenv('DB_HOST') ?: 'mysql',
    'database.hostport' => getenv('DB_PORT') ?: '3306', 'DATABASE_HOSTPORT' => getenv('DB_PORT') ?: '3306',
    'database.database' => getenv('DB_DATABASE') ?: 'ruihao', 'DATABASE_DATABASE' => getenv('DB_DATABASE') ?: 'ruihao',
    'database.username' => getenv('DB_USERNAME') ?: 'root', 'DATABASE_USERNAME' => getenv('DB_USERNAME') ?: 'root',
    'database.password' => getenv('DB_PASSWORD') ?: 'localdev123', 'DATABASE_PASSWORD' => getenv('DB_PASSWORD') ?: 'localdev123',
] as $key => $value) $app->env->set($key, $value);
$envName = new ReflectionProperty($app, 'envName');
$envName->setAccessible(true);
$envName->setValue($app, 'member_detail_cross_store_skip_reload_dotenv');
$app->initialize();
Config::set(['default' => 'file'], 'cache');
Db::connect('mysql', true)->query('SELECT 1');

$memberId = (int)(getenv('MEMBER_DETAIL_TEST_MEMBER_ID') ?: 994678);
$currentStoreId = (int)(getenv('MEMBER_DETAIL_TEST_CURRENT_STORE_ID') ?: 1);
$memberStoreId = (int)Db::name('user')->where('uid', $memberId)->value('belong_store_id');
if ($memberStoreId <= 0 || $memberStoreId === $currentStoreId) {
    throw new RuntimeException('CROSS_STORE_MEMBER_FIXTURE_MISSING');
}
$organizationId = (string)Db::name('organization_store')->where('store_id', $currentStoreId)->value('org_id');
$tenantId = '0';
$operator = new CashierV3OperatorScope($currentStoreId, 1, $organizationId, $tenantId);
$scope = new CashierV3DataScopeContext(
    1, 1, $currentStoreId, $tenantId, $organizationId, [$currentStoreId],
    CashierV3DataScopeContext::MODE_STORES, [], false, '', 'member-detail-cross-store-test', ['cashier.v3.order_center'],
    ['id' => 1, 'staff_name' => 'integration operator']
);
$query = new CashierV3MemberDetailQueryServices();
$detail = $query->read($memberId, $operator, $scope, ['tab' => 'gift', 'status' => 'active']);
$historyCount = (int)Db::name('store_order_cart_info')->alias('ci')
    ->join('store_order o', 'o.id=ci.oid AND o.uid=ci.uid')
    ->where('ci.uid', $memberId)->where('ci.cart_type', 2)
    ->where('ci.product_type', 6)->where('ci.is_gift', 1)
    ->where('ci.source_type', 'migration')->where('ci.write_surplus_times', '>', 0)
    ->where('o.paid', 1)->where('o.is_del', 0)->where('o.is_system_del', 0)
    ->where('o.is_user_del', 0)->where('o.refund_status', 0)->where('o.terminal_action', 0)
    ->where('o.store_id', '<>', $currentStoreId)->count();
$visibleHistory = array_values(array_filter((array)$detail['giftRecords'], static function (array $record): bool {
    return ($record['sourceType'] ?? '') === 'migrated_project_gift';
}));
if ($historyCount <= 0 || count($visibleHistory) < $historyCount) {
    throw new RuntimeException('CROSS_STORE_GIFT_NOT_VISIBLE expected=' . $historyCount . ' actual=' . count($visibleHistory));
}
$readiness = new CashierV3CashierReadinessGuard();
$projection = new CashierV3EntitlementProjectionServices(
    $readiness,
    new CashierV3CashierWorkspaceServices($readiness),
    new CashierV3EntitlementResourceVersionProvider($readiness),
    new CashierV3ResourceVersionServices(new CashierV3ScopeResolver())
);
$selector = $projection->openSelector([
    'memberId' => $memberId,
    'selectorRequestId' => 'ENTITLEMENT_SELECTOR-00000000-0000-4000-8000-000000000001',
], 'member-detail-cross-store-test', $operator, $scope);
$legacySources = array_values(array_filter((array)$selector['entitlementSelector']['sources'], static function (array $source): bool {
    return ($source['entitlementInstanceType'] ?? '') === 'independent_gift';
}));
if (count($legacySources) < $historyCount) {
    throw new RuntimeException('CROSS_STORE_GIFT_SELECTOR_MISSING expected=' . $historyCount . ' actual=' . count($legacySources));
}
$gift = $legacySources[0];
$project = $gift['projects'][0];
$staffId = (int)Db::name('system_store_staff')->where('store_id', $currentStoreId)->where('is_del', 0)->value('id');
if ($staffId <= 0) throw new RuntimeException('HISTORICAL_GIFT_TEST_STAFF_MISSING');
$checkoutScope = new CashierV3DataScopeContext(
    1, 1, $currentStoreId, $tenantId, $organizationId, [$currentStoreId],
    CashierV3DataScopeContext::MODE_STORES, [], false, '', 'historical-gift-checkout-test', ['cashier.v3.cashier'],
    ['id' => 1, 'staff_name' => 'integration operator']
);
$adapter = new CashierV3DirectSnapshotEntitlementSettlementServices(
    new CashierV3CashierWorkspaceServices($readiness),
    new CashierV3StaffProfileProvider(new CashierV3EmployeeTypeAuthority()),
    new CashierV3PerformanceRuleProvider(),
    new CashierV3EntitlementDebtGuardProvider()
);
$discovery = $adapter->discover([
    'action' => 'finalize-checkout-snapshot',
    'operator_scope' => $operator, 'data_scope' => $checkoutScope,
    'state_context_id' => 'historical-gift-checkout-test',
    'contexts' => [],
    'payload' => ['checkoutSnapshot' => [
        'memberId' => $memberId, 'businessDate' => date('Y-m-d'),
        'lines' => [[
            'lineId' => 'historical-gift-test-line', 'lineRole' => 'entitlement_service',
            'entitlementInstanceType' => 'independent_gift',
            'entitlementInstanceId' => $gift['entitlementInstanceId'],
            'entitlementSourceDetailId' => $project['entitlementSourceDetailId'],
            'projectId' => $project['projectId'], 'quantity' => 1, 'actualAmount' => 0,
            'sourceNameSnapshot' => $gift['name'], 'sourceCodeSnapshot' => $gift['fullCardNo'],
            'projectNameSnapshot' => $project['name'], 'serviceObject' => '本人',
            'craftsmen' => [['staffId' => $staffId, 'laborWeight' => 100]],
        ]],
    ]],
]);
if (empty($discovery['resources'])) {
    throw new RuntimeException('HISTORICAL_GIFT_CHECKOUT_DISCOVERY_EMPTY');
}
$sales = new CashierV3SalesOrderQueryServices();
$salesPayload = ['memberId' => $memberId, 'page' => 1, 'pageSize' => 50];
$storeSales = $sales->querySalesOrders($salesPayload, $operator, $scope);
$allSales = $sales->querySalesOrders($salesPayload, $operator, $scope, true);
if ((int)$allSales['total'] < (int)$storeSales['total']) {
    throw new RuntimeException('MEMBER_DETAIL_SALES_SCOPE_NOT_EXPANDED');
}
$selfScope = new CashierV3DataScopeContext(
    1, 1, $currentStoreId, $tenantId, $organizationId, [],
    CashierV3DataScopeContext::MODE_SELF_PARTICIPANT, [], false, '', 'member-detail-cross-store-test', [],
    ['id' => 1, 'staff_name' => 'integration operator']
);
try {
    $query->read($memberId, $operator, $selfScope);
    throw new RuntimeException('SELF_PARTICIPANT_BYPASSED_DETAIL_GATE');
} catch (\app\services\cashier\v3\CashierV3CommandException $expected) {
    // A self-participant-only session cannot acquire cross-store member history.
}
echo 'CROSS_STORE_MEMBER_DETAIL_OK historical_gifts=' . count($visibleHistory)
    . ' local_sales=' . $storeSales['total'] . ' all_sales=' . $allSales['total'] . PHP_EOL;
