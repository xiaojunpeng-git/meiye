<?php

namespace app\services\product\product;

/** 将新销售用途与历史权益承载分离；不修改上下架、余次、库存或历史关联。 */
final class StoreProductSaleUsagePolicy
{
    /** 输入必须是服务端已限定门店的主商品；请求参数不能声明或覆盖承载用途。 */
    public static function isHistoricalCarrier(array $product): bool
    {
        $keyword = (string)($product['keyword'] ?? '');
        $storeId = (int)($product['relation_id'] ?? 0);
        if ($keyword === '' || $storeId <= 0 || (int)($product['type'] ?? 0) !== 1) {
            return false;
        }
        $regexp = (string)config('product_catalog.historical_store_keyword_regexp', '');
        return $regexp !== '' && preg_match('~' . $regexp . '~D', $keyword) === 1
            && substr($keyword, strrpos($keyword, ':STORE:') + 7) === (string)$storeId;
    }

    /** 在目录 limit 之前排除承载行，避免历史大批量导入挤占正常销售目录上限。
     * SQL/PHP 使用同一配置、大小写及门店后缀规则；只适用于新销售/正常同步查询。
     */
    public static function excludeHistoricalCarriers($query, string $alias)
    {
        if (!preg_match('/^[a-z_]+$/D', $alias)) {
            throw new \InvalidArgumentException('Invalid product table alias');
        }
        $regexp = (string)config('product_catalog.historical_store_keyword_regexp', '');
        if ($regexp === '') {
            return $query;
        }
        return $query->whereRaw(
            "NOT ({$alias}.type=1 AND COALESCE({$alias}.keyword,'') REGEXP BINARY ?"
            . " AND SUBSTRING_INDEX(COALESCE({$alias}.keyword,''),':STORE:',-1)=CAST({$alias}.relation_id AS CHAR))",
            [$regexp]
        );
    }
}
