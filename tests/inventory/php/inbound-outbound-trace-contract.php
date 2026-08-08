<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$storeRoutes = (string)file_get_contents($root . '/后端代码/route/store.php');
$adminRoutes = (string)file_get_contents($root . '/后端代码/route/admin.php');
$trace = (string)file_get_contents($root . '/后端代码/app/services/product/inventory/InventoryInboundOutboundTraceReadServices.php');
$modal = (string)file_get_contents($root . '/前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue');
$storeInbound = (string)file_get_contents($root . '/后端代码/app/services/product/inventory/InventoryManualInboundReadServices.php');
$hqInbound = (string)file_get_contents($root . '/后端代码/app/services/product/inventory/InventoryPlatformHqInboundServices.php');
$failed = 0;
function traceCheck(string $name, bool $condition): void { global $failed; echo ($condition ? 'PASS ' : 'FAIL ') . $name . PHP_EOL; if (!$condition) $failed++; }

traceCheck('store and headquarters expose one protected inbound outbound-trace endpoint',
    strpos($storeRoutes, 'v3/inbound/:id/outbound-details') !== false
    && strpos($adminRoutes, 'v3/hq/inbound/:id/outbound-details') !== false);
traceCheck('trace starts from settled manual inbound facts and follows batch origin lineage',
    strpos($trace, "->where('f.source_type', 'manual_inbound')") !== false
    && strpos($trace, 'batchLineagePredicate($batchIds, $originIds)') !== false
    && strpos($trace, "'b.id IN ('") !== false
    && strpos($trace, "'b.origin_batch_id IN ('") !== false);
traceCheck('trace only returns effective outbound facts and excludes their settled reversals',
    strpos($trace, "->where('f.direction', -1)") !== false
    && strpos($trace, 'NOT EXISTS (SELECT 1 FROM {$movementFactTable} reversed') !== false
    && strpos($trace, 'reversed.reversal_of=f.id') !== false
    && strpos($trace, "'manual_outbound'") !== false
    && strpos($trace, "'completion_batch'") !== false);
traceCheck('trace enforces the caller location and redacts costs without permission',
    strpos($trace, "->where('f.location_id', (int)\$location['id'])") !== false
    && strpos($trace, 'if (!$canViewCost)') !== false
    && strpos($storeInbound, 'outboundDetails') !== false
    && strpos($hqInbound, 'outboundDetails') !== false);
traceCheck('trace projects formal business document numbers and never returns internal source ids',
    strpos($trace, "->fieldRaw('f.batch_id, b.origin_batch_id, n.document_no')") !== false
    && strpos($trace, "f.id AS movement_fact_id, n.document_no") !== false
    && strpos($trace, "documentNumber(\$fact['document_no'] ?? null, '历史出库记录')") !== false
    && strpos($trace, "documentNumber(\$inbound[0]['document_no'] ?? null, '历史入库记录')") !== false
    && strpos($trace, "COALESCE(NULLIF(n.document_no, ''), '历史") === false
    && strpos($trace, "unset(\$fact['document_no'])") !== false
    && strpos($trace, "'source_id' => \$sourceId") === false
    && strpos($trace, 'f.source_id, f.source_detail_id') === false);
traceCheck('trace dialog labels the inbound document number and does not render source_id',
    strpos($modal, "入库单号：<b>{{ detail.document.order_sn || '历史入库记录' }}</b>") !== false
    && strpos($modal, 'detail.document.source_id') === false);

echo "INVENTORY_INBOUND_OUTBOUND_TRACE_CONTRACT_RESULT failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
