<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$migration = (string)file_get_contents($root . '/后端代码/database/upgrades/2026-08-16-第三阶段六维报表数据底座/02-正式升级.sql');
$precheck = (string)file_get_contents($root . '/后端代码/database/upgrades/2026-08-16-第三阶段六维报表数据底座/01-升级前检查.sql');
$postcheck = (string)file_get_contents($root . '/后端代码/database/upgrades/2026-08-16-第三阶段六维报表数据底座/03-升级后验证.sql');
$foundation = (string)file_get_contents($root . '/后端代码/app/services/report/StoreUnifiedReportPhaseThreeFoundationServices.php');
$itemFact = (string)file_get_contents($root . '/后端代码/app/services/report/CardSaleItemAllocationFactServices.php');
$categoryFact = (string)file_get_contents($root . '/后端代码/app/services/report/CardSaleCategoryAllocationFactServices.php');
$issuance = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/card/CashierV3CardPurchaseIssuanceServices.php');
$checkout = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/settlement/CashierV3SaleOnlyCheckoutSubmissionServices.php');
$memberWriter = (string)file_get_contents($root . '/后端代码/app/services/user/UserServices.php');
$assignmentWriter = (string)file_get_contents($root . '/后端代码/app/services/user/UserBelongStoreServices.php');
$cashierMemberWriter = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/member/CashierV3MemberModule.php');
$mobileMemberWriter = (string)file_get_contents($root . '/后端代码/app/services/mobile/customer/MobileCustomerCreateServices.php');
$cardOperations = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/card/CashierV3CardOperationAuthorityServices.php');

function phaseThreeFoundationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    fwrite(STDOUT, "PASS: {$message}\n");
}

$tables = [
    'eb_cashier_v3_report_organization_dimension',
    'eb_cashier_v3_report_consumption_tier',
    'eb_cashier_v3_report_consumption_tier_audit',
    'eb_cashier_v3_report_member_origin_evidence',
    'eb_cashier_v3_report_member_origin_evidence_audit',
    'eb_cashier_v3_report_member_store_assignment_period',
    'eb_cashier_v3_card_sale_item_allocation_fact',
];
foreach ($tables as $table) {
    phaseThreeFoundationAssert(str_contains($migration, "CREATE TABLE IF NOT EXISTS `{$table}`"), "migration creates {$table}");
}
phaseThreeFoundationAssert(
    str_contains($precheck, 'eb_cashier_v3_card_operation')
        && str_contains($migration, 'idx_scope_business_date_status')
        && str_contains($migration, '`tenant_id`,`store_id`,`business_date`,`operation_status`,`operation_type`,`id`')
        && str_contains($postcheck, 'phase3_card_operation_date_index_count'),
    'card transfer date-range queries have a checked MySQL 5.6 composite index'
);

phaseThreeFoundationAssert(
    str_contains($migration, "'company'") === false
        && str_contains($migration, '`dimension_code`')
        && str_contains($foundation, "['company', 'city_manager']")
        && str_contains($foundation, "->where('valid_from', '<=', \$asOfDate)")
        && str_contains($foundation, "->whereNull('valid_to')->whereOr('valid_to', '>=', \$asOfDate)"),
    'organization dimensions are explicit, effective-dated and service-whitelisted'
);

$tiers = [
    "'gte_100000','100000以上',10000000,NULL",
    "'50000_100000','50000-100000',5000000,10000000",
    "'30000_50000','30000-50000',3000000,5000000",
    "'10000_30000','10000-30000',1000000,3000000",
    "'5000_10000','5000-10000',500000,1000000",
    "'0_5000','0-5000',0,500000",
];
foreach ($tiers as $tier) {
    phaseThreeFoundationAssert(str_contains($migration, $tier), "default tier {$tier} is exact");
}
phaseThreeFoundationAssert(
    str_contains($foundation, '$matchAmount = max(0, $netCashCents);')
        && str_contains($foundation, '$matchAmount < $upper')
        && str_contains($foundation, 'assertNoEnabledTierOverlap')
        && str_contains($foundation, 'CONSUMPTION_TIER_AUDIT_TABLE')
        && str_contains($foundation, 'expected_version')
        && str_contains($foundation, 'sortConsumptionTiers')
        && str_contains($foundation, "\$action = \$deleted ? 'DELETED'")
        && str_contains($foundation, "->whereNull('deleted_at')")
        && str_contains($foundation, '消费分级排序必须包含当前全部配置')
        && str_contains($foundation, "'action' => 'SORTED'"),
    'tier matching clamps negatives and deletion remains hidden, versioned and auditable'
);

