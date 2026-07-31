<?php
declare(strict_types=1);

$backend = getenv('BACKEND_ROOT') ?: '/workspace/后端代码';
require $backend . '/vendor/autoload.php';

use app\services\product\inventory\InventoryManualInboundServices;
use app\services\product\inventory\InventoryStockRequestServices;
use think\facade\Config;
use think\facade\Db;

$app = new \think\App($backend . '/');
$app->env->load($backend . '/.env');
foreach ([['database.type','mysql'], ['DATABASE_TYPE','mysql'], ['database.hostname',getenv('DB_HOST') ?: 'mysql'], ['DATABASE_HOSTNAME',getenv('DB_HOST') ?: 'mysql'], ['database.hostport',getenv('DB_PORT') ?: '3306'], ['DATABASE_HOSTPORT',getenv('DB_PORT') ?: '3306'], ['database.database',getenv('DB_DATABASE') ?: 'inventory_manual_inbound_test_20260730'], ['DATABASE_DATABASE',getenv('DB_DATABASE') ?: 'inventory_manual_inbound_test_20260730'], ['cache.driver','file'], ['CACHE_DRIVER','file']] as [$key, $value]) $app->env->set($key, $value);
$environment = new ReflectionProperty($app, 'envName'); $environment->setAccessible(true); $environment->setValue($app, 'inventory_request_test_skip_dotenv');
$app->initialize(); Config::set(['default' => 'file'], 'cache');

function requestAssert(string $name, bool $condition): void { global $failed; echo ($condition ? 'PASS ' : 'FAIL ') . $name . "\n"; if (!$condition) $failed++; }
function requestReason(callable $operation): string { try { $operation(); } catch (\Throwable $exception) { return $exception->getMessage(); } return ''; }
function requestEnsure(string $table, array $row): void { $query = Db::name($table); $table === 'organization_store' ? $query->where('store_id', $row['store_id']) : $query->where('id', $row['id']); if (!$query->find()) Db::name($table)->insert($row); }
function requestCommand(string $key, string $quantity): array { return ['idempotency_key' => $key, 'business_date' => '2026-07-30', 'remark' => 'TEST-请货保留数据', 'lines' => [['product_id' => 990091, 'sku_id' => 9900911, 'sku_unique' => 'testsku9900911', 'quantity' => $quantity]]]; }

$failed = 0;
foreach ([
    ['system_store', ['id'=>99009, 'name'=>'TEST-请货门店', 'is_del'=>0, 'is_show'=>1]],
    ['system_store_staff', ['id'=>990009, 'store_id'=>99009, 'status'=>1, 'is_del'=>0]],
    ['organization', ['id'=>99009, 'pid'=>99000, 'name'=>'TEST-请货组织', 'is_del'=>0]],
    ['organization_store', ['store_id'=>99009, 'org_id'=>99009]],
    ['store_product', ['id'=>990091, 'type'=>1, 'relation_id'=>99009, 'is_del'=>0, 'is_inventory'=>1, 'store_name'=>'TEST-请货精华', 'code'=>'TEST-P-990091', 'bar_code'=>'6909900100098', 'salon_stock_enabled'=>0, 'sort'=>1, 'keyword'=>'TEST-请货精华']],
    ['store_product_attr_value', ['id'=>9900911, 'product_id'=>990091, 'type'=>0, 'unique'=>'testsku9900911', 'suk'=>'30ml', 'bar_code'=>'6909900100098', 'code'=>'TEST-S-990091', 'stock_unit'=>'瓶']],
] as [$table, $row]) requestEnsure($table, $row);

$inbound = new InventoryManualInboundServices();
$inbound->create(99009, 990009, ['idempotency_key'=>'TEST-request-in-20260730', 'business_date'=>'2026-07-30', 'remark'=>'TEST-请货成本快照来源', 'lines'=>[['product_id'=>990091, 'sku_id'=>9900911, 'sku_unique'=>'testsku9900911', 'batch_no'=>'TEST-REQUEST-COST-20260730', 'quantity'=>'10', 'unit_cost'=>'66.50', 'manufactured_date'=>'2026-07-01', 'expire_date'=>'2027-07-01']]]);

$service = new InventoryStockRequestServices();
$stockBefore = Db::name('inventory_stock')->where('store_id', 99009)->where('consumable_product_id', 990091)->find();
$first = $service->apply(99009, 990009, requestCommand('TEST-request-one-20260730', '3'));
$replay = $service->apply(99009, 990009, requestCommand('TEST-request-one-20260730', '3'));
$second = $service->apply(99009, 990009, requestCommand('TEST-request-two-20260730', '2'));
$firstLine = Db::name('inventory_stock_request_line')->where('document_id', (int)$first['request_id'])->find();
$stock = Db::name('inventory_stock')->where('store_id', 99009)->where('consumable_product_id', 990091)->find();
$cancel = $service->cancel(99009, 990009, (int)$first['request_id']);
$cancelReplay = $service->cancel(99009, 990009, (int)$first['request_id']);

requestAssert('two TEST requests save without changing the real batch stock balance', (int)Db::name('inventory_stock_request_document')->whereIn('idempotency_key', ['TEST-request-one-20260730', 'TEST-request-two-20260730'])->count() === 2 && (int)$stock['available_quantity_units'] === (int)$stockBefore['available_quantity_units']);
requestAssert('request snapshots SKU quantity and the default warehouse reference cost', (int)$firstLine['requested_quantity_units'] === 3 && (int)$firstLine['reference_unit_cost_cents'] === 6650 && (string)$firstLine['reference_cost_status'] === 'SNAPSHOT' && (int)$firstLine['fulfilled_transfer_id'] === 0);
requestAssert('same key replays the same request rather than saving a third request', $replay['idempotent'] && (int)$replay['request_id'] === (int)$first['request_id'] && (int)Db::name('inventory_stock_request_document')->where('store_id', 99009)->count() === 2);
requestAssert('current-store cancellation keeps history and is idempotent', $cancelReplay['idempotent'] && (string)Db::name('inventory_stock_request_document')->where('id', (int)$first['request_id'])->value('document_status') === 'CANCELLED');
requestAssert('another store cannot cancel this request', requestReason(static function () use ($service, $first): void { $service->cancel(99001, 990001, (int)$first['request_id']); }) === 'inventory_stock_request_not_found');
requestAssert('changed input cannot reuse the first request idempotency key', requestReason(static function () use ($service): void { $service->apply(99009, 990009, requestCommand('TEST-request-one-20260730', '4')); }) === 'inventory_stock_request_idempotency_conflict');

echo "INVENTORY_STOCK_REQUEST_RESULT failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
