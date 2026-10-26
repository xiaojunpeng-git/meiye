<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$read = static function (string $path): string {
    $content = file_get_contents($path);
    if ($content === false) {
        throw new RuntimeException('无法读取源码：' . $path);
    }
    return $content;
};

$backend = $root . '/后端代码/app/services/cashier/v3';
$kernel = $read($backend . '/checkout/CashierV3EntitlementCompletionKernel.php');
$direct = $read($backend . '/checkout/CashierV3DirectSnapshotEntitlementSettlementServices.php');
$plan = $read($backend . '/checkout/persistence/CashierV3EntitlementCompletionPlanV1.php');
$sale = $read($backend . '/settlement/CashierV3SaleProjectServiceCompletionServices.php');
$reservation = $read($backend . '/reservation/CashierV3ReservationCompletionFactServices.php');
$events = $read($backend . '/settlement/ThinkPhpCashierV3CheckoutSubmissionExecutionPort.php');
$migration = $read($root . '/后端代码/database/upgrades/2026-09-17-收银V3服务对象客数快照/02-正式升级.sql');

$passed = 0;
$failed = 0;
$check = static function (string $name, bool $condition) use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
        echo "PASS {$name}\n";
        return;
    }
    $failed++;
    echo "FAIL {$name}\n";
};

$check('entitlement command carries a validated customer-count snapshot',
    str_contains($kernel, "'friendCountsAsCustomer' => \$intentLine['friendCountsAsCustomer']")
    && str_contains($kernel, "'friend_counts_as_customer_invalid'")
    && str_contains($direct, "'friendCountsAsCustomer' => (bool)\$intent['friendCountsAsCustomer']"));
$check('entitlement service fact persists the snapshot in its immutable plan',
    str_contains($plan, "'friendCountsAsCustomer', 'isExperience'")
    && str_contains($plan, "'friend_counts_as_customer' => self::booleanInt(")
    && str_contains($plan, "'friend_counts_as_customer' => \$line['friend_counts_as_customer']")
    && str_contains($plan, "\$row['immutable_fingerprint'] = self::fingerprintValue(\$row);"));
$check('paid project completion snapshots the locked sale-line setting',
    str_contains($sale, "'friend_counts_as_customer' => (int)\$line['friend_counts_as_customer']")
    && str_contains($sale, "\$row['immutable_fingerprint'] = self::fingerprint(\$row);"));
$check('reservation completion writes the explicit self-service value',
    str_contains($reservation, "'service_object' => 'SELF',")
    && str_contains($reservation, "'friend_counts_as_customer' => 1,")
    && str_contains($reservation, "\$service['immutable_fingerprint'] = \$this->fingerprint(\$service);"));
$check('service completion event carries the same frozen setting',
    str_contains($events, "'friendCountsAsCustomer' => (bool)\$line['serviceSnapshot']['friendCountsAsCustomer']"));
$check('migration only adds the fact snapshot without historical writes',
    str_contains($migration, 'ADD COLUMN `friend_counts_as_customer` tinyint(3) unsigned NOT NULL DEFAULT')
    && !preg_match('/UPDATE\s+`?eb_cashier_v3_entitlement_service_fact`?/i', $migration)
    && !preg_match('/INSERT\s+INTO\s+`?eb_cashier_v3_entitlement_service_fact`?/i', $migration));

echo "service-customer-snapshot-contract: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
