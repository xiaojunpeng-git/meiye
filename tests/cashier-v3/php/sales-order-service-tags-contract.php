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

$plan = $read($root . '/app/services/cashier/v3/order/settlement/CashierV3SalesOrderPlanV1.php');
$facts = $read($root . '/app/services/cashier/v3/fact/CashierV3SaleOnlyFactAssembler.php');
$query = $read($root . '/app/services/cashier/v3/order/CashierV3SalesOrderQueryServices.php');
$preparation = $read($root . '/app/services/cashier/v3/settlement/CashierV3CheckoutPreparationServices.php');
$kernel = $read($root . '/app/services/cashier/v3/settlement/CashierV3CheckoutSettlementKernel.php');
$repository = $read($root . '/app/services/cashier/v3/settlement/ThinkPhpCashierV3CheckoutRequestRepository.php');
$rebuilder = $read($root . '/app/services/cashier/v3/settlement/CashierV3CheckoutDraftAuthorityRebuilder.php');
$projection = $read($root . '/app/services/cashier/v3/settlement/CashierV3CheckoutProjectionServices.php');
$migration = $read($root . '/database/upgrades/2026-08-02-收银V3销售订单服务标签快照/02-正式升级.sql');
$checkout = $read($frontend . '/components/cashier/CashierCheckoutOverlay.vue');
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
    strpos($plan, "'service_object', 'is_experience'") !== false
        && strpos($plan, "'service_object' => \$line['service_object']") !== false
        && strpos($plan, "'is_experience' => \$line['is_experience']") !== false,
    'locked checkout fields are copied into immutable sales-order lines'
);
$check(
    strpos($facts, "'service_object', 'is_experience'") !== false,
    'sale fact assembler accepts the same locked service-tag fields as the sales-order plan'
);
$check(
    strpos($facts, "'craftsmen_snapshot_json'") !== false,
    'sale fact assembler accepts the immutable craftsmen snapshot persisted by the sales-order plan'
);
$check(
    strpos($plan, "sales_order_project_service_object_invalid") !== false
        && strpos($plan, "sales_order_non_project_service_tags_invalid") !== false
        && strpos($plan, "'serviceObject' => \$serviceObject") !== false
        && strpos($plan, "'isExperience' => \$isExperience") !== false,
    'service tags are constrained by item type and included in the locked-sale fingerprint'
);
$check(
    strpos($preparation, "'serviceObject' => (string)(\$line['serviceObject'] ?? '')") !== false
        && strpos($preparation, "'isExperience' => !empty(\$line['isExperience']) ? 1 : 0") !== false
        && strpos($kernel, "'serviceObject' => \$serviceObject") !== false
        && strpos($kernel, "'isExperience' => \$isExperience") !== false
        && strpos($repository, "'serviceObject' => 'service_object'") !== false
        && strpos($repository, "'isExperience' => 'is_experience'") !== false
        && strpos($rebuilder, "'serviceObject' => (string)(\$row['service_object'] ?? '')") !== false,
    'project service tags survive preparation, draft persistence and draft rebuild'
);
$check(
    strpos($projection, "'serviceObject' => \$serviceObject") !== false
        && strpos($projection, "'isExperience' => \$isExperience") !== false
        && strpos($projection, 'checkout_projection_sale_service_tags_invalid') !== false,
    'project service tags remain fingerprinted and visible in checkout projection'
);
$check(
    strpos($migration, 'ADD COLUMN `service_object`') !== false
        && strpos($migration, 'ADD COLUMN `is_experience`') !== false,
    'migration adds explicit immutable service-tag columns'
);
$check(
    substr_count($query, 'service_object,is_experience') >= 2
        && strpos($query, "'serviceRecipientType' => (string)(\$line['service_object'] ?? '')") !== false
        && strpos($query, "'isExperience' => (int)(\$line['is_experience'] ?? 0) === 1") !== false,
    'order query reads service tags from authority snapshots'
);
$check(
    strpos($checkout, 'checkoutLineServiceTags') !== false
        && strpos($detail, 'isExperienceItem') !== false,
    'checkout confirmation and order detail display service tags'
);

if ($failed > 0) {
    exit(1);
}
echo "SALES_ORDER_SERVICE_TAGS_CONTRACT=PASS\n";
