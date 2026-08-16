<?php

declare(strict_types=1);

$repoRoot = dirname(__DIR__, 3);
$backend = is_dir('/workspace/后端代码') ? '/workspace/后端代码'
    : (is_dir('/var/www/html/app') ? '/var/www/html' : $repoRoot . '/后端代码');
require $backend . '/vendor/autoload.php';

use app\services\report\StoreOperationsReportAnnotationServices;
use app\services\report\StoreReportPaymentSaleAllocationFactServices;
use app\services\report\StoreUnifiedReportPhaseThreeFoundationServices;
use app\services\report\StoreUnifiedReportPhaseThreeServices;
use think\facade\Config;
use think\facade\Db;

$app = new \think\App($backend . '/');
foreach ([
    'cache.driver' => 'file', 'CACHE_DRIVER' => 'file',
    'database.type' => 'mysql', 'DATABASE_TYPE' => 'mysql',
    'database.hostname' => getenv('DB_HOST') ?: 'mysql',
    'DATABASE_HOSTNAME' => getenv('DB_HOST') ?: 'mysql',
    'database.hostport' => getenv('DB_PORT') ?: '3306',
    'DATABASE_HOSTPORT' => getenv('DB_PORT') ?: '3306',
    'database.database' => getenv('DB_DATABASE') ?: 'ruihao_phase3_contract_20260816',
    'DATABASE_DATABASE' => getenv('DB_DATABASE') ?: 'ruihao_phase3_contract_20260816',
    'database.username' => getenv('DB_USERNAME') ?: 'root',
    'DATABASE_USERNAME' => getenv('DB_USERNAME') ?: 'root',
    'database.password' => getenv('DB_PASSWORD') ?: 'localdev123',
    'DATABASE_PASSWORD' => getenv('DB_PASSWORD') ?: 'localdev123',
] as $key => $value) {
    $app->env->set($key, $value);
}
$envName = new ReflectionProperty($app, 'envName');
$envName->setAccessible(true);
$envName->setValue($app, 'phase_three_report_test_skip_dotenv_reload');
$app->initialize();
Config::set(['default' => 'file'], 'cache');
Db::connect('mysql', true)->query('SELECT 1');

$passed = 0;
$failed = 0;
function phaseThreeMysqlOk(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS {$name}\n";
        return;
    }
    $failed++;
    echo "FAIL {$name}" . ($detail === '' ? '' : ": {$detail}") . "\n";
}

function phaseThreeMysqlThrows(callable $callback, string $contains): bool
{
    try {
        $callback();
    } catch (Throwable $error) {
        return strpos($error->getMessage(), $contains) !== false;
    }
    return false;
}

$tenant = 'phase3-test';
$memberNew = 900000001;
$memberImported = 900000002;
$memberUnknown = 900000003;
$matrixFixturePrefix = 'P3MATRIX';
$matrixCreatedSixDimensionRoot = false;
$stores = array_values(array_map('intval', Db::name('system_store')->where('is_del', 0)
    ->where('name', '<>', '总部')->order('id', 'asc')->limit(2)->column('id')));
if (count($stores) < 2) {
    throw new RuntimeException('集成测试至少需要两个本地门店');
}

