<?php
declare(strict_types=1);

// Synthetic local UI fixture only; never copies or edits an existing member.
$backend = getenv('CASHIER_V3_BACKEND') ?: '/var/www/html';
require $backend . '/vendor/autoload.php';
use think\facade\Db;
$app = new \think\App($backend . '/'); $app->env->load($backend . '/.env'); $app->initialize();
set_exception_handler(static function (Throwable $e): void {
    file_put_contents('php://stderr', $e->getMessage() . "\n"); exit(1);
});
$config = Db::connect()->getConfig();
if (($config['hostname'] ?? '') !== 'mysql' || ($config['database'] ?? '') !== 'ruihao'
    || getenv('R48_LOCAL_ONLY') !== '1') throw new RuntimeException('R48_LOCAL_DATABASE_GUARD');
$name = 'R48-ALLOWANCE-UI';
$store = (int)Db::name('system_store')->where('name', '扬州欧尚店')->value('id');
if ($store <= 0) throw new RuntimeException('UI test store not found');
$result = Db::transaction(static function () use ($name, $store): array {
    $user = (array)Db::name('user')->where('account', $name)->find();
    if ($user) return ['memberId' => (int)$user['uid'], 'name' => $name, 'storeId' => $store, 'existing' => true];
    $now = time();
    $uid = (int)Db::name('user')->insertGetId(['account' => $name, 'nickname' => $name, 'real_name' => $name,
        'phone' => '', 'belong_store_id' => $store, 'status' => 1, 'add_time' => $now]);
    Db::name('store_user')->insert(['uid' => $uid, 'store_id' => $store, 'status' => 1, 'add_time' => $now]);
    $issue = (int)Db::name('store_coupon_issue')->insertGetId(['title' => $name, 'coupon_title' => 'R48多次抵用券',
        'coupon_price' => '1000.00', 'use_min_price' => '0.00', 'coupon_type' => 3, 'type' => 0,
        'use_mode' => 0, 'category' => 1, 'applicable_type' => 1, 'coupon_issue_type' => 0,
        'status' => 1, 'is_verify' => 1, 'is_del' => 0, 'add_time' => $now, 'coupon_time' => 30]);
    $coupon = (int)Db::name('store_coupon_user')->insertGetId(['cid' => $issue, 'uid' => $uid,
        'coupon_title' => 'R48多次抵用券', 'coupon_price' => '1000.00', 'use_min_price' => '0.00',
        'start_time' => $now - 10, 'end_time' => $now + 30 * 86400, 'use_time' => 0, 'status' => 0,
        'is_fail' => 0, 'add_time' => $now, 'type' => 'send']);
    return ['memberId' => $uid, 'name' => $name, 'storeId' => $store, 'couponId' => $coupon];
});
$result['accounts'] = Db::name('coupon_allowance_account')->where('member_id', $result['memberId'])->select()->toArray();
$result['movements'] = Db::name('coupon_allowance_movement')->where('member_id', $result['memberId'])
    ->field('id,delta_cents,before_cents,after_cents,order_id,reversal_of')->select()->toArray();
$result['orders'] = Db::name('cashier_v3_sales_order')->where('member_id', $result['memberId'])
    ->field('order_id,order_no,order_status,sale_amount_cents')->select()->toArray();
echo json_encode($result, JSON_UNESCAPED_UNICODE) . "\n";
