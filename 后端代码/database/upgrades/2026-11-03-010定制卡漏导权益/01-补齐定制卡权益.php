<?php
declare(strict_types=1);

/**
 * Recover five proven 007 custom-card entitlements in 010. The existing 007
 * and archived 010 sales, payments and writeoffs remain immutable; this creates
 * only a hidden zero-value carrier, a holder, benefit copies and an org grant.
 * Run without --apply for a read-only preflight. Production apply requires the
 * separately authorized, fixed candidate and a fresh 007 source comparison.
 */
if (PHP_SAPI !== 'cli') throw new RuntimeException('CLI_ONLY');
$root = dirname(__DIR__, 3);
if (!is_file($root . '/vendor/autoload.php')) $root = getcwd();
require $root . '/vendor/autoload.php';
(new think\App($root . '/'))->initialize();

use think\facade\Db;

const CUSTOM_CARD_RECOVERY = [
    79797 => ['uid' => 6959, 'store' => 3, 'shell' => 8163, 'paid' => '1280.00', 'base' => 50066, 'benefits' => [50067 => [6, 4], 50068 => [6, 4]]],
    82800 => ['uid' => 11287, 'store' => 3, 'shell' => 8163, 'paid' => '3280.00', 'base' => 51427, 'benefits' => [51428 => [12, 10]]],
    103935 => ['uid' => 11911, 'store' => 6, 'shell' => 8161, 'paid' => '2592.00', 'base' => 61097, 'benefits' => [61098 => [10, 6]]],
    105256 => ['uid' => 3908, 'store' => 4, 'shell' => 8162, 'paid' => '2388.00', 'base' => 61687, 'benefits' => [61688 => [15, 14], 61689 => [2, 2]]],
    127205 => ['uid' => 4694, 'store' => 3, 'shell' => 8163, 'paid' => '5584.00', 'base' => 72061, 'benefits' => [72062 => [8, 8], 72063 => [2, 2]]],
];

/** Exact original cents, used for conservation; never infer money from display text. */
function recoveryCents($value): int
{
    $s = trim((string)$value);
    if (!preg_match('/^(0|[1-9][0-9]*)\.[0-9]{2}$/D', $s)) throw new RuntimeException('INVALID_MONEY');
    [$yuan, $cent] = explode('.', $s);
    return (int)$yuan * 100 + (int)$cent;
}

