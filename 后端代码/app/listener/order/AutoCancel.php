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
namespace app\listener\order;

use app\services\order\StoreOrderServices;
use mohe\utils\Cron;
use mohe\interfaces\ListenerInterface;
use think\facade\Log;

/**
 * 订单定时取消
 * Class Create
 * @package app\listener\order
 */
class AutoCancel extends Cron implements ListenerInterface
{
    /**
     * @param $event
     */
    public function handle($event): void
    {
        //自动取消订单
        $this->tick(1000 * 60 * 20, function () {
            //自动取消订单
            try {
                /** @var StoreOrderServices $orderServices */
                $orderServices = app()->make(StoreOrderServices::class);
                return $orderServices->orderUnpaidCancel();
            } catch (\Throwable $e) {
                Log::error('自动取消订单,失败原因:[' . class_basename($this) . ']' . $e->getMessage());
            }
        });
    }
}
