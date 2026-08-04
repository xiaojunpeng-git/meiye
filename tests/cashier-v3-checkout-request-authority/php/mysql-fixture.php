<?php
declare(strict_types=1);

require '/tests/cashier-v3/lib/boot-env.php';
require '/var/www/html/vendor/autoload.php';
require '/tests/cashier-v3/lib/_lib.php';

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\settlement\CashierV3CheckoutSettlementKernel;
use app\services\cashier\v3\settlement\CashierV3CheckoutVerifiedSourceSet;
use think\facade\Db;

c1aBootThinkApp('/var/www/html/');

function checkoutAuthorityOperator(int $storeId = 7, string $tenantId = '0'): CashierV3OperatorScope
{
    return new CashierV3OperatorScope($storeId, 21, '3', $tenantId);
}

function checkoutAuthorityScope(
    int $storeId = 7,
    string $tenantId = '0',
    string $mode = CashierV3DataScopeContext::MODE_STORES
): CashierV3DataScopeContext {
    $visible = $mode === CashierV3DataScopeContext::MODE_ALL ? null : [$storeId];
    if ($mode === CashierV3DataScopeContext::MODE_NONE
        || $mode === CashierV3DataScopeContext::MODE_SELF_PARTICIPANT) {
        $visible = [];
    }
    return new CashierV3DataScopeContext(
        21,
        121,
        $storeId,
        $tenantId,
        '3',
        $visible,
        $mode,
        [],
        $mode === CashierV3DataScopeContext::MODE_ALL,
        $mode === CashierV3DataScopeContext::MODE_ALL ? 'test' : '',
        'checkout-authority-scope-v1',
        ['cashier.v3.cashier'],
        ['id' => 21, 'employee_id' => 121, 'staff_name' => '收银测试员']
    );
}

function checkoutAuthorityIdempotency(int $number): string
{
    return sprintf('CHECKOUT-00000000-0000-4000-8000-%012d', $number);
}

function checkoutAuthorityCommand(int $number, string $requestId = '', ?int $expectedVersion = null): array
{
    $command = [
        'contractVersion' => CashierV3CheckoutSettlementKernel::CONTRACT_VERSION,
        'operation' => CashierV3CheckoutSettlementKernel::OPERATION_SAVE_DRAFT,
        'idempotencyKey' => checkoutAuthorityIdempotency($number),
        'workspaceId' => 'ws:7:21:checkout-authority-state',
        'stateContextId' => 'checkout-authority-state',
        'permissionSnapshotFingerprint' => 'roles:' . str_repeat('a', 32),
    ];
    if ($requestId !== '') {
        $command['requestId'] = $requestId;
    }
    if ($expectedVersion !== null) {
        $command['expectedVersion'] = $expectedVersion;
    }
    return $command;
}

function checkoutAuthoritySnapshot(int $variant = 1): array
{
    $saleAmount = 1000 + (($variant - 1) * 100);
    $snapshot = [
        'contractVersion' => CashierV3CheckoutSettlementKernel::AUTHORITY_CONTRACT_VERSION,
        'authorityOrigin' => 'server_final_lock_snapshot',
        'authoritySnapshotVersion' => $variant,
        'authoritySnapshotFingerprint' => '',
        'tenantId' => '0',
        'organizationId' => '3',
        'organizationPath' => '/1/3/',
        'organizationName' => '结账测试组织',
        'storeId' => 7,
        'storeName' => '结账测试门店',
        'workspaceId' => 'ws:7:21:checkout-authority-state',
        'stateContextId' => 'checkout-authority-state',
        'permissionSnapshotFingerprint' => 'roles:' . str_repeat('a', 32),
        'memberId' => 1001,
        'memberName' => '权益明细测试会员',
        'operatorId' => 21,
        'operatorName' => '收银测试员',
        'businessDate' => '2026-07-29',
        'businessTimezone' => 'Asia/Shanghai',
        'occurredAt' => 1785283200 + $variant,
        'recordedAt' => 1785283201 + $variant,
        'sourceDocument' => [
            'type' => 'cashier_workspace',
            'id' => 'ws:7:21:checkout-authority-state',
            'no' => 'CHECKOUT-AUTHORITY-' . $variant,
        ],
        'saleLines' => [[
            'authorityKey' => 'sale:project:501',
            'saleClassification' => 'formal_sale',
            'sourceType' => 'project',
            'sourceId' => 501,
            'sourceVersion' => $variant,
            'quantity' => 1,
            'originalAmountCents' => $saleAmount + 100,
            'discountAmountCents' => 100,
            'saleAmountCents' => $saleAmount,
            'sourceNameSnapshot' => '结账测试项目',
            'sourceCodeSnapshot' => 'PROJECT-501',
            'categoryIdSnapshot' => 51,
            'categoryNameSnapshot' => '测试分类',
        ]],
        'entitlementLines' => [[
            'authorityKey' => 'entitlement:opaque-cart-row-alpha',
            'sourceKind' => 'count_card',
            'holderId' => 701,
            'entitlementSourceDetailId' => 1701,
            'sourceVersion' => $variant,
            'projectId' => 502,
            'projectVersion' => $variant,
            'quantity' => 1,
            'actualEntitlementAmountCents' => 2500,
            'sourceNameSnapshot' => '权益明细测试次卡',
            'sourceCodeSnapshot' => 'CARD-701',
            'projectNameSnapshot' => '权益明细测试项目',
            'projectCategoryIdSnapshot' => 51,
            'projectCategoryNameSnapshot' => '测试分类',
        ]],
        'paymentDetails' => [],
        'balanceDeduction' => [
            'authorityKey' => '',
            'accountId' => '',
            'accountVersion' => 0,
            'amountCents' => 0,
        ],
        'debt' => [
            'authorityKey' => '',
            'policyVersion' => 0,
            'amountCents' => 0,
        ],
    ];
    $snapshot['authoritySnapshotFingerprint'] =
        CashierV3CheckoutSettlementKernel::authorityFingerprint($snapshot);
    return $snapshot;
}

function checkoutAuthoritySources(array $sources): CashierV3CheckoutVerifiedSourceSet
{
    $rows = [];
    foreach ($sources as $source) {
        $rows[] = [
            'tenantId' => '0',
            'storeId' => 7,
            'kind' => $source[0],
            'id' => $source[1],
            'sourceVersion' => $source[3] ?? 1,
            'role' => $source[2],
        ];
    }
    return CashierV3CheckoutVerifiedSourceSet::fromServerVerifiedAuthorityRows('0', 7, $rows);
}

function checkoutAuthorityReset(): void
{
    Db::execute('DELETE FROM `eb_cashier_v3_checkout_source_reference`');
    Db::execute('DELETE FROM `eb_cashier_v3_checkout_payment_draft`');
    Db::execute('DELETE FROM `eb_cashier_v3_checkout_line_draft`');
    Db::execute('DELETE FROM `eb_cashier_v3_checkout_request`');
}

function checkoutAuthorityCount(string $table): int
{
    return (int)Db::name($table)->count();
}
