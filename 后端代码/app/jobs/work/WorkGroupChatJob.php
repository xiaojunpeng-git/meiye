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

namespace app\jobs\work;


use app\services\work\WorkGroupChatServices;
use mohe\basic\BaseJobs;
use mohe\traits\QueueTrait;

/**
 * 企业微信群
 * Class WorkGroupChatJob
 * @package app\jobs\work
 */
class WorkGroupChatJob extends BaseJobs
{

    use QueueTrait;

    public function authChat($corpId, $chatId)
    {
        /** @var WorkGroupChatServices $make */
        $make = app()->make(WorkGroupChatServices::class);
        return $make->saveWorkGroupChat($corpId, $chatId);
    }

    /**
     * @author 等风来
     * @email 136327134@qq.com
     * @date 2022/10/10
     * @param $nextCursor
     */
    public function authGroupChat($nextCursor)
    {
        /** @var WorkGroupChatServices $make */
        $make = app()->make(WorkGroupChatServices::class);
        return $make->authGroupChat($nextCursor);
    }

}
