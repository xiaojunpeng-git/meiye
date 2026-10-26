<?php

namespace app\services\cashier\v3\settlement;

/**
 * Translate only known inventory checkout failures into actionable cashier text.
 * Unknown settlement failures keep their original error path and audit reason.
 */
final class CashierV3CheckoutInventoryFailureMessage
{
    /** Return null for unrelated failures so their result code and original message remain unchanged. */
    public static function forReason(string $reason): ?string
    {
        if (in_array($reason, [
            'sale_inventory_shortage_denied',
            'inventory_shortage_denied',
            'sale_inventory_stock_not_ready',
            'inventory_stock_not_ready',
        ], true)) {
            // 未找到可售库存记录与数量不足，对收银员都是当前门店无足够可售库存。
            return '库存不足，未完成结账。请核对当前门店库存后重试。';
        }

        if ($reason === 'sale_inventory_default_location_not_ready') {
            return '当前门店库存库位未配置好，未完成结账。请联系管理员核对库位。';
        }

        if (in_array($reason, [
            'sale_inventory_stock_batch_balance_mismatch',
            'inventory_stock_batch_balance_mismatch',
        ], true)) {
            return '库存数据异常，未完成结账。请联系管理员核对库存。';
        }

        if (in_array($reason, [
            'sale_inventory_stock_changed',
            'sale_inventory_stock_mapping_changed',
            'sale_inventory_batch_changed',
            'inventory_stock_changed',
            'inventory_batch_changed',
            'sale_inventory_stock_cas_failed',
            'sale_inventory_batch_cas_failed',
        ], true)) {
            return '库存已发生变化，未完成结账。请核对当前门店库存后重试。';
        }

        return null;
    }
}
