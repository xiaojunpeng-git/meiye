<?php
declare(strict_types=1);

namespace app\services\product\inventory;

/**
 * Normal physical-flow queries exclude only a fully neutralized presale mistake
 * and its maintenance correction. Raw facts remain available for reconciliation,
 * cost correction and order void. Real presale claims are physical dispatches.
 */
final class InventoryNormalMovementScopeServices
{
    /** Apply before count, grouping and pagination; caller supplies its authorized `f` query. */
    public function excludeCorrectedPresalePairs($query): void
    {
        $query->whereNotExists(function ($pair): void {
            $this->pair($pair);
            $pair->whereRaw('pso.id=f.id');
        })->whereNotExists(function ($pair): void {
            $this->pair($pair);
            $pair->whereRaw('psr.id=f.id');
        });
    }

    /** Exact same-tenant/store/order/line/batch/quantity/cost pair; never hide an unresolved error. */
    private function pair($query): void
    {
        $query->name('inventory_batch_movement_fact')->alias('psr')
            ->join('inventory_batch_movement_fact pso', 'pso.id=psr.reversal_of')
            ->join('cashier_v3_sales_order_line psl', 'psl.tenant_id=pso.tenant_id AND psl.store_id=pso.store_id AND psl.order_id=pso.source_id AND psl.order_line_id=pso.source_detail_id')
            ->join('cashier_v3_sales_order psh', 'psh.tenant_id=psl.tenant_id AND psh.store_id=psl.store_id AND psh.order_id=psl.order_id')
            ->where('pso.source_type', 'cashier_sale')->where('pso.direction', -1)
            ->where('pso.reversal_of', 0)->where('pso.fact_status', 'SETTLED')
            ->where('psr.source_type', 'cashier_presale_stock_repair')->where('psr.direction', 1)
            ->where('psr.fact_status', 'SETTLED')->where('psl.item_type', 'product')
            ->where('psl.line_direction', 'forward')->where('psl.is_presale', 1)->where('psl.inventory_outbound_required', 0)
            ->whereRaw("psr.fact_key=CONCAT('invpsr:',SHA2(CAST(pso.id AS CHAR),256))");
        foreach (['tenant_id', 'organization_id', 'organization_path', 'store_id', 'location_id',
            'stock_id', 'batch_id', 'source_id', 'source_detail_id', 'quantity_units',
            'unit_cost_cents', 'cost_amount_cents'] as $field) {
            $query->whereRaw('pso.' . $field . '=psr.' . $field);
        }
        // Multiple reversals are not a balanced pair and must stay visible for investigation.
        $query->whereNotExists(function ($duplicate): void {
            $duplicate->name('inventory_batch_movement_fact')->alias('psd')
                ->whereRaw('psd.tenant_id=pso.tenant_id AND psd.reversal_of=pso.id AND psd.id<>psr.id');
        });
    }
}
