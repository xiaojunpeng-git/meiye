<?php
declare(strict_types=1);

/** Read-only regression: final entitlement authority must match actual-sale selection under card debt. */
if (getenv('R17_LOCAL_READONLY') !== '1') {
    throw new RuntimeException('explicit local read-only flag required');
}
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require $backend . '/vendor/autoload.php';
$app = new \think\App($backend . '/');
$app->initialize();

use app\services\cashier\v3\card\CashierV3CardRuleEntitlementAuthorityServices;
use app\services\cashier\v3\card\CashierV3CardSaleActualAmountServices;
use app\services\cashier\v3\cashier\CashierV3CardOriginDebtResolver;
use app\services\cashier\v3\cashier\CashierV3EntitlementActualAmountAllocator;
use app\services\cashier\v3\cashier\CashierV3EntitlementProjectionServices;
use app\services\cashier\v3\checkout\CashierV3DirectSnapshotEntitlementSettlementServices;
use app\services\cashier\v3\checkout\CashierV3EntitlementCompletionKernel;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\order\store\WriteOffOrderServices;
use think\facade\Db;

if ((string)Db::query('SELECT DATABASE() AS db')[0]['db'] !== 'ruihao') {
    throw new RuntimeException('this regression requires the local ruihao database');
}
// V3 debt authority links the sales order, not the compatibility card order.
$holders = Db::name('cashier_v3_card_purchase_receipt')->alias('r')
    ->join('cashier_v3_debt_authority a', 'a.sales_order_id=r.sales_order_id AND a.member_id=r.member_id')
    ->join('store_debt d', 'd.id=a.debt_id')
    ->join('user_card_holder h', 'h.id=r.card_holder_id')
    ->whereIn('d.status', [0, 3])->where('h.is_del', 0)
    ->where('r.status', 'completed')
    ->where('h.write_surplus_times', '>', 0)
    ->field('r.card_holder_id AS holder_id,r.legacy_order_id AS origin_order_id,r.member_id,d.status AS debt_status')
    ->limit(1000)->select()->toArray();
