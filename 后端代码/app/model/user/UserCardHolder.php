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

namespace app\model\user;

use app\model\product\product\StoreProduct;
use app\model\product\sku\StoreProductAttrValue;
use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;
use think\Model;

/**
 * Class UserVisit
 * @package app\model\user
 */
class UserCardHolder extends BaseModel
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
    protected $name = 'user_card_holder';

    /**
     * 一对一关联
     * 门店商品
     * @return \think\model\relation\HasOne
     */
    public function productInfo()
    {
        return $this->hasOne(StoreProduct::class, 'id', 'product_id')->where(['is_show' => 1, 'is_verify' => 1, 'is_del' => 0]);
    }

    /**
     * @param Model $query
     * @param $value
     */
    public function searchIsDelAttr($query, $value)
    {
        if ($value !== '') $query->where('is_del', $value);
    }

    /**
     * @param Model $query
     * @param $value
     */
    public function searchUidAttr($query, $value)
    {
        if ($value !== '') $query->where('uid', $value);
    }

    /**
     * @param Model $query
     * @param $value
     */
    public function searchStoreIdAttr($query, $value)
    {
        if ($value !== '') $query->where('store_id', $value);
    }
}
