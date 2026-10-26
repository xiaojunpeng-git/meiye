<?php
declare(strict_types=1);

/** Exercise the actual reader on local MySQL temporary rows; permanent card data stays read-only. */
if (getenv('M011_LOCAL_TEST') !== '1') throw new RuntimeException('Explicit local-test guard required');
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();
$config = config('database.connections.mysql');
if ($config['hostname'] !== 'mysql' || (int)$config['hostport'] !== 3306) {
    throw new RuntimeException('Only the local Docker MySQL instance is allowed');
}
$connection = think\facade\Db::connect();
$cardTotals = static function () use ($connection): array {
    return $connection->query('SELECT COUNT(*) AS card_count,COALESCE(SUM(write_times),0) AS total_times,
        COALESCE(SUM(write_surplus_times),0) AS surplus_times FROM eb_user_card_holder');
};
$before = $cardTotals();
$created = false;
$started = false;
try {
    // Connection-local shadow table avoids changing any real catalog or member fixture.
    $connection->execute('CREATE TEMPORARY TABLE eb_store_product (
        id BIGINT PRIMARY KEY,pid BIGINT,project_service_duration INT NULL) ENGINE=InnoDB');
    $created = true;
    // MySQL forbids temporary-table DDL inside READ ONLY, but permits its DML.
    // Create the shadow first, then protect every permanent table during execution.
    $connection->query('SET TRANSACTION READ ONLY');
    $connection->startTrans();
    $started = true;
    $connection->execute('INSERT INTO eb_store_product VALUES (8382,0,60),(8404,0,75),(101,8404,0),(102,0,NULL),(103,999,0),(104,0,-1)');
    $method = new ReflectionMethod(app\services\cashier\v3\reservation\CashierV3ReservationModule::class, 'purchasedProjectDuration');
    $method->setAccessible(true);
    $checks = 0;
    foreach ([8382 => 60, 8404 => 75, 101 => 75, 102 => 60, 103 => 60, 104 => 60, 999 => 60] as $id => $minutes) {
        if ($method->invoke(null, $id) !== $minutes) throw new RuntimeException('Duration mismatch for ' . $id);
        ++$checks;
    }
    if ($before !== $cardTotals()) throw new RuntimeException('Permanent card totals changed');
    echo 'M011_DURATION_MYSQL=PASS checks=' . ($checks + 1) . ' permanent_card_data_unchanged=true', PHP_EOL;
} finally {
    if ($started) $connection->rollback();
    if ($created) $connection->execute('DROP TEMPORARY TABLE eb_store_product');
}
