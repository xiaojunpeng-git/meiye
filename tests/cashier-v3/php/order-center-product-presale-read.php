<?php

declare(strict_types=1);

/**
 * Read-only presale projection regression. The optional local fixture check
 * uses the normal scoped query; no checkout, stock change or historical repair.
 */
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require $backend . '/vendor/autoload.php';

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\order\CashierV3SalesOrderQueryServices;
use think\facade\Db;

$reader = new CashierV3SalesOrderQueryServices();
$formal = new ReflectionMethod($reader, 'mapAuthorityLine');
$legacy = new ReflectionMethod($reader, 'mapLine');
$service = new ReflectionMethod($reader, 'mapEntitlementServiceLine');
foreach ([$formal, $legacy, $service] as $method) $method->setAccessible(true);
$check = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$line = [
    'order_line_id' => 'presale-line', 'item_type' => 'product',
    'item_name_snapshot' => 'Same product', 'quantity' => 3,
    'original_amount_cents' => 56400, 'sale_amount_cents' => 56400,
];
foreach ([0 => false, 1 => true] as $flag => $expected) {
    foreach ([$flag, (string)$flag] as $storedFlag) {
        $snapshot = array_merge($line, ['is_presale' => $storedFlag]);
        $mapped = $formal->invoke($reader, $snapshot, []);
        $oldMapped = $legacy->invoke($reader, ['id' => 9], ['authority' => $snapshot]);
        $check($mapped['isPresale'] === $expected && $oldMapped['isPresale'] === $expected, 'presale flag lost');
        $check($mapped['quantity'] === 3 && $mapped['payableAmount'] === 564.0, 'presale changed economics');
    }
}
$check($formal->invoke($reader, $line, [])['isPresale'] === false, 'missing flag inferred');
$check($legacy->invoke($reader, ['cart_info' => ['isPresale' => true]])['isPresale'] === false, 'legacy cart inferred');
foreach (['project', 'card'] as $type) {
    $snapshot = array_merge($line, ['item_type' => $type, 'is_presale' => 1]);
    $check($formal->invoke($reader, $snapshot, [])['isPresale'] === false, 'non-product presale label');
    $check($legacy->invoke($reader, [], ['authority' => $snapshot])['isPresale'] === false, 'legacy non-product label');
}
$entitlement = $service->invoke($reader, [
    'id' => 1, 'quantity' => 1, 'project_name_snapshot' => 'Service', 'is_presale' => 1,
], []);
$check(empty($entitlement['isPresale']), 'entitlement was labelled presale');
echo "presale mapping regression: PASS\n";

// A missing, malformed or legacy-only flag cannot become an historical choice.
foreach ([0, '0', 1, '1', null, false, '', 'invalid'] as $outbound) {
    $snapshot = array_merge($line, ['inventory_outbound_required' => $outbound]);
    $expected = in_array($outbound, [0, '0'], true);
    $check($formal->invoke($reader, $snapshot, [])['isNoOutbound'] === $expected, 'formal outbound flag lost');
    $check($legacy->invoke($reader, [], ['authority' => $snapshot])['isNoOutbound'] === $expected, 'linked outbound flag lost');
}
$check($formal->invoke($reader, $line, [])['isNoOutbound'] === false, 'missing outbound flag inferred');
$check($legacy->invoke($reader, ['cart_info' => ['inventoryOutboundRequired' => false]])['isNoOutbound'] === false, 'legacy outbound inferred');
foreach (['project', 'card'] as $type) {
    $snapshot = array_merge($line, ['item_type' => $type, 'inventory_outbound_required' => 0]);
    $check($formal->invoke($reader, $snapshot, [])['isNoOutbound'] === false, 'non-product outbound label');
    $check($legacy->invoke($reader, [], ['authority' => $snapshot])['isNoOutbound'] === false, 'legacy non-product outbound label');
}
$check(empty($entitlement['isNoOutbound']), 'entitlement labelled no outbound');
echo "no-outbound mapping regression: PASS\n";

if (in_array('--local-fixture', $argv, true)) {
    $app = new \think\App($backend . '/');
    $app->initialize();
    // Do not let this local acceptance probe follow a production/tunnel config.
    $config = config('database.connections.mysql');
    $check(file_exists('/.dockerenv') && ($config['hostname'] ?? '') === 'mysql' && ($config['database'] ?? '') === 'ruihao', 'not local fixture database');
    Db::execute('START TRANSACTION READ ONLY');
    try {
        foreach (['XS26100900006' => 2, 'XS26101000001' => 3] as $orderNo => $expectedCount) {
            $order = Db::name('cashier_v3_sales_order')->where('order_no', $orderNo)->find();
            $check(is_array($order), 'local sample missing');
            $store = (int)$order['store_id'];
            $tenant = (string)$order['tenant_id'];
            $scope = new CashierV3DataScopeContext(
                71, 701, $store, $tenant, 'organization:test', [$store],
                CashierV3DataScopeContext::MODE_ALL, [], true, 'test-super', 'permission-v2',
                ['cashier.v3.order_center', 'cashier.v3.order.service_detail'], ['account' => 'presale-readonly']
            );
            $operator = new CashierV3OperatorScope($store, 71, 'organization:test', $tenant);
            $filters = [
                'recordType' => 'sales', 'storeIds' => [$store], 'dataScope' => 'all', 'status' => '',
                'page' => 1, 'pageSize' => 100, 'dateFrom' => $order['business_date'], 'dateTo' => $order['business_date'],
            ];
            // Two fresh reads verify reload stability without relying on browser cache.
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $page = $reader->querySalesOrders($filters, $operator, $scope);
                $records = array_values(array_filter($page['records'], static function (array $row) use ($order): bool {
                    return ($row['orderId'] ?? '') === $order['order_id'];
                }));
                $check(count($records) === 1, 'sample missing from scoped list');
                $items = $records[0]['items'];
                $source = Db::name('cashier_v3_sales_order_line')->where('order_id', $order['order_id'])
                    ->field('order_line_id,is_presale,inventory_outbound_required,quantity,sale_amount_cents')->select()->toArray();
                $check(count($items) === $expectedCount && count($source) === $expectedCount, 'sample row count changed');
                foreach ($source as $row) {
                    $matches = array_values(array_filter($items, static function (array $item) use ($row): bool {
                        return $item['id'] === $row['order_line_id'];
                    }));
                    $check(count($matches) === 1, 'line identity lost');
                    $item = $matches[0];
                    $check($item['isPresale'] === ((int)$row['is_presale'] === 1), 'sibling flag leaked');
                    $check($item['isNoOutbound'] === ((int)$row['inventory_outbound_required'] === 0), 'sibling outbound flag leaked');
                    $check($item['quantity'] === (int)$row['quantity'] && $item['payableAmount'] === (float)((int)$row['sale_amount_cents'] / 100), 'line money changed');
                }
                $check(count(array_filter($items, static function (array $item): bool { return $item['isPresale']; })) === 1, 'expected exactly one presale line');
                $check(count(array_filter($items, static function (array $item): bool { return $item['isNoOutbound']; })) === $expectedCount - 1, 'expected explicit no-outbound choices');
                $check((float)$records[0]['receivableAmount'] === 1128.0, 'order money changed');
            }
            echo "local scoped query/reload: PASS; order=$orderNo; rows=$expectedCount; presale=1; receivable=1128\n";
        }
    } finally {
        Db::execute('ROLLBACK');
    }
}
