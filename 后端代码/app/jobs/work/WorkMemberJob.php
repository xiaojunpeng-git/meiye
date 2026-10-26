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


use app\services\work\WorkMemberServices;
use mohe\basic\BaseJobs;
use mohe\traits\QueueTrait;

/**
 * 企业微信成员
 * Class WorkMemberJob
 * @package app\jobs\work
 */
class WorkMemberJob extends BaseJobs
{

    use QueueTrait;

    /**
     * 执行部门同步
     * @param $id
     * @return bool
     */
    public function run($id)
    {
        /** @var WorkMemberServices $make */
        $make = app()->make(WorkMemberServices::class);
        $make->authUpdataMember((int)$id);
        return true;
    }

    /**
     * 保存数据
     * @param $member
     * @return bool
     */
    public function save($member)
    {
        /** @var WorkMemberServices $make */
        $make = app()->make(WorkMemberServices::class);
        return $make->saveMember($member);
    }
}
