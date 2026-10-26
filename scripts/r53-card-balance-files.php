<?php
declare(strict_types=1);

// Signed-manifest, instance-local file phases. Back up every target before any
// mutation; schema-only scripts precede backend, and HTML entries switch last.
$directory = $argv[1] ?? ''; $mode = $argv[2] ?? ''; $manifestHash = $argv[3] ?? '';
if (PHP_SAPI !== 'cli' || !preg_match('~^/www/backups/(rh|008|010)/r53-card-balance-([a-f0-9]{8})$~D', $directory, $match)
    || !in_array($mode, ['backup', 'migrations', 'backend', 'entry', 'rollback'], true)
    || !preg_match('/^[a-f0-9]{64}$/D', $manifestHash)) exit(2);
umask(0077);
set_exception_handler(static function (Throwable $error): void { file_put_contents('php://stderr', $error->getMessage() . PHP_EOL); exit(1); });
$manifestFile = $directory . '/payload/manifest.json';
if (hash_file('sha256', $manifestFile) !== $manifestHash) throw new RuntimeException('Manifest changed');
$manifest = json_decode((string)file_get_contents($manifestFile), true, 512, JSON_THROW_ON_ERROR);
$site = $match[1]; $root = '/www/wwwroot/' . ($site === 'rh' ? 'rh.cc3798.com' : $site . '.cc3798.com');
if ($manifest['site'] !== $site || substr($manifest['commit'], 0, 8) !== $match[2]) throw new RuntimeException('Instance mismatch');
function r53Digest(string $file): ?string { clearstatcache(true, $file); return is_file($file) ? hash_file('sha256', $file) : null; }
function r53Destination(string $key, string $root, string $site): string {
    if (strpos($key, '..') !== false || strpos($key, "\0") !== false) throw new RuntimeException('Unsafe target path');
    $base = $root; $relative = null;
    if (strpos($key, 'formal/') === 0) {
        $part = substr($key, 7);
        if (strpos($part, 'app/services/') === 0 && substr($part, -4) === '.php') $relative = $part;
        if (strpos($part, 'database/upgrades/2026-10-24-卡转余额/') === 0 && substr($part, -4) === '.php') $relative = $part;
        if (strpos($part, 'public/view_cashier_v3/') === 0) $relative = $part;
    } elseif ($site === 'rh' && strpos($key, 'preview/public/view_cashier_v3/') === 0) {
        $base .= '-8080'; $relative = substr($key, 8);
    }
    if ($relative === null) throw new RuntimeException('Target outside authorized code/static scope');
    $file = $base . '/' . $relative;
    if (is_link($file)) throw new RuntimeException('Target symlink denied');
    $parent = dirname($file);
    while (!file_exists($parent) && !is_link($parent)) $parent = dirname($parent);
    $real = realpath($parent);
    if ($real === false || ($real !== $base && strpos($real, $base . '/') !== 0)) throw new RuntimeException('Target escapes instance');
    return $file;
}
function r53Put(string $source, string $destination): void {
    if (!is_dir(dirname($destination)) && !mkdir(dirname($destination), 0755, true)) throw new RuntimeException('Cannot create target directory');
    $temporary = $destination . '.r53-next-' . getmypid();
    if (!copy($source, $temporary)) throw new RuntimeException('Copy failed');
    chmod($temporary, 0644);
    chown($temporary, is_file($destination) ? fileowner($destination) : 'www');
    chgrp($temporary, is_file($destination) ? filegroup($destination) : 'www');
    if (!rename($temporary, $destination)) throw new RuntimeException('Atomic file switch failed');
}
$record = $directory . '/prepared.json';
if ($mode === 'backup') {
    if (file_exists($record)) throw new RuntimeException('File backup already prepared');
    $targets = [];
    foreach ($manifest['targets'] as $key => $row) {
        $file = r53Destination($key, $root, $site); $source = $directory . '/payload/' . $key;
        if (r53Digest($file) !== $row['before'] || r53Digest($source) !== $row['after']) throw new RuntimeException('Baseline/payload mismatch: ' . $key);
        $backup = $directory . '/before/' . hash('sha256', $key);
        if ($row['before'] !== null) {
            if (!is_dir(dirname($backup))) mkdir(dirname($backup), 0700, true);
            if (!copy($file, $backup) || r53Digest($backup) !== $row['before']) throw new RuntimeException('File backup failed');
        }
        $targets[$key] = $row + ['backup' => $backup];
    }
    if (file_put_contents($record, json_encode($targets, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)) === false) throw new RuntimeException('Cannot record file backup');
    if (!copy($root . '/.env', $directory . '/instance-before.env')
        || r53Digest($root . '/.env') !== r53Digest($directory . '/instance-before.env')) throw new RuntimeException('Instance configuration backup failed');
    chmod($record, 0600); chmod($directory . '/instance-before.env', 0600);
} else {
    // Only the explicitly waived, not-yet-live 010 test database may omit a
    // full dump. Other sites still require the verified independent backup.
    $waived = $site === '010' && is_file($directory . '/database-backup-waiver.json');
    $backup = json_decode((string)file_get_contents($directory . ($waived ? '/database-backup-waiver.json' : '/database-backup.json')), true, 512, JSON_THROW_ON_ERROR);
    if ($backup['site'] !== $site || $backup['commit'] !== $manifest['commit']
        || ($waived ? empty($backup['explicitUserWaiver']) : r53Digest($directory . '/database-before.sql.gz') !== $backup['sha256'])) throw new RuntimeException('Database backup or explicit 010 waiver missing or changed');
    $targets = json_decode((string)file_get_contents($record), true, 512, JSON_THROW_ON_ERROR);
    foreach ($targets as $key => $row) if (!in_array(r53Digest(r53Destination($key, $root, $site)), [$row['before'], $row['after']], true)) throw new RuntimeException('Concurrent target change: ' . $key);
    if ($mode === 'rollback') {
        if (!is_file($directory . '/database-rollback-safe.json')) throw new RuntimeException('Database rollback safety proof required');
        foreach (array_reverse($targets, true) as $key => $row) {
            $file = r53Destination($key, $root, $site);
            if ($row['before'] === null) { if (is_file($file)) unlink($file); }
            else r53Put($row['backup'], $file);
        }
    } else {
        foreach ($targets as $key => $row) {
            $isMigration = strpos($key, 'formal/database/upgrades/') === 0;
            $isEntry = substr($key, -11) === '/index.html';
            if (($mode === 'migrations' && $isMigration) || ($mode === 'entry' && $isEntry)
                || ($mode === 'backend' && !$isMigration && !$isEntry)) {
                $file = r53Destination($key, $root, $site);
                if (r53Digest($file) === $row['after']) continue;
                if (r53Digest($directory . '/payload/' . $key) !== $row['after']) throw new RuntimeException('Payload changed');
                r53Put($directory . '/payload/' . $key, $file);
            }
        }
    }
}
echo 'R53_FILES_' . strtoupper($mode) . '_PASS', PHP_EOL;
