<?php
declare(strict_types=1);

/* Legacy long cards may end after 2038; PHP and the V3 rule state support it,
 * but the two legacy projection columns must also be 64-bit. */
$execute = in_array('--execute', $argv, true);
$allowRuihao = in_array('--allow-ruihao', $argv, true);
$envPath = getenv('MOHE_TIMECARD_ENV_PATH') ?: '/var/www/html/.env';
$env = parse_ini_file($envPath, true);
if (!is_array($env) || empty($env['DATABASE'])) throw new RuntimeException('database env unavailable');
$db = $env['DATABASE'];
if ((string)($db['DATABASE'] ?? '') !== 'ruihao' || !$allowRuihao) throw new RuntimeException('refuse: ruihao requires --allow-ruihao');
$pdo = new PDO('mysql:host='.$db['HOSTNAME'].';port='.$db['HOSTPORT'].';dbname=ruihao;charset=utf8mb4', $db['USERNAME'], $db['PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$columns = $pdo->query("SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME='write_end' AND TABLE_NAME IN ('eb_user_card_holder','eb_store_order_cart_info') ORDER BY TABLE_NAME")->fetchAll();
$needs = array_values(array_filter($columns, static fn(array $column): bool => stripos((string)$column['COLUMN_TYPE'], 'bigint') === false));
if (!$execute) { echo json_encode(['status'=>'precheck','database'=>'ruihao','columns'=>$columns,'needs_upgrade'=>array_column($needs,'TABLE_NAME')], JSON_UNESCAPED_UNICODE).PHP_EOL; exit; }
if ((int)$pdo->query("SELECT GET_LOCK('rh_time_card_timestamp_compat_v1',30)")->fetchColumn() !== 1) throw new RuntimeException('timestamp compatibility lock unavailable');
try {
    foreach ($needs as $column) {
        $table = (string)$column['TABLE_NAME'];
        $pdo->exec("ALTER TABLE `{$table}` MODIFY COLUMN `write_end` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0");
    }
    $after = $pdo->query("SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME='write_end' AND TABLE_NAME IN ('eb_user_card_holder','eb_store_order_cart_info') ORDER BY TABLE_NAME")->fetchAll();
    if (count($after) !== 2 || count(array_filter($after, static fn(array $column): bool => stripos((string)$column['COLUMN_TYPE'], 'bigint') === false)) > 0) throw new RuntimeException('timestamp compatibility verification failed');
    echo json_encode(['status'=>'ok','database'=>'ruihao','upgraded_tables'=>array_column($needs,'TABLE_NAME'),'columns'=>$after], JSON_UNESCAPED_UNICODE).PHP_EOL;
} finally {
    $pdo->query("SELECT RELEASE_LOCK('rh_time_card_timestamp_compat_v1')");
}
