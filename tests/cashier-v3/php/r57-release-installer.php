<?php
/** Isolated /www tmpfs fixture: no customer server, credentials or DB. */
$installer = '/installer.php'; $checks = 0;
function check57($value, $message) { global $checks; if (!$value) throw new RuntimeException($message); $checks++; }
function fixture57($site, $commit) {
    $root = '/www/wwwroot/' . ($site === 'rh' ? 'rh.cc3798.com' : $site . '.cc3798.com');
    $dir = '/www/backups/' . $site . '/r57-source-policy-' . substr($commit, 0, 8);
    @mkdir($root . '/public/view_cashier_v3', 0755, true); @mkdir($dir . '/payload/formal/public/view_cashier_v3', 0755, true);
    file_put_contents($root . '/public/view_cashier_v3/index.html', 'before');
    file_put_contents($root . '/public/unrelated.txt', 'keep');
    file_put_contents($dir . '/payload/formal/public/view_cashier_v3/index.html', 'after');
    $manifest = ['site' => $site, 'commit' => $commit, 'previewAuthorized' => false, 'backendAllowlist' => [], 'migrationSHA256' => str_repeat('a', 64),
        'targets' => ['formal/public/view_cashier_v3/index.html' => ['before' => hash('sha256', 'before'), 'after' => hash('sha256', 'after')]]];
    file_put_contents($dir . '/payload/manifest.json', json_encode($manifest));
    return [$root, $dir, $manifest];
}
function run57($dir, $mode, $expected = null) {
    global $installer;
    $proc = proc_open(['php', $installer, $dir, $mode, $expected ?? hash_file('sha256', $dir . '/payload/manifest.json')], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]); $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($proc), $out . $err];
}
foreach (['rh', '008', '010'] as $site) {
    [$root, $dir, $m] = fixture57($site, str_repeat('1', 40));
    check57(run57($dir, 'backup')[0] === 0, $site . ' backup');
    check57(run57($dir, 'install')[0] !== 0 && file_get_contents($root . '/public/view_cashier_v3/index.html') === 'before', 'Migration gate before writes');
    file_put_contents($dir . '/migration.json', json_encode(['site' => $site, 'commit' => $m['commit'], 'sqlSHA256' => $m['migrationSHA256']]));
    check57(run57($dir, 'install')[0] === 0, $site . ' install');
    check57(run57($dir, 'verify')[0] === 0, $site . ' verify');
    check57(file_get_contents($root . '/public/unrelated.txt') === 'keep', 'Unrelated bytes preserved');
    check57(run57($dir, 'rollback')[0] === 0 && file_get_contents($root . '/public/view_cashier_v3/index.html') === 'before', $site . ' rollback');
}
[$root, $dir, $m] = fixture57('rh', str_repeat('2', 40));
check57(run57($dir, 'backup', str_repeat('f', 64))[0] !== 0, 'Manifest tampering blocked');
$m['site'] = '008'; file_put_contents($dir . '/payload/manifest.json', json_encode($m));
check57(run57($dir, 'backup')[0] !== 0, 'Cross-instance manifest blocked');
foreach (['preview/public/view_cashier_v3/index.html', 'formal/../.env', 'formal/.env', 'formal/public/other/index.html'] as $i => $key) {
    [$root, $dir, $m] = fixture57('rh', str_repeat((string)($i + 3), 40));
    $m['backendAllowlist'] = ['.env']; $m['targets'] = [$key => ['before' => null, 'after' => hash('sha256', 'x')]];
    file_put_contents($dir . '/payload/manifest.json', json_encode($m));
    check57(run57($dir, 'backup')[0] !== 0, 'Forbidden target ' . $key);
}
[$root, $dir, $m] = fixture57('rh', str_repeat('7', 40));
check57(run57($dir, 'backup')[0] === 0, 'Corruption fixture backup');
file_put_contents($dir . '/before/' . hash('sha256', 'formal/public/view_cashier_v3/index.html'), 'corrupt');
check57(run57($dir, 'rollback')[0] !== 0, 'Corrupt backup blocked');
echo 'R57_INSTALLER_TEST_PASS checks=' . $checks, PHP_EOL;
