<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$source = (string)file_get_contents(
    $root . '/后端代码/app/services/cashier/v3/CashierV3ActionDispatcher.php'
);

$failed = 0;
function expectProjectionContract(bool $condition, string $message): void
{
    global $failed;
    if ($condition) {
        echo "PASS {$message}\n";
        return;
    }
    $failed++;
    echo "FAIL {$message}\n";
}

expectProjectionContract(
    strpos($source, "['choose-catalog-item', 'remove-cart-line']") !== false
        && strpos($source, '$skipDefaultRootProjection') !== false
        && strpos($source, '&& !$wantCurrentState') !== false,
    'catalog append and single-line delete return their authoritative draft without rebuilding the full workbench'
);

exit($failed > 0 ? 1 : 0);
