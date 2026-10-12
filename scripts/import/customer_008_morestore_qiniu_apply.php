<?php
/**
 * Reuse the old 渼约 Qiniu bucket in the independent 008 instance.
 *
 * The source remains read-only. Only the three active upload settings and the
 * single active bucket are copied; no other client configuration or media rows
 * are imported. The fixed source hash prevents a later key/bucket change from
 * silently changing the reviewed scope.
 */

declare(strict_types=1);

use mohe\services\SystemConfigService;
use think\facade\Db;

const QINIU_SOURCE_ENV = '/www/wwwroot/morestore.cc3798.com/.env';
const QINIU_SOURCE_SHA256 = '4467c3c05120d151ab653c0c4b3908fb657dc1b556efd3f5d603288d6ec17d3b';
const QINIU_TARGET_DB = 'mohe_008';
const QINIU_TARGET_PORT = 3307;
const QINIU_UPGRADE_KEY = '20261013-008-morestore-qiniu-config';
const QINIU_CONFIG_KEYS = ['qiniu_accessKey', 'qiniu_secretKey', 'upload_type'];
const QINIU_STORAGE_FIELDS = ['access_key', 'type', 'name', 'region', 'acl', 'domain',
    'cname', 'is_ssl', 'status', 'is_delete'];

/** Canonical bytes include secrets in memory only; callers may print the digest. */
function qiniuSourceDigest(array $config, array $storage): string
{
    ksort($config, SORT_STRING);
    ksort($storage, SORT_STRING);
    return hash('sha256', (string)json_encode(
        ['config' => $config, 'storage' => $storage],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ));
}

/** Require the one reviewed bucket, valid source key shape, and unchanged data. */
function qiniuValidateSource(array $config, array $storage, string $expectedDigest): void
{
    $keys = array_keys($config);
    sort($keys, SORT_STRING);
    $expectedKeys = QINIU_CONFIG_KEYS;
    sort($expectedKeys, SORT_STRING);
    $storageKeys = array_keys($storage);
    sort($storageKeys, SORT_STRING);
    $expectedStorageKeys = QINIU_STORAGE_FIELDS;
    sort($expectedStorageKeys, SORT_STRING);
    $accessKey = json_decode((string)($config['qiniu_accessKey'] ?? ''), true);
    $secretKey = json_decode((string)($config['qiniu_secretKey'] ?? ''), true);
    if ($keys !== $expectedKeys || $storageKeys !== $expectedStorageKeys
        || !is_string($accessKey) || $accessKey === ''
        || !is_string($secretKey) || $secretKey === ''
        || ($config['upload_type'] ?? '') !== '2'
        || ($storage['access_key'] ?? '') !== $accessKey
        || ($storage['type'] ?? '') !== '2'
        || ($storage['name'] ?? '') !== 'meiyue01'
        || ($storage['domain'] ?? '') !== 'https://myqiniu.cc3798.com'
        || ($storage['status'] ?? '') !== '1'
        || ($storage['is_delete'] ?? '') !== '0'
        || !hash_equals($expectedDigest, qiniuSourceDigest($config, $storage))) {
        throw new RuntimeException('Old Qiniu configuration differs from the reviewed scope');
    }
}

