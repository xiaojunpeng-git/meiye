<?php
declare(strict_types=1);

/**
 * 验证 008 储值套餐补导的完整行集、重复执行拒绝和异常回滚。
 * 使用隔离内存库，不触碰旧站或任何正式客户实例。
 */
require dirname(__DIR__, 3) . '/后端代码/database/upgrades/2026-10-20-008储值金额套餐补导/01-执行.php';

function rechargeExpect(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}

function rechargeDb(): PDO
{
    $db = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_STRINGIFY_FETCHES => true,
    ]);
    $db->exec('CREATE TABLE eb_system_group
        (id INTEGER PRIMARY KEY,cate_id INTEGER,name TEXT,info TEXT,config_name TEXT,fields TEXT)');
    $db->exec('CREATE TABLE eb_system_group_data
        (id INTEGER PRIMARY KEY,gid INTEGER,value TEXT,add_time INTEGER,sort INTEGER,status INTEGER)');
    $db->exec('CREATE TABLE eb_store_gift_config
        (id INTEGER PRIMARY KEY,gift_type INTEGER,relation_id INTEGER,config TEXT)');
    $db->exec('CREATE TABLE eb_database_upgrade_log
        (upgrade_key TEXT PRIMARY KEY,title TEXT,task_file TEXT,sql_checksum TEXT,code_version TEXT,
         executed_at TEXT,executed_by TEXT,result_note TEXT,created_at TEXT)');
    return $db;
}

$snapshot = dirname(__DIR__, 3)
    . '/后端代码/database/upgrades/2026-10-20-008储值金额套餐补导/source-snapshot.json';
$plan = m008RechargeSnapshot($snapshot);
rechargeExpect(count($plan['rows']) === 13, '来源档位数量不符');
$db = rechargeDb();
rechargeExpect(m008RechargeTargetState($db, $plan) === 'ready', '空目标应可导入');
m008RechargeApply($db, $plan, str_repeat('a', 40));
rechargeExpect(m008RechargeTargetState($db, $plan) === 'applied', '目标逐行或审计不符');
rechargeExpect((int)$db->query('SELECT COUNT(*) FROM eb_system_group_data WHERE status=1')->fetchColumn() === 4,
    '启用档位不符');
rechargeExpect((int)$db->query('SELECT COUNT(*) FROM eb_system_group_data WHERE status=0')->fetchColumn() === 9,
    '停用档位被误启用');
try {
    m008RechargeApply($db, $plan, str_repeat('a', 40));
    throw new RuntimeException('重复执行未拒绝');
} catch (RuntimeException $error) {
    rechargeExpect($error->getMessage() !== '重复执行未拒绝', '重复执行未拒绝');
}
$db->exec('UPDATE eb_system_group_data SET status=1 WHERE id=177');
try {
    m008RechargeTargetState($db, $plan);
    throw new RuntimeException('目标变更未阻断');
} catch (RuntimeException $error) {
    rechargeExpect($error->getMessage() !== '目标变更未阻断', '目标变更未阻断');
}
$db = rechargeDb();
$db->exec("INSERT INTO eb_system_group(id,cate_id,name,info,config_name,fields)
    VALUES(62,1,'partial','','user_recharge_quota','[]')");
try {
    m008RechargeApply($db, $plan, str_repeat('a', 40));
    throw new RuntimeException('局部数据未阻断');
} catch (RuntimeException $error) {
    rechargeExpect($error->getMessage() !== '局部数据未阻断', '局部数据未阻断');
}
rechargeExpect((int)$db->query('SELECT COUNT(*) FROM eb_system_group_data')->fetchColumn() === 0,
    '冲突后不应写入档位');
rechargeExpect((int)$db->query('SELECT COUNT(*) FROM eb_database_upgrade_log')->fetchColumn() === 0,
    '冲突后不应写入审计');
echo "008 recharge quota import contract PASS\n";
