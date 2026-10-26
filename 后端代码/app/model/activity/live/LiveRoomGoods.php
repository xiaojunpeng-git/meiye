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

namespace app\model\activity\live;


use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;

/**
 * 直播间关联商品
 * Class LiveRoomGoods
 * @package app\model\activity\live
 */
class LiveRoomGoods extends BaseModel
{
    use ModelTrait;

    protected $name = 'live_room_goods';

    public function goods()
    {
        return $this->hasOne(LiveGoods::class, 'id', 'live_goods_id');
    }

    public function room()
    {
        return $this->hasOne(LiveRoom::class, 'id', 'live_room_id');
    }
}