phaseThreeFoundationAssert(
    str_contains($migration, '1786291200')
        && str_contains($migration, "THEN 'PRE_CUTOVER'")
        && str_contains($migration, "THEN 'IMPORTED'")
        && str_contains($migration, "THEN 'SYSTEM_CREATED'")
        && str_contains($foundation, "'is_new_customer_eligible' => false")
        && str_contains($foundation, "\$origin === 'SYSTEM_CREATED'"),
    'member origin evidence is fail-closed for imported, pre-cutover and unknown members'
);
phaseThreeFoundationAssert(
    str_contains($foundation, "->where('state', 'BOUND')")
        && str_contains($foundation, "'phone:' . (string)array_key_first")
        && str_contains($foundation, "'member:' . \$memberId")
        && str_contains($foundation, 'memberIdentityKeys')
        && str_contains($foundation, 'memberStoreAssignmentsAt')
        && str_contains($foundation, 'memberOrigins'),
    'member evidence, identity and cutoff assignment use batch reads with safe fallbacks'
);
phaseThreeFoundationAssert(
    str_contains($migration, 'USER_BELONG_STORE_HISTORY')
        && substr_count($migration, 'is_store=1') >= 4
        && str_contains($migration, 'LEGACY_CURRENT_ASSIGNMENT')
        && str_contains($foundation, "' 23:59:59 Asia/Shanghai'")
        && str_contains($foundation, "'未配置归属门店'")
        && str_contains($foundation, 'recordMemberStoreAssignmentInTx')
        && str_contains($foundation, 'CashierV3TransactionGuard::assertInTransaction'),
    'member store attribution excludes staff bindings, is effective-dated, cutoff-inclusive and transaction-bound'
);

$reportCodes = [
    'six_dimension_item_deal_analysis',
    'six_dimension_cash_consumption_analysis',
    'six_dimension_consumption_refund_detail',
    'six_dimension_performance_deal',
    'six_dimension_performance_distribution',
    'six_dimension_performance_market_distribution',
];
foreach ($reportCodes as $reportCode) {
    phaseThreeFoundationAssert(
        str_contains($migration, "'{$reportCode}'")
            && str_contains($migration, "admin-report-six-dimension-',r.report_code"),
        "platform permission exists for {$reportCode}"
    );
}
phaseThreeFoundationAssert(
    str_contains($migration, "'admin-report-six-dimension'")
        && str_contains($migration, "data_menu.unique_auth='admin-report'")
        && str_contains($migration, "'setting-shop-six-dimension-consumption-tier'")
        && !str_contains($migration, 'store-report-six-dimension'),
    'menus are platform-only under Data and platform settings'
);

