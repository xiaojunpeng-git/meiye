<?php
/** Fixed-manifest, instance-bound file deployment only. No database writes.
 * Back up every replaced byte before switching; restore only this batch's files.
 */
$directory = $argv[1] ?? ''; $mode = $argv[2] ?? ''; $expected = $argv[3] ?? '';
if (!preg_match('~^/www/backups/(rh|008|010)/r54-service-period-([a-f0-9]{8})$~', $directory, $match)
    || !in_array($mode, ['backup', 'install', 'rollback'], true)
    || !preg_match('/^[a-f0-9]{64}$/D', $expected)
    || hash_file('sha256', $directory . '/payload/manifest.json') !== $expected) throw new RuntimeException('Explicit fixed manifest required');
$manifest = json_decode(file_get_contents($directory . '/payload/manifest.json'), true);
$site = $match[1]; $root = '/www/wwwroot/' . ($site === 'rh' ? 'rh.cc3798.com' : $site . '.cc3798.com');
if ($manifest['site'] !== $site || substr($manifest['commit'], 0, 8) !== $match[2]) throw new RuntimeException('Wrong instance');
function r54Digest($file) { clearstatcache(true, $file); return is_file($file) ? hash_file('sha256', $file) : null; }
function r54Destination($key) {
    global $root, $site, $manifest;
    if (strpos($key, '..') !== false || strpos($key, "\0") !== false) throw new RuntimeException('Unsafe path');
    [$kind, $relative] = explode('/', $key, 2); $base = $root;
    if ($kind === 'preview') {
        if ($site !== 'rh' || !$manifest['previewAuthorized']) throw new RuntimeException('Preview unauthorized');
        $base .= '-8080';
    } elseif ($kind !== 'formal') throw new RuntimeException('Unknown target root');
    $allowed = ['app/services/cashier/v3/member/CashierV3MemberDetailQueryServices.php',
        'app/services/cashier/v3/card/CashierV3CardReadAmountServices.php',
        'app/services/cashier/v3/card/CashierV3CardComponentPurchaseAmountAuthority.php'];
    if (!($relative === 'public/view_cashier_v3/index.html' || preg_match('~^public/view_cashier_v3/(?:assets/[A-Za-z0-9_.-]+|favicon\.ico)$~D', $relative)
        || ($kind === 'formal' && in_array($relative, $allowed, true)))) throw new RuntimeException('Target outside scope');
    $target = $base . '/' . $relative;
    // Preserve an existing same-instance integration entry link, never replace
    // a symlink or follow it into another customer/terminal.
    if (is_link($target)) {
        $real = realpath($target);
        if ($site !== 'rh' || $kind !== 'preview' || $relative !== 'public/view_cashier_v3/index.html'
            || !$real || strpos($real, $base . '/public/.local-8080-integration-releases/') !== 0) throw new RuntimeException('Unsafe target link');
        $target = $real;
    }
    $parent = dirname($target); while (!file_exists($parent)) $parent = dirname($parent);
    $real = realpath($parent);
    if (!$real || ($real !== $base && strpos($real, $base . '/') !== 0)) throw new RuntimeException('Target escapes instance');
    return $target;
}
function r54Put($source, $target) {
    if (!is_dir(dirname($target))) mkdir(dirname($target), 0755, true);
    $next = $target . '.r54-next'; if (file_exists($next) || is_link($next)) throw new RuntimeException('Pending file exists');
    if (!copy($source, $next)) throw new RuntimeException('Copy failed');
    chmod($next, 0644); chown($next, is_file($target) ? fileowner($target) : 'www'); chgrp($next, is_file($target) ? filegroup($target) : 'www');
    if (!rename($next, $target)) throw new RuntimeException('Atomic switch failed');
}
$record = $directory . '/prepared.json';
if ($mode === 'backup') {
    if (file_exists($record)) throw new RuntimeException('Batch already prepared');
    $rows = [];
    foreach ($manifest['targets'] as $key => $row) {
        $target = r54Destination($key); $source = $directory . '/payload/' . $key;
        if (r54Digest($target) !== $row['before'] || r54Digest($source) !== $row['after']) throw new RuntimeException('Baseline/payload drift: ' . $key);
        $backup = $directory . '/before/' . hash('sha256', $key);
        if ($row['before'] !== null) {
            if (!is_dir(dirname($backup))) mkdir(dirname($backup), 0700, true);
            if (!copy($target, $backup) || r54Digest($backup) !== $row['before']) throw new RuntimeException('Backup failed');
        }
        $rows[$key] = $row + ['backup' => $backup];
    }
    if (file_put_contents($record, json_encode($rows, JSON_PRETTY_PRINT)) === false) throw new RuntimeException('Backup receipt failed');
    chmod($record, 0600);
} else {
    $rows = json_decode(file_get_contents($record), true);
    if ($rows === null || array_keys($rows) !== array_keys($manifest['targets'])) throw new RuntimeException('Missing batch backup');
    foreach ($rows as $key => $row) {
        if (!in_array(r54Digest(r54Destination($key)), $mode === 'install' ? [$row['before']] : [$row['before'], $row['after']], true)) throw new RuntimeException('Concurrent target change: ' . $key);
        if ($row['before'] !== null && r54Digest($row['backup']) !== $row['before']) throw new RuntimeException('Backup corrupt');
        if ($mode === 'install' && r54Digest($directory . '/payload/' . $key) !== $row['after']) throw new RuntimeException('Payload corrupt');
    }
    foreach ($mode === 'rollback' ? array_reverse($rows, true) : $rows as $key => $row) {
        $target = r54Destination($key);
        if ($mode === 'rollback') {
            if ($row['before'] === null) { if (is_file($target)) unlink($target); }
            else r54Put($row['backup'], $target);
        } else r54Put($directory . '/payload/' . $key, $target);
    }
    foreach ($rows as $key => $row) if (r54Digest(r54Destination($key)) !== $row[$mode === 'rollback' ? 'before' : 'after']) throw new RuntimeException('Final hash mismatch');
}
echo 'R54_FILES_' . strtoupper($mode) . '_PASS', PHP_EOL;
