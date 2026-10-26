<?php
/**
 * 收银 V3 数据权限选店登录回归：只验证服务端候选门店与会话口径，不模拟密码或浏览器。
 */
require __DIR__ . '/../../cashier-v3/lib/boot-env.php';
require '/var/www/html/vendor/autoload.php';
require __DIR__ . '/../../cashier-v3/lib/_lib.php';

use app\services\cashier\v3\CashierV3StoreLoginServices;
use think\facade\Db;

$app = c1aBootThinkApp('/var/www/html/');
$services = app()->make(CashierV3StoreLoginServices::class);
$employeeId = (int)(Db::name('employee_internal_account')->where('account', 'A0751')->value('employee_id') ?: 0);
$stores = $employeeId > 0 ? $services->eligibleStores($employeeId) : [];
$delegated = array_values(array_filter($stores, static function (array $row): bool {
    return !empty($row['delegated']) && ($row['source'] ?? '') === 'data_scope';
}));
$ok = $employeeId > 0 && count($delegated) > 1;
echo ($ok ? 'PASS' : 'FAIL') . " data-scope employee={$employeeId} stores=" . count($stores) . " delegated=" . count($delegated) . PHP_EOL;
exit($ok ? 0 : 1);
