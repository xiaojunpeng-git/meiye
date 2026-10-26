<?php
/** R50 allowlisted release. Validate every payload and live pre-image before
 * writes; retain old hashed assets and switch the HTML entry last. No database
 * credentials, customer config, uploads or other application files are copied.
 */
if (PHP_SAPI !== 'cli') exit(1);
$instance = $argv[1] ?? '';
$mode = $argv[2] ?? 'check';
$domains = ['rh' => 'rh.cc3798.com', '008' => '008.cc3798.com'];
if (!isset($domains[$instance]) || !in_array($mode, ['check', 'apply', 'verify'], true)) throw new RuntimeException('Invalid release target');
$root = '/www/wwwroot/' . $domains[$instance];
$manifest = json_decode(file_get_contents(__DIR__ . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$payload = __DIR__ . '/payload';
foreach ($manifest['backend'] as $relative => $spec) {
    if (strpos($relative, 'app/') !== 0 || strpos($relative, '..') !== false) throw new RuntimeException('Unsafe path');
    if (hash_file('sha256', $payload . '/' . $relative) !== $spec['after']) throw new RuntimeException('Bad backend payload');
    $expected = $mode === 'verify' ? $spec['after'] : $spec['before'];
    if (hash_file('sha256', $root . '/' . $relative) !== $expected) throw new RuntimeException('Live backend changed: ' . $relative);
}
$entry = $root . '/public/system.html';
$expectedEntry = $mode === 'verify' ? $manifest['frontend']['system.html'] : $manifest['entry_before'];
if (hash_file('sha256', $entry) !== $expectedEntry) throw new RuntimeException('Live platform entry changed');
foreach ($manifest['frontend'] as $relative => $sha) {
    if (($relative !== 'system.html' && strpos($relative, 'view_admin/') !== 0) || strpos($relative, '..') !== false) throw new RuntimeException('Unsafe asset path');
    if (hash_file('sha256', __DIR__ . '/platform/' . $relative) !== $sha) throw new RuntimeException('Bad asset payload');
    $live = $root . '/public/' . $relative;
    // Existing content-addressed resources are immutable. A collision must be
    // investigated, not overwritten while an older page still references it.
    if ($relative !== 'system.html' && is_file($live) && hash_file('sha256', $live) !== $sha) throw new RuntimeException('Asset collision: ' . $relative);
    if ($mode === 'verify' && (!is_file($live) || hash_file('sha256', $live) !== $sha)) throw new RuntimeException('Missing deployed resource');
}
$copyAtomic = static function (string $source, string $target): void {
    if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0755, true)) throw new RuntimeException('Create directory failed');
    $temporary = tempnam(dirname($target), '.r50-');
    if (!copy($source, $temporary) || !chmod($temporary, 0644) || !rename($temporary, $target)) throw new RuntimeException('Atomic copy failed');
};
if ($mode === 'apply') {
    // New code requires the schema first. Migration runner independently checks
    // exact database identity and the two migration fingerprints.
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/r50-instance-migration.php') . ' ' . escapeshellarg($instance) . ' verify', $result);
    if ($result !== 0) throw new RuntimeException('Required migration unavailable');
    foreach ($manifest['frontend'] as $relative => $sha) {
        if ($relative !== 'system.html' && !is_file($root . '/public/' . $relative)) $copyAtomic(__DIR__ . '/platform/' . $relative, $root . '/public/' . $relative);
    }
    foreach ($manifest['backend'] as $relative => $spec) $copyAtomic($payload . '/' . $relative, $root . '/' . $relative);
    $copyAtomic(__DIR__ . '/platform/system.html', $entry);
}
echo json_encode(['instance' => $instance, 'mode' => $mode, 'release' => $manifest['release'], 'backend_count' => count($manifest['backend']), 'asset_count' => count($manifest['frontend']), 'entry_sha256' => hash_file('sha256', $entry)], JSON_UNESCAPED_UNICODE) . PHP_EOL;
