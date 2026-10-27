<?php
declare(strict_types=1);

/** Accepted region-filter release only. No SQL writes, migrations, configuration
 * changes or other frontends are permitted; entry switches after worker restart. */
$key = $argv[1] ?? '';
$directory = $argv[2] ?? '';
$mode = $argv[3] ?? '';
$sites = [
    'rh' => ['rh.cc3798.com', 'ruihao', 3306, '/etc/init.d/ruihao_swoole restart'],
    '008' => ['008.cc3798.com', 'mohe_008', 3307, 'systemctl restart mohe-008-swoole.service'],
    '010' => ['010.cc3798.com', 'mohe_010_app', 3307, 'systemctl restart mohe-010-swoole.service'],
];
if (!isset($sites[$key]) || $directory !== '/www/backups/' . $sites[$key][0] . '/20261024-member-regions-4f13dba0'
    || !in_array($mode, ['prepare', 'install', 'verify', 'rollback'], true)) throw new RuntimeException('Wrong release target');
[$domain, $database, $port, $restart] = $sites[$key];
$root = '/www/wwwroot/' . $domain;
$assert = static function ($ok, $message) {if (!$ok) throw new RuntimeException($message);};
$hash = static function ($f) {return is_file($f) ? hash_file('sha256', $f) : null;};
$read = static function ($f) {return json_decode((string)file_get_contents($f), true, 512, JSON_THROW_ON_ERROR);};
$save = static function ($f, $value) use ($directory) {file_put_contents($directory . '/' . $f, json_encode($value, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);};
$manifest = $read($directory . '/payload/manifest.json');
$baseline = $read($directory . '/baseline.json');
$backend = ['app/controller/api/v1/store/Store.php', 'app/services/store/SystemStoreServices.php', 'app/services/store/StoreSelectableRegionServices.php'];
$copy = static function ($from, $to) use ($assert, $hash) {
    if (!is_dir(dirname($to))) mkdir(dirname($to), 0755, true);
    $next = $to . '.regions-next';
    $assert(copy($from, $next), 'Stage failed: ' . $to);
    chmod($next, 0644);chown($next, 'www');chgrp($next, 'www');
    $assert($hash($next) === $hash($from), 'Stage hash mismatch');
    $assert(rename($next, $to), 'Atomic switch failed: ' . $to);
};
$guard = static function ($memberToo) use ($root, $baseline, $assert, $hash) {
    $assert($hash($root . '/.env') === $baseline['environment'], 'Instance configuration drift');
    foreach ($baseline['front'] as $name => $entry) {
        if (!$memberToo && $name === 'member') continue;
        $assert($hash($entry['path']) === $entry['index'], 'Protected entry drift: ' . $name);
        foreach ($entry['assets'] as $ref => $sha) {
            $public = $name === 'cashier_preview' ? $root . '-8080/public' : $root . '/public';
            $relative = explode('?', $ref)[0];
            $file = $relative[0] === '/' ? $public . $relative : dirname($entry['path']) . '/' . $relative;
            $assert($hash($file) === $sha, 'Protected asset drift: ' . $name);
        }
    }
};
$restartWorker = static function () use ($restart, $assert) {
    exec($restart . ' 2>&1', $output, $code);
    $assert($code === 0, 'Scoped worker restart failed');
};
try {
    $assert($manifest['commit'] === '4f13dba0eac686adf2a029bac6dffc011a920c3a', 'Wrong fixed version');
    $env = parse_ini_file($root . '/.env', true, INI_SCANNER_RAW);
    $db = array_change_key_case($env['DATABASE'] ?? $env['database'] ?? []);
    $assert(($db['database'] ?? '') === $database && (int)$db['hostport'] === $port, 'Wrong database identity');
    foreach ($manifest['files'] as $file => $sha) {
        $assert(strpos($file, '..') === false && (in_array($file, $backend, true) || strpos($file, 'public/') === 0), 'Out-of-scope file');
        $assert(!preg_match('~(?:^|/)(?:system\.html|view_admin|view_cashier_v3)(?:/|$)~', $file), 'Protected frontend in payload');
        $assert($hash($directory . '/payload/' . $file) === $sha, 'Payload hash mismatch: ' . $file);
    }
    if ($mode === 'prepare') {
        $assert(!is_file($directory . '/before.json'), 'Backup already exists');
        $guard(true);
        foreach ($baseline['files'] as $file => $sha) $assert($hash($root . '/' . $file) === $sha, 'Online baseline changed: ' . $file);
        $before = ['files' => [], 'commit' => $manifest['commit']];
        foreach ($manifest['files'] as $file => $_) {
            $old = $hash($root . '/' . $file);$before['files'][$file] = $old;
            if ($old !== null) {
                $target = $directory . '/before/' . $file;
                if (!is_dir(dirname($target))) mkdir(dirname($target), 0700, true);
                $assert(copy($root . '/' . $file, $target) && $hash($target) === $old, 'Backup verification failed');
            }
        }
        $save('before.json', $before);
        echo "BACKUP_VERIFIED\n";exit;
    }
    $before = $read($directory . '/before.json');
    if ($mode === 'install') {
        $assert(!is_file($directory . '/installed.json'), 'Already installed');
        $guard(true);
        foreach ($baseline['files'] as $file => $sha) $assert($hash($root . '/' . $file) === $sha, 'Online baseline changed before switch');
        foreach ($before['files'] as $file => $sha) $assert($hash($root . '/' . $file) === $sha, 'Concurrent edit before switch');
        foreach ($backend as $file) {
            exec('/www/server/php/74/bin/php -l ' . escapeshellarg($directory . '/payload/' . $file) . ' 2>&1', $lint, $code);
            $assert($code === 0, 'PHP lint failed');
        }
        foreach ($manifest['files'] as $file => $_) {
            if ($file !== 'public/index.html') $copy($directory . '/payload/' . $file, $root . '/' . $file);
        }
        $restartWorker();
        $copy($directory . '/payload/public/index.html', $root . '/public/index.html');
        $save('installed.json', ['commit' => $manifest['commit'], 'time' => date(DATE_ATOM)]);
    }
    if ($mode === 'rollback') {
        // Never overwrite a newer task's deployment during recovery.
        foreach ($manifest['files'] as $file => $sha) $assert(in_array($hash($root . '/' . $file), [$sha, $before['files'][$file]], true), 'Newer file blocks rollback: ' . $file);
        $files = array_keys($manifest['files']);
        usort($files, static fn($a, $b) => ($a === 'public/index.html' ? 1 : 0) <=> ($b === 'public/index.html' ? 1 : 0));
        foreach ($files as $file) {
            if ($before['files'][$file] === null) {if (is_file($root . '/' . $file)) unlink($root . '/' . $file);}
            else $copy($directory . '/before/' . $file, $root . '/' . $file);
        }
        $restartWorker();$guard(true);$save('rollback.json', ['restored' => true, 'databaseWrites' => 0]);
        echo "ROLLED_BACK\n";exit;
    }
    foreach ($manifest['files'] as $file => $sha) $assert($hash($root . '/' . $file) === $sha, 'Installed file differs: ' . $file);
    $guard(false);
    $result = ['instance' => $key, 'commit' => $manifest['commit'], 'result' => 'PASS', 'files' => count($manifest['files']), 'databaseWrites' => 0, 'protectedFrontendsUnchanged' => true];
    $save('verified-files.json', $result);echo json_encode($result), PHP_EOL;
} catch (Throwable $e) {fwrite(STDERR, 'DEPLOY STOPPED: ' . $e->getMessage() . PHP_EOL);exit(1);}
