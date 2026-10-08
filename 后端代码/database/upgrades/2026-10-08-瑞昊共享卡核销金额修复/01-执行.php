<?php
declare(strict_types=1);

/**
 * R32 瑞昊共享次数卡历史核销更正。原始服务/核销事实不可覆盖；
 * 只对固定清单内仍生效、未被人工二次调整的服务追加业绩冲正与正确正向事实。
 * 默认只读，--apply 必须在独立备份完成后由人工执行。
 */
use think\facade\Db;

$mode = $argv[1] ?? '--dry-run';
if (!in_array($mode, ['--dry-run', '--test-rollback', '--apply'], true)) {
    throw new RuntimeException('Usage: php 01-执行.php [--dry-run|--test-rollback|--apply]');
}
$backend = dirname(__DIR__, 3);
require $backend . '/vendor/autoload.php';
(new \think\App($backend . '/'))->initialize();
if ((string)(Db::query('SELECT DATABASE() AS db')[0]['db'] ?? '') !== 'ruihao') {
    throw new RuntimeException('R32 repair is restricted to the ruihao database');
}

// 固定影响清单是生产只读盘点结果；任何新行或金额变化都必须重新审计，不能扩散执行范围。
$expected = [6604 => 7900, 7397 => 9900, 11262 => 6600, 11272 => 6600,
    11652 => 6600, 11653 => 6600, 12735 => 6600, 12760 => 6600,
    12778 => 6600, 14473 => 6600];
$voided = [14462, 14472];
$marker = 'SHARED-CARD-PURCHASE-REPAIR-R32';
$ids = array_merge(array_keys($expected), $voided);

/** @return array<string,mixed> */
function sourceFact(array $writeoff, string $type): array
{
    $rows = Db::name('cashier_v3_performance_fact')
        ->where('tenant_id', (string)$writeoff['tenant_id'])
        ->where('checkout_request_id', (string)$writeoff['checkout_request_id'])
        ->where('source_line_id', (string)$writeoff['source_line_id'])
        ->where('performance_type', $type)->where('status', 'effective')
        ->order('id', 'asc')->select()->toArray();
    if (count($rows) !== 1 || (string)$rows[0]['fact_direction'] !== 'forward') {
        throw new RuntimeException('Unexpected existing ' . $type . ' facts for writeoff ' . $writeoff['id']);
    }
    return $rows[0];
}

