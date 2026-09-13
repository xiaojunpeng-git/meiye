<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$source = file_get_contents(
    $root . '/后端代码/app/services/cashier/v3/order/CashierV3OrderLifecycleServices.php'
);

if (!is_string($source)) {
    fwrite(STDERR, "Unable to read order lifecycle service.\n");
    exit(1);
}

$checks = [
    'void_does_not_read_optional_attribution_event_key' => strpos(
        $source,
        "if (\$action === 'void-sales-order' && \$occurredEvents['attribution'])"
    ) === false,
    'void_closes_attribution_facts_idempotently' => strpos(
        $source,
        "if (\$action === 'void-sales-order') {\n                // Attribution facts"
    ) !== false
        && strpos($source, 'voidOrderAttributionFactsInTx($source, $dataScope);') !== false,
    'event_set_has_no_undefined_attribution_index' => strpos(
        $source,
        "'presale' => isset(\$types['gift.consumed'])"
    ) !== false,
];

$failed = array_keys(array_filter($checks, static function (bool $passed): bool {
    return !$passed;
}));

if ($failed !== []) {
    fwrite(STDERR, 'FAILED: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo "PASS order-lifecycle-attribution-void-guard-contract\n";
