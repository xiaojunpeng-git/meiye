<?php
/**
 * 经营看板阶段1 smoke（测试收口）
 *
 * SQL 口径（写入开发单 §8.4a，本脚本强制执行）：
 * - listen_raw：独立 PHP 进程内 Db::listen 捕获条数（含 CONNECT 握手、SHOW FULL COLUMNS）。
 * - 冷启动总 SQL(cold)：listen_raw − CONNECT（连接握手非查询；门店列表在 listen 前解析，不计入）。
 * - 业务统计 SQL(biz)：cold − schema 探测（SHOW FULL/COLUMNS / TABLES / INDEX）。
 * - 门槛 概览≈15～25 / 硬上限30、实际业绩趋势≤4、员工排行≤8 均针对「业务统计 SQL」。
 * - 冷启动总数必须打印；门店排行冷启动总数硬上限 ≤30（含 schema，不含 CONNECT）。
 *
 * 禁止对共享库 insert/delete 做跨店回归；改为 SQL 结构断言（须含 sy.store_id = a.store_id）。
 *
 * docker cp 美容源码/scripts/smoke-business-dashboard-phase1.php mohe-app:/tmp/
 * docker exec mohe-app php /tmp/smoke-business-dashboard-phase1.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$scenario = '';
$storesArg = '';
foreach ($argv as $arg) {
    if (strpos($arg, '--scenario=') === 0) {
        $scenario = substr($arg, strlen('--scenario='));
    }
    if (strpos($arg, '--stores=') === 0) {
        $storesArg = substr($arg, strlen('--stores='));
    }
}

require '/var/www/html/vendor/autoload.php';
$app = new think\App();
$app->initialize();

use app\model\store\SystemStore;
use app\services\order\agent\AgentOrderServices;
use app\services\organization\OrganizationScopeService;
use app\services\report\ReportServices;
use app\services\statistics\BusinessDashboardServices;
use think\facade\Db;

/**
 * @return array{0:bool,1:string}
 */
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
    // 门店 ID 由父进程传入（等价 HTTP 入参），禁止在本进程 listen 前查库预热 schema
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
        case 'reservation_detail':
            // 仅明细；卡片 count 对齐由主进程用独立 overview 冷启动结果比对
            $payload['result'] = $dash->reservationDetail($allStores, $start, $end, 1, 20);
            break;
        case 'new_profile_detail':
            $payload['result'] = $dash->newProfileDetail($allStores, $start, $end, 1, 20);
            break;
        default:
            fwrite(STDERR, "unknown scenario\n");
            exit(2);
    }

    $sum = summarizeSql($sqlLog);
    $payload['sql'] = $sum;
    $payload['sql_samples'] = array_slice(array_map(function ($r) {
        return preg_replace('/\s+/', ' ', substr((string)$r['sql'], 0, 160));
    }, $sqlLog), 0, 40);
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

$self = '/tmp/smoke-business-dashboard-phase1.php';
if (!is_file($self)) {
    $self = __FILE__;
}

/**
 * @param int[] $storeIds
 * @return array<string,mixed>
 */
