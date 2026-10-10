<?php
declare(strict_types=1);
namespace app\services\product\inventory\query;
/** Export expands the already-filtered documents into the same settled batch lines as the list. */
final class InventoryOutboundUnifiedQueryProvider extends InventoryOperationalUnifiedQueryProvider
{
    public function pageCode(): string { return 'inventory_outbound'; }

    public function executeFrozenPlan(array $context, array $plan, string $exportScope, array $fieldKeys): array
    {
        // Always retain the business key internally, even if a saved export did not select that column.
        $result = parent::executeFrozenPlan($context, $plan, $exportScope, array_values(array_unique(array_merge($fieldKeys, ['order_sn']))));
        $documents = $result['exportRows'];
        if (!$documents) return $result;
        $numbers = array_column($documents, 'order_sn');
        $locations = (array)($context['scope_dimensions']['location_id'] ?? []);
        $facts = \think\facade\Db::name('inventory_batch_movement_fact')->alias('f')
            ->join('inventory_batch b', 'b.id=f.batch_id')->join('inventory_stock s', 's.id=f.stock_id')
            ->leftJoin('inventory_business_document_no n', 'n.tenant_id=f.tenant_id AND n.source_type=f.source_type AND n.source_id=f.source_id')
            ->where('f.tenant_id', $context['tenant_id'])->where('f.store_id', (int)$context['store_id'])
            ->whereIn('f.location_id', $locations)->where('f.fact_status', 'SETTLED')
            ->whereIn('f.source_type', ['manual_outbound', 'presale_claim_outbound'])
            ->where(function ($q) use ($numbers): void { $q->whereIn('n.document_no', $numbers)->whereOr('f.source_id', 'in', $numbers); })
            ->fieldRaw('COALESCE(n.document_no,f.source_id) AS order_sn,b.product_name_snapshot AS outbound_product,b.sku_name_snapshot AS outbound_sku,b.batch_no AS outbound_batch,b.manufactured_date AS outbound_manufactured,b.expire_date AS outbound_expire,s.stock_unit AS outbound_unit,s.quantity_scale,f.quantity_units,f.unit_cost_cents AS outbound_unit_cost_cents,f.cost_amount_cents AS outbound_cost_cents,f.detail_remark AS outbound_detail_remark')
            ->order('f.id asc')->limit(\app\services\query\UnifiedQueryExecutionServices::MAX_SOURCE_ROWS + 1)->select()->toArray();
        // Expanding document headers must not bypass the shared row budget or silently truncate a workbook.
        if (count($facts) > \app\services\query\UnifiedQueryExecutionServices::MAX_SOURCE_ROWS) {
            throw new \app\services\query\UnifiedQueryException('UNIFIED_QUERY_SOURCE_LIMIT_EXCEEDED', '出库明细过多，请缩小查询范围后导出。', []);
        }
        $canCost = in_array(InventoryBatchStockQueryContract::PERMISSION_COST, (array)$context['permissions'], true);
        $lines = [];
        foreach ($facts as $fact) {
            $scale = (int)$fact['quantity_scale'];
            $digits = str_pad((string)$fact['quantity_units'], $scale + 1, '0', STR_PAD_LEFT);
            $fact['outbound_quantity'] = $scale ? substr($digits, 0, -$scale) . '.' . substr($digits, -$scale) : $digits;
            if (!$canCost) { $fact['outbound_unit_cost_cents'] = null; $fact['outbound_cost_cents'] = null; }
            $lines[$fact['order_sn']][] = $fact;
        }
        $result['exportRows'] = [];
        foreach ($documents as $document) {
            // The Excel writer does not format Unix timestamps; export the same business-local time as the list.
            if (isset($document['operation_at']) && is_numeric($document['operation_at'])) {
                $document['operation_at'] = (int)$document['operation_at'] > 0 ? date('Y-m-d H:i:s', (int)$document['operation_at']) : '';
            }
            // Never silently omit a document whose authoritative details disappeared during export.
            if (empty($lines[$document['order_sn']])) throw new \RuntimeException('inventory_outbound_export_details_missing');
            foreach ($lines[$document['order_sn']] as $line) {
                $result['exportRows'][] = array_intersect_key(array_merge($document, $line), array_flip($fieldKeys));
            }
        }
        return $result;
    }
}
