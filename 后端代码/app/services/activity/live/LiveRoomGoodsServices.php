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

namespace app\services\activity\live;


use app\dao\activity\live\LiveRoomGoodsDao;
use app\services\BaseServices;

/**
 * 直播间关联商品
 * Class LiveRoomGoodsServices
 * @package app\services\activity\live
 * @mixin LiveRoomGoodsDao
 */
class LiveRoomGoodsServices extends BaseServices
{
	/**
	* @param LiveRoomGoodsDao $dao
	 */
    public function __construct(LiveRoomGoodsDao $dao)
    {
        $this->dao = $dao;
    }
}
