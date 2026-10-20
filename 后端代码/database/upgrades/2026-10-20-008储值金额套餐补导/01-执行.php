<?php
declare(strict_types=1);

/**
 * 将旧渼约的储值金额档位完整补入独立 008。
 *
 * 这批数据只定义新充值可选档位，不是会员余额、历史充值或赠金发放。
 * 保留原组和明细 ID，避免赠送配置及重导映射因重编号失效；仅在两张目标表
 * 没有本批组/ID 时写入，任何来源变化或目标局部冲突都拒绝自动覆盖。
 */
const M008_RECHARGE_SOURCE_ENV = '/www/wwwroot/morestore.cc3798.com/.env';
const M008_RECHARGE_TARGET_ENV = '/www/wwwroot/008.cc3798.com/.env';
const M008_RECHARGE_UPGRADE = '20261020-008-morestore-recharge-quotas';
const M008_RECHARGE_SNAPSHOT_SHA = '61972c1a162993b31e8569d508a618e1a1cc6a65bb518248a7ead30895f9994f';
const M008_RECHARGE_TASK_FILE = '后端代码/database/upgrades/2026-10-20-008储值金额套餐补导/01-执行.php';

/** 固定源文件同时约束来源实例、字段和逐条档位，避免在执行窗口误取新配置。 */
function m008RechargeSnapshot(string $file): array
{
    if (!hash_equals(M008_RECHARGE_SNAPSHOT_SHA, (string)hash_file('sha256', $file))) {
        throw new RuntimeException('储值来源快照指纹已变');
    }
    $plan = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($plan) || $plan['source_database'] !== 'morestore'
        || (int)$plan['source_port'] !== 3306 || $plan['target_database'] !== 'mohe_008'
        || (int)$plan['target_port'] !== 3307) {
        throw new RuntimeException('储值来源或目标实例不符');
    }
    $group = $plan['group'] ?? [];
    $fields = json_decode((string)($group['fields'] ?? ''), true);
    if ((int)($group['id'] ?? 0) !== 62 || (int)($group['cate_id'] ?? 0) !== 1
        || ($group['config_name'] ?? '') !== 'user_recharge_quota'
        || array_column($fields ?: [], 'title') !== ['price', 'give_money']
        || count($plan['rows'] ?? []) !== 13) {
        throw new RuntimeException('储值组结构或档位数量与固定版不符');
    }
    $seen = [];
    $active = 0;
    foreach ($plan['rows'] as $row) {
        $id = (int)($row['id'] ?? 0);
        $value = json_decode((string)($row['value'] ?? ''), true);
        if ($id <= 0 || isset($seen[$id]) || (int)($row['gid'] ?? 0) !== 62
            || !in_array((int)($row['status'] ?? -1), [0, 1], true)
            || !is_array($value) || array_keys($value) !== ['price', 'give_money']) {
            throw new RuntimeException('储值档位身份或值结构不符');
        }
        foreach (['price', 'give_money'] as $key) {
            $amount = $value[$key]['value'] ?? null;
            if (($value[$key]['type'] ?? '') !== 'input'
                || !preg_match('/^(0|[1-9][0-9]*)$/', (string)$amount)) {
                throw new RuntimeException('储值金额不是非负整数元：' . $id);
            }
        }
        if ((int)$value['price']['value'] <= 0) {
            throw new RuntimeException('储值售价必须为正整数元：' . $id);
        }
        $seen[$id] = true;
        $active += (int)$row['status'];
    }
    if ($active !== 4) {
        throw new RuntimeException('旧站启用档位数已变化');
    }
    return $plan;
}

/** 只连接固定的本机旧库或 008 库，且复核实际数据库和端口。 */
function m008RechargeConnect(string $envPath, string $database, int $port): PDO
{
    $env = parse_ini_file($envPath, true, INI_SCANNER_RAW)['DATABASE'] ?? [];
    $clean = static function ($value): string { return trim((string)$value, " \t\n\r\0\x0B\"'"); };
    foreach ($env as $key => $value) $env[$key] = $clean($value);
    if (($env['DATABASE'] ?? '') !== $database || (int)($env['HOSTPORT'] ?? 0) !== $port
        || !in_array($env['HOSTNAME'] ?? '', ['localhost', '127.0.0.1'], true)
        || ($env['PREFIX'] ?? 'eb_') !== 'eb_') {
        throw new RuntimeException('储值迁移数据库连接守卫失败');
    }
    $pdo = new PDO('mysql:host=' . $env['HOSTNAME'] . ';port=' . $port
        . ';dbname=' . $database . ';charset=utf8mb4', $env['USERNAME'], $env['PASSWORD'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STRINGIFY_FETCHES => true]);
    $identity = $pdo->query('SELECT DATABASE() db, @@port port')->fetch();
    if (($identity['db'] ?? '') !== $database || (int)($identity['port'] ?? 0) !== $port) {
        throw new RuntimeException('储值迁移连接到错误数据库');
    }
    return $pdo;
}

