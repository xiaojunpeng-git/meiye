<?php
declare(strict_types=1);
// Narrow repair of the retained development-test holder, not customer data.
require '/var/www/html/vendor/autoload.php';
$app = new think\App('/var/www/html/');
$app->env->load('/var/www/html/.env');
$app->initialize();
if (config('database.connections.mysql.hostname') !== 'mysql') throw new RuntimeException('Local only');
use think\facade\Db;
Db::transaction(static function (): void {
    $holder = Db::name('user_card_holder')->where('id',2076883)->where('uid',913081)->where('oid',2327156)->lock(true)->find();
    if (!$holder) throw new RuntimeException('Test holder changed');
    if (preg_match('/^\d{7}$/D',$holder['card_no'])) { echo 'Already valid',PHP_EOL; return; }
    if ($holder['card_no'] !== 'SJ-P-44') throw new RuntimeException('Unexpected test card number');
    (new \app\services\user\CardNumberServices())->withAllocateRetry(static function (string $number) use ($holder): int {
        return (int)Db::name('user_card_holder')->where('id',$holder['id'])->where('card_no','SJ-P-44')->update(['card_no'=>$number]);
    });
    echo 'Retained test holder card number repaired; IDs/rights/price unchanged',PHP_EOL;
});
