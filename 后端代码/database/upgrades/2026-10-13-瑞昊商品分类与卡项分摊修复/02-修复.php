<?php

declare(strict_types=1);

/**
 * RH-only, receipt-backed repair. The default mode is a read-only preview.
 * It never infers a sale's category from the card shell or its display name.
 */
function rhOption(array $args, string $name): string
{
    foreach ($args as $arg) {
        if (strpos($arg, $name . '=') === 0) return substr($arg, strlen($name) + 1);
    }
    return '';
}

/** Use the checkout writer's largest-remainder rule, including stable string-key ties. */
function rhAllocate(int $amount, array $weights): array
{
    if ($amount < 0 || $weights === []) throw new RuntimeException('invalid allocation');
    $total = array_sum($weights);
    if ($total <= 0) throw new RuntimeException('empty allocation weights');
    $result = [];
    $remainders = [];
    $allocated = 0;
    foreach ($weights as $key => $weight) {
        if (!is_int($weight) || $weight < 0) throw new RuntimeException('invalid allocation weight');
        $product = bcmul((string)$amount, (string)$weight, 0);
        $share = (int)bcdiv($product, (string)$total, 0);
        $result[(string)$key] = $share;
        $remainders[] = ['key' => (string)$key, 'remainder' => (int)bcmod($product, (string)$total)];
        $allocated += $share;
    }
    usort($remainders, static fn(array $a, array $b): int =>
        $b['remainder'] <=> $a['remainder'] ?: strcmp($a['key'], $b['key']));
    for ($left = $amount - $allocated, $index = 0; $left > 0; $left--, $index++) {
        $result[$remainders[$index]['key']]++;
    }
    return $result;
}

/** Reject broken, cross-tenant or cyclic categories instead of copying invalid IDs. */
function rhCategoryPath(int $id, array $categories): string
{
    $parts = [];
    $seen = [];
    while ($id > 0 && isset($categories[$id]) && !isset($seen[$id]) && count($parts) < 12) {
        $seen[$id] = true;
        $node = $categories[$id];
        if (trim((string)$node['cate_name']) === '') return '';
        array_unshift($parts, trim((string)$node['cate_name']));
        $id = (int)$node['pid'];
    }
    return $id === 0 ? implode(' / ', $parts) : '';
}

