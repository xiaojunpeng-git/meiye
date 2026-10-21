<?php
declare(strict_types=1);
/** Per-instance read-only baseline. Reads configuration locally without exposing credentials. */
$instance = $argv[1] ?? '';
$sites = ['rh' => ['rh.cc3798.com', 'ruihao'], '008' => ['008.cc3798.com', 'mohe_008']];
if (!isset($sites[$instance])) throw new InvalidArgumentException('Explicit instance required');
[$domain, $database] = $sites[$instance];
$root = '/www/wwwroot/' . $domain;
require $root . '/vendor/autoload.php';
(new think\App($root . '/'))->initialize();
$c = config('database.connections.mysql');
if ($c['database'] !== $database) throw new LogicException('Wrong database');
$out = ['instance' => $instance, 'database' => $database, 'files' => [], 'fronts' => []];
$files = [
    'app/services/cashier/v3/reservation/CashierV3ReservationLifecycleServices.php',
    'app/services/cashier/v3/reservation/CashierV3ReservationCompletionFactServices.php',
    'app/services/cashier/v3/cashier/CashierV3EntitlementProjectionServices.php',
    'app/services/cashier/v3/cashier/CashierV3SharedCardPurchaseAmount.php',
    'app/services/cashier/v3/card/CashierV3CardRuleEntitlementAuthorityServices.php',
    'app/services/cashier/v3/card/CashierV3CardSaleActualAmountServices.php',
    'app/services/order/store/WriteOffOrderServices.php',
    'app/services/cashier/v3/order/CashierV3ServiceRecordCraftsmanAdjustmentServices.php',
    'app/services/cashier/v3/order/CashierV3OrderCenterRecordQueryServices.php',
    'app/services/cashier/v3/order/CashierV3SalesOrderQueryServices.php',
];
foreach ($files as $file) $out['files'][$file] = hash_file('sha256', $root . '/' . $file);
$fronts = [$root . '/public/view_cashier_v3'];
if ($instance === 'rh') $fronts[] = '/www/wwwroot/rh.cc3798.com-8080/public/view_cashier_v3';
foreach ($fronts as $front) {
    $html = file_get_contents($front . '/index.html');
    preg_match_all('/(?:src|href)="([^" ]+\.(?:js|css))"/', $html, $matches);
    $entry = ['path' => $front, 'index' => hash('sha256', $html), 'assets' => []];
    foreach ($matches[1] as $ref) {
        $file = 'assets/' . basename($ref);
        $entry['assets'][$file] = hash_file('sha256', $front . '/' . $file);
    }
    $out['fronts'][] = $entry;
}
$pdo = new PDO('mysql:host=' . $c['hostname'] . ';port=' . $c['hostport'] . ';dbname=' . $database,
    $c['username'], $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('SET TRANSACTION READ ONLY');
$pdo->beginTransaction();
try {
    $out['migration'] = $pdo->query('SELECT upgrade_key,code_version,executed_at FROM eb_database_upgrade_log ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
} finally { $pdo->rollBack(); }
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
