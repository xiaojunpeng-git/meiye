<?php
/** 会员H5逐站发布：固定文件清单、发布前指纹、独立文件备份；入口最后原子替换，不碰配置/数据库/其他终端。 */
$dir = $argv[1] ?? ''; $mode = $argv[2] ?? ''; $expected = $argv[3] ?? '';
if (!preg_match('~^/www/backups/(rh|008|010)/r43-store-contact-[a-f0-9]{8}$~D', $dir, $m)
    || !in_array($mode, ['backup', 'install', 'verify', 'rollback'], true)
    || !preg_match('/^[a-f0-9]{64}$/D', $expected)
    || hash_file('sha256', $dir . '/payload/manifest.json') !== $expected) throw new RuntimeException('Fixed batch required');
$manifest = json_decode(file_get_contents($dir . '/payload/manifest.json'), true);
$root = '/www/wwwroot/' . $m[1] . '.cc3798.com';
if ($manifest['site'] !== $m[1] || substr($manifest['tree'], 0, 8) !== substr($dir, -8)) throw new RuntimeException('Wrong instance/tree');
function digest43($file) { clearstatcache(true, $file); return is_file($file) ? hash_file('sha256', $file) : null; }
function path43($file) {
    global $root;
    if (strpos($file, '..') !== false || strpos($file, "\0") !== false
        || !preg_match('~^public/(index\.html|(?:static/(?:js|css|promotion)|pages|assets)/[A-Za-z0-9_./@\~-]+)$~D', $file)) throw new RuntimeException('Outside member H5 allowlist');
    $path = $root . '/' . $file;
    if (is_link($path)) throw new RuntimeException('Target symlink');
    $parent = dirname($path); while (!file_exists($parent)) $parent = dirname($parent);
    if (strpos(realpath($parent) . '/', $root . '/') !== 0) throw new RuntimeException('Path escapes instance');
    return $path;
}
function put43($from, $to) {
    if (!is_dir(dirname($to))) mkdir(dirname($to), 0755, true);
    $next = $to . '.r43-next';
    if (file_exists($next) || is_link($next) || !copy($from, $next)) throw new RuntimeException('Cannot stage target');
    chmod($next, 0644); chown($next, is_file($to) ? fileowner($to) : 'www'); chgrp($next, is_file($to) ? filegroup($to) : 'www');
    if (!rename($next, $to)) throw new RuntimeException('Cannot switch target');
}
$lock = fopen($dir . '/install.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Concurrent installer');
// 客户配置、平台、收银与PC壳均为受保护文件，不允许会员发布顺手覆盖。
foreach ($manifest['protected'] as $file => $sha) if (digest43($root . '/' . $file) !== $sha) throw new RuntimeException('Protected entry drift: ' . $file);
$record = $dir . '/prepared.json';
if ($mode === 'backup') {
    if (file_exists($record)) throw new RuntimeException('Already prepared');
    $rows = [];
    foreach ($manifest['targets'] as $file => $row) {
        $target = path43($file); $source = $dir . '/payload/' . $file;
        if (digest43($target) !== $row['before'] || digest43($source) !== $row['after']) throw new RuntimeException('Baseline or payload drift: ' . $file);
        $backup = $dir . '/before/' . hash('sha256', $file);
        if ($row['before'] !== null) {
            if (!is_dir(dirname($backup))) mkdir(dirname($backup), 0700, true);
            if (!copy($target, $backup) || digest43($backup) !== $row['before']) throw new RuntimeException('Backup failed');
        }
        $rows[$file] = $row + ['backup' => $backup];
    }
    if (file_put_contents($record, json_encode($rows, JSON_PRETTY_PRINT)) === false) throw new RuntimeException('Backup receipt failed');
    chmod($record, 0600);
} else {
    $rows = json_decode(file_get_contents($record), true);
    if (!is_array($rows) || array_keys($rows) !== array_keys($manifest['targets'])) throw new RuntimeException('Backup required');
    foreach ($rows as $file => $row) {
        $valid = $mode === 'verify' ? [$row['after']] : ($mode === 'install' ? [$row['before']] : [$row['before'], $row['after']]);
        if (!in_array(digest43(path43($file)), $valid, true)) throw new RuntimeException('Concurrent member change: ' . $file);
        if ($row['before'] !== null && digest43($row['backup']) !== $row['before']) throw new RuntimeException('Backup corrupt');
        if ($mode === 'install' && digest43($dir . '/payload/' . $file) !== $row['after']) throw new RuntimeException('Payload corrupt');
    }
    if ($mode !== 'verify') foreach ($mode === 'rollback' ? array_reverse($rows, true) : $rows as $file => $row) {
        $target = path43($file);
        if ($mode === 'rollback') {
            if ($row['before'] === null) { if (is_file($target)) unlink($target); }
            else put43($row['backup'], $target);
        } else put43($dir . '/payload/' . $file, $target);
    }
    foreach ($rows as $file => $row) if (digest43(path43($file)) !== $row[$mode === 'rollback' ? 'before' : 'after']) throw new RuntimeException('Final file mismatch');
}
echo 'R43_' . strtoupper($mode) . '_PASS', PHP_EOL;
