<?php
declare(strict_types=1);

/**
 * 修正旧种子把 other_collection 写成“现金”的配置。默认只读；正式执行须明确库名，
 * 且仅在独立 cash 已启用、配置仍为未经管理员修改的旧值时原子更新并审计。
 * 历史收款快照和收款事实绝不在这里改写。
 */
const R44_UPGRADE_KEY = '20261011-001-other-collection-name-repair';
const R44_AUDIT_KEY = 'R44-OTHER-COLLECTION-SEED-NAME-REPAIR';

$options = getopt('', ['execute', 'database:', 'code-version:']);
$execute = array_key_exists('execute', $options);
$expectedDatabase = trim((string)($options['database'] ?? ''));
if ($expectedDatabase === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $expectedDatabase)) {
    throw new RuntimeException('必须明确指定 --database=目标库名');
}
$envPath = getenv('MOHE_REPAIR_ENV_PATH') ?: '/var/www/html/.env';
$env = parse_ini_file($envPath, true);
if (!is_array($env) || !is_array($env['DATABASE'] ?? null)) {
    throw new RuntimeException('数据库环境不可用');
}
$db = $env['DATABASE'];
$database = (string)($db['DATABASE'] ?? '');
if ($database !== $expectedDatabase) {
    throw new RuntimeException('实际数据库与明确指定的目标不一致');
}

$pdo = new PDO(
    'mysql:host=' . $db['HOSTNAME'] . ';port=' . $db['HOSTPORT'] . ';dbname=' . $database . ';charset=utf8mb4',
    $db['USERNAME'],
    $db['PASSWORD'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false]
);
$config = $pdo->query("SELECT id,code,default_name,display_name,status,sort,version,updated_at
    FROM eb_cashier_v3_payment_method_config WHERE code IN ('cash','other_collection') ORDER BY code")
    ->fetchAll();
$byCode = [];
foreach ($config as $row) $byCode[(string)$row['code']] = $row;
$other = $byCode['other_collection'] ?? null;
$cash = $byCode['cash'] ?? null;
if (!$other || !$cash) throw new RuntimeException('缺少独立现金或其他收款配置');
$eligible = (int)$cash['status'] === 1
    && (string)$cash['display_name'] === '现金'
    && (string)$other['default_name'] === '现金'
    && (string)$other['display_name'] === '现金'
    && (int)$other['version'] === 1;
$history = $pdo->query("SELECT COUNT(*) FROM eb_cashier_v3_payment_collection
    WHERE payment_method='other_collection' AND payment_method_name_snapshot='现金'
      AND collection_status='settled' AND collection_direction='forward'")->fetchColumn();
if (!$execute) {
    echo json_encode(['database' => $database, 'mode' => 'read_only', 'eligible' => $eligible,
        'config' => $config, 'historicalSnapshotCount' => (int)$history], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
    exit(0);
}
if (!$eligible) {
    throw new RuntimeException('配置已变化或不符合旧种子特征，拒绝覆盖管理员设置');
}

$pdo->beginTransaction();
try {
    $select = $pdo->prepare("SELECT id,code,default_name,display_name,status,sort,version,updated_at
        FROM eb_cashier_v3_payment_method_config WHERE code=? FOR UPDATE");
    $select->execute(['cash']);
    $lockedCash = $select->fetch();
    $select->execute(['other_collection']);
    $lockedOther = $select->fetch();
    if (!$lockedCash || (int)$lockedCash['status'] !== 1 || (string)$lockedCash['display_name'] !== '现金'
        || !$lockedOther || (int)$lockedOther['id'] !== (int)$other['id']
        || (string)$lockedOther['default_name'] !== '现金'
        || (string)$lockedOther['display_name'] !== '现金'
        || (int)$lockedOther['version'] !== 1) {
        throw new RuntimeException('配置在预检后已变化，拒绝修正');
    }
    $already = $pdo->prepare('SELECT id FROM eb_database_upgrade_log WHERE upgrade_key=?');
    $already->execute([R44_UPGRADE_KEY]);
    if ($already->fetch()) throw new RuntimeException('升级日志已存在但配置仍为旧值，需要人工核对');

    $now = time();
    $update = $pdo->prepare("UPDATE eb_cashier_v3_payment_method_config
        SET default_name='其他收款',display_name='其他收款',version=version+1,updated_at=?
        WHERE id=? AND code='other_collection' AND default_name='现金'
          AND display_name='现金' AND version=1");
    $update->execute([$now, (int)$lockedOther['id']]);
    if ($update->rowCount() !== 1) throw new RuntimeException('配置版本校验失败');
    $select->execute(['other_collection']);
    $after = $select->fetch();
    $audit = $pdo->prepare('INSERT INTO eb_cashier_v3_business_config_audit
        (action,entity_type,entity_key,idempotency_key,operator_id,before_snapshot_json,after_snapshot_json,occurred_at)
        VALUES (?,?,?,?,?,?,?,?)');
    $audit->execute(['payment_seed_name_repair', 'payment_method', 'other_collection', R44_AUDIT_KEY, 0,
        json_encode($lockedOther, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        json_encode($after, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $now]);
    $log = $pdo->prepare('INSERT INTO eb_database_upgrade_log
        (upgrade_key,title,task_file,sql_checksum,code_version,executed_at,executed_by,result_note)
        VALUES (?,?,?,?,?,NOW(),?,?)');
    $log->execute([R44_UPGRADE_KEY, '其他收款旧种子名称纠正',
        'database/upgrades/2026-10-11-R44-其他收款名称纠正/02-执行.php', hash_file('sha256', __FILE__),
        trim((string)($options['code-version'] ?? 'R44-local')), 'Codex',
        '仅纠正配置默认名与显示名；已结算收款代码、金额及历史名称快照不变']);
    $pdo->commit();
    echo json_encode(['database' => $database, 'mode' => 'executed', 'before' => $lockedOther,
        'after' => $after, 'historicalSnapshotCount' => (int)$history], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $error;
}
