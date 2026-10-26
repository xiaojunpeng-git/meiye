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

namespace app\model\order;


use app\model\user\User;
use mohe\basic\BaseModel;

/**
 * Class StoreHangOrder
 * @package app\model\order
 */
class StoreHangOrder extends BaseModel
{

    /**
     * 表名
     * @var string
     */
    protected $name = 'store_hang_order';

    /**
     * 主键id
     * @var string
     */
    protected $pk = 'id';

    /**
     * @return \think\model\relation\HasOne
     */
    public function user()
    {
        return $this->hasOne(User::class, 'uid', 'uid')->bind([
            'nickname' => 'nickname',
            'avatar' => 'avatar'
        ]);
    }

    /**
     * @param $query
     * @param $value
     */
    public function searchStoreIdAttr($query, $value)
    {
        $query->where('store_id', $value);
    }

    /**
     * @param $query
     * @param $value
     */
    public function searchStaffIdAttr($query, $value)
    {
        $query->where('staff_id', $value);
    }

    /**
     * @param $query
     * @param $value
     */
    public function searchUidAttr($query, $value)
    {
        $query->where('uid', $value);
    }

    /**
     * @param $query
     * @param $value
     */
    public function searchIsCheckAttr($query, $value)
    {
        $query->where('is_check', $value);
    }

}
