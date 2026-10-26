<?php
/** Fixed-manifest, instance-bound file deployment only. No database writes.
 * Back up every replaced byte before switching; restore only this batch's files.
 */
$directory = $argv[1] ?? ''; $mode = $argv[2] ?? ''; $expected = $argv[3] ?? '';
if (!preg_match('~^/www/backups/(rh|008|010)/r55-member-list-([a-f0-9]{8})$~', $directory, $match)
    || !in_array($mode, ['backup', 'install', 'rollback'], true)
    || !preg_match('/^[a-f0-9]{64}$/D', $expected)
    || hash_file('sha256', $directory . '/payload/manifest.json') !== $expected) throw new RuntimeException('Explicit fixed manifest required');
$manifest = json_decode(file_get_contents($directory . '/payload/manifest.json'), true);
foreach (array_keys($manifest['targets']) as $key) {
    if (!preg_match('~^formal/public/view_cashier_v3/(?:index\.html|favicon\.ico|assets/[A-Za-z0-9_.-]+)$~D', $key)) throw new RuntimeException('R55_STATIC_FORMAL_ONLY');
}
$site = $match[1]; $root = '/www/wwwroot/' . ($site === 'rh' ? 'rh.cc3798.com' : $site . '.cc3798.com');
if ($manifest['site'] !== $site || substr($manifest['commit'], 0, 8) !== $match[2]) throw new RuntimeException('Wrong instance');
function r55Digest($file) { clearstatcache(true, $file); return is_file($file) ? hash_file('sha256', $file) : null; }
function r55Destination($key) {
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
function r55Put($source, $target) {
    if (!is_dir(dirname($target))) mkdir(dirname($target), 0755, true);
    $next = $target . '.r55-next'; if (file_exists($next) || is_link($next)) throw new RuntimeException('Pending file exists');
    if (!copy($source, $next)) throw new RuntimeException('Copy failed');
    chmod($next, 0644); chown($next, is_file($target) ? fileowner($target) : 'www'); chgrp($next, is_file($target) ? filegroup($target) : 'www');
    if (!rename($next, $target)) throw new RuntimeException('Atomic switch failed');
}
$record = $directory . '/prepared.json';
if ($mode === 'backup') {
    if (file_exists($record)) throw new RuntimeException('Batch already prepared');
    $rows = [];
    foreach ($manifest['targets'] as $key => $row) {
        $target = r55Destination($key); $source = $directory . '/payload/' . $key;
        if (r55Digest($target) !== $row['before'] || r55Digest($source) !== $row['after']) throw new RuntimeException('Baseline/payload drift: ' . $key);
        $backup = $directory . '/before/' . hash('sha256', $key);
        if ($row['before'] !== null) {
            if (!is_dir(dirname($backup))) mkdir(dirname($backup), 0700, true);
            if (!copy($target, $backup) || r55Digest($backup) !== $row['before']) throw new RuntimeException('Backup failed');
        }
        $rows[$key] = $row + ['backup' => $backup];
    }
    if (file_put_contents($record, json_encode($rows, JSON_PRETTY_PRINT)) === false) throw new RuntimeException('Backup receipt failed');
    chmod($record, 0600);
} else {
    $rows = json_decode(file_get_contents($record), true);
    if ($rows === null || array_keys($rows) !== array_keys($manifest['targets'])) throw new RuntimeException('Missing batch backup');
    foreach ($rows as $key => $row) {
        if (!in_array(r55Digest(r55Destination($key)), $mode === 'install' ? [$row['before']] : [$row['before'], $row['after']], true)) throw new RuntimeException('Concurrent target change: ' . $key);
        if ($row['before'] !== null && r55Digest($row['backup']) !== $row['before']) throw new RuntimeException('Backup corrupt');
        if ($mode === 'install' && r55Digest($directory . '/payload/' . $key) !== $row['after']) throw new RuntimeException('Payload corrupt');
    }
    foreach ($mode === 'rollback' ? array_reverse($rows, true) : $rows as $key => $row) {
        $target = r55Destination($key);
        if ($mode === 'rollback') {
            if ($row['before'] === null) { if (is_file($target)) unlink($target); }
            else r55Put($row['backup'], $target);
        } else r55Put($directory . '/payload/' . $key, $target);
    }
    foreach ($rows as $key => $row) if (r55Digest(r55Destination($key)) !== $row[$mode === 'rollback' ? 'before' : 'after']) throw new RuntimeException('Final hash mismatch');
}
echo 'R55_FILES_' . strtoupper($mode) . '_PASS', PHP_EOL;
