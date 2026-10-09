<?php
declare(strict_types=1);

/**
 * Local-only, single-order repair authorized in round 6. Default rolls back.
 * --apply requires an evidence path and writes a before snapshot before commit.
 * This is deliberately not a production migration or a bulk repair command.
 */
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 2) . '/后端代码';
require $backend . '/vendor/autoload.php';

use app\services\cashier\v3\settlement\CashierV3PresaleStockRepairServices;
use think\facade\Db;

try {
    (new \think\App($backend . '/'))->initialize();
    $config = config('database.connections.mysql');
    if (PHP_SAPI !== 'cli' || ($config['hostname'] ?? '') !== 'mysql' || ($config['database'] ?? '') !== 'ruihao'
        || !is_file('/.dockerenv')) throw new RuntimeException('local_container_database_required');
    $apply = in_array('--apply', $argv, true);
    $evidence = getenv('PRESALE_REPAIR_EVIDENCE') ?: '';
    if ($apply && ($evidence === '' || file_exists($evidence))) throw new RuntimeException('new_evidence_path_required');
    Db::startTrans();
    $orderId = 'CSO-13a9cb70037ed6777316e8ed11b10b8138848b55';
    $order = Db::name('cashier_v3_sales_order')->where('tenant_id', '0')->where('store_id', 126)
        ->where('order_id', $orderId)->lock(true)->find();
    if (!$order || $order['order_no'] !== 'XS26100900006') throw new RuntimeException('exact_order_required');
    $snapshot = static function () use ($orderId): array {
        return [
            'orderId' => $orderId, 'orderNo' => 'XS26100900006', 'storeId' => 126,
            'stock' => Db::name('inventory_stock')->where('id', 6551)->find(),
            'batch' => Db::name('inventory_batch')->where('id', 6066)->find(),
            'movements' => Db::name('inventory_batch_movement_fact')->where('tenant_id', '0')->where('source_id', $orderId)->order('id')->select()->toArray(),
        ];
    };
    $before = $snapshot();
    $target = Db::name('inventory_batch_movement_fact')->where('id', 21719)->find();
    if (!$target || $target['source_id'] !== $orderId || (int)$target['stock_id'] !== 6551
        || (int)$target['batch_id'] !== 6066 || (int)$target['quantity_units'] !== 3
        || $target['source_detail_id'] !== 'CSL-d3cd7c3e269d16678bd19b2458c283e377a9a8d3') {
        throw new RuntimeException('frozen_repair_target_changed');
    }
    if ($apply && file_put_contents($evidence, json_encode(['before' => $before], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
        throw new RuntimeException('before_evidence_write_failed');
    }
    $result = (new CashierV3PresaleStockRepairServices())->repairInTx('0', 126, $orderId,
        '第6轮本地测试单：纠正同规格普通购买与预售混合结账误扣；仅退回预售3盒，原扣库事实保留。', time());
    $after = $snapshot();
    if ($result['restoredQuantityUnits'] + $result['replayedQuantityUnits'] !== 3
        || (int)$after['stock']['available_quantity_units'] !== 33
        || (int)$after['batch']['available_quantity_units'] !== 33) throw new RuntimeException('repair_postcheck_failed');
    if ($apply) {
        $bytes = file_put_contents($evidence, json_encode(['before' => $before, 'after' => $after, 'result' => $result], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        if ($bytes === false) throw new RuntimeException('after_evidence_write_failed');
        Db::commit();
    } else {
        Db::rollback();
    }
    echo json_encode(['mode' => $apply ? 'applied' : 'rollback-preview', 'result' => $result,
        'stockBefore' => $before['stock']['available_quantity_units'], 'stockAfter' => $after['stock']['available_quantity_units'],
        'evidence' => $evidence], JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $error) {
    if (isset($config)) Db::rollback();
    file_put_contents('php://stderr', $error->getMessage() . PHP_EOL);
    exit(1);
}
