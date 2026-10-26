<?php
declare(strict_types=1);

/**
 * 只将 008 歌妮组织下的全部门店设为停业。
 *
 * 旧门店列表以 is_show 判断“已停业”，新业务入口以 business_status 判断
 * 是否可以写业务；必须同批设置两列，不能只停页面而留下可写的收银入口。
 * 组织、员工、订单与历史事实不在本批修改范围内。
 */
const M008_GENI_ENV = '/www/wwwroot/008.cc3798.com/.env';
const M008_GENI_SNAPSHOT_SHA = '002cb705485683847b1c689d86d79aead61f1d39a8626679069d1d4d3c9da884';
const M008_GENI_UPGRADE = '20261020-008-geni-store-close';
const M008_GENI_ACTION = '008_geni_store_close';
const M008_GENI_REASON = '歌妮门店按产品经理指令停业';
const M008_GENI_TASK_FILE = '后端代码/database/upgrades/2026-10-20-008歌妮门店停业/01-执行.php';

/** 固定组织祖先和逐店前态；新增门店、转组织或状态漂移都必须重新验收。 */
function m008GeniSnapshot(string $file): array
{
    if (!hash_equals(M008_GENI_SNAPSHOT_SHA, (string)hash_file('sha256', $file))) {
        throw new RuntimeException('歌妮门店固定清单指纹已变化');
    }
    $plan = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($plan) || ($plan['target_database'] ?? '') !== 'mohe_008'
        || (int)($plan['target_port'] ?? 0) !== 3307
        || (string)($plan['group']['name'] ?? '') !== '集团'
        || (int)($plan['group']['id'] ?? 0) !== 1
        || (string)($plan['geni']['name'] ?? '') !== '歌妮'
        || (int)($plan['geni']['id'] ?? 0) !== 2
        || (int)($plan['geni']['pid'] ?? 0) !== 1
        || count($plan['organizations'] ?? []) !== 47
        || count($plan['stores'] ?? []) !== 106) {
        throw new RuntimeException('歌妮组织或门店数量与固定版不符');
    }
    $orgIds = array_map('intval', array_column($plan['organizations'], 'id'));
    if (count(array_unique($orgIds)) !== 47 || !in_array(2, $orgIds, true)) {
        throw new RuntimeException('歌妮组织清单有重复或缺少根节点');
    }
    $seen = [];
    foreach ($plan['stores'] as $row) {
        $id = (int)($row['id'] ?? 0);
        if ($id <= 0 || isset($seen[$id])
            || !in_array((int)($row['org_id'] ?? 0), $orgIds, true)
            || (string)($row['name'] ?? '') === ''
            || (int)($row['is_show'] ?? -1) !== 1
            || (int)($row['business_status'] ?? -1) !== 1
            || (int)($row['is_del'] ?? -1) !== 0) {
            throw new RuntimeException('歌妮门店身份或营业前态不符');
        }
        $seen[$id] = true;
    }
    return $plan;
}

