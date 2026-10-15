<?php
declare(strict_types=1);

/** Isolated transaction regression: source void keeps delivered claims and never needs stock tables. */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
if (!class_exists(\Swoole\Table::class)) {
    eval('namespace Swoole; final class Table { public const TYPE_STRING = 7; public const TYPE_INT = 1; }');
}
$backend = dirname(__DIR__, 3) . '/后端代码';
require $backend . '/vendor/autoload.php';

use app\services\cashier\v3\presale\CashierV3PresaleClaimServices;
use app\services\cashier\v3\permission\CashierV3FeatureResolver;
use app\services\organization\EmployeeDataScopeServices;
use think\facade\Db;

$app = new \think\App($backend . '/');
$db = new \think\DbManager();
$db->setConfig([
    'default' => 'sqlite',
    'connections' => ['sqlite' => [
        'type' => 'sqlite', 'database' => ':memory:', 'prefix' => 'eb_',
        'fields_strict' => true, 'fields_cache' => false,
    ]],
]);
\think\Container::getInstance()->instance(\think\DbManager::class, $db);
// Supply a live-role grant and two data-authorized stores without loading
// unrelated permission tables. The production service still resolves both
// from its authoritative position and employee data-scope services.
$featureGrant = new class extends CashierV3FeatureResolver {
    public bool $enabled = true;
    public function resolveGrantedFeatures(array $profile): array
    {
        return $this->enabled && (int)($profile['employee_id'] ?? 0) === 77
            ? ['cashier.v3.inventory.presale_claim.void'] : [];
    }
};
\think\Container::getInstance()->instance(CashierV3FeatureResolver::class, $featureGrant);
\think\Container::getInstance()->instance(EmployeeDataScopeServices::class, new class extends EmployeeDataScopeServices {
    public function resolveEffectiveStoreIds(int $employeeId, int $contextStoreId = 0, array $adminInfo = [])
    {
        return $employeeId === 77 && $contextStoreId === 0 ? [10, 11] : [];
    }
});
if (Db::connect()->getConfig('type') !== 'sqlite') throw new RuntimeException('isolated_sqlite_required');

Db::execute('CREATE TABLE eb_system_store_staff (id INTEGER PRIMARY KEY, store_id INTEGER, employee_id INTEGER, status INTEGER, is_del INTEGER, staff_name TEXT)');
Db::execute('CREATE TABLE eb_employee_store_isolation (id INTEGER PRIMARY KEY, employee_id INTEGER, store_id INTEGER, status INTEGER, is_del INTEGER)');
Db::execute('CREATE TABLE eb_cashier_v3_presale_claimable_line (id INTEGER PRIMARY KEY, claimable_line_id TEXT, tenant_id TEXT, store_id INTEGER, source_kind TEXT, quantity INTEGER, claimed_quantity INTEGER, claim_status TEXT, close_operation_id TEXT, closed_at INTEGER, version INTEGER, updated_at INTEGER)');
Db::execute('CREATE TABLE eb_cashier_v3_presale_claimable_void (id INTEGER PRIMARY KEY AUTOINCREMENT, operation_id TEXT UNIQUE, tenant_id TEXT, claimable_line_id TEXT UNIQUE, store_id INTEGER, source_kind TEXT, previous_status TEXT, previous_close_operation_id TEXT, remaining_quantity INTEGER, reason_snapshot TEXT, operator_type TEXT, operator_id INTEGER, operator_name_snapshot TEXT, idempotency_key TEXT UNIQUE, created_at INTEGER)');
Db::execute('CREATE TABLE eb_cashier_v3_presale_claim (id INTEGER PRIMARY KEY, claim_id TEXT, tenant_id TEXT, claimable_line_id TEXT, store_id INTEGER, claim_no TEXT, idempotency_key TEXT, claim_status TEXT, void_idempotency_key TEXT, remark TEXT, remark_version INTEGER, quantity INTEGER)');
Db::name('system_store_staff')->insert(['id' => 7, 'store_id' => 10, 'employee_id' => 77, 'status' => 1, 'is_del' => 0, 'staff_name' => '本地测试员工']);

