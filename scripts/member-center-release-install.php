<?php
declare(strict_types=1);

/** 008-only release executor for M008-R8-001. Source/config hashes are checked
 * immediately before writes; rollback refuses to overwrite any unknown state.
 * No schema, credential, member asset, order or cashier record is modified.
 */
$root = '/www/wwwroot/008.cc3798.com';
$directory = __DIR__;
$mode = $argv[1] ?? '';
if (!preg_match('~^/www/backups/008\.cc3798\.com/20261021-member-center-[a-f0-9]{8}$~D', $directory)
    || !in_array($mode, ['inspect', 'prepare', 'install', 'verify', 'rollback'], true)) {
    throw new RuntimeException('Explicit 008 backup directory and release action required');
}
function insist(bool $ok, string $why): void {if (!$ok) throw new RuntimeException($why);}
function digest(string $file): ?string {return is_file($file) ? hash_file('sha256', $file) : null;}
function loadJson(string $file): array {return json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);}
function saveJson(string $file, array $data): void {
    insist(file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)) !== false, 'Cannot write audit');
    chmod($file, 0600);
}
function atomicCopy(string $from, string $to): void {
    if (!is_dir(dirname($to))) mkdir(dirname($to), 0755, true);
    $tmp = $to . '.m008-r8-next';
    insist(copy($from, $tmp), 'Cannot stage ' . $to);
    chmod($tmp, 0644); chown($tmp, 'www'); chgrp($tmp, 'www');
    insist(rename($tmp, $to), 'Cannot switch ' . $to);
}
$env = parse_ini_file($root . '/.env', true, INI_SCANNER_RAW);
$cfg = array_change_key_case($env['DATABASE'] ?? []);
insist(($cfg['database'] ?? '') === 'mohe_008' && (string)($cfg['hostport'] ?? '') === '3307'
    && in_array($cfg['hostname'] ?? '', ['127.0.0.1', 'localhost'], true), 'Wrong database identity');