$itemColumns = [
    'tenant_id', 'store_id', 'order_id', 'sale_fact_id', 'source_line_id', 'card_receipt_id',
    'component_product_id', 'item_name_snapshot', 'component_count', 'category_id_snapshot',
    'category_name_snapshot', 'category_path_snapshot', 'sale_amount_cents',
    'cash_performance_amount_cents', 'business_date', 'status', 'immutable_fingerprint',
    'command_idempotency_key',
];
foreach ($itemColumns as $column) {
    phaseThreeFoundationAssert(str_contains($migration, "`{$column}`"), "card item fact stores {$column}");
}
phaseThreeFoundationAssert(
    str_contains($issuance, "'projectNameSnapshot' => \$projectName")
        && str_contains($issuance, "'componentCount' => max(0, (int)(\$component['writeTimes'] ?? 0))")
        && str_contains($itemFact, "\$stableKey = 'project:' . \$productId")
        && str_contains($itemFact, "\$items[\$stableKey]['componentCount'] += \$componentCount")
        && str_contains($itemFact, "\$items[\$stableKey]['amountWeightCents'] += \$weight"),
    'issued receipt freezes project name/count and item fact groups by project rather than category'
);
phaseThreeFoundationAssert(
    str_contains($checkout, 'new CardSaleCategoryAllocationFactServices()')
        && str_contains($checkout, 'new CardSaleItemAllocationFactServices()')
        && str_contains($itemFact, "CashierV3TransactionGuard::assertInTransaction('cardSaleItemAllocation.persistInTx')")
        && str_contains($itemFact, "->where('natural_key', \$naturalKey)->lock(true)")
        && str_contains($itemFact, 'card_item_allocation_replay_conflict')
        && str_contains($categoryFact, "public const TABLE = 'cashier_v3_card_sale_category_allocation_fact'"),
    'checkout transaction preserves old category fact and adds immutable idempotent item fact'
);
phaseThreeFoundationAssert(
    str_contains($cardOperations, '$organizationSnapshot = $this->organizationSnapshot($operatorScope)')
        && str_contains($cardOperations, "'organization_id' => (string)\$organizationSnapshot['id']")
        && str_contains($cardOperations, "'organization_path_snapshot' => \$organizationSnapshot['path']")
        && str_contains($cardOperations, "'organization_name_snapshot' => \$organizationSnapshot['name']")
        && str_contains($cardOperations, 'private function organizationSnapshot(')
        && str_contains($cardOperations, "\$path === [] || trim(\$name) === '' || \$leafOrganizationId <= 0")
        && str_contains($cardOperations, "card_operation_organization_snapshot_missing")
        && str_contains($cardOperations, "return ['id' => \$leafOrganizationId, 'path' => implode('/', \$path), 'name' => \$name]"),
    'card transfers freeze a non-empty authoritative organization path and name snapshot'
);

require_once $root . '/后端代码/app/services/report/CardSaleItemAllocationFactServices.php';
$itemService = new \app\services\report\CardSaleItemAllocationFactServices();
$itemsMethod = Closure::bind(function (array $components): array {
    return $this->itemsFromReceipt($components);
}, $itemService, get_class($itemService));
$projectItems = $itemsMethod([
    ['productId' => 91, 'projectNameSnapshot' => '项目A', 'componentCount' => 2,
        'categoryIdSnapshot' => 7, 'categoryNameSnapshot' => '六维', 'allocationWeightCents' => 300],
    ['productId' => 91, 'projectNameSnapshot' => '项目A', 'componentCount' => 1,
        'categoryIdSnapshot' => 7, 'categoryNameSnapshot' => '六维', 'allocationWeightCents' => 200],
    ['productId' => 92, 'projectNameSnapshot' => '项目B', 'componentCount' => 4,
        'categoryIdSnapshot' => 7, 'categoryNameSnapshot' => '六维', 'allocationWeightCents' => 500],
]);
phaseThreeFoundationAssert(
    count($projectItems) === 2
        && (int)$projectItems['project:91']['componentCount'] === 3
        && (int)$projectItems['project:91']['amountWeightCents'] === 500
        && (string)$projectItems['project:91']['itemNameSnapshot'] === '项目A',
    'project-level projection deterministically combines duplicate project components'
);

phaseThreeFoundationAssert(
    str_contains($memberWriter, 'Db::transaction(function ()')
        && str_contains($memberWriter, 'recordMemberOrigin(')
        && str_contains($assignmentWriter, 'Db::transaction(function ()')
        && str_contains($assignmentWriter, 'recordMemberStoreAssignmentInTx(')
        && str_contains($cashierMemberWriter, "'source_type' => 'CASHIER_V3_MEMBER_CREATE'")
        && str_contains($cashierMemberWriter, 'recordMemberOrigin(')
        && str_contains($cashierMemberWriter, 'recordMemberStoreAssignmentInTx(')
        && str_contains($mobileMemberWriter, "'source_type' => 'MOBILE_CUSTOMER_CREATE'")
        && str_contains($mobileMemberWriter, 'recordMemberStoreAssignmentInTx('),
    'active system member creation and belonging-store writers persist report evidence transactionally'
);

echo "phase three report foundation contract: PASS\n";
