<?php

$backendRoot = is_file('/var/www/html/app/services/cashier/v3/CashierV3ResultCode.php')
    ? '/var/www/html'
    : dirname(__DIR__, 3) . '/后端代码';
require_once $backendRoot . '/app/services/cashier/v3/CashierV3ResultCode.php';
require_once $backendRoot . '/app/services/cashier/v3/CashierV3CommandException.php';
require_once $backendRoot . '/app/services/cashier/v3/card/CashierV3CardOperationReadinessGuard.php';
require_once $backendRoot . '/app/services/cashier/v3/card/CashierV3CardOperationKernel.php';

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\card\CashierV3CardOperationReadinessGuard;
use app\services\cashier\v3\card\CashierV3CardOperationKernel;

$failed = 0;
function check_card_operation($condition, $name)
{
    global $failed;
    if (!$condition) {
        $failed++;
        fwrite(STDERR, "FAIL: {$name}\n");
    }
}

$source = [
    'holderId' => 10,
    'holderVersion' => 7,
    'originOrderId' => 100,
    'originMemberId' => 1,
    'currentMemberId' => 1,
    'cardStatus' => 'enabled',
    'cardName' => '测试次卡',
    'cardNo' => 'K100',
    'effectiveWriteStart' => 0,
    'effectiveWriteEnd' => 1798761600,
    'remainingValueCents' => 12000,
    'projects' => [[
        'detailId' => 301,
        'detailVersion' => 3,
        'projectId' => 401,
        'remainingTimes' => 4,
        'remainingValueCents' => 8000,
    ]],
];
$context = [
    'tenantId' => 'tenant-1', 'organizationId' => 'org-1', 'storeId' => 1,
    'operatorId' => 9, 'businessDate' => '2026-07-30', 'businessTimezone' => 'Asia/Shanghai',
    'occurredAt' => 1785369600, 'recordedAt' => 1785369600,
];

$transfer = CashierV3CardOperationKernel::plan([
    'operationType' => 'card_transfer', 'sourceCardHolderId' => 10, 'sourceCardHolderVersion' => 7,
    'idempotencyKey' => 'CARD-TRANSFER-0001', 'reason' => '会员本人申请',
], $source, ['memberId' => 2, 'memberName' => '新会员'], $context);
check_card_operation($transfer['operationStatus'] === 'succeeded', 'transfer is direct');
check_card_operation($transfer['stateMutation']['currentMemberId'] === 2, 'transfer changes current owner only');
check_card_operation($transfer['sourceCard']['originMemberId'] === 1, 'transfer preserves origin member snapshot');

$extension = CashierV3CardOperationKernel::plan([
    'operationType' => 'card_extension', 'sourceCardHolderId' => 10, 'sourceCardHolderVersion' => 7,
    'idempotencyKey' => 'CARD-EXTENSION-0001', 'reason' => '活动延期', 'newWriteEnd' => 1801353600,
], $source, [], $context);
check_card_operation($extension['stateMutation']['effectiveWriteEnd'] === 1801353600, 'extension updates effective end');
check_card_operation(
    ($extension['resultSnapshot']['operationStatus'] ?? '') === 'succeeded'
    && (int)($extension['resultSnapshot']['stateMutation']['effectiveWriteEnd'] ?? 0) === 1801353600,
    'operation result snapshot freezes the accepted state transition'
);

$disable = CashierV3CardOperationKernel::plan([
    'operationType' => 'card_disable', 'sourceCardHolderId' => 10, 'sourceCardHolderVersion' => 7,
    'idempotencyKey' => 'CARD-DISABLE-0001', 'reason' => '客户要求暂停',
], $source, [], $context);
check_card_operation($disable['stateMutation']['cardStatus'] === 'disabled', 'disable changes state');

