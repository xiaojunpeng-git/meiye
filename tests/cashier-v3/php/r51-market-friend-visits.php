<?php
/** 本地事务回滚夹具：验证朋友计数规则、同日去重、市场汇总/明细及下钻同源，不留业务数据。 */
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require $backend . '/vendor/autoload.php';
$app = new \think\App($backend . '/');
$app->initialize();
use think\facade\Db;
use app\services\report\StoreUnifiedReportServices;
function check51($ok, $message) { if (!$ok) throw new RuntimeException($message); }
check51(getenv('R51_LOCAL_TEST') === '1', 'explicit local test flag required');
$seed = Db::name('cashier_v3_entitlement_service_fact')->where('service_status', 'completed')->find();
$source = Db::name('cashier_v3_checkout_business_source_selection')->where('primary_source_id', '>', 0)->find();
check51($seed && $source, 'missing local fixtures');
$store = (int)$seed['store_id'];
$date = '2026-09-26';
$member = 987654321;
$report = new StoreUnifiedReportServices();
$query = static function ($code, $extra = []) use ($report, $store, $date) {
    return $report->query([$store], array_merge(['report'=>$code, 'start_date'=>$date, 'end_date'=>$date, 'limit'=>100], $extra));
};
$visits = static function () use ($query, $member, $source) {
    $result = $query('market_detail', ['dimension_code'=>(string)$source['primary_source_id'], 'metric_code'=>'visits']);
    $rows = array_values(array_filter($result['records'], static function ($r) use ($member) { return (int)$r['member_id'] === $member; }));
    return count($rows) ? (int)$rows[0]['visits'] : 0;
};
$summary = static function () use ($query, $store, $source) {
    foreach ($query('market_performance')['records'] as $row) {
        if ((int)$row['store_id'] === $store) return (int)($row['channel_'.$source['primary_source_id'].'_visits'] ?? 0);
    }
    return 0;
};
$baseline = $summary();
Db::startTrans();
try {
    $random = bin2hex(random_bytes(16));
    $checkout = 'CKR-R51-'.$random;
    unset($source['id']);
    $source['tenant_id'] = $seed['tenant_id'];
    $source['store_id'] = $store;
    $source['checkout_request_id'] = $checkout;
    $source['checkout_kind'] = 'sale';
    Db::name('cashier_v3_checkout_business_source_selection')->insert($source);
    $ids = [];
    for ($i = 0; $i < 2; $i++) {
        $row = $seed;
        unset($row['id']);
        foreach (['service_fact_id','natural_key','command_idempotency_key','business_event_no','source_line_id'] as $field) $row[$field] = 'R51-'.$random.'-'.$i;
        $row['immutable_fingerprint'] = hash('sha256', $random.$i);
        $row['checkout_request_id'] = $checkout;
        $row['service_record_no'] = 'R51'.substr($random, 0, 16).$i;
        $row['member_id'] = $member;
        $row['member_name_snapshot'] = 'R51本地夹具';
        $row['business_date'] = $date;
        $row['service_object'] = 'friend';
        $row['friend_counts_as_customer'] = 0;
        $ids[] = Db::name('cashier_v3_entitlement_service_fact')->insertGetId($row);
    }
    check51($visits() === 0 && $summary() === $baseline, 'excluded friend leaked into detail/drilldown/summary');
    // 混合项目必须保留可计数服务，不能因同单存在不计人次项目而整单排除。
    Db::name('cashier_v3_entitlement_service_fact')->where('id', $ids[0])->update(['friend_counts_as_customer'=>1]);
    check51($visits() === 1 && $summary() === $baseline + 1, 'counted friend missing');
    Db::name('cashier_v3_entitlement_service_fact')->whereIn('id', $ids)->update(['service_object'=>'self','friend_counts_as_customer'=>0]);
    check51($visits() === 1 && $summary() === $baseline + 1, 'self visits lost or duplicated');
    Db::name('cashier_v3_entitlement_service_fact')->whereIn('id', $ids)->update(['service_object'=>'friend','member_id'=>0]);
    check51($summary() === $baseline, 'excluded guest friend counted');
    Db::name('cashier_v3_entitlement_service_fact')->whereIn('id', $ids)->update(['friend_counts_as_customer'=>1]);
    check51($summary() === $baseline + 1, 'counted guest projects not deduplicated');
    echo "PASS R51: excluded/counting friends, mixed projects, self, guest, dedup, detail/drilldown/summary\n";
} finally {
    Db::rollback();
}
