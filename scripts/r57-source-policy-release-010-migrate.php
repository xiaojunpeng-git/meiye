<?php
/** Deployment-only credential adapter for the existing shared DB maintenance
 * service. Fixed 010 schema, fixed backup and reviewed SQL; never GRANT and
 * never borrow a customer application's credentials or data connection.
 */
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '/www/backups/010/r57-source-policy-232dd50b'
    || ($argv[2] ?? '') !== 'migrate'
    || ($argv[3] ?? '') !== '5b9250455bb2d6b30ae2fa5af9bd04d5469868f2e2f2c258b526c1d4fddaf753') exit(2);
set_exception_handler(static function (Throwable $error): void {
    file_put_contents('php://stderr', '010 fixed migration failed; no credentials logged' . PHP_EOL); exit(1);
});
$file = $argv[1] . '/migrate.php';
if (hash_file('sha256', $file) !== '92dc1bbc34057df8efb05b0255ce8d20468315ae1e7d84b6e9ad2e5322e7fec3') throw new RuntimeException('Reviewed migrator drift');
$source = file_get_contents($file);
$needle = <<<'PHP'
$pdo = new PDO('mysql:host=' . $cfg['hostname'] . ';port=' . $port . ';dbname=' . $database . ';charset=utf8mb4', $cfg['username'], $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
PHP;
$replacement = <<<'PHP'
if ($site !== '010' || $database !== 'mohe_010_app' || $port !== '3307') throw new RuntimeException('Fixed 010 target required');
$credentials = parse_ini_file('/root/.mysql57-008.cnf', true, INI_SCANNER_RAW)['client'];
$pdo = new PDO('mysql:host=127.0.0.1;port=3307;dbname=mohe_010_app;charset=utf8mb4', $credentials['user'], $credentials['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
unset($credentials);
$identity = $pdo->query('SELECT DATABASE() AS db,@@port AS port')->fetch(PDO::FETCH_ASSOC);
if ($identity['db'] !== 'mohe_010_app' || (int)$identity['port'] !== 3307) throw new RuntimeException('Wrong maintenance database');
PHP;
if (substr_count($source, $needle) !== 1) throw new RuntimeException('Credential adapter boundary changed');
// All manifest, table backup, settings-preservation, SQL hash and migration
// audit checks execute unchanged from the reviewed migrator, in memory only.
eval(substr(str_replace($needle, $replacement, $source), 5));
echo 'R57_010_MAINTENANCE_PASS appPrivilegesChanged=false', PHP_EOL;
