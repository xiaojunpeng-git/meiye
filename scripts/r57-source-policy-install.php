<?php
/** Instance-bound file installer. The manifest is fixed before upload; no
 * customer configuration, retired preview, or unlisted file can be replaced.
 */
$directory = $argv[1] ?? ''; $mode = $argv[2] ?? ''; $expected = $argv[3] ?? '';
if (!preg_match('~^/www/backups/(rh|008|010)/r57-source-policy-([a-f0-9]{8})$~D', $directory, $match)
    || !in_array($mode, ['backup', 'install', 'verify', 'rollback'], true)
    || !preg_match('/^[a-f0-9]{64}$/D', $expected)
    || hash_file('sha256', $directory . '/payload/manifest.json') !== $expected) throw new RuntimeException('Fixed instance manifest required');
$manifest = json_decode(file_get_contents($directory . '/payload/manifest.json'), true);
$site = $match[1]; $root = '/www/wwwroot/' . ($site === 'rh' ? 'rh.cc3798.com' : $site . '.cc3798.com');
if ($manifest['site'] !== $site || substr($manifest['commit'], 0, 8) !== $match[2] || $manifest['previewAuthorized'] !== false) throw new RuntimeException('Wrong instance');
function r57Digest($file) { clearstatcache(true, $file); return is_file($file) ? hash_file('sha256', $file) : null; }
function r57Destination($key) {
    global $root, $manifest;
    if (strpos($key, '..') !== false || strpos($key, "\0") !== false || strpos($key, 'formal/') !== 0) throw new RuntimeException('Unsafe path');
    $relative = substr($key, 7);
    $allowed = (in_array($relative, $manifest['backendAllowlist'], true) && preg_match('~^app/(controller|services)/[A-Za-z0-9_/-]+\.php$~D', $relative))
        || $relative === 'public/system.html'
        || preg_match('~^public/(view_admin|view_cashier_v3|view_mobile_v3)/[A-Za-z0-9_./-]+$~D', $relative);
    if (!$allowed) throw new RuntimeException('Outside R57 scope');
    $target = $root . '/' . $relative;
    if (is_link($target)) throw new RuntimeException('Target symlink forbidden');
    $parent = dirname($target); while (!file_exists($parent)) $parent = dirname($parent);
    $real = realpath($parent);
    if (!$real || ($real !== $root && strpos($real, $root . '/') !== 0)) throw new RuntimeException('Target escapes instance');
    return $target;
}
function r57Put($source, $target) {
    if (!is_dir(dirname($target))) mkdir(dirname($target), 0755, true);
    $next = $target . '.r57-next';
    if (file_exists($next) || is_link($next)) throw new RuntimeException('Pending file exists');
    if (!copy($source, $next)) throw new RuntimeException('Copy failed');
    chmod($next, 0644); chown($next, is_file($target) ? fileowner($target) : 'www'); chgrp($next, is_file($target) ? filegroup($target) : 'www');
    if (!rename($next, $target)) throw new RuntimeException('Atomic switch failed');
}
$lock = fopen($directory . '/install.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Concurrent installer');
$record = $directory . '/prepared.json';
if ($mode === 'backup') {
    if (file_exists($record)) throw new RuntimeException('Batch already prepared');
    $rows = [];
    foreach ($manifest['targets'] as $key => $row) {
        $target = r57Destination($key); $source = $directory . '/payload/' . $key;
        if (r57Digest($target) !== $row['before'] || r57Digest($source) !== $row['after']) throw new RuntimeException('Baseline/payload drift: ' . $key);
        $backup = $directory . '/before/' . hash('sha256', $key);
        if ($row['before'] !== null) {
            if (!is_dir(dirname($backup))) mkdir(dirname($backup), 0700, true);
            if (!copy($target, $backup) || r57Digest($backup) !== $row['before']) throw new RuntimeException('Backup failed');
        }
        $rows[$key] = $row + ['backup' => $backup];
    }
    if (file_put_contents($record, json_encode($rows, JSON_PRETTY_PRINT)) === false) throw new RuntimeException('Backup receipt failed');
    chmod($record, 0600);
} else {
    $rows = json_decode(file_get_contents($record), true);
    if (!is_array($rows) || array_keys($rows) !== array_keys($manifest['targets'])) throw new RuntimeException('Missing backup');
    foreach ($rows as $key => $row) {
        $allowed = $mode === 'verify' ? [$row['after']] : ($mode === 'install' ? [$row['before']] : [$row['before'], $row['after']]);
        if (!in_array(r57Digest(r57Destination($key)), $allowed, true)) throw new RuntimeException('Concurrent target change: ' . $key);
        if ($row['before'] !== null && r57Digest($row['backup']) !== $row['before']) throw new RuntimeException('Backup corrupt');
        if ($mode === 'install' && r57Digest($directory . '/payload/' . $key) !== $row['after']) throw new RuntimeException('Payload corrupt');
    }
    // Migration receipt is compulsory before any application switch. Schema
    // additions remain backward-compatible if this batch's files are rolled back.
    if ($mode === 'install') {
        $receipt = is_file($directory . '/migration.json') ? json_decode(file_get_contents($directory . '/migration.json'), true) : null;
        if (!$receipt || $receipt['site'] !== $site || $receipt['commit'] !== $manifest['commit']
            || $receipt['sqlSHA256'] !== $manifest['migrationSHA256']) throw new RuntimeException('Migration verification required');
    }
    if ($mode !== 'verify') foreach ($mode === 'rollback' ? array_reverse($rows, true) : $rows as $key => $row) {
        $target = r57Destination($key);
        if ($mode === 'rollback') {
            if ($row['before'] === null) { if (is_file($target)) unlink($target); }
            else r57Put($row['backup'], $target);
        } else r57Put($directory . '/payload/' . $key, $target);
    }
    foreach ($rows as $key => $row) if (r57Digest(r57Destination($key)) !== $row[$mode === 'rollback' ? 'before' : 'after']) throw new RuntimeException('Final fingerprint mismatch');
}
echo 'R57_FILES_' . strtoupper($mode) . '_PASS', PHP_EOL;
