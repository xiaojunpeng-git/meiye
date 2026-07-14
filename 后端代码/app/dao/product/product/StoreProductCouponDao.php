<?php
// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2020 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------
declare (strict_types=1);

namespace app\dao\product\product;

use app\dao\BaseDao;
use app\model\product\product\StoreProductCoupon;

/**
 *
 * Class StoreProductCouponDao
 * @package app\dao\coupon
 */
class StoreProductCouponDao extends BaseDao
{

    /**
     * 设置模型
     * @return string
     */
    protected function setModel(): string
    {
        return StoreProductCoupon::class;
    }

	/**
	 * 获取商品关联优惠卷
	 * @param array $product_ids
	 * @param string $field
	 * @return array
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function getProductCoupon(array $product_ids, string $field = '*')
    {
        return $this->search(['product_id' => $product_ids])->field($field)->select()->toArray();
    }

}
