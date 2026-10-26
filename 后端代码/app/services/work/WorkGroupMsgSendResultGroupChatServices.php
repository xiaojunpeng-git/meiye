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

namespace app\services\work;


use app\dao\work\WorkGroupMsgSendResultGroupChatDao;
use app\services\BaseServices;

/**
 * Class WorkGroupMsgSendResultGroupChatServices
 * @package app\services\work
 * @mixin WorkGroupMsgSendResultGroupChatDao
 */
class WorkGroupMsgSendResultGroupChatServices extends BaseServices
{

    /**
     * WorkGroupMsgSendResultGroupChatServices constructor.
     * @param WorkGroupMsgSendResultGroupChatDao $dao
     */
    public function __construct(WorkGroupMsgSendResultGroupChatDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * @param array $where
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getList(array $where)
    {
        [$page, $limit] = $this->getPageValue();

        $list = $this->dao->groupChat($where)->page($page, $limit)->select()->toArray();
        $count = $this->dao->groupChat($where)->count();
        return compact('list', 'count');
    }
}