Db::startTrans();
try {
    $foundation = new StoreUnifiedReportPhaseThreeFoundationServices();
    $now = StoreUnifiedReportPhaseThreeFoundationServices::V3_CUTOVER_AT + 3600;
    $foundation->recordMemberOrigin(['tenant_id' => $tenant, 'operator_id' => 7], [
        'member_id' => $memberNew, 'origin_type' => 'SYSTEM_CREATED',
        'source_type' => 'MYSQL_TEST', 'source_id' => 'new', 'member_created_at' => $now,
        'idempotency_key' => 'phase3-test-origin-new',
    ]);
    $foundation->recordMemberOrigin(['tenant_id' => $tenant, 'operator_id' => 7], [
        'member_id' => $memberImported, 'origin_type' => 'IMPORTED',
        'source_type' => 'MYSQL_TEST', 'source_id' => 'imported', 'member_created_at' => $now,
        'idempotency_key' => 'phase3-test-origin-imported',
    ]);
    $origins = $foundation->memberOrigins($tenant, [$memberNew, $memberImported, $memberUnknown]);
    phaseThreeMysqlOk('system-created member after cutover is new-customer eligible', !empty($origins[$memberNew]['is_new_customer_eligible']));
    phaseThreeMysqlOk('imported and unknown members fail closed as returning',
        empty($origins[$memberImported]['is_new_customer_eligible'])
        && empty($origins[$memberUnknown]['is_new_customer_eligible']));

    $tierContext = ['tenant_id' => $tenant, 'operator_id' => 7, 'operator_name' => '集成测试'];
    $lowTier = $foundation->saveConsumptionTier($tierContext, [
        'tier_code' => 'test_low', 'tier_name' => '低档', 'lower_bound_cents' => 0,
        'upper_bound_cents' => 10000, 'sort_order' => 10, 'enabled' => 1,
        'expected_version' => 0, 'idempotency_key' => 'phase3-test-tier-low',
    ]);
    $highTier = $foundation->saveConsumptionTier($tierContext, [
        'tier_code' => 'test_high', 'tier_name' => '高档', 'lower_bound_cents' => 10000,
        'upper_bound_cents' => null, 'sort_order' => 20, 'enabled' => 1,
        'expected_version' => 0, 'idempotency_key' => 'phase3-test-tier-high',
    ]);
    phaseThreeMysqlOk('tier overlap is rejected atomically', phaseThreeMysqlThrows(
        function () use ($foundation, $tierContext): void {
            $foundation->saveConsumptionTier($tierContext, [
                'tier_code' => 'test_overlap', 'tier_name' => '重叠档', 'lower_bound_cents' => 5000,
                'upper_bound_cents' => 15000, 'sort_order' => 30, 'enabled' => 1,
                'expected_version' => 0, 'idempotency_key' => 'phase3-test-tier-overlap',
            ]);
        }, '存在重叠'
    ) && !Db::name('cashier_v3_report_consumption_tier')->where('tenant_id', $tenant)
        ->where('tier_code', 'test_overlap')->find());
    phaseThreeMysqlOk('partial tier ordering is rejected', phaseThreeMysqlThrows(
        function () use ($foundation, $tierContext, $lowTier): void {
            $foundation->sortConsumptionTiers($tierContext, [
                'idempotency_key' => 'phase3-test-tier-sort-partial',
                'tiers' => [['tier_code' => 'test_low', 'expected_version' => (int)$lowTier['version']]],
            ]);
        }, '必须包含当前全部配置'
    ));
    $sortedTiers = $foundation->sortConsumptionTiers($tierContext, [
        'idempotency_key' => 'phase3-test-tier-sort-all',
        'tiers' => [
            ['tier_code' => 'test_high', 'expected_version' => (int)$highTier['version']],
            ['tier_code' => 'test_low', 'expected_version' => (int)$lowTier['version']],
        ],
    ]);
    phaseThreeMysqlOk('complete tier ordering updates every version atomically',
        count($sortedTiers) === 2
        && (int)$sortedTiers[0]['sort_order'] === 10 && (int)$sortedTiers[0]['version'] === 2
        && (int)$sortedTiers[1]['sort_order'] === 20 && (int)$sortedTiers[1]['version'] === 2);
    $foundation->saveConsumptionTier($tierContext, [
        'tier_code' => 'test_low', 'tier_name' => '低档', 'lower_bound_cents' => 0,
        'upper_bound_cents' => 10000, 'sort_order' => 20, 'enabled' => 0, 'delete' => 1,
        'expected_version' => 2, 'idempotency_key' => 'phase3-test-tier-delete',
    ]);
    phaseThreeMysqlOk('tier delete is a versioned audited soft disable',
        (string)Db::name('cashier_v3_report_consumption_tier_audit')->where('tenant_id', $tenant)
            ->where('idempotency_key', 'phase3-test-tier-delete')->value('action') === 'DELETED'
        && (int)Db::name('cashier_v3_report_consumption_tier')->where('tenant_id', $tenant)
            ->where('tier_code', 'test_low')->value('enabled') === 0
        && (int)Db::name('cashier_v3_report_consumption_tier')->where('tenant_id', $tenant)
            ->where('tier_code', 'test_low')->value('deleted_at') > 0
        && count($foundation->consumptionTiers($tenant, false)) === 1);

    $digest = hash('sha256', 'phase3-test-phone');
    Db::name('user_phone_identity')->insert([
        'phone_digest' => $digest, 'bound_uid' => $memberNew, 'state' => 'BOUND',
        'identity_version' => 1, 'bound_at' => $now, 'released_at' => 0,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $identities = $foundation->memberIdentityKeys([$memberNew, $memberUnknown]);
    phaseThreeMysqlOk('canonical phone identity and member-id fallback are deterministic',
        $identities[$memberNew] === 'phone:' . $digest
        && $identities[$memberUnknown] === 'member:' . $memberUnknown);

    $foundation->recordMemberStoreAssignmentInTx(['tenant_id' => $tenant], [
        'member_id' => $memberNew, 'assigned' => true, 'store_id' => $stores[0],
        'effective_at' => $now, 'source_type' => 'MYSQL_TEST', 'source_event_id' => 'assignment-1',
        'idempotency_key' => 'phase3-test-assignment-1',
    ]);
    $foundation->recordMemberStoreAssignmentInTx(['tenant_id' => $tenant], [
        'member_id' => $memberNew, 'assigned' => true, 'store_id' => $stores[1],
        'effective_at' => $now + 86400, 'source_type' => 'MYSQL_TEST', 'source_event_id' => 'assignment-2',
        'idempotency_key' => 'phase3-test-assignment-2',
    ]);
    $firstDay = date('Y-m-d', $now);
    $secondDay = date('Y-m-d', $now + 86400);
    $firstAssignment = $foundation->memberStoreAssignmentsAt($tenant, [$memberNew, $memberUnknown], $firstDay);
    $secondAssignment = $foundation->memberStoreAssignmentsAt($tenant, [$memberNew], $secondDay);
    phaseThreeMysqlOk('cutoff assignment is inclusive and switches by effective period',
        $firstAssignment[$memberNew]['store_id'] === $stores[0]
        && $secondAssignment[$memberNew]['store_id'] === $stores[1]
        && $firstAssignment[$memberUnknown]['configured'] === false);

    $annotations = new StoreOperationsReportAnnotationServices();
    $paymentFactRowId = (int)Db::name('cashier_v3_payment_sale_allocation_fact')->insertGetId([
        'allocation_fact_id' => 'phase3-test-allocation', 'natural_key' => 'phase3-test-allocation-natural',
        'contract_version' => 'phase3-test-v1', 'fact_version' => 1, 'status' => 'effective',
        'fact_direction' => 'forward', 'reversal_of' => '', 'tenant_id' => $tenant,
        'organization_id' => 'phase3-org', 'store_id' => $stores[0], 'member_id' => $memberNew,
        'order_id' => 'ORDER-101', 'order_no_snapshot' => 'ORDER-101',
        'payment_fact_id' => 'PAYMENT-101', 'payment_method' => 'cash',
        'payment_amount_cents' => 500, 'sale_fact_id' => 'SALE-101', 'source_line_id' => 'LINE-101',
        'sale_amount_cents' => 300, 'debt_amount_cents' => 0, 'allocation_base_amount_cents' => 300,
        'amount_cents' => 300, 'business_date' => $firstDay, 'occurred_at' => $now,
        'settled_at' => $now, 'recorded_at' => $now, 'business_event_no' => 'EVENT-101',
        'checkout_request_id' => 'CHECKOUT-101', 'command_idempotency_key' => 'phase3-test-payment-command',
        'immutable_fingerprint' => hash('sha256', 'phase3-test-payment'),
        'add_time' => $now, 'update_time' => $now,
    ]);
    Db::name('cashier_v3_payment_sale_allocation_fact')->insert([
        'allocation_fact_id' => 'phase3-test-allocation-2', 'natural_key' => 'phase3-test-allocation-natural-2',
        'contract_version' => 'phase3-test-v1', 'fact_version' => 1, 'status' => 'effective',
        'fact_direction' => 'forward', 'reversal_of' => '', 'tenant_id' => $tenant,
        'organization_id' => 'phase3-org', 'store_id' => $stores[0], 'member_id' => $memberNew,
        'order_id' => 'ORDER-101', 'order_no_snapshot' => 'ORDER-101',
        'payment_fact_id' => 'PAYMENT-101', 'payment_method' => 'cash',
        'payment_amount_cents' => 500, 'sale_fact_id' => 'SALE-102', 'source_line_id' => 'LINE-102',
        'sale_amount_cents' => 200, 'debt_amount_cents' => 0, 'allocation_base_amount_cents' => 200,
        'amount_cents' => 200, 'business_date' => $firstDay, 'occurred_at' => $now,
        'settled_at' => $now, 'recorded_at' => $now, 'business_event_no' => 'EVENT-101',
        'checkout_request_id' => 'CHECKOUT-101', 'command_idempotency_key' => 'phase3-test-payment-command',
        'immutable_fingerprint' => hash('sha256', 'phase3-test-payment-2'),
        'add_time' => $now, 'update_time' => $now,
    ]);
    $reversalService = new StoreReportPaymentSaleAllocationFactServices();
    $reversalContext = [
        'tenant_id' => $tenant, 'organization_id' => 'phase3-org', 'store_id' => $stores[0],
        'member_id' => $memberNew, 'order_id' => 'ORDER-101', 'order_no_snapshot' => 'ORDER-101',
        'business_date' => $secondDay, 'occurred_at' => $now + 86400, 'settled_at' => $now + 86400,
        'recorded_at' => $now + 86400, 'business_event_no' => 'EVENT-101-REFUND',
        'checkout_request_id' => 'CHECKOUT-101',
    ];
    $reversalFacts = [[
        'fact_id' => 'PAYMENT-101-REFUND', 'reversal_of' => 'PAYMENT-101',
        'amount_cents' => -201, 'status' => 'effective', 'fact_direction' => 'reversal',
        'payment_method' => 'cash', 'command_idempotency_key' => 'phase3-test-payment-refund-command',
    ]];
    $reversalResult = $reversalService->persistLifecycleReversalsInTx($reversalContext, $reversalFacts);
    $reversalReplay = $reversalService->persistLifecycleReversalsInTx($reversalContext, $reversalFacts);
    $reversalAllocations = Db::name('cashier_v3_payment_sale_allocation_fact')
        ->where('tenant_id', $tenant)->where('payment_fact_id', 'PAYMENT-101-REFUND')
        ->order('source_line_id', 'asc')->select()->toArray();
    phaseThreeMysqlOk('lifecycle refund preserves multi-line weights, cent remainder and exact reversal links',
        (int)$reversalResult['inserted'] === 2
        && (int)$reversalReplay['replayed'] === 2
        && array_sum(array_map(static function (array $row): int { return (int)$row['amount_cents']; }, $reversalAllocations)) === -201
        && (int)$reversalAllocations[0]['amount_cents'] === -121
        && (int)$reversalAllocations[1]['amount_cents'] === -80
        && (string)$reversalAllocations[0]['reversal_of'] === 'phase3-test-allocation'
        && (string)$reversalAllocations[1]['reversal_of'] === 'phase3-test-allocation-2');

    $voidOrderId = 'PHASE3-VOID-ORDER';
    $voidEventNo = 'PHASE3-VOID-EVENT';
    Db::name('cashier_v3_payment_sale_allocation_fact')->insertAll([
        [
            'allocation_fact_id' => 'phase3-void-forward', 'natural_key' => 'phase3-void-forward-natural',
            'contract_version' => 'phase3-test-v1', 'fact_version' => 1, 'status' => 'effective',
            'fact_direction' => 'forward', 'reversal_of' => '', 'tenant_id' => '0',
            'organization_id' => 'phase3-org', 'store_id' => $stores[0], 'member_id' => $memberNew,
            'order_id' => $voidOrderId, 'order_no_snapshot' => $voidOrderId,
            'payment_fact_id' => 'PHASE3-VOID-PAYMENT', 'payment_method' => 'cash',
            'payment_amount_cents' => 500, 'sale_fact_id' => 'PHASE3-VOID-SALE', 'source_line_id' => 'VOID-LINE',
            'sale_amount_cents' => 500, 'debt_amount_cents' => 0, 'allocation_base_amount_cents' => 500,
            'amount_cents' => 500, 'business_date' => $firstDay, 'occurred_at' => $now,
            'settled_at' => $now, 'recorded_at' => $now, 'business_event_no' => 'PHASE3-FORWARD-EVENT',
            'checkout_request_id' => 'PHASE3-VOID-CHECKOUT', 'command_idempotency_key' => 'phase3-void-forward-command',
            'immutable_fingerprint' => hash('sha256', 'phase3-void-forward'), 'add_time' => $now, 'update_time' => $now,
        ],
        [
            'allocation_fact_id' => 'phase3-void-reversal', 'natural_key' => 'phase3-void-reversal-natural',
            'contract_version' => 'phase3-test-v1', 'fact_version' => 1, 'status' => 'effective',
            'fact_direction' => 'reversal', 'reversal_of' => 'phase3-void-forward', 'tenant_id' => '0',
            'organization_id' => 'phase3-org', 'store_id' => $stores[0], 'member_id' => $memberNew,
            'order_id' => $voidOrderId, 'order_no_snapshot' => $voidOrderId,
            'payment_fact_id' => 'PHASE3-VOID-PAYMENT-R', 'payment_method' => 'cash',
            'payment_amount_cents' => -500, 'sale_fact_id' => 'PHASE3-VOID-SALE-R', 'source_line_id' => 'VOID-LINE',
            'sale_amount_cents' => -500, 'debt_amount_cents' => 0, 'allocation_base_amount_cents' => -500,
            'amount_cents' => -500, 'business_date' => $secondDay, 'occurred_at' => $now + 86400,
            'settled_at' => $now + 86400, 'recorded_at' => $now + 86400, 'business_event_no' => $voidEventNo,
            'checkout_request_id' => 'PHASE3-VOID-CHECKOUT', 'command_idempotency_key' => 'phase3-void-reversal-command',
            'immutable_fingerprint' => hash('sha256', 'phase3-void-reversal'), 'add_time' => $now, 'update_time' => $now,
        ],
    ]);
    Db::name('cashier_v3_order_lifecycle_operation')->insert([
        'operation_id' => 'PHASE3-VOID-OP', 'operation_no' => 'P3VOIDOP', 'tenant_id' => '0',
        'store_id' => $stores[0], 'member_id' => $memberNew, 'operator_id' => 7,
        'source_type' => 'sales', 'source_order_id' => $voidOrderId, 'source_order_no_snapshot' => $voidOrderId,
        'operation_type' => 'void', 'command_idempotency_key' => 'phase3-void-operation-command',
        'immutable_fingerprint' => hash('sha256', 'phase3-void-operation'), 'reason_snapshot' => 'test',
        'request_json' => '{}', 'business_event_no' => $voidEventNo, 'amount_cents' => -500,
        'cash_refund_cents' => 0, 'reversed_cash_cents' => 500, 'restored_principal_cents' => 0,
        'restored_bonus_cents' => 0, 'deducted_principal_cents' => 0, 'deducted_bonus_cents' => 0,
        'status' => 'succeeded', 'version' => 1, 'business_date' => $secondDay,
        'occurred_at' => $now + 86400, 'settled_at' => $now + 86400, 'recorded_at' => $now + 86400,
        'created_at' => $now + 86400, 'updated_at' => $now + 86400,
    ]);
    Db::name('cashier_v3_order_lifecycle_financial_reversal')->insert([
        'reversal_id' => 'PHASE3-VOID-REVERSAL', 'operation_id' => 'PHASE3-VOID-OP', 'tenant_id' => '0',
        'store_id' => $stores[0], 'member_id' => $memberNew, 'source_order_id' => $voidOrderId,
        'source_order_no_snapshot' => $voidOrderId, 'reversal_type' => 'void', 'cash_refund_cents' => 0,
        'reversed_cash_cents' => 500, 'restored_principal_cents' => 0, 'restored_bonus_cents' => 0,
        'payment_fact_ids_json' => '[]', 'command_idempotency_key' => 'phase3-void-financial-command',
        'status' => 'succeeded', 'occurred_at' => $now + 86400, 'created_at' => $now + 86400,
    ]);
    $phaseThree = new StoreUnifiedReportPhaseThreeServices();
    $loadPaymentAllocations = \Closure::bind(function (int $storeId, int $memberId, string $start, string $end): array {
        return $this->paymentAllocationQuery([$storeId], ['start' => $start, 'end' => $end], false, [$memberId])
            ->fieldRaw('p.allocation_fact_id,p.fact_direction,p.amount_cents,MAX(financial_reversal.reversal_type) reversal_type')
            ->group('p.id')->order('p.business_date', 'asc')->select()->toArray();
    }, $phaseThree, get_class($phaseThree));
    $voidAllocations = $loadPaymentAllocations($stores[0], $memberNew, $firstDay, $secondDay);
    $storedVoidFacts = (int)Db::name('cashier_v3_payment_sale_allocation_fact')
        ->where('tenant_id', '0')->where('order_id', $voidOrderId)->count();
    phaseThreeMysqlOk('void preserves the original-period amount and appends a dated negative adjustment',
        $storedVoidFacts === 2 && count($voidAllocations) === 2
        && (string)$voidAllocations[0]['allocation_fact_id'] === 'phase3-void-forward'
        && (int)$voidAllocations[0]['amount_cents'] === 500
        && (string)$voidAllocations[1]['allocation_fact_id'] === 'phase3-void-reversal'
        && (int)$voidAllocations[1]['amount_cents'] === -500
        && (string)$voidAllocations[1]['reversal_type'] === 'void');
    $context = [
        'tenant_id' => $tenant, 'store_id' => $stores[0], 'store_ids' => [$stores[0]],
        'operator_id' => 7, 'operator_name' => '集成测试', 'authorization_mode' => 'stores',
    ];
    $base = [
        'store_id' => $stores[0], 'report_code' => 'six_dimension_consumption_refund_detail',
        'subject_type' => 'business_event_line', 'subject_key' => 'payment-allocation:phase3-test-allocation',
        'field_key' => 'complaint_count', 'source_fact_id' => 999,
        'source_order_id' => 'CLIENT-MUST-NOT-WIN', 'source_line_id' => 'CLIENT-MUST-NOT-WIN',
    ];
    $created = $annotations->saveAnnotation($context, $base + [
        'field_value' => '5', 'expected_version' => 0, 'idempotency_key' => 'phase3-test-annotation-create',
    ]);
    $replayed = $annotations->saveAnnotation($context, $base + [
        'field_value' => '5', 'expected_version' => 0, 'idempotency_key' => 'phase3-test-annotation-create',
    ]);
    phaseThreeMysqlOk('complaint count create and idempotent replay preserve one version',
        (int)$created['version'] === 1 && !empty($replayed['replayed']) && (int)$replayed['version'] === 1);
    $updated = $annotations->saveAnnotation($context, $base + [
        'field_value' => '8', 'expected_version' => 1, 'idempotency_key' => 'phase3-test-annotation-update',
    ]);
    $cleared = $annotations->saveAnnotation($context, $base + [
        'field_value' => '', 'expected_version' => 2, 'idempotency_key' => 'phase3-test-annotation-clear',
    ]);
    $originalReplayAfterChanges = $annotations->saveAnnotation($context, $base + [
        'field_value' => '5', 'expected_version' => 0, 'idempotency_key' => 'phase3-test-annotation-create',
    ]);
    $persisted = Db::name('cashier_v3_report_annotation')->where('tenant_id', $tenant)
        ->where('subject_key', 'payment-allocation:phase3-test-allocation')->find();
    phaseThreeMysqlOk('complaint count update and clear retain source lineage and audit version',
        (int)$updated['version'] === 2 && (int)$cleared['version'] === 3
        && (string)$persisted['field_value'] === '' && (int)$persisted['source_fact_id'] === $paymentFactRowId
        && (string)$persisted['source_order_id'] === 'ORDER-101'
        && (string)$persisted['source_line_id'] === 'LINE-101');
    phaseThreeMysqlOk('old complaint idempotency replay returns its original result after later changes',
        !empty($originalReplayAfterChanges['replayed'])
        && (string)$originalReplayAfterChanges['field_value'] === '5'
        && (int)$originalReplayAfterChanges['version'] === 1);
    phaseThreeMysqlOk('negative complaint count is rejected by the backend', phaseThreeMysqlThrows(
        function () use ($annotations, $context, $base): void {
            $annotations->saveAnnotation($context, $base + [
                'field_value' => '-1', 'expected_version' => 3,
                'idempotency_key' => 'phase3-test-annotation-negative',
            ]);
        }, '不能小于 0'
    ));
    phaseThreeMysqlOk('stale complaint count version is rejected', phaseThreeMysqlThrows(
        function () use ($annotations, $context, $base): void {
            $annotations->saveAnnotation($context, $base + [
                'field_value' => '9', 'expected_version' => 1,
                'idempotency_key' => 'phase3-test-annotation-stale',
            ]);
        }, '请刷新后再保存'
    ));
    $wrongScope = $context;
    $wrongScope['store_ids'] = [$stores[1]];
    $wrongScope['store_id'] = $stores[1];
    phaseThreeMysqlOk('complaint count write cannot escape the authenticated store scope', phaseThreeMysqlThrows(
        function () use ($annotations, $wrongScope, $base): void {
            $annotations->saveAnnotation($wrongScope, $base + [
                'field_value' => '9', 'expected_version' => 3,
                'idempotency_key' => 'phase3-test-annotation-out-of-scope',
            ]);
        }, '无权写入该门店报表补充记录'
    ));
    phaseThreeMysqlOk('empty authorized store scope cannot read complaint annotations',
        $annotations->listAnnotations([
            'tenant_id' => $tenant, 'store_ids' => [], 'authorization_mode' => 'stores',
        ], ['report_code' => 'six_dimension_consumption_refund_detail']) === []
    );
    phaseThreeMysqlOk('annotation reads require an explicit registered report code', phaseThreeMysqlThrows(
        function () use ($annotations, $context): void {
            $annotations->listAnnotations($context, []);
        }, '报表类型无效'
    ));

    $matrixStoreCandidates = Db::name('system_store')->where('is_del', 0)->where('name', '<>', '总部')
        ->field('id,name')->order('id', 'asc')->select()->toArray();
    $matrixRawStoreCount = count($matrixStoreCandidates);
    foreach ($matrixStoreCandidates as &$candidate) {
        $candidate['org_id'] = (int)(Db::name('organization_store')
            ->where('store_id', (int)$candidate['id'])->value('org_id') ?? 0);
    }
    unset($candidate);
    $mappedOrganizationIds = array_values(array_unique(array_filter(array_map(
        static fn(array $row): int => (int)$row['org_id'], $matrixStoreCandidates
    ))));
    if (count($mappedOrganizationIds) < 2) {
        foreach (array_slice($matrixStoreCandidates, 0, 2) as $index => $candidate) {
            $organizationId = (int)Db::name('organization')->insertGetId([
                'pid' => 0, 'name' => $matrixFixturePrefix . '组织' . ($index + 1),
                'status' => 1, 'status_reason' => '', 'sort' => 900 + $index,
                'legacy_manage_region_id' => 0, 'is_del' => 0,
                'add_time' => time(), 'update_time' => time(),
            ]);
            $existingMapping = Db::name('organization_store')
                ->where('store_id', (int)$candidate['id'])->find();
            if (is_array($existingMapping)) {
                Db::name('organization_store')->where('id', (int)$existingMapping['id'])
                    ->update(['org_id' => $organizationId, 'add_time' => time()]);
            } else {
                Db::name('organization_store')->insert([
                    'org_id' => $organizationId, 'store_id' => (int)$candidate['id'], 'add_time' => time(),
                ]);
            }
            $matrixStoreCandidates[$index]['org_id'] = $organizationId;
        }
    }
    $matrixStoreCandidates = array_values(array_filter($matrixStoreCandidates,
        static fn(array $row): bool => (int)$row['org_id'] > 0));
    $matrixStores = [];
    foreach ($matrixStoreCandidates as $candidate) {
        if ($matrixStores === [] || (int)$candidate['org_id'] !== (int)$matrixStores[0]['org_id']) {
            $matrixStores[] = $candidate;
        }
        if (count($matrixStores) === 2) break;
    }
    if (count($matrixStores) < 2) {
        $databaseRow = Db::query('SELECT DATABASE() AS database_name');
        throw new RuntimeException('确定性报表样本至少需要两个组织归属不同的门店；候选=' . count($matrixStoreCandidates)
            . '，原始门店=' . $matrixRawStoreCount . '，数据库=' . (string)($databaseRow[0]['database_name'] ?? '-')
            . '，组织=' . implode(',', array_unique(array_map(static fn(array $row): string => (string)$row['org_id'], $matrixStoreCandidates))));
    }
    $matrixStoreIds = array_map(static fn(array $row): int => (int)$row['id'], $matrixStores);
    $organizationPath = static function (int $organizationId): string {
        $ids = [];
        $seen = [];
        for ($guard = 0; $organizationId > 0 && $guard < 64; $guard++) {
            if (isset($seen[$organizationId])) throw new RuntimeException('测试组织路径存在循环');
            $seen[$organizationId] = true;
            $row = Db::name('organization')->where('id', $organizationId)->where('is_del', 0)
                ->field('id,pid')->find();
            if (!is_array($row)) break;
            array_unshift($ids, (string)$row['id']);
            $organizationId = (int)$row['pid'];
        }
        return $ids === [] ? '' : '/' . implode('/', $ids);
    };

    $categorySeed = [
        'type' => 0, 'relation_id' => 0, 'sync_cate_id' => 0, 'sort' => 0,
        'pic' => '', 'is_show' => 1, 'mobile_card_show' => 1, 'add_time' => time(),
        'big_pic' => '', 'adv_pic' => '', 'adv_link' => '',
    ];
    $sixDimensionRootId = (int)(Db::name('store_product_category')->where('pid', 0)
        ->where('is_show', 1)->where('cate_name', '六维')->value('id') ?? 0);
    if ($sixDimensionRootId <= 0) {
        $sixDimensionRootId = (int)Db::name('store_product_category')->insertGetId($categorySeed + [
            'pid' => 0, 'cate_name' => '六维', 'path' => '', 'level' => 0,
        ]);
        $matrixCreatedSixDimensionRoot = true;
    }
    $matrixCategories = [];
    foreach ([
        'direct' => '矩阵直购', 'card' => '矩阵卡项', 'tier' => '矩阵分级',
        'performance' => '矩阵新老客', 'rank' => '矩阵排名',
    ] as $key => $name) {
        $matrixCategories[$key] = (int)Db::name('store_product_category')->insertGetId($categorySeed + [
            'pid' => $sixDimensionRootId, 'cate_name' => $name,
            'path' => (string)$sixDimensionRootId, 'level' => 1,
        ]);
    }

    $saleRows = [];
    $insertSale = static function (
        string $suffix,
        array $store,
        int $memberId,
        string $date,
        int $categoryId,
        int $itemId,
        string $itemName,
        int $quantity,
        int $saleAmountCents,
        int $debtAmountCents,
        string $sourceType,
        int $isExperience,
        string $businessGroup = ''
    ) use (&$saleRows, $organizationPath, $matrixFixturePrefix): array {
        $businessGroup = $businessGroup !== '' ? $businessGroup : $suffix;
        $factId = $matrixFixturePrefix . '-SALE-' . $suffix;
        $eventNo = $matrixFixturePrefix . '-EVENT-' . $businessGroup;
        $orderId = $matrixFixturePrefix . '-ORDER-' . $businessGroup;
        $lineId = $matrixFixturePrefix . '-LINE-' . $suffix;
        $checkoutId = $matrixFixturePrefix . '-CHECKOUT-' . $businessGroup;
        $occurredAt = (int)strtotime($date . ' 12:00:00 Asia/Shanghai');
        $organizationId = (int)$store['org_id'];
        $row = [
            'fact_id' => $factId, 'business_event_no' => $eventNo,
            'fact_type' => 'sale_completed', 'fact_direction' => 'forward',
            'natural_key' => $matrixFixturePrefix . '-NATURAL-' . $suffix,
            'command_idempotency_key' => $matrixFixturePrefix . '-SALE-CMD-' . $suffix,
            'immutable_fingerprint' => hash('sha256', $matrixFixturePrefix . '-SALE-' . $suffix),
            'fact_version' => 1, 'reversal_of' => '', 'status' => 'effective', 'tenant_id' => '0',
            'tenant_name_snapshot' => '矩阵租户', 'organization_id' => (string)$organizationId,
            'organization_name_snapshot' => '矩阵组织',
            'organization_path_snapshot' => $organizationPath($organizationId),
            'store_id' => (int)$store['id'], 'store_name_snapshot' => (string)$store['name'],
            'member_id' => $memberId, 'member_name_snapshot' => '矩阵会员' . $memberId,
            'operator_id' => 0, 'operator_name_snapshot' => '集成测试',
            'business_date' => $date, 'business_timezone' => 'Asia/Shanghai',
            'occurred_at' => $occurredAt, 'settled_at' => $occurredAt, 'recorded_at' => $occurredAt,
            'checkout_request_id' => $checkoutId, 'order_id' => $orderId,
            'order_no_snapshot' => $orderId, 'source_document_type' => 'sales',
            'source_line_id' => $lineId, 'source_type' => $sourceType,
            'item_id' => (string)$itemId, 'item_name_snapshot' => $itemName,
            'category_id_snapshot' => (string)$categoryId, 'category_name_snapshot' => '矩阵分类',
            'quantity' => $quantity, 'original_amount_cents' => $saleAmountCents,
            'discount_amount_cents' => 0, 'sale_amount_cents' => $saleAmountCents,
            'debt_amount_cents' => $debtAmountCents,
        ];
        Db::name('cashier_v3_sale_fact')->insert($row);
        if ($sourceType !== 'card') {
            Db::name('cashier_v3_report_sale_dimension_fact')->insert([
                'tenant_id' => '0', 'store_id' => (int)$store['id'],
                'organization_id' => (string)$organizationId, 'member_id' => $memberId,
                'order_id' => $orderId, 'sale_fact_id' => $factId, 'source_line_id' => $lineId,
                'business_date' => $date, 'item_id' => (string)$itemId,
                'item_name_snapshot' => $itemName, 'product_type_snapshot' => $sourceType,
                'category_id_snapshot' => (string)$categoryId, 'category_name_snapshot' => '矩阵分类',
                'category_parent_id_snapshot' => '', 'category_parent_name_snapshot' => '六维',
                'category_path_snapshot' => '六维 / 矩阵分类', 'partner_name_snapshot' => '',
                'cash_performance_amount_cents' => 0, 'partner_category_id_snapshot' => 0,
                'partner_category_name_snapshot' => '', 'partner_category_path_snapshot' => '',
                'partner_default_ratio_snapshot' => 0, 'partner_config_version_snapshot' => 0,
                'partner_share_amount_cents' => 0, 'is_experience' => $isExperience,
                'created_at' => $occurredAt, 'updated_at' => $occurredAt,
                'immutable_fingerprint' => hash('sha256', $matrixFixturePrefix . '-DIM-' . $suffix),
            ]);
        }
        return $saleRows[$suffix] = $row;
    };
    $insertPayment = static function (
        string $suffix,
        array $sale,
        int $amountCents,
        string $date,
        string $method,
        string $paymentFactId,
        int $paymentAmountCents,
        string $factDirection = 'forward',
        string $reversalOf = '',
        string $businessEventNo = ''
    ) use ($matrixFixturePrefix): array {
        $occurredAt = (int)strtotime($date . ' 13:00:00 Asia/Shanghai');
        $allocationId = $matrixFixturePrefix . '-ALLOC-' . $suffix;
        $row = [
            'allocation_fact_id' => $allocationId,
            'natural_key' => $matrixFixturePrefix . '-ALLOC-NATURAL-' . $suffix,
            'contract_version' => 'phase3-matrix-v1', 'fact_version' => 1, 'status' => 'effective',
            'fact_direction' => $factDirection, 'reversal_of' => $reversalOf, 'tenant_id' => '0',
            'organization_id' => (string)$sale['organization_id'], 'store_id' => (int)$sale['store_id'],
            'member_id' => (int)$sale['member_id'], 'order_id' => (string)$sale['order_id'],
            'order_no_snapshot' => (string)$sale['order_no_snapshot'], 'payment_fact_id' => $paymentFactId,
            'payment_method' => $method, 'payment_amount_cents' => $paymentAmountCents,
            'sale_fact_id' => (string)$sale['fact_id'], 'source_line_id' => (string)$sale['source_line_id'],
            'sale_amount_cents' => (int)$sale['sale_amount_cents'],
            'debt_amount_cents' => (int)$sale['debt_amount_cents'],
            'allocation_base_amount_cents' => abs($amountCents), 'amount_cents' => $amountCents,
            'business_date' => $date, 'occurred_at' => $occurredAt,
            'settled_at' => $occurredAt, 'recorded_at' => $occurredAt,
            'business_event_no' => $businessEventNo !== '' ? $businessEventNo : $matrixFixturePrefix . '-PAY-EVENT-' . $suffix,
            'checkout_request_id' => (string)$sale['checkout_request_id'],
            'command_idempotency_key' => $matrixFixturePrefix . '-PAY-CMD-' . $suffix,
            'immutable_fingerprint' => hash('sha256', $matrixFixturePrefix . '-PAY-' . $suffix),
            'add_time' => $occurredAt, 'update_time' => $occurredAt,
        ];
        Db::name('cashier_v3_payment_sale_allocation_fact')->insert($row);
        return $row;
    };
    $insertService = static function (
        string $suffix,
        array $store,
        int $memberId,
        string $date,
        int $categoryId,
        int $projectId,
        string $projectName,
        int $isExperience
    ) use ($organizationPath, $matrixFixturePrefix): array {
        $occurredAt = (int)strtotime($date . ' 15:00:00 Asia/Shanghai');
        $row = [
            'service_fact_id' => $matrixFixturePrefix . '-SERVICE-' . $suffix,
            'service_record_no' => $matrixFixturePrefix . '-SR-' . $suffix,
            'natural_key' => $matrixFixturePrefix . '-SERVICE-NATURAL-' . $suffix,
            'immutable_fingerprint' => hash('sha256', $matrixFixturePrefix . '-SERVICE-' . $suffix),
            'business_event_no' => $matrixFixturePrefix . '-SERVICE-EVENT-' . $suffix,
            'command_idempotency_key' => $matrixFixturePrefix . '-SERVICE-CMD-' . $suffix,
            'tenant_id' => '0', 'tenant_name_snapshot' => '矩阵租户',
            'organization_id' => (string)$store['org_id'], 'organization_name_snapshot' => '矩阵组织',
            'organization_path_snapshot' => $organizationPath((int)$store['org_id']),
            'store_id' => (int)$store['id'], 'store_name_snapshot' => (string)$store['name'],
            'member_id' => $memberId, 'member_name_snapshot' => '矩阵会员' . $memberId,
            'operator_id' => 0, 'operator_name_snapshot' => '集成测试',
            'business_date' => $date, 'business_timezone' => 'Asia/Shanghai',
            'occurred_at' => $occurredAt, 'settled_at' => $occurredAt, 'recorded_at' => $occurredAt,
            'checkout_request_id' => $matrixFixturePrefix . '-SERVICE-CHECKOUT-' . $suffix,
            'document_id' => $matrixFixturePrefix . '-DOC-' . $suffix,
            'document_no_snapshot' => $matrixFixturePrefix . '-DOC-' . $suffix,
            'source_document_type' => 'matrix', 'source_document_id' => 0,
            'source_line_id' => $matrixFixturePrefix . '-SERVICE-LINE-' . $suffix,
            'project_id' => $projectId, 'project_name_snapshot' => $projectName,
            'project_category_id_snapshot' => $categoryId,
            'project_category_name_snapshot' => '矩阵分类',
            'project_category_path_snapshot' => '六维 / 矩阵分类',
            'quantity' => 1, 'service_object' => 'member', 'is_experience' => $isExperience,
            'labor_amount_cents' => 0, 'labor_fee_amount_cents' => 0,
            'labor_mode' => 'project_rule', 'primary_craftsman_staff_id' => 0,
            'craftsmen_snapshot_json' => json_encode([
                ['employeeId' => 990001, 'employeeName' => '矩阵操作师'],
            ], JSON_UNESCAPED_UNICODE),
            'service_status' => 'completed',
        ];
        Db::name('cashier_v3_entitlement_service_fact')->insert($row);
        return $row;
    };

    $directMember = 990000001;
    $directA = $insertSale('DIRECT-A', $matrixStores[0], $directMember, '2098-01-31',
        $matrixCategories['direct'], 88000001, '矩阵项目A', 2, 10000, 4000, 'project', 1, 'DIRECT');
    $directB = $insertSale('DIRECT-B', $matrixStores[0], $directMember, '2098-01-31',
        $matrixCategories['direct'], 88000002, '矩阵项目B', 1, 5000, 0, 'project', 0, 'DIRECT');
    $insertPayment('DIRECT-A-P1', $directA, 3000, '2098-01-31', 'unionpay',
        $matrixFixturePrefix . '-PAY-GROUP-1', 5000);
    $insertPayment('DIRECT-B-P1', $directB, 2000, '2098-01-31', 'unionpay',
        $matrixFixturePrefix . '-PAY-GROUP-1', 5000);
    $insertPayment('DIRECT-A-P2', $directA, 3000, '2098-01-31', 'wechat',
        $matrixFixturePrefix . '-PAY-GROUP-2', 6000);
    $directBPayment2 = $insertPayment('DIRECT-B-P2', $directB, 3000, '2098-01-31', 'wechat',
        $matrixFixturePrefix . '-PAY-GROUP-2', 6000);
    $insertPayment('DIRECT-A-DEBT', $directA, 4000, '2098-02-01', 'alipay',
        $matrixFixturePrefix . '-PAY-DEBT', 4000);
    $directService = $insertService('DIRECT-A', $matrixStores[0], $directMember, '2098-01-31',
        $matrixCategories['direct'], 88000001, '矩阵项目A', 1);
    $serviceOriginOrderId = 990000900;
    Db::name('cashier_v3_card_purchase_receipt')->insert([
        'receipt_id' => $matrixFixturePrefix . '-SERVICE-RECEIPT', 'tenant_id' => '0',
        'checkout_request_id' => (string)$directA['checkout_request_id'],
        'sales_order_id' => (string)$directA['order_id'],
        'sales_order_line_id' => (string)$directA['source_line_id'], 'issue_no' => 99,
        'command_idempotency_key' => $matrixFixturePrefix . '-SERVICE-RECEIPT-CMD',
        'immutable_fingerprint' => hash('sha256', $matrixFixturePrefix . '-SERVICE-RECEIPT'),
        'contract_version' => 'phase3-matrix-v1', 'store_id' => (int)$matrixStores[0]['id'],
        'member_id' => $directMember, 'catalog_product_id' => 88000001, 'catalog_sku_id' => 0,
        'legacy_order_id' => $serviceOriginOrderId, 'card_holder_id' => 990000900,
        'base_cart_id' => 0, 'benefit_detail_ids_json' => '[]', 'result_snapshot_json' => '{}',
        'status' => 'succeeded', 'occurred_at' => (int)$directA['occurred_at'],
        'settled_at' => (int)$directA['settled_at'], 'recorded_at' => (int)$directA['recorded_at'],
        'add_time' => (int)$directA['recorded_at'], 'update_time' => (int)$directA['recorded_at'],
    ]);
    Db::name('cashier_v3_entitlement_writeoff_fact')->insert([
        'writeoff_id' => $matrixFixturePrefix . '-WRITEOFF-DIRECT-A',
        'natural_key' => $matrixFixturePrefix . '-WRITEOFF-NATURAL-DIRECT-A',
        'immutable_fingerprint' => hash('sha256', $matrixFixturePrefix . '-WRITEOFF-DIRECT-A'),
        'business_event_no' => (string)$directService['business_event_no'],
        'command_idempotency_key' => $matrixFixturePrefix . '-WRITEOFF-CMD-DIRECT-A',
        'tenant_id' => '0', 'tenant_name_snapshot' => '矩阵租户',
        'organization_id' => (string)$directService['organization_id'],
        'organization_name_snapshot' => '矩阵组织',
        'organization_path_snapshot' => (string)$directService['organization_path_snapshot'],
        'store_id' => (int)$directService['store_id'],
        'store_name_snapshot' => (string)$directService['store_name_snapshot'],
        'member_id' => $directMember, 'member_name_snapshot' => '矩阵会员' . $directMember,
        'operator_id' => 0, 'operator_name_snapshot' => '集成测试',
        'business_date' => '2098-01-31', 'business_timezone' => 'Asia/Shanghai',
        'occurred_at' => (int)$directService['occurred_at'],
        'settled_at' => (int)$directService['settled_at'],
        'recorded_at' => (int)$directService['recorded_at'],
        'checkout_request_id' => (string)$directService['checkout_request_id'],
        'document_id' => (string)$directService['document_id'],
        'document_no_snapshot' => (string)$directService['document_no_snapshot'],
        'source_document_type' => 'matrix', 'source_document_id' => 0,
        'source_line_id' => (string)$directService['source_line_id'],
        'entitlement_instance_type' => 'card', 'entitlement_instance_id' => 990000900,
        'source_kind' => 'card', 'is_gift' => 0, 'gift_source_type' => '',
        'gift_id' => 0, 'gift_version' => 0, 'holder_id' => 990000900,
        'origin_order_id' => $serviceOriginOrderId, 'source_name_snapshot' => '矩阵组合卡',
        'source_code_snapshot' => 'P3MATRIX-CARD', 'source_detail_id' => 990000901,
        'project_id' => 88000001, 'project_name_snapshot' => '矩阵项目A',
        'project_category_id_snapshot' => $matrixCategories['direct'],
        'project_category_name_snapshot' => '矩阵直购', 'source_version_snapshot' => 1,
        'detail_version_snapshot' => 1, 'quantity' => 1,
        'actual_entitlement_amount_cents' => 2500, 'purchase_amount_cents_snapshot' => 10000,
        'total_purchase_times_snapshot' => 4, 'consumed_times_before_snapshot' => 0,
        'amount_calculation_version_snapshot' => 'phase3-matrix-v1',
        'service_object' => 'member', 'is_experience' => 1,
        'primary_craftsman_staff_id' => 990001,
        'craftsmen_snapshot_json' => (string)$directService['craftsmen_snapshot_json'],
        'occupation_snapshot_json' => '[]', 'status' => 'effective',
    ]);
    Db::name('cashier_v3_performance_fact')->insert([
        'fact_id' => $matrixFixturePrefix . '-PERFORMANCE-DIRECT-A',
        'business_event_no' => (string)$directService['business_event_no'],
        'fact_type' => 'performance_recorded', 'fact_direction' => 'forward',
        'natural_key' => $matrixFixturePrefix . '-PERFORMANCE-NATURAL-DIRECT-A',
        'command_idempotency_key' => $matrixFixturePrefix . '-PERFORMANCE-CMD-DIRECT-A',
        'immutable_fingerprint' => hash('sha256', $matrixFixturePrefix . '-PERFORMANCE-DIRECT-A'),
        'fact_version' => 1, 'reversal_of' => '', 'status' => 'effective', 'tenant_id' => '0',
        'tenant_name_snapshot' => '矩阵租户', 'organization_id' => (string)$directService['organization_id'],
        'organization_name_snapshot' => '矩阵组织',
        'organization_path_snapshot' => (string)$directService['organization_path_snapshot'],
        'store_id' => (int)$directService['store_id'],
        'store_name_snapshot' => (string)$directService['store_name_snapshot'],
        'member_id' => $directMember, 'member_name_snapshot' => '矩阵会员' . $directMember,
        'operator_id' => 0, 'operator_name_snapshot' => '集成测试',
        'business_date' => '2098-01-31', 'business_timezone' => 'Asia/Shanghai',
        'occurred_at' => (int)$directService['occurred_at'],
        'settled_at' => (int)$directService['settled_at'],
        'recorded_at' => (int)$directService['recorded_at'],
        'checkout_request_id' => (string)$directService['checkout_request_id'],
        'order_id' => (string)$directA['order_id'], 'order_no_snapshot' => (string)$directA['order_no_snapshot'],
        'source_document_type' => 'matrix', 'source_line_id' => (string)$directService['source_line_id'],
        'performance_type' => 'consumption_performance_recorded', 'employee_id' => 0,
        'employee_name_snapshot' => '', 'employee_type_snapshot' => '',
        'employee_type_authority_version' => 0, 'role_snapshot' => 'consumption',
        'allocation_weight_numerator' => 1, 'allocation_weight_denominator' => 1,
        'allocation_base_amount_cents' => 2500, 'amount_cents' => 2500,
        'rule_code_snapshot' => 'phase3_matrix', 'rule_name_snapshot' => '矩阵消耗业绩',
        'rule_version_snapshot' => 'phase3-matrix-v1',
    ]);
    Db::name('cashier_v3_card_operation')->insert([
        'operation_id' => $matrixFixturePrefix . '-CARD-UPGRADE',
        'operation_no' => $matrixFixturePrefix . '-CARD-UPGRADE-NO',
        'operation_type' => 'card_upgrade', 'operation_status' => 'succeeded',
        'contract_version' => 'phase3-matrix-v1', 'tenant_id' => '0',
        'organization_id' => (string)$matrixStores[0]['org_id'],
        'organization_path_snapshot' => $organizationPath((int)$matrixStores[0]['org_id']),
        'organization_name_snapshot' => '矩阵组织', 'store_id' => (int)$matrixStores[0]['id'],
        'store_name_snapshot' => (string)$matrixStores[0]['name'], 'source_card_holder_id' => 990000901,
        'source_card_holder_version' => 1, 'origin_order_id' => $serviceOriginOrderId,
        'origin_member_id' => $directMember, 'member_id_before' => $directMember,
        'member_id_after' => $directMember, 'member_name_before_snapshot' => '矩阵会员' . $directMember,
        'member_name_after_snapshot' => '矩阵会员' . $directMember,
        'card_name_snapshot' => '矩阵升级旧卡', 'card_no_snapshot' => 'P3MATRIX-CARD-001',
        'card_status_before' => 'enabled', 'card_status_after' => 'upgraded',
        'write_end_before' => 0, 'write_end_after' => 0, 'target_catalog_id' => 88000999,
        'target_catalog_name_snapshot' => '矩阵升级新卡', 'target_price_cents' => 10000,
        'source_remaining_value_cents' => 3500, 'settlement_delta_cents' => 6500,
        'checkout_request_id' => $matrixFixturePrefix . '-CARD-UPGRADE-CHECKOUT',
        'reason_snapshot' => '矩阵卡升级',
        'command_idempotency_key' => $matrixFixturePrefix . '-CARD-UPGRADE-CMD',
        'natural_key' => $matrixFixturePrefix . '-CARD-UPGRADE-NATURAL',
        'immutable_fingerprint' => hash('sha256', $matrixFixturePrefix . '-CARD-UPGRADE'),
        'source_snapshot_json' => '{}', 'target_snapshot_json' => '{}', 'result_snapshot_json' => '{}',
        'operator_id' => 0, 'operator_name_snapshot' => '集成测试',
        'business_date' => '2098-02-02', 'business_timezone' => 'Asia/Shanghai',
        'occurred_at' => (int)strtotime('2098-02-02 16:00:00 Asia/Shanghai'),
        'settled_at' => (int)strtotime('2098-02-02 16:00:00 Asia/Shanghai'),
        'recorded_at' => (int)strtotime('2098-02-02 16:00:00 Asia/Shanghai'),
        'add_time' => time(), 'update_time' => time(),
    ]);

    $refundEventNo = $matrixFixturePrefix . '-REFUND-EVENT';
    $refundAllocation = $insertPayment('DIRECT-B-REFUND', $directB, -2000, '2098-02-02', 'unionpay',
        $matrixFixturePrefix . '-PAY-REFUND', -2000, 'reversal',
        (string)$directBPayment2['allocation_fact_id'], $refundEventNo);
    Db::name('cashier_v3_order_lifecycle_operation')->insert([
        'operation_id' => $matrixFixturePrefix . '-REFUND-OP',
        'operation_no' => $matrixFixturePrefix . '-REFUND-OP-NO', 'tenant_id' => '0',
        'store_id' => (int)$matrixStores[0]['id'], 'member_id' => $directMember, 'operator_id' => 0,
        'source_type' => 'sales', 'source_order_id' => (string)$directB['order_id'],
        'source_order_no_snapshot' => (string)$directB['order_no_snapshot'],
        'operation_type' => 'refund',
        'command_idempotency_key' => $matrixFixturePrefix . '-REFUND-OP-CMD',
        'immutable_fingerprint' => hash('sha256', $matrixFixturePrefix . '-REFUND-OP'),
        'reason_snapshot' => '矩阵退款', 'request_json' => '{}', 'business_event_no' => $refundEventNo,
        'amount_cents' => -2000, 'cash_refund_cents' => 2000, 'reversed_cash_cents' => 0,
        'restored_principal_cents' => 0, 'restored_bonus_cents' => 0,
        'deducted_principal_cents' => 0, 'deducted_bonus_cents' => 0,
        'status' => 'succeeded', 'version' => 1, 'business_date' => '2098-02-02',
        'occurred_at' => (int)$refundAllocation['occurred_at'], 'settled_at' => (int)$refundAllocation['settled_at'],
        'recorded_at' => (int)$refundAllocation['recorded_at'], 'created_at' => time(), 'updated_at' => time(),
    ]);
    Db::name('cashier_v3_order_lifecycle_financial_reversal')->insert([
        'reversal_id' => $matrixFixturePrefix . '-REFUND-REVERSAL',
        'operation_id' => $matrixFixturePrefix . '-REFUND-OP', 'tenant_id' => '0',
        'store_id' => (int)$matrixStores[0]['id'], 'member_id' => $directMember,
        'source_order_id' => (string)$directB['order_id'],
        'source_order_no_snapshot' => (string)$directB['order_no_snapshot'],
        'reversal_type' => 'refund', 'cash_refund_cents' => 2000, 'reversed_cash_cents' => 0,
        'restored_principal_cents' => 0, 'restored_bonus_cents' => 0,
        'payment_fact_ids_json' => json_encode([$refundAllocation['payment_fact_id']]),
        'command_idempotency_key' => $matrixFixturePrefix . '-REFUND-REVERSAL-CMD',
        'status' => 'succeeded', 'occurred_at' => (int)$refundAllocation['occurred_at'], 'created_at' => time(),
    ]);

    $cardMember = 990000002;
    $cardSale = $insertSale('CARD', $matrixStores[0], $cardMember, '2098-01-31',
        $matrixCategories['card'], 88000100, '矩阵组合卡', 1, 10000, 3000, 'card', 0);
    foreach ([
        ['C', 88000101, '矩阵卡内项目C', 3, 6000],
        ['D', 88000102, '矩阵卡内项目D', 1, 4000],
    ] as [$suffix, $productId, $name, $count, $amount]) {
        Db::name('cashier_v3_card_sale_item_allocation_fact')->insert([
            'allocation_fact_id' => $matrixFixturePrefix . '-CARD-ITEM-' . $suffix,
            'natural_key' => $matrixFixturePrefix . '-CARD-ITEM-NATURAL-' . $suffix,
            'contract_version' => 'phase3-matrix-v1', 'fact_version' => 1, 'status' => 'effective',
            'reversal_of' => '', 'tenant_id' => '0', 'organization_id' => (string)$cardSale['organization_id'],
            'store_id' => (int)$cardSale['store_id'], 'member_id' => $cardMember,
            'order_id' => (string)$cardSale['order_id'], 'sale_fact_id' => (string)$cardSale['fact_id'],
            'source_line_id' => (string)$cardSale['source_line_id'],
            'card_receipt_id' => $matrixFixturePrefix . '-CARD-RECEIPT', 'card_issue_no' => 1,
            'component_product_id' => $productId, 'item_name_snapshot' => $name,
            'component_count' => $count, 'category_id_snapshot' => $matrixCategories['card'],
            'category_name_snapshot' => '矩阵卡项', 'category_path_snapshot' => '六维 / 矩阵卡项',
            'configured_amount_cents' => $amount, 'sale_amount_cents' => $amount,
            'cash_performance_amount_cents' => $amount, 'business_date' => '2098-01-31',
            'occurred_at' => (int)$cardSale['occurred_at'], 'settled_at' => (int)$cardSale['settled_at'],
            'recorded_at' => (int)$cardSale['recorded_at'],
            'business_event_no' => (string)$cardSale['business_event_no'],
            'command_idempotency_key' => $matrixFixturePrefix . '-CARD-ITEM-CMD-' . $suffix,
            'immutable_fingerprint' => hash('sha256', $matrixFixturePrefix . '-CARD-ITEM-' . $suffix),
            'add_time' => (int)$cardSale['occurred_at'], 'update_time' => (int)$cardSale['occurred_at'],
        ]);
    }
    $insertPayment('CARD-P1', $cardSale, 7000, '2098-01-31', 'unionpay',
        $matrixFixturePrefix . '-CARD-PAY-1', 7000);
    $insertPayment('CARD-DEBT', $cardSale, 3000, '2098-02-01', 'alipay',
        $matrixFixturePrefix . '-CARD-PAY-DEBT', 3000);

    $matrixFoundation = new StoreUnifiedReportPhaseThreeFoundationServices();
    foreach ([
        990000010 => 'SYSTEM_CREATED',
        990000011 => 'IMPORTED',
        990000012 => 'SYSTEM_CREATED',
    ] as $memberId => $originType) {
        $matrixFoundation->recordMemberOrigin(['tenant_id' => '0', 'operator_id' => 0], [
            'member_id' => $memberId, 'origin_type' => $originType,
            'source_type' => 'MYSQL_MATRIX', 'source_id' => (string)$memberId,
            'member_created_at' => StoreUnifiedReportPhaseThreeFoundationServices::V3_CUTOVER_AT + 7200,
            'idempotency_key' => $matrixFixturePrefix . '-ORIGIN-' . $memberId,
        ]);
    }
    $performanceNew1 = $insertSale('PERF-NEW-1', $matrixStores[0], 990000010, '2098-02-10',
        $matrixCategories['performance'], 88000201, '矩阵新老客项目', 1, 10000, 0, 'project', 1);
    $performanceNew2 = $insertSale('PERF-NEW-2', $matrixStores[0], 990000010, '2098-02-11',
        $matrixCategories['performance'], 88000201, '矩阵新老客项目', 1, 5000, 0, 'project', 1);
    $performanceImported = $insertSale('PERF-IMPORTED', $matrixStores[0], 990000011, '2098-02-10',
        $matrixCategories['performance'], 88000201, '矩阵新老客项目', 1, 7000, 0, 'project', 1);
    $insertSale('PERF-GIFT', $matrixStores[0], 990000012, '2098-02-10',
        $matrixCategories['performance'], 88000201, '矩阵新老客项目', 1, 0, 0, 'project', 1);
    $insertPayment('PERF-NEW-1', $performanceNew1, 10000, '2098-02-10', 'unionpay',
        $matrixFixturePrefix . '-PERF-PAY-NEW-1', 10000);
    $insertPayment('PERF-NEW-2', $performanceNew2, 5000, '2098-02-11', 'wechat',
        $matrixFixturePrefix . '-PERF-PAY-NEW-2', 5000);
    $insertPayment('PERF-IMPORTED', $performanceImported, 7000, '2098-02-10', 'alipay',
        $matrixFixturePrefix . '-PERF-PAY-IMPORTED', 7000);

    foreach ([
        990000020 => [499999, 'TIER-LOW'],
        990000021 => [500000, 'TIER-BOUNDARY'],
        990000022 => [-100, 'TIER-NEGATIVE'],
    ] as $memberId => [$amount, $suffix]) {
        $tierSale = $insertSale($suffix, $matrixStores[0], $memberId, '2098-03-01',
            $matrixCategories['tier'], 88000300 + ($memberId % 100), '矩阵分级项目' . $suffix,
            1, max(0, $amount), 0, 'project', 0);
        $insertPayment($suffix, $tierSale, $amount, '2098-03-01', 'unionpay',
            $matrixFixturePrefix . '-TIER-PAY-' . $suffix, $amount);
        $insertService($suffix, $matrixStores[0], $memberId, '2098-03-01',
            $matrixCategories['tier'], 88000300 + ($memberId % 100), '矩阵分级项目' . $suffix, 0);
        $matrixFoundation->recordMemberStoreAssignmentInTx(['tenant_id' => '0'], [
            'member_id' => $memberId, 'assigned' => true, 'store_id' => (int)$matrixStores[0]['id'],
            'effective_at' => (int)strtotime('2098-02-28 12:00:00 Asia/Shanghai'),
            'source_type' => 'MYSQL_MATRIX', 'source_event_id' => $matrixFixturePrefix . '-ASSIGN-' . $memberId,
            'idempotency_key' => $matrixFixturePrefix . '-ASSIGN-' . $memberId,
        ]);
    }

    foreach ($matrixStores as $index => $store) {
        Db::name('cashier_v3_report_organization_dimension')->insert([
            'tenant_id' => '0', 'organization_id' => (string)$store['org_id'],
            'organization_name_snapshot' => '矩阵城市经理' . ($index + 1),
            'dimension_code' => 'city_manager', 'display_order' => 900 + $index,
            'valid_from' => '2098-01-01', 'valid_to' => '2098-12-31', 'enabled' => 1,
            'version' => 1, 'created_by' => 0, 'updated_by' => 0,
            'created_at' => time(), 'updated_at' => time(),
        ]);
        Db::name('cashier_v3_report_organization_dimension')->insert([
            'tenant_id' => '0', 'organization_id' => (string)$store['org_id'],
            'organization_name_snapshot' => '矩阵分公司' . ($index + 1),
            'dimension_code' => 'company', 'display_order' => 900 + $index,
            'valid_from' => '2098-01-01', 'valid_to' => '2098-12-31', 'enabled' => 1,
            'version' => 1, 'created_by' => 0, 'updated_by' => 0,
            'created_at' => time(), 'updated_at' => time(),
        ]);
        foreach ([['2098-09-15', 1000000, 'PREV'], ['2098-10-15', 500000, 'CURR']] as [$date, $amount, $period]) {
            $rankSale = $insertSale('RANK-' . $index . '-' . $period, $store, 0, $date,
                $matrixCategories['rank'], 88000400 + $index, '矩阵排名项目' . ($index + 1),
                1, $amount, 0, 'project', 0);
            $insertPayment('RANK-' . $index . '-' . $period, $rankSale, $amount, $date, 'unionpay',
                $matrixFixturePrefix . '-RANK-PAY-' . $index . '-' . $period, $amount);
        }
    }

    $matrixService = new StoreUnifiedReportPhaseThreeServices();
    $matrixInput = [
        'page' => 1, 'limit' => 100, '_internal_all' => true,
        '_authorized_store_ids' => $matrixStoreIds, '_report_scope' => ['mode' => 'all'],
    ];
    $rowsBy = static function (array $records, string $key): array {
        $result = [];
        foreach ($records as $row) $result[(string)($row[$key] ?? '')] = $row;
        return $result;
    };

    $directResult = $matrixService->query('six_dimension_item_deal_analysis', [$matrixStoreIds[0]],
        ['start' => '2098-01-31', 'end' => '2098-02-02'],
        $matrixInput + ['category_id' => $matrixCategories['direct']]);
    $directRows = $rowsBy((array)$directResult['records'], 'item_name');
    $directAResult = (array)($directRows['矩阵项目A'] ?? []);
    $directBResult = (array)($directRows['矩阵项目B'] ?? []);
    phaseThreeMysqlOk('item report fields reconcile multi-product, multi-payment, debt collection and refund',
        count($directRows) === 2
        && (int)($directAResult['store_id'] ?? 0) === $matrixStoreIds[0]
        && (string)($directAResult['store_name'] ?? '') === (string)$matrixStores[0]['name']
        && trim((string)($directAResult['company_name'] ?? '')) !== ''
        && (int)($directAResult['experience_people'] ?? -1) === 1
        && (int)($directAResult['purchase_people'] ?? -1) === 1
        && (string)($directAResult['conversion_rate'] ?? '') === '100%'
        && (int)($directAResult['purchase_count'] ?? -1) === 2
        && (string)($directAResult['purchase_amount'] ?? '') === '100'
        && (string)($directAResult['average_sale_price'] ?? '') === '50'
        && (string)($directAResult['average_unit_output'] ?? '') === '100'
        && (int)($directBResult['experience_people'] ?? -1) === 0
        && (int)($directBResult['purchase_people'] ?? -1) === 1
        && (string)($directBResult['conversion_rate'] ?? '') === '-'
        && (int)($directBResult['purchase_count'] ?? -1) === 1
        && (string)($directBResult['purchase_amount'] ?? '') === '30'
        && (string)($directBResult['average_sale_price'] ?? '') === '30'
        && (string)($directBResult['average_unit_output'] ?? '') === '30',
        json_encode($directRows, JSON_UNESCAPED_UNICODE));
    phaseThreeMysqlOk('item report summary uses net full-result values',
        (int)($directResult['summary_row']['experience_people'] ?? -1) === 1
        && (int)($directResult['summary_row']['purchase_people'] ?? -1) === 2
        && (int)($directResult['summary_row']['purchase_count'] ?? -1) === 3
        && (string)($directResult['summary_row']['purchase_amount'] ?? '') === '130');

    $januaryResult = $matrixService->query('six_dimension_item_deal_analysis', [$matrixStoreIds[0]],
        ['start' => '2098-01-31', 'end' => '2098-01-31'],
        $matrixInput + ['category_id' => $matrixCategories['direct']]);
    $februaryResult = $matrixService->query('six_dimension_item_deal_analysis', [$matrixStoreIds[0]],
        ['start' => '2098-02-01', 'end' => '2098-02-02'],
        $matrixInput + ['category_id' => $matrixCategories['direct']]);
    $januaryRows = $rowsBy((array)$januaryResult['records'], 'item_name');
    $februaryRows = $rowsBy((array)$februaryResult['records'], 'item_name');
    phaseThreeMysqlOk('debt and refund enter their actual business dates across months',
        (string)($januaryRows['矩阵项目A']['purchase_amount'] ?? '') === '60'
        && (string)($januaryRows['矩阵项目B']['purchase_amount'] ?? '') === '50'
        && (string)($februaryRows['矩阵项目A']['purchase_amount'] ?? '') === '40'
        && (string)($februaryRows['矩阵项目B']['purchase_amount'] ?? '') === '-20');

    $paymentMatrix = Db::name('cashier_v3_payment_sale_allocation_fact')
        ->where('tenant_id', '0')->whereLike('allocation_fact_id', $matrixFixturePrefix . '-ALLOC-DIRECT-%')
        ->field('payment_fact_id,payment_method,source_line_id,business_date,amount_cents')
        ->order('payment_fact_id', 'asc')->order('source_line_id', 'asc')->select()->toArray();
    $groupedPayments = [];
    foreach ($paymentMatrix as $row) $groupedPayments[(string)$row['payment_fact_id']][] = $row;
    phaseThreeMysqlOk('multi-product and multi-payment facts retain line allocation and debt date',
        count((array)($groupedPayments[$matrixFixturePrefix . '-PAY-GROUP-1'] ?? [])) === 2
        && count((array)($groupedPayments[$matrixFixturePrefix . '-PAY-GROUP-2'] ?? [])) === 2
        && count(array_unique(array_column(
            (array)$groupedPayments[$matrixFixturePrefix . '-PAY-GROUP-1'], 'source_line_id'
        ))) === 2
        && array_sum(array_map(static fn(array $row): int => (int)$row['amount_cents'],
            (array)$groupedPayments[$matrixFixturePrefix . '-PAY-GROUP-1'])) === 5000
        && array_sum(array_map(static fn(array $row): int => (int)$row['amount_cents'],
            (array)$groupedPayments[$matrixFixturePrefix . '-PAY-GROUP-2'])) === 6000
        && (string)($groupedPayments[$matrixFixturePrefix . '-PAY-DEBT'][0]['business_date'] ?? '') === '2098-02-01');

    $detailResult = $matrixService->query('six_dimension_consumption_refund_detail', [$matrixStoreIds[0]],
        ['start' => '2098-01-31', 'end' => '2098-02-02'],
        $matrixInput + ['item_id' => 88000002]);
    $refundRows = array_values(array_filter((array)$detailResult['records'], static fn(array $row): bool =>
        (string)($row['refund_amount'] ?? '0') !== '0'));
    $receivedRows = array_values(array_filter((array)$detailResult['records'], static fn(array $row): bool =>
        (string)($row['received_amount'] ?? '0') !== '0'));
    phaseThreeMysqlOk('detail report keeps payment and refund as independent event rows',
        count($receivedRows) === 2 && count($refundRows) === 1
        && (string)$refundRows[0]['store_name'] === (string)$matrixStores[0]['name']
        && (string)$refundRows[0]['craftsman'] === '-'
        && (string)$refundRows[0]['item_name'] === '矩阵项目B'
        && (string)$refundRows[0]['purchase_date'] === '2098-01-31'
        && (string)$refundRows[0]['consumption_amount'] === '0'
        && (string)$refundRows[0]['consultant'] === '-'
        && (string)$refundRows[0]['received_amount'] === '0'
        && (string)$refundRows[0]['transfer_amount'] === '0'
        && (string)$refundRows[0]['complaint_count'] === ''
        && (string)$refundRows[0]['refund_amount'] === '20'
        && (string)($detailResult['summary_row']['received_amount'] ?? '') === '50'
        && (string)($detailResult['summary_row']['refund_amount'] ?? '') === '20');

    $eventDetailResult = $matrixService->query('six_dimension_consumption_refund_detail', [$matrixStoreIds[0]],
        ['start' => '2098-01-31', 'end' => '2098-02-02'], $matrixInput);
    $serviceRows = array_values(array_filter((array)$eventDetailResult['records'], static fn(array $row): bool =>
        (string)($row['consumption_amount'] ?? '0') !== '0'));
    $transferRows = array_values(array_filter((array)$eventDetailResult['records'], static fn(array $row): bool =>
        (string)($row['transfer_amount'] ?? '0') !== '0'));
    phaseThreeMysqlOk('detail report reads service consumption from performance fact and keeps event amounts exclusive',
        count($serviceRows) === 1
        && (string)$serviceRows[0]['craftsman'] === '矩阵操作师'
        && (string)$serviceRows[0]['item_name'] === '矩阵项目A'
        && (string)$serviceRows[0]['purchase_date'] === '2098-01-31'
        && (string)$serviceRows[0]['consumption_amount'] === '25'
        && (string)$serviceRows[0]['received_amount'] === '0'
        && (string)$serviceRows[0]['transfer_amount'] === '0'
        && (string)$serviceRows[0]['refund_amount'] === '0',
        json_encode($serviceRows, JSON_UNESCAPED_UNICODE));
    phaseThreeMysqlOk('detail report reads card-upgrade remaining value as transfer performance',
        count($transferRows) === 1
        && (string)$transferRows[0]['craftsman'] === '-'
        && (string)$transferRows[0]['item_name'] === '矩阵升级旧卡'
        && (string)$transferRows[0]['consumption_amount'] === '0'
        && (string)$transferRows[0]['received_amount'] === '0'
        && (string)$transferRows[0]['transfer_amount'] === '35'
        && (string)$transferRows[0]['refund_amount'] === '0'
        && (string)($eventDetailResult['summary_row']['consumption_amount'] ?? '') === '25'
        && (string)($eventDetailResult['summary_row']['transfer_amount'] ?? '') === '35',
        json_encode($transferRows, JSON_UNESCAPED_UNICODE));

    $matrixAnnotationContext = [
        'tenant_id' => '0', 'store_id' => $matrixStoreIds[0], 'store_ids' => [$matrixStoreIds[0]],
        'operator_id' => 7, 'operator_name' => '集成测试', 'authorization_mode' => 'stores',
    ];
    foreach ([[$serviceRows[0], '6', 'SERVICE'], [$transferRows[0], '7', 'TRANSFER']] as [$eventRow, $value, $suffix]) {
        $annotations->saveAnnotation($matrixAnnotationContext, [
            'store_id' => $matrixStoreIds[0],
            'report_code' => 'six_dimension_consumption_refund_detail',
            'subject_type' => 'business_event_line',
            'subject_key' => (string)$eventRow['annotation_subject_key'],
            'field_key' => 'complaint_count', 'field_value' => $value, 'expected_version' => 0,
            'idempotency_key' => $matrixFixturePrefix . '-ANNOTATION-' . $suffix,
            'source_fact_id' => 999, 'source_order_id' => 'CLIENT-MUST-NOT-WIN',
            'source_line_id' => 'CLIENT-MUST-NOT-WIN',
        ]);
    }
    $eventDetailAfterAnnotation = $matrixService->query(
        'six_dimension_consumption_refund_detail', [$matrixStoreIds[0]],
        ['start' => '2098-01-31', 'end' => '2098-02-02'], $matrixInput
    );
    $matrixPageInput = $matrixInput;
    unset($matrixPageInput['_internal_all']);
    $eventDetailPageAfterAnnotation = $matrixService->query(
        'six_dimension_consumption_refund_detail', [$matrixStoreIds[0]],
        ['start' => '2098-01-31', 'end' => '2098-02-02'], $matrixPageInput
    );
    $annotatedBySubject = $rowsBy((array)$eventDetailAfterAnnotation['records'], 'annotation_subject_key');
    $pageAnnotatedBySubject = $rowsBy((array)$eventDetailPageAfterAnnotation['records'], 'annotation_subject_key');
    phaseThreeMysqlOk('complaint count saves and refreshes on service and transfer event rows',
        (string)($annotatedBySubject[(string)$serviceRows[0]['annotation_subject_key']]['complaint_count'] ?? '') === '6'
        && (string)($annotatedBySubject[(string)$transferRows[0]['annotation_subject_key']]['complaint_count'] ?? '') === '7'
        && (string)($pageAnnotatedBySubject[(string)$serviceRows[0]['annotation_subject_key']]['complaint_count'] ?? '') === '6'
        && $eventDetailPageAfterAnnotation['columns'] === $eventDetailAfterAnnotation['columns']
        && $eventDetailPageAfterAnnotation['summary_row'] === $eventDetailAfterAnnotation['summary_row']
        && (string)($eventDetailAfterAnnotation['summary_row']['complaint_count'] ?? '') === '13');
    $annotations->saveAnnotation($matrixAnnotationContext, [
        'store_id' => $matrixStoreIds[0],
        'report_code' => 'six_dimension_consumption_refund_detail',
        'subject_type' => 'business_event_line',
        'subject_key' => (string)$serviceRows[0]['annotation_subject_key'],
        'field_key' => 'complaint_count', 'field_value' => '', 'expected_version' => 1,
        'idempotency_key' => $matrixFixturePrefix . '-ANNOTATION-SERVICE-CLEAR',
        'source_fact_id' => 999, 'source_order_id' => 'CLIENT-MUST-NOT-WIN',
        'source_line_id' => 'CLIENT-MUST-NOT-WIN',
    ]);
    $eventDetailAfterClear = $matrixService->query(
        'six_dimension_consumption_refund_detail', [$matrixStoreIds[0]],
        ['start' => '2098-01-31', 'end' => '2098-02-02'], $matrixInput
    );
    $clearedBySubject = $rowsBy((array)$eventDetailAfterClear['records'], 'annotation_subject_key');
    phaseThreeMysqlOk('complaint count clear survives refresh without affecting another event row',
        (string)($clearedBySubject[(string)$serviceRows[0]['annotation_subject_key']]['complaint_count'] ?? 'missing') === ''
        && (string)($clearedBySubject[(string)$transferRows[0]['annotation_subject_key']]['complaint_count'] ?? '') === '7'
        && (string)($eventDetailAfterClear['summary_row']['complaint_count'] ?? '') === '7');

    $cardResult = $matrixService->query('six_dimension_item_deal_analysis', [$matrixStoreIds[0]],
        ['start' => '2098-01-31', 'end' => '2098-02-01'],
        $matrixInput + ['category_id' => $matrixCategories['card']]);
    $cardRows = $rowsBy((array)$cardResult['records'], 'item_name');
    phaseThreeMysqlOk('card sale expands contained projects with weighted cash and counts',
        count($cardRows) === 2
        && (int)($cardRows['矩阵卡内项目C']['experience_people'] ?? -1) === 0
        && (int)($cardRows['矩阵卡内项目C']['purchase_people'] ?? -1) === 1
        && (string)($cardRows['矩阵卡内项目C']['conversion_rate'] ?? '') === '-'
        && (int)($cardRows['矩阵卡内项目C']['purchase_count'] ?? -1) === 3
        && (string)($cardRows['矩阵卡内项目C']['purchase_amount'] ?? '') === '60'
        && (string)($cardRows['矩阵卡内项目C']['average_sale_price'] ?? '') === '20'
        && (string)($cardRows['矩阵卡内项目C']['average_unit_output'] ?? '') === '60'
        && (int)($cardRows['矩阵卡内项目D']['purchase_people'] ?? -1) === 1
        && (int)($cardRows['矩阵卡内项目D']['purchase_count'] ?? -1) === 1
        && (string)($cardRows['矩阵卡内项目D']['purchase_amount'] ?? '') === '40'
        && (string)($cardRows['矩阵卡内项目D']['average_sale_price'] ?? '') === '40'
        && (string)($cardRows['矩阵卡内项目D']['average_unit_output'] ?? '') === '40',
        json_encode($cardRows, JSON_UNESCAPED_UNICODE));

    $performanceResult = $matrixService->query('six_dimension_performance_deal', [$matrixStoreIds[0]],
        ['start' => '2098-02-10', 'end' => '2098-02-11'],
        $matrixInput + ['category_id' => $matrixCategories['performance']]);
    $performanceRow = (array)($performanceResult['records'][0] ?? []);
    phaseThreeMysqlOk('performance deal classifies system-created first purchase and imported/ repeat purchases',
        count((array)$performanceResult['records']) === 1
        && (int)($performanceRow['new_visit_purchase'] ?? -1) === 1
        && (int)($performanceRow['new_visit_gift'] ?? -1) === 1
        && (int)($performanceRow['old_visit_people'] ?? -1) === 2
        && (int)($performanceRow['new_deal_purchase'] ?? -1) === 1
        && (int)($performanceRow['old_deal_people'] ?? -1) === 2
        && (int)($performanceRow['new_deal_gift'] ?? -1) === 1
        && (string)($performanceRow['new_performance_purchase'] ?? '') === '100'
        && (string)($performanceRow['old_performance'] ?? '') === '120'
        && (string)($performanceRow['new_performance_gift'] ?? '') === '0'
        && (string)($performanceRow['new_unit_output_purchase'] ?? '') === '100'
        && (string)($performanceRow['old_unit_output'] ?? '') === '60'
        && (string)($performanceRow['new_unit_output_gift'] ?? '') === '0',
        json_encode($performanceRow, JSON_UNESCAPED_UNICODE));

    $tierResult = $matrixService->query('six_dimension_cash_consumption_analysis', [$matrixStoreIds[0]],
        ['start' => '2098-03-01', 'end' => '2098-03-01'],
        $matrixInput + ['category_id' => $matrixCategories['tier']]);
    $tierRows = $rowsBy((array)$tierResult['records'], 'consumption_tier');
    phaseThreeMysqlOk('cash consumption tier uses left-closed boundary and clamps negative total to lowest tier',
        count($tierRows) === 2
        && (int)($tierRows['0-5000']['store_id'] ?? 0) === $matrixStoreIds[0]
        && (string)($tierRows['0-5000']['store_name'] ?? '') === (string)$matrixStores[0]['name']
        && (int)($tierRows['0-5000']['accumulated_consumers'] ?? -1) === 2
        && (string)($tierRows['0-5000']['people_share'] ?? '') === '66.67%'
        && (string)($tierRows['0-5000']['category_consumption_amount'] ?? '') === '4998.99'
        && (string)($tierRows['0-5000']['total_consumption_amount'] ?? '') === '4998.99'
        && (string)($tierRows['0-5000']['category_consumption_share'] ?? '') === '100%'
        && (int)($tierRows['5000-10000']['accumulated_consumers'] ?? -1) === 1
        && (string)($tierRows['5000-10000']['people_share'] ?? '') === '33.33%'
        && (string)($tierRows['5000-10000']['category_consumption_amount'] ?? '') === '5000'
        && (string)($tierRows['5000-10000']['total_consumption_amount'] ?? '') === '5000'
        && (string)($tierRows['5000-10000']['category_consumption_share'] ?? '') === '100%',
        json_encode($tierRows, JSON_UNESCAPED_UNICODE));

    $rankResult = $matrixService->query('six_dimension_performance_distribution', $matrixStoreIds,
        ['start' => '2098-10-01', 'end' => '2098-10-31'], $matrixInput);
    $rankRows = $rowsBy((array)$rankResult['records'], 'city_manager');
    phaseThreeMysqlOk('city-manager targets and dense ranks give equal rates the same rank',
        count($rankRows) === 2
        && (int)($rankRows['矩阵城市经理1']['store_count'] ?? -1) === 1
        && (string)($rankRows['矩阵城市经理1']['previous_completed'] ?? '') === '10000'
        && (string)($rankRows['矩阵城市经理1']['previous_target'] ?? '') === '20000'
        && (string)($rankRows['矩阵城市经理1']['previous_rate'] ?? '') === '50%'
        && (int)($rankRows['矩阵城市经理1']['previous_rank'] ?? -1) === 1
        && (string)($rankRows['矩阵城市经理1']['current_completed'] ?? '') === '5000'
        && (string)($rankRows['矩阵城市经理1']['current_target'] ?? '') === '20000'
        && (string)($rankRows['矩阵城市经理1']['current_rate'] ?? '') === '25%'
        && (int)($rankRows['矩阵城市经理1']['current_rank'] ?? -1) === 1
        && (int)($rankRows['矩阵城市经理2']['previous_rank'] ?? -1) === 1
        && (string)($rankRows['矩阵城市经理2']['previous_completed'] ?? '') === '10000'
        && (string)($rankRows['矩阵城市经理2']['previous_target'] ?? '') === '20000'
        && (string)($rankRows['矩阵城市经理2']['previous_rate'] ?? '') === '50%'
        && (int)($rankRows['矩阵城市经理2']['current_rank'] ?? -1) === 1
        && (string)($rankRows['矩阵城市经理2']['current_completed'] ?? '') === '5000'
        && (string)($rankRows['矩阵城市经理2']['current_target'] ?? '') === '20000'
        && (string)($rankRows['矩阵城市经理2']['current_rate'] ?? '') === '25%',
        json_encode($rankRows, JSON_UNESCAPED_UNICODE));

    $marketResult = $matrixService->query('six_dimension_performance_market_distribution', $matrixStoreIds,
        ['start' => '2098-09-01', 'end' => '2098-10-31'], $matrixInput);
    $marketRows = $rowsBy((array)$marketResult['records'], 'month');
    $companyOnePrefix = 'company_' . (string)$matrixStores[0]['org_id'];
    $companyTwoPrefix = 'company_' . (string)$matrixStores[1]['org_id'];
    phaseThreeMysqlOk('market distribution reconciles monthly group, dynamic company amounts and shares',
        count($marketRows) === 2
        && (string)($marketRows['2098-09']['group_total'] ?? '') === '20000'
        && (string)($marketRows['2098-09'][$companyOnePrefix . '_amount'] ?? '') === '10000'
        && (string)($marketRows['2098-09'][$companyOnePrefix . '_share'] ?? '') === '50%'
        && (string)($marketRows['2098-09'][$companyTwoPrefix . '_amount'] ?? '') === '10000'
        && (string)($marketRows['2098-09'][$companyTwoPrefix . '_share'] ?? '') === '50%'
        && (string)($marketRows['2098-10']['group_total'] ?? '') === '10000'
        && (string)($marketRows['2098-10'][$companyOnePrefix . '_amount'] ?? '') === '5000'
        && (string)($marketRows['2098-10'][$companyOnePrefix . '_share'] ?? '') === '50%'
        && (string)($marketRows['2098-10'][$companyTwoPrefix . '_amount'] ?? '') === '5000'
        && (string)($marketRows['2098-10'][$companyTwoPrefix . '_share'] ?? '') === '50%',
        json_encode($marketRows, JSON_UNESCAPED_UNICODE));
} catch (Throwable $error) {
    $failed++;
    echo 'FAIL unexpected exception: ' . get_class($error) . ': ' . $error->getMessage() . "\n";
} finally {
    Db::rollback();
}

$matrixResiduals = 0;
$matrixResiduals += (int)Db::name('cashier_v3_sale_fact')->whereLike('fact_id', $matrixFixturePrefix . '-%')->count();
$matrixResiduals += (int)Db::name('cashier_v3_payment_sale_allocation_fact')
    ->whereLike('allocation_fact_id', $matrixFixturePrefix . '-%')->count();
$matrixResiduals += (int)Db::name('cashier_v3_card_sale_item_allocation_fact')
    ->whereLike('allocation_fact_id', $matrixFixturePrefix . '-%')->count();
$matrixResiduals += (int)Db::name('cashier_v3_report_sale_dimension_fact')
    ->whereLike('sale_fact_id', $matrixFixturePrefix . '-%')->count();
$matrixResiduals += (int)Db::name('cashier_v3_entitlement_service_fact')
    ->whereLike('service_fact_id', $matrixFixturePrefix . '-%')->count();
$matrixResiduals += (int)Db::name('cashier_v3_entitlement_writeoff_fact')
    ->whereLike('writeoff_id', $matrixFixturePrefix . '-%')->count();
$matrixResiduals += (int)Db::name('cashier_v3_performance_fact')
    ->whereLike('fact_id', $matrixFixturePrefix . '-%')->count();
$matrixResiduals += (int)Db::name('cashier_v3_card_purchase_receipt')
    ->whereLike('receipt_id', $matrixFixturePrefix . '-%')->count();
$matrixResiduals += (int)Db::name('cashier_v3_card_operation')
    ->whereLike('operation_id', $matrixFixturePrefix . '-%')->count();
$matrixResiduals += (int)Db::name('cashier_v3_card_operation_line')
    ->whereLike('operation_line_id', $matrixFixturePrefix . '-%')->count();
$matrixResiduals += (int)Db::name('cashier_v3_order_lifecycle_operation')
    ->whereLike('operation_id', $matrixFixturePrefix . '-%')->count();
$matrixResiduals += (int)Db::name('cashier_v3_order_lifecycle_financial_reversal')
    ->whereLike('reversal_id', $matrixFixturePrefix . '-%')->count();
$matrixResiduals += (int)Db::name('cashier_v3_report_member_origin_evidence')
    ->whereLike('idempotency_key', $matrixFixturePrefix . '-%')->count();
$matrixResiduals += (int)Db::name('cashier_v3_report_member_store_assignment_period')
    ->whereLike('idempotency_key', $matrixFixturePrefix . '-%')->count();
$matrixResiduals += (int)Db::name('cashier_v3_report_organization_dimension')
    ->whereLike('organization_name_snapshot', '矩阵%')->count();
$matrixResiduals += (int)Db::name('store_product_category')->whereLike('cate_name', '矩阵%')->count();
if ($matrixCreatedSixDimensionRoot) {
    $matrixResiduals += (int)Db::name('store_product_category')->where('pid', 0)
        ->where('cate_name', '六维')->count();
}
phaseThreeMysqlOk('deterministic report matrix rolls back every fact and projection', $matrixResiduals === 0,
    'residual_rows=' . $matrixResiduals);

echo "PHASE3_MYSQL_INTEGRATION passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