/** Read only the active old bucket and the exact settings used by UploadService. */
function qiniuLiveSource(): array
{
    $env = parse_ini_file(QINIU_SOURCE_ENV);
    if (!is_array($env) || ($env['DATABASE'] ?? '') !== 'morestore'
        || (int)($env['HOSTPORT'] ?? 0) !== 3306) {
        throw new RuntimeException('Old 渼约 database identity unavailable');
    }
    $dsn = 'mysql:host=' . $env['HOSTNAME'] . ';port=' . $env['HOSTPORT']
        . ';dbname=' . $env['DATABASE'] . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $env['USERNAME'], $env['PASSWORD'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $identity = $pdo->query('SELECT DATABASE() AS db, @@port AS port')->fetch(PDO::FETCH_ASSOC);
    if (($identity['db'] ?? '') !== 'morestore' || (int)($identity['port'] ?? 0) !== 3306) {
        throw new RuntimeException('Connected old database identity changed');
    }
    $rows = $pdo->query("SELECT menu_name,value FROM eb_system_config
        WHERE menu_name IN ('qiniu_accessKey','qiniu_secretKey','upload_type')")
        ->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) !== count(QINIU_CONFIG_KEYS)) {
        throw new RuntimeException('Old Qiniu setting count changed');
    }
    $config = [];
    foreach ($rows as $row) {
        if (isset($config[$row['menu_name']])) {
            throw new RuntimeException('Duplicate old Qiniu setting');
        }
        $config[$row['menu_name']] = (string)$row['value'];
    }
    ksort($config, SORT_STRING);
    $fields = implode(',', array_map(static function ($field) {
        return '`' . $field . '`';
    }, QINIU_STORAGE_FIELDS));
    $storageRows = $pdo->query('SELECT ' . $fields . ' FROM eb_system_storage
        WHERE type=2 AND status=1 AND is_delete=0')->fetchAll(PDO::FETCH_ASSOC);
    if (count($storageRows) !== 1) {
        throw new RuntimeException('Expected exactly one active old Qiniu bucket');
    }
    $storage = [];
    foreach (QINIU_STORAGE_FIELDS as $field) {
        $storage[$field] = (string)$storageRows[0][$field];
    }
    qiniuValidateSource($config, $storage, QINIU_SOURCE_SHA256);
    return ['config' => $config, 'storage' => $storage];
}

/** All target reads and writes must resolve to the isolated 008 MySQL port. */
function qiniuAssertTargetIdentity(): void
{
    $row = Db::query('SELECT DATABASE() AS db, @@port AS port');
    if (($row[0]['db'] ?? '') !== QINIU_TARGET_DB
        || (int)($row[0]['port'] ?? 0) !== QINIU_TARGET_PORT) {
        throw new RuntimeException('Refusing a database other than mohe_008 / 3307');
    }
}

/** Retry succeeds only when the marker and every imported value still agree. */
function qiniuTargetState(array $source, bool $lockRows = false): string
{
    qiniuAssertTargetIdentity();
    $query = Db::name('system_config')->whereIn('menu_name', QINIU_CONFIG_KEYS);
    if ($lockRows) {
        $query = $query->lock(true);
    }
    $rows = $query->field('menu_name,value')->select()->toArray();
    if (count($rows) !== count(QINIU_CONFIG_KEYS)) {
        throw new RuntimeException('008 Qiniu setting rows are incomplete');
    }
    $config = [];
    foreach ($rows as $row) {
        if (isset($config[$row['menu_name']])) {
            throw new RuntimeException('Duplicate 008 Qiniu setting');
        }
        $config[$row['menu_name']] = (string)$row['value'];
    }
    ksort($config, SORT_STRING);
    $storageRows = Db::name('system_storage')->select()->toArray();
    $markers = Db::name('database_upgrade_log')->where('upgrade_key', QINIU_UPGRADE_KEY)
        ->select()->toArray();
    if (count($markers) > 1) {
        throw new RuntimeException('Duplicate 008 Qiniu import marker');
    }
    if (count($markers) === 1) {
        if (count($storageRows) !== 1 || ($markers[0]['result_note'] ?? '') === '') {
            throw new RuntimeException('008 Qiniu marker exists without the expected bucket');
        }
        $note = json_decode((string)$markers[0]['result_note'], true);
        if (($note['source_guard_sha256'] ?? '') !== QINIU_SOURCE_SHA256
            || $config !== $source['config']) {
            throw new RuntimeException('008 Qiniu settings drifted after import');
        }
        foreach (QINIU_STORAGE_FIELDS as $field) {
            if ((string)$storageRows[0][$field] !== $source['storage'][$field]) {
                throw new RuntimeException('008 active Qiniu bucket drifted after import');
            }
        }
        return 'already_applied';
    }
    if (count($storageRows) !== 0 || count(array_filter($config, static function ($value) {
        return $value !== '';
    })) !== 0) {
        throw new RuntimeException('008 already has an unreviewed upload configuration');
    }
    return 'ready';
}

/** Copy the reviewed settings and bucket in one 008 transaction. */
function qiniuApply(array $source, string $backupPath, string $backupSha, string $codeVersion): int
{
    if (!preg_match('/^[a-f0-9]{64}$/', $backupSha)
        || !is_file($backupPath) || filesize($backupPath) < 1024
        || !hash_equals($backupSha, (string)hash_file('sha256', $backupPath))) {
        throw new RuntimeException('A verified 008 backup is required');
    }
    if (!preg_match('/^[a-f0-9]{40}$/', $codeVersion)) {
        throw new RuntimeException('A fixed local commit is required');
    }
    $got = Db::query("SELECT GET_LOCK('008_qiniu_config_import',15) AS got");
    if ((int)($got[0]['got'] ?? 0) !== 1) {
        throw new RuntimeException('008 Qiniu import lock unavailable');
    }
    try {
        Db::startTrans();
        try {
            if (qiniuTargetState($source, true) !== 'ready') {
                throw new RuntimeException('008 Qiniu configuration was already imported');
            }
            foreach (QINIU_CONFIG_KEYS as $key) {
                Db::name('system_config')->where('menu_name', $key)
                    ->update(['value' => $source['config'][$key]]);
            }
            $storage = $source['storage'];
            $storage['add_time'] = time();
            $storage['update_time'] = time();
            $storageId = (int)Db::name('system_storage')->insertGetId($storage);
            if ($storageId <= 0) {
                throw new RuntimeException('008 Qiniu bucket insert failed');
            }
            $note = json_encode([
                'source_guard_sha256' => QINIU_SOURCE_SHA256,
                'bucket' => 'meiyue01',
                'backup_sha256' => $backupSha,
            ], JSON_UNESCAPED_UNICODE);
            Db::name('database_upgrade_log')->insert([
                'upgrade_key' => QINIU_UPGRADE_KEY,
                'title' => '008 渼约旧七牛云配置导入',
                'task_file' => 'scripts/import/customer_008_morestore_qiniu_apply.php',
                'sql_checksum' => hash_file('sha256', __FILE__),
                'code_version' => $codeVersion,
                'executed_at' => date('Y-m-d H:i:s'),
                'executed_by' => 'Codex',
                'result_note' => $note,
            ]);
            Db::commit();
        } catch (Throwable $error) {
            Db::rollback();
            throw $error;
        }
    } finally {
        Db::query("SELECT RELEASE_LOCK('008_qiniu_config_import') AS released");
    }
    // The 008 Redis instance has its own port and cache namespace; refresh only its config tag.
    if (SystemConfigService::clear() === false) {
        throw new RuntimeException('008 Qiniu settings committed but config cache refresh failed');
    }
    return $storageId;
}

/** Suppress all secret values in the CLI result, including failure paths. */
function qiniuMain(array $argv): void
{
    $command = (string)($argv[1] ?? '');
    if (!in_array($command, ['check', 'apply'], true)) {
        throw new RuntimeException('Usage: php qiniu_apply.php check|apply [verified-backup sha256 commit]');
    }
    require getcwd() . '/vendor/autoload.php';
    $app = new \think\App(getcwd() . '/');
    $app->initialize();
    qiniuAssertTargetIdentity();
    $source = qiniuLiveSource();
    $state = qiniuTargetState($source);
    if ($command === 'check' || $state === 'already_applied') {
        echo json_encode(['ok' => true, 'mode' => $state, 'source_guard_sha256' => QINIU_SOURCE_SHA256]) . PHP_EOL;
        return;
    }
    $storageId = qiniuApply($source, (string)($argv[2] ?? ''),
        (string)($argv[3] ?? ''), (string)($argv[4] ?? ''));
    if (qiniuTargetState($source) !== 'already_applied') {
        throw new RuntimeException('008 Qiniu post-import verification failed');
    }
    echo json_encode(['ok' => true, 'mode' => 'applied', 'bucket_id' => $storageId,
        'source_guard_sha256' => QINIU_SOURCE_SHA256]) . PHP_EOL;
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        qiniuMain($argv);
    } catch (Throwable $error) {
        // Vendor/SQL exception text can contain bound values; keep secrets out of CLI logs.
        $message = $error instanceof RuntimeException ? $error->getMessage() : get_class($error);
        fwrite(STDERR, '008 Qiniu import stopped: ' . $message . PHP_EOL);
        exit(1);
    }
}
