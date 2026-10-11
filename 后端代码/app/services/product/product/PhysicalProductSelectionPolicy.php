<?php
declare(strict_types=1);

namespace app\services\product\product;

/** 共用控件的业务资格正本：库存看参与开关，赠送看销售/仓库状态；不决定门店权限。 */
final class PhysicalProductSelectionPolicy
{
    /** 调用方先固定认证主体；实体类型与未删除为共同有效性边界，两类业务条件互不叠加。 */
    public static function apply($query, bool $inventoryOnly = true, string $prefix = 'p.')
    {
        $query->where($prefix . 'product_type', 0)->where($prefix . 'is_del', 0);
        if ($inventoryOnly) {
            // 库存业务仅看参与库存管理；不能把销售或审核状态再次当作库存资格。
            $query->where($prefix . 'is_inventory', 1);
        } else {
            // 赠送只接收商品列表的“销售中/仓库中”，完全忽略参与库存管理开关。
            $query->where($prefix . 'is_verify', 1)->whereIn($prefix . 'is_show', [0, 1]);
        }
        return $query;
    }
}