$replacement = CashierV3CardOperationKernel::plan([
    'operationType' => 'project_replacement', 'sourceCardHolderId' => 10, 'sourceCardHolderVersion' => 7,
    'idempotencyKey' => 'PROJECT-REPLACEMENT-0001', 'reason' => '项目调整',
    'projectLines' => [['sourceDetailId' => 301, 'quantity' => 2]],
], $source, ['catalogId' => 501, 'catalogName' => '目标项目'], $context);
check_card_operation($replacement['operationStatus'] === 'succeeded', 'replacement is direct');
check_card_operation($replacement['lines'][0]['quantityAfter'] === 2, 'replacement quantity is frozen');
check_card_operation(
    count($replacement['lines']) === 2
    && ($replacement['lines'][1]['lineRole'] ?? '') === 'target_project'
    && (int)($replacement['lines'][1]['quantityAfter'] ?? 0) === 2,
    'replacement freezes one target right line at the selected quantity'
);
$replacementRepeated = CashierV3CardOperationKernel::plan([
    'operationType' => 'project_replacement', 'sourceCardHolderId' => 10, 'sourceCardHolderVersion' => 7,
    'idempotencyKey' => 'PROJECT-REPLACEMENT-0002', 'reason' => '项目调整',
    'projectLines' => [['sourceDetailId' => 301, 'quantity' => 1], ['sourceDetailId' => 301, 'quantity' => 3]],
], $source, ['catalogId' => 501, 'catalogName' => '目标项目'], $context);
check_card_operation(
    count($replacementRepeated['stateMutation']['projectMutations'] ?? []) === 1
    && (int)($replacementRepeated['stateMutation']['projectMutations'][0]['quantityAfter'] ?? -1) === 0,
    'repeated source selection is aggregated before available rights validation'
);
$roundingSource = $source;
$roundingSource['projects'] = [[
    'detailId' => 302,
    'detailVersion' => 4,
    'projectId' => 402,
    'remainingTimes' => 2,
    'remainingValueCents' => 67,
    'totalTimes' => 3,
    'totalValueCents' => 100,
]];
$replacementRounding = CashierV3CardOperationKernel::plan([
    'operationType' => 'project_replacement', 'sourceCardHolderId' => 10, 'sourceCardHolderVersion' => 7,
    'idempotencyKey' => 'PROJECT-REPLACEMENT-0003', 'reason' => '项目调整',
    'projectLines' => [['sourceDetailId' => 302, 'quantity' => 1]],
], $roundingSource, ['catalogId' => 501, 'catalogName' => '目标项目'], $context);
check_card_operation(
    (int)($replacementRounding['lines'][0]['amountCents'] ?? -1) === 33,
    'replacement allocation uses original total price instead of rounded remaining value'
);

$upgrade = CashierV3CardOperationKernel::plan([
    'operationType' => 'card_upgrade', 'sourceCardHolderId' => 10, 'sourceCardHolderVersion' => 7,
    'idempotencyKey' => 'CARD-UPGRADE-0001', 'reason' => '升级套餐',
], $source, ['catalogId' => 601, 'catalogName' => '高级卡', 'priceCents' => 20000], $context);
check_card_operation($upgrade['operationStatus'] === 'awaiting_checkout', 'positive upgrade waits for checkout');
check_card_operation($upgrade['checkoutSettlement']['settlementDeltaCents'] === 8000, 'upgrade delta uses remaining value');
check_card_operation(
    $upgrade['stateMutation']['cardStatus'] === 'enabled'
    && $upgrade['stateMutation']['projectMutations'] === []
    && $upgrade['settledAt'] === 0,
    'pending upgrade changes no current right before formal checkout'
);
$zeroDeltaUpgrade = CashierV3CardOperationKernel::plan([
    'operationType' => 'card_upgrade', 'sourceCardHolderId' => 10, 'sourceCardHolderVersion' => 7,
    'idempotencyKey' => 'CARD-UPGRADE-0002', 'reason' => '等值升级套餐',
], $source, ['catalogId' => 602, 'catalogName' => '等值升级卡', 'priceCents' => 12000], $context);
check_card_operation(
    $zeroDeltaUpgrade['operationStatus'] === 'awaiting_checkout'
    && $zeroDeltaUpgrade['requiresCheckout'] === true
    && $zeroDeltaUpgrade['settledAt'] === 0,
    'zero-difference upgrade still requires formal settlement'
);

$conflicted = false;
try {
    CashierV3CardOperationKernel::plan([
        'operationType' => 'card_transfer', 'sourceCardHolderId' => 10, 'sourceCardHolderVersion' => 6,
        'idempotencyKey' => 'CARD-TRANSFER-0002', 'reason' => '过期请求',
    ], $source, ['memberId' => 2], $context);
} catch (\Throwable $exception) {
    $conflicted = true;
}
check_card_operation($conflicted, 'old source version is rejected');

$runtimeTables = [
    'eb_cashier_v3_command_receipt',
    'eb_cashier_v3_entitlement_resource_version',
    'eb_cashier_v3_business_event',
    'eb_cashier_v3_outbox',
    'eb_cashier_v3_card_state',
    'eb_cashier_v3_card_operation',
    'eb_cashier_v3_card_operation_line',
];
$readyGuard = new CashierV3CardOperationReadinessGuard();
$readyGuard->setInspector(static function () use ($runtimeTables): array {
    return $runtimeTables;
});
$ready = true;
try {
    $readyGuard->assertReady();
} catch (Throwable $exception) {
    $ready = false;
}
check_card_operation($ready, 'card operation readiness accepts the complete atomic dependency set');

$missingGuard = new CashierV3CardOperationReadinessGuard();
$missingGuard->setInspector(static function () use ($runtimeTables): array {
    return array_values(array_diff($runtimeTables, ['eb_cashier_v3_outbox']));
});
$missingCode = '';
$missingTables = [];
try {
    $missingGuard->assertReady();
} catch (CashierV3CommandException $exception) {
    $missingCode = $exception->getResultCode();
    $missingTables = (array)($exception->getDetail()['missing_tables'] ?? []);
}
check_card_operation(
    $missingCode === 'COMMAND_TABLE_NOT_READY' && $missingTables === ['eb_cashier_v3_outbox'],
    'card operation readiness fails before a command when a gateway dependency is absent'
);

if ($failed > 0) {
    exit(1);
}
echo "card-operation kernel: 16/0 PASS\n";
