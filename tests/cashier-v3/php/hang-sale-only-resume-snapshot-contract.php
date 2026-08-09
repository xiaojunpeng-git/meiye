<?php
declare(strict_types=1);

/**
 * A resumable normal hang must keep every persisted sale-line authority field.
 * In particular, salespeople_json cannot be reconstructed from the public line.
 */

$backendRoot = __DIR__ . '/../../../后端代码';
foreach ([
    '/app/services/cashier/v3/CashierV3ResultCode.php',
    '/app/services/cashier/v3/CashierV3CommandException.php',
    '/app/services/cashier/v3/CashierV3ResourceScope.php',
    '/app/services/cashier/v3/CashierV3OperatorScope.php',
    '/app/services/cashier/v3/CashierV3DataScopeContext.php',
    '/app/services/cashier/v3/hang/authority/CashierV3HangOrderAuthorityException.php',
    '/app/services/cashier/v3/hang/authority/CashierV3HangOrderPlanV1.php',
    '/app/services/cashier/v3/cashier/CashierV3CashierWorkspaceServices.php',
] as $file) {
    require_once $backendRoot . $file;
}

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\cashier\CashierV3CashierWorkspaceServices;
use app\services\cashier\v3\hang\authority\CashierV3HangOrderPlanV1;

$passed = 0;
$failed = 0;

function hangSaleResumeAssert(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS {$name}\n";
        return;
    }
    $failed++;
    echo "FAIL {$name}" . ($detail === '' ? '' : ': ' . $detail) . "\n";
}

function hangSaleResumeFailure(callable $callable): string
{
    try {
        $callable();
    } catch (CashierV3CommandException $exception) {
        return (string)($exception->getDetail()['reason'] ?? '');
    } catch (\Throwable $exception) {
        return get_class($exception) . ':' . $exception->getMessage();
    }
    return '';
}

