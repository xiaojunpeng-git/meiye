<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$dao = (string)file_get_contents($root . '/后端代码/app/dao/activity/coupon/StoreCouponUserDao.php');
$couponService = (string)file_get_contents($root . '/后端代码/app/services/activity/coupon/StoreCouponUserServices.php');
$claimService = (string)file_get_contents($root . '/后端代码/app/services/activity/coupon/StoreCouponIssueUserServices.php');
$issuance = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/member/CashierV3RechargeGiftIssuanceServices.php');
$reversal = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/order/CashierV3RechargeGiftReversalServices.php');
$migration = (string)file_get_contents($root . '/后端代码/database/upgrades/2026-08-05-收银V3充值赠券领取冲销/02-正式升级.sql');
$failures = [];

foreach (["->where('status', 0)", "->where('is_fail', 0)", "->where('use_time', 0)", "->where('uid', \$uid)"] as $needle) {
    if (strpos($dao, $needle) === false) $failures[] = 'coupon use CAS missing: ' . $needle;
}
if (strpos($couponService, "useCoupon(\$couponId, \$uid) !== 1") === false
    || strpos($couponService, '优惠券状态已变化') === false
    || strpos($couponService, 'return true;') === false) {
    $failures[] = 'coupon service must reject stale CAS while retaining successful true return';
}
if (strpos($claimService, 'effectiveClaimCount') === false
    || strpos($claimService, "where('gm.status', 'voided')") === false
    || strpos($claimService, 'whereNotExists') === false) {
    $failures[] = 'effective claim count must exclude voided exact mappings';
}
if (strpos($issuance, 'couponIssueUserId') === false || strpos($issuance, 'insertCouponIssueMappings') === false
    || strpos($reversal, 'issueMappings') === false || strpos($reversal, "'status' => 'voided'") === false) {
    $failures[] = 'issuance and void must persist and reverse exact coupon claim mappings';
}
foreach ([
    '20260805-005-cashier-v3-recharge-gift-coupon-claim-reversal',
    'CREATE TABLE IF NOT EXISTS `eb_cashier_v3_recharge_gift_coupon_issue_mapping`',
    'ALTER TABLE `eb_store_coupon_issue_user` ADD COLUMN `id`',
    'UNIQUE KEY `uk_coupon_user`', 'UNIQUE KEY `uk_coupon_issue_user`',
] as $needle) {
    if (strpos($migration, $needle) === false) $failures[] = 'mapping migration missing: ' . $needle;
}
$oldMigration = (string)file_get_contents($root . '/后端代码/database/upgrades/2026-08-05-收银V3订单生命周期权威/02-正式升级.sql');
if (strpos($migration, '20260805-004') !== false || $migration === $oldMigration) {
    $failures[] = 'coupon claim mapping must use a new additive migration';
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
echo "recharge gift coupon claim reversal contract: PASS\n";
