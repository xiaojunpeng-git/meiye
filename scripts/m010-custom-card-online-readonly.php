<?php
declare(strict_types=1);

/** Real catalog reads only: no checkout, synthetic production rows, or customer identifiers in output. */
$root = $argv[1] ?? '';
$database = $argv[2] ?? '';
if (!in_array($root, ['/www/wwwroot/rh.cc3798.com', '/www/wwwroot/008.cc3798.com'], true)) throw new RuntimeException('Explicit root required');
chdir($root);
require $root . '/vendor/autoload.php';
(new think\App($root . '/'))->initialize();
if (config('database.connections.mysql.database') !== $database) throw new RuntimeException('Wrong database');
think\facade\Db::execute('SET SESSION TRANSACTION READ ONLY');
think\facade\Db::startTrans();
$results = [];
try {
    $stores = think\facade\Db::name('store_product')->where('type', 1)->where('is_del', 0)
        ->where('relation_id', '>', 0)->group('relation_id')->limit(3)->column('relation_id');
    foreach ($stores as $store) {
        $operator = new app\services\cashier\v3\CashierV3OperatorScope((int)$store, 1, '0', '0');
        // A deployment diagnostic has explicit root authorization, not a browser login or write capability.
        $scope = new app\services\cashier\v3\CashierV3DataScopeContext(1, 0, (int)$store, '0', '0',
            [(int)$store], 'all', [], true, 'M010 authorized read-only deployment check', 'm010', [], [], true);
        try {
            $catalog = (new app\services\cashier\v3\cashier\CashierV3SaleCatalogServices())->catalog($operator, $scope);
            $results[] = ['result' => 'PASS', 'keys' => array_keys($catalog), 'fingerprint' => hash('sha256', json_encode($catalog))];
        } catch (app\services\cashier\v3\CashierV3CommandException $e) {
            $detail = $e->getDetail();
            $results[] = ['result' => 'BUSINESS_GUARD', 'reason' => $detail['reason'] ?? get_class($e)];
        }
    }
    echo json_encode(['database' => $database, 'readOnly' => true, 'catalogs' => $results], JSON_THROW_ON_ERROR), PHP_EOL;
} finally { think\facade\Db::rollback(); }