$operator = new CashierV3OperatorScope(8, 1, '3', '0');
$dataScope = new CashierV3DataScopeContext(
    1,
    1,
    8,
    '0',
    '3',
    [8],
    CashierV3DataScopeContext::MODE_STORES,
    [],
    false,
    '',
    'hang-sale-resume-permission-v1',
    ['cashier.v3.hang'],
    ['id' => 1]
);
$lineKey = 'sale:' . substr(hash('sha256', 'hang-sale-resume-snapshot'), 0, 48);
$authoritySnapshot = [
    'product' => ['id' => 501, 'productType' => 0],
    'productVersion' => 3,
    'sku' => ['id' => 1501, 'originalPriceCents' => 8800, 'priceCents' => 8800],
    'skuVersion' => 4,
];
$salespeopleJson = '[]';
$trustedRow = [
    'line_key' => $lineKey,
    'line_role' => 'sale',
    'member_id' => 0,
    'holder_id' => 0,
    'source_detail_id' => 0,
    'project_id' => 0,
    'catalog_product_id' => 501,
    'catalog_sku_id' => 1501,
    'catalog_product_type' => 0,
    'quantity' => 1,
    'source_version' => 3,
    'detail_version' => 4,
    'unit_price_cents' => 8800,
    'original_unit_price_cents' => 8800,
    'authority_fingerprint' => hash('sha256', json_encode($authoritySnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
    'authority_snapshot_json' => json_encode($authoritySnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    'service_object' => '',
    'craftsmen_json' => '[]',
    'salespeople_json' => $salespeopleJson,
    'is_experience' => 0,
    'display_snapshot_json' => json_encode([
        'productId' => 501,
        'skuId' => 1501,
        'name' => '挂单恢复测试产品',
        'kind' => '产品',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    'sort_no' => 1,
];
$publicLine = [
    'id' => $lineKey,
    'lineRole' => 'sale',
    'memberId' => 0,
    'productId' => 501,
    'productVersion' => 3,
    'skuId' => 1501,
    'skuVersion' => 4,
    'projectId' => 0,
    'quantity' => 1,
    'lineAmountCents' => 8800,
    'serviceObject' => '',
    'craftsmen' => [],
    'isExperience' => false,
    'kind' => '产品',
    'name' => '挂单恢复测试产品',
];
$plan = CashierV3HangOrderPlanV1::fromLockedDraft([
    'commandIdempotencyKey' => 'HANG-SALE-RESUME-COMMAND-0001',
    'preparationRequestId' => 'HANG-SALE-RESUME-PREPARE-0001',
    'preparationToken' => hash('sha256', 'hang-sale-resume-prepare'),
    'mode' => CashierV3HangOrderPlanV1::MODE_NORMAL,
    'organizationPathSnapshot' => '/1/3/',
    'organizationNameSnapshot' => '当前组织',
    'storeNameSnapshot' => '本店',
    'memberNameSnapshot' => '',
    'operatorNameSnapshot' => '操作员一',
    'businessDate' => '2026-08-04',
    'businessTimezone' => 'Asia/Shanghai',
    'occurredAt' => 1785830400,
    'recordedAt' => 1785830401,
], [
    'workspaceId' => 'ws:8:1:CTX-HANG-SALE-RESUME-01',
    'stateContextId' => 'CTX-HANG-SALE-RESUME-01',
    'customerMode' => 'guest',
    'memberId' => 0,
    'status' => 'editing',
    'lineFingerprint' => hash('sha256', 'hang-sale-resume-line'),
    'complete' => true,
    'lines' => [$publicLine],
], $operator, $dataScope, [$trustedRow]);

$snapshot = json_decode((string)$plan->lines()[0]['workspace_snapshot_json'], true);
hangSaleResumeAssert(
    'normal hang snapshots salespeople authority JSON',
    is_array($snapshot) && ($snapshot['salespeople_json'] ?? null) === $salespeopleJson,
    json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

$workspaceClass = new ReflectionClass(CashierV3CashierWorkspaceServices::class);
$workspaceSource = (string)file_get_contents($backendRoot . '/app/services/cashier/v3/cashier/CashierV3CashierWorkspaceServices.php');
hangSaleResumeAssert(
    'restore replaces the current cart only inside the transaction',
    strpos($workspaceSource, '$existingRows = $this->lineRows($workspaceId, true);') !== false
        && strpos($workspaceSource, "'cashier_hang_restore_workspace_replace_incomplete'") !== false
        && strpos($workspaceSource, '当前购物车不为空，请先完成或清空现有内容后再提单。') === false
);
$workspace = $workspaceClass->newInstanceWithoutConstructor();
$restore = $workspaceClass->getMethod('normalizeRestoredSaleLine');
$restored = $restore->invoke($workspace, $snapshot, 'ws:8:1:CTX-HANG-SALE-RESUME-02', 0, 1);
hangSaleResumeAssert(
    'normal hang authority snapshot passes strict restore validation',
    is_array($restored)
        && ($restored['salespeople_json'] ?? '') === $salespeopleJson
        && ($restored['authority_fingerprint'] ?? '') === $trustedRow['authority_fingerprint']
);

$missingSalespeople = $snapshot;
unset($missingSalespeople['salespeople_json']);
hangSaleResumeAssert(
    'missing salesperson authority remains fail-closed',
    hangSaleResumeFailure(function () use ($restore, $workspace, $missingSalespeople): void {
        $restore->invoke($workspace, $missingSalespeople, 'ws:8:1:CTX-HANG-SALE-RESUME-03', 0, 1);
    }) === 'cashier_hang_restore_sale_snapshot_invalid'
);

$tamperedAuthority = $snapshot;
$tamperedAuthority['authority_snapshot_json'] = json_encode([
    'product' => ['id' => 502, 'productType' => 0],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
hangSaleResumeAssert(
    'tampered authority snapshot remains fail-closed',
    hangSaleResumeFailure(function () use ($restore, $workspace, $tamperedAuthority): void {
        $restore->invoke($workspace, $tamperedAuthority, 'ws:8:1:CTX-HANG-SALE-RESUME-04', 0, 1);
    }) === 'cashier_hang_restore_authority_mismatch'
);

echo "hang-sale-only-resume-snapshot-contract: " . ($passed + $failed)
    . " assertions, {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
