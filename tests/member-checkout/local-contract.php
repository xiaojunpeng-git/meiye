<?php
declare(strict_types=1);

// Real local domain execution with rollback; final UI acceptance uses separate retained orders.
require '/var/www/html/vendor/autoload.php';
$app = new think\App('/var/www/html/');
$app->env->load('/var/www/html/.env');
$app->initialize();
if (config('database.connections.mysql.hostname') !== 'mysql') throw new RuntimeException('Local only');
use think\facade\Db;
use app\services\member\MemberCommerceServices;
$uid = 913081;
$productQuery = Db::name('store_product')->alias('p')->where('relation_id',118)->where('type',1)->where('is_show',1)
    ->whereIn('id', [625196,625093])
    ->where('is_verify',1)->where('is_del',0)->whereIn('product_type',[5,6])->where('spec_type',0)
    ->field('id,store_name,product_type')->order('id desc');
$products = \app\services\product\product\StoreProductSaleUsagePolicy::excludeHistoricalCarriers($productQuery, 'p')->select()->toArray();
$seen = [];
foreach ($products as $product) {
    $type = (int)$product['product_type'];
    if (isset($seen[$type])) continue;
    Db::startTrans();
    try {
        $service = new MemberCommerceServices();
        $input = ['store_id'=>118, 'items'=>[['product_id'=>(int)$product['id'],'quantity'=>1]],
            'salespeople'=>[], 'payment_mode'=>'balance', 'idempotency_key'=>'local-contract-' . bin2hex(random_bytes(10))];
        $quote = $service->quote($uid,$input);
        if ($quote['amountCents'] <= 0) continue;
        $input['fingerprint']=$quote['fingerprint'];
        $result = $service->create($uid,$input,'h5');
        echo json_encode(['type'=>$type,'product'=>$product,'amountCents'=>$quote['amountCents'],'result'=>$result],JSON_UNESCAPED_UNICODE),PHP_EOL;
        $seen[$type]=true;
    } catch (Throwable $error) {
        echo json_encode(['type'=>$type,'product'=>$product,'error'=>get_class($error), 'message'=>$error->getMessage(),
            'detail'=>method_exists($error,'getDetail') ? $error->getDetail() : [],
            'file'=>$error->getFile(),'line'=>$error->getLine(),'trace'=>array_map(static function ($frame) {
                unset($frame['args']); return $frame;
            }, array_slice($error->getTrace(),0,6))],JSON_UNESCAPED_UNICODE),PHP_EOL;
        break;
    } finally { Db::rollback(); }
}
