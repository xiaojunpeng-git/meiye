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
            // 业务日期是统计和周期筛选口径；操作日期只是审计展示，不可替代业务归属日。
            UnifiedQueryPageRegistry::field('business_date', self::businessDateLabel($pageCode), 'date', $pageCode !== 'inventory_count', $pageCode !== 'inventory_count'),
            UnifiedQueryPageRegistry::field('order_sn', '业务单号', 'text', true, true),
            UnifiedQueryPageRegistry::field('status_name', '状态', 'text', true, true),
        ];
        if ($pageCode === 'inventory_count') {
            $common[] = UnifiedQueryPageRegistry::field('count_date', '盘点日期', 'date', true, true);
            // 旧查询偏好和冻结计划仍可读取真实仓名；默认不展示，不将仓名改写为门店名。
            $common[] = UnifiedQueryPageRegistry::field('location_name', '盘点仓库', 'text', false, false);
        }
        if ($pageCode === 'inventory_outbound') {
            // Retain warehouse identity for existing frozen plans without presenting it as a store.
            $common[] = UnifiedQueryPageRegistry::field('location_name', '出库仓库', 'text', false, false);
        }
        if ($pageCode === 'inventory_transfer') {
            // 仅允许按服务端计算的当前门店收货权限缩小结果，不把此内部布尔值展示或导出。
            $common[] = UnifiedQueryPageRegistry::field('can_receive', '待当前门店收货', 'boolean', false, false, ['filter']);
        }
        $specific = [
            'inventory_inbound' => [
                ['order_type_name', '入库类型', 'text', ''], ['location_name', '入库仓/门店', 'text', ''],
                ['product_summary', '商品摘要', 'text', ''], ['detail_count', '入库项数', 'integer', ''],
                ['cost_amount_cents', '入库金额（分）', 'integer', InventoryBatchStockQueryContract::PERMISSION_COST],
                ['operation_at', '操作日期', 'datetime', ''], ['operator_name', '操作人', 'text', ''],
            ],
            'inventory_outbound' => [
                // Typed and optional header values share the list/filter/export authority.
                ['outbound_recipient', '出库对象', 'text', ''], ['remark', '备注', 'text', ''],
                ['order_type_name', '出库类型', 'text', ''], ['store_name', '出库门店', 'text', ''],
                ['product_summary', '商品摘要', 'text', ''], ['detail_count', '出库项数', 'integer', ''],
                ['cost_amount_cents', '成本金额（分）', 'integer', InventoryBatchStockQueryContract::PERMISSION_COST],
                ['operation_at', '操作日期', 'datetime', ''], ['operator_name', '操作人', 'text', ''],
            ],
            'inventory_count' => [
                // 门店名称与仓库名称分开，筛选、排序和导出读取同一后端投影。
                ['store_name', '门店名称', 'text', ''], ['detail_count', '差异项', 'integer', ''],
                ['change_amount_cents', '盈亏金额（分）', 'integer', InventoryBatchStockQueryContract::PERMISSION_COST],
                ['operation_at', '操作日期', 'datetime', ''], ['operator_name', '操作人', 'text', ''],
            ],
            'inventory_movement' => [
                ['movement_type_name', '业务类型', 'text', ''], ['product_name', '商品名称', 'text', ''],
                ['sku_name', '商品规格', 'text', ''], ['batch_no', '批次号', 'text', ''],
                ['quantity_display', '数量变化', 'text', ''], ['location_name', '发生仓库', 'text', ''],
                ['cost_amount_cents', '成本金额（分）', 'integer', InventoryBatchStockQueryContract::PERMISSION_COST],
                ['operation_at', '操作日期', 'datetime', ''], ['operator_name', '操作人', 'text', ''],
            ],
            'inventory_request' => [
                ['request_no', '请货单号', 'text', ''],
                ['request_party_name', '请货方', 'text', ''], ['supply_party_name', '供货方', 'text', ''],
                ['detail_count', '商品项数', 'integer', ''], ['estimated_amount_cents', '预计金额（分）', 'integer', InventoryBatchStockQueryContract::PERMISSION_COST],
                ['operation_at', '操作日期', 'datetime', ''],
                ['operator_name', '操作人', 'text', ''],
            ],
            'inventory_transfer' => [
                ['from_party_name', '调出方', 'text', ''], ['to_party_name', '调入方', 'text', ''],
                ['detail_count', '商品项数', 'integer', ''], ['transfer_amount_cents', '调拨金额（分）', 'integer', InventoryBatchStockQueryContract::PERMISSION_COST],
                ['operation_at', '操作日期', 'datetime', ''], ['operator_name', '操作人', 'text', ''],
            ],
            'inventory_salon_usage' => [
                ['operation_name', '领退类型', 'text', ''], ['project_name_snapshot', '关联项目', 'text', ''],
                ['detail_count', '耗材项数', 'integer', ''], ['remark', '备注', 'text', ''],
                ['operation_at', '操作日期', 'datetime', ''], ['operator_name', '操作人', 'text', ''],
            ],
            'inventory_import' => [
                ['source_file_name', '文件名称', 'text', ''], ['direction_name', '业务类型', 'text', ''],
                ['business_type', '出入库类型', 'text', ''], ['total_count', '数据量', 'integer', ''],
                ['success_count', '成功行数', 'integer', ''], ['failure_count', '失败行数', 'integer', ''],
                ['document_no', '正式单号', 'text', ''], ['failure_message', '失败原因', 'text', ''],
                ['operation_at', '操作日期', 'datetime', ''], ['operator_name', '操作人', 'text', ''],
            ],
        ];
        if (!isset($specific[$pageCode])) {
            throw new \InvalidArgumentException('inventory_operational_page_code_invalid');
        }
        foreach ($specific[$pageCode] as [$key, $label, $type, $permission]) {
            $common[] = UnifiedQueryPageRegistry::field($key, $label, $type, true, true, [], $permission);
        }
        // Detail fields are export-only: document filters must never change grain to batch lines.
        if ($pageCode === 'inventory_outbound') {
            foreach ([['outbound_product', '商品', 'text'], ['outbound_sku', '规格', 'text'],
                ['outbound_batch', '扣减批次', 'text'], ['outbound_manufactured', '生产日期', 'date'],
                ['outbound_expire', '到期日', 'date'], ['outbound_quantity', '出库数量', 'decimal'],
                ['outbound_unit', '库存单位', 'text'], ['outbound_unit_cost_cents', '成本单价（分）', 'integer'],
                ['outbound_cost_cents', '成本金额（分）', 'integer'], ['outbound_detail_remark', '明细备注', 'text']] as [$key, $label, $type]) {
                $permission = in_array($key, ['outbound_unit_cost_cents', 'outbound_cost_cents'], true) ? InventoryBatchStockQueryContract::PERMISSION_COST : '';
                $common[] = UnifiedQueryPageRegistry::field($key, $label, $type, false, false, ['display', 'export'], $permission);
            }
        }
        return [
            'pageCode' => $pageCode,
            'label' => self::label($pageCode),
            'stableRowKey' => 'record_id',
            'keywordFields' => self::keywordFields($pageCode),
            'requiredFeature' => InventoryBatchStockQueryContract::PERMISSION_VIEW,
            // Outbound uses the registered operational worker and rechecks the same store grant.
            // Other operational pages retain their existing closed export gate.
            'exportFeature' => $pageCode === 'inventory_outbound' ? InventoryBatchStockQueryContract::PERMISSION_EXPORT : 'inventory.operation.export',
            'fields' => $common,
        ];
    }

    /** User-facing label for the authoritative business-date filter of each document type. */
    private static function businessDateLabel(string $pageCode): string
    {
        return [
            'inventory_inbound' => '入库日期', 'inventory_outbound' => '出库日期',
            // 盘点的权威筛选字段是 count_date；隐藏的旧 business_date 仅保留兼容名称。
            'inventory_count' => '业务日期', 'inventory_movement' => '业务日期',
            'inventory_request' => '请货日期', 'inventory_transfer' => '调拨日期',
            'inventory_salon_usage' => '领退日期', 'inventory_import' => '导入业务日期',
        ][$pageCode] ?? '业务日期';
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
