<?php
declare(strict_types=1);

/** Exercise historical order identity and card provenance against isolated SQL. */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
if (!class_exists(\Swoole\Table::class)) {
    eval('namespace Swoole; final class Table { public const TYPE_STRING = 7; public const TYPE_INT = 1; }');
}
$backend = dirname(__DIR__, 3) . '/后端代码';
require $backend . '/vendor/autoload.php';

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\order\CashierV3OrderLifecycleServices;
use app\services\cashier\v3\order\CashierV3SalesOrderReversalServices;
use think\facade\Db;

new \think\App($backend . '/');
$manager = new \think\DbManager();
$manager->setConfig(['default' => 'void_test', 'connections' => ['void_test' => [
    'type' => 'sqlite', 'database' => ':memory:', 'prefix' => 'eb_',
    'fields_strict' => true, 'fields_cache' => false,
]]]);
\think\Container::getInstance()->instance(\think\DbManager::class, $manager);
if (Db::connect()->getConfig('database') !== ':memory:') throw new RuntimeException('isolation_required');

Db::execute('CREATE TABLE eb_cashier_v3_sales_order (id INTEGER PRIMARY KEY,tenant_id TEXT,organization_id TEXT,store_id INTEGER,order_id TEXT,order_no TEXT,order_status TEXT,order_direction TEXT,member_id INTEGER,store_name_snapshot TEXT,checkout_request_id TEXT,business_date TEXT,sale_amount_cents INTEGER,order_note TEXT,source_document_type TEXT,source_document_id TEXT)');
Db::execute('CREATE TABLE eb_cashier_v3_sales_order_line (id INTEGER PRIMARY KEY,tenant_id TEXT,order_id TEXT,order_line_id TEXT,line_direction TEXT,line_status TEXT,item_type TEXT,quantity INTEGER)');
Db::execute('CREATE TABLE eb_store_order (id INTEGER PRIMARY KEY,uid INTEGER,store_id INTEGER,order_id TEXT,paid INTEGER,refund_status INTEGER,terminal_action INTEGER)');
Db::execute('CREATE TABLE eb_user_card_holder (id INTEGER PRIMARY KEY,uid INTEGER,oid INTEGER,is_del INTEGER,write_times INTEGER,write_surplus_times INTEGER)');
Db::execute('CREATE TABLE eb_store_order_cart_info (id INTEGER PRIMARY KEY,oid INTEGER,cart_type INTEGER,cart_num INTEGER)');
Db::execute('CREATE TABLE eb_cashier_v3_sale_inventory_receipt (id INTEGER PRIMARY KEY,tenant_id TEXT,sales_order_id TEXT)');

Db::name('cashier_v3_sales_order')->insert(['id' => 1, 'tenant_id' => 'T', 'organization_id' => '65',
    'store_id' => 1, 'order_id' => 'IMP007-127119-sale', 'order_no' => 'wx846063308397084672',
    'order_status' => 'settled', 'order_direction' => 'forward', 'member_id' => 785,
    'store_name_snapshot' => '万象城店', 'checkout_request_id' => 'IMP007-127119-request',
    'business_date' => '2026-10-26', 'sale_amount_cents' => 59800, 'order_note' => '',
    'source_document_type' => 'legacy_store_order', 'source_document_id' => '127119']);
Db::name('cashier_v3_sales_order_line')->insert(['id' => 1, 'tenant_id' => 'T',
    'order_id' => 'IMP007-127119-sale', 'order_line_id' => 'IMP007-127119-line-72022',
    'line_direction' => 'forward', 'line_status' => 'settled', 'item_type' => 'card', 'quantity' => 1]);
Db::name('store_order')->insert(['id' => 127119, 'uid' => 785, 'store_id' => 1,
    'order_id' => 'wx846063308397084672', 'paid' => 1, 'refund_status' => 0, 'terminal_action' => 0]);
Db::name('user_card_holder')->insert(['id' => 12644, 'uid' => 785, 'oid' => 127119,
    'is_del' => 0, 'write_times' => 14, 'write_surplus_times' => 14]);
Db::name('store_order_cart_info')->insert(['id' => 72022, 'oid' => 127119, 'cart_type' => 0, 'cart_num' => 1]);

$scope = new CashierV3DataScopeContext(7, 7, 1, 'T', '68', [1],
    CashierV3DataScopeContext::MODE_STORES, [], false, '', 'test', [], ['id' => 7]);
$operator = new CashierV3OperatorScope(1, 7, '68', 'T');
$sourceMethod = new ReflectionMethod(CashierV3OrderLifecycleServices::class, 'source');
$sourceMethod->setAccessible(true);
$source = $sourceMethod->invoke(new CashierV3OrderLifecycleServices(),
    ['orderId' => 'IMP007-127119-sale'], $operator, $scope, false);
if ($source['sourceOrganizationId'] !== '65' || $source['storeId'] !== 1) {
    throw new RuntimeException('historical_organization_lost');
}
$receiptMethod = new ReflectionMethod(CashierV3SalesOrderReversalServices::class, 'legacyImportedCardEvidence');
$receiptMethod->setAccessible(true);
$reversal = new CashierV3SalesOrderReversalServices();
$evidence = $receiptMethod->invoke($reversal, $source, $scope);
if ($evidence['card_holder_id'] !== 12644 || $evidence['legacy_order_id'] !== 127119
    || strpos($evidence['receipt_id'], 'HIST-') !== 0) {
    throw new RuntimeException('legacy_card_evidence_wrong');
}

// A second holder makes provenance ambiguous and must block the void path.
Db::name('user_card_holder')->insert(['id' => 12645, 'uid' => 785, 'oid' => 127119,
    'is_del' => 0, 'write_times' => 14, 'write_surplus_times' => 14]);
try {
    $receiptMethod->invoke($reversal, $source, $scope);
    throw new RuntimeException('ambiguous_holder_was_allowed');
} catch (\app\services\cashier\v3\CashierV3CommandException $expected) {
    if (($expected->getDetail()['reason'] ?? '') !== 'sales_reversal_card_issuance_incomplete') {
        throw new RuntimeException('wrong_ambiguous_holder_reason');
    }
}

// An imported product with no outbound proof must stop before any reversal.
Db::name('cashier_v3_sales_order_line')->insert(['id' => 2, 'tenant_id' => 'T',
    'order_id' => 'IMP007-127119-sale', 'order_line_id' => 'IMP007-127119-line-72026',
    'line_direction' => 'forward', 'line_status' => 'settled', 'item_type' => 'product', 'quantity' => 1]);
Db::startTrans();
try {
    $reversal->prepare($source, 'void-sales-order', [], $scope);
    throw new RuntimeException('imported_product_without_stock_proof_was_allowed');
} catch (\app\services\cashier\v3\CashierV3CommandException $expected) {
    if (($expected->getDetail()['reason'] ?? '') !== 'sales_historical_product_inventory_unverified') {
        throw new RuntimeException('wrong_product_proof_reason');
    }
} finally {
    Db::rollback();
}
echo "LEGACY_SALES_VOID_COMPAT=PASS\n";
