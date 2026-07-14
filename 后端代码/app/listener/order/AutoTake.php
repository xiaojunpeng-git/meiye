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

use app\services\order\StoreOrderTakeServices;
use mohe\utils\Cron;
use mohe\interfaces\ListenerInterface;
use think\facade\Log;

/**
 * 订单自动确认收货
 * Class AutoTake
 * @package app\listener\order
 */
class AutoTake extends Cron  implements ListenerInterface
{
    /**
     * @param $event
     */
    public function handle($event): void
    {
        //订单自动确认收货
        $this->tick(1000 * 60 * 30, function (){
            //自动收货
            try {
                /** @var StoreOrderTakeServices $services */
                $services = app()->make(StoreOrderTakeServices::class);
                return $services->autoTakeOrder();
            } catch (\Throwable $e) {
                Log::error('自动收货,失败原因:[' . class_basename($this) . ']' . $e->getMessage());
            }
        });

    }
}
