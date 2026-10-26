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
namespace app\jobs\activity;

use mohe\basic\BaseJobs;
use mohe\traits\QueueTrait;
use think\facade\Log;
use app\services\activity\live\LiveGoodsServices;
use app\services\activity\live\LiveRoomServices;

/**
 * 自动更新直播间状态和直播间产品状态
 * Class AutoUpdateLiveJob
 * @package app\jobs\live
 */
class AutoUpdateLiveJob extends BaseJobs
{
    use QueueTrait;

    /**
     * @return string
     */
    protected static function queueName()
    {
        return 'MOHE_PRO_TASK';
    }

    public function doJob()
    {
        //更新直播商品状态
        try {
            /** @var LiveGoodsServices $liveGoods */
            $liveGoods = app()->make(LiveGoodsServices::class);
            $liveGoods->syncGoodStatus();
        } catch (\Throwable $e) {
            Log::error('更新直播商品状态失败,失败原因:[' . class_basename($this) . ']' . $e->getMessage());
        }
        //更新直播间状态
        try {
            /** @var LiveRoomServices $liveRoom */
            $liveRoom = app()->make(LiveRoomServices::class);
            $liveRoom->syncRoomStatus();
        } catch (\Throwable $e) {
            Log::error('更新直播间状态失败,失败原因:[' . class_basename($this) . ']' . $e->getMessage());
        }
		return true;

    }
}
