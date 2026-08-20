<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$port = (string)file_get_contents(
    $root . '/后端代码/app/services/cashier/v3/settlement/ThinkPhpCashierV3CheckoutSubmissionExecutionPort.php'
);
$orchestrator = (string)file_get_contents(
    $root . '/后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutSubmissionOrchestrator.php'
);
$interface = (string)file_get_contents(
    $root . '/后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutSubmissionExecutionPort.php'
);

$passed = 0;
$failed = 0;
$check = static function (string $name, bool $condition) use (&$passed, &$failed): void {
    $condition ? $passed++ : $failed++;
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $name . PHP_EOL;
};

$check('mixed effect port is part of the final transaction contract',
    str_contains($interface, 'persistSaleSettlementEffectsInTx')
    && str_contains($orchestrator, '$this->port->persistSaleSettlementEffectsInTx(')
);
$check('mixed effect port settles both product stock and card issuance from the same locked aggregate',
    str_contains($port, 'public function persistSaleSettlementEffectsInTx')
    && str_contains($port, 'new CashierV3SaleInventorySettlementServices()')
    && str_contains($port, '->planInTx(')
    && str_contains($port, '->persistInTx($inventoryPlan)')
    && str_contains($port, 'new CashierV3CardPurchaseIssuanceServices()')
    && str_contains($port, '->issueInTx(')
);
$check('mixed result exposes both effects and replay status includes both',
    str_contains($orchestrator, "'saleInventory' => \$saleSettlementEffects['inventory'] ?? null")
    && str_contains($orchestrator, "'cardPurchase' => \$saleSettlementEffects['cardPurchase'] ?? null")
    && str_contains($orchestrator, "!empty(\$saleSettlementEffects['inventory']['replayed'])")
    && str_contains($orchestrator, "!empty(\$saleSettlementEffects['cardPurchase']['replayed'])")
);
$check('mixed sale stock events remain in the final event recorder transaction',
    str_contains($port, '(new CashierV3SaleInventorySettlementServices())->eventInputs(')
    && str_contains($port, "'source_type' => self::ACTION")
    && str_contains($port, "'source_id' => \$authority['checkoutRequestId']")
);

echo sprintf('checkout-mixed-sale-effects-contract: %d passed, %d failed', $passed, $failed) . PHP_EOL;
exit($failed > 0 ? 1 : 0);
