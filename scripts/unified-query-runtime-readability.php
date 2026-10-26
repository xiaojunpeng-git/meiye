<?php
declare(strict_types=1);

/**
 * Read-only release check: run as the HTTP worker account, never as root.
 * A root-only class_exists check can pass while the actual worker cannot
 * traverse a newly deployed directory. No application/DB boot or writes occur.
 */
$options = getopt('', ['root:', 'runtime-user:']);
$root = realpath((string)($options['root'] ?? dirname(__DIR__) . '/后端代码'));
$expected = (string)($options['runtime-user'] ?? 'www');
if (!$root || !function_exists('posix_geteuid') || !function_exists('posix_getpwuid')) {
    file_put_contents('php://stderr', "Source root and POSIX runtime identity are required.\n");
    exit(2);
}
$identity = posix_getpwuid(posix_geteuid());
if (($identity['name'] ?? '') !== $expected || posix_geteuid() === 0) {
    file_put_contents('php://stderr', "Run this probe as the actual non-root HTTP worker account.\n");
    exit(2);
}
require $root . '/vendor/autoload.php';
$config = require $root . '/config/unified_query.php';
$checks = [];
try {
    // Check every configured dependency, not merely the first reported class;
    // do not drop inaccessible registrars or weaken the runtime's fail-closed rule.
    foreach (['registrars', 'permission_resolvers', 'providers', 'worker_context_resolvers'] as $key) {
        foreach ((array)($config[$key] ?? []) as $class) {
            $checks[$class] = class_exists($class);
        }
    }
    $ok = $checks && !in_array(false, $checks, true);
    echo json_encode(['runtimeUser' => $identity['name'], 'ok' => (bool)$ok,
        'classes' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit($ok ? 0 : 1);
} catch (Throwable $error) {
    file_put_contents('php://stderr', 'Runtime class loading failed: ' . $error->getMessage() . "\n");
    exit(1);
}
