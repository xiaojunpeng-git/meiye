<?php
/**
 * 经营看板阶段4 性能收口 smoke
 *
 * 交付：
 * - 全量授权门店（本地全量；不足 130 时明确打印 store_count）冷启动 SQL/耗时
 * - 门槛沿用 §8.4a：overview biz≈15～25 硬上限30；trend_actual biz≤4；store_ranking cold≤30；staff≤8
 * - 金额明细 moneyMetricDetail 冷启动（6 指标）+ total 与 overview 卡片对账
 * - 全量门店 IN 列表 EXPLAIN；输出索引缺口建议（本脚本不执行 ALTER）
 *
 * docker cp 美容源码/scripts/smoke-business-dashboard-phase4.php mohe-app:/tmp/
 * docker exec mohe-app php /tmp/smoke-business-dashboard-phase4.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$scenario = '';
$storesArg = '';
$metricArg = '';
foreach ($argv as $arg) {
    if (strpos($arg, '--scenario=') === 0) {
        $scenario = substr($arg, strlen('--scenario='));
    }
    if (strpos($arg, '--stores=') === 0) {
        $storesArg = substr($arg, strlen('--stores='));
    }
    if (strpos($arg, '--metric=') === 0) {
        $metricArg = substr($arg, strlen('--metric='));
    }
}

require '/var/www/html/vendor/autoload.php';
$app = new think\App();
$app->initialize();

use app\model\store\SystemStore;
use app\services\report\ReportServices;
use app\services\statistics\BusinessDashboardServices;
use think\facade\Db;

function isSchemaSql(string $sql): array
{
    $s = ltrim($sql);
    if (preg_match('/^SHOW\s+(FULL\s+)?COLUMNS\b/i', $s)) {
        return [true, 'SHOW_COLUMNS'];
    }
    if (preg_match('/^SHOW\s+TABLES\b/i', $s)) {
        return [true, 'SHOW_TABLES'];
    }
    if (preg_match('/^SHOW\s+INDEX\b/i', $s)) {
        return [true, 'SHOW_INDEX'];
    }
    return [false, 'biz'];
}

function isConnectSql(string $sql): bool
{
    return (bool)preg_match('/^CONNECT\s*:/i', ltrim($sql));
}

/**
 * @param array<int, array{sql:string,runtime:float|int|string}> $sqlLog
 * @return array{listen_raw:int,cold:int,biz:int,schema:int,connect:int,ms:float,biz_ms:float}
 */
function summarizeSql(array $sqlLog): array
{
    $listenRaw = count($sqlLog);
    $connect = 0;
    $schema = 0;
    $ms = 0.0;
    $bizMs = 0.0;
    foreach ($sqlLog as $row) {
        $sql = (string)($row['sql'] ?? '');
        $rt = (float)($row['runtime'] ?? 0);
        $ms += $rt;
        if (isConnectSql($sql)) {
            $connect++;
            continue;
        }
        [$isSchema] = isSchemaSql($sql);
        if ($isSchema) {
            $schema++;
        } else {
            $bizMs += $rt;
        }
    }
    $cold = $listenRaw - $connect;
    return [
        'listen_raw' => $listenRaw,
        'cold' => $cold,
        'biz' => $cold - $schema,
        'schema' => $schema,
        'connect' => $connect,
        'ms' => round($ms * 1000, 2),
        'biz_ms' => round($bizMs * 1000, 2),
    ];
}

function printSqlSummary(string $label, array $sum): void
{
    echo sprintf(
        "SQL %-28s cold=%-3d biz=%-3d schema=%-3d connect=%-2d listen_raw=%-3d cold_ms=%s biz_ms=%s\n",
        $label,
        $sum['cold'],
        $sum['biz'],
        $sum['schema'],
        $sum['connect'] ?? 0,
        $sum['listen_raw'] ?? $sum['cold'],
        $sum['ms'],
        $sum['biz_ms']
    );
}