/** 字段逐字节比对；来源新加赠品缓存时中止，不能只迁售价而漏送赠品。 */
function m008RechargeAssertSource(PDO $source, array $plan): void
{
    $group = $source->query("SELECT id,cate_id,name,info,config_name,fields FROM eb_system_group
        WHERE config_name='user_recharge_quota'")->fetchAll();
    $rows = $source->query('SELECT id,gid,value,add_time,sort,status FROM eb_system_group_data
        WHERE gid=62 ORDER BY id')->fetchAll();
    if (count($group) !== 1 || $group[0] !== $plan['group'] || $rows !== $plan['rows']) {
        throw new RuntimeException('旧站储值配置相对固定快照已变化');
    }
    $gift = $source->query("SELECT COUNT(*) FROM eb_cache WHERE `key` IN
        ('recharge_gift_config_" . implode("','recharge_gift_config_", array_map('intval', array_column($plan['rows'], 'id'))) . "')")->fetchColumn();
    if ((int)$gift !== 0) {
        throw new RuntimeException('旧站新增储值赠品配置，需重新固定迁移范围');
    }
}

/** 对目标当前数据做完整比对；已应用时返回幂等状态，局部导入时停止。 */
function m008RechargeTargetState(PDO $target, array $plan): string
{
    $group = $plan['group'];
    $ids = array_map('intval', array_column($plan['rows'], 'id'));
    $idSql = implode(',', $ids);
    $lookup = $target->prepare('SELECT id,cate_id,name,info,config_name,fields FROM eb_system_group
        WHERE id=? OR config_name=? ORDER BY id');
    $lookup->execute([(int)$group['id'], $group['config_name']]);
    $groups = $lookup->fetchAll();
    $rows = $target->query('SELECT id,gid,value,add_time,sort,status FROM eb_system_group_data
        WHERE gid=62 OR id IN (' . $idSql . ') ORDER BY id')->fetchAll();
    $giftRows = (int)$target->query('SELECT COUNT(*) FROM eb_store_gift_config
        WHERE gift_type=2 AND relation_id IN (' . $idSql . ')')->fetchColumn();
    $marker = $target->prepare('SELECT sql_checksum,result_note FROM eb_database_upgrade_log WHERE upgrade_key=?');
    $marker->execute([M008_RECHARGE_UPGRADE]);
    $markers = $marker->fetchAll();
    if (!$groups && !$rows && !$markers && $giftRows === 0) return 'ready';
    $note = count($markers) === 1 ? json_decode((string)$markers[0]['result_note'], true) : null;
    if (count($groups) === 1 && $groups[0] === $group && $rows === $plan['rows']
        && $giftRows === 0 && count($markers) === 1
        && ($note['source_sha256'] ?? '') === M008_RECHARGE_SNAPSHOT_SHA
        && ($note['rows'] ?? 0) === 13 && ($note['active'] ?? 0) === 4
        && ($markers[0]['sql_checksum'] ?? '') === hash_file('sha256', __FILE__)) {
        return 'applied';
    }
    throw new RuntimeException('008 储值配置存在部分数据或审计冲突，不自动覆盖');
}

/** 一个事务写入组、13 条原档位和升级标记；旧停用状态保持停用。 */
function m008RechargeApply(PDO $target, array $plan, string $commit): void
{
    if (!preg_match('/^[a-f0-9]{40}$/', $commit)) throw new RuntimeException('缺少已验收本地提交');
    $target->beginTransaction();
    try {
        if (m008RechargeTargetState($target, $plan) !== 'ready') {
            throw new RuntimeException('008 储值配置已导入');
        }
        $g = $plan['group'];
        $groupInsert = $target->prepare('INSERT INTO eb_system_group
            (id,cate_id,name,info,config_name,fields) VALUES (?,?,?,?,?,?)');
        $groupInsert->execute(array_values($g));
        $rowInsert = $target->prepare('INSERT INTO eb_system_group_data
            (id,gid,value,add_time,sort,status) VALUES (?,?,?,?,?,?)');
        foreach ($plan['rows'] as $row) $rowInsert->execute(array_values($row));
        $note = json_encode(['source_sha256' => M008_RECHARGE_SNAPSHOT_SHA,
            'rows' => 13, 'active' => 4], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $now = date('Y-m-d H:i:s');
        $log = $target->prepare('INSERT INTO eb_database_upgrade_log
            (upgrade_key,title,task_file,sql_checksum,code_version,executed_at,executed_by,result_note,created_at)
            VALUES (?,?,?,?,?,?,?,?,?)');
        $log->execute([M008_RECHARGE_UPGRADE, '008 渼约储值金额套餐补导',
            M008_RECHARGE_TASK_FILE, hash_file('sha256', __FILE__), $commit, $now, 'Codex', $note, $now]);
        if (m008RechargeTargetState($target, $plan) !== 'applied') {
            throw new RuntimeException('储值配置事务内对账失败');
        }
        $target->commit();
    } catch (Throwable $error) {
        if ($target->inTransaction()) $target->rollBack();
        throw $error;
    }
}

/** 只清 008 的本档位缓存；数据库提交后失败可单独重试，不触碰其他客户 Redis。 */
function m008RechargeRefreshCache(): void
{
    $env = parse_ini_file(M008_RECHARGE_TARGET_ENV, true, INI_SCANNER_RAW);
    $clean = static function ($value): string { return trim((string)$value, " \t\n\r\0\x0B\"'"); };
    $redis = $env['REDIS'] ?? [];
    $cache = $env['CACHE'] ?? [];
    if ($clean($redis['REDIS_HOSTNAME'] ?? '') !== '127.0.0.1'
        || (int)$clean($redis['PORT'] ?? '') !== 6388
        || (int)$clean($redis['SELECT'] ?? '') !== 0
        || $clean($cache['PREFIX'] ?? '') !== '008:') {
        throw new RuntimeException('008 独立缓存配置已变化，拒绝删除缓存');
    }
    $client = new Redis();
    $client->connect('127.0.0.1', 6388, 3.0);
    $password = $clean($redis['REDIS_PASSWORD'] ?? '');
    if ($password !== '') $client->auth($password);
    $client->select(0);
    $client->del('008:data_user_recharge_quota');
    if ($client->exists('008:data_user_recharge_quota')) {
        throw new RuntimeException('008 储值档位缓存仍存在');
    }
}

/** check 和 verify 只读；apply 必须带固定文件指纹及本地提交。 */
function m008RechargeMain(): void
{
    $opts = getopt('', ['action:', 'snapshot-sha:', 'script-sha:', 'commit:']);
    $action = $opts['action'] ?? 'check';
    if (!in_array($action, ['check', 'apply', 'verify', 'refresh-cache'], true)) {
        throw new RuntimeException('未知操作');
    }
    $plan = m008RechargeSnapshot(__DIR__ . '/source-snapshot.json');
    $target = m008RechargeConnect(M008_RECHARGE_TARGET_ENV, 'mohe_008', 3307);
    if ($action !== 'verify') {
        $source = m008RechargeConnect(M008_RECHARGE_SOURCE_ENV, 'morestore', 3306);
        m008RechargeAssertSource($source, $plan);
    }
    if ($action === 'apply') {
        if (($opts['snapshot-sha'] ?? '') !== M008_RECHARGE_SNAPSHOT_SHA
            || ($opts['script-sha'] ?? '') !== hash_file('sha256', __FILE__)) {
            throw new RuntimeException('未提供已验收固定文件指纹');
        }
        $lock = (int)$target->query("SELECT GET_LOCK('008_recharge_quota_import', 15)")->fetchColumn();
        if ($lock !== 1) throw new RuntimeException('008 储值配置迁移锁获取失败');
        try {
            m008RechargeApply($target, $plan, (string)($opts['commit'] ?? ''));
        } finally {
            $target->query("SELECT RELEASE_LOCK('008_recharge_quota_import')");
        }
        m008RechargeRefreshCache();
    }
    $state = m008RechargeTargetState($target, $plan);
    if ($action === 'refresh-cache') {
        if ($state !== 'applied') throw new RuntimeException('数据未完成时禁止刷新缓存');
        m008RechargeRefreshCache();
    }
    if ($action === 'verify' && $state !== 'applied') throw new RuntimeException('008 储值配置尚未完成');
    echo json_encode(['state' => $state, 'source_sha256' => M008_RECHARGE_SNAPSHOT_SHA,
        'group_id' => 62, 'rows' => 13, 'active' => 4],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        m008RechargeMain();
    } catch (Throwable $error) {
        fwrite(STDERR, $error->getMessage() . PHP_EOL);
        exit(1);
    }
}