/** 连接必须是 008 独立 MySQL 实例，不能从当前目录或默认配置猜测目标。 */
function m008GeniConnect(): PDO
{
    $env = parse_ini_file(M008_GENI_ENV, true, INI_SCANNER_RAW)['DATABASE'] ?? [];
    $clean = static function ($value): string { return trim((string)$value, " \t\n\r\0\x0B\"'"); };
    foreach ($env as $key => $value) $env[$key] = $clean($value);
    if (($env['DATABASE'] ?? '') !== 'mohe_008' || (int)($env['HOSTPORT'] ?? 0) !== 3307
        || !in_array($env['HOSTNAME'] ?? '', ['localhost', '127.0.0.1'], true)
        || ($env['PREFIX'] ?? 'eb_') !== 'eb_') {
        throw new RuntimeException('008 数据库连接守卫失败');
    }
    $pdo = new PDO('mysql:host=' . $env['HOSTNAME'] . ';port=3307;dbname=mohe_008;charset=utf8mb4',
        $env['USERNAME'], $env['PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_STRINGIFY_FETCHES => true]);
    $identity = $pdo->query('SELECT DATABASE() db, @@port port')->fetch();
    if (($identity['db'] ?? '') !== 'mohe_008' || (int)($identity['port'] ?? 0) !== 3307) {
        throw new RuntimeException('连接到错误数据库');
    }
    return $pdo;
}

/** 从正式组织绑定而非相似店名求出歌妮门店，含全部下级组织。 */
function m008GeniRead(PDO $pdo): array
{
    $all = $pdo->query('SELECT id,pid,name,status,is_del FROM eb_organization ORDER BY id')->fetchAll();
    $map = [];
    $children = [];
    foreach ($all as $row) {
        $id = (int)$row['id'];
        if (isset($map[$id])) throw new RuntimeException('重复组织 ID');
        $map[$id] = $row;
        $children[(int)$row['pid']][] = $id;
    }
    if (($map[1]['name'] ?? '') !== '集团' || (int)($map[1]['pid'] ?? -1) !== 0
        || ($map[2]['name'] ?? '') !== '歌妮' || (int)($map[2]['pid'] ?? 0) !== 1
        || ($map[3]['name'] ?? '') !== '渼约' || (int)($map[3]['pid'] ?? 0) !== 1) {
        throw new RuntimeException('集团公司组织祖先已变化');
    }
    $seen = [];
    $stack = [2];
    while ($stack) {
        $id = (int)array_pop($stack);
        if (isset($seen[$id]) || count($seen) > 5000) throw new RuntimeException('歌妮组织树存在环或异常规模');
        $seen[$id] = true;
        foreach ($children[$id] ?? [] as $child) $stack[] = $child;
    }
    $ids = array_keys($seen);
    sort($ids, SORT_NUMERIC);
    $orgs = [];
    foreach ($ids as $id) $orgs[] = $map[$id];
    $stores = $pdo->query('SELECT s.id,s.name,s.is_show,s.business_status,s.business_status_reason,s.is_del,os.org_id
        FROM eb_system_store s JOIN eb_organization_store os ON os.store_id=s.id
        WHERE os.org_id IN (' . implode(',', $ids) . ') ORDER BY s.id')->fetchAll();
    return ['group' => $map[1], 'geni' => $map[2], 'organizations' => $orgs, 'stores' => $stores];
}

/** 明确区分未执行与完整执行；任何部分关闭或非本批审计均阻断自动重试。 */
function m008GeniState(PDO $pdo, array $plan): string
{
    $live = m008GeniRead($pdo);
    if ($live['group'] !== $plan['group'] || $live['geni'] !== $plan['geni']
        || $live['organizations'] !== $plan['organizations']) {
        throw new RuntimeException('歌妮组织层级已变化');
    }
    $marker = $pdo->prepare('SELECT sql_checksum,code_version,result_note FROM eb_database_upgrade_log WHERE upgrade_key=?');
    $marker->execute([M008_GENI_UPGRADE]);
    $markers = $marker->fetchAll();
    $audit = $pdo->prepare('SELECT org_id,target_id,action,target_type,before_data,after_data
        FROM eb_organization_change_log WHERE request_id=? ORDER BY target_id');
    $audit->execute([M008_GENI_UPGRADE]);
    $audits = $audit->fetchAll();
    if ($live['stores'] === $plan['stores'] && !$markers && !$audits) return 'ready';

    $expected = $plan['stores'];
    foreach ($expected as &$row) {
        $row['is_show'] = '0';
        $row['business_status'] = '0';
        $row['business_status_reason'] = M008_GENI_REASON;
    }
    unset($row);
    $note = count($markers) === 1 ? json_decode((string)$markers[0]['result_note'], true) : null;
    if ($live['stores'] !== $expected || count($markers) !== 1 || count($audits) !== 106
        || ($note['snapshot_sha256'] ?? '') !== M008_GENI_SNAPSHOT_SHA
        || (int)($note['stores'] ?? 0) !== 106
        || ($markers[0]['sql_checksum'] ?? '') !== hash_file('sha256', __FILE__)) {
        throw new RuntimeException('歌妮门店状态存在局部结果或审计冲突');
    }
    foreach ($audits as $index => $row) {
        $before = json_decode((string)$row['before_data'], true);
        $after = json_decode((string)$row['after_data'], true);
        $source = $plan['stores'][$index];
        if ($row['action'] !== M008_GENI_ACTION || $row['target_type'] !== 'store'
            || (int)$row['target_id'] !== (int)$source['id']
            || (int)$row['org_id'] !== (int)$source['org_id']
            || ($before['is_show'] ?? null) !== 1 || ($before['business_status'] ?? null) !== 1
            || ($before['reason'] ?? null) !== $source['business_status_reason']
            || ($after['is_show'] ?? null) !== 0 || ($after['business_status'] ?? null) !== 0
            || ($after['reason'] ?? '') !== M008_GENI_REASON) {
            throw new RuntimeException('歌妮门店逐行审计不完整');
        }
    }
    return 'applied';
}

/** 一个事务修改 106 家门店的两个状态字段，并逐店留前后审计。 */
function m008GeniApply(PDO $pdo, array $plan, string $commit): void
{
    if (!preg_match('/^[a-f0-9]{40}$/', $commit)) throw new RuntimeException('缺少已验收本地提交');
    $pdo->beginTransaction();
    try {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $storeIds = implode(',', array_map('intval', array_column($plan['stores'], 'id')));
            $orgIds = implode(',', array_map('intval', array_column($plan['organizations'], 'id')));
            $pdo->query('SELECT id FROM eb_organization WHERE id IN (' . $orgIds . ') ORDER BY id FOR UPDATE')->fetchAll();
            $pdo->query('SELECT id FROM eb_organization_store WHERE store_id IN (' . $storeIds . ') ORDER BY id FOR UPDATE')->fetchAll();
            $pdo->query('SELECT id FROM eb_system_store WHERE id IN (' . $storeIds . ') ORDER BY id FOR UPDATE')->fetchAll();
        }
        if (m008GeniState($pdo, $plan) !== 'ready') throw new RuntimeException('歌妮门店已停业');
        $update = $pdo->prepare('UPDATE eb_system_store SET is_show=0,business_status=0,business_status_reason=?
            WHERE id=? AND is_show=1 AND business_status=1 AND is_del=0');
        $audit = $pdo->prepare('INSERT INTO eb_organization_change_log
            (org_id,action,target_type,target_id,before_data,after_data,remark,operator_id,operator_name,operator_ip,request_id,request_token,add_time)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $now = time();
        foreach ($plan['stores'] as $row) {
            $update->execute([M008_GENI_REASON, (int)$row['id']]);
            if ($update->rowCount() !== 1) throw new RuntimeException('门店状态未按固定前态更新：' . $row['id']);
            $before = json_encode(['is_show' => 1, 'business_status' => 1,
                'reason' => $row['business_status_reason']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $after = json_encode(['is_show' => 0, 'business_status' => 0,
                'reason' => M008_GENI_REASON], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $audit->execute([(int)$row['org_id'], M008_GENI_ACTION, 'store', (int)$row['id'],
                $before, $after, M008_GENI_REASON, 0, 'Codex', '', M008_GENI_UPGRADE, '', $now]);
        }
        $note = json_encode(['snapshot_sha256' => M008_GENI_SNAPSHOT_SHA, 'stores' => 106],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $date = date('Y-m-d H:i:s');
        $log = $pdo->prepare('INSERT INTO eb_database_upgrade_log
            (upgrade_key,title,task_file,sql_checksum,code_version,executed_at,executed_by,result_note,created_at)
            VALUES (?,?,?,?,?,?,?,?,?)');
        $log->execute([M008_GENI_UPGRADE, '008 歌妮门店全部停业', M008_GENI_TASK_FILE,
            hash_file('sha256', __FILE__), $commit, $date, 'Codex', $note, $date]);
        if (m008GeniState($pdo, $plan) !== 'applied') throw new RuntimeException('歌妮停业事务内逐店对账失败');
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

/** 只删除 008 的 106 个门店缓存成员；提交后失败时可单独重试。 */
function m008GeniRefreshCache(array $plan): void
{
    $env = parse_ini_file(M008_GENI_ENV, true, INI_SCANNER_RAW);
    $clean = static function ($value): string { return trim((string)$value, " \t\n\r\0\x0B\"'"); };
    $redis = $env['REDIS'] ?? [];
    $cache = $env['CACHE'] ?? [];
    if ($clean($redis['REDIS_HOSTNAME'] ?? '') !== '127.0.0.1'
        || (int)$clean($redis['PORT'] ?? '') !== 6388
        || (int)$clean($redis['SELECT'] ?? '') !== 0
        || $clean($cache['PREFIX'] ?? '') !== '008:') {
        throw new RuntimeException('008 独立缓存配置已变化');
    }
    $client = new Redis();
    $client->connect('127.0.0.1', 6388, 3.0);
    $password = $clean($redis['REDIS_PASSWORD'] ?? '');
    if ($password !== '') $client->auth($password);
    $client->select(0);
    foreach ($plan['stores'] as $row) {
        $id = (string)$row['id'];
        $client->hDel('008:mc:system_store:map', $id);
        $client->zRem('008:mc:system_store:page', $id);
    }
}

/** check 与 verify 只读；apply 同时要求固定快照、脚本指纹和已验收提交。 */
function m008GeniMain(): void
{
    $opts = getopt('', ['action:', 'snapshot-sha:', 'script-sha:', 'commit:']);
    $action = $opts['action'] ?? 'check';
    if (!in_array($action, ['check', 'apply', 'verify', 'refresh-cache'], true)) {
        throw new RuntimeException('未知操作');
    }
    $plan = m008GeniSnapshot(__DIR__ . '/source-snapshot.json');
    $pdo = m008GeniConnect();
    if ($action === 'apply') {
        if (($opts['snapshot-sha'] ?? '') !== M008_GENI_SNAPSHOT_SHA
            || ($opts['script-sha'] ?? '') !== hash_file('sha256', __FILE__)) {
            throw new RuntimeException('未提供已验收固定文件指纹');
        }
        $lock = (int)$pdo->query("SELECT GET_LOCK('008_geni_store_close', 15)")->fetchColumn();
        if ($lock !== 1) throw new RuntimeException('歌妮停业迁移锁获取失败');
        try {
            m008GeniApply($pdo, $plan, (string)($opts['commit'] ?? ''));
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('008_geni_store_close')");
        }
        m008GeniRefreshCache($plan);
    }
    $state = m008GeniState($pdo, $plan);
    if ($action === 'refresh-cache') {
        if ($state !== 'applied') throw new RuntimeException('门店未完成停业时不能刷新缓存');
        m008GeniRefreshCache($plan);
    }
    if ($action === 'verify' && $state !== 'applied') throw new RuntimeException('歌妮门店尚未全部停业');
    echo json_encode(['state' => $state, 'stores' => 106, 'closed' => $state === 'applied' ? 106 : 0,
        'snapshot_sha256' => M008_GENI_SNAPSHOT_SHA], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        m008GeniMain();
    } catch (Throwable $error) {
        fwrite(STDERR, $error->getMessage() . PHP_EOL);
        exit(1);
    }
}
