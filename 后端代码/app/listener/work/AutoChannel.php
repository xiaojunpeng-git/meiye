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

namespace app\listener\work;


use app\services\work\WorkChannelCodeServices;
use mohe\interfaces\ListenerInterface;
use mohe\utils\Cron;
use think\facade\Log;

/**
 * 渠道码定时任务
 * Class AutoChannel
 * @package app\listener\work
 */
class AutoChannel extends Cron implements ListenerInterface
{

    public function handle($event): void
    {
        //1分钟执行一次
        $this->tick(1000, function () {
            /** @var WorkChannelCodeServices $service */
            $service = app()->make(WorkChannelCodeServices::class);

            try {
                $service->cronHandle();
            } catch (\Throwable $e) {
                Log::error([
                    'message' => '渠道码定时任务执行错误：' . $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]);
            }
        });
    }
}