$fixtures = [
    ['id' => 1, 'claimable_line_id' => 'PCL-AVAILABLE', 'source_kind' => 'PRESALE', 'quantity' => 5, 'claimed_quantity' => 2, 'claim_status' => 'AVAILABLE'],
    ['id' => 2, 'claimable_line_id' => 'GCL-FULL', 'source_kind' => 'GIFT', 'quantity' => 1, 'claimed_quantity' => 1, 'claim_status' => 'FULLY_CLAIMED'],
    ['id' => 3, 'claimable_line_id' => 'PCL-CLOSED', 'source_kind' => 'PRESALE', 'quantity' => 2, 'claimed_quantity' => 0, 'claim_status' => 'CLOSED_AFTER_SALE_REVERSAL'],
];
foreach ($fixtures as $row) {
    Db::name(CashierV3PresaleClaimServices::CLAIMABLE_TABLE)->insert(array_merge($row, [
        'tenant_id' => '0', 'store_id' => 10, 'close_operation_id' => $row['id'] === 3 ? 'OLD-CLOSE' : '',
        'closed_at' => 0, 'version' => 1, 'updated_at' => 1,
    ]));
}
Db::name(CashierV3PresaleClaimServices::CLAIM_TABLE)->insert([
    'id' => 1, 'claim_id' => 'PCC-DELIVERED', 'tenant_id' => '0', 'claimable_line_id' => 'PCL-AVAILABLE',
    'store_id' => 10, 'claim_no' => 'OUT-1', 'idempotency_key' => 'original-claim', 'claim_status' => 'SETTLED', 'void_idempotency_key' => '',
    'remark' => '已交付', 'remark_version' => 1, 'quantity' => 2,
]);

$service = new CashierV3PresaleClaimServices();
$expectFailure = static function (callable $action, string $expected): void {
    try { $action(); } catch (Throwable $error) {
        if ($error->getMessage() === $expected) return;
        throw new RuntimeException('wrong_failure_' . $error->getMessage(), 0, $error);
    }
    throw new RuntimeException('expected_failure_' . $expected);
};
foreach ($fixtures as $row) {
    $key = 'claimable-void-' . $row['id'];
    // No inventory location or stock table exists yet: void must close eligibility alone.
    $result = $service->voidClaimableForStore(10, 7, $row['claimable_line_id'], [
        'idempotency_key' => $key, 'reason' => $row['id'] === 1 ? '会员不再领取' : '',
    ]);
    if ($result['status'] !== 'VOIDED' || $result['idempotent'] !== false) throw new RuntimeException('source_void_failed');
    $after = Db::name(CashierV3PresaleClaimServices::CLAIMABLE_TABLE)->where('id', $row['id'])->find();
    $audit = Db::name(CashierV3PresaleClaimServices::CLAIMABLE_VOID_TABLE)->where('claimable_line_id', $row['claimable_line_id'])->find();
    if ($after['claim_status'] !== 'VOIDED' || (int)$after['claimed_quantity'] !== $row['claimed_quantity']
        || (int)$after['version'] !== 2 || (int)$audit['remaining_quantity'] !== $row['quantity'] - $row['claimed_quantity']
        || $audit['previous_status'] !== $row['claim_status'] || (int)$audit['operator_id'] !== 7) {
        throw new RuntimeException('source_void_fact_changed');
    }
    if ($row['id'] === 3 && $audit['previous_close_operation_id'] !== 'OLD-CLOSE') throw new RuntimeException('prior_closure_audit_lost');
    $replay = $service->voidClaimableForStore(10, 7, $row['claimable_line_id'], ['idempotency_key' => $key]);
    if ($replay['idempotent'] !== true) throw new RuntimeException('idempotent_replay_failed');
    $expectFailure(static fn() => $service->voidClaimableForStore(10, 7, $row['claimable_line_id'], ['idempotency_key' => $key . '-different']), 'presale_claimable_already_voided');
}
if ((string)Db::name(CashierV3PresaleClaimServices::CLAIM_TABLE)->where('id', 1)->value('claim_status') !== 'SETTLED'
    || (int)Db::name(CashierV3PresaleClaimServices::CLAIM_TABLE)->where('id', 1)->value('quantity') !== 2) {
    throw new RuntimeException('delivered_claim_was_changed');
}
$expectFailure(static fn() => $service->voidClaimableForStore(11, 7, 'PCL-AVAILABLE', ['idempotency_key' => 'cross-store-key']), 'presale_claim_scope_denied');

