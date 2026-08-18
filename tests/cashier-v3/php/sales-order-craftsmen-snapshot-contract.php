<?php

$root = is_dir('/var/www/html/app') ? '/var/www/html' : dirname(__DIR__, 3) . '/后端代码';
$frontend = is_dir('/var/www/html/app') ? '/var/www/html/../cashier-v3/src' : dirname(__DIR__, 3) . '/前端代码/cashier-v3/src';

$read = static function (string $path): string {
    $content = file_get_contents($path);
    if ($content === false) {
        throw new RuntimeException('无法读取源码：' . $path);
    }
    return $content;
};

$snapshot = $read($root . '/app/services/cashier/v3/settlement/CashierV3CheckoutCraftsmenSnapshot.php');
$preparation = $read($root . '/app/services/cashier/v3/settlement/CashierV3CheckoutPreparationServices.php');
$kernel = $read($root . '/app/services/cashier/v3/settlement/CashierV3CheckoutSettlementKernel.php');
$repository = $read($root . '/app/services/cashier/v3/settlement/ThinkPhpCashierV3CheckoutRequestRepository.php');
$rebuilder = $read($root . '/app/services/cashier/v3/settlement/CashierV3CheckoutDraftAuthorityRebuilder.php');
$projection = $read($root . '/app/services/cashier/v3/settlement/CashierV3CheckoutProjectionServices.php');
$plan = $read($root . '/app/services/cashier/v3/order/settlement/CashierV3SalesOrderPlanV1.php');
$writer = $read($root . '/app/services/cashier/v3/order/settlement/ThinkPhpCashierV3SalesOrderAuthorityWriter.php');
$query = $read($root . '/app/services/cashier/v3/order/CashierV3SalesOrderQueryServices.php');
$migration = $read($root . '/database/upgrades/2026-08-04-收银V3销售订单手艺人快照/02-正式升级.sql');
$postcheck = $read($root . '/database/upgrades/2026-08-04-收银V3销售订单手艺人快照/03-升级后验证.sql');
$detail = $read($frontend . '/components/order/SalesOrderDetailOverlay.vue');

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
    strpos($snapshot, "'id', 'staffId', 'employeeId', 'storeId', 'name', 'isPrimary'") !== false
        && strpos($snapshot, "'sequence', 'laborWeight', 'isPointCustomer'") !== false
        && strpos($snapshot, "if (\$normalized !== [] && \$commissionWeight !== 100 && \$commissionWeight !== 0)") !== false
        && strpos($snapshot, 'if (self::isLegacyEmpty($json))') !== false
        && strpos($snapshot, 'return [];') !== false,
    'shared snapshot normalizer requires complete server-locked people and keeps historical empty values empty'
);
$check(
    strpos($preparation, "'craftsmen' => \$craftsmen") !== false
        && strpos($preparation, 'CashierV3CheckoutCraftsmenSnapshot::normalize') !== false
        && strpos($kernel, "'craftsmen',") !== false
        && strpos($kernel, "'craftsmen' => \$craftsmen") !== false
        && strpos($kernel, "CashierV3CheckoutCraftsmenSnapshot::encode") !== false,
    'checkout preparation, normalization, line fingerprint input, and line drafts preserve craftsmen'
);
$check(
    strpos($kernel, "} elseif (\$serviceObject !== '' || \$friendCountsAsCustomer !== 1 || \$isExperience !== 0 || \$craftsmen !== [])") !== false
        && strpos($kernel, "'craftsmenSnapshotJson' => '[]'") !== false,
    'non-project checkout lines cannot carry craftsmen and entitlement lines persist an explicit empty snapshot'
);
$check(
    strpos($repository, "'craftsmenSnapshotJson' => 'craftsmen_snapshot_json'") !== false
        && strpos($repository, 'craftsmen_snapshot_json,salespeople_snapshot_json') !== false
        && strpos($repository, 'inventory_outbound_required,line_fingerprint') !== false
        && strpos($repository, "'craftsmen_snapshot_json' => (string)\$row['craftsmenSnapshotJson']") !== false
        && strpos($rebuilder, "'craftsmen' => self::craftsmenSnapshot") !== false
        && strpos($projection, "'craftsmen' => \$craftsmen") !== false,
    'draft repository, replay rebuilder, and read projection retain the frozen snapshot'
);
$check(
    strpos($snapshot, 'public static function isLegacyEmpty') !== false
        && strpos($projection, 'CashierV3CheckoutCraftsmenSnapshot::isLegacyEmpty($craftsmenJson)') !== false
        && strpos($plan, 'CashierV3CheckoutCraftsmenSnapshot::decode($json)') !== false,
    'pre-migration blank snapshots keep their original line fingerprint until explicitly rewritten'
);
$check(
    strpos($plan, "'craftsmen_snapshot_json',") !== false
        && strpos($plan, "'craftsmen' => \$craftsmen") !== false
        && strpos($plan, "'craftsmen_snapshot_json' => CashierV3CheckoutCraftsmenSnapshot::encode(\$craftsmen)") !== false
        && strpos($plan, "'craftsmen_snapshot_json' => \$line['craftsmen_snapshot_json']") !== false
        && strpos($writer, 'insertAll($lineRows)') !== false
        && strpos($writer, 'sales_order_replay_line_payload_conflict') !== false,
    'sales-order plan fingerprints, writes, and immutable replay include the craftsmen snapshot'
);
$check(
    substr_count($query, 'craftsmen_snapshot_json') >= 3
        && strpos($query, 'craftsmenForLine($line)') !== false
        && strpos($query, "'craftsmen' => \$v3Ready ? \$this->craftsmenForLine(\$authority) : []") !== false
        && strpos($query, 'CashierV3CheckoutCraftsmenSnapshot::decode') !== false
        && strpos($detail, "['name', 'staffName', 'employeeName'") !== false,
    'both authority query paths expose stored craftsmen names without current-employee lookup'
);
$check(
    strpos($migration, 'ADD COLUMN `craftsmen_snapshot_json` MEDIUMTEXT NOT NULL') !== false
        && substr_count($migration, 'ADD COLUMN `craftsmen_snapshot_json`') === 2
        && strpos($migration, 'DEFAULT') === false
        && strpos($postcheck, "craftsmen_snapshot_json NOT IN ('','[]')") !== false,
    'MySQL 5.6 migration adds both immutable columns without unsupported TEXT defaults or data backfill'
);

if ($failed > 0) {
    exit(1);
}
echo "SALES_ORDER_CRAFTSMEN_SNAPSHOT_CONTRACT=PASS\n";
