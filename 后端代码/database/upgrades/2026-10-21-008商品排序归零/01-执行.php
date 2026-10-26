<?php
declare(strict_types=1);

/**
 * 将 008 商品列表的排序值归零。固定清单记录原 ID/排序值，既用作并发漂移守卫，
 * 也保留逐行恢复依据；不修改上下架、价格、库存或历史业务事实。
 */
const M008_SORT_ENV = '/www/wwwroot/008.cc3798.com/.env';
const M008_SORT_PLAN_SHA = 'c58ba4b118b71181cc00dd5a396ae504c8a96f996d37b6c93f35260cd508d52b';
const M008_SORT_UPGRADE = '20261021-008-product-sort-zero';
const M008_SORT_TASK_FILE = '后端代码/database/upgrades/2026-10-21-008商品排序归零/01-执行.php';

/** 固定来源文件必须来自 008 当前商品表，防止把其他实例的清单用于写入。 */
function m008SortPlan(): array
{
    $path = __DIR__ . '/source-plan.json';
    if (!hash_equals(M008_SORT_PLAN_SHA, (string)hash_file('sha256', $path))) {
        throw new RuntimeException('商品排序清单指纹不符');
    }
    $plan = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (($plan['target_database'] ?? '') !== 'mohe_008' || (int)($plan['target_port'] ?? 0) !== 3307
        || ($plan['table'] ?? '') !== 'eb_store_product' || (int)($plan['all_count'] ?? 0) !== 6404
        || (int)($plan['nonzero_count'] ?? 0) !== 2483 || count($plan['rows'] ?? []) !== 2483) {
        throw new RuntimeException('商品排序清单范围不符');
    }
    $previous = 0;
    foreach ($plan['rows'] as $row) {
        $id = (int)($row['id'] ?? 0);
        if ($id <= $previous || (int)($row['sort'] ?? 0) === 0) {
            throw new RuntimeException('商品排序清单有重复、乱序或零值');
        }
        $previous = $id;
    }
    return $plan;
}

