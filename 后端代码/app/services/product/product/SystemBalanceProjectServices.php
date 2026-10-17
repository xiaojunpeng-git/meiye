<?php

namespace app\services\product\product;

use app\services\report\StoreReportCurrentProductCategoryServices;
use think\facade\Db;

/**
 * The non-saleable balance project is a classification identity, never a sale line.
 * Recharge and wallet refunds retain their own payment facts and only borrow its current category.
 */
final class SystemBalanceProjectServices
{
    public const KEY = 'member_balance';

    /** A missing or damaged binding must stay unclassified, never guess by product name. */
    public function productId(): int
    {
        $id = (int)Db::name('system_business_product')->where('business_key', self::KEY)->value('product_id');
        if ($id <= 0) return 0;
        $valid = Db::name('store_product')->where('id', $id)->where('type', 0)
            ->where('relation_id', 0)->where('product_type', 6)->where('is_del', 0)->find();
        return $valid ? $id : 0;
    }

    public function isProtected(int $productId): bool
    {
        return $productId > 0 && $productId === $this->productId();
    }

    /** @return array{id:int,path:string,platform_product_id:int}|array{} */
    public function category(int $storeId): array
    {
        $id = $this->productId();
        if ($id === 0) return [];
        return (new StoreReportCurrentProductCategoryServices())->resolve([
            ['store_id' => $storeId, 'product_id' => $id],
        ])[0] ?? [];
    }
}
