<?php
declare(strict_types=1);

require '/tests/cashier-v3/lib/boot-env.php';
require '/var/www/html/vendor/autoload.php';
require '/tests/cashier-v3/lib/_lib.php';
require '/tests/cashier-v3/lib/TestGraphFactory.php';
require '/tests/cashier-v3/lib/MemberIntegrationFixture.php';

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\bootstrap\CashierV3Bootstrap;
use app\services\cashier\v3\settlement\CashierV3CheckoutProjectionServices;
use app\services\cashier\v3\settlement\ThinkPhpCashierV3CheckoutResultReadRepository;
use C1A\CashierV3\Test\MemberIntegrationFixture;
use think\facade\Db;

c1aBootThinkApp('/var/www/html/');
MemberIntegrationFixture::bindTestAdapters();
MemberIntegrationFixture::ensureLegacySchema();

$passed = 0;
$failed = 0;

function mixedOk(string $name, bool $condition, string $detail = ''): void
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

function mixedKey(string $prefix): string
{
    return $prefix . '-' . MemberIntegrationFixture::uuid();
}

function mixedSessionBody(array $session, string $action, array $payload = []): array
{
    return array_merge($payload, [
        'action' => $action,
        'clientSessionId' => $session['client_session_id'],
        'stateContextId' => $session['state_context_id'],
        'correlationId' => mixedKey('CORR'),
    ]);
}

function mixedCommandBody(
    array $session,
    string $action,
    array $payload,
    array $contexts,
    string $idempotencyKey
): array {
    $body = mixedSessionBody($session, $action, $payload);
    $body['command'] = [
        'action' => $action,
        'idempotencyKey' => $idempotencyKey,
        'contexts' => array_values($contexts),
    ];
    return $body;
}

function mixedWorkspaceContexts(string $workspaceId, string $requestId = ''): array
{
    $workspaceVersion = (int)Db::name('cashier_v3_resource_version')
        ->where('resource_kind', 'cashier_workspace')
        ->where('resource_id', $workspaceId)
        ->value('current_version');
    if ($workspaceVersion <= 0) {
        throw new RuntimeException('MIXED_WORKSPACE_VERSION_MISSING');
    }
    $contexts = [[
        'kind' => 'cashier_workspace',
        'id' => $workspaceId,
        'expectedVersion' => $workspaceVersion,
    ]];
    if ($requestId !== '') {
        $requestVersion = (int)Db::name('cashier_v3_checkout_request')
            ->where('request_id', $requestId)
            ->value('request_version');
        if ($requestVersion <= 0) {
            throw new RuntimeException('MIXED_REQUEST_VERSION_MISSING');
        }
        $contexts[] = [
            'kind' => 'checkout_request',
            'id' => $requestId,
            'expectedVersion' => $requestVersion,
        ];
    }
    return $contexts;
}

function mixedSelectionContexts(array $selector, int $holderId, int $detailId): array
{
    return array_values(array_filter(
        (array)($selector['commandContexts'] ?? []),
        static function (array $context) use ($holderId, $detailId): bool {
            $kind = (string)($context['kind'] ?? '');
            $id = (string)($context['id'] ?? '');
            return in_array($kind, ['cashier_workspace', 'member'], true)
                || ($kind === 'card_holder' && $id === (string)$holderId)
                || ($kind === 'member_benefit_pool' && $id === (string)$detailId);
        }
    ));
}

function mixedRefreshWorkspaceContext(array $contexts, string $workspaceId): array
{
    $version = (int)Db::name('cashier_v3_resource_version')
        ->where('resource_kind', 'cashier_workspace')
        ->where('resource_id', $workspaceId)
        ->value('current_version');
    return array_map(static function (array $context) use ($workspaceId, $version): array {
        if ((string)($context['kind'] ?? '') === 'cashier_workspace'
            && (string)($context['id'] ?? '') === $workspaceId) {
            $context['expectedVersion'] = $version;
        }
        return $context;
    }, $contexts);
}

function mixedCheckoutProjection($dispatcher, array $session, string $workspaceId): array
{
    $projection = (new CashierV3CheckoutProjectionServices(
        null,
        new ThinkPhpCashierV3CheckoutResultReadRepository()
    ))->readCurrent(
        $workspaceId,
        (string)$session['state_context_id'],
        MemberIntegrationFixture::operatorScope(1),
        MemberIntegrationFixture::dataScope($dispatcher, 1)
    );
    if (!is_array($projection)) {
        throw new RuntimeException('MIXED_CHECKOUT_PROJECTION_MISSING');
    }
    return $projection;
}

function mixedCommonPayload(array $checkout): array
{
    return [
        'checkoutRequestId' => (string)$checkout['checkoutRequestId'],
        'checkoutRequestVersion' => (int)$checkout['checkoutRequestVersion'],
        'preparationRequestId' => (string)$checkout['preparationRequestId'],
        'preparationToken' => (string)$checkout['preparationToken'],
    ];
}

function mixedSubmitContexts(array $checkout): array
{
    $allowed = ['cashier_workspace' => true, 'checkout_request' => true];
    $contexts = array_values(array_filter(
        (array)($checkout['commandContexts'] ?? []),
        static function (array $context) use ($allowed): bool {
            return isset($allowed[(string)($context['kind'] ?? '')]);
        }
    ));
    $kinds = array_values(array_unique(array_map(static function (array $context): string {
        return (string)($context['kind'] ?? '');
    }, $contexts)));
    sort($kinds, SORT_STRING);
    if ($kinds !== ['cashier_workspace', 'checkout_request'] || count($contexts) !== 2) {
        throw new RuntimeException('MIXED_SUBMIT_CONTEXTS_INCOMPLETE');
    }
    return $contexts;
}