/** Existing migrated order is evidence, not a target for rewriting. */
function recoveryPlan(int $oid, array $expected, bool $lock): array
{
    $query = Db::name('store_order')->where('id', $oid);
    $order = $lock ? $query->lock(true)->find() : $query->find();
    if (!$order || (int)$order['uid'] !== $expected['uid'] || (int)$order['store_id'] !== $expected['store']
        || recoveryCents($order['pay_price']) !== recoveryCents($expected['paid'])
        || (int)$order['paid'] !== 1 || (int)$order['refund_status'] !== 0
        || (int)$order['terminal_action'] !== 0 || (int)$order['is_del'] !== 0
        || (int)$order['is_user_del'] !== 0 || (int)$order['is_system_del'] !== 1) {
        throw new RuntimeException("SOURCE_ORDER_CHANGED:$oid");
    }
    $detailsQuery = Db::name('store_order_cart_info')->where('oid', $oid)->order('id', 'asc');
    $details = $lock ? $detailsQuery->lock(true)->select()->toArray() : $detailsQuery->select()->toArray();
    $byId = array_column($details, null, 'id');
    if (count($details) !== count($expected['benefits']) + 1 || !isset($byId[$expected['base']])) {
        throw new RuntimeException("DETAIL_SET_CHANGED:$oid");
    }
    $base = $byId[$expected['base']];
    if ((int)$base['product_id'] !== $expected['shell'] || (int)$base['cart_num'] !== 1
        || (int)$base['cart_type'] !== 0 || (int)$base['product_type'] !== 6) {
        throw new RuntimeException("SHELL_CHANGED:$oid");
    }
    $shell = Db::name('store_product')->where('id', $expected['shell'])->field('id,pid,store_name,is_del')->find();
    if (!$shell || (int)$shell['pid'] !== 8154 || (int)$shell['is_del'] !== 0) {
        throw new RuntimeException("SHELL_CATALOG_CHANGED:$oid");
    }
    $paidComponents = 0;
    $activeUses = [];
    $writeoffs = Db::name('store_order_writeoff')->where('oid', $oid)->select()->toArray();
    foreach ($writeoffs as $w) {
        if ((int)$w['status'] === 0) {
            $detailId = (int)$w['order_cart_id'];
            $activeUses[$detailId] = ($activeUses[$detailId] ?? 0) + (int)$w['writeoff_num'];
        }
    }
    $total = 0; $remaining = 0;
    foreach ($expected['benefits'] as $id => [$all, $left]) {
        $d = $byId[$id] ?? null;
        if (!$d || (int)$d['product_type'] !== 6 || (int)$d['cart_type'] !== 0
            || (int)$d['cart_num'] !== $all || (int)$d['write_times'] !== $all
            || (int)$d['write_surplus_times'] !== $left || (int)$d['refund_num'] !== 0
            || (int)$d['write_end'] !== 0 || (int)$d['write_start'] !== 0
            || ($activeUses[$id] ?? 0) !== $all - $left) {
            throw new RuntimeException("BENEFIT_OR_USE_CHANGED:$oid:$id");
        }
        $paidComponents += recoveryCents($d['pay_price']);
        $total += $all; $remaining += $left;
    }
    if (array_diff(array_keys($activeUses), array_keys($expected['benefits']))
        || $paidComponents !== recoveryCents($expected['paid']) || $remaining <= 0) {
        throw new RuntimeException("BENEFIT_POOL_CHANGED:$oid");
    }
    $sales = Db::name('cashier_v3_sales_order')->where('source_document_id', $oid)
        ->where('source_document_type', 'legacy_store_order')->find();
    if (!$sales || (int)$sales['member_id'] !== $expected['uid']
        || (int)$sales['store_id'] !== $expected['store']
        || (int)$sales['sale_amount_cents'] !== $paidComponents
        || (string)$sales['order_status'] !== 'settled') {
        throw new RuntimeException("SALES_FACT_CHANGED:$oid");
    }
    $cardNo = 'R' . str_pad((string)$oid, 6, '0', STR_PAD_LEFT);
    $carrierNo = "IMP007-$oid-card-rights";
    $existing = Db::name('user_card_holder')->where('oid', $oid)->count()
        + Db::name('user_card_holder')->where('card_no', $cardNo)->count()
        + Db::name('store_order')->where('order_id', $carrierNo)->count()
        + Db::name('cashier_v3_card_purchase_receipt')->where('receipt_id', "IMP007-$oid-card-rights-receipt")->count()
        + Db::name('migration_entitlement_scope')->where('source_system', '007-custom-card-recovery')
            ->where('source_order_id', $oid)->count();
    if ($existing) throw new RuntimeException("RIGHTS_ALREADY_PRESENT:$oid");
    $org = (int)Db::name('organization_store')->where('store_id', $expected['store'])->value('org_id');
    $seen = [];
    while ($org > 0) {
        if (isset($seen[$org])) throw new RuntimeException('ORG_CYCLE');
        $seen[$org] = true;
        $row = Db::name('organization')->where('id', $org)->field('id,pid,status,is_del')->find();
        if (!$row || (int)$row['status'] !== 1 || (int)$row['is_del'] !== 0) throw new RuntimeException('ORG_INVALID');
        if ((int)$row['pid'] === 0) break;
        $org = (int)$row['pid'];
    }
    if ($org <= 0) throw new RuntimeException('ORG_MISSING');
    return compact('order', 'details', 'sales', 'total', 'remaining', 'cardNo', 'carrierNo', 'org')
        + ['shellName' => (string)$shell['store_name'], 'baseDetailId' => $expected['base'],
           'benefitIds' => array_keys($expected['benefits'])];
}

