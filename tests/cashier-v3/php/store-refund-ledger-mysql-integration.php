<?php

declare(strict_types=1);

/** The ledger must reconcile successful refund operations without counting balance grants. */
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require rtrim($backend, '/\\') . '/vendor/autoload.php';

use app\services\report\StoreUnifiedReportServices;
use app\services\report\StoreRefundCurrentCategoryServices;
use app\services\product\product\SystemBalanceProjectServices;
use think\facade\Db;

$app = new \think\App(rtrim($backend, '/\\') . '/');
$app->initialize();
date_default_timezone_set('Asia/Shanghai');

$reports = new StoreUnifiedReportServices();
$operations = Db::name('cashier_v3_order_lifecycle_operation')
    ->where('operation_type', 'refund')->where('status', 'succeeded')
    ->whereIn('source_type', ['card', 'document', 'catalog', 'imported_card', 'balance'])
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
        || (string)($result['metric_version'] ?? '') !== 'store-refund-ledger-v9'
        || in_array('market', $columnKeys, true)
        || ($columnLabels['store_name'] ?? '') !== '门店'
        || ($columnLabels['customer'] ?? '') !== '会员'
        || ($columnLabels['phone'] ?? '') !== '手机'
        || array_search('phone', $columnKeys, true) !== array_search('customer', $columnKeys, true) + 1
        || array_slice($columnKeys, array_search('refund_items', $columnKeys, true) - 4, 5)
            !== ['salesperson', 'guide', 'sales_manager', 'category', 'refund_items']
        || in_array('attribution', $columnKeys, true)
        || array_column($export['records'], 'operation_id') !== $ids
        || array_column($export['columns'], 'key') !== $columnKeys
        || $export['summary_row'] !== ($result['summary_row'] ?? [])) {
        throw new RuntimeException("Refund ledger, total and export diverged for store {$storeId} on {$date}");
    }
    foreach ($result['records'] as $row) {
        $currentPhone = (string)Db::name('user')->where('uid', (int)$row['member_id'])->value('phone');
        if (trim((string)($row['refund_items'] ?? '')) === ''
            || (!in_array((string)$row['source_type'], ['catalog', 'balance'], true)
                && trim((string)($row['original_sale_date'] ?? '')) === '')
            || (string)($row['phone'] ?? '') !== $currentPhone) {
            throw new RuntimeException('Refund target, original sale date or member phone was lost');
        }
        // 导出必须沿用同一笔退款的当前投影；不得在客户端重新补人名或分类。
        $exportRow = $export['records'][array_search((string)$row['operation_id'], $ids, true)] ?? [];
        foreach (['salesperson', 'guide', 'sales_manager', 'category'] as $key) {
            if (!array_key_exists($key, $row) || ($exportRow[$key] ?? null) !== $row[$key]) {
                throw new RuntimeException("Refund ledger {$key} diverged from export");
            }
        }
        $operationId = (string)$row['operation_id'];
        $tenantId = (string)$row['tenant_id'];
        $revision = Db::name('cashier_v3_refund_attribution_revision')->where('tenant_id', $tenantId)
            ->where('operation_id', $operationId)->order('id desc')->find();
        $attributionQuery = Db::name($revision ? 'cashier_v3_refund_attribution_revision_person'
            : 'cashier_v3_refund_attribution')->where('tenant_id', $tenantId)->where('operation_id', $operationId);
        if ($revision) $attributionQuery->where('revision_no', (int)$revision['revision_no']);
        $guide = []; $manager = [];
        foreach ($attributionQuery->order('id asc')->select()->toArray() as $person) {
            if ((string)$person['role'] === 'guide') $guide[(int)$person['employee_id']] = (string)$person['employee_name_snapshot'];
            if ((string)$person['role'] === 'sales_manager') $manager[(int)$person['employee_id']] = (string)$person['employee_name_snapshot'];
        }
        if ((string)$row['guide'] !== implode('、', $guide)
            || (string)$row['sales_manager'] !== implode('、', $manager)
            || array_key_exists('attribution', $row)) {
            throw new RuntimeException('Refund ledger did not split the latest complete attribution version');
        }
        $sourceType = (string)$row['source_type'];
        if (in_array($sourceType, ['card', 'document', 'catalog'], true)) {
            $adjustment = (array)Db::name('cashier_v3_refund_adjustment')
                ->where('tenant_id', $tenantId)->where('operation_id', $operationId)->find();
            $people = json_decode((string)($adjustment['salespeople_snapshot_json'] ?? '[]'), true);
            $names = [];
            foreach (is_array($people) ? $people : [] as $person) {
                if (!is_array($person)) continue;
                $name = trim((string)($person['name'] ?? ''));
                if ($name !== '') $names[(string)($person['employeeId'] ?? $name)] = $name;
            }
            if ((string)$row['salesperson'] !== implode('、', array_values($names))) {
                throw new RuntimeException('Manual refund salesperson did not match the current saved snapshot');
            }
        }
        if ($sourceType === 'imported_card') {
            $revision = Db::name('cashier_v3_refund_salesperson_revision')
                ->where('tenant_id', $tenantId)->where('operation_id', $operationId)->order('id desc')->find();
            $people = json_decode((string)($revision['new_snapshot_json'] ?? '[]'), true);
            $names = [];
            foreach (is_array($people) ? $people : [] as $person) {
                if (!is_array($person)) continue;
                $name = trim((string)($person['name'] ?? ''));
                if ($name !== '') $names[(string)($person['employeeId'] ?? $name)] = $name;
            }
            if ((string)$row['salesperson'] !== implode('、', array_values($names))
                || (string)$row['category'] !== '') {
                throw new RuntimeException('Imported-card refund projected unverified personnel or category');
            }
        }
        if ($sourceType === 'catalog') {
            $adjustment = (array)Db::name('cashier_v3_refund_adjustment')
                ->where('tenant_id', $tenantId)->where('operation_id', $operationId)->find();
            $categories = (new StoreRefundCurrentCategoryServices())->resolve([[
                'operation_id' => $operationId, 'source_type' => 'catalog',
                'store_id' => (int)$row['store_id'],
                'catalog_item_id' => (int)($adjustment['catalog_item_id'] ?? 0),
            ]]);
            $paths = array_values(array_unique(array_filter(array_map(
                static fn(array $category): string => trim((string)($category['path'] ?? '')),
                $categories[$operationId] ?? []
            ))));
            if ((string)$row['category'] !== implode('、', $paths)) {
                throw new RuntimeException('Catalog refund category must follow its verified current platform product');
            }
        }
        if ($sourceType === 'balance') {
            $category = (new SystemBalanceProjectServices())->category($storeId);
            if ((string)$row['refund_items'] !== '余额'
                || (string)$row['category'] !== (string)($category['path'] ?? '')
                || (string)$row['original_sale_date'] !== '') {
                throw new RuntimeException('Balance refund lost its protected project category');
            }
        }
    }
    // The zero-cash operation path must honor the same organization narrowing as paid refunds.
    if (array_filter($group, static fn(array $operation): bool => (int)$operation['cash_refund_cents'] === 0)) {
        $narrowed = $reports->query([$storeId], $input + ['company_dimension_id' => '__missing__']);
        if (array_intersect(array_column($group, 'operation_id'), array_column($narrowed['records'], 'operation_id'))) {
            throw new RuntimeException('Zero-cash refund escaped the organization filter');
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