function mixedFindProject(array $selector, int $holderId, int $detailId): array
{
    foreach ((array)($selector['sources'] ?? []) as $source) {
        if ((int)($source['entitlementInstanceId'] ?? 0) !== $holderId) {
            continue;
        }
        foreach ((array)($source['projects'] ?? []) as $project) {
            if ((int)($project['entitlementSourceDetailId'] ?? 0) === $detailId) {
                return ['source' => $source, 'project' => $project];
            }
        }
    }
    return [];
}

function mixedEntitlementLineId(string $addIntentId): string
{
    return 'entitlement:' . substr(hash('sha256', $addIntentId), 0, 48);
}

function mixedTableExists(string $table): bool
{
    $rows = Db::query(
        'SELECT COUNT(*) AS c FROM information_schema.TABLES'
        . ' WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',
        ['eb_' . $table]
    );
    return (int)($rows[0]['c'] ?? 0) === 1;
}

function mixedDomainTables(): array
{
    return [
        'cashier_v3_command_receipt',
        'cashier_v3_resource_version',
        'cashier_v3_state_context',
        'cashier_v3_workspace_line',
        'cashier_v3_workspace_draft',
        'cashier_v3_entitlement_resource_version',
        'cashier_v3_checkout_resource_plan_row',
        'cashier_v3_checkout_resource_plan',
        'cashier_v3_checkout_source_reference',
        'cashier_v3_checkout_payment_draft',
        'cashier_v3_checkout_line_draft',
        'cashier_v3_checkout_request',
        'cashier_v3_sales_order_line',
        'cashier_v3_sales_order',
        'cashier_v3_payment_collection',
        'cashier_v3_payment_collection_batch',
        'cashier_v3_sale_fact',
        'cashier_v3_payment_fact',
        'cashier_v3_balance_fact',
        'cashier_v3_performance_fact',
        'cashier_v3_entitlement_writeoff_fact',
        'cashier_v3_entitlement_service_fact',
        'cashier_v3_entitlement_completion_receipt',
        'cashier_v3_business_event',
        'cashier_v3_outbox',
        'cashier_v3_outbox_attempt',
        'cashier_v3_consumer_once',
        'cashier_v3_entitlement_debt_guard_mutation',
        'cashier_v3_entitlement_debt_guard',
        'cashier_v3_staff_profile_version',
        'cashier_v3_entitlement_occupation_version',
        'cashier_v3_project_performance_rule',
        'cashier_v3_service_order_operation',
        'cashier_v3_service_order_line',
        'cashier_v3_service_order_entitlement_guard',
        'cashier_v3_service_order',
        'inventory_shortage_cost_adjustment',
        'inventory_shortage_fact',
        'inventory_batch_movement_fact',
        'inventory_batch_consumption_fact',
        'inventory_consumption_receipt',
        'inventory_shortage_cost_cursor',
        'inventory_batch',
        'inventory_stock',
        'inventory_location',
        'inventory_shortage_policy',
        'store_reservation_order',
        'store_debt',
        'store_order_cart_info',
        'user_card_holder',
        'store_order',
    ];
}

function mixedDatabaseSnapshot(): string
{
    $snapshot = [];
    foreach (mixedDomainTables() as $table) {
        if (!mixedTableExists($table)) {
            $snapshot[$table] = null;
            continue;
        }
        $snapshot[$table] = Db::query('SELECT * FROM `eb_' . $table . '` ORDER BY 1');
    }
    return hash('sha256', json_encode(
        $snapshot,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
    ));
}

function mixedDeleteDomainRows(): void
{
    foreach (mixedDomainTables() as $table) {
        if (mixedTableExists($table)) {
            Db::execute('DELETE FROM `eb_' . $table . '`');
        }
    }
    foreach ([
        'store_project_consumable_recipe_detail',
        'store_project_consumable_recipe',
        'store_card_related',
        'store_product_attr_value',
        'store_product_category',
        'store_product',
    ] as $table) {
        if (mixedTableExists($table)) {
            Db::execute('DELETE FROM `eb_' . $table . '`');
        }
    }
}

