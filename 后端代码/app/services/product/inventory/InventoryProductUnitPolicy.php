<?php
declare(strict_types=1);

namespace app\services\product\inventory;

/** 普通商品按资料包装单位记库存；院装商品才允许 SKU 耗材单位与包装单位不同。 */
final class InventoryProductUnitPolicy
{
    /** 输入为当前商品包装单位和 SKU 耗材单位；返回当前库存展示及新建库存使用的单位。 */
    public static function resolve(string $saleUnit, string $skuStockUnit, bool $salonStockEnabled): string
    {
        $saleUnit = trim($saleUnit);
        $skuStockUnit = trim($skuStockUnit);
        return $salonStockEnabled
            ? ($skuStockUnit !== '' ? $skuStockUnit : $saleUnit)
            : ($saleUnit !== '' ? $saleUnit : $skuStockUnit);
    }

    /** 与 resolve 同口径，供已限定商品和 SKU 范围的目录及入库锁定查询使用。 */
    public static function selectSql(): string
    {
        return self::expression('p', 'a') . ' AS stock_unit';
    }

    /** 跨主体目录使用固定 SQL 别名；仅接受标识符，不能让请求值进入表达式。 */
    public static function expression(string $productAlias, string $skuAlias): string
    {
        foreach ([$productAlias, $skuAlias] as $alias) {
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $alias)) {
                throw new \InvalidArgumentException('inventory_product_unit_sql_alias_invalid');
            }
        }
        return "CASE WHEN {$productAlias}.salon_stock_enabled = 1 "
            . "THEN COALESCE(NULLIF(TRIM({$skuAlias}.stock_unit), ''), NULLIF(TRIM({$productAlias}.unit_name), ''), '') "
            . "ELSE COALESCE(NULLIF(TRIM({$productAlias}.unit_name), ''), NULLIF(TRIM({$skuAlias}.stock_unit), ''), '') END";
    }
}
