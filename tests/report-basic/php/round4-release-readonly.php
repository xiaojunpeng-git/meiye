<?php
declare(strict_types=1);

/**
 * 第4轮逐实例发布只读探针。在独立进程中优先加载已校验指纹的候选类和统一查询配置。
 * stdin 只提供实例代号、候选源码及报表代码；门店身份由本脚本固定，绝不接收浏览器范围。
 */
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$targets = [
    'rh' => ['database' => 'ruihao', 'port' => '3306', 'store' => 164],
    '008' => ['database' => 'mohe_008', 'port' => '3307', 'store' => 113],
    '010' => ['database' => 'mohe_010_app', 'port' => '3307', 'store' => 4],
];
$site = (string)($input['site'] ?? '');
if (!isset($targets[$site])) throw new RuntimeException('实例未授权');
$target = $targets[$site];
$root = getcwd();
if (!is_file($root . '/vendor/autoload.php')) throw new RuntimeException('后端运行目录错误');
require $root . '/vendor/autoload.php';

// sources={} 是部署前现网基线探针；有候选时必须同时固定目录、业务入口与配置。
$sources = $input['sources'] ?? null;
if (!is_array($sources)) throw new RuntimeException('候选来源格式错误');
$baseline = !$sources;
if (!$baseline && !isset($sources['config/unified_query.php'])) throw new RuntimeException('缺少统一查询候选配置');
$candidate = [];
$hashes = [];
foreach ($sources as $path => $source) {
    if (!is_string($path) || !preg_match('~^(?:app/[A-Za-z0-9_/]+|config/unified_query)\.php$~D', $path)
        || strpos($path, '//') !== false || !is_array($source)
        || !isset($source['base64'], $source['sha256'])) {
        throw new RuntimeException('候选来源路径或格式越界');
    }
    $bytes = base64_decode((string)$source['base64'], true);
    $hash = strtolower((string)$source['sha256']);
    if ($bytes === false || !preg_match('/^[0-9a-f]{64}$/D', $hash)
        || !hash_equals($hash, hash('sha256', $bytes))) {
        throw new RuntimeException('候选来源指纹错误');
    }
    $candidate[$path] = $bytes;
    $hashes[$path] = $hash;
}
if (!$baseline) foreach (['app/services/report/BasicReportCatalogServices.php',
    'app/services/report/BasicBusinessReportServices.php'] as $required) {
        if (!isset($candidate[$required])) throw new RuntimeException('缺少核心报表候选：' . $required);
    }
ksort($hashes);
$autoloaded = [];
spl_autoload_register(static function (string $class) use (&$candidate, &$autoloaded): void {
    if (strncmp($class, 'app\\', 4) !== 0) return;
    $path = str_replace('\\', '/', $class) . '.php';
    if (!isset($candidate[$path])) return;
    // 预置自动加载器比 Composer 先执行，确保本轮类取固定字节，而非本机共享工作区。
    $autoloaded[$path] = true;
    eval(substr($candidate[$path], 5));
}, true, true);

(new think\App($root . '/'))->initialize();
if (!$baseline) {
    $unifiedQuery = eval(substr($candidate['config/unified_query.php'], 5));
    if (!is_array($unifiedQuery)) throw new RuntimeException('统一查询候选配置无效');
    // 以整个命名空间替换，避免目标实例原有配置残留额外 registrar/provider。
    think\facade\Config::set(['unified_query' => $unifiedQuery]);
}
$db = config('database.connections.mysql');
if (!is_array($db) || ($db['database'] ?? '') !== $target['database']
    || (string)($db['hostport'] ?? '') !== $target['port']
    || ($db['prefix'] ?? '') !== 'eb_'
    || !in_array($db['hostname'] ?? '', ['localhost', '127.0.0.1'], true)) {
    throw new RuntimeException('数据库或端口身份错误');
}

$round4 = ['basic_sales_data', 'basic_sales_pk', 'basic_sales_analysis', 'basic_store_efficiency',
    'basic_store_summary', 'basic_cross_store_settlement', 'basic_store_item_sales',
    'basic_item_consumption', 'basic_writeoff_detail', 'basic_product_sales',
    'basic_balance_order_detail', 'basic_balance_order_summary', 'basic_daily_reconciliation',
    'basic_unconsumed_summary', 'basic_unconsumed_detail', 'basic_followup_employee_detail',
    'basic_customer_entitlements', 'basic_unconsumed_as_of_detail'];
$regression = ['basic_employee_item_sales', 'basic_employee_service_home_store',
    'basic_employee_service_store', 'basic_employee_performance_detail',
    'basic_salary_realtime', 'basic_employee_summary', 'basic_brush_registration', 'basic_store_reconciliation'];