/** Copy the frozen source dimensions; only the event identity, direction and measured amount change. */
function insertCorrection(array $source, int $writeoffId, string $kind, string $direction,
    int $amount, int $now, string $marker): void
{
    $row = $source;
    unset($row['id']);
    $suffix = $kind . '-' . ($direction === 'reversal' ? 'R' : 'F');
    $row['fact_id'] = 'R32-' . $writeoffId . '-' . $suffix;
    $row['business_event_no'] = 'R32-SHARED-WF-' . $writeoffId;
    $row['fact_direction'] = $direction;
    $row['natural_key'] = 'r32_shared_purchase:' . $writeoffId . ':' . $suffix;
    $row['command_idempotency_key'] = 'r32_shared_purchase:' . $writeoffId;
    $row['fact_version'] = 1;
    $row['reversal_of'] = $direction === 'reversal' ? (string)$source['fact_id'] : '';
    $row['amount_cents'] = $amount;
    $row['allocation_base_amount_cents'] = $amount;
    $row['allocation_weight_numerator'] = $direction === 'forward' && (int)$source['employee_id'] > 0
        ? max(0, $amount) : (int)$source['allocation_weight_numerator'];
    $row['allocation_weight_denominator'] = $direction === 'forward' && (int)$source['employee_id'] > 0
        ? max(1, $amount) : (int)$source['allocation_weight_denominator'];
    $row['labor_fee_amount_cents'] = $direction === 'reversal'
        ? -(int)$source['labor_fee_amount_cents'] : (int)$source['labor_fee_amount_cents'];
    $row['project_count_half_units'] = $direction === 'reversal'
        ? -(int)$source['project_count_half_units'] : (int)$source['project_count_half_units'];
    if ($direction === 'reversal' && $source['project_count_decimal'] !== null) {
        $row['project_count_decimal'] = -(float)$source['project_count_decimal'];
    }
    $row['rule_code_snapshot'] = $marker;
    $row['rule_name_snapshot'] = 'R32共享次数卡购买金额历史更正';
    $row['rule_version_snapshot'] = 'r32';
    $row['occurred_at'] = $now;
    $row['settled_at'] = $now;
    $row['recorded_at'] = $now;
    $row['immutable_fingerprint'] = hash('sha256', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    if (Db::name('cashier_v3_performance_fact')->insert($row) !== 1) {
        throw new RuntimeException('Cannot insert ' . $row['fact_id']);
    }
}

Db::startTrans();
try {
    $rows = Db::name('cashier_v3_entitlement_writeoff_fact')->alias('wf')
        ->join('user_card_holder h', 'h.id=wf.holder_id')
        ->join('store_order o', 'o.id=h.oid')
        ->join('cashier_v3_card_rule_state rs', "rs.card_holder_id=wf.holder_id AND rs.rule_type='choice_count'")
        ->join('cashier_v3_entitlement_service_fact sf', 'sf.checkout_request_id=wf.checkout_request_id AND sf.source_line_id=wf.source_line_id')
        ->leftJoin('cashier_v3_service_record_void_operation vo', "vo.service_fact_id=sf.id AND vo.status='succeeded'")
        ->whereIn('wf.id', $ids)
        ->field('wf.*,o.pay_price,rs.shared_total_times,sf.id AS service_fact_id,sf.service_record_no,sf.service_status,vo.id AS void_id')
        ->order('wf.id', 'asc')->lock(true)->select()->toArray();
    if (count($rows) !== count($ids)) throw new RuntimeException('Fixed writeoff inventory changed');
    $seen = [];
    $plan = [];
    foreach ($rows as $row) {
        $id = (int)$row['id'];
        if (isset($seen[$id]) || (int)$row['quantity'] !== 1 || (string)$row['status'] !== 'effective'
            || (string)$row['service_status'] !== 'completed') {
            throw new RuntimeException('Unexpected writeoff state ' . $id);
        }
        $seen[$id] = true;
        if (in_array($id, $voided, true)) {
            if ((int)$row['void_id'] <= 0) throw new RuntimeException('Expected void missing ' . $id);
            continue;
        }
        if ((int)$row['void_id'] > 0) throw new RuntimeException('Active service is voided ' . $id);
        $price = (string)$row['pay_price'];
        if (preg_match('/^(?:0|[1-9][0-9]*)\.00$/D', $price) !== 1) {
            throw new RuntimeException('Card pay price is not whole yuan ' . $id);
        }
        $payCents = (int)substr($price, 0, -3) * 100;
        $times = (int)$row['shared_total_times'];
        $consumedBefore = (int)$row['consumed_times_before_snapshot'];
        if ($times <= 0 || $consumedBefore < 0 || $consumedBefore >= $times) {
            throw new RuntimeException('Invalid shared-count snapshot ' . $id);
        }
        $unit = intdiv(intdiv($payCents, 100), $times) * 100;
        $computed = $consumedBefore === $times - 1 ? $payCents - $unit * ($times - 1) : $unit;
        if ($computed !== $expected[$id]) throw new RuntimeException('Card authority changed ' . $id);
        $changed = Db::name('cashier_v3_service_record_adjustment_operation')
            ->where('service_fact_id', (int)$row['service_fact_id'])->where('status', 'succeeded')->count();
        if ((int)$changed !== 0) throw new RuntimeException('Manual service adjustment exists ' . $id);
        $consumption = sourceFact($row, 'consumption_performance_recorded');
        $labor = sourceFact($row, 'labor_performance_allocated');
        $old = (int)$row['actual_entitlement_amount_cents'];
        if ((int)$consumption['amount_cents'] !== $old) throw new RuntimeException('Consumption source mismatch ' . $id);
        $manualLabor = $id === 7397;
        if ($manualLabor ? (int)$labor['amount_cents'] !== 9900 : (int)$labor['amount_cents'] !== $old) {
            throw new RuntimeException('Labor source mismatch ' . $id);
        }
        $plan[] = ['id' => $id, 'service' => $row['service_record_no'], 'old' => $old,
            'new' => $computed, 'manual_labor_kept' => $manualLabor,
            'consumption' => $consumption, 'labor' => $labor];
    }
    $existing = (int)Db::name('cashier_v3_performance_fact')->where('rule_code_snapshot', $marker)
        ->where('business_event_no', 'like', 'R32-SHARED-WF-%')->count();
    if ($existing !== 0) throw new RuntimeException('R32 correction facts already exist; refusing replay');
    $delta = 0;
    foreach ($plan as $entry) {
        $delta += $entry['new'] - $entry['old'];
        echo $entry['id'] . ' ' . $entry['service'] . ' ' . $entry['old'] . ' -> ' . $entry['new']
            . ($entry['manual_labor_kept'] ? ' labor=manual-kept' : ' labor=corrected') . PHP_EOL;
    }
    echo 'active=' . count($plan) . ' voided=' . count($voided) . ' consumption_delta_cents=' . $delta . PHP_EOL;
    if ($mode !== '--dry-run') {
        $now = time();
        foreach ($plan as $entry) {
            insertCorrection($entry['consumption'], $entry['id'], 'C', 'reversal', -$entry['old'], $now, $marker);
            insertCorrection($entry['consumption'], $entry['id'], 'C', 'forward', $entry['new'], $now, $marker);
            if (!$entry['manual_labor_kept']) {
                insertCorrection($entry['labor'], $entry['id'], 'L', 'reversal', -$entry['old'], $now, $marker);
                insertCorrection($entry['labor'], $entry['id'], 'L', 'forward', $entry['new'], $now, $marker);
            }
        }
        $written = (int)Db::name('cashier_v3_performance_fact')->where('rule_code_snapshot', $marker)
            ->where('business_event_no', 'like', 'R32-SHARED-WF-%')->count();
        if ($written !== 38) throw new RuntimeException('Unexpected correction fact count ' . $written);
        foreach ($plan as $entry) {
            $source = $entry['consumption'];
            $base = Db::name('cashier_v3_performance_fact')
                ->where('tenant_id', (string)$source['tenant_id'])
                ->where('checkout_request_id', (string)$source['checkout_request_id'])
                ->where('source_line_id', (string)$source['source_line_id'])->where('status', 'effective');
            $consumptionNet = (int)(clone $base)->where('performance_type', 'consumption_performance_recorded')->sum('amount_cents');
            $laborNet = (int)(clone $base)->where('performance_type', 'labor_performance_allocated')->sum('amount_cents');
            if ($consumptionNet !== $entry['new']
                || $laborNet !== ($entry['manual_labor_kept'] ? 9900 : $entry['new'])) {
                throw new RuntimeException('Post-write net amount mismatch ' . $entry['id']);
            }
        }
        if ($mode === '--apply') {
            Db::commit();
            echo "R32_REPAIR_COMMITTED\n";
        } else {
            Db::rollback();
            echo "R32_WRITE_TEST_ROLLED_BACK\n";
        }
    } else {
        Db::rollback();
        echo "R32_DRY_RUN_ROLLED_BACK\n";
    }
} catch (\Throwable $error) {
    Db::rollback();
    throw $error;
}
