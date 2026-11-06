<?php

declare(strict_types=1);

/**
 * 010 全部历史旧核销中“已有唯一完成服务和员工事实、唯独缺消耗事实”的恢复候选。
 *
 * 原旧核销是金额来源；完成服务与员工事实是身份来源。跳过缺服务、映射冲突或
 * 真正 V3 作废的记录，绝不据订单名称猜测来源，也不修改现有收银事实。
 * 默认只读；写入必须附产品经理验收的固定计划指纹与独立备份目录。
 */

ini_set('memory_limit', '1024M');
$options = getopt('', ['apply', 'accepted-hash:', 'backup-dir:']);
$apply = array_key_exists('apply', $options);
require getcwd() . '/vendor/autoload.php';
(new \think\App())->initialize();

use app\services\query\metric\RegisteredMetricReadServices;
use think\facade\Db;

function assertRepair(bool $ok, string $code): void
{
    if (!$ok) throw new RuntimeException($code);
}

/** 历史事实指纹覆盖最终业务列，不含数据库自增 ID。 */
function mappedFingerprint(array $row): string
{
    unset($row['id'], $row['immutable_fingerprint']);
    ksort($row);
    return hash('sha256', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

/** 从同一完成服务的员工事实复制静态来源字段，再移除员工分摊语义。 */
function mappedConsumption(array $labor, array $service, array $writeoff, int $recordedAt): array
{
    $row = $labor;
    unset($row['id']);
    $id = (int)$writeoff['id'];
    $amount = (int)round((float)$writeoff['writeoff_price'] * 100);
    assertRepair($amount >= 0 && $amount % 100 === 0, 'SOURCE_AMOUNT_NOT_WHOLE_YUAN_' . $id);
    $row['fact_id'] = 'IMP007-W' . $id . '-consumption';
    $row['natural_key'] = $row['fact_id'];
    $row['command_idempotency_key'] = $row['fact_id'];
    $row['business_event_no'] = (string)$service['business_event_no'];
    $row['fact_type'] = 'performance_recorded';
    $row['fact_direction'] = 'forward';
    $row['reversal_of'] = '';
    $row['status'] = 'effective';
    $row['tenant_id'] = (string)$service['tenant_id'];
    $row['organization_id'] = (string)$service['organization_id'];
    $row['store_id'] = (int)$service['store_id'];
    $row['member_id'] = (int)$service['member_id'];
    $row['operator_id'] = (int)$service['operator_id'];
    $row['business_date'] = (string)$service['business_date'];
    $row['business_timezone'] = (string)$service['business_timezone'];
    $row['occurred_at'] = (int)$service['occurred_at'];
    $row['settled_at'] = (int)$service['settled_at'];
    $row['recorded_at'] = $recordedAt;
    $row['checkout_request_id'] = (string)$service['checkout_request_id'];
    $row['source_document_type'] = (string)$service['source_document_type'];
    $row['source_line_id'] = (string)$service['source_line_id'];
    $row['performance_type'] = 'consumption_performance_recorded';
    $row['employee_id'] = 0;
    $row['employee_name_snapshot'] = '';
    $row['employee_type_snapshot'] = '';
    $row['employee_type_authority_version'] = 0;
    $row['role_snapshot'] = '';
    $row['allocation_weight_numerator'] = 1;
    $row['allocation_weight_denominator'] = 1;
    $row['allocation_base_amount_cents'] = $amount;
    $row['amount_cents'] = $amount;
    $row['labor_fee_amount_cents'] = 0;
    $row['project_count_half_units'] = 0;
    $row['project_count_decimal'] = '0.000000';
    $row['rule_code_snapshot'] = 'legacy_consumption_recovery_mapped';
    $row['rule_name_snapshot'] = '历史核销消耗恢复';
    $row['rule_version_snapshot'] = 'legacy-restoration-v1';
    $row['immutable_fingerprint'] = mappedFingerprint($row);
    return $row;
}

/** 运行目标与数据库都固定为 010，其他客户只能另行做实例级验收。 */
$config = config('database.connections.mysql');
assertRepair(realpath(getcwd()) === '/www/wwwroot/010.cc3798.com'
    && (string)$config['database'] === 'mohe_010_app'
    && (string)$config['hostname'] === '127.0.0.1'
    && (string)$config['hostport'] === '3307', 'TARGET_NOT_010_APP');
Db::execute($apply ? 'START TRANSACTION' : 'START TRANSACTION READ ONLY');
try {
    $cooperation = array_fill_keys(array_map('intval', Db::name('store_product_relation')
        ->where('relation_id', 78)->where('type', 1)->column('product_id')), true);
    $recorded = [];
    foreach (Db::name('cashier_v3_performance_fact')->where('performance_type', 'consumption_performance_recorded')
        ->whereIn('source_document_type', ['legacy_writeoff', 'legacy_paid_project'])
        ->field('fact_id,status,amount_cents')->select()->toArray() as $row) {
        if (!preg_match('/^IMP007-W([0-9]+)-consumption(?:-historical-void)?$/D',
            (string)$row['fact_id'], $match)) continue;
        $id = (int)$match[1];
        $recorded[$id] ??= ['rows' => 0, 'cents' => 0];
        if ($row['status'] === 'effective') {
            $recorded[$id]['rows']++;
            $recorded[$id]['cents'] += (int)$row['amount_cents'];
        }
    }
    $writeoffs = [];
    $matched = $matchedCents = $cooperationRows = 0;
    foreach (Db::name('store_order_writeoff')->where('status', 0)->where('add_time', '>', 0)
        ->field('id,oid,uid,relation_id,product_id,writeoff_price,add_time')->order('id')
        ->select()->toArray() as $row) {
        if (isset($cooperation[(int)$row['product_id']])) { $cooperationRows++; continue; }
        $id = (int)$row['id'];
        $cents = (int)round((float)$row['writeoff_price'] * 100);
        if (($recorded[$id]['rows'] ?? 0) > 0) {
            assertRepair($recorded[$id]['cents'] === $cents, 'EXISTING_AMOUNT_MISMATCH_' . $id);
            $matched++;
            $matchedCents += $cents;
            continue;
        }
        $writeoffs[$id] = $row;
    }
    $services = [];
    foreach (array_chunk(array_keys($writeoffs), 300) as $chunk) {
        foreach (Db::name('cashier_v3_entitlement_service_fact')->where('tenant_id', '0')
            ->whereIn('source_document_type', ['legacy_writeoff', 'legacy_paid_project'])
            ->whereIn('source_document_id', $chunk)->select()->toArray() as $row) {
            $services[(int)$row['source_document_id']][] = $row;
        }
    }
    $lineIds = [];
    foreach ($services as $list) foreach ($list as $row) $lineIds[$row['source_line_id']] = true;
    $labor = [];
    foreach (array_chunk(array_keys($lineIds), 250) as $chunk) {
        foreach (Db::name('cashier_v3_performance_fact')->where('tenant_id', '0')
            ->where('performance_type', 'labor_performance_allocated')->where('status', 'effective')
            ->whereIn('source_line_id', $chunk)->order('id')->select()->toArray() as $row) {
            $key = $row['checkout_request_id'] . '/' . $row['source_line_id'];
            $labor[$key] ??= $row;
        }
    }
    $voids = [];
    foreach (Db::name('cashier_v3_service_record_void_operation')->where('status', 'succeeded')
        ->field('service_fact_id')->select()->toArray() as $row) $voids[(int)$row['service_fact_id']] = true;
    $orderVoids = [];
    foreach (Db::name('cashier_v3_order_lifecycle_operation')->where('source_type', 'sales')
        ->where('operation_type', 'void')->where('status', 'succeeded')
        ->field('source_order_id')->select()->toArray() as $row) $orderVoids[(string)$row['source_order_id']] = true;
    $candidate = [];
    $skip = [];
    $byStore = [];
    $recordedAt = time();
    foreach ($writeoffs as $id => $writeoff) {
        $found = $services[$id] ?? [];
        $reason = '';
        if (count($found) !== 1) $reason = count($found) === 0 ? 'service_missing' : 'service_ambiguous';
        else {
            $service = $found[0];
            $day = (new DateTimeImmutable('@' . (int)$writeoff['add_time']))
                ->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('Y-m-d');
            if ((int)$service['store_id'] !== (int)$writeoff['relation_id']
                || (int)$service['member_id'] !== (int)$writeoff['uid']
                || (int)$service['project_id'] !== (int)$writeoff['product_id']
                || (string)$service['service_status'] !== 'completed'
                || (string)$service['business_date'] !== $day) $reason = 'service_identity_conflict';
            elseif (isset($voids[(int)$service['id']])) $reason = 'v3_service_void';
            else {
                $key = $service['checkout_request_id'] . '/' . $service['source_line_id'];
                $template = $labor[$key] ?? null;
                if (!$template) $reason = 'labor_template_missing';
                elseif ((int)$template['store_id'] !== (int)$service['store_id']
                    || (int)$template['member_id'] !== (int)$service['member_id']
                    || (string)$template['business_date'] !== (string)$service['business_date'])
                    $reason = 'labor_template_conflict';
                elseif (isset($orderVoids[(string)$template['order_id']])) $reason = 'v3_order_void';
            }
        }
        if ($reason !== '') { $skip[$reason] = ($skip[$reason] ?? 0) + 1; continue; }
        $new = mappedConsumption($template, $service, $writeoff, $recordedAt);
        $candidate[] = $new;
        $store = (int)$new['store_id'];
        $byStore[$store] ??= ['rows' => 0, 'cents' => 0];
        $byStore[$store]['rows']++;
        $byStore[$store]['cents'] += (int)$new['amount_cents'];
    }
    ksort($byStore);
    // 计划指纹覆盖每条事实的全部静态业务列，运行时刻和派生指纹除外。
    $hash = hash_init('sha256');
    foreach ($candidate as $row) {
        unset($row['recorded_at'], $row['immutable_fingerprint']);
        ksort($row);
        hash_update($hash, json_encode($row,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    }
    $planHash = hash_final($hash);
    $stores = array_map('intval', array_keys($byStore));
    $range = ['start' => '2025-01-01', 'end' => '2026-11-06'];
    $reader = new RegisteredMetricReadServices();
    assertRepair((int)Db::name('cashier_v3_performance_fact')
        ->where('rule_code_snapshot', 'legacy_consumption_recovery_mapped')->count() === 0,
        'RESTORATION_BATCH_ALREADY_EXISTS');
    $before = [];
    foreach ($stores as $store) $before[$store] = $reader->summary('consume_amount', '0', [$store], $range);
    $result = ['mode' => $apply ? 'apply' : 'plan', 'target' => '010.cc3798.com / mohe_010_app',
        'legacy_matched_rows' => $matched, 'legacy_matched_cents' => $matchedCents,
        'cooperation_excluded_rows' => $cooperationRows, 'missing_rows' => count($writeoffs),
        'candidate_rows' => count($candidate), 'candidate_cents' => array_sum(array_column($byStore, 'cents')),
        'skip' => $skip, 'by_store' => $byStore, 'before_by_store_cents' => $before,
        'candidate_sha256' => $planHash];
    if ($apply) {
        $expected = (string)($options['accepted-hash'] ?? '');
        $backup = (string)($options['backup-dir'] ?? '');
        assertRepair($expected !== '' && hash_equals($expected, $planHash), 'ACCEPTED_PLAN_HASH_MISMATCH');
        assertRepair(preg_match('#^/www/backups/010/[A-Za-z0-9-]+$#D', $backup) === 1
            && is_file($backup . '/affected-tables.sql.gz')
            && is_file($backup . '/SHA256SUMS'), 'PRIVATE_BACKUP_REQUIRED');
        $manifest = trim((string)file_get_contents($backup . '/SHA256SUMS'));
        assertRepair(str_starts_with($manifest, hash_file('sha256', $backup . '/affected-tables.sql.gz')),
            'BACKUP_CHECKSUM_MISMATCH');
        foreach (array_chunk($candidate, 250) as $chunk) {
            foreach ($chunk as &$row) $row['immutable_fingerprint'] = mappedFingerprint($row);
            unset($row);
            Db::name('cashier_v3_performance_fact')->insertAll($chunk);
        }
        assertRepair((int)Db::name('cashier_v3_performance_fact')
            ->where('rule_code_snapshot', 'legacy_consumption_recovery_mapped')->count()
            === count($candidate), 'INSERTED_FACT_COUNT_MISMATCH');
        foreach ($stores as $store) {
            $after = $reader->summary('consume_amount', '0', [$store], $range);
            assertRepair($after - $before[$store] === $byStore[$store]['cents'],
                'POST_REPAIR_METRIC_MISMATCH_' . $store);
            $result['after_by_store_cents'][$store] = $after;
        }
        Db::execute('COMMIT');
    } else Db::execute('ROLLBACK');
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable $error) {
    Db::execute('ROLLBACK');
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
