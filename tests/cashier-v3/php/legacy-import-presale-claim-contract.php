<?php
declare(strict_types=1);

/**
 * Historical JSJ/SWXT goods imports use OPEN for an unspent claimable line.
 * Verify both public projection and command eligibility keep the same rule,
 * while exhausted and closed rights remain unavailable without a DB rewrite.
 */
$root = is_dir('/var/www/html/app') ? '/var/www/html' : dirname(__DIR__, 3) . '/后端代码';
$path = $root . '/app/services/cashier/v3/presale/CashierV3PresaleClaimServices.php';
require_once $path;

use app\services\cashier\v3\presale\CashierV3PresaleClaimServices;

$service = new CashierV3PresaleClaimServices();
$isClaimable = new ReflectionMethod($service, 'isClaimable');
$displayStatus = new ReflectionMethod($service, 'claimableDisplayStatus');
$cases = [
    ['OPEN', 5, 0, true, 'AVAILABLE'],
    ['OPEN', 5, 4, true, 'AVAILABLE'],
    ['AVAILABLE', 5, 0, true, 'AVAILABLE'],
    ['OPEN', 5, 5, false, 'OPEN'],
    ['AVAILABLE', 5, 5, false, 'AVAILABLE'],
    ['FULLY_CLAIMED', 5, 0, false, 'FULLY_CLAIMED'],
    ['CLOSED_AFTER_SALE_REVERSAL', 5, 0, false, 'CLOSED_AFTER_SALE_REVERSAL'],
];
foreach ($cases as [$status, $quantity, $claimed, $expectedClaimable, $expectedDisplay]) {
    $row = ['claim_status' => $status, 'quantity' => $quantity, 'claimed_quantity' => $claimed];
    if ($isClaimable->invoke($service, $row) !== $expectedClaimable
        || $displayStatus->invoke($service, $row) !== $expectedDisplay) {
        throw new RuntimeException("legacy claim state mismatch: {$status} {$claimed}/{$quantity}");
    }
}

// The list's indexed status predicate, final claim guard and read models must
// all use the same compatibility branch; testing only a helper would miss it.
$source = file_get_contents($path);
$required = [
    "whereIn('claim_status', [self::STATUS_AVAILABLE, self::STATUS_LEGACY_OPEN])",
    "whereRaw('quantity > claimed_quantity')",
    "'can_claim' => \$this->isClaimable(\$row)",
    "if (!\$this->isClaimable(\$claimable))",
    "'claim_status' => \$this->claimableDisplayStatus(\$row)",
];
foreach ($required as $snippet) {
    if (!str_contains($source, $snippet)) {
        throw new RuntimeException("legacy claim path missing: {$snippet}");
    }
}
echo "LEGACY_IMPORT_PRESALE_CLAIM_CONTRACT_PASS\n";
