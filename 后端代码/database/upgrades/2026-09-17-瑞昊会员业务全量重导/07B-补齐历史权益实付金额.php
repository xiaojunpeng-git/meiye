<?php
declare(strict_types=1);

/** Makes imported JSJ historical card value available to the cashier entitlement amount calculation. */
$arguments = array_fill_keys(array_slice($argv, 1), true);
if (!isset($arguments['--execute'], $arguments['--allow-ruihao'], $arguments['--after-import'])) {
    fwrite(STDERR, "Refusing to write without --execute --allow-ruihao --after-import\n");
    exit(2);
}
$env = parse_ini_file(getenv('RH_ENV_FILE') ?: '/www/wwwroot/rh.cc3798.com/.env', true, INI_SCANNER_RAW);
$database = $env['DATABASE'] ?? [];
$target = getenv('RH_TARGET_DATABASE') ?: ($database['DATABASE'] ?? '');
if ($target !== 'ruihao') throw new RuntimeException("target guard failed: $target");
$pdo = new PDO('mysql:host=' . $database['HOSTNAME'] . ';port=' . $database['HOSTPORT'] . ';dbname=ruihao;charset=utf8mb4', $database['USERNAME'], $database['PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$before = $pdo->query("SELECT COUNT(*) AS rows,SUM(total_price) AS total_value,SUM(pay_price) AS paid_value FROM eb_store_order_cart_info WHERE cart_id LIKE 'RH-LINE-%'")->fetch(PDO::FETCH_ASSOC);
if ((int)$before['rows'] === 0) throw new RuntimeException('no imported JSJ entitlement lines found');
$pdo->beginTransaction();
try {
    $updatedLines = $pdo->exec("UPDATE eb_store_order_cart_info SET pay_price=total_price WHERE cart_id LIKE 'RH-LINE-%' AND pay_price<>total_price");
    $updatedOrders = $pdo->exec("UPDATE eb_store_order o JOIN (SELECT oid,SUM(total_price) amount FROM eb_store_order_cart_info WHERE cart_id LIKE 'RH-LINE-%' GROUP BY oid) l ON l.oid=o.id SET o.total_price=l.amount,o.pay_price=l.amount WHERE o.order_id LIKE 'RH-RELOAD-102-CARD-%'");
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $exception;
}
$after = $pdo->query("SELECT COUNT(*) AS rows,SUM(total_price) AS total_value,SUM(pay_price) AS paid_value,SUM(pay_price=0 AND total_price>0) AS unresolved FROM eb_store_order_cart_info WHERE cart_id LIKE 'RH-LINE-%'")->fetch(PDO::FETCH_ASSOC);
if ((int)$after['unresolved'] !== 0 || (string)$after['total_value'] !== (string)$after['paid_value']) throw new RuntimeException('historical entitlement payment repair verification failed');
echo json_encode(['updated_lines'=>$updatedLines,'updated_orders'=>$updatedOrders,'before'=>$before,'after'=>$after], JSON_UNESCAPED_UNICODE), PHP_EOL;
