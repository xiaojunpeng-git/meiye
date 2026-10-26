<?php

/** C22-00002 销售订单卡项购买次数历史快照契约（PHP 7.4）。 */

$root = dirname(__DIR__, 3);
$backendRoot = trim((string)getenv('CASHIER_V3_BACKEND_ROOT'));
if ($backendRoot === '') {
    $backendRoot = $root . '/后端代码';
}
require $backendRoot . '/app/services/cashier/v3/order/CashierV3SalesOrderQueryServices.php';

use app\services\cashier\v3\order\CashierV3SalesOrderQueryServices;

$service = new CashierV3SalesOrderQueryServices();
$method = new ReflectionMethod(CashierV3SalesOrderQueryServices::class, 'cardPurchaseTimesSnapshot');
// PHP 8.1 起私有方法可直接由 Reflection 调用；旧的 PHP 7.4 测试
// 运行时仍需显式开放可见性。避免在本地 PHP 8.5 产生无关弃用提示。
if (PHP_VERSION_ID < 80100) {
    $method->setAccessible(true);
}
$passed = 0;
$failed = 0;

$check = static function (string $name, bool $condition) use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
        echo "[PASS] {$name}\n";
        return;
    }
    $failed++;
    echo "[FAIL] {$name}\n";
};

$independent = $method->invoke($service, json_encode([
    'sourceKind' => 'card_package',
    'ruleType' => 'normal',
    'components' => [
        ['nameSnapshot' => '赋活霜', 'writeTimes' => 20],
        ['nameSnapshot' => '护理项目', 'writeTimes' => 3],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$check('独立次数卡展示成交时各权益购买次数，不读取当前余额', $independent === [
    'mode' => 'independent',
    'components' => [
        ['name' => '赋活霜', 'times' => 20],
        ['name' => '护理项目', 'times' => 3],
    ],
]);

$shared = $method->invoke($service, json_encode([
    'ruleType' => 'choice_count',
    'sharedTimes' => 12,
    'components' => [
        ['nameSnapshot' => '任选项目 A', 'writeTimes' => 0],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$check('任选卡只展示共享购买次数，不伪造每个项目的次数', $shared === [
    'mode' => 'shared',
    'times' => 12,
    'components' => [],
]);

$time = $method->invoke($service, json_encode([
    'ruleType' => 'time',
    'components' => [['nameSnapshot' => '按时长服务', 'writeTimes' => 0]],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$check('按时长卡不伪造购买次数', $time === []);
$check('空或畸形快照不以零次冒充历史事实',
    $method->invoke($service, '') === [] && $method->invoke($service, '{invalid') === []);

echo "ORDER_CENTER_CARD_PURCHASE_TIMES_CONTRACT passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
