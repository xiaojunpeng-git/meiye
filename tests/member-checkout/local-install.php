<?php
declare(strict_types=1);

// Local schema preparation only. This entry never contacts a payment provider.
require '/var/www/html/vendor/autoload.php';
$app = new think\App('/var/www/html/');
$app->env->load('/var/www/html/.env');
$app->initialize();
if (config('database.connections.mysql.hostname') !== 'mysql') throw new RuntimeException('Docker local database required');
use think\facade\Db;
$sql = file_get_contents('/var/www/html/database/upgrades/2026-10-20-会员统一结账/01-schema.sql');
foreach (explode(';', $sql) as $statement) if (trim($statement) !== '') Db::execute($statement);
$fixed = Db::name('cashier_v3_business_source')->where('is_fixed', 1)->where('parent_id', 0)->where('status', 1)->select()->toArray();
if (count($fixed) !== 1 || (int)$fixed[0]['require_secondary'] !== 0) throw new RuntimeException('Default source requires explicit configuration');
if (!Db::name('member_commerce_settings')->where('id', 1)->find()) {
    Db::name('member_commerce_settings')->insert(['id' => 1, 'purchase_primary_id' => (int)$fixed[0]['id'],
        'recharge_primary_id' => (int)$fixed[0]['id'], 'updated_at' => time()]);
}
echo json_encode(['installed' => true, 'database' => Db::query('SELECT DATABASE() AS db'),
    'defaults' => Db::name('member_commerce_settings')->where('id',1)->find()], JSON_UNESCAPED_UNICODE), PHP_EOL;
