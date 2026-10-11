<?php
declare(strict_types=1);

/** 第八轮本地真实单据只读回归；只查询既有替换，不生成、修改或核销权益。 */
require '/var/www/html/vendor/autoload.php';
(new \think\App('/var/www/html/'))->initialize();

use app\services\cashier\v3\card\CashierV3CardRuleEntitlementAuthorityServices;
use app\services\cashier\v3\card\CashierV3ProjectReplacementDebtPolicy;
use app\services\cashier\v3\cashier\CashierV3CardOriginDebtResolver;
use app\services\cashier\v3\cashier\CashierV3EntitlementActualAmountAllocator;
use app\services\order\store\WriteOffOrderServices;
use think\facade\Db;

if (config('database.connections.mysql.hostname') !== 'mysql'
    || config('database.connections.mysql.database') !== 'ruihao') {
    throw new RuntimeException('This regression only reads the local ruihao fixture');
}

$checks = 0;
function r8Check(bool $ok, string $label): void
{
    global $checks;
    if (!$ok) throw new RuntimeException($label);
    $checks++;
    echo 'PASS ' . $label . "\n";
}

Db::execute('START TRANSACTION READ ONLY');
try {
    $operation = (array)Db::name('cashier_v3_card_operation')
        ->where('operation_no', 'CK26101100001')->find();
    r8Check(($operation['operation_type'] ?? '') === 'project_replacement'
        && ($operation['operation_status'] ?? '') === 'succeeded', 'Replacement audit is authoritative');
    $orderId = (int)($operation['origin_order_id'] ?? 0);
    $order = (array)Db::name('store_order')->where('id', $orderId)->find();
    $rows = Db::name('store_order_cart_info')->where('oid', $orderId)
        ->where('cart_type', 2)->where('product_type', 6)->order('id asc')->select()->toArray();
    $result = json_decode((string)$operation['result_snapshot_json'], true) ?: [];
    $sourceId = (int)($result['stateMutation']['projectMutations'][0]['sourceDetailId'] ?? 0);
    $source = null;
    $target = null;
    foreach ($rows as $row) {
        if ((int)$row['id'] === $sourceId) $source = $row;
        if (CashierV3ProjectReplacementDebtPolicy::isTransferredRight($row)) {
            $snapshot = json_decode((string)$row['cart_info'], true) ?: [];
            if (($snapshot['cardOperationId'] ?? '') === $operation['operation_id']) $target = $row;
        }
    }
    r8Check($source !== null && $target !== null && (int)$target['write_surplus_times'] === 1,
        'One transferred target right exists on the original card');

    $pending = (new CashierV3CardOriginDebtResolver())->unavailableCardDebt(
        $order, (int)$order['uid'], (string)$operation['tenant_id'], false
    );
    $writeoff = app()->make(WriteOffOrderServices::class);
    $pendingForRow = new ReflectionMethod($writeoff, 'resolveCartPendingDebtForWriteoff');
    $pendingForRow->setAccessible(true);
    $targetSnapshot = json_decode((string)$target['cart_info'], true) ?: [];
    $targetRemaining = CashierV3EntitlementActualAmountAllocator::remainingForSnapshot(
        (string)$target['pay_price'], (int)$target['write_times'],
        (int)$target['write_times'] - (int)$target['write_surplus_times'], $targetSnapshot
    );
    r8Check(bccomp($pending, '0', 2) > 0
        && (float)$pendingForRow->invoke($writeoff, $target, (float)$pending, $order, $rows, false) === 0.0,
        'Origin debt is not allocated to the transferred target');
    r8Check($writeoff->calcEffectiveWriteSurplusTimes(
        $target, (float)$pending, $order, $targetRemaining, $rows, false
    ) === 1 && $writeoff->usableEntitlementAmount(
        $target, $order, $targetRemaining, (float)$pending, $rows, false
    ) === $targetRemaining, 'Selector and writeoff both expose the target once');
    r8Check((new CashierV3CardRuleEntitlementAuthorityServices())->debtLimitForDetail(
        $target, $order, false
    ) === null, 'Shared-count debt limit does not block the transferred target again');

    $sourceDebt = (float)$pendingForRow->invoke($writeoff, $source, (float)$pending, $order, $rows, false);
    r8Check($sourceDebt === (float)$pending
        && (string)Db::name('store_order_cart_info')->where('id', $target['id'])->value('pay_price') === $target['pay_price'],
        'Original right retains all debt; target price stays unchanged');

    $explicit = $target;
    $explicit['debt_amount'] = '5.00';
    r8Check((float)$pendingForRow->invoke($writeoff, $explicit, (float)$pending, $order, $rows, false) === 5.0
        && $writeoff->calcEffectiveWriteSurplusTimes(
            $explicit, (float)$pending, $order, $targetRemaining, $rows, false
        ) === 0, 'An explicit target-line debt still limits use');

    $notReplacement = $target;
    $changed = $targetSnapshot;
    $changed['sourceType'] = 'cashier_v3_project_upgrade';
    $notReplacement['cart_info'] = json_encode($changed, JSON_UNESCAPED_UNICODE);
    r8Check(!CashierV3ProjectReplacementDebtPolicy::isTransferredRight($notReplacement),
        'Project upgrade cannot inherit the replacement exemption');
    $changed['sourceType'] = 'cashier_v3_project_replacement';
    $changed['cardOperationId'] = 'invalid';
    $notReplacement['cart_info'] = json_encode($changed, JSON_UNESCAPED_UNICODE);
    r8Check(!CashierV3ProjectReplacementDebtPolicy::isTransferredRight($notReplacement),
        'Incomplete operation provenance cannot bypass debt');
    echo "R8_PROJECT_REPLACEMENT_DEBT: {$checks} PASS; database writes: 0\n";
} finally {
    Db::execute('ROLLBACK');
}
