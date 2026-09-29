<?php
declare(strict_types=1);

namespace app\services\product\inventory\query;

use app\services\query\UnifiedQueryPageRegistry;

/**
 * Read-only document and ledger projections used by the operational inventory
 * pages. These fields deliberately mirror the V3 read models; they never
 * expose a legacy stock document as a second authority.
 */
final class InventoryOperationalUnifiedQueryContract
{
    public const PAGE_CODES = [
        'inventory_inbound', 'inventory_outbound', 'inventory_count',
        'inventory_movement', 'inventory_request', 'inventory_transfer',
        'inventory_salon_usage', 'inventory_import',
    ];

    public static function definition(string $pageCode): array
    {
        $common = [
            UnifiedQueryPageRegistry::field('record_id', '记录标识', 'text', false, false, ['sort']),
            // 盘点新周期按完成时刻；保留旧业务日期字段以兼容已保存的查询设置，但不再默认展示。
            UnifiedQueryPageRegistry::field('business_date', '业务日期', 'date', $pageCode !== 'inventory_count', $pageCode !== 'inventory_count'),
            UnifiedQueryPageRegistry::field('order_sn', '业务单号', 'text', true, true),
            UnifiedQueryPageRegistry::field('status_name', '状态', 'text', true, true),
        ];
        if ($pageCode === 'inventory_count') {
            $common[] = UnifiedQueryPageRegistry::field('count_date', '盘点日期', 'date', true, true);
        }
        $specific = [
            'inventory_inbound' => [
                ['order_type_name', '入库类型', 'text', ''], ['location_name', '入库仓/门店', 'text', ''],
                ['product_summary', '商品摘要', 'text', ''], ['detail_count', '入库项数', 'integer', ''],
                ['cost_amount_cents', '入库金额（分）', 'integer', InventoryBatchStockQueryContract::PERMISSION_COST],
            ],
            'inventory_outbound' => [
                ['order_type_name', '出库类型', 'text', ''], ['location_name', '出库仓/门店', 'text', ''],
                ['product_summary', '商品摘要', 'text', ''], ['detail_count', '出库项数', 'integer', ''],
                ['cost_amount_cents', '成本金额（分）', 'integer', InventoryBatchStockQueryContract::PERMISSION_COST],
                ['operation_at', '操作时间', 'datetime', ''],
            ],
            'inventory_count' => [
                ['location_name', '盘点主体', 'text', ''], ['detail_count', '差异项', 'integer', ''],
                ['change_amount_cents', '盈亏金额（分）', 'integer', InventoryBatchStockQueryContract::PERMISSION_COST],
                ['operation_at', '操作时间', 'datetime', ''],
            ],
            'inventory_movement' => [
                ['movement_type_name', '业务类型', 'text', ''], ['product_name', '商品名称', 'text', ''],
                ['sku_name', '商品规格', 'text', ''], ['batch_no', '批次号', 'text', ''],
                ['quantity_display', '数量变化', 'text', ''], ['location_name', '发生仓库', 'text', ''],
                ['cost_amount_cents', '成本金额（分）', 'integer', InventoryBatchStockQueryContract::PERMISSION_COST],
                ['operation_at', '操作时间', 'datetime', ''],
            ],
            'inventory_request' => [
                ['request_no', '请货单号', 'text', ''],
                ['request_party_name', '请货方', 'text', ''], ['supply_party_name', '供货方', 'text', ''],
                ['detail_count', '商品项数', 'integer', ''], ['estimated_amount_cents', '预计金额（分）', 'integer', InventoryBatchStockQueryContract::PERMISSION_COST],
                ['operation_at', '操作时间', 'datetime', ''],
            ],
            'inventory_transfer' => [
                ['from_party_name', '调出方', 'text', ''], ['to_party_name', '调入方', 'text', ''],
                ['detail_count', '商品项数', 'integer', ''], ['transfer_amount_cents', '调拨金额（分）', 'integer', InventoryBatchStockQueryContract::PERMISSION_COST],
                ['operation_at', '操作时间', 'datetime', ''],
            ],
            'inventory_salon_usage' => [
                ['operation_name', '领退类型', 'text', ''], ['project_name_snapshot', '关联项目', 'text', ''],
                ['detail_count', '耗材项数', 'integer', ''], ['remark', '备注', 'text', ''],
                ['operation_at', '操作时间', 'datetime', ''],
            ],
            'inventory_import' => [
                ['source_file_name', '文件名称', 'text', ''], ['direction_name', '业务类型', 'text', ''],
                ['business_type', '出入库类型', 'text', ''], ['total_count', '数据量', 'integer', ''],
                ['success_count', '成功行数', 'integer', ''], ['failure_count', '失败行数', 'integer', ''],
                ['document_no', '正式单号', 'text', ''], ['failure_message', '失败原因', 'text', ''],
                ['updated_at_display', '导入时间', 'datetime', ''],
            ],
        ];
        if (!isset($specific[$pageCode])) {
            throw new \InvalidArgumentException('inventory_operational_page_code_invalid');
        }
        foreach ($specific[$pageCode] as [$key, $label, $type, $permission]) {
            $common[] = UnifiedQueryPageRegistry::field($key, $label, $type, true, true, [], $permission);
        }
        return [
            'pageCode' => $pageCode,
            'label' => self::label($pageCode),
            'stableRowKey' => 'record_id',
            'keywordFields' => self::keywordFields($pageCode),
            'requiredFeature' => InventoryBatchStockQueryContract::PERMISSION_VIEW,
            // Export workers only exist for the batch-balance provider today. Keep
            // document-page export closed until each page has a worker context
            // that can rebuild the same store-scoped projection.
            'exportFeature' => 'inventory.operation.export',
            'fields' => $common,
        ];
    }

    public static function label(string $pageCode): string
    {
        return [
            'inventory_inbound' => '入库管理', 'inventory_outbound' => '出库管理',
            'inventory_count' => '库存盘点', 'inventory_movement' => '出入库记录',
            'inventory_request' => '请货管理', 'inventory_transfer' => '调拨管理',
            'inventory_salon_usage' => '院装管理', 'inventory_import' => '导入记录',
        ][$pageCode] ?? '';
    }

    private static function keywordFields(string $pageCode): array
    {
        $fields = ['order_sn'];
        if ($pageCode === 'inventory_request') return ['request_no', 'order_sn'];
        if ($pageCode === 'inventory_movement') return array_merge($fields, ['product_name', 'sku_name', 'batch_no']);
        if (in_array($pageCode, ['inventory_inbound', 'inventory_outbound'], true)) return array_merge($fields, ['product_summary']);
        if ($pageCode === 'inventory_salon_usage') return ['order_sn', 'project_name_snapshot', 'remark'];
        if ($pageCode === 'inventory_import') return ['order_sn', 'source_file_name', 'document_no', 'failure_message'];
        return $fields;
    }
}