$rules = new CashierV3CardRuleEntitlementAuthorityServices();
$saleAmounts = new CashierV3CardSaleActualAmountServices();
$resolver = new CashierV3CardOriginDebtResolver();
$writeoff = app()->make(WriteOffOrderServices::class);
$matched = ['active' => 0, 'cancelled' => 0];
foreach ($holders as $source) {
    $order = Db::name('store_order')->where('id', (int)$source['origin_order_id'])->find();
    $holder = Db::name('user_card_holder')->where('id', (int)$source['holder_id'])->find();
    $rows = Db::name('store_order_cart_info')
        ->where('oid', (int)$source['origin_order_id'])
        ->where('cart_type', 2)->where('product_type', 6)
        ->order('id asc')->select()->toArray();
    $actual = $saleAmounts->forOrders([(int)$source['origin_order_id']], $rows);
    $tenant = (string)($order['tenant_id'] ?? '0');
    $state = (int)$source['debt_status'] === 3 ? 'cancelled' : 'active';
    $unavailable = $state === 'cancelled'
        ? $resolver->cancelledCardDebt($order, (int)$source['member_id'], $tenant)
        : $resolver->pendingV3CardDebt($order, (int)$source['member_id']);
    if ($unavailable === null || bccomp($unavailable, '0', 2) <= 0) continue;
    foreach ($rows as $detail) {
        $detailId = (int)$detail['id'];
        if (!isset($actual[$detailId]) || (int)$detail['is_gift'] === 1) continue;
        $rule = $rules->authorityForDetail($tenant, (int)$holder['id'], $detailId);
        if (!$rule || $rule['ruleType'] !== 'normal'
            || (int)$actual[$detailId] === (int)$rule['purchaseAmountCents']) continue;
        $snapshot = json_decode((string)$detail['cart_info'], true);
        $snapshot = is_array($snapshot) ? $snapshot : [];
        $total = (int)$rule['totalTimes'];
        $remaining = (int)$rule['remainingTimes'];
        $actualMoney = bcdiv((string)$actual[$detailId], '100', 2);
        $allocationSnapshot = $snapshot;
        if ((int)$actual[$detailId] % 100 !== 0) {
            $allocationSnapshot['amountCalculationVersion'] = 'operation-cent-card-sale-actual-amount-'
                . CashierV3EntitlementActualAmountAllocator::CALCULATION_VERSION;
        }
        $actualRemaining = CashierV3EntitlementActualAmountAllocator::remainingForSnapshot(
            $actualMoney, $total, $total - $remaining, $allocationSnapshot
        );
        $configuredRemaining = CashierV3EntitlementActualAmountAllocator::remainingForSnapshot(
            bcdiv((string)$rule['purchaseAmountCents'], '100', 2), $total,
            $total - $remaining, $snapshot
        );
        $pending = $state === 'active' ? (float)$unavailable : 0.0;
        $expected = $writeoff->calcEffectiveWriteSurplusTimes($detail, $pending, $order, $actualRemaining, $rows);
        $oldResult = $writeoff->calcEffectiveWriteSurplusTimes($detail, $pending, $order, $configuredRemaining, $rows);
        if ($expected <= 0 || ($state === 'cancelled' && $oldResult !== 0)) continue;

        // Invoke only the final authority read; never submit a checkout or write a service fact.
        $reflection = new ReflectionClass(CashierV3DirectSnapshotEntitlementSettlementServices::class);
        $adapter = $reflection->newInstanceWithoutConstructor();
        $rulesProperty = $reflection->getProperty('cardRules');
        $rulesProperty->setAccessible(true);
        $rulesProperty->setValue($adapter, $rules);
        $method = $reflection->getMethod('loadEntitlementAuthorities');
        $method->setAccessible(true);
        $storeId = (int)$order['store_id'];
        $lineId = 'r17-dynamic-source-' . $detailId;
        $line = [
            'line_id' => $lineId, 'source_id' => (int)$holder['id'],
            'entitlement_source_detail_id' => $detailId, 'project_id' => (int)$detail['product_id'],
            'source_type' => 'card_holder', 'source_name_snapshot' => (string)$holder['card_name'],
            'source_code_snapshot' => (string)$holder['card_no'],
            'project_name_snapshot' => (string)($snapshot['productInfo']['store_name'] ?? ''),
        ];
        $result = $method->invoke($adapter, [$line], (int)$source['member_id'],
            new CashierV3OperatorScope($storeId, 1, '', $tenant),
            new CashierV3DataScopeContext(1, 1, $storeId, $tenant, '', [$storeId],
                CashierV3DataScopeContext::MODE_STORES, [], false, '', 'r17-read-only',
                ['cashier.v3.cashier'], []));
        $authority = $result[$lineId] ?? [];
        $expectedVersion = ((int)$actual[$detailId] % 100 === 0 ? '' : 'operation-cent-')
            . 'card-sale-actual-amount-'
            . CashierV3EntitlementActualAmountAllocator::CALCULATION_VERSION;
        if ((int)($authority['debtLimitedUsableTimes'] ?? -1) !== $expected
            || (string)($authority['purchaseAmount'] ?? '') !== $actualMoney
            || (int)($authority['physicalRemainingTimes'] ?? -1) !== $remaining
            || (string)($authority['amountCalculationVersion'] ?? '') !== $expectedVersion) {
            throw new RuntimeException('final authority differs from actual-sale selection for detail ' . $detailId);
        }
        ++$matched[$state];
    }
}
if ($matched['active'] === 0 || $matched['cancelled'] === 0) {
    throw new RuntimeException('missing active or cancelled repriced-card regression sources');
}
$projectionClass = new ReflectionClass(CashierV3EntitlementProjectionServices::class);
$projectionMethod = $projectionClass->getMethod('projectActualSaleAmount');
$projectionMethod->setAccessible(true);
$centSnapshot = $projectionMethod->invoke($projectionClass->newInstanceWithoutConstructor(),
    ['write_times' => 10], 10, 238805, ['sourceType' => 'cashier_v3_card_purchase']);
if ($centSnapshot['purchaseAmount'] !== '2388.05'
    || $centSnapshot['remainingAmount'] !== '2388.05'
    || strpos((string)$centSnapshot['calculationVersion'], 'operation-cent-') !== 0
    || CashierV3EntitlementCompletionKernel::allocateActualAmountCents(
        238805, 10, 0, 1, $centSnapshot['calculationVersion']) !== 23880) {
    throw new RuntimeException('cent-accurate selector and final kernel versions differ');
}
echo 'PASS read-only actual-sale settlement sources=' . json_encode($matched) . PHP_EOL;
