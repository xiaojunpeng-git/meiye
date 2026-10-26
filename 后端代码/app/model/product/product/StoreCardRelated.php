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
namespace app\model\product\product;

use app\model\product\sku\StoreProductAttrValue;
use app\model\product\product\StoreProduct;
use mohe\traits\ModelTrait;
use mohe\basic\BaseModel;

/**
 *  卡项关联商品
 * Class StoreCardRelated
 * @package app\model\product\product
 */
class StoreCardRelated extends BaseModel
{
    use ModelTrait;

    /**
     * 数据表主键
     * @var string
     */
    protected $pk = 'id';

    /**
     * 模型名称
     * @var string
     */
    protected $name = 'store_card_related';

    /**
     * 一对一关联
     * 门店商品
     * @return \think\model\relation\HasOne
     */
    public function productInfo()
    {
        return $this->hasOne(StoreProduct::class, 'id', 'product_id')->where(['is_verify' => 1, 'is_del' => 0]);
    }

    /**
     * @return \think\model\relation\HasOne
     */
    public function attrInfo()
    {
        return $this->hasOne(StoreProductAttrValue::class, 'unique', 'product_attr_unique');
    }

    /**
     * 卡项搜索
     * @param $query
     * @param $value
     * @return void
     */
    public function searchCardProductIdAttr($query, $value)
    {
        if ($value) {
            $query->where('card_product_id', $value);
        }
    }

}
