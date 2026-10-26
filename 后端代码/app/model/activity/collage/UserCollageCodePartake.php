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
namespace app\model\activity\collage;

use app\model\product\product\StoreProduct;
use app\model\user\User;
use app\model\product\sku\StoreProductAttrValue;
use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;

/**
 *  用户参与拼单Model
 * Class UserCollagePartake
 * @package app\model\collage
 */
class UserCollageCodePartake extends BaseModel
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
    protected $name = 'user_collage_code_partake';

    /**
     * 一对一关联
     * 购物车关联商品商品详情
     * @return \think\model\relation\HasOne
     */
    public function productInfo()
    {
        return $this->hasOne(StoreProduct::class, 'id', 'product_id');
    }

    /**
     * 一对一关联
     * 购物车关联商品商品规格
     * @return \think\model\relation\HasOne
     */
    public function attrInfo()
    {
        return $this->hasOne(StoreProductAttrValue::class, 'unique', 'product_attr_unique');
    }

    /**
     * 一对一关联
     * 关联用户信息
     * @return \think\model\relation\HasOne
     */
    public function userInfo()
    {
        return $this->hasOne(User::class, 'uid', 'uid');
    }
}
