<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$service = file_get_contents($root . '/后端代码/app/services/product/inventory/InventoryManualDocumentReversalServices.php');
$migration = file_get_contents($root . '/后端代码/database/upgrades/2026-08-05-库存V3手工单作废权威/02-正式升级.sql');
$failed = 0;

function reversalContract(string $name, bool $condition): void
{
    global $failed;
    echo ($condition ? 'PASS ' : 'FAIL ') . $name . "\n";
    if (!$condition) $failed++;
}

reversalContract('store and headquarters commands share one reversal kernel',
    strpos($service, 'reverseForStore') !== false
    && strpos($service, 'reverseForHeadquarters') !== false
    && substr_count($service, 'reverseAtLocation(') >= 3);
reversalContract('only manual inbound and outbound documents can enter the authority',
    strpos($service, "public const INBOUND = 'manual_inbound'") !== false
    && strpos($service, "public const OUTBOUND = 'manual_outbound'") !== false
    && strpos($service, "in_array(\$sourceType, [self::INBOUND, self::OUTBOUND], true)") !== false);
reversalContract('command requires a bounded reason and an idempotency key',
    strpos($service, "array_keys(\$input) !== ['source_id', 'idempotency_key', 'reason']") !== false
    && strpos($service, 'mb_strlen($reason) < 2') !== false
    && strpos($migration, 'UNIQUE KEY `uk_tenant_idempotency`') !== false);
reversalContract('one original document can be voided only once without deleting history',
    strpos($migration, 'UNIQUE KEY `uk_tenant_document`') !== false
    && strpos($service, "->where('reversal_of', 0)") !== false
    && strpos($service, "'reversalOf' => (int)\$original['id']") !== false
    && strpos($service, 'delete(') === false);
reversalContract('inbound reversal refuses unavailable original batch quantity',
    strpos($service, 'inventory_manual_reversal_inbound_batch_insufficient') !== false
    && strpos($service, "'available_quantity_units' => \$new") !== false);
reversalContract('outbound reversal restores exact original batch and cost',
    strpos($service, "'batchId' => (int)\$original['batch_id']") !== false
    && strpos($service, "'direction' => -(int)\$original['direction']") !== false
    && strpos($service, "'unitCostCents' => (int)\$original['unit_cost_cents']") !== false
    && strpos($service, "'costAmountCents' => (int)\$original['cost_amount_cents']") !== false);
reversalContract('ledger and aggregate mismatches abort instead of being hidden',
    strpos($service, 'inventory_manual_reversal_batch_ledger_mismatch') !== false
    && strpos($service, 'inventory_manual_reversal_stock_ledger_mismatch') !== false);
reversalContract('audit receipt preserves operator reason result and four inventory times',
    strpos($migration, '`operator_type`') !== false && strpos($migration, '`operator_id`') !== false
    && strpos($migration, '`reason`') !== false && strpos($migration, '`result_snapshot`') !== false
    && strpos($migration, '`business_date`') !== false && strpos($migration, '`occurred_at`') !== false
    && strpos($migration, '`settled_at`') !== false && strpos($migration, '`recorded_at`') !== false);
$projection = file_get_contents($root . '/后端代码/app/services/product/inventory/InventoryManualDocumentReversalProjectionServices.php');
reversalContract('list projection exposes status and the action flag needed by both clients',
    strpos($projection, "'已作废' : '已完成'") !== false
    && strpos($projection, "\$row['can_void'] = \$reversal === null") !== false
    && strpos($projection, "\$row['void_reason']") !== false
    && strpos($projection, "\$row['voided_at']") !== false);

echo "INVENTORY_MANUAL_REVERSAL_CONTRACT failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
