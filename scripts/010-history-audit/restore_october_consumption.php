<?php

declare(strict_types=1);

/**
 * 010 大悦城店 2026-10 历史消耗缺口的固定数据恢复候选。
 *
 * 只使用 010 已保存的整元历史核销与来源身份；不回写 007、不改订单、余额或卡权益。
 * 默认只读计划。实际写入须携带本次计划指纹，并在同一事务中验证注册指标合计。
 */

$options = getopt('', ['apply', 'accepted-hash:']);
$apply = array_key_exists('apply', $options);
$acceptedHash = (string)($options['accepted-hash'] ?? '');
require getcwd() . '/vendor/autoload.php';
(new \think\App())->initialize();

use app\services\query\metric\RegisteredMetricReadServices;
use think\facade\Db;

function requireState(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

/** 业务事实指纹只覆盖将落库的列，避免自增 ID 与数据库默认值改变重放身份。 */
function factFingerprint(array $row): string
{
    unset($row['id'], $row['immutable_fingerprint']);
    ksort($row);
    return hash('sha256', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

/** 复制已有同源事实时清除员工分摊，并恢复原核销的整元消耗。 */
function consumptionRow(array $template, array $service, array $writeoff, int $recordedAt): array
{
    $row = $template;
    unset($row['id']);
    $stem = 'IMP007-W' . (int)$writeoff['id'];
    $amount = (int)round((float)$writeoff['writeoff_price'] * 100);
    requireState($amount >= 0 && $amount % 100 === 0, 'HISTORICAL_AMOUNT_NOT_WHOLE_YUAN');
    $row['fact_id'] = $stem . '-consumption';
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
    $row['rule_code_snapshot'] = 'legacy_consumption_recovery';
    $row['rule_name_snapshot'] = '历史核销消耗恢复';
    $row['rule_version_snapshot'] = 'legacy-restoration-v1';
    $row['immutable_fingerprint'] = factFingerprint($row);
    return $row;
}

/** 唯一缺失服务由同一原订单的已承接项目行和旧核销原件共同证明。 */
function missingService68920(array $writeoff, int $recordedAt): array
{
    requireState((int)$writeoff['id'] === 68920 && (int)$writeoff['oid'] === 119280,
        'UNMAPPED_SERVICE_IDENTITY_CHANGED');
    $sibling = Db::name('cashier_v3_entitlement_service_fact')->where('source_document_id', 68921)
        ->where('store_id', 9)->find();
    $sale = Db::name('cashier_v3_sales_order')->where('source_document_id', '119280')
        ->where('store_id', 9)->find();
    requireState((bool)$sibling && (bool)$sale && (int)$sibling['member_id'] === (int)$writeoff['uid']
        && (string)$sibling['document_id'] === (string)$sale['order_id'], 'UNMAPPED_SERVICE_SALE_PROOF_MISSING');
    $lineId = 'IMP007-119280-line-' . (int)$writeoff['order_cart_id'];
    $line = Db::name('cashier_v3_sales_order_line')->where('order_id', $sale['order_id'])
        ->where('order_line_id', $lineId)->find();
    requireState((bool)$line && (string)$line['item_id'] === (string)$writeoff['product_id']
        && (int)$line['member_id'] === (int)$writeoff['uid']
        && (int)$line['store_id'] === (int)$writeoff['relation_id']
        && (string)$line['item_type'] === 'project', 'UNMAPPED_SERVICE_LINE_PROOF_MISSING');
    $service = $sibling;
    unset($service['id']);
    $stem = 'IMP007-W68920';
    $service['service_fact_id'] = $stem . '-service';
    $service['natural_key'] = $service['service_fact_id'];
    $service['service_record_no'] = (string)$writeoff['writeoff_code'];
    $service['business_event_no'] = $stem . '-source-service';
    $service['command_idempotency_key'] = $stem;
    $service['business_date'] = date('Y-m-d', (int)$writeoff['add_time']);
    $service['occurred_at'] = (int)$writeoff['add_time'];
    $service['settled_at'] = (int)$writeoff['add_time'];
    $service['recorded_at'] = $recordedAt;
    $service['document_no_snapshot'] = (string)$writeoff['writeoff_code'];
    $service['source_document_type'] = 'legacy_paid_project';
    $service['source_document_id'] = 68920;
    $service['source_line_id'] = $lineId;
    $service['project_id'] = (int)$writeoff['product_id'];
    $service['project_name_snapshot'] = (string)$line['item_name_snapshot'];
    $service['project_category_id_snapshot'] = (int)$line['category_id_snapshot'];
    $service['project_category_name_snapshot'] = (string)$line['category_name_snapshot'];
    $service['project_category_path_snapshot'] = (string)$line['category_name_snapshot'];
    $service['quantity'] = (string)$writeoff['writeoff_num'];
    $service['service_object'] = in_array($writeoff['service_object'], ['朋友', 'friend'], true) ? 'friend' : 'self';
    $service['labor_amount_cents'] = 0;
    $service['labor_fee_amount_cents'] = 0;
    $service['primary_craftsman_staff_id'] = 0;
    $service['craftsmen_snapshot_json'] = '[]';
    $service['service_status'] = 'completed';
    $service['immutable_fingerprint'] = factFingerprint($service);
    return $service;
}

$config = config('database.connections.mysql');
requireState(realpath(getcwd()) === '/www/wwwroot/010.cc3798.com'
    && (string)$config['database'] === 'mohe_010_app'
    && (string)$config['hostname'] === '127.0.0.1'
    && (string)$config['hostport'] === '3307', 'TARGET_NOT_010_APP');

Db::execute($apply ? 'START TRANSACTION' : 'START TRANSACTION READ ONLY');
try {
    $range = ['start' => '2026-10-01', 'end' => '2026-10-31'];
    $start = strtotime('2026-10-01 00:00:00');
    $end = strtotime('2026-10-31 23:59:59');
    $metric = new RegisteredMetricReadServices();
    $before = $metric->summary('consume_amount', '0', [9], $range);
    requireState($before === 11795500, 'METRIC_BASELINE_CHANGED');
    $cooperation = Db::name('store_product_relation')->where('relation_id', 78)
        ->where('type', 1)->column('product_id');
    $writeoffs = Db::name('store_order_writeoff')->where('relation_id', 9)->where('status', 0)
        ->whereBetween('add_time', [$start, $end])->whereNotIn('product_id', $cooperation)
        ->order('id')->select()->toArray();
    requireState(count($writeoffs) === 827, 'LEGACY_POPULATION_CHANGED');
    $legacyCents = 0;
    foreach ($writeoffs as $writeoff) $legacyCents += (int)round((float)$writeoff['writeoff_price'] * 100);
    requireState($legacyCents === 12806900, 'LEGACY_AMOUNT_CHANGED');
    $ids = array_column($writeoffs, 'id');
    $services = [];
    foreach (array_chunk($ids, 300) as $chunk) {
        foreach (Db::name('cashier_v3_entitlement_service_fact')->where('tenant_id', '0')
            ->where('store_id', 9)->whereIn('source_document_id', $chunk)->select()->toArray() as $row) {
            $services[(int)$row['source_document_id']][] = $row;
        }
    }
    $lines = [];
    foreach ($services as $list) foreach ($list as $service) $lines[$service['source_line_id']] = true;
    $facts = [];
    foreach (array_chunk(array_keys($lines), 250) as $chunk) {
        foreach (Db::name('cashier_v3_performance_fact')->where('tenant_id', '0')
            ->where('store_id', 9)->whereIn('source_line_id', $chunk)->select()->toArray() as $row) {
            $facts[$row['checkout_request_id'] . '/' . $row['source_line_id']][] = $row;
        }
    }
    $orders = [];
    foreach (array_chunk(array_column($writeoffs, 'oid'), 300) as $chunk) {
        foreach (Db::name('store_order')->whereIn('id', $chunk)
            ->field('id,paid,is_del,is_system_del,refund_status,terminal_action')->select()->toArray() as $row) {
            $orders[(int)$row['id']] = $row;
        }
    }
    $candidates = [];
    $extraService = null;
    $deletedCount = $activeCount = $deletedCents = $activeCents = 0;
    $recordedAt = time();
    foreach ($writeoffs as $writeoff) {
        $id = (int)$writeoff['id'];
        $matched = $services[$id] ?? [];
        requireState(count($matched) <= 1, 'DUPLICATE_SERVICE_MAPPING_' . $id);
        $service = $matched[0] ?? null;
        if (!$service && $id === 68920) $service = $extraService = missingService68920($writeoff, $recordedAt);
        requireState((bool)$service, 'MISSING_SERVICE_MAPPING_' . $id);
        requireState((int)$service['source_document_id'] === $id
            && (int)$service['member_id'] === (int)$writeoff['uid']
            && (int)$service['project_id'] === (int)$writeoff['product_id']
            && (int)$service['store_id'] === 9
            && (string)$service['service_status'] === 'completed', 'SERVICE_SOURCE_MISMATCH_' . $id);
        $key = $service['checkout_request_id'] . '/' . $service['source_line_id'];
        $all = $facts[$key] ?? [];
        $consumption = array_values(array_filter($all, static fn(array $row): bool =>
            $row['performance_type'] === 'consumption_performance_recorded'));
        if ($consumption) {
            requireState(count($consumption) <= 2, 'DUPLICATE_CONSUMPTION_' . $id);
            $existingCents = array_sum(array_map(static fn(array $row): int => (int)$row['amount_cents'],
                $consumption));
            requireState($existingCents === (int)round((float)$writeoff['writeoff_price'] * 100),
                'MATCHED_AMOUNT_CHANGED_' . $id);
            continue;
        }
        $order = $orders[(int)$writeoff['oid']] ?? null;
        requireState((bool)$order && (int)$order['paid'] === 1 && (int)$order['is_del'] === 0
            && (int)$order['refund_status'] === 0 && (int)$order['terminal_action'] === 0,
            'ORDER_STATE_CHANGED_' . $id);
        $templates = array_values(array_filter($all, static fn(array $row): bool =>
            $row['performance_type'] === 'labor_performance_allocated' && $row['status'] === 'effective'));
        if ($id === 68920) {
            $templates = Db::name('cashier_v3_performance_fact')->where('tenant_id', '0')
                ->where('store_id', 9)->where('source_line_id', 'IMP007-119280-line-68343')
                ->where('performance_type', 'labor_performance_allocated')->select()->toArray();
        }
        requireState(count($templates) > 0, 'PERFORMANCE_TEMPLATE_MISSING_' . $id);
        $candidate = consumptionRow($templates[0], $service, $writeoff, $recordedAt);
        requireState((int)Db::name('cashier_v3_performance_fact')->where('fact_id', $candidate['fact_id'])->count() === 0,
            'FACT_ID_ALREADY_EXISTS_' . $id);
        $candidates[] = $candidate;
        $amount = (int)$candidate['amount_cents'];
        if ((int)$order['is_system_del'] === 1) { $deletedCount++; $deletedCents += $amount; }
        else { $activeCount++; $activeCents += $amount; }
    }
    requireState(count($candidates) === 88 && $activeCount === 36 && $activeCents === 562600
        && $deletedCount === 52 && $deletedCents === 448800 && $extraService !== null,
        'RESTORATION_POPULATION_CHANGED');
    // 真正成功的 V3 作废链仍由正常报表排除，不能用历史补录绕过冲销。
    $candidateOrders = array_values(array_unique(array_column($candidates, 'order_id')));
    $candidateServiceIds = array_map(static fn(array $row): string =>
        str_replace('-consumption', '-service', (string)$row['fact_id']), $candidates);
    foreach (array_chunk($candidateOrders, 200) as $chunk) {
        requireState((int)Db::name('cashier_v3_order_lifecycle_operation')->where('tenant_id', '0')
            ->whereIn('source_order_id', $chunk)->where('source_type', 'sales')
            ->where('operation_type', 'void')->where('status', 'succeeded')->count() === 0,
            'V3_ORDER_VOID_CONFLICT');
    }
    foreach (array_chunk($candidateServiceIds, 200) as $chunk) {
        requireState((int)Db::name('cashier_v3_entitlement_service_fact')->alias('s')
            ->join('cashier_v3_service_record_void_operation v',
                'v.tenant_id=s.tenant_id AND v.service_fact_id=s.id')
            ->where('s.tenant_id', '0')->whereIn('s.service_fact_id', $chunk)
            ->where('v.status', 'succeeded')->count() === 0,
            'V3_SERVICE_VOID_CONFLICT');
    }
    // 验收指纹覆盖全部候选业务列；只排除执行时刻和由其派生的指纹。
    $identity = array_map(static function (array $row): array {
        unset($row['recorded_at'], $row['immutable_fingerprint']);
        ksort($row);
        return $row;
    }, $candidates);
    $serviceIdentity = $extraService;
    unset($serviceIdentity['recorded_at'], $serviceIdentity['immutable_fingerprint']);
    ksort($serviceIdentity);
    $planHash = hash('sha256', json_encode([$serviceIdentity, $identity],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $result = ['target' => '010.cc3798.com / mohe_010_app', 'store_id' => 9, 'range' => $range,
        'before_cents' => $before, 'expected_after_cents' => $legacyCents,
        'active_missing' => ['rows' => $activeCount, 'cents' => $activeCents],
        'archived_missing' => ['rows' => $deletedCount, 'cents' => $deletedCents],
        'new_service_source_id' => 68920, 'candidate_sha256' => $planHash,
        'mode' => $apply ? 'apply' : 'plan'];
    if ($apply) {
        requireState($acceptedHash !== '' && hash_equals($planHash, $acceptedHash), 'ACCEPTED_PLAN_HASH_MISMATCH');
        Db::name('cashier_v3_entitlement_service_fact')->insert($extraService);
        foreach ($candidates as $candidate) Db::name('cashier_v3_performance_fact')->insert($candidate);
        $after = $metric->summary('consume_amount', '0', [9], $range);
        requireState($after === $legacyCents, 'POST_REPAIR_METRIC_MISMATCH');
        $result['actual_after_cents'] = $after;
        Db::execute('COMMIT');
    } else Db::execute('ROLLBACK');
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable $error) {
    Db::execute('ROLLBACK');
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
