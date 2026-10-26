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

namespace app\jobs\spread;


use app\services\spread\AgentManageServices;
use mohe\basic\BaseJobs;
use mohe\traits\QueueTrait;

/**
 * 重置分销有效期
 * Class SystemJob
 * @package app\jobs
 */
class SystemJob extends BaseJobs
{

    use QueueTrait;


    public function resetSpreadTime()
    {
        /** @var AgentManageServices $agentManage */
        $agentManage = app()->make(AgentManageServices::class);
        $agentManage->resetSpreadTime();
		return true;
    }
}
