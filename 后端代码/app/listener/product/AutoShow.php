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
namespace app\listener\product;

use app\services\product\product\StoreProductServices;
use mohe\utils\Cron;
use mohe\interfaces\ListenerInterface;
use think\facade\Log;

/**
 * 自动上下架
 * Class Create
 * @package app\listener\order
 */
class AutoShow extends Cron implements ListenerInterface
{
    /**
     * @param $event
     */
    public function handle($event): void
    {
        //自动上下架
        $this->tick(1000 * 60, function () {
            //自动上下架
            try {
                /** @var StoreProductServices $storeProductServices */
                $storeProductServices = app()->make(StoreProductServices::class);
                return $storeProductServices->autoUpperShelves();
            } catch (\Throwable $e) {
                Log::error('自动上下架,失败原因:[' . class_basename($this) . ']' . $e->getMessage());
            }
        });
    }
}
