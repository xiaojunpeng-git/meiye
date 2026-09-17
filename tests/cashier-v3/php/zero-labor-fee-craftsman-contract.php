<?php

declare(strict_types=1);

/**
 * Regression contract: a selected craftsman can legitimately have no labor
 * amount for a sale. The checkout must retain the attribution but skip a
 * zero-value labor-performance fact.
 */
$root = dirname(__DIR__, 3);
$assembler = (string)file_get_contents(
    $root . '/后端代码/app/services/cashier/v3/fact/CashierV3SaleOnlyFactAssembler.php'
);
$snapshot = (string)file_get_contents(
    $root . '/后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutCraftsmenSnapshot.php'
);
$planner = (string)file_get_contents(
    $root . '/后端代码/app/services/cashier/v3/settlement/CashierV3PaidProjectCraftsmanPerformanceServices.php'
);

$checks = [
    'checkout snapshot accepts a zero craftsman weight without inventing a performance fact' => strpos(
        $snapshot,
        "\$laborWeight = self::nonNegativeInt(\$row['laborWeight']);"
    ) !== false && strpos($snapshot, "\$performanceType !== 'labor' && \$laborWeight <= 0") === false,
    'a zero labor fee remains a valid explicit value' => strpos(
        $snapshot,
        "\$laborFeeCents = \$hasPerformanceFields ? self::nonNegativeInt(\$row['laborFeeCents']) : 0;"
    ) !== false,
    'a labor-only selection receives no labor performance and zero-value allocations write no performance fact' => strpos(
        $planner,
        'if ($performanceCraftsmen === []) {'
    ) !== false && strpos(
        $assembler,
        "if ((int)\$allocation['laborPerformanceCents'] === 0\n                        && (int)\$allocation['laborFeeCents'] === 0)"
    ) !== false && strpos($assembler, 'continue;') !== false,
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$passed) $failed[] = $name;
}

exit($failed === [] ? 0 : 1);
