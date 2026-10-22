<?php
declare(strict_types=1);

/** Fixed eight-file release only; no schema, customer data or configuration writes. */
$base = dirname(__DIR__);
$manifest = json_decode(file_get_contents($base . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$mode = $argv[1] ?? '';
if (!in_array($mode, ['prepare', 'install', 'verify', 'rollback', 'probe'], true)) throw new RuntimeException('Explicit mode required');
$root = $manifest['root'];
if (!in_array($root, ['/www/wwwroot/rh.cc3798.com', '/www/wwwroot/008.cc3798.com'], true)) throw new RuntimeException('Unknown instance');
$hash = static function (string $path): ?string { return is_file($path) ? hash_file('sha256', $path) : null; };
$assert = static function (string $path, ?string $sha) use ($hash): void {
    if ($hash($path) !== $sha) throw new RuntimeException('Fingerprint changed: ' . $path);
};
$copy = static function (string $source, string $dest): void {
    $tmp = $dest . '.m010-' . getmypid();
    if (!copy($source, $tmp)) throw new RuntimeException('Copy failed');
    $owner = is_file($dest) ? $dest : dirname($dest);
    chmod($tmp, 0644); chown($tmp, fileowner($owner)); chgrp($tmp, filegroup($owner));
    if (!rename($tmp, $dest)) throw new RuntimeException('Atomic replacement failed');
};
if ($mode === 'probe') {
    // Use the application's own connection, but enforce read-only transactions.
    chdir($root);
    require $root . '/vendor/autoload.php';
    (new think\App($root . '/'))->initialize();
    $db = config('database.connections.mysql');
    if ($db['database'] !== $manifest['database']) throw new RuntimeException('Database identity mismatch');
    think\facade\Db::execute('SET SESSION TRANSACTION READ ONLY');
    think\facade\Db::startTrans();
    try {
        (new app\services\cashier\v3\cashier\CashierV3CashierReadinessGuard())->assertSaleCatalogReady();
        $method = new ReflectionMethod(app\services\cashier\v3\cashier\CashierV3EntitlementResourceVersionProvider::class, 'synchronizeProjectionVersion');
        $parameters = array_map(static function ($p) { return $p->getName(); }, $method->getParameters());
        if (!in_array('allowDisabledBenefitPool', $parameters, true)) throw new RuntimeException('Delayed activation contract missing');
        $stores = think\facade\Db::name('system_store')->column('id');
        $checked = 0;
        if (class_exists(app\services\cashier\v3\card\CashierV3CustomCardTemplateServices::class)) {
            $templates = new app\services\cashier\v3\card\CashierV3CustomCardTemplateServices();
            foreach ($stores as $store) { $templates->find((int)$store); $checked++; }
        }
        $upgrade = think\facade\Db::name('database_upgrade_log')->order('id', 'desc')->field('upgrade_key,code_version,executed_at')->find();
        echo json_encode(['result' => 'PASS', 'database' => $db['database'], 'readOnly' => true,
            'schemaReady' => true, 'delayedActivationContract' => true, 'templateLookups' => $checked,
            'latestUpgrade' => $upgrade], JSON_THROW_ON_ERROR), PHP_EOL;
    } finally { think\facade\Db::rollback(); }
    exit;
}
if (count($manifest['after']) !== 8) throw new RuntimeException('Eight-file boundary required');
foreach ($manifest['protected'] as $path => $sha) $assert($path, $sha);
if ($mode === 'prepare') {
    if (file_exists($base . '/prepared.json')) throw new RuntimeException('Backup already exists');
    foreach ($manifest['after'] as $path => $sha) {
        $assert($root . '/' . $path, $manifest['before'][$path]);
        $assert($base . '/payload/' . $path, $sha);
        exec('/www/server/php/74/bin/php -l ' . escapeshellarg($base . '/payload/' . $path), $output, $code);
        if ($code !== 0) throw new RuntimeException('PHP 7.4 lint failed');
        if ($manifest['before'][$path] !== null) {
            $dest = $base . '/before/' . $path;
            if (!is_dir(dirname($dest))) mkdir(dirname($dest), 0700, true);
            if (!copy($root . '/' . $path, $dest)) throw new RuntimeException('Backup failed');
            $assert($dest, $manifest['before'][$path]);
        }
    }
    file_put_contents($base . '/prepared.json', json_encode($manifest, JSON_THROW_ON_ERROR));
} else {
    if (json_decode(file_get_contents($base . '/prepared.json'), true) !== $manifest) throw new RuntimeException('Prepared manifest changed');
    if ($mode === 'install') {
        // Check all inputs before the first write. The orchestrator stops only this site's worker.
        foreach ($manifest['after'] as $path => $sha) {
            $assert($root . '/' . $path, $manifest['before'][$path]);
            $assert($base . '/payload/' . $path, $sha);
        }
        foreach ($manifest['after'] as $path => $sha) $copy($base . '/payload/' . $path, $root . '/' . $path);
    } elseif ($mode === 'rollback') {
        // Never roll back a concurrent publisher's changes.
        foreach ($manifest['after'] as $path => $sha) {
            $actual = $hash($root . '/' . $path);
            if ($actual !== $sha && $actual !== $manifest['before'][$path]) throw new RuntimeException('Concurrent deployment');
            if ($manifest['before'][$path] !== null) $assert($base . '/before/' . $path, $manifest['before'][$path]);
        }
        foreach ($manifest['after'] as $path => $sha) {
            if ($manifest['before'][$path] !== null) $copy($base . '/before/' . $path, $root . '/' . $path);
            elseif (is_file($root . '/' . $path) && !unlink($root . '/' . $path)) throw new RuntimeException('Cannot restore absent file');
        }
    }
    foreach ($manifest['after'] as $path => $sha) $assert($root . '/' . $path, $mode === 'rollback' ? $manifest['before'][$path] : $sha);
}
foreach ($manifest['protected'] as $path => $sha) $assert($path, $sha);
echo json_encode(['result' => 'PASS', 'mode' => $mode, 'commit' => $manifest['commit'],
    'files' => 8, 'protectedFiles' => count($manifest['protected']), 'backup' => $base], JSON_THROW_ON_ERROR), PHP_EOL;
