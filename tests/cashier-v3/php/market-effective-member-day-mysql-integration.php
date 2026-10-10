<?php

declare(strict_types=1);

/** 本地事务夹具验证每日达标；不触发真实结账，所有收款/冲销夹具最终回滚。 */
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require rtrim($backend, '/\\') . '/vendor/autoload.php';

use app\services\report\StoreUnifiedReportServices;
use think\facade\Db;

(new \think\App(rtrim($backend, '/\\') . '/'))->initialize();
date_default_timezone_set('Asia/Shanghai');
if (getenv('MARKET_DAY_LOCAL_TEST') !== '1'
    || config('database.connections.mysql.hostname') !== 'mysql'
    || config('database.connections.mysql.database') !== 'ruihao') {
    throw new RuntimeException('Explicit local Docker test environment required');
}
$checks = 0;
/** 失败立即终止，但 finally 仍回滚；仅输出断言名称，不输出会员隐私。 */
function checkMarketDay(bool $valid, string $message): void
{
    global $checks;
    if (!$valid) throw new RuntimeException($message);
    $checks++;
    echo 'PASS ' . $message . PHP_EOL;
}
$seed = Db::name('cashier_v3_payment_fact')->where('fact_direction', 'forward')
    ->where('status', 'effective')->where('payment_method', 'cash')->find();
