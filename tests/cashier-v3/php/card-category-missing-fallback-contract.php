<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$factSource = file_get_contents($root . '/后端代码/app/services/report/CardSaleCategoryAllocationFactServices.php');
$issuanceSource = file_get_contents($root . '/后端代码/app/services/cashier/v3/card/CashierV3CardPurchaseIssuanceServices.php');
$configurationSource = file_get_contents($root . '/后端代码/app/services/cashier/v3/card/CashierV3CustomCardConfigurationServices.php');

foreach ([
    'missing_category_uses_issued_name' => "trim((string)\$allocation['categoryNameSnapshot'])",
    'missing_category_has_empty_partner_snapshot' => '$this->emptyPartnerSnapshot()',
    'existing_category_keeps_partner_snapshot' => '$partnerSnapshots->resolveInTx(',
    'empty_partner_ratio' => "'partner_share_amount_cents' => 0",
] as $name => $needle) {
    if (strpos($factSource, $needle) === false) {
        fwrite(STDERR, "FAIL card category fallback contract: {$name}\n");
        exit(1);
    }
}

if (strpos($factSource, "throw new \\LogicException('card_category_snapshot_not_found')") !== false) {
    fwrite(STDERR, "FAIL card category fallback contract: deleted category still blocks checkout\n");
    exit(1);
}

foreach ([
    'legacy_configuration_uses_uncategorized_snapshot' => "\$categoryName = '未分类';",
    'missing_category_id_still_fails_closed' => "if (\$categoryId <= 0)",
] as $name => $needle) {
    if (strpos($issuanceSource, $needle) === false) {
        fwrite(STDERR, "FAIL card category fallback contract: {$name}\n");
        exit(1);
    }
}

foreach ([
    'new_configuration_freezes_category_id' => "'categoryIdSnapshot' => \$categoryId",
    'new_configuration_freezes_category_name' => "'categoryNameSnapshot' => \$categoryName",
] as $name => $needle) {
    if (strpos($configurationSource, $needle) === false) {
        fwrite(STDERR, "FAIL card category fallback contract: {$name}\n");
        exit(1);
    }
}

echo "PASS card category fallback contract\n";
