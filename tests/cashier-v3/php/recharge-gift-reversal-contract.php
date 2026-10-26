<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$service = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/order/CashierV3RechargeGiftReversalServices.php');
$lifecycle = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/order/CashierV3RechargeOrderLifecycleServices.php');
$upgrade = $root . '/后端代码/database/upgrades/2026-08-22-收银V3充值赠送冲销审计';
$precheck = (string)file_get_contents($upgrade . '/01-升级前检查.sql');
$migration = (string)file_get_contents($upgrade . '/02-正式升级.sql');
$postcheck = (string)file_get_contents($upgrade . '/03-升级后验证.sql');
$failures = [];

$requireAll = static function (string $name, string $haystack, array $needles) use (&$failures): void {
    foreach ($needles as $needle) {
        if (strpos($haystack, $needle) === false) $failures[] = $name . ' missing: ' . $needle;
    }
};

$requireAll('refund preservation', $service, [
    "if (\$action === 'refund-recharge-order')",
    "return ['authority' => [], 'items' => [], 'revokedItemCount' => 0]",
]);
$requireAll('project unused proof', $service, [
    'write_surplus_times', 'split_surplus_num', 'split_status', 'replacement_id',
    'cashier_v3_entitlement_writeoff_fact', 'cashier_v3_service_order_line',
    'store_reservation_order', 'cashier_v3_card_operation', 'selected_kind_count',
    'shared_remaining_times', 'remaining_times', 'selected_at',
]);
$requireAll('coupon unused proof and inventory restoration', $service, [
    "'cashier_v3_recharge_gift'", "'use_time'", "'status'", "'is_fail'", "'oid'",
    "'total_count'", "'remain_count'", "->inc('remain_count', count(\$ids))->update()",
]);
$requireAll('append-only gift reversal authority', $service, [
    'cashier_v3_recharge_gift_reversal', 'original_gift_fact_id', 'reversal_gift_fact_id',
    "'status' => 'voided'", "\$row['reversal_of'] = (string)\$source['gift_fact_id']",
    "'status' => 'revoked'", "where('status', 'issued')->update",
]);
$requireAll('compatibility projections are revoked', $service, [
    'StoreOrderTerminalOperation::ACTION_VOID', "'is_del' => 1", "'card_status' => 'disabled'",
    "'status' => 'revoked'", "'is_fail' => 1",
]);
if (strpos($service, 'recharge_gift_reversal_unprovable_kind') === false) {
    $failures[] = 'unprovable physical gift kind must fail closed';
}

$executeLifecycle = substr($lifecycle, (int)strpos($lifecycle, 'public function executeInTx'));
$existingPosition = strpos($executeLifecycle, '$existing = Db::name(self::OPERATION_TABLE)');
$preparePosition = strpos($executeLifecycle, '$preparedGifts = $action === \'void-recharge-order\'');
$eventPosition = strpos($executeLifecycle, '$event = $recorder->recordInTx');
if ($existingPosition === false || $preparePosition === false || $eventPosition === false
    || !($existingPosition < $preparePosition && $preparePosition < $eventPosition)) {
    $failures[] = 'idempotency replay must return first and gift proof must run before lifecycle writes';
}
if (strpos($lifecycle, '$giftReversal->apply') === false
    || strpos($lifecycle, 'recharge_lifecycle_gift_reversal_not_available') !== false) {
    $failures[] = 'recharge lifecycle must delegate gift handling instead of blanket rejection';
}

$requireAll('migration precheck', $precheck, [
    'eb_cashier_v3_recharge_gift_authority', 'eb_cashier_v3_recharge_gift_item',
    'eb_cashier_v3_gift_fact', 'eb_store_coupon_user', 'eb_store_coupon_issue',
]);
$requireAll('migration audit schema', $migration, [
    'CREATE TABLE IF NOT EXISTS `eb_cashier_v3_recharge_gift_reversal`',
    '`uk_reversal_id`', '`uk_operation_item`', '`restored_coupon_inventory_count`',
    '`original_gift_fact_id`', '`reversal_gift_fact_id`',
]);
$requireAll('migration postcheck', $postcheck, [
    'eb_cashier_v3_recharge_gift_reversal', '@rgr_tables=1',
    '@rgr_unique_keys=2', '@rgr_invalid=0',
]);
if (stripos($migration, 'insert into') !== false || stripos($migration, 'update `eb_cashier_v3_recharge_gift') !== false) {
    $failures[] = 'migration must not backfill or mutate historical recharge gifts';
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
echo "recharge gift reversal contract: PASS\n";
