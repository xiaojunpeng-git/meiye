<?php
declare(strict_types=1);

// Read-only local discovery. Never print credentials, tokens, or member passwords.
require '/var/www/html/vendor/autoload.php';
$app = new think\App('/var/www/html/');
$app->env->load('/var/www/html/.env');
$app->initialize();
$connection = config('database.connections.mysql');
if (!in_array($connection['hostname'], ['mysql', 'mohe-mysql', '127.0.0.1', 'localhost'], true)) {
    throw new RuntimeException('Local database required');
}
use think\facade\Db;
$member = Db::name('user')->where('phone', '15959380751')
    ->field('uid,nickname,real_name,belong_store_id,now_money,ben_money,give_money,balance_version,status')->find();
$staff = Db::name('system_store_staff')->where('account', '4796')
    ->field('id,store_id,staff_name,employee_id,status')->find();
$mode = $argv[1] ?? 'baseline';
if ($mode === 'schema') {
    $out = [];
    foreach (['store_order','store_order_cart_info','user_card_holder','cashier_v3_sales_order',
        'cashier_v3_checkout_request','cashier_v3_command_receipt','user_recharge','store_product_attr_value'] as $table) {
        $out[$table] = array_values(array_filter(Db::query('SHOW COLUMNS FROM `eb_' . $table . '`'),
            static function (array $column): bool {
                return $column['Default'] === null && $column['Null'] === 'NO' && $column['Extra'] !== 'auto_increment';
            }));
    }
} else {
    $storeId = (int)($staff['store_id'] ?? 0);
    $out = ['database' => Db::query('SELECT DATABASE() AS db, @@hostname AS host, VERSION() AS version'),
        'member' => $member, 'staff' => $staff,
        'store' => Db::name('system_store')->where('id', $storeId)->field('id,name,is_show,is_del')->find(),
        'sources' => Db::name('cashier_v3_business_source')->select()->toArray()];
}
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
