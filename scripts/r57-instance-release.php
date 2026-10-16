<?php
/** R57 per-instance release: pin old/new bytes, back up independently, then migrate and switch. */
if (PHP_SAPI !== 'cli') exit(1);
$instance = $argv[1] ?? '';
$mode = $argv[2] ?? '';
$targets = [
    'rh' => ['rh.cc3798.com', 'ruihao', '3306', '/etc/init.d/ruihao_swoole restart', "ss -lnt | grep -q ':20800 '"],
    '008' => ['008.cc3798.com', 'mohe_008', '3307', 'systemctl restart mohe-008-swoole.service', 'systemctl is-active --quiet mohe-008-swoole.service'],
];
if (!isset($targets[$instance]) || !in_array($mode, ['backup', 'migrate', 'deploy', 'verify'], true)) exit(2);
[$domain, $database, $port, $restart, $health] = $targets[$instance];
$root = '/www/wwwroot/' . $domain;
$backup = '/www/backups/' . $domain . '/r57-9537349f-20261015';
$payload = $backup . '/payload';
$commit = '9537349fb18b8794ee914e0346d8c8d3910704c8';
$upgradeKey = '20261015-002-product-category-statistics-switch';
$sqlHash = 'a05140f5bf15d385cfd0f6d583daf2e6a908fef6debcd3e75980a4366f5d4435';
$entryBefore = '2deef682bfc4d41381cbeb9857828cca772ef15c93a5745a29d95b047512ce71';
$entryAfter = '42349d893b958c6b04d6f7add6ce7f8c4e627081d2f61657fa89ef9debf19bb6';
$before = [
    'rh' => [
        'app/controller/admin/v1/product/StoreProductCategory.php' => '253f9a0a8af38b4e86bf5cf234ec619e36d73f87ec9b0288be2f746a43f401a7',
        'app/services/product/category/StoreProductCategoryServices.php' => 'ef1fb7c85293b2fb6fff9d9f4c7fa25b1c4b283fbb0900e10e3f32a6aa1be369',
        'app/services/report/MemberManagementDashboardServices.php' => '4f822b3d76562d5dd2980a6920a98578b5b307b3497f9fede5bba3a8d1c537ae',
        'route/admin.php' => 'e0ce640e39f95a4d25d9a2c74e312ca80e37fba9ecdbe9e56838ad31760ecf73',
    ],
    '008' => [
        'app/controller/admin/v1/product/StoreProductCategory.php' => '253f9a0a8af38b4e86bf5cf234ec619e36d73f87ec9b0288be2f746a43f401a7',
        'app/services/product/category/StoreProductCategoryServices.php' => 'ef1fb7c85293b2fb6fff9d9f4c7fa25b1c4b283fbb0900e10e3f32a6aa1be369',
        'app/services/report/MemberManagementDashboardServices.php' => '42c5d60c593ca7f304e449a5bc4c8a606ea947faae1683af84ae59fe41419a41',
        'route/admin.php' => 'c1d3eeb6ca8c7ded3c2375fc05fc20d143ca5489ec38658d4ffc5bd859361ee6',
    ],
];
$after = [
    'app/controller/admin/v1/product/StoreProductCategory.php' => 'a1af96f3fa70be0ce72e1da57cd42e2e0abdaf72b9186886196496c6c20ee51f',
    'app/services/product/category/StoreProductCategoryServices.php' => 'ee61219711de347ab52485b7cdfc1f861b91ec0181e61c8c4292a99fd8d2d050',
    'app/services/report/MemberManagementDashboardServices.php' => 'fb0c607b63c1c9237ac678bd2e1cf6711383b17557fba86fc9b4b13f432cff1b',
    'route/admin.php' => $instance === 'rh'
        ? 'c46bb0061c1aafb386fded2d589235c7da68441f076e046bddbe7dc0445f9614'
        : '44b7353994b1be8cd10708bc6c4bd44959ac6d57a123a78067a6edb0c123631b',
];
chdir($root);
require $root . '/vendor/autoload.php';
(new think\App($root . '/'))->initialize();
$config = config('database.connections.mysql');
if ($config['database'] !== $database || (string)$config['hostport'] !== $port
    || !in_array($config['hostname'], ['localhost', '127.0.0.1'], true)) {
    throw new RuntimeException('Wrong customer database');
}
$pdo = new PDO('mysql:host=' . $config['hostname'] . ';port=' . $port . ';dbname=' . $database . ';charset=utf8mb4',
    $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_MULTI_STATEMENTS => true]);
