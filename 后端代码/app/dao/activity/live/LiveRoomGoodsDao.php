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

namespace app\dao\activity\live;


use app\dao\BaseDao;
use app\model\activity\live\LiveRoomGoods;

/**
 * 直播间关联商品
 * Class LiveRoomGoodsDao
 * @package app\dao\activity\live
 */
class LiveRoomGoodsDao extends BaseDao
{


    protected function setModel(): string
    {
        return LiveRoomGoods::class;
    }

    public function clear($id)
    {
        return $this->getModel()->where('live_room_id', $id)->delete();
    }
}
