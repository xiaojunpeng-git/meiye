<?php
declare(strict_types=1);

$source = file_get_contents(
    __DIR__ . '/../../../后端代码/app/services/cashier/v3/card/CashierV3CardPurchaseIssuanceServices.php'
);

$passed = 0;
$failed = 0;

function workspaceBindingCheck(string $name, bool $condition): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "[PASS] {$name}\n";
        return;
    }
    $failed++;
    echo "[FAIL] {$name}\n";
}

workspaceBindingCheck(
    'custom card configuration binds through the locked checkout authority key',
    strpos($source, 'workspaceLineKeysByCheckoutLineId') !== false
    && strpos($source, "strpos(\$authorityKey, 'sale:') !== 0") !== false
    && strpos($source, "substr(\$authorityKey, strlen('sale:'))") !== false
);

workspaceBindingCheck(
    'configuration lookup uses the recovered workspace line key',
    strpos($source, "->where('workspace_line_key', \$workspaceLineKey)") !== false
    && strpos($source, "->where('workspace_line_key', \$lineKey)") === false
);

workspaceBindingCheck(
    'ambiguous checkout bindings fail closed',
    strpos($source, 'card_purchase_workspace_line_binding_invalid') !== false
);

echo sprintf("%d passed, %d failed\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