$column = static function () use ($pdo): array {
    return $pdo->query("SELECT COLUMN_NAME,COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_store_product_category' AND COLUMN_NAME='statistics_enabled'")->fetchAll(PDO::FETCH_ASSOC);
};
$upgradeCount = static function () use ($pdo, $upgradeKey): int {
    $query = $pdo->prepare('SELECT COUNT(*) FROM eb_database_upgrade_log WHERE upgrade_key=?');
    $query->execute([$upgradeKey]);
    return (int)$query->fetchColumn();
};
$checkHashes = static function (array $expected) use ($root): void {
    foreach ($expected as $relative => $hash) {
        if (!is_file($root . '/' . $relative) || hash_file('sha256', $root . '/' . $relative) !== $hash) {
            throw new RuntimeException('Live file changed: ' . $relative);
        }
    }
};
$run = static function (array $command, ?string $stdout = null, array $extraEnv = []) use ($backup): void {
    $stderr = $backup . '/command-stderr.tmp';
    $descriptors = [0 => ['pipe', 'r'], 1 => ['file', $stdout ?? '/dev/null', 'w'], 2 => ['file', $stderr, 'w']];
    $process = proc_open($command, $descriptors, $pipes, null, array_merge($_ENV, $extraEnv));
    if (!is_resource($process)) throw new RuntimeException('Cannot start command');
    fclose($pipes[0]);
    $status = proc_close($process);
    $message = is_file($stderr) ? trim((string)file_get_contents($stderr)) : '';
    @unlink($stderr);
    if ($status !== 0) throw new RuntimeException('Command failed ' . $status . ': ' . substr($message, 0, 240));
};
$manifestPath = $backup . '/manifest.json';
if ($mode === 'backup') {
    if (is_dir($backup) || $column() !== [] || $upgradeCount() !== 0) throw new RuntimeException('Backup/schema baseline changed');
    if ($instance === 'rh') {
        $record = $pdo->query('SELECT cate_name,type,relation_id,pid FROM eb_store_product_category WHERE id=21872')->fetch(PDO::FETCH_ASSOC);
        if (!$record || $record['cate_name'] !== '菡香美雅' || (int)$record['type'] !== 0
            || (int)$record['relation_id'] !== 0 || (int)$record['pid'] !== 0) throw new RuntimeException('Ruihao target category changed');
    }
    $checkHashes($before[$instance]);
    if (hash_file('sha256', $root . '/public/system.html') !== $entryBefore) throw new RuntimeException('Platform entry changed');
    if (!mkdir($backup, 0700, true)) throw new RuntimeException('Cannot create backup');
    $run(array_merge(['tar', '-czf', $backup . '/code-before.tar.gz', '-C', $root],
        array_keys($before[$instance]), ['public/system.html', 'public/view_admin']));
    $plain = $backup . '/database-before.sql';
    $run(['mysqldump', '--host=' . $config['hostname'], '--port=' . $port, '--user=' . $config['username'],
        '--single-transaction', '--quick', '--routines', '--triggers', '--no-tablespaces', '--default-character-set=utf8mb4', $database],
        $plain, ['MYSQL_PWD' => $config['password']]);
    $input = fopen($plain, 'rb');
    $output = gzopen($backup . '/database-before.sql.gz', 'wb6');
    if (!$input || !$output) throw new RuntimeException('Cannot compress database backup');
    while (!feof($input)) {
        $chunk = fread($input, 1024 * 1024);
        if ($chunk === false || gzwrite($output, $chunk) === false) throw new RuntimeException('Database compression failed');
    }
    fclose($input); gzclose($output); unlink($plain);
    $run(['gzip', '-t', $backup . '/database-before.sql.gz']);
    $run(['gzip', '-t', $backup . '/code-before.tar.gz']);
    $manifest = [
        'instance' => $instance, 'database' => $database, 'port' => $port, 'commit' => $commit,
        'backend_before' => $before[$instance], 'entry_before' => $entryBefore,
        'code_sha256' => hash_file('sha256', $backup . '/code-before.tar.gz'),
        'database_sha256' => hash_file('sha256', $backup . '/database-before.sql.gz'),
        'database_bytes' => filesize($backup . '/database-before.sql.gz'),
    ];
    file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    chmod($manifestPath, 0600);
    echo json_encode(['mode' => 'backup', 'manifest' => $manifest], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit;
}
if (!is_file($manifestPath)) throw new RuntimeException('Backup missing');
$manifest = json_decode((string)file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
if ($manifest['instance'] !== $instance || $manifest['database'] !== $database || $manifest['commit'] !== $commit
    || hash_file('sha256', $backup . '/code-before.tar.gz') !== $manifest['code_sha256']
    || hash_file('sha256', $backup . '/database-before.sql.gz') !== $manifest['database_sha256']) {
    throw new RuntimeException('Backup validation failed');
}
if ($mode === 'migrate') {
    $sql = $payload . '/backend/后端代码/database/upgrades/2026-10-15-商品分类统计开关/01-正式升级.sql';
    if (!is_file($sql) || hash_file('sha256', $sql) !== $sqlHash || $column() !== [] || $upgradeCount() !== 0) {
        throw new RuntimeException('Migration preflight changed');
    }
    if ($instance === 'rh') {
        $record = $pdo->query('SELECT cate_name,type,relation_id,pid FROM eb_store_product_category WHERE id=21872')->fetch(PDO::FETCH_ASSOC);
        if (!$record || $record['cate_name'] !== '菡香美雅' || (int)$record['type'] !== 0
            || (int)$record['relation_id'] !== 0 || (int)$record['pid'] !== 0) throw new RuntimeException('Ruihao target category changed');
    }
    $checkHashes($before[$instance]);
    $statement = $pdo->query((string)file_get_contents($sql));
    do { if ($statement->columnCount()) $statement->fetchAll(PDO::FETCH_ASSOC); } while ($statement->nextRowset());
    $statement->closeCursor();
    if (count($column()) !== 1 || (string)$column()[0]['COLUMN_DEFAULT'] !== '1') throw new RuntimeException('Column postcheck failed');
    $insert = $pdo->prepare('INSERT INTO eb_database_upgrade_log (upgrade_key,title,task_file,sql_checksum,code_version,executed_at,executed_by,result_note,created_at) VALUES (?,?,?,?,?,NOW(),?,?,NOW())');
    $insert->execute([$upgradeKey, '商品分类统计开关', '后端代码/database/upgrades/2026-10-15-商品分类统计开关/01-正式升级.sql',
        $sqlHash, $commit, 'Codex', 'R57 additive column; independent backup ' . $backup]);
    if ($instance === 'rh') {
        // The accepted Ruihao setting hides only this root category; 008 keeps its own default-on data.
        $change = $pdo->prepare("UPDATE eb_store_product_category SET statistics_enabled=0 WHERE id=21872 AND cate_name='菡香美雅' AND type=0 AND relation_id=0 AND pid=0 AND statistics_enabled=1");
        $change->execute();
        if ($change->rowCount() !== 1) throw new RuntimeException('Ruihao category setting mismatch');
    }
}
if (count($column()) !== 1 || $upgradeCount() !== 1) throw new RuntimeException('Migration incomplete');
if ($instance === 'rh') {
    $value = $pdo->query('SELECT statistics_enabled FROM eb_store_product_category WHERE id=21872')->fetchColumn();
    if ((string)$value !== '0') throw new RuntimeException('Ruihao category setting missing');
}
if ($mode === 'migrate') {
    echo json_encode(['instance' => $instance, 'mode' => $mode, 'column' => $column()[0], 'upgrade_count' => $upgradeCount()], JSON_THROW_ON_ERROR) . PHP_EOL;
    exit;
}
$candidate = [];
foreach ($after as $relative => $hash) $candidate[$relative] = $payload . '/backend/后端代码/' . $relative;
$candidateEntry = $payload . '/platform/system.html';
if (hash_file('sha256', $candidateEntry) !== $entryAfter) throw new RuntimeException('Platform candidate changed');
foreach ($candidate as $relative => $file) {
    if (!is_file($file) || hash_file('sha256', $file) !== $after[$relative]) throw new RuntimeException('Backend candidate changed: ' . $relative);
    $run([PHP_BINARY, '-l', $file]);
}
$assetsRoot = $payload . '/platform/view_admin';
if (!is_dir($assetsRoot)) throw new RuntimeException('Platform assets missing');
$copyAtomic = static function (string $source, string $destination): void {
    if (!is_dir(dirname($destination)) && !mkdir(dirname($destination), 0755, true)) throw new RuntimeException('Cannot create asset directory');
    $temporary = tempnam(dirname($destination), '.r57-');
    if ($temporary === false || !copy($source, $temporary) || !chmod($temporary, 0644)
        || !chown($temporary, 'www') || !chgrp($temporary, 'www') || !rename($temporary, $destination)) {
        throw new RuntimeException('Atomic copy failed: ' . $destination);
    }
};
if ($mode === 'deploy') {
    $checkHashes($before[$instance]);
    if (hash_file('sha256', $root . '/public/system.html') !== $entryBefore) throw new RuntimeException('Platform entry changed since backup');
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($assetsRoot, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $source) {
        if (!$source->isFile()) continue;
        $relative = substr($source->getPathname(), strlen($assetsRoot) + 1);
        $destination = $root . '/public/view_admin/' . $relative;
        if (is_file($destination)) {
            if (hash_file('sha256', $destination) !== hash_file('sha256', $source->getPathname())) throw new RuntimeException('Asset collision: ' . $relative);
        } else $copyAtomic($source->getPathname(), $destination);
    }
    try {
        foreach ($candidate as $relative => $file) $copyAtomic($file, $root . '/' . $relative);
        $run(['bash', '-lc', $restart]);
        $run(['bash', '-lc', $health]);
        $copyAtomic($candidateEntry, $root . '/public/system.html');
    } catch (Throwable $error) {
        // Schema stays additive; restore only this instance's previous code and entry.
        $run(array_merge(['tar', '-xzf', $backup . '/code-before.tar.gz', '-C', $root], array_keys($before[$instance]), ['public/system.html']));
        $run(['bash', '-lc', $restart]);
        throw $error;
    }
}
$checkHashes($after);
if (hash_file('sha256', $root . '/public/system.html') !== $entryAfter) throw new RuntimeException('Platform entry postcheck failed');
$run(['bash', '-lc', $health]);
echo json_encode(['instance' => $instance, 'mode' => $mode, 'commit' => $commit, 'entry_sha256' => $entryAfter,
    'backend' => $after, 'column' => $column()[0], 'upgrade_count' => $upgradeCount(), 'backup' => $backup],
    JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
