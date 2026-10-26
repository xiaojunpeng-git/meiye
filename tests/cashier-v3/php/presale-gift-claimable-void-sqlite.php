<?php
declare(strict_types=1);

/** Isolated transaction regression: source closure preserves deliveries until each outbound is explicitly reversed. */
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
Db::execute('CREATE TABLE eb_cashier_v3_presale_claim (id INTEGER PRIMARY KEY, claim_id TEXT, tenant_id TEXT, claimable_line_id TEXT, store_id INTEGER, location_id INTEGER, source_kind TEXT, claim_no TEXT, idempotency_key TEXT, claim_status TEXT, void_idempotency_key TEXT, void_reason_snapshot TEXT, void_operator_id INTEGER, voided_at INTEGER, updated_at INTEGER, remark TEXT, remark_version INTEGER, quantity INTEGER)');
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
    'store_id' => 10, 'location_id' => 1, 'source_kind' => 'PRESALE', 'claim_no' => 'OUT-1', 'idempotency_key' => 'original-claim', 'claim_status' => 'SETTLED', 'void_idempotency_key' => '',
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

Db::execute('CREATE TABLE eb_inventory_location (id INTEGER PRIMARY KEY, tenant_id TEXT, store_id INTEGER, owner_id INTEGER, organization_id TEXT, organization_path TEXT, location_type TEXT, is_default INTEGER, location_status TEXT)');
Db::name('inventory_location')->insert(['id' => 1, 'tenant_id' => '0', 'store_id' => 10, 'owner_id' => 10, 'organization_id' => 'ORG-10', 'organization_path' => '/', 'location_type' => 'STORE', 'is_default' => 1, 'location_status' => 'ACTIVE']);
$expectFailure(static fn() => $service->updateRemarkForStore(10, 7, 'PCC-DELIVERED', ['expected_version' => 1, 'remark' => '修改']), 'presale_claimable_already_voided');
$expectFailure(static fn() => $service->claimForStore(10, 7, [
    'idempotency_key' => 'new-claim-after-void', 'claimable_line_id' => 'PCL-AVAILABLE',
    'quantity' => 1, 'business_date' => date('Y-m-d'),
]), 'presale_claimable_already_voided');

// A previously delivered outbound owns its batch and stock reversal even after
// the source closes; the source must stay closed and the reversal must be idempotent.
Db::execute('CREATE TABLE eb_inventory_stock (id INTEGER PRIMARY KEY, tenant_id TEXT, organization_id TEXT, organization_path TEXT, store_id INTEGER, location_id INTEGER, available_quantity_units INTEGER, version INTEGER, updated_at INTEGER)');
Db::execute('CREATE TABLE eb_inventory_batch (id INTEGER PRIMARY KEY, stock_id INTEGER, available_quantity_units INTEGER, version INTEGER, updated_at INTEGER)');
Db::execute('CREATE TABLE eb_cashier_v3_presale_claim_batch (id INTEGER PRIMARY KEY, tenant_id TEXT, claim_id TEXT, stock_id INTEGER, batch_id INTEGER, movement_fact_id INTEGER, quantity_units INTEGER, unit_cost_cents INTEGER, cost_amount_cents INTEGER)');
Db::execute('CREATE TABLE eb_inventory_batch_movement_fact (id INTEGER PRIMARY KEY AUTOINCREMENT, fact_key TEXT UNIQUE, tenant_id TEXT, organization_id TEXT, organization_path TEXT, location_id INTEGER, store_id INTEGER, operator_type TEXT, operator_id INTEGER, operator_name_snapshot TEXT, stock_id INTEGER, batch_id INTEGER, direction INTEGER, quantity_units INTEGER, unit_cost_cents INTEGER, cost_amount_cents INTEGER, fact_status TEXT, source_type TEXT, source_id TEXT, source_detail_id TEXT, detail_remark TEXT, reversal_of INTEGER, business_date TEXT, occurred_at INTEGER, settled_at INTEGER, recorded_at INTEGER)');
Db::name('inventory_stock')->insert(['id' => 1, 'tenant_id' => '0', 'organization_id' => 'ORG-10', 'organization_path' => '/', 'store_id' => 10, 'location_id' => 1, 'available_quantity_units' => 3, 'version' => 1, 'updated_at' => 1]);
Db::name('inventory_batch')->insert(['id' => 1, 'stock_id' => 1, 'available_quantity_units' => 3, 'version' => 1, 'updated_at' => 1]);
Db::name('inventory_batch_movement_fact')->insert(['id' => 1, 'fact_key' => 'original-outbound', 'tenant_id' => '0', 'stock_id' => 1, 'batch_id' => 1, 'direction' => -1, 'quantity_units' => 2, 'unit_cost_cents' => 100, 'cost_amount_cents' => 200]);
Db::name(CashierV3PresaleClaimServices::CLAIM_BATCH_TABLE)->insert(['id' => 1, 'tenant_id' => '0', 'claim_id' => 'PCC-DELIVERED', 'stock_id' => 1, 'batch_id' => 1, 'movement_fact_id' => 1, 'quantity_units' => 2, 'unit_cost_cents' => 100, 'cost_amount_cents' => 200]);
$voidInput = ['idempotency_key' => 'direct-claim-void-key', 'reason' => '重新入库'];
$voided = $service->voidForStore(10, 7, 'PCC-DELIVERED', $voidInput);
if ($voided['status'] !== 'VOIDED' || $voided['idempotent'] !== false) throw new RuntimeException('delivered_claim_void_failed');
$sourceAfter = Db::name(CashierV3PresaleClaimServices::CLAIMABLE_TABLE)->where('id', 1)->find();
$claimAfter = Db::name(CashierV3PresaleClaimServices::CLAIM_TABLE)->where('id', 1)->find();
$reversal = Db::name('inventory_batch_movement_fact')->where('reversal_of', 1)->find();
if ($sourceAfter['claim_status'] !== 'VOIDED' || (int)$sourceAfter['claimed_quantity'] !== 0
    || $claimAfter['claim_status'] !== 'VOIDED' || (int)$claimAfter['void_operator_id'] !== 7
    || (int)Db::name('inventory_stock')->where('id', 1)->value('available_quantity_units') !== 5
    || (int)Db::name('inventory_batch')->where('id', 1)->value('available_quantity_units') !== 5
    || !$reversal || (int)$reversal['direction'] !== 1 || (int)$reversal['quantity_units'] !== 2
    || (int)Db::name('inventory_batch_movement_fact')->count() !== 2) {
    throw new RuntimeException('closed_source_claim_reversal_mismatch');
}
$replay = $service->voidForStore(10, 7, 'PCC-DELIVERED', $voidInput);
if ($replay['idempotent'] !== true || (int)Db::name('inventory_stock')->where('id', 1)->value('available_quantity_units') !== 5
    || (int)Db::name('inventory_batch_movement_fact')->count() !== 2) throw new RuntimeException('claim_reversal_replayed_twice');
$expectFailure(static fn() => $service->voidForStore(10, 7, 'PCC-DELIVERED', [
    'idempotency_key' => 'different-void-key', 'reason' => '重复作废',
]), 'presale_claim_already_voided');

echo "PRESALE_GIFT_CLAIMABLE_VOID_SQLITE=PASS\n";