/** New technical copies preserve used counts and original evidence, with corrected card roles. */
function recoveryInsert(int $oid, array $expected, array $plan): array
{
    $carrier = $plan['order'];
    unset($carrier['id']);
    $carrier['order_id'] = $plan['carrierNo'];
    $carrier['unique'] = md5($plan['carrierNo']);
    $carrier['store_id'] = 0;
    $carrier['mark'] = 'migration_entitlement';
    $carrier['is_system_del'] = 1;
    $carrier['paid_balance_ready'] = 0;
    foreach (['pay_price','total_price','settle_price','total_postage','pay_postage','deduction_price',
        'coupon_price','cost','cash_pay_price','yue_pay_price','debt_amount','repaid_debt_amount',
        'refund_price','promotions_price','first_order_price','change_price','service_price',
        'one_brokerage','two_brokerage','yue_money'] as $key) {
        if (array_key_exists($key, $carrier)) $carrier[$key] = '0.00';
    }
    $carrier['paid_ben_amount'] = '0.00';
    $carrier['paid_give_amount'] = '0.00';
    $carrierId = (int)Db::name('store_order')->insertGetId($carrier);
    if ($carrierId <= 0) throw new RuntimeException('CARRIER_INSERT_FAILED');
    $mapped = []; $benefitIds = [];
    foreach ($plan['details'] as $original) {
        $copy = $original;
        unset($copy['id']);
        $copy['oid'] = $carrierId;
        $base = (int)$original['id'] === $expected['base'];
        $copy['cart_type'] = $base ? 0 : 2;
        $copy['product_type'] = $base ? 5 : 6;
        $copy['writeoff_time'] = 0;
        $snapshot = json_decode((string)$copy['cart_info'], true);
        if (is_array($snapshot)) {
            $snapshot['cart_type'] = $copy['cart_type'];
            $snapshot['product_type'] = $copy['product_type'];
            $copy['cart_info'] = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        $newId = (int)Db::name('store_order_cart_info')->insertGetId($copy);
        if ($newId <= 0) throw new RuntimeException('DETAIL_INSERT_FAILED');
        $mapped[(int)$original['id']] = $newId;
        if (!$base) $benefitIds[] = $newId;
    }
    $holderId = (int)Db::name('user_card_holder')->insertGetId([
        'uid' => $expected['uid'], 'oid' => $carrierId, 'card_name' => $plan['shellName'],
        'card_no' => $plan['cardNo'], 'store_id' => 0, 'product_id' => $expected['shell'],
        'product_type' => 5, 'verify_code' => sprintf('%012d', 900000000000 + $oid),
        'write_valid' => 0, 'write_days' => 0, 'write_start' => 0, 'write_end' => 0,
        'write_times' => $plan['total'], 'write_surplus_times' => $plan['remaining'],
        'is_del' => 0, 'add_time' => (int)$plan['order']['add_time'],
    ]);
    if ($holderId <= 0) throw new RuntimeException('HOLDER_INSERT_FAILED');
    $source = [
        'source_paid_amount_yuan' => $expected['paid'], 'source_order_id' => $oid,
        'source_order_no' => $plan['order']['order_id'], 'source_store_id' => $expected['store'],
        'source_store_name' => $plan['sales']['store_name_snapshot'],
        'base_detail_id' => $expected['base'], 'benefit_detail_id_map' => $mapped,
        'source_note' => 'Original 007 and archived 010 sale/payment/writeoff facts remain unchanged',
    ];
    Db::name('migration_entitlement_scope')->insert([
        'tenant_id' => (string)$plan['sales']['tenant_id'], 'source_system' => '007-custom-card-recovery',
        'source_detail_id' => $expected['base'], 'source_order_id' => $oid,
        'source_uid' => $expected['uid'], 'source_store_id' => $expected['store'],
        'target_uid' => $expected['uid'], 'card_holder_id' => $holderId,
        'owner_org_id' => $plan['org'], 'status' => 'active',
        'source_snapshot_json' => json_encode($source, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        'created_at' => time(),
    ]);
    // The original sale line remains an immutable imported project snapshot.
    // This recovery receipt links the new card authority to that sale and makes
    // a whole-sale void fail closed until a dedicated historical reversal exists.
    $receiptId = "IMP007-$oid-card-rights-receipt";
    $lineId = "IMP007-$oid-line-" . $expected['base'];
    $line = Db::name('cashier_v3_sales_order_line')->where('order_line_id', $lineId)
        ->where('order_id', (string)$plan['sales']['order_id'])->find();
    if (!$line || (int)$line['item_id'] !== $expected['shell']) throw new RuntimeException('SHELL_SALE_LINE_CHANGED');
    Db::name('cashier_v3_card_purchase_receipt')->insert([
        'receipt_id' => $receiptId, 'tenant_id' => (string)$plan['sales']['tenant_id'],
        'checkout_request_id' => (string)$plan['sales']['checkout_request_id'],
        'sales_order_id' => (string)$plan['sales']['order_id'], 'sales_order_line_id' => $lineId,
        'issue_no' => 1, 'command_idempotency_key' => "IMP007-$oid-card-rights",
        'immutable_fingerprint' => hash('sha256', json_encode($source, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
        'contract_version' => 'legacy-custom-card-recovery-v1',
        'store_id' => $expected['store'], 'member_id' => $expected['uid'],
        'catalog_product_id' => $expected['shell'], 'catalog_sku_id' => 0,
        'legacy_order_id' => $carrierId, 'card_holder_id' => $holderId,
        'base_cart_id' => $mapped[$expected['base']],
        'benefit_detail_ids_json' => json_encode($benefitIds, JSON_THROW_ON_ERROR),
        'result_snapshot_json' => json_encode(['sourceOrderId' => $oid,
            'originalPaidCents' => recoveryCents($expected['paid']), 'cashRefundFromCarrierCents' => 0], JSON_THROW_ON_ERROR),
        'status' => 'completed', 'occurred_at' => (int)$plan['sales']['occurred_at'],
        'settled_at' => (int)$plan['sales']['settled_at'], 'recorded_at' => time(),
        'add_time' => time(), 'update_time' => time(),
    ]);
    $check = Db::name('store_order_cart_info')->where('oid', $carrierId)->where('cart_type', 2)
        ->field('write_times,write_surplus_times')->select()->toArray();
    if (count($check) !== count($benefitIds)
        || array_sum(array_column($check, 'write_times')) !== $plan['total']
        || array_sum(array_column($check, 'write_surplus_times')) !== $plan['remaining']) {
        throw new RuntimeException('POST_INSERT_COUNTER_MISMATCH');
    }
    return ['sourceOrderId' => $oid, 'carrierId' => $carrierId, 'holderId' => $holderId,
        'baseDetailId' => $mapped[$expected['base']], 'benefitDetailIds' => $benefitIds,
        'totalTimes' => $plan['total'], 'remainingTimes' => $plan['remaining']];
}

/** A retry is a no-op only when every newly created authority still reconciles. */
function recoveryCompleted(int $oid, array $expected): ?array
{
    $carrier = Db::name('store_order')->where('order_id', "IMP007-$oid-card-rights")->find();
    if (!$carrier) return null;
    $holder = Db::name('user_card_holder')->where('oid', (int)$carrier['id'])->find();
    $scope = $holder ? Db::name('migration_entitlement_scope')->where('card_holder_id', (int)$holder['id'])
        ->where('source_system', '007-custom-card-recovery')->where('source_order_id', $oid)->find() : null;
    $receipt = $holder ? Db::name('cashier_v3_card_purchase_receipt')->where('legacy_order_id', (int)$carrier['id'])
        ->where('card_holder_id', (int)$holder['id'])->where('receipt_id', "IMP007-$oid-card-rights-receipt")->find() : null;
    $details = Db::name('store_order_cart_info')->where('oid', (int)$carrier['id'])->select()->toArray();
    $total = array_sum(array_map(static fn(array $r): int => (int)$r['write_times'],
        array_filter($details, static fn(array $r): bool => (int)$r['cart_type'] === 2)));
    $remaining = array_sum(array_map(static fn(array $r): int => (int)$r['write_surplus_times'],
        array_filter($details, static fn(array $r): bool => (int)$r['cart_type'] === 2)));
    $expectedTotal = array_sum(array_column($expected['benefits'], 0));
    $expectedRemaining = array_sum(array_column($expected['benefits'], 1));
    if (!$holder || !$scope || !$receipt || (int)$holder['uid'] !== $expected['uid']
        || (int)$holder['is_del'] !== 0 || (int)$scope['target_uid'] !== $expected['uid']
        || (string)$scope['status'] !== 'active' || (string)$receipt['status'] !== 'completed'
        || (int)$carrier['store_id'] !== 0 || (string)$carrier['mark'] !== 'migration_entitlement'
        || (int)$carrier['is_system_del'] !== 1 || count($details) !== count($expected['benefits']) + 1
        || $total !== $expectedTotal || $remaining !== $expectedRemaining
        || (int)$holder['write_times'] !== $total || (int)$holder['write_surplus_times'] !== $remaining) {
        throw new RuntimeException("PARTIAL_OR_CHANGED_RECOVERY:$oid");
    }
    return ['sourceOrderId' => $oid, 'carrierId' => (int)$carrier['id'],
        'holderId' => (int)$holder['id'], 'totalTimes' => $total,
        'remainingTimes' => $remaining, 'status' => 'already_applied'];
}

$apply = in_array('--apply', $argv, true);
if ($apply && !in_array('--authorized-010-migration', $argv, true)) {
    throw new RuntimeException('EXPLICIT_010_MIGRATION_AUTHORIZATION_REQUIRED');
}
$plans = [];
foreach (CUSTOM_CARD_RECOVERY as $oid => $expected) {
    $completed = recoveryCompleted($oid, $expected);
    if ($completed) {
        $plans[] = $completed;
        continue;
    }
    $plan = recoveryPlan($oid, $expected, false);
    $plans[] = ['sourceOrderId' => $oid, 'storeId' => $expected['store'],
        'memberId' => $expected['uid'], 'cardProductId' => $expected['shell'],
        'benefitProductIds' => array_values(array_map(static function ($d) { return (int)$d['product_id']; },
            array_filter($plan['details'], static function ($d) use ($expected) { return (int)$d['id'] !== $expected['base']; }))),
        'totalTimes' => $plan['total'], 'remainingTimes' => $plan['remaining'],
        'sourceSaleCents' => (int)$plan['sales']['sale_amount_cents']];
}
if (!$apply) {
    echo json_encode(['status' => 'read_only_ready', 'count' => count($plans), 'plans' => $plans], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(0);
}
Db::startTrans();
try {
    $result = [];
    foreach (CUSTOM_CARD_RECOVERY as $oid => $expected) {
        $completed = recoveryCompleted($oid, $expected);
        if ($completed) {
            $result[] = $completed;
            continue;
        }
        $plan = recoveryPlan($oid, $expected, true);
        $result[] = recoveryInsert($oid, $expected, $plan);
    }
    Db::commit();
    echo json_encode(['status' => 'applied', 'result' => $result], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $e) {
    Db::rollback();
    throw $e;
}
