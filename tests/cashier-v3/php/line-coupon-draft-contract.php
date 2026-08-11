<?php

$root = is_dir('/var/www/html/app') ? '/var/www/html' : dirname(__DIR__, 3) . '/后端代码';

$read = static function (string $path): string {
    $content = file_get_contents($path);
    if ($content === false) {
        throw new RuntimeException('无法读取源码：' . $path);
    }
    return $content;
};

$workspace = $read($root . '/app/services/cashier/v3/cashier/CashierV3CashierWorkspaceServices.php');
$module = $read($root . '/app/services/cashier/v3/cashier/CashierV3CashierModule.php');
$idempotency = $read($root . '/app/services/cashier/v3/CashierV3IdempotencyKeyServices.php');
$checkoutRepository = $read($root . '/app/services/cashier/v3/settlement/ThinkPhpCashierV3CheckoutRequestRepository.php');
$settlementKernel = $read($root . '/app/services/cashier/v3/settlement/CashierV3CheckoutSettlementKernel.php');
$c2 = $read($root . '/app/services/cashier/v3/manifest/CashierV3C2CashierModule.php');
$events = $read($root . '/app/services/cashier/v3/manifest/CashierV3ActionManifest.php');
$migration = $read($root . '/database/upgrades/2026-08-11-收银V3销售行优惠券快照/02-正式升级.sql');

$failed = 0;
$check = static function (bool $condition, string $message) use (&$failed): void {
    if (!$condition) {
        $failed++;
        fwrite(STDERR, "FAIL: {$message}\n");
        return;
    }
    echo "PASS: {$message}\n";
};

$check(
    strpos($module, "registerProjection('open-line-coupon'") !== false
        && strpos($module, "['apply-line-coupon', 'remove-line-coupon']") !== false
        && substr_count($c2, "'open-line-coupon'") === 1
        && strpos($c2, "'apply-line-coupon'") !== false,
    'coupon projection and draft commands are registered'
);
$check(
    strpos($module, 'self::workspaceIdForProjection($scope)') !== false
        && strpos($module, 'Projection 不能携带写命令 contexts') !== false,
    'coupon projection derives the workspace only from the authenticated state context'
);
$check(
    strpos($idempotency, "'APPLY_LINE_COUPON'") !== false
        && strpos($idempotency, "'REMOVE_LINE_COUPON'") !== false,
    'coupon mutation idempotency prefixes are registered'
);
$check(
    strpos($checkoutRepository, "'couponUserId' => 'coupon_user_id'") !== false
        && strpos($checkoutRepository, "'couponNameSnapshot' => 'coupon_name_snapshot'") !== false
        && strpos($checkoutRepository, "'couponDiscountCents' => 'coupon_discount_cents'") !== false,
    'checkout request line persistence keeps coupon identity and discount snapshots'
);
$buildLineDraftsStart = strpos($settlementKernel, 'private static function buildLineDrafts');
$buildPaymentDraftsStart = strpos($settlementKernel, 'private static function buildPaymentDrafts');
$buildLineDrafts = ($buildLineDraftsStart !== false && $buildPaymentDraftsStart > $buildLineDraftsStart)
    ? substr($settlementKernel, $buildLineDraftsStart, $buildPaymentDraftsStart - $buildLineDraftsStart)
    : '';
$check(
    substr_count($buildLineDrafts, "'couponUserId' =>") === 2
        && substr_count($buildLineDrafts, "'couponNameSnapshot' =>") === 2
        && substr_count($buildLineDrafts, "'couponDiscountCents' =>") === 2,
    'checkout kernel line drafts carry coupon snapshots for sale and entitlement rows'
);
$check(
    strpos($events, "'apply-line-coupon' => \$eventless(\$workspaceDraft)") !== false
        && strpos($events, "'remove-line-coupon' => \$eventless(\$workspaceDraft)") !== false,
    'coupon draft commands have explicit eventless contracts'
);
$check(
    strpos($workspace, "->where('cu.uid', \$memberId)") !== false
        && strpos($workspace, "->where('cu.status', 0)") !== false
        && strpos($workspace, "->where('cu.use_time', 0)") !== false
        && strpos($workspace, "->lock(true)") !== false,
    'apply re-reads and locks the authoritative member coupon'
);
$check(
    strpos($workspace, "'threshold' => \$threshold, 'base' => \$base, 'cap' => \$base") !== false
        && strpos($workspace, "targetPriceCents") !== false
        && strpos($workspace, "coupon_discount_cents") !== false,
    'normal and upgrade lines keep threshold, receivable cap and coupon discount separate'
);
$check(
    strpos($workspace, "'couponUserId' => \$couponUserId") !== false
        && strpos($workspace, "'couponDiscountAmountCents' => \$couponDiscountCents") !== false
        && strpos($workspace, "\$item['coupon_user_id']") !== false,
    'coupon identity and discount are public and fingerprinted workspace fields'
);
$check(
    strpos($workspace, "\$this->lineFingerprint(\$rows, true, false)") !== false
        && strpos($workspace, 'bool $includeCouponFields = true') !== false
        && strpos($workspace, 'if ($includeCouponFields)') !== false,
    'pre-coupon editing drafts remain readable through a narrowly scoped fingerprint variant'
);
$check(
    strpos($workspace, "store_coupon_user')->update") === false
        && strpos($workspace, "'use_time' =>") === false,
    'workspace selection never consumes the coupon'
);
$check(
    substr_count($migration, "'coupon_user_id'") === 4
        && substr_count($migration, "'coupon_name_snapshot'") === 4
        && substr_count($migration, "'coupon_discount_cents'") === 4
        && strpos($migration, 'ADD COLUMN IF NOT EXISTS') === false
        && strpos($migration, 'CREATE PROCEDURE') !== false,
    'MySQL 5.6 migration covers workspace, checkout, order and sale-fact lines'
);

if ($failed > 0) {
    exit(1);
}
echo "LINE_COUPON_DRAFT_CONTRACT=PASS\n";
