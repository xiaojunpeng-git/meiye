<?php
declare(strict_types=1);

/** Read-only integration test for the browser entitlement note -> authority line boundary. */
$backend = getenv('CASHIER_V3_BACKEND') ?: '/var/www/html';
require $backend . '/vendor/autoload.php';

use app\services\cashier\v3\settlement\CashierV3CheckoutPreparationServices;
use think\facade\Db;

$app = new \think\App($backend . '/');
$app->env->load($backend . '/.env');
$app->initialize();
$source = (array)Db::name('cashier_v3_entitlement_service_fact')
    ->where('project_id', '>', 0)->where('store_id', '>', 0)
    ->order('id', 'desc')->field('project_id,store_id')->find();
if (!$source) throw new RuntimeException('ENTITLEMENT_REMARK_TEST_SOURCE_MISSING');

$adapter = (new ReflectionClass(CashierV3CheckoutPreparationServices::class))
    ->newInstanceWithoutConstructor();
$convert = new ReflectionMethod(CashierV3CheckoutPreparationServices::class, 'entitlementSnapshotLine');
$convert->setAccessible(true);
$line = [
    'id' => 'remark-test-line', 'entitlementSourceKind' => 'count_card',
    'entitlementInstanceId' => 0, 'entitlementSourceDetailId' => 0,
    'projectId' => (int)$source['project_id'], 'projectVersion' => 1,
    'quantity' => 1, 'actualAmount' => '322', 'name' => '备注转换测试',
    'detailRemark' => '顾客要求下次复核护理方案',
];

// Category resolution requires the checkout transaction, but this test only reads rows.
Db::startTrans();
try {
    $converted = (array)$convert->invoke($adapter, $line, (int)$source['store_id']);
    if (($converted['detailRemark'] ?? null) !== $line['detailRemark']) {
        throw new RuntimeException('ENTITLEMENT_REMARK_LOST_AT_AUTHORITY_ADAPTER');
    }
    $line['detailRemark'] = '';
    $withoutRemark = (array)$convert->invoke($adapter, $line, (int)$source['store_id']);
    if (($withoutRemark['detailRemark'] ?? null) !== '') {
        throw new RuntimeException('EMPTY_ENTITLEMENT_REMARK_CHANGED');
    }
    echo "CHECKOUT_ENTITLEMENT_REMARK_TRANSFER=PASS\n";
} finally {
    Db::rollback();
}
