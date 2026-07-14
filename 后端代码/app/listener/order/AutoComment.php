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

use app\services\order\StoreOrderCommentServices;
use mohe\utils\Cron;
use mohe\interfaces\ListenerInterface;
use think\facade\Log;

/**
 * 订单自动默认好评
 * Class AutoComment
 * @package app\listener\order
 */
class AutoComment extends Cron  implements ListenerInterface
{
    /**
     * @param $event
     */
    public function handle($event): void
    {
        //订单自动默认好评
        $this->tick(1000 * 60 * 30, function (){
            //自动默认好评
            try {
                /** @var StoreOrderCommentServices $services */
                $services = app()->make(StoreOrderCommentServices::class);
                return $services->autoCommentOrder();
            } catch (\Throwable $e) {
                Log::error('自动默认好评,失败原因:[' . class_basename($this) . ']' . $e->getMessage());
            }
        });

    }
}
