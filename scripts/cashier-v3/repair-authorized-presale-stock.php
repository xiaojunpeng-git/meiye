<?php
declare(strict_types=1);

use app\services\cashier\v3\settlement\CashierV3PresaleStockRepairServices;
use think\facade\Db;

/**
 * Explicit-instance maintenance CLI: scan is read-only; preview always rolls
 * back; apply requires the frozen scan hash and a private, new audit directory.
 * Never hard-delete facts or mutate sale/payment snapshots. No HTTP entry.
 */
$backend = realpath(getenv('BACKEND_ROOT') ?: '');
$mode = $argv[1] ?? 'scan';
$inTransaction = false;
try {
    if (PHP_SAPI !== 'cli' || !$backend || !in_array($mode, ['scan', 'preview', 'apply'], true)) {
        throw new RuntimeException('explicit_backend_and_mode_required');
    }
    require $backend . '/vendor/autoload.php';
    chdir($backend);
    (new \think\App($backend . '/'))->initialize();
    $config = config('database.connections.mysql');
    // Caller must explicitly bind host, application root and database. This is
    // an operational allowlist, not a client-controlled business permission.
    if (getenv('PRESALE_REPAIR_HOST') !== gethostname()
        || realpath(getenv('PRESALE_REPAIR_ROOT') ?: '') !== $backend
        || getenv('PRESALE_REPAIR_DATABASE') !== ($config['database'] ?? '')) {
        throw new RuntimeException('instance_guard_failed');
    }
    $encode = static function ($value): string {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return $json;
    };
    $candidates = static function (bool $lock = false): array {
        // Only formal presale checkout mistakes; true claims and ordinary sale
        // allocations never enter this set. Include status for fail-closed review.
        $query = Db::name('inventory_batch_movement_fact')->alias('f')
            ->join('cashier_v3_sales_order_line l', 'l.tenant_id=f.tenant_id AND l.store_id=f.store_id AND l.order_id=f.source_id AND l.order_line_id=f.source_detail_id')
            ->join('cashier_v3_sales_order o', 'o.tenant_id=l.tenant_id AND o.store_id=l.store_id AND o.order_id=l.order_id')
            ->where('f.source_type', 'cashier_sale')->where('f.direction', -1)
            ->where('f.reversal_of', 0)->where('f.fact_status', 'SETTLED')
            ->where('l.item_type', 'product')->where('l.is_presale', 1)->where('l.inventory_outbound_required', 0)
            ->field('f.*,o.order_no,o.order_status,o.order_direction,l.line_status,l.line_direction,l.quantity line_quantity,l.item_id,l.catalog_sku_id')
            ->order('f.store_id,f.source_id,f.id');
        if ($lock) $query->lock(true);
        return $query->select()->toArray();
    };
    if ($mode === 'scan') {
        Db::execute('SET TRANSACTION READ ONLY');
        Db::startTrans(); $inTransaction = true;
        $entries = $candidates();
        $plan = ['version' => 1, 'host' => gethostname(), 'backend' => $backend,
            'database' => $config['database'], 'entries' => $entries,
            'entries_sha256' => hash('sha256', $encode($entries))];
        Db::rollback(); $inTransaction = false;
        echo $encode($plan) . PHP_EOL;
        exit(0);
    }
    $planPath = $argv[2] ?? '';
    $plan = json_decode((string)file_get_contents($planPath), true, 512, JSON_THROW_ON_ERROR);
    if (($plan['version'] ?? null) !== 1 || ($plan['host'] ?? '') !== gethostname()
        || ($plan['backend'] ?? '') !== $backend || ($plan['database'] ?? '') !== $config['database']
        || !hash_equals((string)($plan['entries_sha256'] ?? ''), hash('sha256', $encode($plan['entries'] ?? [])))
        || getenv('PRESALE_REPAIR_PLAN_SHA256') !== hash_file('sha256', $planPath)) {
        throw new RuntimeException('frozen_plan_required');
    }
    $evidence = getenv('PRESALE_REPAIR_EVIDENCE') ?: '';
    if ($mode === 'apply' && ($evidence === '' || !is_dir($evidence)
        || (fileperms($evidence) & 0077) !== 0 || glob($evidence . '/*'))) {
        throw new RuntimeException('new_private_evidence_directory_required');
    }
    Db::startTrans(); $inTransaction = true;
    // One small frozen batch is atomic. Lock formal sources before inventory
    // facts, following the lifecycle writer order; concurrent changes abort.
    $orders = [];
    foreach ($plan['entries'] as $entry) {
        if ($entry['order_status'] !== 'settled' || $entry['order_direction'] !== 'forward'
            || $entry['line_status'] !== 'settled' || $entry['line_direction'] !== 'forward') {
            throw new RuntimeException('unsettled_or_voided_source_requires_separate_review');
        }
        $orders[$entry['tenant_id'] . ':' . $entry['store_id'] . ':' . $entry['source_id']] = $entry;
    }
    ksort($orders);
    foreach ($orders as $entry) {
        Db::name('cashier_v3_sales_order')->where('tenant_id', $entry['tenant_id'])
            ->where('store_id', $entry['store_id'])->where('order_id', $entry['source_id'])->lock(true)->find();
        Db::name('cashier_v3_sale_inventory_receipt')->where('tenant_id', $entry['tenant_id'])
            ->where('sales_order_id', $entry['source_id'])->lock(true)->find();
    }
    if ($encode($candidates(true)) !== $encode($plan['entries'])) throw new RuntimeException('live_candidates_changed_rescan_required');
    // Freeze full stock/batch before-images under the same locks as the writer.
    // Without these locks a concurrent normal sale could make the backup stale.
    $stockIds = []; $batchIds = [];
    foreach ($orders as $entry) {
        $facts = Db::name('inventory_batch_movement_fact')->where('tenant_id', $entry['tenant_id'])
            ->where('source_id', $entry['source_id'])->order('id')->lock(true)->select()->toArray();
        $stockIds = array_merge($stockIds, array_column($facts, 'stock_id'));
        $batchIds = array_merge($batchIds, array_column($facts, 'batch_id'));
    }
    if ($stockIds) Db::name('inventory_stock')->whereIn('id', array_unique($stockIds))->order('id')->lock(true)->select();
    if ($batchIds) Db::name('inventory_batch')->whereIn('id', array_unique($batchIds))->order('id')->lock(true)->select();
    $snapshot = static function () use ($orders): array {
        $out = [];
        foreach ($orders as $key => $entry) {
            $tenant = $entry['tenant_id']; $order = $entry['source_id'];
            $facts = Db::name('inventory_batch_movement_fact')->where('tenant_id', $tenant)->where('source_id', $order)->order('id')->select()->toArray();
            $stockIds = array_unique(array_column($facts, 'stock_id'));
            $batchIds = array_unique(array_column($facts, 'batch_id'));
            $out[$key] = [
                // Full before-images stay in a private instance backup only;
                // console output contains no customer/payment personal data.
                'order' => Db::name('cashier_v3_sales_order')->where('tenant_id', $tenant)->where('order_id', $order)->find(),
                'lines' => Db::name('cashier_v3_sales_order_line')->where('tenant_id', $tenant)->where('order_id', $order)->order('id')->select()->toArray(),
                'receipt' => Db::name('cashier_v3_sale_inventory_receipt')->where('tenant_id', $tenant)->where('sales_order_id', $order)->find(),
                'facts' => $facts,
                'stocks' => Db::name('inventory_stock')->whereIn('id', $stockIds)->order('id')->select()->toArray(),
                'batches' => Db::name('inventory_batch')->whereIn('id', $batchIds)->order('id')->select()->toArray(),
            ];
        }
        return $out;
    };
    $before = $snapshot();
    $write = static function (string $path, string $json): void {
        $handle = fopen($path, 'x');
        if (!$handle) throw new RuntimeException('audit_file_creation_failed');
        chmod($path, 0600);
        $written = fwrite($handle, $json);
        $flushed = fflush($handle); fclose($handle);
        if ($written !== strlen($json) || !$flushed) throw new RuntimeException('audit_snapshot_write_failed');
    };
    if ($mode === 'apply') $write($evidence . '/before.json', $encode($before));
    $results = [];
    foreach ($orders as $key => $entry) {
        $results[$key] = (new CashierV3PresaleStockRepairServices())->repairInTx(
            $entry['tenant_id'], (int)$entry['store_id'], $entry['source_id'],
            'R51 第6轮：产品经理授权纠正预售结账误扣；按冻结计划恢复原批次库存，保留销售和原扣库审计。', time());
    }
    $after = $snapshot();
    foreach ($before as $key => $original) {
        foreach (['order', 'lines', 'receipt'] as $field) {
            if ($encode($original[$field]) !== $encode($after[$key][$field])) throw new RuntimeException('source_snapshot_changed');
        }
        $afterFacts = array_column($after[$key]['facts'], null, 'id');
        foreach ($original['facts'] as $fact) {
            if ($encode($fact) !== $encode($afterFacts[$fact['id']] ?? null)) throw new RuntimeException('original_fact_changed');
        }
        // Explicitly prove stock and every touched batch still reconcile after repair.
        foreach (['stocks' => 'stock_id', 'batches' => 'batch_id'] as $kind => $field) {
            foreach ($after[$key][$kind] as $row) {
                $net = Db::name('inventory_batch_movement_fact')->where($field, $row['id'])->where('fact_status', 'SETTLED')
                    ->fieldRaw('SUM(IF(direction=1,quantity_units,-CAST(quantity_units AS SIGNED))) balance')->find();
                if ((int)$net['balance'] !== (int)$row['available_quantity_units']) throw new RuntimeException('post_repair_ledger_mismatch');
            }
        }
    }
    if ($mode === 'apply') {
        $write($evidence . '/after-before-commit.json', $encode(['after' => $after, 'results' => $results]));
        Db::commit(); $inTransaction = false;
        $write($evidence . '/committed.json', $encode(['committed_at' => date('c'), 'results' => $results]));
    } else {
        Db::rollback(); $inTransaction = false;
    }
    echo $encode(['mode' => $mode, 'results' => array_values($results), 'source_snapshots_unchanged' => true]) . PHP_EOL;
} catch (Throwable $error) {
    if ($inTransaction) Db::rollback();
    file_put_contents('php://stderr', $error->getMessage() . PHP_EOL);
    exit(1);
}