$operationSeed = Db::name('cashier_v3_order_lifecycle_operation')->where('status', 'succeeded')->find();
$a = Db::name('cashier_v3_business_source')->where('parent_id', 0)->where('status', 1)->where('name', 'like', 'A%')->find();
$b = Db::name('cashier_v3_business_source')->where('parent_id', 0)->where('status', 1)->where('name', 'like', 'B%')->find();
if (!$seed || !$a || !$b || !$operationSeed) throw new RuntimeException('Local payment/source/operation seeds required');
$stores = [(int)$seed['store_id']];
$otherStore = (int)Db::name('cashier_v3_payment_fact')->where('store_id', '<>', $stores[0])->value('store_id');
if ($otherStore <= 0) throw new RuntimeException('Second local store required');
$stores[] = $otherStore;
$start = '2035-01-01';
$end = '2035-01-02';
if (Db::name('cashier_v3_payment_fact')->whereIn('store_id', $stores)->whereBetween('business_date', [$start, $end])->count()) {
    throw new RuntimeException('Fixture date range must be empty');
}
$token = 'MDAY-' . bin2hex(random_bytes(10));
$sequence = 0;
$reports = new StoreUnifiedReportServices();
$input = ['start_date' => $start, 'end_date' => $end, 'limit' => 10];
$query = static function (string $report, array $extra = [], ?array $scope = null) use ($reports, $input, $stores): array {
    return $reports->query($scope ?? $stores, array_merge($input, ['report' => $report], $extra));
};
/** 复制本地字段结构并覆盖所有身份和金额；不同夹具绝不复用原业务幂等键。 */
$pay = static function (int $member, array $source, string $date, int $cents, array $extra = []) use ($seed, $token, &$sequence, $stores): array {
    $fact = $seed;
    unset($fact['id']);
    $key = $token . '-' . ++$sequence;
    foreach (['fact_id', 'business_event_no', 'natural_key', 'command_idempotency_key', 'order_id', 'checkout_request_id', 'payment_authority_key'] as $field) $fact[$field] = $key;
    $fact['order_no_snapshot'] = $key;
    $fact['fact_type'] = 'payment_collected';
    $fact['fact_direction'] = 'forward';
    $fact['source_document_type'] = 'sale';
    $fact['store_id'] = $stores[0];
    $fact['member_id'] = $member;
    $fact['member_name_snapshot'] = '每日达标测试';
    $fact['business_date'] = $date;
    $fact['business_source_primary_id'] = (int)$source['id'];
    $fact['business_source_primary_name_snapshot'] = $source['name'];
    $fact['business_source_label_snapshot'] = $source['name'];
    $fact['amount_cents'] = $cents;
    $fact['immutable_fingerprint'] = hash('sha256', $key);
    $fact = array_replace($fact, $extra);
    Db::name('cashier_v3_payment_fact')->insert($fact);
    return $fact;
};
/** 成功退款/作废才被事实读取器识别；负向事实与操作记录在同一测试事务内。 */
$reverse = static function (array $original, string $date, int $cents, string $type) use ($pay, $operationSeed, $a): void {
    $fact = $pay((int)$original['member_id'], $a, $date, -$cents, [
        'order_id' => $original['order_id'], 'fact_direction' => 'reversal',
        'fact_type' => $type === 'void' ? 'payment_voided' : 'payment_refunded',
        'reversal_of' => $original['fact_id'],
    ]);
    $operation = $operationSeed;
    unset($operation['id']);
    foreach (['operation_id', 'operation_no', 'business_event_no', 'command_idempotency_key'] as $field) $operation[$field] = $fact['command_idempotency_key'];
    $operation['tenant_id'] = $fact['tenant_id'];
    $operation['store_id'] = $fact['store_id'];
    $operation['source_order_id'] = $original['order_id'];
    $operation['source_type'] = 'sales';
    $operation['operation_type'] = $type;
    $operation['status'] = 'succeeded';
    $operation['immutable_fingerprint'] = hash('sha256', $fact['command_idempotency_key']);
    Db::name('cashier_v3_order_lifecycle_operation')->insert($operation);
};
$base = 987650000;
Db::startTrans();
try {
    // A: 跨日各 600 不达标；连续两日各 1200 计 2；同日两笔各 600 计 1。
    $pay($base + 1, $a, $start, 60000); $pay($base + 1, $a, $end, 60000);
    $pay($base + 2, $a, $start, 120000); $pay($base + 2, $a, $end, 120000);
    $pay($base + 3, $a, $start, 60000); $pay($base + 3, $a, $start, 60000, ['payment_method' => 'wechat']);
    $pay($base + 4, $a, $start, 100000); $pay($base + 5, $a, $start, 99999);
    // B: 500 元临界值，跨日 300+300 不达标；同会员不同来源和门店独立。
    $pay($base + 2, $b, $start, 50000); $pay($base + 6, $b, $start, 49999);
    $pay($base + 7, $b, $start, 30000); $pay($base + 7, $b, $end, 30000);
    $pay($base + 2, $a, $start, 100000, ['store_id' => $stores[1]]);
    $pay(0, $a, $start, 200000);
    $pay($base + 8, $a, $start, 200000, ['status' => 'voided']);
    // 同日退款后 900 不达标；跨日退款不得撤回前一天的达标资格。
    $refund = $pay($base + 9, $a, $start, 120000); $reverse($refund, $start, 30000, 'refund');
    $later = $pay($base + 10, $a, $start, 120000); $reverse($later, $end, 30000, 'refund');
    $voided = $pay($base + 11, $a, $start, 120000); $reverse($voided, $end, 120000, 'void');

    $detail = $query('market_detail', ['_internal_all' => true]);
    $effective = static function (int $member, ?int $source = null) use ($detail): int {
        $count = 0;
        foreach ($detail['records'] as $row) {
            if ((int)$row['member_id'] === $member && ($source === null || (int)$row['business_source_primary_id'] === $source)) $count += (int)$row['effective_people'];
        }
        return $count;
    };
    checkMarketDay($effective($base + 1) === 0, 'A receipts cannot qualify across dates');
    checkMarketDay($effective($base + 2, (int)$a['id']) === 3, 'A repeated days and different stores count independently');
    checkMarketDay($effective($base + 2) === 4, 'Different sources retain independent daily qualification');
    checkMarketDay($effective($base + 3) === 1, 'Same-day multiple payments and methods count once');
    checkMarketDay($effective($base + 4) === 1 && $effective($base + 5) === 0, 'A threshold uses exact cents');
    checkMarketDay($effective($base + 2, (int)$b['id']) === 1 && $effective($base + 6) === 0 && $effective($base + 7) === 0, 'Other source threshold is daily 500 yuan');
    checkMarketDay($effective(0) === 0 && $effective($base + 8) === 0, 'Guests and ineffective facts do not qualify');
    checkMarketDay($effective($base + 9) === 0 && $effective($base + 10) === 1, 'Refunds offset only their business day; K display does not alter qualification');
    checkMarketDay($effective($base + 11) === 0, 'Successfully voided sale never qualifies');
    checkMarketDay((int)$detail['summary_row']['effective_people'] === 7, 'All-source detail summary counts qualified member-days');

    $performance = $query('market_performance');
    $aKey = 'channel_' . $a['id'] . '_effective';
    checkMarketDay((int)$performance['summary_row'][$aKey] === 6, 'Performance summary equals daily A qualification');
    $daySum = 0;
    foreach ([$start, $end] as $date) $daySum += (int)$query('market_performance', ['start_date' => $date, 'end_date' => $date])['summary_row'][$aKey];
    checkMarketDay($daySum === 6, 'Period result equals sum of independent daily queries');
    $drill = ['dimension_code' => (string)$a['id'], 'metric_code' => 'effective_people'];
    $drilled = $query('market_detail', $drill);
    checkMarketDay($drilled['total'] === 6 && (int)$drilled['summary_row']['effective_people'] === 6, 'Drilldown only contains qualified dates and reconciles with summary');
    $cashOnly = $query('market_detail', $drill + ['payment_method_code' => 'cash']);
    checkMarketDay($cashOnly['total'] === 5, 'Payment filter narrows daily qualifying receipts');
    $singleStore = $query('market_detail', $drill, [$stores[0]]);
    checkMarketDay($singleStore['total'] === 5, 'Authorized store scope excludes another store');
    $none = $query('market_detail', $drill + ['store_id' => $stores[0]], [999999999]);
    checkMarketDay($none['records'] === [] && (int)$none['summary_row']['effective_people'] === 0, 'Client filter cannot expand caller store scope');
    $personal = $query('market_detail', $drill + ['_report_scope' => ['mode' => 'self_participant', 'employee_id' => 999999999]]);
    checkMarketDay($personal['records'] === [], 'Unrelated employee cannot read qualifying members');
    $empty = $query('market_detail', ['start_date' => '2035-01-03', 'end_date' => '2035-01-03']);
    checkMarketDay($empty['records'] === [] && (int)$empty['summary_row']['effective_people'] === 0, 'Empty range returns zero qualification');

    // 超过第一页的会员日仍应进入合计、下钻和导出，不能用页面条数当总数。
    for ($i = 20; $i < 32; $i++) $pay($base + $i, $a, $end, 100000);
    $page = $query('market_detail', $drill);
    $export = $reports->export($stores, array_merge($input, ['report' => 'market_detail'], $drill));
    checkMarketDay(count($page['records']) === 10 && $page['total'] === 18 && (int)$page['summary_row']['effective_people'] === 18, 'Summary covers all pages');
    checkMarketDay(count($export['records']) === 18 && $export['summary_row'] === $page['summary_row'], 'Detail export uses complete identical daily results');
    $marketExport = $reports->export($stores, $input + ['report' => 'market_performance']);
    checkMarketDay((int)$marketExport['summary_row'][$aKey] === 18 && $marketExport['metric_version'] === $export['metric_version']
        && $export['metric_version'] === 'market-refund-member-day-v5', 'Summary export and detail share daily metric version');
    checkMarketDay($query('market_detail', $drill)['summary_row'] === $page['summary_row'], 'Repeated query is stable and read-only');
} finally {
    Db::rollback();
}
checkMarketDay((int)Db::name('cashier_v3_payment_fact')->where('fact_id', 'like', $token . '%')->count() === 0
    && (int)Db::name('cashier_v3_order_lifecycle_operation')->where('operation_id', 'like', $token . '%')->count() === 0, 'All local fixtures rolled back');
echo 'market effective member-day integration: ' . $checks . " PASS\n";