/** 连接身份只允许 008 独立库，不沿用命令执行目录或默认环境。 */
function m008SortDb(): PDO
{
    $env = parse_ini_file(M008_SORT_ENV, true, INI_SCANNER_RAW)['DATABASE'] ?? [];
    $clean = static function ($value): string { return trim((string)$value, " \t\n\r\0\x0B\"'"); };
    foreach ($env as $key => $value) $env[$key] = $clean($value);
    if (($env['DATABASE'] ?? '') !== 'mohe_008' || (int)($env['HOSTPORT'] ?? 0) !== 3307
        || !in_array($env['HOSTNAME'] ?? '', ['localhost', '127.0.0.1'], true)
        || ($env['PREFIX'] ?? 'eb_') !== 'eb_') {
        throw new RuntimeException('008 数据库身份守卫失败');
    }
    $db = new PDO('mysql:host=' . $env['HOSTNAME'] . ';port=3307;dbname=mohe_008;charset=utf8mb4',
        $env['USERNAME'], $env['PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_STRINGIFY_FETCHES => true]);
    $identity = $db->query('SELECT DATABASE() db, @@port port')->fetch();
    if (($identity['db'] ?? '') !== 'mohe_008' || (int)($identity['port'] ?? 0) !== 3307) {
        throw new RuntimeException('连接到了非 008 数据库');
    }
    return $db;
}

/** 前后状态必须完整，局部更新或清单漂移不能自动重试。 */
function m008SortState(PDO $db, array $plan): string
{
    $count = (int)$db->query('SELECT COUNT(*) FROM eb_store_product')->fetchColumn();
    $rows = $db->query('SELECT id,sort FROM eb_store_product WHERE sort<>0 ORDER BY id')->fetchAll();
    $query = $db->prepare('SELECT sql_checksum,result_note FROM eb_database_upgrade_log WHERE upgrade_key=?');
    $query->execute([M008_SORT_UPGRADE]);
    $markers = $query->fetchAll();
    if ($count === (int)$plan['all_count'] && $rows === $plan['rows'] && !$markers) return 'ready';
    $note = count($markers) === 1 ? json_decode((string)$markers[0]['result_note'], true) : null;
    if (!$rows && count($markers) === 1
        && ($note['plan_sha256'] ?? '') === M008_SORT_PLAN_SHA
        && (int)($note['updated'] ?? 0) === 2483
        && ($markers[0]['sql_checksum'] ?? '') === hash_file('sha256', __FILE__)) return 'applied';
    throw new RuntimeException('008 商品排序清单、状态或升级记录发生漂移');
}

/** 锁住当前商品行，在一个 InnoDB 事务中归零并记录固定版。 */
function m008SortApply(PDO $db, array $plan, string $commit): void
{
    if (!preg_match('/^[a-f0-9]{40}$/', $commit)) throw new RuntimeException('缺少已验收提交');
    $db->beginTransaction();
    try {
        $locked = $db->query('SELECT id,sort FROM eb_store_product ORDER BY id FOR UPDATE')->fetchAll();
        $nonzero = array_values(array_filter($locked, static function (array $row): bool {
            return (int)$row['sort'] !== 0;
        }));
        if (count($locked) !== (int)$plan['all_count'] || $nonzero !== $plan['rows']
            || m008SortState($db, $plan) !== 'ready') {
            throw new RuntimeException('商品排序执行前态已变化');
        }
        $changed = $db->exec('UPDATE eb_store_product SET sort=0 WHERE sort<>0');
        if ($changed !== 2483 || (int)$db->query('SELECT COUNT(*) FROM eb_store_product WHERE sort<>0')->fetchColumn() !== 0) {
            throw new RuntimeException('商品排序归零数量不符');
        }
        $time = date('Y-m-d H:i:s');
        $note = json_encode(['plan_sha256' => M008_SORT_PLAN_SHA, 'updated' => 2483],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $log = $db->prepare('INSERT INTO eb_database_upgrade_log
            (upgrade_key,title,task_file,sql_checksum,code_version,executed_at,executed_by,result_note,created_at)
            VALUES (?,?,?,?,?,?,?,?,?)');
        $log->execute([M008_SORT_UPGRADE, '008 商品排序归零', M008_SORT_TASK_FILE,
            hash_file('sha256', __FILE__), $commit, $time, 'Codex', $note, $time]);
        if (m008SortState($db, $plan) !== 'applied') throw new RuntimeException('商品排序事务内对账失败');
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

/** 数据提交后仅删除 008 的商品列表/详情缓存，失败时可单独重试。 */
function m008SortRefreshCache(): void
{
    $env = parse_ini_file(M008_SORT_ENV, true, INI_SCANNER_RAW);
    $clean = static function ($value): string { return trim((string)$value, " \t\n\r\0\x0B\"'"); };
    $redis = $env['REDIS'] ?? [];
    if ($clean($redis['REDIS_HOSTNAME'] ?? '') !== '127.0.0.1'
        || (int)$clean($redis['PORT'] ?? '') !== 6388
        || (int)$clean($redis['SELECT'] ?? '') !== 0
        || $clean($env['CACHE']['PREFIX'] ?? '') !== '008:') {
        throw new RuntimeException('008 缓存身份守卫失败');
    }
    $client = new Redis();
    $client->connect('127.0.0.1', 6388, 3.0);
    $password = $clean($redis['REDIS_PASSWORD'] ?? '');
    if ($password !== '') $client->auth($password);
    $client->select(0);
    $client->del('008:mc:store_product:map', '008:mc:store_product:page');
}

/** check/verify 只读；apply 必须带固定文件指纹与本地提交。 */
function m008SortMain(): void
{
    $opts = getopt('', ['action:', 'plan-sha:', 'script-sha:', 'commit:']);
    $action = $opts['action'] ?? 'check';
    if (!in_array($action, ['check', 'apply', 'verify', 'refresh-cache'], true)) throw new RuntimeException('未知操作');
    $plan = m008SortPlan();
    $db = m008SortDb();
    if ($action === 'apply') {
        if (($opts['plan-sha'] ?? '') !== M008_SORT_PLAN_SHA
            || ($opts['script-sha'] ?? '') !== hash_file('sha256', __FILE__)) {
            throw new RuntimeException('固定版本指纹不符');
        }
        if ((int)$db->query("SELECT GET_LOCK('008_product_sort_zero',15)")->fetchColumn() !== 1) {
            throw new RuntimeException('商品排序执行锁获取失败');
        }
        try { m008SortApply($db, $plan, (string)($opts['commit'] ?? '')); }
        finally { $db->query("SELECT RELEASE_LOCK('008_product_sort_zero')"); }
        m008SortRefreshCache();
    }
    $state = m008SortState($db, $plan);
    if ($action === 'refresh-cache') {
        if ($state !== 'applied') throw new RuntimeException('商品排序尚未归零');
        m008SortRefreshCache();
    }
    if ($action === 'verify' && $state !== 'applied') throw new RuntimeException('商品排序尚未归零');
    echo json_encode(['state' => $state, 'total' => (int)$plan['all_count'],
        'updated' => $state === 'applied' ? 2483 : 0, 'plan_sha256' => M008_SORT_PLAN_SHA],
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try { m008SortMain(); }
    catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . PHP_EOL); exit(1); }
}