$allowed = array_merge($round4, $regression);
$codes = $input['codes'] ?? $allowed;
if (!is_array($codes) || !$codes || array_diff($codes, $allowed) || count($codes) !== count(array_unique($codes))) {
    throw new RuntimeException('报表代码不在发布探针范围');
}
$start = (string)($input['start_date'] ?? '2026-10-01');
$end = (string)($input['end_date'] ?? '2026-10-30');
foreach ([$start, $end] as $date) {
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Asia/Shanghai'));
    if (!$parsed || $parsed->format('Y-m-d') !== $date) throw new RuntimeException('报表日期无效');
}
if ($start > $end || $end > (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d')) {
    throw new RuntimeException('报表日期范围无效');
}

/** 仅导出字段契约和数值摘要，不输出会员、员工、订单的任何明细值。 */
$probe = static function (string $code) use ($target, $start, $end): array {
    $context = app\services\report\BasicReportHostServices::context(
        [$target['store']], 'stores', 999901, 'platform', false, 0, $code
    );
    $current = app\services\report\BasicReportCatalogServices::definition($code)['time_mode'] === 'current';
    $last = $current ? (string)$context['query_cutoff_date'] : $end;
    $payload = [
        'pageCode' => $code, 'start_date' => $current ? $last : $start,
        'end_date' => $last, 'as_of_date' => $last,
    ];
    // 人工刷单登记使用统一查询字段筛选，不接受业务事实报表的顶层日期参数。
    if ($code === 'basic_brush_registration') $payload = ['pageCode' => $code];
    $result = app\services\report\BasicReportHostServices::query($context, $payload);
    $declared = $result['queryFields'] ?? [];
    $fields = array_values(array_filter($declared, static function ($field): bool {
        return !empty($field['defaultVisible']);
    }));
    $declaredKeys = array_column($declared, 'key');
    $contractRows = [];
    foreach (($result['rows'] ?? []) as $row) {
        $contract = [];
        foreach ($declaredKeys as $key) $contract[$key] = array_key_exists($key, $row) ? $row[$key] : null;
        $contractRows[] = $contract;
    }
    // 统一执行器的 summaries 是「field:operator => 数值/null」；兼容对象包装但只提取已声明列。
    $declaredSet = array_fill_keys($declaredKeys, true);
    $summary = [];
    foreach (($result['summaries'] ?? []) as $key => $value) {
        $fieldKey = explode(':', (string)$key, 2)[0];
        if (!isset($declaredSet[$fieldKey])) continue;
        if (is_array($value)) $value = $value['value'] ?? $value['sum'] ?? null;
        if ($value === null || (is_scalar($value) && is_numeric($value))) $summary[(string)$key] = $value;
    }
    $cards = [];
    foreach (($result['summary_cards'] ?? []) as $card) {
        if (!is_array($card) || !isset($card['key'])) continue;
        $value = $card['value'] ?? null;
        $cards[(string)$card['key']] = is_scalar($value) && is_numeric($value) ? $value : null;
    }
    return [
        'status' => 'pass', 'rows' => (int)($result['pagination']['total'] ?? 0),
        'page_rows' => count($result['rows'] ?? []),
        'rows_sha256' => hash('sha256', json_encode($contractRows, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
        'fields' => array_map(static function ($field): array {
            return ['key' => (string)$field['key'], 'label' => (string)$field['label']];
        }, $fields),
        'summary_position' => $result['table_layout']['summary_position'] ?? null,
        'summaries' => $summary, 'summary_cards' => $cards,
        'complete' => $result['complete'] ?? null,
        'gap_codes' => array_values(array_unique(array_filter(array_map(static function ($gap): string {
            return is_array($gap) ? (string)($gap['code'] ?? '') : '';
        }, $result['data_gaps'] ?? [])))),
    ];
};
$failure = static function (Throwable $error): array {
    $message = $error->getMessage();
    // 异常可能包含SQL和条件值，只保留机器码与消息指纹供服务端定位。
    preg_match('/\b[A-Z][A-Z0-9_]{5,}\b/', $message, $match);
    return ['status' => 'fail', 'error' => get_class($error),
        'message' => $match[0] ?? (preg_match('/SQL|SELECT|INSERT|UPDATE|DELETE|bindings|password/i', $message) ? '查询失败' : mb_substr($message, 0, 200)), 'message_sha256' => hash('sha256', $message)];
};

$connection = think\facade\Db::connect();
$connection->execute('SET SESSION max_execution_time = 8000');
$connection->execute('SET SESSION TRANSACTION READ ONLY');
$results = [];
$progress = fopen('php://stderr', 'w');
$runProbe = static function (string $code) use ($probe, $failure, $progress): array {
    fwrite($progress, 'START ' . $code . PHP_EOL);
    $started = microtime(true);
    try { $result = $probe($code); }
    catch (Throwable $error) { $result = $failure($error); }
    fwrite($progress, 'END ' . $code . ' ' . $result['status'] . ' '
        . (int)((microtime(true) - $started) * 1000) . 'ms' . PHP_EOL);
    return $result;
};
$ordinary = array_values(array_filter($codes, static function ($code): bool { return $code !== 'basic_salary_realtime'; }));
if ($ordinary) {
    $connection->startTrans();
    try {
        foreach ($ordinary as $code) $results[$code] = $runProbe($code);
    } finally { $connection->rollback(); }
}
if (in_array('basic_salary_realtime', $codes, true)) {
    // 工资 Reader 的一致性事务禁止外层嵌套；会话仍是 READ ONLY，内部自行开启只读事务。
    $results['basic_salary_realtime'] = $runProbe('basic_salary_realtime');
}
$migration = $connection->name('database_upgrade_log')->order('id', 'desc')
    ->field('upgrade_key,code_version,executed_at')->find();
$connection->execute('SET SESSION TRANSACTION READ WRITE');
echo json_encode([
    'site' => $site, 'database' => $target['database'], 'port' => $target['port'],
    'store_id' => $target['store'], 'range' => [$start, $end],
    'migration' => $migration,
    'candidate_sha256' => $hashes, 'candidate_loaded' => array_keys($autoloaded),
    'unified_query_fixed' => !$baseline, 'round4_codes' => $round4, 'regression_codes' => $regression,
    'reports' => $results, 'business_writes' => 0,
], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
// 现网基线可能尚无新增Provider；其失败也须保留为可比较JSON，判断交给发布控制器。
// Caller validates each result; always preserve diagnostic JSON on query failures.
exit(0);
