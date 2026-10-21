<?php
declare(strict_types=1);

/** Static member H5 release only: customer config, database and other frontends stay immutable. */
$root = $argv[1] ?? '';
$directory = $argv[2] ?? '';
$mode = $argv[3] ?? '';
$sites = [
    '/www/wwwroot/rh.cc3798.com' => ['key' => 'rh', 'database' => 'ruihao', 'port' => '3306'],
    '/www/wwwroot/008.cc3798.com' => ['key' => '008', 'database' => 'mohe_008', 'port' => '3307'],
];
if (!isset($sites[$root])
    || !preg_match('~^/www/backups/[^/]+/20261020-member-round5-46c4b377$~D', $directory)
    || !in_array($mode, ['prepare', 'install', 'verify', 'rollback'], true)) {
    throw new RuntimeException('Wrong release target');
}

$site = $sites[$root];
$manifest = json_decode((string)file_get_contents($directory . '/payload/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$hash = static function (string $file): ?string {
    return is_file($file) ? hash_file('sha256', $file) : null;
};
$save = static function (string $file, array $data) use ($directory): void {
    file_put_contents($directory . '/' . $file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL);
};
$copy = static function (string $from, string $to) use ($assert, $hash): void {
    if (!is_dir(dirname($to))) mkdir(dirname($to), 0755, true);
    $next = $to . '.m008-r5-next';
    $assert(copy($from, $next), 'Stage failed: ' . $to);
    chmod($next, 0644);
    chown($next, 'www');
    chgrp($next, 'www');
    $assert($hash($next) === $hash($from), 'Stage hash differs: ' . $to);
    $assert(rename($next, $to), 'Atomic switch failed: ' . $to);
};
$entryState = static function (array $entry) use ($hash, $root): array {
    $actual = ['index' => $hash($entry['path']), 'assets' => []];
    foreach ($entry['assets'] as $reference => $_) {
        $public = strpos($entry['path'], '-8080/') !== false ? $root . '-8080/public' : $root . '/public';
        $relative = explode('?', $reference)[0];
        $path = strpos($relative, '/') === 0 ? $public . $relative : dirname($entry['path']) . '/' . $relative;
        $actual['assets'][$reference] = $hash($path);
    }
    return $actual;
};

try {
    $assert(($manifest['commit'] ?? '') === '46c4b37715880d0b6f8f22f59e5d1cf39d3ad54c', 'Wrong commit');
    foreach ($manifest['files'] as $file => $sha) {
        $assert(strpos($file, '..') === false && strpos($file, 'public/') === 0, 'Unexpected path: ' . $file);
        $assert(!preg_match('~(?:^|/)(?:system\.html|view_admin|view_cashier_v3)(?:/|$)~', $file), 'Protected frontend in payload');
        $assert($hash($directory . '/payload/' . $file) === $sha, 'Payload differs: ' . $file);
    }
    $environment = parse_ini_file($root . '/.env', true, INI_SCANNER_RAW);
    $database = array_change_key_case($environment['DATABASE'] ?? $environment['database'] ?? []);
    $assert(($database['database'] ?? '') === $site['database'] && (string)($database['hostport'] ?? '') === $site['port'], 'Wrong instance database');
    // The member artifact is immutable, while an independently released admin
    // or cashier may advance before this instance is installed. Use the exact
    // per-instance snapshot supplied immediately before deployment when present.
    $baselineFile = $directory . '/baseline.json';
    $beforeRelease = is_file($baselineFile)
        ? json_decode((string)file_get_contents($baselineFile), true, 512, JSON_THROW_ON_ERROR)
        : $manifest['before'][$site['key']];
    $checkBaseline = static function () use ($beforeRelease, $entryState, $hash, $root, $assert): void {
        $assert($hash($root . '/.env') === $beforeRelease['environment'], 'Environment changed');
        foreach ($beforeRelease['front'] as $entry) {
            $actual = $entryState($entry);
            $assert($actual['index'] === $entry['index'] && $actual['assets'] === $entry['assets'], 'Frontend baseline changed: ' . $entry['path']);
        }
    };

    if ($mode === 'prepare') {
        $assert(!is_file($directory . '/before.json'), 'Snapshot already exists');
        $checkBaseline();
        $before = ['files' => [], 'environment' => $hash($root . '/.env')];
        foreach ($manifest['files'] as $file => $_) {
            $old = $hash($root . '/' . $file);
            $before['files'][$file] = $old;
            if ($old !== null) {
                $target = $directory . '/before/' . $file;
                if (!is_dir(dirname($target))) mkdir(dirname($target), 0700, true);
                $assert(copy($root . '/' . $file, $target), 'Backup failed: ' . $file);
                $assert($hash($target) === $old, 'Backup hash differs: ' . $file);
            }
        }
        $save('before.json', $before);
        echo "BACKUP_VERIFIED\n";
        exit;
    }

    $before = json_decode((string)file_get_contents($directory . '/before.json'), true, 512, JSON_THROW_ON_ERROR);
    if ($mode === 'install') {
        $assert(!is_file($directory . '/installed.json'), 'Release already installed');
        $checkBaseline();
        foreach ($before['files'] as $file => $sha) $assert($hash($root . '/' . $file) === $sha, 'File changed after backup: ' . $file);
        foreach ($manifest['files'] as $file => $_) {
            if ($file === 'public/index.html') continue;
            $copy($directory . '/payload/' . $file, $root . '/' . $file);
        }
        $copy($directory . '/payload/public/index.html', $root . '/public/index.html');
        $save('installed.json', ['commit' => $manifest['commit'], 'installedAt' => date(DATE_ATOM)]);
    }
    if ($mode === 'rollback') {
        foreach ($manifest['files'] as $file => $candidate) {
            $assert(in_array($hash($root . '/' . $file), [$candidate, $before['files'][$file]], true), 'Later edit blocks rollback: ' . $file);
        }
        $files = array_keys($manifest['files']);
        usort($files, static fn(string $a, string $b): int => ($a === 'public/index.html' ? 1 : 0) <=> ($b === 'public/index.html' ? 1 : 0));
        foreach ($files as $file) {
            $old = $before['files'][$file];
            if ($old === null) {
                if (is_file($root . '/' . $file)) unlink($root . '/' . $file);
            } else {
                $copy($directory . '/before/' . $file, $root . '/' . $file);
            }
        }
        $save('rollback.json', ['memberFilesRestored' => true, 'databaseUntouched' => true]);
        echo "ROLLED_BACK\n";
        exit;
    }

    foreach ($manifest['files'] as $file => $sha) $assert($hash($root . '/' . $file) === $sha, 'Installed hash differs: ' . $file);
    $assert($hash($root . '/.env') === $before['environment'], 'Environment changed during release');
    foreach ($beforeRelease['front'] as $key => $entry) {
        if ($key === 'member') continue;
        $actual = $entryState($entry);
        $assert($actual['index'] === $entry['index'] && $actual['assets'] === $entry['assets'], 'Protected frontend changed: ' . $key);
    }
    $result = ['instance' => $site['key'], 'result' => 'PASS', 'commit' => $manifest['commit'],
        'files' => count($manifest['files']), 'environmentUnchanged' => true, 'databaseWrites' => 0,
        'adminUnchanged' => true, 'cashierEntriesUnchanged' => true];
    $save('verified-files.json', $result);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'DEPLOY STOPPED: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