/** A fixed product inventory prevents a changed catalog from silently entering the repair. */
function rhProductPlan(PDO $db, array $categories): array
{
    $targets = [
        56952 => ['花园SPA测试套', '26312', ''],
        57056 => ['花园SPA加强套', '26312', ''],
        57264 => ['花园SPA精灵套（大）', '26312', ''],
        64128 => ['盈润滋养抑菌液', '22184', ''],
        603822 => ['黑金牌抑菌套', '', '26334'],
    ];
    $query = $db->query('SELECT id,store_name,cate_id,type,relation_id,is_del
        FROM eb_store_product WHERE id IN (56952,57056,57264,64128,603822,78583) ORDER BY id');
    $products = [];
    foreach ($query as $row) $products[(int)$row['id']] = $row;
    $donor = $products[78583] ?? [];
    if ($donor === [] || (int)$donor['type'] !== 0 || (int)$donor['relation_id'] !== 0
        || (int)$donor['is_del'] !== 1 || (string)$donor['store_name'] !== '黑金牌抑菌套'
        || (string)$donor['cate_id'] !== '26334'
        || rhCategoryPath(26334, $categories) !== '瑞昊采购部 / 易耗品') {
        throw new RuntimeException('same-name platform donor changed');
    }
    $updates = [];
    foreach ($targets as $id => [$name, $old, $new]) {
        $row = $products[$id] ?? [];
        if ($row === [] || (int)$row['type'] !== 0 || (int)$row['relation_id'] !== 0
            || (int)$row['is_del'] !== 0 || (string)$row['store_name'] !== $name
            || (string)$row['cate_id'] !== $old) throw new RuntimeException('target product changed: ' . $id);
        if ($new !== '' && rhCategoryPath((int)$new, $categories) === '') {
            throw new RuntimeException('donor category no longer valid');
        }
        if ($old !== '' && rhCategoryPath((int)$old, $categories) !== '') {
            throw new RuntimeException('requested empty category is now valid: ' . $id);
        }
        $updates[] = ['id' => $id, 'name' => $name, 'before' => $old, 'after' => $new];
    }
    return $updates;
}

/** Reconstruct only completed, non-void sales lacking both immutable projections. */
function rhCardPlan(PDO $db, array $categories, bool $lockOrders = false): array
{
    if (rhCategoryPath(26332, $categories) !== '生美 / 卡项') {
        throw new RuntimeException('card component category changed');
    }
    // Historical category facts for this cohort carried no partner allocation.
    // A newly enabled partner rule makes zero-filled repair rows unsafe.
    $partnerConfig = $db->query("SELECT COUNT(*) FROM eb_cashier_v3_report_category_config
        WHERE tenant_id='0' AND enabled=1 AND category_id IN (26302,26332)")->fetchColumn();
    if ((int)$partnerConfig !== 0) throw new RuntimeException('card partner configuration needs manual review');
    $sql = "SELECT s.fact_id,s.tenant_id,s.organization_id,s.store_id,s.member_id,s.order_id,
        s.source_line_id,s.checkout_request_id,s.business_date,s.occurred_at,s.settled_at,
        s.business_event_no,s.command_idempotency_key,s.sale_amount_cents,s.debt_amount_cents,
        s.order_no_snapshot
        FROM eb_cashier_v3_sale_fact s
        WHERE s.tenant_id='0' AND s.source_type='card' AND s.fact_direction='forward'
          AND s.status='effective'
          AND NOT EXISTS (SELECT 1 FROM eb_cashier_v3_order_lifecycle_operation o
              WHERE o.tenant_id=s.tenant_id AND o.source_order_id=s.order_id
                AND o.operation_type='void' AND o.status='succeeded')
          AND NOT EXISTS (SELECT 1 FROM eb_cashier_v3_card_sale_category_allocation_fact a
              WHERE a.tenant_id=s.tenant_id AND a.sale_fact_id=s.fact_id)
          AND NOT EXISTS (SELECT 1 FROM eb_cashier_v3_card_sale_item_allocation_fact i
              WHERE i.tenant_id=s.tenant_id AND i.sale_fact_id=s.fact_id)
        ORDER BY s.business_date,s.fact_id";
    $sales = $db->query($sql)->fetchAll();
    if (count($sales) !== 17) throw new RuntimeException('expected 17 missing active card sales; found ' . count($sales));
    $orders = array_values(array_unique(array_column($sales, 'order_id')));
    $holders = implode(',', array_fill(0, count($orders), '?'));
    if ($lockOrders) {
        // Lifecycle operations lock the source order; serialize against a new void.
        $orderLock = $db->prepare("SELECT order_id FROM eb_cashier_v3_sales_order
            WHERE tenant_id='0' AND order_id IN ($holders) ORDER BY order_id FOR UPDATE");
        $orderLock->execute($orders);
        if (count($orderLock->fetchAll()) !== count($orders)) {
            throw new RuntimeException('source order missing before repair');
        }
        $voidCheck = $db->prepare("SELECT operation_id FROM eb_cashier_v3_order_lifecycle_operation
            WHERE tenant_id='0' AND operation_type='void' AND status='succeeded'
              AND source_order_id IN ($holders) FOR UPDATE");
        $voidCheck->execute($orders);
        if ($voidCheck->fetchColumn() !== false) throw new RuntimeException('source order voided during repair');
    }
    // A prior refund needs its own reversal reconstruction; do not backfill a
    // forward fact in isolation when the order has a completed refund.
    $refundSql = "SELECT operation_id FROM eb_cashier_v3_order_lifecycle_operation
        WHERE tenant_id='0' AND operation_type='refund' AND status='succeeded'
          AND source_order_id IN ($holders)" . ($lockOrders ? ' FOR UPDATE' : '');
    $refundCheck = $db->prepare($refundSql);
    $refundCheck->execute($orders);
    if ($refundCheck->fetchColumn() !== false) throw new RuntimeException('refunded card sale needs separate review');
    $receiptsQuery = $db->prepare("SELECT receipt_id,issue_no,sales_order_line_id,
        command_idempotency_key,result_snapshot_json FROM eb_cashier_v3_card_purchase_receipt
        WHERE tenant_id=? AND sales_order_id=? AND sales_order_line_id=? AND status='completed'
        ORDER BY issue_no,receipt_id");
    $cashQuery = $db->prepare("SELECT COALESCE(SUM(amount_cents),0) FROM eb_cashier_v3_payment_sale_allocation_fact
        WHERE tenant_id=? AND sale_fact_id=? AND checkout_request_id=?
          AND fact_direction='forward' AND status='effective'");
    $productQuery = $db->prepare('SELECT id,pid,type,relation_id,cate_id FROM eb_store_product WHERE id=?');
    $plans = [];
    foreach ($sales as $sale) {
        $saleId = (string)$sale['fact_id'];
        $receiptsQuery->execute([$sale['tenant_id'], $sale['order_id'], $sale['source_line_id']]);
        $receipts = $receiptsQuery->fetchAll();
        if ($receipts === []) throw new RuntimeException('completed receipt missing: ' . $saleId);
        $cashQuery->execute([$sale['tenant_id'], $saleId, $sale['checkout_request_id']]);
        $initialCash = (int)$cashQuery->fetchColumn();
        if ($initialCash < 0 || $initialCash !== (int)$sale['sale_amount_cents'] - (int)$sale['debt_amount_cents']) {
            throw new RuntimeException('initial cash/debt reconciliation failed: ' . $saleId);
        }
        $receiptWeights = [];
        $decodedReceipts = [];
        foreach ($receipts as $receipt) {
            $receiptId = (string)$receipt['receipt_id'];
            $snapshot = json_decode((string)$receipt['result_snapshot_json'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($snapshot) || (string)($snapshot['receiptId'] ?? '') !== $receiptId
                || (string)($snapshot['salesOrderLineId'] ?? '') !== (string)$sale['source_line_id']
                || (int)($snapshot['issueNo'] ?? 0) !== (int)$receipt['issue_no']
                || (string)$receipt['command_idempotency_key'] !== (string)$sale['command_idempotency_key']
                || !is_array($snapshot['reportCategoryComponents'] ?? null)
                || $snapshot['reportCategoryComponents'] === []) {
                throw new RuntimeException('receipt snapshot changed: ' . $receiptId);
            }
            $items = [];
            $firstProductId = 0;
            foreach ($snapshot['reportCategoryComponents'] as $component) {
                if (!is_array($component)) throw new RuntimeException('invalid receipt component');
                $productId = (int)($component['productId'] ?? 0);
                $categoryId = (int)($component['categoryIdSnapshot'] ?? 0);
                $name = trim((string)($component['projectNameSnapshot'] ?? ''));
                $categoryName = trim((string)($component['categoryNameSnapshot'] ?? ''));
                $weight = (int)($component['allocationWeightCents'] ?? -1);
                $count = (int)($component['componentCount'] ?? -1);
                if ($productId <= 0 || $categoryId !== 26332 || $categoryName !== '卡项'
                    || $name === '' || $weight < 0 || $count < 0) {
                    throw new RuntimeException('receipt component cannot be safely classified: ' . $receiptId);
                }
                if ($firstProductId === 0) $firstProductId = $productId;
                $productQuery->execute([$productId]);
                $product = $productQuery->fetch() ?: [];
                $productQuery->execute([(int)($product['pid'] ?? 0)]);
                $platform = $productQuery->fetch() ?: [];
                if ((int)($product['type'] ?? -1) !== 1
                    || (int)($product['relation_id'] ?? 0) !== (int)$sale['store_id']
                    || (int)($platform['type'] ?? -1) !== 0
                    || (int)($platform['relation_id'] ?? -1) !== 0
                    || (string)($platform['cate_id'] ?? '') !== '26332') {
                    throw new RuntimeException('platform product link changed: ' . $productId);
                }
                $key = 'project:' . $productId;
                if (isset($items[$key])
                    && ($items[$key]['name'] !== $name || $items[$key]['category_name'] !== $categoryName)) {
                    throw new RuntimeException('duplicate project snapshot conflict');
                }
                if (!isset($items[$key])) $items[$key] = [
                    'product_id' => $productId, 'name' => $name, 'category_id' => $categoryId,
                    'category_name' => $categoryName, 'count' => 0, 'weight' => 0,
                ];
                $items[$key]['count'] += $count;
                $items[$key]['weight'] += $weight;
            }
            ksort($items, SORT_STRING);
            if (array_sum(array_column($items, 'weight')) <= 0) {
                throw new RuntimeException('receipt has no positive allocation weight');
            }
            $receiptWeights[$receiptId] = 1;
            $decodedReceipts[] = ['id' => $receiptId, 'issue_no' => (int)$receipt['issue_no'],
                'first_product_id' => $firstProductId, 'items' => $items];
        }
        $saleByReceipt = rhAllocate((int)$sale['sale_amount_cents'], $receiptWeights);
        $cashByReceipt = rhAllocate($initialCash, $receiptWeights);
        $categoryRows = [];
        $itemRows = [];
        foreach ($decodedReceipts as $receipt) {
            $receiptId = $receipt['id'];
            $itemWeights = array_map(static fn(array $item): int => $item['weight'], $receipt['items']);
            $saleByItem = rhAllocate($saleByReceipt[$receiptId], $itemWeights);
            $cashByItem = rhAllocate($cashByReceipt[$receiptId], $itemWeights);
            foreach ($receipt['items'] as $key => $item) {
                $itemRows[] = $item + [
                    'receipt_id' => $receiptId, 'issue_no' => $receipt['issue_no'],
                    'sale_cents' => $saleByItem[$key], 'cash_cents' => $cashByItem[$key],
                ];
            }
            // The writer groups categories before item sorting, so retain receipt order.
            $categoryRows[] = [
                'receipt_id' => $receiptId, 'issue_no' => $receipt['issue_no'],
                'product_id' => $receipt['first_product_id'], 'category_id' => 26332,
                'category_name' => '卡项', 'weight' => array_sum($itemWeights),
                'sale_cents' => $saleByReceipt[$receiptId], 'cash_cents' => $cashByReceipt[$receiptId],
            ];
        }
        if (array_sum(array_column($categoryRows, 'sale_cents')) !== (int)$sale['sale_amount_cents']
            || array_sum(array_column($categoryRows, 'cash_cents')) !== $initialCash
            || array_sum(array_column($itemRows, 'sale_cents')) !== (int)$sale['sale_amount_cents']
            || array_sum(array_column($itemRows, 'cash_cents')) !== $initialCash) {
            throw new RuntimeException('allocated totals do not reconcile: ' . $saleId);
        }
        $plans[] = ['sale' => $sale, 'initial_cash_cents' => $initialCash,
            'categories' => $categoryRows, 'items' => $itemRows];
    }
    return $plans;
}

/** Preview and execute share the exact, re-read plan; the hash binds product and money decisions. */
function rhPlan(PDO $db, bool $lockOrders = false): array
{
    $categories = [];
    foreach ($db->query('SELECT id,pid,cate_name FROM eb_store_product_category
        WHERE type=0 AND relation_id=0 ORDER BY id') as $row) $categories[(int)$row['id']] = $row;
    return ['products' => rhProductPlan($db, $categories), 'cards' => rhCardPlan($db, $categories, $lockOrders)];
}

function rhInsert(PDO $db, string $table, array $row): void
{
    $fields = array_keys($row);
    $sql = 'INSERT INTO ' . $table . ' (' . implode(',', $fields) . ') VALUES ('
        . implode(',', array_fill(0, count($fields), '?')) . ')';
    $db->prepare($sql)->execute(array_values($row));
}

/** Preserve original business time; the repair's recorded time is the actual insertion time. */
function rhFactBase(array $sale, array $allocation, string $kind): array
{
    $category = $kind === 'category';
    $suffix = $category ? (string)$allocation['category_id'] : (string)$allocation['product_id'];
    $natural = ($category ? 'card-sale-category:' : 'card-sale-item:')
        . $sale['fact_id'] . ':' . $allocation['issue_no'] . ':' . $suffix;
    return [
        'allocation_fact_id' => ($category ? 'CSCA-' : 'CSIA-')
            . strtoupper(substr(hash('sha256', $natural), 0, 40)),
        'natural_key' => $natural,
        'contract_version' => $category ? 'cashier-v3-card-sale-category-allocation-v1'
            : 'cashier-v3-card-sale-item-allocation-v1',
        'fact_version' => 1, 'status' => 'effective', 'reversal_of' => '',
        'tenant_id' => $sale['tenant_id'], 'organization_id' => $sale['organization_id'],
        'store_id' => (int)$sale['store_id'], 'member_id' => (int)$sale['member_id'],
        'order_id' => $sale['order_id'], 'sale_fact_id' => $sale['fact_id'],
        'source_line_id' => $sale['source_line_id'], 'card_receipt_id' => $allocation['receipt_id'],
        'card_issue_no' => (int)$allocation['issue_no'],
        'component_product_id' => (int)$allocation['product_id'],
    ];
}

function rhAudit(PDO $db, string $kind, string $id, array $before, array $after,
    string $hash, string $backup, int $now): void
{
    rhInsert($db, 'mig_rh_20261013_category_and_card_repair', [
        'repair_kind' => $kind, 'source_id' => $id,
        'before_json' => json_encode($before, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        'after_json' => json_encode($after, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        'evidence_hash' => $hash, 'backup_ref' => $backup, 'applied_at' => $now,
    ]);
}

function rhApply(PDO $db, array $plan, string $hash, string $backup): array
{
    $now = time();
    $productUpdate = $db->prepare('UPDATE eb_store_product SET cate_id=? WHERE id=? AND cate_id=?
        AND type=0 AND relation_id=0 AND is_del=0');
    foreach ($plan['products'] as $product) {
        $productUpdate->execute([$product['after'], $product['id'], $product['before']]);
        if ($productUpdate->rowCount() !== 1) throw new RuntimeException('product update conflict');
        rhAudit($db, 'platform_product', (string)$product['id'],
            ['cate_id' => $product['before']], ['cate_id' => $product['after']], $hash, $backup, $now);
    }
    $categoryTable = 'eb_cashier_v3_card_sale_category_allocation_fact';
    $itemTable = 'eb_cashier_v3_card_sale_item_allocation_fact';
    $categoryCount = 0;
    $itemCount = 0;
    foreach ($plan['cards'] as $entry) {
        $sale = $entry['sale'];
        $categoryKeys = [];
        $itemKeys = [];
        foreach ($entry['categories'] as $allocation) {
            $row = rhFactBase($sale, $allocation, 'category') + [
                'category_id_snapshot' => 26332, 'category_name_snapshot' => '卡项',
                'category_path_snapshot' => '生美 / 卡项',
                'partner_name_snapshot' => '', 'partner_category_id_snapshot' => 0,
                'partner_category_name_snapshot' => '', 'partner_category_path_snapshot' => '',
                'partner_default_ratio_snapshot' => 0, 'partner_config_version_snapshot' => 0,
                'partner_share_amount_cents' => 0, 'product_type_snapshot' => 'project',
                'component_count' => 1,
                'configured_amount_cents' => (int)$allocation['weight'],
                'sale_amount_cents' => (int)$allocation['sale_cents'],
                'cash_performance_amount_cents' => (int)$allocation['cash_cents'],
                'business_date' => $sale['business_date'],
                'occurred_at' => (int)$sale['occurred_at'], 'settled_at' => (int)$sale['settled_at'],
                'recorded_at' => $now, 'business_event_no' => $sale['business_event_no'],
                'command_idempotency_key' => $sale['command_idempotency_key'],
            ];
            $row['immutable_fingerprint'] = hash('sha256', json_encode($row,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $row['add_time'] = $now;
            $row['update_time'] = $now;
            rhInsert($db, $categoryTable, $row);
            $categoryKeys[] = $row['natural_key'];
            $categoryCount++;
        }
        foreach ($entry['items'] as $allocation) {
            $row = rhFactBase($sale, $allocation, 'item') + [
                'item_name_snapshot' => $allocation['name'],
                'component_count' => (int)$allocation['count'],
                'category_id_snapshot' => 26332, 'category_name_snapshot' => '卡项',
                'category_path_snapshot' => '生美 / 卡项',
                'configured_amount_cents' => (int)$allocation['weight'],
                'sale_amount_cents' => (int)$allocation['sale_cents'],
                'cash_performance_amount_cents' => (int)$allocation['cash_cents'],
                'business_date' => $sale['business_date'],
                'occurred_at' => (int)$sale['occurred_at'], 'settled_at' => (int)$sale['settled_at'],
                'recorded_at' => $now, 'business_event_no' => $sale['business_event_no'],
                'command_idempotency_key' => $sale['command_idempotency_key'],
            ];
            $row['immutable_fingerprint'] = hash('sha256', json_encode($row,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $row['add_time'] = $now;
            $row['update_time'] = $now;
            rhInsert($db, $itemTable, $row);
            $itemKeys[] = $row['natural_key'];
            $itemCount++;
        }
        rhAudit($db, 'card_sale', (string)$sale['fact_id'],
            ['category_facts' => 0, 'item_facts' => 0],
            ['category_facts' => count($entry['categories']), 'item_facts' => count($entry['items']),
                'sale_cents' => (int)$sale['sale_amount_cents'],
                'initial_cash_cents' => $entry['initial_cash_cents'],
                'category_natural_keys' => $categoryKeys, 'item_natural_keys' => $itemKeys],
            $hash, $backup, $now);
    }
    return ['products' => count($plan['products']), 'card_sales' => count($plan['cards']),
        'category_facts' => $categoryCount, 'item_facts' => $itemCount];
}

function rhMain(array $args): void
{
    $execute = in_array('--execute', $args, true);
    $expect = rhOption($args, '--expect');
    $backup = trim(rhOption($args, '--backup-ref'));
    if ($execute && (!in_array('--allow-ruihao', $args, true)
        || !preg_match('/^[a-f0-9]{64}$/D', $expect) || $backup === '')) {
        throw new RuntimeException('execution requires approved preview hash and backup reference');
    }
    $config = parse_ini_file(getenv('RH_ENV_FILE') ?: '/www/wwwroot/rh.cc3798.com/.env',
        true, INI_SCANNER_RAW)['DATABASE'] ?? [];
    if (($config['DATABASE'] ?? '') !== 'ruihao') throw new RuntimeException('expected RH database');
    $db = new PDO('mysql:host=' . $config['HOSTNAME'] . ';port=' . $config['HOSTPORT']
        . ';dbname=ruihao;charset=utf8mb4', $config['USERNAME'], $config['PASSWORD'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $plan = rhPlan($db);
    $hash = hash('sha256', json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    $preview = ['mode' => $execute ? 'preflight' : 'dry-run', 'evidence_hash' => $hash,
        'products' => $plan['products'],
        'cards' => array_map(static fn(array $entry): array => [
            'order_no' => $entry['sale']['order_no_snapshot'],
            'sale_fact_id' => $entry['sale']['fact_id'],
            'sale_cents' => (int)$entry['sale']['sale_amount_cents'],
            'initial_cash_cents' => $entry['initial_cash_cents'],
            'category_facts' => count($entry['categories']), 'item_facts' => count($entry['items']),
        ], $plan['cards'])];
    echo json_encode($preview, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
    if (!$execute) return;
    if (!hash_equals($expect, $hash)) throw new RuntimeException('approved preview changed');
    $lock = $db->prepare('SELECT GET_LOCK(?,0)');
    $lock->execute(['rh_20261013_category_and_card_repair']);
    if ((int)$lock->fetchColumn() !== 1) throw new RuntimeException('another repair is running');
    try {
        $db->beginTransaction();
        $locked = rhPlan($db, true);
        if (!hash_equals($hash, hash('sha256', json_encode($locked, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)))) {
            throw new RuntimeException('sources changed after preview');
        }
        $result = rhApply($db, $locked, $hash, $backup);
        $db->commit();
        echo json_encode(['applied' => $result, 'evidence_hash' => $hash], JSON_UNESCAPED_UNICODE), PHP_EOL;
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    } finally {
        $release = $db->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute(['rh_20261013_category_and_card_repair']);
    }
}

if (getenv('RH_REPAIR_LIB_ONLY') !== '1') rhMain(array_slice($argv, 1));
