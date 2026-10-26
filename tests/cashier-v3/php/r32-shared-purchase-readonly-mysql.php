<?php
declare(strict_types=1);

/** 本地瑞昊共享次数卡只读联调：选择器、最终权威与会员摘要同源。 */
if (getenv('R32_LOCAL_READONLY') !== '1') throw new RuntimeException('local read-only flag required');
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require $backend . '/vendor/autoload.php';
(new \think\App($backend . '/'))->initialize();

use app\services\cashier\v3\card\CashierV3CardRuleEntitlementAuthorityServices;
use app\services\cashier\v3\cashier\CashierV3CashierMemberSummaryServices;
use app\services\cashier\v3\cashier\CashierV3EntitlementProjectionServices;
use app\services\cashier\v3\checkout\CashierV3DirectSnapshotEntitlementSettlementServices;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use think\facade\Db;

if ((string)Db::query('SELECT DATABASE() AS db')[0]['db'] !== 'ruihao') {
    throw new RuntimeException('local ruihao database required');
}
$candidates = Db::name('cashier_v3_card_rule_state')->alias('s')
    ->join('user_card_holder h', 'h.id=s.card_holder_id')
    ->join('store_order o', 'o.id=h.oid')
    ->where('s.rule_type', 'choice_count')->where('s.shared_remaining_times', '>', 0)
    ->where('o.pay_price', '2000.00')->where('o.paid', 1)->where('h.is_del', 0)
    ->field('s.card_holder_id,s.tenant_id,s.shared_total_times,s.shared_remaining_times,h.uid,h.card_name,h.card_no,o.id AS order_id,o.store_id')
    ->limit(30)->select()->toArray();
$matched = false;
$failures = [];
foreach ($candidates as $candidate) {
    $holderId = (int)$candidate['card_holder_id'];
    $memberId = (int)$candidate['uid'];
    $storeId = (int)$candidate['store_id'];
    $tenantId = (string)$candidate['tenant_id'];
    $scope = new CashierV3OperatorScope($storeId, 1, '', $tenantId);
    $dataScope = new CashierV3DataScopeContext(1, 1, $storeId, $tenantId, '', [$storeId],
        CashierV3DataScopeContext::MODE_STORES, [], false, '', 'r32-read-only', ['cashier.v3.cashier'], []);
    try {
        $sources = app()->make(CashierV3EntitlementProjectionServices::class)
            ->reservationSources($memberId, $scope, $dataScope);
        $source = null;
        foreach ($sources as $row) {
            if ((int)($row['id'] ?? 0) === $holderId) { $source = $row; break; }
        }
        if (!$source || (string)$source['purchaseAmount'] !== '2000.00') continue;
        $project = null;
        foreach ($source['projects'] as $row) {
            if (str_starts_with((string)($row['amountCalculationVersion'] ?? ''), 'shared-card-purchase-amount-')
                && !empty($row['selectable'])) { $project = $row; break; }
        }
        if (!$project || (string)$project['purchaseAmount'] !== '2000.00') continue;
        $detail = Db::name('store_order_cart_info')->where('id', (int)$project['id'])->find();
        $decoded = json_decode((string)$detail['cart_info'], true);
        $lineId = 'r32-shared-' . (int)$project['id'];
        $line = ['line_id' => $lineId, 'source_id' => $holderId,
            'entitlement_source_detail_id' => (int)$project['id'],
            'project_id' => (int)$detail['product_id'], 'source_type' => 'card_holder',
            'source_name_snapshot' => (string)$candidate['card_name'],
            'source_code_snapshot' => (string)$candidate['card_no'],
            'project_name_snapshot' => (string)($decoded['productInfo']['store_name'] ?? '')];
        $final = (new ReflectionClass(CashierV3DirectSnapshotEntitlementSettlementServices::class))
            ->newInstanceWithoutConstructor();
        $property = new ReflectionProperty(CashierV3DirectSnapshotEntitlementSettlementServices::class, 'cardRules');
        $property->setAccessible(true);
        $property->setValue($final, new CashierV3CardRuleEntitlementAuthorityServices());
        $method = new ReflectionMethod(CashierV3DirectSnapshotEntitlementSettlementServices::class, 'loadEntitlementAuthorities');
        $method->setAccessible(true);
        $authority = $method->invoke($final, [$line], $memberId, $scope, $dataScope)[$lineId];
        if ((string)$authority['purchaseAmount'] !== (string)$project['purchaseAmount']
            || (string)$authority['amountCalculationVersion'] !== (string)$project['amountCalculationVersion']) {
            throw new RuntimeException('selector and final authority amount mismatch');
        }
        (new CashierV3CashierMemberSummaryServices())->read($memberId, $storeId);
        echo 'PASS local shared card holder=' . $holderId . ' amount=' . $authority['purchaseAmount'] . PHP_EOL;
        $matched = true;
        break;
    } catch (\Throwable $error) {
        if (str_contains($error->getMessage(), 'amount mismatch')) throw $error;
        $failures[] = $holderId . ': ' . $error->getMessage();
    }
}
if (!$matched) throw new RuntimeException('no usable local ¥2000/30 shared card completed all read paths: '
    . json_encode(array_slice($failures, 0, 5), JSON_UNESCAPED_UNICODE));
echo "R32_SHARED_PURCHASE_READONLY_PASS\n";