function mixedSeedAuthorities(): void
{
    mixedDeleteDomainRows();
    MemberIntegrationFixture::resetAndSeed();
    MemberIntegrationFixture::seedMember(101, '混合结账会员', '13800000101', 8);
    Db::name('employee')->whereIn('id', [1, 2, 20])->update([
        'employment_type_code' => 'internal',
        'employment_type_version' => 1,
    ]);

    $now = time();
    Db::name('store_product_category')->insert([
        'id' => 51,
        'cate_name' => '混合结账商品',
        'type' => 1,
        'relation_id' => 8,
        'is_show' => 1,
    ]);
    Db::name('store_product')->insertAll([
        [
            'id' => 501, 'pid' => 0, 'type' => 1, 'relation_id' => 8,
            'product_type' => 0, 'store_name' => '普通零售商品', 'cate_id' => '51',
            'keyword' => '', 'unit_name' => '件', 'sort' => 1, 'is_show' => 1,
            'is_del' => 0, 'is_verify' => 1, 'is_inventory' => 0,
            'allow_negative_stock' => 1, 'card_num' => 0, 'card_num_type' => 0,
        ],
        [
            'id' => 600, 'pid' => 0, 'type' => 0, 'relation_id' => 0,
            'product_type' => 6, 'store_name' => '平台护理项目', 'cate_id' => '51',
            'keyword' => '', 'unit_name' => '次', 'sort' => 2, 'is_show' => 1,
            'is_del' => 0, 'is_verify' => 1, 'is_inventory' => 0,
            'allow_negative_stock' => 1, 'card_num' => 0, 'card_num_type' => 0,
        ],
        [
            'id' => 601, 'pid' => 600, 'type' => 1, 'relation_id' => 8,
            'product_type' => 6, 'store_name' => '门店护理项目', 'cate_id' => '51',
            'keyword' => '', 'unit_name' => '次', 'sort' => 3, 'is_show' => 1,
            'is_del' => 0, 'is_verify' => 1, 'is_inventory' => 0,
            'allow_negative_stock' => 1, 'card_num' => 0, 'card_num_type' => 0,
        ],
        [
            'id' => 700, 'pid' => 0, 'type' => 0, 'relation_id' => 0,
            'product_type' => 0, 'store_name' => '平台护理耗材', 'cate_id' => '51',
            'keyword' => '', 'unit_name' => '片', 'sort' => 4, 'is_show' => 1,
            'is_del' => 0, 'is_verify' => 1, 'is_inventory' => 1,
            'allow_negative_stock' => 0, 'card_num' => 0, 'card_num_type' => 0,
        ],
        [
            'id' => 701, 'pid' => 700, 'type' => 1, 'relation_id' => 8,
            'product_type' => 0, 'store_name' => '门店护理耗材', 'cate_id' => '51',
            'keyword' => '', 'unit_name' => '片', 'sort' => 5, 'is_show' => 1,
            'is_del' => 0, 'is_verify' => 1, 'is_inventory' => 1,
            'allow_negative_stock' => 0, 'card_num' => 0, 'card_num_type' => 0,
        ],
    ]);
    Db::name('store_product_attr_value')->insertAll([
        [
            'id' => 1501, 'product_id' => 501, 'product_type' => 0,
            'unique' => 'SALE501', 'suk' => '默认', 'price' => '100.00',
            'ot_price' => '100.00', 'stock' => '100.0000', 'code' => 'SALE-501',
            'bar_code' => '', 'is_show' => 1, 'type' => 0, 'write_times' => 0,
            'write_valid' => 1, 'write_days' => 0, 'write_start' => 0, 'write_end' => 0,
        ],
        [
            'id' => 1600, 'product_id' => 600, 'product_type' => 6,
            'unique' => 'PPROJ600', 'suk' => '标准项目', 'price' => '0.00',
            'ot_price' => '0.00', 'stock' => '0.0000', 'code' => 'PPROJ-600',
            'bar_code' => '', 'is_show' => 1, 'type' => 0, 'write_times' => 0,
            'write_valid' => 1, 'write_days' => 0, 'write_start' => 0, 'write_end' => 0,
        ],
        [
            'id' => 1601, 'product_id' => 601, 'product_type' => 6,
            'unique' => 'SPROJ601', 'suk' => '标准项目', 'price' => '0.00',
            'ot_price' => '0.00', 'stock' => '0.0000', 'code' => 'SPROJ-601',
            'bar_code' => '', 'is_show' => 1, 'type' => 0, 'write_times' => 0,
            'write_valid' => 1, 'write_days' => 0, 'write_start' => 0, 'write_end' => 0,
        ],
        [
            'id' => 1700, 'product_id' => 700, 'product_type' => 0,
            'unique' => 'PMAT700', 'suk' => '标准耗材', 'price' => '0.00',
            'ot_price' => '0.00', 'stock' => '0.0000', 'code' => 'PMAT-700',
            'bar_code' => '', 'is_show' => 1, 'type' => 0, 'write_times' => 0,
            'write_valid' => 1, 'write_days' => 0, 'write_start' => 0, 'write_end' => 0,
        ],
        [
            'id' => 1701, 'product_id' => 701, 'product_type' => 0,
            'unique' => 'SMAT701', 'suk' => '标准耗材', 'price' => '0.00',
            'ot_price' => '0.00', 'stock' => '10.0000', 'code' => 'SMAT-701',
            'bar_code' => '', 'is_show' => 1, 'type' => 0, 'write_times' => 0,
            'write_valid' => 1, 'write_days' => 0, 'write_start' => 0, 'write_end' => 0,
        ],
    ]);

    Db::name('store_order')->insert([
        'id' => 5001, 'uid' => 101, 'store_id' => 8, 'paid' => 1,
        'is_del' => 0, 'is_system_del' => 0, 'is_user_del' => 0,
        'refund_status' => 0, 'terminal_action' => 0, 'card_upgrade_use_oid' => 0,
        'pid' => 0, 'order_type' => 0, 'is_debt_repay' => 0,
        'order_id' => 'LEGACY-ENTITLEMENT-5001', 'mark' => '混合结账权益来源',
        'pay_price' => '90.00', 'cash_pay_price' => '90.00',
        'yue_pay_price' => '0.00', 'debt_amount' => '0.00',
        'repaid_debt_amount' => '0.00', 'add_time' => $now - 86400,
        'pay_time' => $now - 86400,
    ]);
    Db::name('user_card_holder')->insert([
        'id' => 1001, 'uid' => 101, 'oid' => 5001, 'card_name' => '护理十次卡',
        'card_no' => 'CARD-1001', 'store_id' => 8, 'product_type' => 4,
        'write_times' => 10, 'write_surplus_times' => 10,
        'write_start' => $now - 86400, 'write_end' => $now + 86400 * 365,
        'is_del' => 0,
    ]);
    Db::name('store_order_cart_info')->insert([
        'id' => 2001, 'oid' => 5001, 'cart_id' => 'SPROJ601',
        'product_id' => 601, 'cart_type' => 2, 'product_type' => 6,
        'cart_info' => json_encode([
            'productInfo' => [
                'store_name' => '门店护理项目',
                'categoryName' => '护理项目',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'write_times' => 10, 'write_surplus_times' => 10, 'is_writeoff' => 0,
        'write_start' => $now - 86400, 'write_end' => $now + 86400 * 365,
        'pay_price' => '90.00', 'debt_amount' => '0.00',
        'repaid_debt_amount' => '0.00', 'is_gift' => 0,
    ]);

    Db::name('cashier_v3_project_performance_rule')->insert([
        'tenant_id' => '0', 'project_id' => 601,
        'consumption_mode' => 'actual_entitlement_amount',
        'consumption_configured_unit_amount_cents' => 0,
        'labor_mode' => 'actual_entitlement_amount',
        'labor_configured_unit_amount_cents' => 0,
        'current_version' => 1, 'created_at' => $now, 'updated_at' => $now,
    ]);

    Db::name('store_project_consumable_recipe')->insert([
        'id' => 9001, 'type' => 0, 'relation_id' => 0,
        'project_product_id' => 600, 'project_unique' => 'PPROJ600',
        'status' => 1, 'version' => 1,
    ]);
    Db::name('store_project_consumable_recipe_detail')->insert([
        'id' => 9002, 'recipe_id' => 9001, 'consumable_product_id' => 700,
        'consumable_unique' => 'PMAT700', 'qty_per_writeoff' => '2.0000',
    ]);
    Db::name('inventory_shortage_policy')->insertAll([
        [
            'tenant_id' => '0', 'policy_scope' => 'MERCHANT', 'project_id' => 0,
            'policy_value' => 'deny_shortage', 'version' => 1, 'updated_by' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ],
        [
            'tenant_id' => '0', 'policy_scope' => 'PROJECT', 'project_id' => 601,
            'policy_value' => 'inherit', 'version' => 1, 'updated_by' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ],
    ]);
    Db::name('inventory_location')->insert([
        'id' => 8001, 'tenant_id' => '0', 'organization_id' => '3',
        'organization_path' => '/1/2/3/', 'organization_name_snapshot' => '当前组织',
        'location_type' => 'STORE', 'owner_id' => 8, 'location_code' => 'STORE-8',
        'location_name' => '本店默认仓库', 'store_id' => 8,
        'store_name_snapshot' => '本店', 'is_default' => 1,
        'location_status' => 'ACTIVE', 'version' => 1,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    Db::name('inventory_stock')->insert([
        'id' => 8101, 'tenant_id' => '0', 'organization_id' => '3',
        'organization_path' => '/1/2/3/', 'location_id' => 8001, 'store_id' => 8,
        'consumable_product_id' => 701, 'sku_id' => 1701,
        'product_unique' => 'SMAT701', 'stock_status' => 'GOOD',
        'stock_unit' => 'piece', 'quantity_scale' => 0,
        'available_quantity_units' => 10, 'estimated_unit_cost_cents' => 50,
        'version' => 1, 'created_at' => $now, 'updated_at' => $now,
    ]);
    Db::name('inventory_batch')->insert([
        'id' => 8201, 'stock_id' => 8101, 'origin_batch_id' => 8201,
        'source_batch_id' => 0, 'batch_no' => 'MIXED-BATCH-001',
        'manufactured_date' => '2026-01-01', 'expire_date' => '2027-12-31',
        'received_at' => $now - 86400, 'received_business_date' => date('Y-m-d', $now - 86400),
        'product_name_snapshot' => '门店护理耗材', 'sku_name_snapshot' => '标准耗材',
        'product_code_snapshot' => 'SMAT-701', 'barcode_snapshot' => '',
        'brand_name_snapshot' => '', 'category_name_snapshot' => '护理耗材',
        'source_order_no_snapshot' => 'OPENING-MIXED-8201', 'data_quality' => 'COMPLETE',
        'available_quantity_units' => 10, 'unit_cost_cents' => 50,
        'cost_allocated_quantity_units' => 0, 'batch_status' => 'ACTIVE',
        'version' => 1, 'created_at' => $now, 'updated_at' => $now,
    ]);

    Db::name('cashier_v3_service_order')->insert([
        'id' => 9101, 'service_order_no' => 'FW-MIXED-9101', 'tenant_id' => '0',
        'organization_id' => '3', 'organization_path' => '/1/2/3/',
        'organization_name_snapshot' => '当前组织', 'business_store_id' => 8,
        'business_store_name_snapshot' => '本店', 'member_id' => 101,
        'member_name_snapshot' => '混合结账会员', 'source_type' => 'direct',
        'source_id' => 0, 'source_no_snapshot' => '', 'source_version_snapshot' => 0,
        'room_id' => 0, 'room_name_snapshot' => '',
        'participant_employee_ids_json' => '[1,20]', 'status' => 'IN_SERVICE',
        'version' => 1, 'business_date' => date('Y-m-d', $now),
        'service_started_at' => $now - 600, 'pending_checkout_at' => 0,
        'completed_at' => 0, 'cancelled_at' => 0, 'voided_at' => 0,
        'occurred_at' => $now - 600, 'recorded_at' => $now - 600,
        'created_by_staff_id' => 1, 'created_by_employee_id' => 1,
        'created_by_name_snapshot' => '操作员一',
        'created_at' => $now - 600, 'updated_at' => $now - 600,
    ]);
    Db::name('cashier_v3_service_order_line')->insert([
        'id' => 9102, 'tenant_id' => '0', 'service_order_id' => 9101,
        'line_key' => 'service-line-entitlement-2001',
        'entitlement_source_detail_id' => 2001, 'entitlement_instance_id' => 1001,
        'project_id' => 601, 'project_name_snapshot' => '门店护理项目',
        'occupied_times' => 3, 'service_target' => 'SELF', 'is_experience' => 0,
        'artisan_staff_id' => 20, 'artisan_employee_id' => 20,
        'artisan_name_snapshot' => '专属服务人甲', 'status' => 'ACTIVE',
        'version' => 1, 'created_at' => $now - 600, 'updated_at' => $now - 600,
    ]);
    Db::name('cashier_v3_service_order_entitlement_guard')->insert([
        'id' => 9103, 'tenant_id' => '0', 'entitlement_source_detail_id' => 2001,
        'current_version' => 1, 'last_action' => 'fixture_seeded',
        'created_at' => $now - 600, 'updated_at' => $now - 600,
    ]);
}

function mixedPrepareScenario(): array
{
    mixedSeedAuthorities();
    MemberIntegrationFixture::bindTestAdapters();
    $dispatcher = MemberIntegrationFixture::dispatcher();

    $selectRequest = MemberIntegrationFixture::commandRequest(
        $dispatcher,
        'select-cashier-member',
        ['selectorEntry' => 'cashier', 'memberId' => '101'],
        1,
        mixedKey('CMD')
    );
    $session = $selectRequest['session'];
    $workspaceId = $selectRequest['workspace_id'];
    $selected = $dispatcher->dispatch($selectRequest['body'], $session);
    if (($selected['result']['status'] ?? '') !== CashierV3ResultCode::STATUS_SUCCESS) {
        throw new RuntimeException('MIXED_MEMBER_SELECTION_FAILED');
    }

    $chosen = $dispatcher->dispatch(mixedCommandBody(
        $session,
        'choose-catalog-item',
        ['itemId' => 1501],
        mixedWorkspaceContexts($workspaceId),
        mixedKey('CMD')
    ), $session);
    if (($chosen['result']['status'] ?? '') !== CashierV3ResultCode::STATUS_SUCCESS) {
        throw new RuntimeException('MIXED_SALE_SELECTION_FAILED');
    }

    $selectorRequestId = mixedKey('ENTITLEMENT_SELECTOR');
    $projection = $dispatcher->dispatch(mixedSessionBody(
        $session,
        'open-add-card-service-project',
        ['memberId' => 101, 'selectorRequestId' => $selectorRequestId]
    ), $session);
    $selector = (array)($projection['data']['entitlementSelector'] ?? []);
    $pair = mixedFindProject($selector, 1001, 2001);
    if (!$pair || empty($pair['project']['selectable'])) {
        throw new RuntimeException('MIXED_ENTITLEMENT_NOT_SELECTABLE:' . json_encode($pair));
    }

    $addIntentId = mixedKey('ENTITLEMENT_ADD');
    $lineId = mixedEntitlementLineId($addIntentId);
    $selectionContexts = mixedSelectionContexts($selector, 1001, 2001);
    $added = $dispatcher->dispatch(mixedCommandBody(
        $session,
        'add-checkout-entitlement-lines',
        [
            'memberId' => 101,
            'selectorRequestId' => $selectorRequestId,
            'selectorToken' => (string)$selector['selectorToken'],
            'addIntentId' => $addIntentId,
            'mutationMode' => 'append',
            'lines' => [[
                'entitlementInstanceId' => 1001,
                'entitlementInstanceType' => 'card_holder',
                'entitlementSourceDetailId' => 2001,
                'entitlementSourceVersion' => (int)$pair['source']['version'],
                'projectId' => 601,
                'projectVersion' => (int)$pair['project']['version'],
                'quantity' => 1,
                'serviceObject' => '本人',
                'craftsmen' => [],
            ]],
        ],
        $selectionContexts,
        mixedKey('ADD_ENTITLEMENT')
    ), $session);
    if (($added['result']['status'] ?? '') !== CashierV3ResultCode::STATUS_SUCCESS) {
        throw new RuntimeException('MIXED_ENTITLEMENT_ADD_FAILED');
    }

    $quantityChanged = $dispatcher->dispatch(mixedCommandBody(
        $session,
        'change-cart-line-quantity',
        ['lineId' => $lineId, 'delta' => 2],
        mixedRefreshWorkspaceContext($selectionContexts, $workspaceId),
        mixedKey('CHANGE_CART_QUANTITY')
    ), $session);
    if (($quantityChanged['result']['status'] ?? '') !== CashierV3ResultCode::STATUS_SUCCESS) {
        throw new RuntimeException('MIXED_ENTITLEMENT_QUANTITY_FAILED');
    }

    $settings = $dispatcher->dispatch(mixedCommandBody(
        $session,
        'update-cart-line-service-settings',
        [
            'lineId' => $lineId,
            'serviceObject' => 'self',
            'craftsmen' => [['id' => 20, 'name' => '客户端不可信姓名']],
            'isExperience' => false,
        ],
        mixedRefreshWorkspaceContext($selectionContexts, $workspaceId),
        mixedKey('CART_SERVICE_SETTINGS')
    ), $session);
    if (($settings['result']['status'] ?? '') !== CashierV3ResultCode::STATUS_SUCCESS) {
        throw new RuntimeException('MIXED_SERVICE_SETTINGS_FAILED');
    }

    $prepareKey = mixedKey('CHECKOUT_PREPARE');
    $prepareContexts = mixedWorkspaceContexts($workspaceId);
    $prepareContexts[] = [
        'kind' => 'service_order',
        'id' => '9101',
        'expectedVersion' => 1,
    ];
    $prepared = $dispatcher->dispatch(mixedCommandBody(
        $session,
        'prepare-checkout',
        [
            'preparationRequestId' => $prepareKey,
            'serviceOrderId' => 9101,
        ],
        $prepareContexts,
        $prepareKey
    ), $session);
    if (($prepared['result']['status'] ?? '') !== CashierV3ResultCode::STATUS_SUCCESS) {
        throw new RuntimeException('MIXED_CHECKOUT_PREPARE_FAILED');
    }

    $checkout = mixedCheckoutProjection($dispatcher, $session, $workspaceId);
    if ((string)($checkout['compositionCode'] ?? $checkout['composition'] ?? '') !== 'mixed') {
        throw new RuntimeException('MIXED_COMPOSITION_NOT_FROZEN');
    }
    $paymentPayload = mixedCommonPayload($checkout);
    $paymentPayload['paymentMethodId'] = 'wechat';
    $payment = $dispatcher->dispatch(mixedCommandBody(
        $session,
        'add-payment-method',
        $paymentPayload,
        array_values((array)$checkout['commandContexts']),
        mixedKey('CMD')
    ), $session);
    if (($payment['result']['status'] ?? '') !== CashierV3ResultCode::STATUS_SUCCESS) {
        throw new RuntimeException('MIXED_PAYMENT_DRAFT_FAILED');
    }

    $checkout = mixedCheckoutProjection($dispatcher, $session, $workspaceId);
    $submitKey = mixedKey('CHECKOUT');
    $submitPrepareKey = preg_replace('/^CHECKOUT-/', 'CHECKOUT_PREPARE-', $submitKey);
    $submissionPreparation = $dispatcher->dispatch(mixedCommandBody(
        $session,
        'prepare-checkout-submission',
        mixedCommonPayload($checkout),
        array_values((array)$checkout['commandContexts']),
        (string)$submitPrepareKey
    ), $session);
    if (($submissionPreparation['result']['status'] ?? '') !== CashierV3ResultCode::STATUS_SUCCESS) {
        throw new RuntimeException('MIXED_SUBMISSION_PREPARATION_FAILED');
    }

    $checkout = mixedCheckoutProjection($dispatcher, $session, $workspaceId);
    if ((string)($checkout['requestStatus'] ?? '') !== 'ready_for_submit') {
        throw new RuntimeException('MIXED_REQUEST_NOT_READY');
    }
    $requestId = (string)$checkout['checkoutRequestId'];
    return [
        'dispatcher' => $dispatcher,
        'session' => $session,
        'workspaceId' => $workspaceId,
        'requestId' => $requestId,
        'requestVersion' => (int)$checkout['checkoutRequestVersion'],
        'lineId' => $lineId,
        'submitKey' => $submitKey,
        'finalBody' => mixedCommandBody(
            $session,
            'submit-checkout',
            mixedCommonPayload($checkout),
            mixedSubmitContexts($checkout),
            $submitKey
        ),
    ];
}

function mixedFailure(callable $operation): array
{
    try {
        $operation();
    } catch (CashierV3CommandException $exception) {
        return [
            'class' => get_class($exception),
            'code' => $exception->getResultCode(),
            'reason' => (string)($exception->getDetail()['reason'] ?? ''),
            'message' => $exception->getMessage(),
        ];
    } catch (Throwable $throwable) {
        return [
            'class' => get_class($throwable),
            'code' => '',
            'reason' => '',
            'message' => $throwable->getMessage(),
        ];
    }
    return [];
}

try {
    $success = mixedPrepareScenario();
    $submitted = $success['dispatcher']->dispatch($success['finalBody'], $success['session']);
    $submission = (array)($submitted['data']['checkoutSubmission'] ?? []);
    $salesOrder = (array)($submission['salesOrder'] ?? []);
    $entitlement = (array)($submission['entitlementCompletion'] ?? []);
    mixedOk(
        'MIXED-MYSQL-01 real Gateway commits one mixed checkout',
        ($submitted['result']['status'] ?? '') === CashierV3ResultCode::STATUS_SUCCESS
            && empty($submitted['replay'])
            && ($submission['composition'] ?? '') === 'mixed'
            && ($submission['requestStatus'] ?? '') === 'succeeded'
            && preg_match('/^CSO-[0-9a-f]{40}$/D', (string)($salesOrder['orderId'] ?? '')) === 1
            && preg_match('/^ECR-[0-9a-f]{40}$/D', (string)($entitlement['receiptId'] ?? '')) === 1,
        json_encode($submitted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    $requestId = $success['requestId'];
    $order = (array)Db::name('cashier_v3_sales_order')
        ->where('checkout_request_id', $requestId)->find();
    $batch = (array)Db::name('cashier_v3_payment_collection_batch')
        ->where('checkout_request_id', $requestId)->find();
    mixedOk(
        'MIXED-MYSQL-02 sale and payment authorities contain only this purchase',
        (int)Db::name('cashier_v3_sales_order')->count() === 1
            && (int)Db::name('cashier_v3_sales_order_line')->count() === 1
            && ($order['composition'] ?? '') === 'mixed'
            && (int)($order['sale_amount_cents'] ?? 0) === 10000
            && (int)Db::name('cashier_v3_payment_collection_batch')->count() === 1
            && (int)Db::name('cashier_v3_payment_collection')->count() === 1
            && (int)($batch['collected_amount_cents'] ?? 0) === 10000
            && (int)($batch['cash_performance_amount_cents'] ?? 0) === 10000
    );

    $receipt = (array)Db::name('cashier_v3_entitlement_completion_receipt')
        ->where('checkout_request_id', $requestId)->find();
    mixedOk(
        'MIXED-MYSQL-03 quantity three deducts the entitlement and persists one service grain',
        ($receipt['status'] ?? '') === 'completed'
            && (string)($receipt['receipt_id'] ?? '') === (string)($entitlement['receiptId'] ?? '')
            && (int)Db::name('user_card_holder')->where('id', 1001)->value('write_surplus_times') === 7
            && (int)Db::name('store_order_cart_info')->where('id', 2001)->value('write_surplus_times') === 7
            && (int)Db::name('cashier_v3_entitlement_writeoff_fact')
                ->where('checkout_request_id', $requestId)->sum('quantity') === 3
            && (int)Db::name('cashier_v3_entitlement_service_fact')
                ->where('checkout_request_id', $requestId)->sum('quantity') === 3
            && (int)Db::name('cashier_v3_entitlement_service_fact')
                ->where('checkout_request_id', $requestId)->count() === 1
    );

    $inventoryReceipt = (array)Db::name('inventory_consumption_receipt')
        ->where('source_detail_id', $requestId)->find();
    mixedOk(
        'MIXED-MYSQL-04 recipe quantity follows cart quantity and deducts one real batch',
        (int)($inventoryReceipt['actual_cost_cents'] ?? 0) === 300
            && (int)($inventoryReceipt['estimated_shortage_cost_cents'] ?? -1) === 0
            && (int)Db::name('inventory_stock')->where('id', 8101)->value('available_quantity_units') === 4
            && (int)Db::name('inventory_batch')->where('id', 8201)->value('available_quantity_units') === 4
            && (int)Db::name('inventory_batch_consumption_fact')
                ->where('receipt_id', (int)($inventoryReceipt['id'] ?? 0))->sum('quantity_units') === 6
            && (int)Db::name('inventory_batch_movement_fact')
                ->where('source_type', 'completion_batch')->where('direction', -1)
                ->sum('quantity_units') === 6
            && (int)Db::name('inventory_shortage_fact')->count() === 0
    );

    $serviceOrder = (array)Db::name('cashier_v3_service_order')->where('id', 9101)->find();
    $serviceLine = (array)Db::name('cashier_v3_service_order_line')->where('id', 9102)->find();
    mixedOk(
        'MIXED-MYSQL-05 C3 service occupation converts in the same transaction',
        ($serviceOrder['status'] ?? '') === 'COMPLETED'
            && (int)($serviceOrder['version'] ?? 0) === 2
            && (int)($serviceOrder['completed_at'] ?? 0) > 0
            && ($serviceLine['status'] ?? '') === 'RELEASED'
            && (int)($serviceLine['occupied_times'] ?? -1) === 0
            && (int)($serviceLine['version'] ?? 0) === 2
            && (int)Db::name('cashier_v3_service_order_operation')->count() === 1
            && (int)Db::name('cashier_v3_service_order_entitlement_guard')
                ->where('entitlement_source_detail_id', 2001)->value('current_version') === 2
    );

    $performanceTypes = Db::name('cashier_v3_performance_fact')
        ->where('checkout_request_id', $requestId)
        ->column('performance_type');
    sort($performanceTypes, SORT_STRING);
    $laborFact = (array)Db::name('cashier_v3_performance_fact')
        ->where('checkout_request_id', $requestId)
        ->where('performance_type', 'labor_performance_allocated')->find();
    mixedOk(
        'MIXED-MYSQL-06 sales service and staff performance facts use frozen authority',
        (int)Db::name('cashier_v3_sale_fact')->where('checkout_request_id', $requestId)->count() === 1
            && (int)Db::name('cashier_v3_payment_fact')->where('checkout_request_id', $requestId)->count() === 1
            && $performanceTypes === [
                'actual_performance_recorded',
                'consumption_performance_recorded',
                'labor_performance_allocated',
            ]
            && (int)($laborFact['employee_id'] ?? 0) === 20
            && ($laborFact['employee_name_snapshot'] ?? '') === '专属服务人甲'
            && ($laborFact['employee_type_snapshot'] ?? '') === 'internal'
            && (int)($laborFact['employee_type_authority_version'] ?? 0) === 1
            && (int)($laborFact['amount_cents'] ?? 0) === 2700
    );

    $eventTypes = Db::name('cashier_v3_business_event')
        ->where('source_type', 'submit-checkout')
        ->where('source_id', $requestId)
        ->column('event_type');
    sort($eventTypes, SORT_STRING);
    mixedOk(
        'MIXED-MYSQL-07 exact synchronous event set commits once without Outbox',
        $eventTypes === [
            'checkout.completed',
            'entitlement.writeoff.completed',
            'inventory.batch.consumed',
            'inventory.service_consumption.resolved',
            'performance.consumption.recorded',
            'performance.labor.allocated',
            'service.completed',
        ]
            && (int)Db::name('cashier_v3_outbox')->count() === 0
    );

    $request = (array)Db::name('cashier_v3_checkout_request')
        ->where('request_id', $requestId)->find();
    mixedOk(
        'MIXED-MYSQL-08 request drafts plan receipt and cart share one terminal state',
        ($request['request_status'] ?? '') === 'succeeded'
            && (int)($request['request_version'] ?? 0) === $success['requestVersion'] + 1
            && ($request['last_idempotency_key'] ?? '') === $success['submitKey']
            && (int)Db::name('cashier_v3_checkout_line_draft')
                ->where('request_id', $requestId)->where('draft_status', 'committed')->count() === 2
            && (int)Db::name('cashier_v3_checkout_payment_draft')
                ->where('request_id', $requestId)->where('draft_status', 'committed')->count() === 1
            && (int)Db::name('cashier_v3_checkout_resource_plan')
                ->where('request_id', $requestId)->where('plan_status', 'consumed')->count() === 1
            && (int)Db::name('cashier_v3_workspace_line')
                ->where('workspace_id', $success['workspaceId'])->count() === 0
            && (int)Db::name('cashier_v3_command_receipt')
                ->where('idempotency_key', $success['submitKey'])->where('status', 1)->count() === 1
    );

    $queryBody = [
        'action' => 'query-checkout-result',
        'originalIdempotencyKey' => $success['submitKey'],
        'clientSessionId' => $success['session']['client_session_id'],
        'stateContextId' => $success['session']['state_context_id'],
        'correlationId' => mixedKey('CORR'),
    ];
    $queried = $success['dispatcher']->dispatch($queryBody, $success['session']);
    $queryResult = (array)($queried['data']['checkoutResult'] ?? []);
    mixedOk(
        'MIXED-MYSQL-09 recovery requires and returns the committed CSO plus ECR',
        ($queryResult['status'] ?? '') === CashierV3ResultCode::STATUS_SUCCESS
            && ($queryResult['phase'] ?? '') === 'succeeded'
            && ($queryResult['composition'] ?? '') === 'mixed'
            && ($queryResult['salesOrder']['orderId'] ?? '') === ($salesOrder['orderId'] ?? '')
            && ($queryResult['entitlementCompletion']['receiptId'] ?? '')
                === ($entitlement['receiptId'] ?? '')
            && !isset($queried['stateRevision'])
            && !isset($queried['versions'])
    );

    $beforeReplay = mixedDatabaseSnapshot();
    $replayed = $success['dispatcher']->dispatch($success['finalBody'], $success['session']);
    mixedOk(
        'MIXED-MYSQL-10 exact final body replay performs no additional business write',
        !empty($replayed['replay'])
            && $beforeReplay === mixedDatabaseSnapshot()
    );

    $rollback = mixedPrepareScenario();
    $rollbackBefore = mixedDatabaseSnapshot();
    Db::execute(
        "CREATE TRIGGER `trg_mixed_checkout_late_failure` BEFORE DELETE"
        . " ON `eb_cashier_v3_workspace_line` FOR EACH ROW"
        . " SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='MIXED_CHECKOUT_LATE_ROLLBACK'"
    );
    try {
        $lateFailure = mixedFailure(static function () use ($rollback): void {
            $rollback['dispatcher']->dispatch($rollback['finalBody'], $rollback['session']);
        });
    } finally {
        Db::execute('DROP TRIGGER IF EXISTS `trg_mixed_checkout_late_failure`');
    }
    mixedOk(
        'MIXED-MYSQL-11 final cart-cleanup failure rolls every domain back',
        $lateFailure !== []
            && strpos((string)($lateFailure['message'] ?? ''), 'MIXED_CHECKOUT_LATE_ROLLBACK') !== false
            && $rollbackBefore === mixedDatabaseSnapshot(),
        json_encode($lateFailure, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
} catch (Throwable $throwable) {
    $detail = $throwable instanceof CashierV3CommandException
        ? ' code=' . $throwable->getResultCode()
            . ' detail=' . json_encode(
                $throwable->getDetail(),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            )
        : '';
    mixedOk(
        'MIXED-MYSQL-UNEXPECTED focused integration completed without an unexpected throwable',
        false,
        get_class($throwable) . ': ' . $throwable->getMessage() . $detail
    );
}

echo "MIXED_CHECKOUT_MYSQL passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
