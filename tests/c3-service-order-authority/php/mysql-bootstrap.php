<?php
declare(strict_types=1);

use app\services\cashier\v3\CashierV3DataScopeContext;
use think\facade\Config;
use think\facade\Db;

function c3MysqlBoot(): void
{
    static $booted = false;
    if ($booted) {
        return;
    }
    $backend = getenv('C3_BACKEND_ROOT') ?: '/workspace/后端代码';
    require_once $backend . '/vendor/autoload.php';
    $app = new \think\App($backend . '/');
    $app->env->load($backend . '/.env');
    $settings = [
        'cache.driver' => 'file', 'CACHE_DRIVER' => 'file', 'PHP_CACHE_DRIVER' => 'file',
        'database.type' => 'mysql', 'DATABASE_TYPE' => 'mysql',
        'database.hostname' => getenv('DB_HOST') ?: 'mysql',
        'DATABASE_HOSTNAME' => getenv('DB_HOST') ?: 'mysql',
        'database.hostport' => getenv('DB_PORT') ?: '3306',
        'DATABASE_HOSTPORT' => getenv('DB_PORT') ?: '3306',
        'database.database' => getenv('DB_DATABASE') ?: 'c3_service_order',
        'DATABASE_DATABASE' => getenv('DB_DATABASE') ?: 'c3_service_order',
        'database.username' => getenv('DB_USERNAME') ?: 'root',
        'DATABASE_USERNAME' => getenv('DB_USERNAME') ?: 'root',
        'database.password' => getenv('DB_PASSWORD') ?: '',
        'DATABASE_PASSWORD' => getenv('DB_PASSWORD') ?: '',
    ];
    foreach ($settings as $key => $value) {
        $app->env->set($key, $value);
    }
    $envName = new ReflectionProperty($app, 'envName');
    $envName->setAccessible(true);
    $envName->setValue($app, 'c3_service_order_test_skip_reload_dotenv');
    $app->initialize();
    Config::set(['default' => 'file'], 'cache');
    $effective = Config::get('database.connections.mysql');
    if (!is_array($effective)
        || (string)($effective['hostname'] ?? '') !== (getenv('DB_HOST') ?: 'mysql')
        || (string)($effective['database'] ?? '') !== (getenv('DB_DATABASE') ?: 'c3_service_order')) {
        throw new RuntimeException('C3_TEST_DATABASE_CONFIG_MISMATCH');
    }
    Db::connect('mysql', true)->query('SELECT 1');
    $booted = true;
}

function c3MysqlScope(string $mode, $visibleStoreIds = null, int $employeeId = 101): CashierV3DataScopeContext
{
    return new CashierV3DataScopeContext(
        9, $employeeId, 7, 'tenant-1', 'org-1', $visibleStoreIds, $mode, [],
        $mode === CashierV3DataScopeContext::MODE_ALL, '', 'c3-scope-v1', [], []
    );
}

function c3MysqlRequest(string $type, int $detailId, int $serviceOrderId = 0, int $reservationId = 0): array
{
    return [
        'tenantId' => 'tenant-1',
        'storeId' => 7,
        'entitlementSourceDetailId' => $detailId,
        'source' => compact('type', 'serviceOrderId', 'reservationId'),
    ];
}

function c3MysqlOrderRow(string $number, int $storeId, int $memberId, string $status, int $version, array $participants): array
{
    return [
        'service_order_no' => $number,
        'tenant_id' => 'tenant-1',
        'organization_id' => 'org-' . $storeId,
        'organization_path' => '/root/org-' . $storeId . '/',
        'organization_name_snapshot' => '组织' . $storeId,
        'business_store_id' => $storeId,
        'business_store_name_snapshot' => '门店' . $storeId,
        'member_id' => $memberId,
        'member_name_snapshot' => $memberId > 0 ? '会员' . $memberId : '游客',
        'source_type' => 'direct',
        'source_id' => 0,
        'source_no_snapshot' => '',
        'source_version_snapshot' => 0,
        'room_id' => 0,
        'room_name_snapshot' => '',
        'participant_employee_ids_json' => json_encode($participants),
        'status' => $status,
        'version' => $version,
        'business_date' => '2026-07-29',
        'service_started_at' => $status === 'IN_SERVICE' ? 1785286800 : 0,
        'pending_checkout_at' => $status === 'PENDING_CHECKOUT' ? 1785286900 : 0,
        'completed_at' => $status === 'COMPLETED' ? 1785287000 : 0,
        'cancelled_at' => 0,
        'voided_at' => 0,
        'occurred_at' => 1785286800,
        'recorded_at' => 1785286800,
        'created_by_staff_id' => 9,
        'created_by_employee_id' => 101,
        'created_by_name_snapshot' => '操作员',
        'created_at' => 1785286800,
        'updated_at' => 1785286800,
    ];
}

function c3MysqlLineRow(int $orderId, string $lineKey, int $detailId, int $times, string $status = 'ACTIVE'): array
{
    return [
        'tenant_id' => 'tenant-1',
        'service_order_id' => $orderId,
        'line_key' => $lineKey,
        'entitlement_source_detail_id' => $detailId,
        'entitlement_instance_id' => 8000 + $detailId,
        'project_id' => 9000 + $detailId,
        'project_name_snapshot' => '项目' . $detailId,
        'occupied_times' => $times,
        'service_target' => 'SELF',
        'is_experience' => 0,
        'artisan_staff_id' => 301,
        'artisan_employee_id' => 401,
        'artisan_name_snapshot' => '手艺人',
        'status' => $status,
        'version' => 1,
        'created_at' => 1785286800,
        'updated_at' => 1785286800,
    ];
}
