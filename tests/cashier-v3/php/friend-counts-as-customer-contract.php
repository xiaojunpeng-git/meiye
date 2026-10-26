<?php

$root = is_dir('/var/www/html/app') ? '/var/www/html' : dirname(__DIR__, 3) . '/后端代码';
$read = static function (string $path): string {
    $content = file_get_contents($path);
    if ($content === false) throw new RuntimeException('无法读取源码：' . $path);
    return $content;
};

$normalizer = $read($root . '/app/services/cashier/v3/CashierV3RequestNormalizer.php');
$workspace = $read($root . '/app/services/cashier/v3/cashier/CashierV3CashierWorkspaceServices.php');
$catalog = $read($root . '/app/services/cashier/v3/cashier/CashierV3SaleCatalogServices.php');
$preparation = $read($root . '/app/services/cashier/v3/settlement/CashierV3CheckoutPreparationServices.php');
$kernel = $read($root . '/app/services/cashier/v3/settlement/CashierV3CheckoutSettlementKernel.php');
$requestRepository = $read($root . '/app/services/cashier/v3/settlement/ThinkPhpCashierV3CheckoutRequestRepository.php');
$orderPlan = $read($root . '/app/services/cashier/v3/order/settlement/CashierV3SalesOrderPlanV1.php');
$factPlan = $read($root . '/app/services/cashier/v3/fact/CashierV3CheckoutFactPlanV1.php');
$factAssembler = $read($root . '/app/services/cashier/v3/fact/CashierV3SaleOnlyFactAssembler.php');
$migration = $read($root . '/database/upgrades/2026-08-13-收银V3销售行顾客规则快照/02-正式升级.sql');

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
    strpos($normalizer, "['friendCountsAsCustomer', 'friend_counts_as_customer']") !== false
        && strpos($normalizer, "\$payload['friendCountsAsCustomer'] = \$normalizedFriendCounts") !== false,
    'request normalizer accepts one canonical friend customer-count flag'
);
$check(
    strpos($workspace, "'friend_counts_as_customer' => \$friendCountsAsCustomer") !== false
        && strpos($workspace, "'friendCountsAsCustomer' => (int)(\$row['friend_counts_as_customer'] ?? 1) === 1") !== false,
    'workspace persists and reprojects the selected friend customer-count flag'
);
$check(
    strpos($catalog, "'friendCountsAsCustomer' => (int)(\$storedLine['friend_counts_as_customer'] ?? 1) === 1") !== false
        && strpos($preparation, "'friendCountsAsCustomer' => !array_key_exists('friendCountsAsCustomer', \$line)") !== false,
    'locked workspace source carries the flag into checkout preparation'
);
$check(
    strpos($kernel, "'friendCountsAsCustomer' => \$friendCountsAsCustomer") !== false
        && strpos($kernel, "\$friendCountsAsCustomer > 1") !== false
        && strpos($requestRepository, "'friendCountsAsCustomer' => 'friend_counts_as_customer'") !== false,
    'kernel validates and request draft persists the immutable flag'
);
$check(
    strpos($orderPlan, "'friend_counts_as_customer' => \$friendCountsAsCustomer") !== false
        && strpos($factPlan, "'friend_counts_as_customer' => self::nonNegativeInt") !== false
        && strpos($factAssembler, "'friendCountsAsCustomer' => (int)(\$line['friend_counts_as_customer'] ?? 1)") !== false,
    'order line and sale fact keep the same immutable flag'
);
$check(
    strpos($migration, 'eb_cashier_v3_workspace_line') !== false
        && strpos($migration, 'eb_cashier_v3_checkout_line_draft') !== false
        && strpos($migration, 'eb_cashier_v3_sales_order_line') !== false
        && strpos($migration, 'eb_cashier_v3_sale_fact') !== false,
    'migration provides the flag column at every authoritative persistence stage'
);

if ($failed > 0) exit(1);
echo "FRIEND_COUNTS_AS_CUSTOMER_CONTRACT=PASS\n";