// ---------- 子进程：单场景冷启动 ----------
if ($scenario !== '') {
    $allStores = array_values(array_filter(array_map('intval', $storesArg === '' ? [] : explode(',', $storesArg))));
    if (!$allStores) {
        fwrite(STDERR, "stores required\n");
        exit(2);
    }
    $sampleStores = array_slice($allStores, 0, 3);
    $timeStr = date('Y/m/01') . '-' . date('Y/m/d');
    $start = strtotime(date('Y-m-01 00:00:00'));
    $end = strtotime(date('Y-m-d 23:59:59'));
    $trendEnd = strtotime(date('Y-m-d 23:59:59'));
    $trendStart = strtotime(date('Y-m-d 00:00:00', strtotime('-29 day')));

    $sqlLog = [];
    Db::listen(function ($sql, $runtime) use (&$sqlLog) {
        $sqlLog[] = ['sql' => (string)$sql, 'runtime' => $runtime];
    });

    /** @var BusinessDashboardServices $dash */
    $dash = app()->make(BusinessDashboardServices::class);
    $payload = [];
    $t0 = microtime(true);

    switch ($scenario) {
        case 'overview':
            $payload['result'] = $dash->overview($allStores, $start, $end, $timeStr);
            break;
        case 'trend_actual':
            $payload['result'] = $dash->trend($allStores, 'actual_performance', $trendStart, $trendEnd);
            break;
        case 'trend_cash':
            $payload['result'] = $dash->trend($allStores, 'cash_performance', $trendStart, $trendEnd);
            break;
        case 'store_ranking':
            $payload['result'] = $dash->storeRanking($allStores, $start, $end, $timeStr, 'cash_performance', 'desc');
            break;
        case 'staff_ranking':
            $sid = (int)($sampleStores[0] ?? 0);
            $payload['result'] = $dash->staffRanking($sid, $start, $end, $timeStr, 'cash_performance', 'desc');
            $payload['store_id'] = $sid;
            break;
        case 'money_detail':
            $metric = $metricArg !== '' ? $metricArg : 'cash_performance';
            $payload['result'] = $dash->moneyMetricDetail($allStores, $metric, $start, $end, $timeStr, 1, 20);
            $payload['metric'] = $metric;
            break;
        default:
            fwrite(STDERR, "unknown scenario\n");
            exit(2);
    }

    $payload['wall_ms'] = round((microtime(true) - $t0) * 1000, 2);
    $sum = summarizeSql($sqlLog);
    $payload['sql'] = $sum;
    echo json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

// ---------- 主进程 ----------
$failures = [];
function fail(string $case, string $msg): void
{
    global $failures;
    $failures[] = "{$case}: {$msg}";
    echo "FAIL {$case}: {$msg}\n";
}
function pass(string $case, string $extra = ''): void
{
    echo 'PASS ' . $case . ($extra !== '' ? ' ' . $extra : '') . "\n";
}

$self = '/tmp/smoke-business-dashboard-phase4.php';
if (!is_file($self)) {
    $self = __FILE__;
}

/**
 * @param int[] $storeIds
 * @return array<string,mixed>
 */
function runColdScenario(string $self, string $name, array $storeIds, string $metric = ''): array
{
    $stores = implode(',', array_map('intval', $storeIds));
    $cmd = 'php ' . escapeshellarg($self)
        . ' --scenario=' . escapeshellarg($name)
        . ' --stores=' . escapeshellarg($stores);
    if ($metric !== '') {
        $cmd .= ' --metric=' . escapeshellarg($metric);
    }
    $cmd .= ' 2>&1';
    $out = shell_exec($cmd);
    $line = trim((string)$out);
    $parts = preg_split("/\r\n|\n|\r/", $line);
    $jsonLine = '';
    for ($i = count($parts) - 1; $i >= 0; $i--) {
        $cand = trim($parts[$i]);
        if ($cand !== '' && $cand[0] === '{') {
            $jsonLine = $cand;
            break;
        }
    }
    $data = json_decode($jsonLine, true);
    if (!is_array($data) || empty($data['sql'])) {
        throw new RuntimeException("cold scenario {$name} invalid output: " . substr($line, 0, 500));
    }
    return $data;
}

echo "=== Business Dashboard Phase4 Performance Smoke ===\n";
echo "口径: cold=listen−CONNECT; biz=cold−SHOW; 门槛看 biz; 门店排行 cold≤30\n";
echo "范围: 本地全量授权门店（不足130时以全量为准，打印 store_count）\n\n";

$allStores = SystemStore::where('is_del', 0)->where('name', '<>', '总部')->column('id');
$allStores = array_values(array_unique(array_filter(array_map('intval', $allStores))));
$storeCount = count($allStores);
$timeStr = date('Y/m/01') . '-' . date('Y/m/d');
$start = strtotime(date('Y-m-01 00:00:00'));
$end = strtotime(date('Y-m-d 23:59:59'));
echo "store_count={$storeCount} (target_note=130_or_all_authorized) time={$timeStr}\n";
if ($storeCount < 130) {
    echo "NOTE local_authorized_stores={$storeCount} < 130; evidence uses all authorized stores on this DB\n";
}
echo "\n";

/** @var ReportServices $report */
$report = app()->make(ReportServices::class);
$ctx = $report->buildReportSaleSourceContext();

echo "=== COLD START (independent processes, all stores) ===\n";
$coldResults = [];
foreach (['overview', 'trend_actual', 'trend_cash', 'store_ranking', 'staff_ranking'] as $name) {
    try {
        $coldResults[$name] = runColdScenario($self, $name, $allStores);
        printSqlSummary($name, $coldResults[$name]['sql']);
        echo sprintf("WALL %-28s %sms\n", $name, $coldResults[$name]['wall_ms'] ?? '?');
    } catch (Throwable $e) {
        fail('cold_' . $name, $e->getMessage());
    }
}

$moneyMetrics = BusinessDashboardServices::MONEY_DETAIL_METRICS;
echo "\n=== MONEY DETAIL COLD + CARD RECONCILE ===\n";
$ovCards = [];
foreach (($coldResults['overview']['result']['cards'] ?? []) as $card) {
    $ovCards[$card['metric_code']] = (float)$card['value'];
}
foreach ($moneyMetrics as $metric) {
    try {
        $cold = runColdScenario($self, 'money_detail', $allStores, $metric);
        $coldResults['money_' . $metric] = $cold;
        printSqlSummary('money_' . $metric, $cold['sql']);
        $detailTotal = round((float)($cold['result']['total'] ?? -1), 2);
        $cardVal = round((float)($ovCards[$metric] ?? -2), 2);
        if (abs($detailTotal - $cardVal) < 0.005) {
            pass("money_reconcile_{$metric}", (string)$detailTotal);
        } else {
            fail("money_reconcile_{$metric}", "detail={$detailTotal} card={$cardVal}");
        }
    } catch (Throwable $e) {
        fail('cold_money_' . $metric, $e->getMessage());
    }
}

// 门槛
$ovBiz = (int)($coldResults['overview']['sql']['biz'] ?? 999);
$ovCold = (int)($coldResults['overview']['sql']['cold'] ?? 999);
if ($ovBiz > 30) {
    fail('overview_biz_sql', "biz={$ovBiz} hard max 30 (cold={$ovCold})");
} elseif ($ovBiz > 25) {
    echo "WARN overview_biz_sql={$ovBiz} (target 15-25), cold={$ovCold}\n";
    pass('overview_biz_sql', (string)$ovBiz . " cold={$ovCold}");
} else {
    pass('overview_biz_sql', (string)$ovBiz . " cold={$ovCold}");
}

$trBiz = (int)($coldResults['trend_actual']['sql']['biz'] ?? 999);
if ($trBiz <= 4) {
    pass('trend_actual_biz_sql', (string)$trBiz);
} else {
    fail('trend_actual_biz_sql', "biz={$trBiz} expect<=4");
}

$srCold = (int)($coldResults['store_ranking']['sql']['cold'] ?? 999);
$srBiz = (int)($coldResults['store_ranking']['sql']['biz'] ?? 999);
if ($srCold > 30) {
    fail('store_ranking_cold_sql', "cold={$srCold} hard max 30 (biz={$srBiz})");
} else {
    pass('store_ranking_cold_sql', (string)$srCold);
}
if ($srBiz > 30) {
    fail('store_ranking_biz_sql', "biz={$srBiz} hard max 30");
} else {
    pass('store_ranking_biz_sql', (string)$srBiz . " cold={$srCold}");
}

$stBiz = (int)($coldResults['staff_ranking']['sql']['biz'] ?? 999);
if ($stBiz <= 8) {
    pass('staff_ranking_biz_sql', (string)$stBiz);
} else {
    fail('staff_ranking_biz_sql', "biz={$stBiz} expect<=8");
}

// EXPLAIN（全量门店）
$inList = implode(',', $allStores ?: [0]);
$sourceIn = implode(',', array_map('intval', $ctx['sourceAttr'] ?: [0]));
$yejiTable = Db::name('staff_yeji')->getTable();
$trendStart = strtotime(date('Y-m-d 00:00:00', strtotime('-29 day')));
$trendEnd = strtotime(date('Y-m-d 23:59:59'));
$explains = [
    'source_order_member' => "EXPLAIN SELECT a.store_id, COUNT(DISTINCT CONCAT(FROM_UNIXTIME(a.add_time, '%Y%m%d'), '_', a.uid)) AS cnt
FROM eb_store_order a
WHERE a.paid=1 AND a.refund_status=0 AND a.is_system_del=0
AND a.store_id IN ({$inList}) AND a.order_type IN (0,1)
AND a.source IN ({$sourceIn})
AND a.uid>0 AND TRIM(IFNULL(a.service_object,'')) <> '朋友'
AND a.add_time BETWEEN {$start} AND {$end}
AND NOT EXISTS (SELECT 1 FROM {$yejiTable} sy WHERE sy.order_id=a.id AND sy.store_id=a.store_id AND sy.status=0 AND sy.type IN (1,2))
GROUP BY a.store_id",
    'cash_by_store' => "EXPLAIN SELECT store_id, SUM(cash_pay_price) AS total
FROM eb_store_order
WHERE paid=1 AND store_id IN ({$inList}) AND add_time BETWEEN {$start} AND {$end}
GROUP BY store_id",
    'old_shop_both' => "EXPLAIN SELECT store_id, SUM(cash_money) AS cash_total, SUM(use_money) AS use_total
FROM eb_old_shop_money
WHERE store_id IN ({$inList}) AND add_time BETWEEN {$start} AND {$end}
GROUP BY store_id",
    'writeoff_active' => "EXPLAIN SELECT relation_id, SUM(writeoff_price) AS total
FROM eb_store_order_writeoff
WHERE status=0 AND relation_id IN ({$inList})
AND add_time BETWEEN {$start} AND {$end}
GROUP BY relation_id",
    'fencheng_yeji' => "EXPLAIN SELECT y.store_id, SUM(y.yeji) AS total
FROM eb_staff_yeji y
INNER JOIN eb_system_store_staff s ON s.id=y.staff_id
WHERE s.is_fencheng=1 AND y.store_id IN ({$inList}) AND y.type IN (1,2) AND y.status=0
AND y.created_time BETWEEN '" . date('Y/m/01') . "' AND '" . date('Y/m/d') . " 23:59:59'
GROUP BY y.store_id",
    'refund' => "EXPLAIN SELECT store_id, SUM(refunded_price) AS total
FROM eb_store_order_refund
WHERE store_id IN ({$inList}) AND refund_type=6 AND is_cancel=0 AND is_del=0
AND refunded_time BETWEEN {$start} AND {$end}
GROUP BY store_id",
    'store_user' => "EXPLAIN SELECT store_id, COUNT(*) AS cnt
FROM eb_store_user
WHERE store_id IN ({$inList}) AND add_time BETWEEN {$start} AND {$end}
GROUP BY store_id",
    'reservation' => "EXPLAIN SELECT store_id, COUNT(DISTINCT uid) AS cnt
FROM eb_store_reservation_order
WHERE store_id IN ({$inList}) AND is_del=0 AND uid>0
AND reservation_time BETWEEN {$start} AND {$end}
GROUP BY store_id",
];

echo "\n=== EXPLAIN (all authorized stores) ===\n";
$explainRisks = [];
foreach ($explains as $name => $sql) {
    echo "-- {$name}\n";
    try {
        $rows = Db::query($sql);
        foreach ($rows as $r) {
            $type = (string)($r['type'] ?? '');
            $key = (string)($r['key'] ?? '');
            $rowsEst = (string)($r['rows'] ?? '');
            $extra = (string)($r['Extra'] ?? '');
            echo sprintf("type=%s key=%s rows=%s Extra=%s\n", $type, $key, $rowsEst, $extra);
            if ($type === 'ALL' || ($type === 'index' && (int)$rowsEst > 10000)) {
                $explainRisks[] = "{$name}: type={$type} key={$key} rows={$rowsEst}";
            }
        }
    } catch (Throwable $e) {
        echo 'EXPLAIN_ERR ' . $e->getMessage() . "\n";
        fail('explain_' . $name, $e->getMessage());
    }
}

echo "\n=== INDEX GAP (recommendation only; DO NOT ALTER HERE) ===\n";
$tableRows = [
    'eb_store_order' => (int)Db::name('store_order')->count(),
    'eb_store_order_writeoff' => (int)Db::name('store_order_writeoff')->count(),
    'eb_store_order_refund' => (int)Db::name('store_order_refund')->count(),
    'eb_store_user' => (int)Db::name('store_user')->count(),
    'eb_store_reservation_order' => (int)Db::name('store_reservation_order')->count(),
    'eb_old_shop_money' => (int)Db::name('old_shop_money')->count(),
    'eb_staff_yeji' => (int)Db::name('staff_yeji')->count(),
];
foreach ($tableRows as $t => $c) {
    echo "rows {$t}={$c}\n";
}
if ($explainRisks) {
    echo "RISK_COUNT=" . count($explainRisks) . "\n";
    foreach ($explainRisks as $r) {
        echo "RISK {$r}\n";
    }
    echo "RECOMMEND_PACKAGE=任务管理/数据库升级文件/2026-07-21-经营看板统计索引/\n";
    pass('explain_risk_documented', (string)count($explainRisks));
} else {
    pass('explain_no_high_risk', 'ok');
}

echo "\n=== SUMMARY ===\n";
echo "store_count={$storeCount}\n";
if ($failures) {
    echo 'FAILED ' . count($failures) . "\n";
    foreach ($failures as $f) {
        echo " - {$f}\n";
    }
    exit(1);
}
echo "ALL_PASS\n";
exit(0);
