<?php

declare(strict_types=1);

/** The ledger must reconcile successful refund operations without counting balance grants. */
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require rtrim($backend, '/\\') . '/vendor/autoload.php';

use app\services\report\StoreUnifiedReportServices;
use think\facade\Db;

$app = new \think\App(rtrim($backend, '/\\') . '/');
$app->initialize();
date_default_timezone_set('Asia/Shanghai');

$reports = new StoreUnifiedReportServices();
$operations = Db::name('cashier_v3_order_lifecycle_operation')
    ->where('operation_type', 'refund')->where('status', 'succeeded')
    ->whereIn('source_type', ['card', 'document', 'imported_card'])
    ->order('id', 'desc')->select()->toArray();
if (!$operations) throw new RuntimeException('Local successful refund fixtures are required');

$byStoreAndDate = [];
foreach ($operations as $operation) {
    $key = (int)$operation['store_id'] . '|' . (string)$operation['business_date'];
    $byStoreAndDate[$key][] = $operation;
}

foreach ($byStoreAndDate as $group) {
    $seed = $group[0];
    $storeId = (int)$seed['store_id'];
    $date = (string)$seed['business_date'];
    $input = [
        'report' => 'store_refund_ledger', 'start_date' => $date, 'end_date' => $date,
        'page' => 1, 'limit' => 100, '_internal_all' => true,
    ];
    $result = $reports->query([$storeId], $input);
    $export = $reports->export([$storeId], $input);
    $ids = array_column($result['records'], 'operation_id');
    $expectedCents = array_sum(array_map(static fn(array $row): int => (int)$row['cash_refund_cents'], $group));
    $actualCents = array_sum(array_map('intval', array_column($result['records'], 'refund_amount_cents')));
    $columnLabels = array_column($result['columns'], 'label', 'key');
    $columnKeys = array_column($result['columns'], 'key');
    if (count($ids) !== count(array_unique($ids))
        || count(array_intersect(array_column($group, 'operation_id'), $ids)) !== count($group)
        || $actualCents !== $expectedCents
        || (int)($result['totals']['refund_amount_cents'] ?? -1) !== $expectedCents
        || (string)($result['metric_version'] ?? '') !== 'store-refund-ledger-v3'
        || in_array('market', $columnKeys, true)
        || ($columnLabels['store_name'] ?? '') !== '门店'
        || ($columnLabels['customer'] ?? '') !== '会员'
        || ($columnLabels['phone'] ?? '') !== '手机'
        || array_search('phone', $columnKeys, true) !== array_search('customer', $columnKeys, true) + 1
        || array_column($export['records'], 'operation_id') !== $ids
        || array_column($export['columns'], 'key') !== $columnKeys
        || $export['summary_row'] !== ($result['summary_row'] ?? [])) {
        throw new RuntimeException("Refund ledger, total and export diverged for store {$storeId} on {$date}");
    }
    foreach ($result['records'] as $row) {
        $currentPhone = (string)Db::name('user')->where('uid', (int)$row['member_id'])->value('phone');
        if (trim((string)($row['refund_items'] ?? '')) === ''
            || trim((string)($row['original_sale_date'] ?? '')) === ''
            || (string)($row['phone'] ?? '') !== $currentPhone) {
            throw new RuntimeException('Refund target, original sale date or member phone was lost');
        }
    }
    echo "PASS store={$storeId} date={$date} count=" . count($group) . " cents={$expectedCents}\n";
}

// A successful operation from another store must never appear through a narrower store scope.
$first = $operations[0];
$otherStore = Db::name('system_store')->where('id', '<>', (int)$first['store_id'])->value('id');
if ($otherStore) {
    $date = (string)$first['business_date'];
    $other = $reports->query([(int)$otherStore], [
        'report' => 'store_refund_ledger', 'start_date' => $date, 'end_date' => $date,
        '_internal_all' => true,
    ]);
    if (in_array((string)$first['operation_id'], array_column($other['records'], 'operation_id'), true)) {
        throw new RuntimeException('Another store can see the refund operation');
    }
}
echo "STORE_REFUND_LEDGER=PASS\n";
