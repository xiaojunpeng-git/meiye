<?php
namespace app\services\product\product;

/** 库存预警和售罄只适用于启用库存管理的实体产品。 */
final class ProductStockEligibility
{
    public static function appliesTo($product): bool
    {
        return (int)($product['product_type'] ?? -1) === 0
            && (int)($product['is_inventory'] ?? 0) === 1;
    }

    /** 列表与数量使用相同条件，历史误标记不需要直接改库。 */
    public static function constrain($query): void
    {
        $query->where('product_type', 0)->where('is_inventory', 1);
    }
}
