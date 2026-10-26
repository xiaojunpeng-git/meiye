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
namespace app\listener\user;

use mohe\utils\Cron;
use mohe\interfaces\ListenerInterface;
use app\services\spread\AgentManageServices;
use think\facade\Log;

/**
 * 定时自动解绑上下级
 * Class AutoClearIntegral
 * @package app\listener\user
 */
class AutoAgent extends Cron  implements ListenerInterface
{
    /**
     * @param $event
     */
    public function handle($event): void
    {
        //自动取消订单
        $this->tick(1000 * 60 * 10, function (){
            //自动解绑上级绑定
            try {
                /** @var AgentManageServices $agentManage */
                $agentManage = app()->make(AgentManageServices::class);
                $agentManage->removeSpread();
                return true;
            } catch (\Throwable $e) {
                Log::error('自动解除上级绑定失败,失败原因:[' . class_basename($this) . ']' . $e->getMessage());
            }
        });

    }
}
