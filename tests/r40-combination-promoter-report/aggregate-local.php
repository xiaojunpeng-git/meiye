<?php
declare(strict_types=1);

/** 用本地临时表验证跨状态会员去重、全额退款零人数和分桶金额；临时表随连接关闭消失。 */
require '/var/www/html/vendor/autoload.php';
$app = new think\App('/var/www/html/');
$app->env->load('/var/www/html/.env');
$app->initialize();
if (config('database.connections.mysql.hostname') !== 'mysql' || config('database.connections.mysql.database') !== 'ruihao') {
    throw new RuntimeException('Only local mysql/ruihao is allowed');
}
$db = think\facade\Db::connect();
$db->execute('CREATE TEMPORARY TABLE promoter_r40_fact (group_status VARCHAR(20),net_cents INT,member_id INT)');
try {
    foreach ([['formed',10000,11],['formed',5000,11],['pending',3000,12],['failed',2000,11],['failed',0,13]] as $row) {
        $db->execute('INSERT INTO promoter_r40_fact VALUES (?,?,?)', $row);
    }
    $service = new app\services\activity\combination\CombinationPromotionServices();
    $method = new ReflectionMethod($service, 'promoterAggregateSql');
    $method->setAccessible(true);
    $row = $db->query('SELECT ' . $method->invoke($service) . ' FROM promoter_r40_fact')[0];
    foreach (['formed_amount'=>15000,'pending_amount'=>5000,'total_amount'=>20000,'formed_people'=>1,'pending_people'=>2,'total_people'=>2] as $key=>$expected) {
        if ((int)$row[$key] !== $expected) throw new RuntimeException($key . ' mismatch');
    }
    echo "PASS amounts and distinct buyers across statuses\n";
} finally { $db->execute('DROP TEMPORARY TABLE IF EXISTS promoter_r40_fact'); }