// A role-authorized employee may close a different store's source line;
// the audit must name the source store, not the current cashier session store.
foreach ([11, 12] as $store) {
    Db::name(CashierV3PresaleClaimServices::CLAIMABLE_TABLE)->insert([
        'id' => $store, 'claimable_line_id' => 'GCL-STORE-' . $store, 'tenant_id' => '0',
        'store_id' => $store, 'source_kind' => 'GIFT', 'quantity' => 2,
        'claimed_quantity' => 0, 'claim_status' => 'AVAILABLE',
        'close_operation_id' => '', 'closed_at' => 0, 'version' => 1, 'updated_at' => 1,
    ]);
}
$cross = $service->voidClaimableForStore(10, 7, 'GCL-STORE-11', ['idempotency_key' => 'cross-store-authorized']);
if ($cross['status'] !== 'VOIDED'
    || (int)Db::name(CashierV3PresaleClaimServices::CLAIMABLE_VOID_TABLE)
        ->where('claimable_line_id', 'GCL-STORE-11')->value('store_id') !== 11) {
    throw new RuntimeException('authorized_cross_store_audit_failed');
}
$expectFailure(static fn() => $service->voidClaimableForStore(10, 7, 'GCL-STORE-12',
    ['idempotency_key' => 'cross-store-denied']), 'presale_claimable_line_not_found');
$featureGrant->enabled = false;
$expectFailure(static fn() => $service->voidClaimableForStore(10, 7, 'GCL-STORE-12',
    ['idempotency_key' => 'role-feature-denied']), 'presale_claim_scope_denied');
$featureGrant->enabled = true;
Db::name('employee_store_isolation')->insert(['id' => 1, 'employee_id' => 77, 'store_id' => 10, 'status' => 1, 'is_del' => 0]);
Db::name(CashierV3PresaleClaimServices::CLAIMABLE_TABLE)->insert([
    'id' => 14, 'claimable_line_id' => 'PCL-ISOLATED', 'tenant_id' => '0',
    'store_id' => 10, 'source_kind' => 'PRESALE', 'quantity' => 1,
    'claimed_quantity' => 0, 'claim_status' => 'AVAILABLE',
    'close_operation_id' => '', 'closed_at' => 0, 'version' => 1, 'updated_at' => 1,
]);
$expectFailure(static fn() => $service->voidClaimableForStore(10, 7, 'PCL-ISOLATED',
    ['idempotency_key' => 'isolated-store-denied']), 'presale_claimable_line_not_found');
Db::name('employee_store_isolation')->where('id', 1)->delete();

Db::execute('CREATE TABLE eb_inventory_location (id INTEGER PRIMARY KEY, tenant_id TEXT, store_id INTEGER, organization_id TEXT, organization_path TEXT, location_type TEXT, is_default INTEGER, location_status TEXT)');
Db::name('inventory_location')->insert(['id' => 1, 'tenant_id' => '0', 'store_id' => 10, 'organization_id' => 'ORG-10', 'organization_path' => '/', 'location_type' => 'STORE', 'is_default' => 1, 'location_status' => 'ACTIVE']);
$expectFailure(static fn() => $service->voidForStore(10, 7, 'PCC-DELIVERED', ['idempotency_key' => 'direct-claim-void-key', 'reason' => '重新入库']), 'presale_claimable_already_voided');
$expectFailure(static fn() => $service->updateRemarkForStore(10, 7, 'PCC-DELIVERED', ['expected_version' => 1, 'remark' => '修改']), 'presale_claimable_already_voided');
$expectFailure(static fn() => $service->claimForStore(10, 7, [
    'idempotency_key' => 'new-claim-after-void', 'claimable_line_id' => 'PCL-AVAILABLE',
    'quantity' => 1, 'business_date' => date('Y-m-d'),
]), 'presale_claimable_already_voided');

echo "PRESALE_GIFT_CLAIMABLE_VOID_SQLITE=PASS\n";
