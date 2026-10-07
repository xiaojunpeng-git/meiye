<?php

/** 本地事务回滚验证：体验服务仅不计市场人次，明细行和金额口径不随标签消失。 */
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require rtrim($backend, '/\\') . '/vendor/autoload.php';

use app\services\report\StoreUnifiedReportServices;
use think\facade\Db;

$app = new \think\App(rtrim($backend, '/\\') . '/');
$app->initialize();
if (getenv('MARKET_EXPERIENCE_LOCAL_TEST') !== '1') throw new RuntimeException('Explicit local test flag required');

function checkMarketExperience(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$seed = Db::name('cashier_v3_entitlement_service_fact')->where('service_status', 'completed')->find();
$source = Db::name('cashier_v3_business_source')->where('parent_id', 0)->where('status', 1)->where('name', 'like', 'B%')->find();
$selection = Db::name('cashier_v3_checkout_business_source_selection')->where('primary_source_id', '>', 0)->find();
checkMarketExperience((bool)$seed && (bool)$source && (bool)$selection, 'Missing local service or B-source fixture');

$random = bin2hex(random_bytes(16));
$store = (int)$seed['store_id'];
$date = '2026-10-04';
$sourceId = (int)$source['id'];
$member = 987654321;
$checkout = 'CKR-ME-' . $random;
$serviceFactId = 'ESF-ME-' . $random;
$pseudoOrderId = 'service:' . $checkout;
$reports = new StoreUnifiedReportServices();
$query = static function (string $code, array $extra = []) use ($reports, $store, $date, $sourceId): array {
    return $reports->query([$store], array_merge([
        'report' => $code, 'start_date' => $date, 'end_date' => $date,
        'dimension_code' => (string)$sourceId, 'limit' => 500,
    ], $extra));
};
$visits = static function () use ($query, $sourceId, $store): int {
    foreach ($query('market_performance')['records'] as $row) {
        if ((int)$row['store_id'] === $store) return (int)($row['channel_' . $sourceId . '_visits'] ?? 0);
    }
    return 0;
};
$detailRow = static function (string $orderId, array $extra = []) use ($query): ?array {
    foreach ($query('market_detail', $extra)['records'] as $row) {
        if (in_array($orderId, array_column((array)($row['_market_orders'] ?? []), 'order_id'), true)) return $row;
    }
    return null;
};

$baselineVisits = $visits();
$visitExplanation = static function (array $report, string $key): string {
    foreach ($report['columns'] as $column) {
        if ((string)($column['key'] ?? '') === $key) return (string)($column['source_explanation'] ?? '');
    }
    return '';
};
checkMarketExperience(strpos($visitExplanation($query('market_performance'), 'channel_' . $sourceId . '_visits'), '非体验') !== false
    && strpos($visitExplanation($query('market_detail'), 'visits'), '非体验') !== false,
    'Market visit column explanations do not describe the experience exclusion');
// R29 门店市场只排除体验服务；人员的“不算人数人次”开关不得误导为门店人次资格。
// 所有动态来源列与明细都检查，防止只修改单一来源或漏掉金额说明。
foreach (['market_performance', 'market_detail'] as $reportCode) {
    foreach ($query($reportCode)['columns'] as $column) {
        $key = (string)$column['key'];
        $visitColumn = $key === 'visits' || preg_match('/^channel_\d+_visits$/', $key);
        $cashColumn = in_array($key, ['effective_people', 'amount'], true)
            || preg_match('/^channel_\d+_(effective|amount)$/', $key);
        if (!$visitColumn && !$cashColumn) continue;
        $explanation = (string)($column['source_explanation'] ?? '');
        checkMarketExperience(strpos($explanation, '“体验”') !== false
            && strpos($explanation, '“不算人数人次”') !== false,
            'Missing explicit service labels: ' . $reportCode . ':' . $key);
        if ($visitColumn) {
            checkMarketExperience(strpos($explanation, '只影响人员统计，不影响本列') !== false
                && strpos($explanation, '其他符合条件的服务') !== false
                && strpos($explanation, '整行') !== false,
                'Missing personnel/market split, mixed-service or row-visibility boundary: ' . $reportCode . ':' . $key);
        }
    }
}
Db::startTrans();
try {
    $frozenSource = $selection;
    unset($frozenSource['id']);
    $frozenSource['tenant_id'] = $seed['tenant_id'];
    $frozenSource['store_id'] = $store;
    $frozenSource['checkout_request_id'] = $checkout;
    $frozenSource['checkout_kind'] = 'sale';
    $frozenSource['primary_source_id'] = $sourceId;
    $frozenSource['primary_source_name_snapshot'] = (string)$source['name'];
    $frozenSource['secondary_source_id'] = 0;
    $frozenSource['secondary_source_name_snapshot'] = '';
    $frozenSource['source_label_snapshot'] = (string)$source['name'];
    Db::name('cashier_v3_checkout_business_source_selection')->insert($frozenSource);

    $fact = $seed;
    unset($fact['id']);
    foreach (['service_fact_id', 'natural_key', 'command_idempotency_key', 'business_event_no', 'source_line_id'] as $field) {
        $fact[$field] = 'ME-' . $random;
    }
    $fact['service_fact_id'] = $serviceFactId;
    $fact['immutable_fingerprint'] = hash('sha256', $random);
    $fact['checkout_request_id'] = $checkout;
    $fact['service_record_no'] = 'ME' . substr($random, 0, 20);
    $fact['business_date'] = $date;
    $fact['member_id'] = $member;
    $fact['member_name_snapshot'] = '市场体验本地夹具';
    $fact['service_object'] = 'self';
    $fact['friend_counts_as_customer'] = 1;
    $fact['is_experience'] = 1;
    Db::name('cashier_v3_entitlement_service_fact')->insert($fact);

    // 体验服务保留在无收款市场明细，但人次及人次下钻不得包含它。
    $experienceRow = $detailRow($pseudoOrderId);
    checkMarketExperience($visits() === $baselineVisits, 'Experience service increased summary visits');
    checkMarketExperience($experienceRow !== null && (int)$experienceRow['visits'] === 0
        && (int)$experienceRow['amount_cents'] === 0, 'Experience service detail row/amount/visits incorrect');
    checkMarketExperience($detailRow($pseudoOrderId, ['metric_code' => 'visits']) === null,
        'Experience service leaked into visits drilldown');

    Db::name('cashier_v3_entitlement_service_fact')->where('service_fact_id', $serviceFactId)
        ->update(['is_experience' => 0]);
    checkMarketExperience($visits() === $baselineVisits + 1
        && (int)($detailRow($pseudoOrderId, ['metric_code' => 'visits'])['visits'] ?? 0) === 1,
        'Normal service not counted in summary and drilldown');
    // 人员开关关闭后，正常服务仍必须保留门店市场人次、明细与下钻。
    Db::name('cashier_v3_entitlement_service_fact')->where('service_fact_id', $serviceFactId)
        ->update(['friend_counts_as_customer' => 0]);
    checkMarketExperience($visits() === $baselineVisits + 1
        && (int)($detailRow($pseudoOrderId, ['metric_code' => 'visits'])['visits'] ?? 0) === 1,
        'Personnel no-count flag incorrectly removed normal market visit');
    Db::name('cashier_v3_entitlement_service_fact')->where('service_fact_id', $serviceFactId)
        ->update(['friend_counts_as_customer' => 1]);

    // 同一会员同日混合体验与非体验项目，只由非体验项目取得一次人次。
    $mixed = $fact;
    $mixed['service_fact_id'] = 'ESF-ME-MIX-' . $random;
    $mixed['natural_key'] = 'ME-MIX-' . $random;
    $mixed['source_line_id'] = 'ME-MIX-' . $random;
    $mixed['command_idempotency_key'] = 'ME-MIX-' . $random;
    $mixed['business_event_no'] = 'ME-MIX-' . $random;
    $mixed['service_record_no'] = 'MEMIX' . substr($random, 0, 18);
    $mixed['immutable_fingerprint'] = hash('sha256', $random . '|mixed');
    Db::name('cashier_v3_entitlement_service_fact')->insert($mixed);
    checkMarketExperience($visits() === $baselineVisits + 1
        && (int)($detailRow($pseudoOrderId)['visits'] ?? 0) === 1,
        'Mixed experience and normal projects changed member-day deduplication');

    Db::name('cashier_v3_entitlement_service_fact')->where('service_fact_id', $serviceFactId)
        ->update(['is_experience' => 1]);
    checkMarketExperience($visits() === $baselineVisits
        && (int)($detailRow($pseudoOrderId)['visits'] ?? -1) === 0,
        'All-experience service still counted');

    // 游客没有会员 ID，体验标签也必须优先于“按服务单计一次”的规则。
    $guestCheckout = 'CKR-ME-G-' . $random;
    $guestSource = $frozenSource;
    $guestSource['checkout_request_id'] = $guestCheckout;
    Db::name('cashier_v3_checkout_business_source_selection')->insert($guestSource);
    $guestFactId = 'ESF-ME-G-' . $random;
    $guest = $fact;
    $guest['service_fact_id'] = $guestFactId;
    $guest['natural_key'] = 'ME-G-' . $random;
    $guest['source_line_id'] = 'ME-G-' . $random;
    $guest['command_idempotency_key'] = 'ME-G-' . $random;
    $guest['business_event_no'] = 'ME-G-' . $random;
    $guest['immutable_fingerprint'] = hash('sha256', $random . '|guest');
    $guest['service_record_no'] = 'MEG' . substr($random, 0, 20);
    $guest['checkout_request_id'] = $guestCheckout;
    $guest['member_id'] = 0;
    $guest['member_name_snapshot'] = '';
    Db::name('cashier_v3_entitlement_service_fact')->insert($guest);
    $guestOrderId = 'service:' . $guestCheckout;
    checkMarketExperience($visits() === $baselineVisits
        && (int)($detailRow($guestOrderId)['visits'] ?? -1) === 0,
        'Experience guest was counted or its zero-visit row disappeared');
    $allDetail = $query('market_detail', ['_internal_all' => true]);
    checkMarketExperience((string)($allDetail['metric_version'] ?? '') === 'market-visits-experience-only-v3'
        && (string)($query('market_performance')['metric_version'] ?? '') === (string)$allDetail['metric_version']
        && array_key_exists('service_customer_rule_version', $allDetail)
        && $allDetail['service_customer_rule_version'] === null
        && array_filter($allDetail['records'], static function (array $row) use ($guestOrderId): bool {
            return in_array($guestOrderId, array_column((array)($row['_market_orders'] ?? []), 'order_id'), true)
                && (int)($row['visits'] ?? -1) === 0;
        }), 'All-rows export/query mouth or experience row differs from on-screen detail');
    Db::name('cashier_v3_entitlement_service_fact')->where('service_fact_id', $guestFactId)
        ->update(['is_experience' => 0]);
    checkMarketExperience($visits() === $baselineVisits + 1
        && (int)($detailRow($guestOrderId, ['metric_code' => 'visits'])['visits'] ?? 0) === 1,
        'Normal guest service not counted exactly once');

    echo "PASS market experience visits: member/guest, detail retained, summary/drilldown, mixed-day dedup\n";
} finally {
    Db::rollback();
}
