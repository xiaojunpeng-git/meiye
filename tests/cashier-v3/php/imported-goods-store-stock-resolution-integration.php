<?php
declare(strict_types=1);

/**
 * Read-only integration check: imported presale/gift rights must resolve only
 * their own default-store stock identity. No customer claim or stock mutation
 * is made; the transaction is always rolled back after lock-based inspection.
 */
$backend = getenv('CASHIER_V3_BACKEND') ?: '/var/www/html';
$fallbackId = (int)(getenv('IMPORTED_GOODS_FALLBACK_LINE_ID') ?: 0);
$exactId = (int)(getenv('IMPORTED_GOODS_EXACT_LINE_ID') ?: 0);
if ($fallbackId <= 0 || $exactId <= 0) throw new RuntimeException('Test line IDs required');
require $backend.'/vendor/autoload.php';

use app\services\cashier\v3\presale\CashierV3PresaleClaimServices;
use think\facade\Db;

$app = new \think\App($backend.'/');
$app->env->load($backend.'/.env');
foreach (['database.hostname'=>getenv('DB_HOST') ?: 'mohe-mysql',
    'database.hostport'=>getenv('DB_PORT') ?: '3306',
    'database.database'=>getenv('DB_DATABASE') ?: 'ruihao',
    'database.username'=>getenv('DB_USERNAME') ?: 'root',
    'database.password'=>getenv('DB_PASSWORD') ?: 'localdev123'] as $key=>$value) $app->env->set($key,$value);
$envName = new ReflectionProperty($app,'envName');
$envName->setAccessible(true);
$envName->setValue($app,'imported_goods_stock_resolution_skip_dotenv_reload');
$app->initialize();
$service = new CashierV3PresaleClaimServices();
$method = new ReflectionMethod($service,'lockStock');
$method->setAccessible(true);
$resolved = [];
Db::startTrans();
try {
    foreach ([$fallbackId,$exactId] as $id) {
        $right = (array)Db::name('cashier_v3_presale_claimable_line')->where('id',$id)->find();
        if (!$right) throw new RuntimeException('Claimable line missing');
        $location = (array)Db::name('inventory_location')->where('tenant_id',(string)$right['tenant_id'])
            ->where('store_id',(int)$right['store_id'])->where('location_type','STORE')
            ->where('is_default',1)->where('location_status','ACTIVE')->find();
        if (!$location) throw new RuntimeException('Default store location missing');
        $stock = $method->invoke($service,['tenantId'=>(string)$right['tenant_id'],
            'storeId'=>(int)$right['store_id']],$location,$right);
        if ((int)$stock['store_id'] !== (int)$right['store_id']
            || (int)$stock['location_id'] !== (int)$location['id']) throw new RuntimeException('Cross-store stock resolution');
        $resolved[$id] = ['kind'=>(string)$right['source_kind'],
            'mode'=>(int)$stock['consumable_product_id'] === (int)$right['product_id'] ? 'exact' : 'sibling',
            'stock_id'=>(int)$stock['id']];
    }
} finally {
    Db::rollback();
}
if ($resolved[$fallbackId]['mode'] !== 'sibling' || $resolved[$exactId]['mode'] !== 'exact') {
    throw new RuntimeException('Expected sibling and exact stock paths were not both exercised');
}
echo 'IMPORTED_GOODS_STORE_STOCK_RESOLUTION_PASS ',json_encode($resolved),PHP_EOL;
