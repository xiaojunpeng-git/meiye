<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$file = $root . '/后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutBusinessSourceSelectionServices.php';
$source = file_get_contents($file);
if (!is_string($source)) {
    fwrite(STDERR, "missing business source service\n");
    exit(1);
}

$start = strpos($source, 'public function lockResolvedForSettlementInTx');
$end = strpos($source, '    public function read(', $start === false ? 0 : $start);
$method = $start === false || $end === false ? '' : substr($source, $start, $end - $start);

$checks = [
    'final settlement returns the locked row snapshot' => str_contains($method, 'return self::rowProjection($row);'),
    'final settlement does not revalidate live source configuration' => !str_contains($method, 'resolveSourceSnapshot('),
    'selection remains tenant and store scoped before locking' => str_contains($method, "->where('tenant_id', \$tenantId)")
        && str_contains($method, "->where('store_id', \$storeId)")
        && str_contains($method, '->lock(true)'),
];

$failed = [];
foreach ($checks as $name => $passed) {
    if (!$passed) $failed[] = $name;
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $name . PHP_EOL;
}
echo sprintf('checkout-business-source-snapshot-contract: %d passed, %d failed', count($checks) - count($failed), count($failed)) . PHP_EOL;
exit($failed ? 1 : 0);
