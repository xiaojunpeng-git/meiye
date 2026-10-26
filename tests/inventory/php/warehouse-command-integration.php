<?php
declare(strict_types=1);

$backend = getenv('BACKEND_ROOT') ?: '/workspace/后端代码';
require __DIR__ . '/manual-test-bootstrap.php';

use app\services\product\inventory\InventoryPlatformWarehouseCommandServices;
use think\facade\Db;

Db::execute("CREATE TABLE IF NOT EXISTS eb_system_admin (
  id bigint(20) unsigned NOT NULL,
  account varchar(64) NOT NULL DEFAULT '',
  real_name varchar(64) NOT NULL DEFAULT '',
  level int(11) NOT NULL DEFAULT 1,
  admin_type int(11) NOT NULL DEFAULT 0,
  roles varchar(255) NOT NULL DEFAULT '',
  status tinyint(1) NOT NULL DEFAULT 1,
  is_del tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
Db::execute("CREATE TABLE IF NOT EXISTS eb_system_menus (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  pid bigint(20) unsigned NOT NULL DEFAULT 0,
  type tinyint(2) NOT NULL DEFAULT 1,
  icon varchar(64) NOT NULL DEFAULT '',
  menu_name varchar(128) NOT NULL DEFAULT '',
  module varchar(64) NOT NULL DEFAULT '',
  controller varchar(128) NOT NULL DEFAULT '',
  action varchar(128) NOT NULL DEFAULT '',
  api_url varchar(255) NOT NULL DEFAULT '',
  methods varchar(32) NOT NULL DEFAULT '',
  params text,
  sort int(11) NOT NULL DEFAULT 0,
  is_show tinyint(1) NOT NULL DEFAULT 0,
  is_show_path tinyint(1) NOT NULL DEFAULT 0,
  access tinyint(1) NOT NULL DEFAULT 1,
  menu_path varchar(255) NOT NULL DEFAULT '',
  path varchar(255) NOT NULL DEFAULT '',
  auth_type tinyint(1) NOT NULL DEFAULT 1,
  header varchar(64) NOT NULL DEFAULT '',
  is_header tinyint(1) NOT NULL DEFAULT 0,
  unique_auth varchar(128) NOT NULL DEFAULT '',
  is_del tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_unique_auth (unique_auth,is_del,type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function warehouseAssert(string $name, bool $condition): void
{
    global $warehouseFailed;
    echo ($condition ? 'PASS ' : 'FAIL ') . $name . "\n";
    if (!$condition) $warehouseFailed++;
}

function warehouseError(callable $callback): string
{
    try { $callback(); } catch (\Throwable $exception) { return $exception->getMessage(); }
    return '';
}

$warehouseFailed = 0;
$warehouseSource = file_get_contents($backend . '/app/services/product/inventory/InventoryPlatformWarehouseCommandServices.php');
warehouseAssert('warehouse advisory lock name remains within the MySQL 64-character limit',
    is_string($warehouseSource)
    && strpos($warehouseSource, "'inventory-location:' . substr(") !== false
    && strpos($warehouseSource, "0,\n            40") !== false);
$superAdminId = 99110;
if (!Db::name('system_admin')->where('id', $superAdminId)->find()) {
    Db::name('system_admin')->insert([
        'id' => $superAdminId, 'account' => 'TEST-INVENTORY-WAREHOUSE-SUPER', 'real_name' => 'TEST 仓库超管',
        'level' => 0, 'admin_type' => 0, 'roles' => '', 'status' => 1, 'is_del' => 0,
    ]);
}

$service = new InventoryPlatformWarehouseCommandServices();
$input = [
    'idempotency_key' => 'warehouse-create-test-20260731-001',
    'store_id' => 99001,
    'location_name' => 'TEST 调拨复验仓',
];
$first = $service->create(['id' => $superAdminId], $input);
$second = $service->create(['id' => $superAdminId], $input);
$location = Db::name('inventory_location')->where('id', (int)($first['location']['id'] ?? 0))->find();
warehouseAssert('super admin creates a same-store non-default warehouse with creator audit',
    !($first['idempotent'] ?? true)
    && (int)($location['store_id'] ?? 0) === 99001
    && (int)($location['is_default'] ?? 1) === 0
    && (int)($location['created_by_admin_id'] ?? 0) === $superAdminId
    && (string)($location['location_status'] ?? '') === 'ACTIVE');
warehouseAssert('warehouse create replay returns the original row without a duplicate',
    ($second['idempotent'] ?? false)
    && (int)($second['location']['id'] ?? 0) === (int)($first['location']['id'] ?? 0)
    && (int)Db::name('inventory_location')->where('tenant_id', '0')->where('store_id', 99001)->where('location_name', 'TEST 调拨复验仓')->count() === 1);
warehouseAssert('same idempotency key cannot be replayed with a different warehouse name',
    warehouseError(static function () use ($service, $superAdminId, $input): void {
        $changed = $input; $changed['location_name'] = 'TEST 被篡改仓';
        $service->create(['id' => $superAdminId], $changed);
    }) === 'inventory_platform_warehouse_idempotency_conflict');
warehouseAssert('different idempotency key cannot duplicate a warehouse name in the same store',
    warehouseError(static function () use ($service, $superAdminId, $input): void {
        $changed = $input; $changed['idempotency_key'] = 'warehouse-create-test-20260731-002';
        $service->create(['id' => $superAdminId], $changed);
    }) === 'inventory_platform_warehouse_name_taken');

$unprivilegedAdminId = 99111;
if (!Db::name('system_admin')->where('id', $unprivilegedAdminId)->find()) {
    Db::name('system_admin')->insert([
        'id' => $unprivilegedAdminId, 'account' => 'TEST-INVENTORY-WAREHOUSE-NOAUTH', 'real_name' => 'TEST 无权限',
        'level' => 1, 'admin_type' => 0, 'roles' => '', 'status' => 1, 'is_del' => 0,
    ]);
}
warehouseAssert('non-super administrator without warehouse permission is rejected before master-data write',
    warehouseError(static function () use ($service, $unprivilegedAdminId): void {
        $service->create(['id' => $unprivilegedAdminId], [
            'idempotency_key' => 'warehouse-create-test-20260731-denied',
            'store_id' => 99001,
            'location_name' => 'TEST 越权仓',
        ]);
    }) === '当前岗位未配置“平台库存查看”权限。'
    && (int)Db::name('inventory_location')->where('tenant_id', '0')->where('store_id', 99001)->where('location_name', 'TEST 越权仓')->count() === 0);

echo "INVENTORY_WAREHOUSE_COMMAND_RESULT failed={$warehouseFailed}\n";
exit($warehouseFailed === 0 ? 0 : 1);
