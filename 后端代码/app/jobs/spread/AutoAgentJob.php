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

use mohe\basic\BaseJobs;
use app\services\spread\AgentManageServices;
use mohe\traits\QueueTrait;
use think\facade\Log;

/**
 * 自动解除上下级
 * Class AutoAgentJob
 * @package app\jobs\user
 */
class AutoAgentJob extends BaseJobs
{
    use QueueTrait;

    /**
     * @return string
     */
    protected static function queueName()
    {
        return 'MOHE_PRO_TASK';
    }

    /**
     * @param $page
     * @param $limit
     * @param $where
     */
    public function doJob($page, $limit, $where)
    {
        //自动解绑上级绑定
        try {
            /** @var AgentManageServices $agentManage */
            $agentManage = app()->make(AgentManageServices::class);
            $agentManage->startRemoveSpread($page, $limit, $where);
        } catch (\Throwable $e) {
            Log::error('自动解除上级绑定失败,失败原因:[' . class_basename($this) . ']' . $e->getMessage());
        }
		return true;
    }


}
