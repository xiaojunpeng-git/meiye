<?php
declare(strict_types=1);

namespace app\services\product\inventory\query;

use app\services\query\UnifiedQueryPageRegistry;

final class InventoryStatisticsUnifiedQueryContract
{
    public const PAGE_CODES = ['inventory_statistics_inbound', 'inventory_statistics_outbound', 'inventory_statistics_expiry', 'inventory_statistics_age'];

    public static function definition(string $pageCode): array
    {
        $movement = in_array($pageCode, ['inventory_statistics_inbound', 'inventory_statistics_outbound'], true);
        $fields = $movement ? [
            UnifiedQueryPageRegistry::field('record_id', '记录标识', 'text', false, false, ['sort']),
            UnifiedQueryPageRegistry::field('business_date', '业务日期', 'date', true, true),
            UnifiedQueryPageRegistry::field('source_type_name', '业务类型', 'text', true, true),
            UnifiedQueryPageRegistry::field('product_name', '商品名称', 'text', true, true),
            UnifiedQueryPageRegistry::field('sku_name', '商品规格', 'text', true, true),
            UnifiedQueryPageRegistry::field('stock_unit', '库存单位', 'text', true),
            UnifiedQueryPageRegistry::field('document_count', '单据数', 'integer', true),
            UnifiedQueryPageRegistry::field('movement_count', '流水数', 'integer', true),
            UnifiedQueryPageRegistry::field('quantity', '数量', 'decimal', true),
            UnifiedQueryPageRegistry::field('cost_amount_cents', '成本金额（分）', 'integer', true, false, [], InventoryBatchStockQueryContract::PERMISSION_COST),
        ] : [
            UnifiedQueryPageRegistry::field('record_id', '记录标识', 'text', false, false, ['sort']),
            UnifiedQueryPageRegistry::field('product_name', '商品名称', 'text', true, true),
            UnifiedQueryPageRegistry::field('sku_name', '商品规格', 'text', true, true),
            UnifiedQueryPageRegistry::field('batch_no', '批次号', 'text', true, true),
            UnifiedQueryPageRegistry::field('batch_balance_quantity', '当前剩余库存', 'decimal', true),
            UnifiedQueryPageRegistry::field('received_date', '正式入库日期', 'date', true, true),
            UnifiedQueryPageRegistry::field('expire_date', '到期日', 'date', true, true),
            UnifiedQueryPageRegistry::field('remaining_shelf_life_days', '距到期日天数', 'integer', $pageCode === 'inventory_statistics_expiry'),
            UnifiedQueryPageRegistry::field('remaining_shelf_life_band', '保质期分档', 'text', $pageCode === 'inventory_statistics_expiry', true),
            UnifiedQueryPageRegistry::field('inventory_age_days', '库龄天数', 'integer', $pageCode === 'inventory_statistics_age'),
            UnifiedQueryPageRegistry::field('inventory_age_band', '库龄分档', 'text', $pageCode === 'inventory_statistics_age', true),
            UnifiedQueryPageRegistry::field('inventory_amount', '库存金额', 'amount', true, false, [], InventoryBatchStockQueryContract::PERMISSION_COST),
        ];
        return [
            'pageCode' => $pageCode, 'label' => self::label($pageCode), 'stableRowKey' => 'record_id',
            'keywordFields' => $movement ? ['product_name', 'sku_name', 'source_type_name'] : ['product_name', 'sku_name', 'batch_no'],
            'requiredFeature' => InventoryBatchStockQueryContract::PERMISSION_VIEW,
            'exportFeature' => 'inventory.operation.export', 'fields' => $fields,
        ];
    }

    private static function label(string $pageCode): string
    {
        return ['inventory_statistics_inbound' => '入库统计', 'inventory_statistics_outbound' => '出库统计', 'inventory_statistics_expiry' => '批次与临期', 'inventory_statistics_age' => '产品库龄'][$pageCode] ?? '';
    }
}
