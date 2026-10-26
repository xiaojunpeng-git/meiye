<?php
declare(strict_types=1);

/**
 * 歌妮停业批次合同：逐组织识别、双状态关闭、渼约不变、审计及失败回滚。
 * 全部使用隔离内存库，不触碰 008 或其他客户实例。
 */
require dirname(__DIR__, 3) . '/后端代码/database/upgrades/2026-10-20-008歌妮门店停业/01-执行.php';

function geniAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function geniTestDatabase(array $plan): PDO
{
    $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_STRINGIFY_FETCHES => true]);
    $db->exec('CREATE TABLE eb_organization
        (id INTEGER PRIMARY KEY,pid INTEGER,name TEXT,status INTEGER,is_del INTEGER)');
    $db->exec('CREATE TABLE eb_organization_store
        (id INTEGER PRIMARY KEY,org_id INTEGER,store_id INTEGER,add_time INTEGER)');
    $db->exec('CREATE TABLE eb_system_store
        (id INTEGER PRIMARY KEY,name TEXT,is_show INTEGER,business_status INTEGER,
         business_status_reason TEXT,is_del INTEGER)');
    $db->exec('CREATE TABLE eb_organization_change_log
        (id INTEGER PRIMARY KEY AUTOINCREMENT,org_id INTEGER,action TEXT,target_type TEXT,target_id INTEGER,
         before_data TEXT,after_data TEXT,remark TEXT,operator_id INTEGER,operator_name TEXT,
         operator_ip TEXT,request_id TEXT,request_token TEXT,add_time INTEGER)');
    $db->exec('CREATE TABLE eb_database_upgrade_log
        (id INTEGER PRIMARY KEY AUTOINCREMENT,upgrade_key TEXT UNIQUE,title TEXT,task_file TEXT,
         sql_checksum TEXT,code_version TEXT,executed_at TEXT,executed_by TEXT,result_note TEXT,created_at TEXT)');
    $org = $db->prepare('INSERT INTO eb_organization(id,pid,name,status,is_del) VALUES (?,?,?,?,?)');
    foreach ($plan['organizations'] as $row) $org->execute(array_values($row));
    $org->execute(array_values(['id' => '1', 'pid' => '0', 'name' => '集团', 'status' => '1', 'is_del' => '0']));
    $org->execute(array_values(['id' => '3', 'pid' => '1', 'name' => '渼约', 'status' => '1', 'is_del' => '0']));
    $store = $db->prepare('INSERT INTO eb_system_store
        (id,name,is_show,business_status,business_status_reason,is_del) VALUES (?,?,?,?,?,?)');
    $binding = $db->prepare('INSERT INTO eb_organization_store(org_id,store_id,add_time) VALUES (?,?,0)');
    foreach ($plan['stores'] as $row) {
        $store->execute([$row['id'], $row['name'], $row['is_show'], $row['business_status'],
            $row['business_status_reason'], $row['is_del']]);
        $binding->execute([$row['org_id'], $row['id']]);
    }
    foreach ([1001, 1002] as $id) {
        $store->execute([$id, '渼约对照店' . $id, 1, 1, '', 0]);
        $binding->execute([3, $id]);
    }
    return $db;
}

$plan = m008GeniSnapshot(dirname(__DIR__, 3)
    . '/后端代码/database/upgrades/2026-10-20-008歌妮门店停业/source-snapshot.json');
geniAssert(count($plan['stores']) === 106, '固定歌妮门店数错误');
$db = geniTestDatabase($plan);
geniAssert(m008GeniState($db, $plan) === 'ready', '正确前态不能执行');
m008GeniApply($db, $plan, str_repeat('a', 40));
geniAssert(m008GeniState($db, $plan) === 'applied', '停业结果或审计不完整');
geniAssert((int)$db->query('SELECT COUNT(*) FROM eb_system_store WHERE id<=106 AND is_show=0 AND business_status=0')
    ->fetchColumn() === 106, '歌妮双状态未全部关闭');
geniAssert((int)$db->query('SELECT COUNT(*) FROM eb_system_store WHERE id IN (1001,1002) AND is_show=1 AND business_status=1')
    ->fetchColumn() === 2, '渼约门店被修改');
geniAssert((int)$db->query('SELECT COUNT(*) FROM eb_organization_change_log')->fetchColumn() === 106,
    '逐店审计数量错误');
try {
    m008GeniApply($db, $plan, str_repeat('a', 40));
    throw new RuntimeException('重复执行未拒绝');
} catch (RuntimeException $error) {
    geniAssert($error->getMessage() !== '重复执行未拒绝', '重复执行未拒绝');
}

$db = geniTestDatabase($plan);
$db->exec('UPDATE eb_organization_store SET org_id=3 WHERE store_id=1');
try {
    m008GeniState($db, $plan);
    throw new RuntimeException('组织转属未阻断');
} catch (RuntimeException $error) {
    geniAssert($error->getMessage() !== '组织转属未阻断', '组织转属未阻断');
}
$db = geniTestDatabase($plan);
$db->exec('UPDATE eb_system_store SET business_status=0 WHERE id=1');
try {
    m008GeniApply($db, $plan, str_repeat('a', 40));
    throw new RuntimeException('局部前态未阻断');
} catch (RuntimeException $error) {
    geniAssert($error->getMessage() !== '局部前态未阻断', '局部前态未阻断');
}
geniAssert((int)$db->query('SELECT COUNT(*) FROM eb_organization_change_log')->fetchColumn() === 0,
    '局部状态冲突后留下审计');
geniAssert((int)$db->query('SELECT COUNT(*) FROM eb_system_store WHERE is_show=0')->fetchColumn() === 0,
    '局部状态冲突后改变了门店展示状态');
echo "008 Geni store closure contract PASS\n";