function runColdScenario(string $self, string $name, array $storeIds): array
{
    $stores = implode(',', array_map('intval', $storeIds));
    $cmd = 'php ' . escapeshellarg($self)
        . ' --scenario=' . escapeshellarg($name)
        . ' --stores=' . escapeshellarg($stores)
        . ' 2>&1';
    $out = shell_exec($cmd);
    $line = trim((string)$out);
    // 取最后一行 JSON
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

echo "=== Business Dashboard Phase1 Smoke (R2 测试收口) ===\n";
echo "口径: cold=listen−CONNECT; biz=cold−SHOW; 门槛看 biz; 门店排行 cold≤30; 各场景独立进程\n";
echo "跨店回归: SQL结构断言(须含 sy.store_id=a.store_id)，禁止共享库 mutate；前提不足则 FAIL\n\n";

$allStores = SystemStore::where('is_del', 0)->where('name', '<>', '总部')->column('id');
$allStores = array_values(array_filter(array_map('intval', $allStores)));
$sampleStores = array_slice($allStores, 0, 3);
$timeStr = date('Y/m/01') . '-' . date('Y/m/d');
$start = strtotime(date('Y-m-01 00:00:00'));
$end = strtotime(date('Y-m-d 23:59:59'));
echo 'stores_sample=' . implode(',', $sampleStores) . ' all=' . count($allStores) . " time={$timeStr}\n\n";

/** @var AgentOrderServices $agent */
$agent = app()->make(AgentOrderServices::class);
/** @var ReportServices $report */
$report = app()->make(ReportServices::class);
/** @var BusinessDashboardServices $dash */
$dash = app()->make(BusinessDashboardServices::class);
/** @var OrganizationScopeService $scope */
$scope = app()->make(OrganizationScopeService::class);

// ---- 对账（同进程，不计门槛）----
$home = $agent->homeStatics(['store_id' => $sampleStores, 'time' => $timeStr]);
$overview = $dash->overview($sampleStores, $start, $end, $timeStr);
$homeBy = [];
foreach ($home as $row) {
    $homeBy[$row['metric_code']] = (string)$row['number'];
}
$ovBy = [];
foreach ($overview['cards'] as $card) {
    $ovBy[$card['metric_code']] = (string)$card['value'];
}
foreach (['cash_performance', 'actual_performance', 'consume_amount'] as $code) {
    $h = bcadd((string)($homeBy[$code] ?? '0'), '0', 2);
    $o = bcadd((string)($ovBy[$code] ?? '0'), '0', 2);
    if (bccomp($h, $o, 2) === 0) {
        pass("home_vs_overview_{$code}", $h);
    } else {
        fail("home_vs_overview_{$code}", "home={$h} overview={$o}");
    }
}

$range = [$start, $end];
$ctx = $report->buildReportSaleSourceContext();
if (empty($ctx['sourceAttr'])) {
    fail('report_sale_context', 'sourceAttr empty');
} else {
    pass('report_sale_context', 'sources=' . count($ctx['sourceAttr']));
}
foreach ($sampleStores as $sid) {
    $legacyC = (int)$report->sourceOrder($ctx['sourceAttr'], 0, $range, 1, $sid, $ctx['productIds']);
    $batchC = (int)($report->countSourceOrderByStores([$sid], 0, $range)[$sid] ?? 0);
    $phpC = (int)($report->countSourceOrderByStoresViaPhp([$sid], 0, $range)[$sid] ?? 0);
    $legacyN = (int)$report->sourceOrder($ctx['sourceAttr'], 1, $range, 1, $sid, $ctx['productIds']);
    $batchN = (int)($report->countSourceOrderByStores([$sid], 1, $range)[$sid] ?? 0);
    $phpN = (int)($report->countSourceOrderByStoresViaPhp([$sid], 1, $range)[$sid] ?? 0);
    if ($legacyC === $batchC && $batchC === $phpC) {
        pass("casual_store_{$sid}", (string)$batchC);
    } else {
        fail("casual_store_{$sid}", "legacy={$legacyC} batch={$batchC} php={$phpC}");
    }
    if ($legacyN === $batchN && $batchN === $phpN) {
        pass("new_store_{$sid}", (string)$batchN);
    } else {
        fail("new_store_{$sid}", "legacy={$legacyN} batch={$batchN} php={$phpN}");
    }
}

// ---- 跨店排除：SQL 结构断言（不写共享库）----
$yejiTable = Db::name('staff_yeji')->getTable();
$baseSql = '';
try {
    $q = $report->buildSourceOrderBaseQuery($sampleStores ?: [1], $ctx['sourceAttr'], 0, $range, $ctx['hezuofangStaffIds'] ?? []);
    $baseSql = method_exists($q, 'buildSql') ? (string)$q->buildSql(true) : (string)$q;
    // ThinkPHP 部分版本 buildSql 不可用，退回 fetchSql
    if ($baseSql === '' || $baseSql === '0') {
        $baseSql = (string)$q->field('a.id')->fetchSql(true)->select();
    }
} catch (Throwable $e) {
    fail('coop_exclude_sql_capture', $e->getMessage());
    $baseSql = '';
}
$norm = strtolower(preg_replace('/\s+/', ' ', $baseSql));
$hasNotExists = strpos($norm, 'not exists') !== false;
$hasStoreJoin = (strpos($norm, 'sy.store_id = a.store_id') !== false)
    || (strpos($norm, 'sy.store_id=a.store_id') !== false);
$hasOrderJoin = (strpos($norm, 'sy.order_id = a.id') !== false)
    || (strpos($norm, 'sy.order_id=a.id') !== false);
if ($baseSql === '') {
    fail('coop_exclude_structure', 'empty SQL');
} elseif ($hasNotExists && $hasStoreJoin && $hasOrderJoin) {
    pass('coop_exclude_structure', 'NOT EXISTS + sy.store_id=a.store_id + sy.order_id=a.id');
} else {
    fail(
        'coop_exclude_structure',
        'missing correlated exclude; hasNotExists=' . (int)$hasNotExists
        . ' hasStoreJoin=' . (int)$hasStoreJoin
        . ' hasOrderJoin=' . (int)$hasOrderJoin
        . ' sql=' . substr($norm, 0, 300)
    );
}
// 源码双保险（防止 fetchSql 变形）
$src = file_get_contents('/var/www/html/app/services/report/ReportServices.php') ?: '';
if (strpos($src, 'sy.store_id = a.store_id') === false && strpos($src, 'sy.store_id=a.store_id') === false) {
    fail('coop_exclude_source', 'ReportServices.php missing sy.store_id = a.store_id');
} else {
    pass('coop_exclude_source', 'present');
}

// ---- 独立冷启动场景 ----
echo "\n=== COLD START (independent processes) ===\n";
$coldResults = [];
foreach (['overview', 'trend_actual', 'trend_cash', 'store_ranking', 'staff_ranking', 'reservation_detail', 'new_profile_detail'] as $name) {
    try {
        $coldResults[$name] = runColdScenario($self, $name, $allStores);
        printSqlSummary($name, $coldResults[$name]['sql']);
    } catch (Throwable $e) {
        fail('cold_' . $name, $e->getMessage());
    }
}

// 门槛：业务 SQL
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
$trCold = (int)($coldResults['trend_actual']['sql']['cold'] ?? 999);
if ($trBiz <= 4) {
    pass('trend_actual_biz_sql', (string)$trBiz . " cold={$trCold}");
} else {
    fail('trend_actual_biz_sql', "biz={$trBiz} expect<=4 cold={$trCold}");
}
$points = count($coldResults['trend_actual']['result']['xAxis'] ?? []);
if ($points >= 28) {
    pass('trend_actual_points', (string)$points);
} else {
    fail('trend_actual_points', (string)$points);
}

$srBiz = (int)($coldResults['store_ranking']['sql']['biz'] ?? 999);
$srCold = (int)($coldResults['store_ranking']['sql']['cold'] ?? 999);
if ($srCold > 30) {
    fail('store_ranking_cold_sql', "cold={$srCold} hard max 30 (biz={$srBiz})");
} else {
    pass('store_ranking_cold_sql', (string)$srCold);
}
if ($srBiz > 30) {
    fail('store_ranking_biz_sql', "biz={$srBiz} hard max 30");
} elseif ($srBiz > 25) {
    echo "WARN store_ranking_biz_sql={$srBiz} (target 15-25), cold={$srCold}\n";
    pass('store_ranking_biz_sql', (string)$srBiz . " cold={$srCold}");
} else {
    pass('store_ranking_biz_sql', (string)$srBiz . " cold={$srCold}");
}

$stBiz = (int)($coldResults['staff_ranking']['sql']['biz'] ?? 999);
$stCold = (int)($coldResults['staff_ranking']['sql']['cold'] ?? 999);
if ($stBiz <= 8) {
    pass('staff_ranking_biz_sql', (string)$stBiz . " cold={$stCold}");
} else {
    fail('staff_ranking_biz_sql', "biz={$stBiz} expect<=8 cold={$stCold}");
}

// 明细 count 与卡片：卡片用独立 overview 冷启动结果
$ovCards = [];
foreach (($coldResults['overview']['result']['cards'] ?? []) as $card) {
    $ovCards[$card['metric_code']] = $card['value'];
}
$resCount = (int)($coldResults['reservation_detail']['result']['count'] ?? -1);
$cardRes = (int)($ovCards['reservation_customer'] ?? -2);
if ($resCount === $cardRes) {
    pass('reservation_detail_count', (string)$resCount);
} else {
    fail('reservation_detail_count', "detail={$resCount} card={$cardRes}");
}
$profCount = (int)($coldResults['new_profile_detail']['result']['count'] ?? -1);
$cardProf = (int)($ovCards['new_profile_count'] ?? -2);
if ($profCount === $cardProf) {
    pass('new_profile_detail_count', (string)$profCount);
} else {
    fail('new_profile_detail_count', "detail={$profCount} card={$cardProf}");
}

// 越权
$empty1 = $scope->resolveDashboardStoreIds(0, 999999001, $allStores);
$empty2 = $scope->resolveDashboardStoreIds(999999002, 0, $allStores);
if ($empty1 === [] && $empty2 === []) {
    pass('scope_unauthorized_empty', 'ok');
} else {
    fail('scope_unauthorized_empty', json_encode([$empty1, $empty2], JSON_UNESCAPED_UNICODE));
}
$rootId = $scope->resolveGroupRootOrgId();
pass('group_root_resolved', (string)$rootId);

// EXPLAIN
$inList = implode(',', $sampleStores ?: [0]);
$sourceIn = implode(',', array_map('intval', $ctx['sourceAttr'] ?: [0]));
$trendStart = strtotime(date('Y-m-d 00:00:00', strtotime('-29 day')));
$trendEnd = strtotime(date('Y-m-d 23:59:59'));
$explains = [
    'source_order_member_correlated' => "EXPLAIN SELECT a.store_id, COUNT(DISTINCT CONCAT(FROM_UNIXTIME(a.add_time, '%Y%m%d'), '_', a.uid)) AS cnt
FROM eb_store_order a
WHERE a.paid=1 AND a.refund_status=0 AND a.is_system_del=0
AND a.store_id IN ({$inList}) AND a.order_type IN (0,1)
AND a.source IN ({$sourceIn})
AND a.uid>0 AND TRIM(IFNULL(a.service_object,'')) <> '朋友'
AND a.add_time BETWEEN {$start} AND {$end}
AND NOT EXISTS (SELECT 1 FROM {$yejiTable} sy WHERE sy.order_id=a.id AND sy.store_id=a.store_id AND sy.status=0 AND sy.type IN (1,2))
GROUP BY a.store_id",
    'actual_cash_by_day_store' => "EXPLAIN SELECT store_id, FROM_UNIXTIME(add_time,'%Y-%m-%d') AS day, SUM(cash_pay_price) AS total
FROM eb_store_order
WHERE paid=1 AND store_id IN ({$inList}) AND add_time BETWEEN {$trendStart} AND {$trendEnd}
GROUP BY store_id, FROM_UNIXTIME(add_time,'%Y-%m-%d')",
    'old_shop_both' => "EXPLAIN SELECT store_id, SUM(cash_money) AS cash_total, SUM(use_money) AS use_total
FROM eb_old_shop_money
WHERE store_id IN ({$inList}) AND add_time BETWEEN {$start} AND {$end}
GROUP BY store_id",
    'writeoff_active' => "EXPLAIN SELECT relation_id, SUM(writeoff_price) AS total
FROM eb_store_order_writeoff
WHERE status=0 AND relation_id IN ({$inList})
AND add_time BETWEEN {$start} AND {$end}
GROUP BY relation_id",
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

echo "\n=== EXPLAIN ===\n";
foreach ($explains as $name => $sql) {
    echo "-- {$name}\n";
    try {
        $rows = Db::query($sql);
        foreach ($rows as $r) {
            echo sprintf(
                "type=%s key=%s rows=%s Extra=%s\n",
                $r['type'] ?? '',
                $r['key'] ?? '',
                $r['rows'] ?? '',
                $r['Extra'] ?? ''
            );
        }
    } catch (Throwable $e) {
        echo 'EXPLAIN_ERR ' . $e->getMessage() . "\n";
        fail('explain_' . $name, $e->getMessage());
    }
}

echo "\n=== SUMMARY ===\n";
if ($failures) {
    echo 'FAILED ' . count($failures) . "\n";
    foreach ($failures as $f) {
        echo " - {$f}\n";
    }
    exit(1);
}
echo "ALL_PASS\n";
exit(0);