$db = new PDO('mysql:host=' . $cfg['hostname'] . ';port=3307;dbname=mohe_008;charset=utf8mb4',
    $cfg['username'], $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
function memberRow(PDO $db, bool $lock = false): array {
    $rows = $db->query("SELECT id,value,update_time FROM eb_diy WHERE template_name='member' AND type=3" . ($lock ? ' FOR UPDATE' : ''))->fetchAll();
    insist(count($rows) === 1 && (int)$rows[0]['id'] === 2, 'Member row identity changed');
    return $rows[0];
}
function protectedFiles(string $root): array {
    $result = [];
    foreach (['.env', 'public/index.html', 'public/view_cashier_v3/index.html'] as $file) $result[$file] = digest($root . '/' . $file);
    return $result;
}
function invalidateMember(array $env): void {
    insist(($env['CACHE']['PREFIX'] ?? '') === '008:' && ($env['CACHE']['TAG_PREFIX'] ?? '') === '008:', 'Wrong cache namespace');
    $cfg = $env['REDIS'];
    insist((int)$cfg['PORT'] === 6388 && (int)$cfg['SELECT'] === 0, 'Wrong Redis instance');
    $redis = new Redis(); $redis->connect($cfg['REDIS_HOSTNAME'], (int)$cfg['PORT']);
    if (($cfg['REDIS_PASSWORD'] ?? '') !== '') $redis->auth($cfg['REDIS_PASSWORD']);
    $redis->select(0);
    $redis->del('008:mc:diy:tagdiy_data_member_3');
}
if ($mode === 'inspect') {
    $row = memberRow($db);
    $result = ['instance' => '008', 'database' => ['name' => 'mohe_008', 'port' => 3307,
        'version' => $db->query('SELECT VERSION()')->fetchColumn()], 'protected' => protectedFiles($root),
        'row' => ['id' => 2, 'sha256' => hash('sha256', $row['value']), 'menuCount' => count(loadJsonValue($row['value'])['menu']['list'] ?? [])],
        'adminEntry' => digest($root . '/public/system.html'), 'backend' => [],
        'latestUpgrade' => $db->query('SELECT upgrade_key,code_version,executed_at FROM eb_database_upgrade_log ORDER BY id DESC LIMIT 1')->fetch()];
    foreach (['app/services/diy/DiyServices.php', 'app/controller/api/v1/PublicController.php'] as $file) $result['backend'][$file] = digest($root . '/' . $file);
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit;
}
function loadJsonValue(string $value): array {return json_decode($value, true, 512, JSON_THROW_ON_ERROR);}
$manifest = loadJson($directory . '/payload/manifest.json');
insist(($manifest['commit'] ?? '') === 'd208975258544751cd39d549157e61b8015a527c', 'Wrong release commit');
foreach ($manifest['files'] as $file => $expected) {
    insist(strpos($file, '..') === false && preg_match('~^(?:app/|public/)~', $file) === 1, 'Unsafe payload path');
    insist(digest($directory . '/payload/' . $file) === $expected, 'Payload drift ' . $file);
}
if ($mode === 'prepare') {
    insist(!is_file($directory . '/before.json'), 'Backup already exists');
    insist(digest($root . '/public/system.html') === $manifest['before']['adminEntry'], 'Live admin entry changed');
    foreach ($manifest['baselineAdmin'] as $file => $expected) {
        insist(digest($root . '/public/' . $file) === $expected, 'Live admin baseline differs ' . $file);
    }
    foreach ($manifest['before']['backend'] as $file => $expected) insist(digest($root . '/' . $file) === $expected, 'Live backend changed');
    insist(protectedFiles($root) === $manifest['before']['protected'], 'Protected entry or environment changed');
    $row = memberRow($db);
    insist(hash('sha256', $row['value']) === $manifest['before']['row']['sha256'], 'Member configuration changed');
    $value = loadJsonValue($row['value']);
    insist(($value['menu']['list'] ?? null) === [], 'Refuse to replace an edited/nonempty menu');
    $fixtureText = trim(file_get_contents($directory . '/payload/accepted-service-menu.json'));
    insist(hash('sha256', $fixtureText) === '9bd2214a50dea15361b4b4c73df005c9b93b56f98a0ba2704db74f67d5ebf0cf', 'Accepted recovery menu changed');
    $fixture = loadJsonValue($fixtureText);
    insist(count($fixture['list']) === 14, 'Expected exactly 14 services');
    // Only list entries are restored; all current style/order/asset settings stay.
    $value['menu']['list'] = $fixture['list'];
    $next = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $before = ['row' => $row, 'nextValue' => $next, 'nextSha256' => hash('sha256', $next),
        'protected' => protectedFiles($root), 'files' => [], 'preparedAt' => time()];
    foreach ($manifest['files'] as $file => $expected) {
        $current = digest($root . '/' . $file); $before['files'][$file] = $current;
        insist(strpos($file, 'public/') !== 0 || $file === 'public/system.html' || $current === null || $current === $expected,
            'Refuse to overwrite unknown static asset ' . $file);
        if ($current !== null) {
            $backup = $directory . '/before/' . $file;
            if (!is_dir(dirname($backup))) mkdir(dirname($backup), 0700, true);
            insist(copy($root . '/' . $file, $backup) && digest($backup) === $current, 'Backup mismatch ' . $file);
            chmod($backup, 0600);
        }
    }
    saveJson($directory . '/before.json', $before);
    $roundTrip = loadJson($directory . '/before.json');
    insist($roundTrip === $before && hash('sha256', $roundTrip['row']['value']) === $manifest['before']['row']['sha256'], 'Database backup failed');
    echo json_encode(['result' => 'BACKUP_VERIFIED', 'files' => count($before['files']), 'rowSha256' => hash('sha256', $row['value'])]), PHP_EOL;
    exit;
}
$before = loadJson($directory . '/before.json');
if ($mode === 'verify') {
    foreach ($manifest['files'] as $file => $expected) insist(digest($root . '/' . $file) === $expected, 'Deployed file mismatch ' . $file);
    insist(protectedFiles($root) === $before['protected'], 'Protected file changed');
    $row = memberRow($db);
    insist(hash('sha256', $row['value']) === $before['nextSha256'], 'Restored row mismatch');
    $old = loadJsonValue($before['row']['value']); $new = loadJsonValue($row['value']);
    $count = count($new['menu']['list']); $new['menu']['list'] = [];
    insist($count === 14 && $old === $new, 'Unrelated configuration changed');
    $result = ['result' => 'PASS', 'instance' => '008', 'commit' => $manifest['commit'],
        'businessCommit' => $manifest['businessCommit'], 'files' => count($manifest['files']),
        'restoredMenuCount' => $count, 'rowSha256' => hash('sha256', $row['value']), 'protectedUnchanged' => true];
    saveJson($directory . '/verified.json', $result);
    echo json_encode($result), PHP_EOL;
    exit;
}
$stmt = $db->prepare('SELECT GET_LOCK(?,0)'); $stmt->execute(['mohe008-member-center-r8']);
insist((int)$stmt->fetchColumn() === 1, '008 recovery lock busy');
try {
    insist(protectedFiles($root) === $before['protected'], 'Protected file changed since backup');
    if ($mode === 'install') {
        insist(!is_file($directory . '/installed.json'), 'Already installed');
        foreach ($before['files'] as $file => $expected) insist(digest($root . '/' . $file) === $expected, 'Live file changed ' . $file);
        $db->beginTransaction();
        try {
            $row = memberRow($db, true);
            insist($row === $before['row'], 'Configuration changed since backup');
            foreach ($manifest['files'] as $file => $expected) {
                if ($file !== 'public/system.html') atomicCopy($directory . '/payload/' . $file, $root . '/' . $file);
            }
            $stmt = $db->prepare('UPDATE eb_diy SET value=?,update_time=? WHERE id=2 AND SHA2(value,256)=?');
            $stmt->execute([$before['nextValue'], time(), hash('sha256', $row['value'])]);
            insist($stmt->rowCount() === 1, 'Configuration update refused');
            $db->commit();
        } catch (Throwable $error) {if ($db->inTransaction()) $db->rollBack(); throw $error;}
        atomicCopy($directory . '/payload/public/system.html', $root . '/public/system.html');
        invalidateMember($env);
        saveJson($directory . '/installed.json', ['commit' => $manifest['commit'], 'at' => time()]);
        echo json_encode(['result' => 'INSTALLED', 'commit' => $manifest['commit']]), PHP_EOL;
    } else {
        // Deployment rollback is conditional on known bytes, never a blanket
        // restore over later legitimate configuration edits or other releases.
        foreach ($before['files'] as $file => $oldHash) {
            $current = digest($root . '/' . $file);
            insist($current === $oldHash || $current === $manifest['files'][$file], 'Unknown file blocks rollback ' . $file);
        }
        $db->beginTransaction();
        try {
            $row = memberRow($db, true); $sha = hash('sha256', $row['value']);
            insist(in_array($sha, [hash('sha256', $before['row']['value']), $before['nextSha256']], true), 'Unknown row blocks rollback');
            if ($sha === $before['nextSha256']) {
                $stmt = $db->prepare('UPDATE eb_diy SET value=?,update_time=? WHERE id=2');
                $stmt->execute([$before['row']['value'], $before['row']['update_time']]);
            }
            foreach ($before['files'] as $file => $oldHash) {
                if ($oldHash !== null) atomicCopy($directory . '/before/' . $file, $root . '/' . $file);
                elseif (is_file($root . '/' . $file)) unlink($root . '/' . $file);
            }
            $db->commit();
        } catch (Throwable $error) {if ($db->inTransaction()) $db->rollBack(); throw $error;}
        invalidateMember($env);
        echo json_encode(['result' => 'ROLLED_BACK']), PHP_EOL;
    }
} finally {
    $stmt = $db->prepare('SELECT RELEASE_LOCK(?)'); $stmt->execute(['mohe008-member-center-r8']);
}
