<?php

/** 本地只读验证：执行门店入口缺省处理与所有门店报表真实查询，不写事实、不连接线上。 */
if (getenv('R10_LOCAL_TEST') !== '1') throw new RuntimeException('Explicit local test flag required');
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require rtrim($backend, '/\\') . '/vendor/autoload.php';
$app = new \think\App(rtrim($backend, '/\\') . '/');
$app->initialize();
$host = (string)\think\facade\Db::getConfig('connections.mysql.hostname');
if (!in_array($host, ['mysql', '127.0.0.1', 'localhost'], true)) throw new RuntimeException('Local database required');

function r10Assert($condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$reflection = new ReflectionClass(\app\controller\cashier\v3\Report::class);
$controller = $reflection->newInstanceWithoutConstructor();
$defaults = $reflection->getMethod('withDefaultDates');
$defaults->setAccessible(true);
$platformOnly = $reflection->getMethod('isPlatformOnlyReport');
$platformOnly->setAccessible(true);
$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
$normalized = $defaults->invoke($controller, ['start_date' => '', 'end_date' => '']);
r10Assert($normalized === ['start_date' => $today, 'end_date' => $today], 'Server default must be today');
foreach ([['start_date' => '2026-09-02'], ['end_date' => '2026-09-02']] as $input) {
    $actual = $defaults->invoke($controller, $input);
    r10Assert($actual['start_date'] === '2026-09-02' && $actual['end_date'] === '2026-09-02', 'Partial date must stay a single day');
}
$explicit = ['start_date' => '2026-09-03', 'end_date' => '2026-10-05', 'category_path' => '/1/'];
r10Assert($defaults->invoke($controller, $explicit) === $explicit, 'Explicit range and filters must survive');

// 从正式目录枚举而非手抄固定列表，新增门店报表也必须验证首查与导出使用同一实际日期。
$base = new \app\services\report\StoreUnifiedReportServices();
$four = new \app\services\report\StoreUnifiedReportPhaseFourServices();
$six = new \app\services\report\StoreUnifiedReportPhaseSixServices();
$catalog = array_merge($base->catalog(), $four::catalogEntries(false), $six::catalogEntries(false));
$codes = array_values(array_unique(array_column(array_filter($catalog, static function ($entry) use ($platformOnly, $controller): bool {
    return !$platformOnly->invoke($controller, (string)$entry['code']);
}), 'code')));
$stores = array_map('intval', \think\facade\Db::name('cashier_v3_sale_fact')
    ->where('business_date', '>=', substr($today, 0, 7) . '-01')->group('store_id')->limit(3)->column('store_id'));
r10Assert((bool)$stores, 'Local month must contain sale fixtures');
$run = static function (string $code, array $input) use ($base, $four, $six, $stores): array {
    $input['report'] = $code;
    $range = ['start' => $input['start_date'], 'end' => $input['end_date']];
    return $six->supports($code) ? $six->query($code, $stores, $range, $input)
        : ($four->supports($code) ? $four->query($code, $stores, $range, $input) : $base->query($stores, $input));
};
$failures = [];
foreach ($codes as $code) {
    try {
        $input = $defaults->invoke($controller, ['report' => $code, '_internal_all' => true, 'page' => 1, 'limit' => 20]);
        $result = $run($code, $input);
        $range = $result['scope']['date_range'] ?? [
            'start' => $result['filters']['start_date'] ?? '', 'end' => $result['filters']['end_date'] ?? '',
        ];
        r10Assert($range === ['start' => $today, 'end' => $today], $code . ': returned range must equal today');
        r10Assert(is_array($result['records']) && is_array($result['columns']), $code . ': result contract');
        foreach ($result['records'] as $row) {
            // 日期明细必须在范围内；不将历史登记日/年度指标误当成本期业务日改写。
            if (isset($row['business_date'])) r10Assert($row['business_date'] === $today, $code . ': out-of-range business date');
        }
        $export = $six->supports($code) || $four->supports($code) ? $run($code, $input) : $base->export($stores, $input);
        r10Assert(($export['records'] ?? []) === $result['records'], $code . ': export differs from complete query');
        r10Assert(($export['summary_row'] ?? []) === ($result['summary_row'] ?? []), $code . ': export summary differs');
        echo 'PASS ' . $code . ' range=' . $today . '..' . $today . ' rows=' . count($result['records']) . PHP_EOL;
    } catch (Throwable $error) {
        $failures[] = $code . ': ' . $error->getMessage();
        echo 'FAIL ' . end($failures) . PHP_EOL;
    }
}

// 使用本地已有有效事实做反例：月查询有历史行，当天查询不能混入这些历史行。
$monthInput = ['start_date' => substr($today, 0, 7) . '-01', 'end_date' => $today, '_internal_all' => true];
$month = $run('member_consumption_detail', $monthInput);
$day = $run('member_consumption_detail', $normalized + ['_internal_all' => true]);
$historical = array_filter($month['records'], static fn(array $row): bool => (string)($row['business_date'] ?? '') < $today);
r10Assert(count($historical) > 0, 'Need historical rows to demonstrate date exclusion');
r10Assert(count($month['records']) > count($day['records']), 'Day result must not silently remain month-to-date');
echo 'PASS historical exclusion month_rows=' . count($month['records']) . ' today_rows=' . count($day['records']) . ' excluded=' . count($historical) . PHP_EOL;
r10Assert(!$failures, implode('; ', $failures));
echo 'PASS ' . count($codes) . ' actual local report queries/exports; defaults, explicit dates, historical exclusion; no DB writes' . PHP_EOL;
