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

namespace app\dao\work;


use app\dao\BaseDao;
use app\model\wechat\WechatUser;
use app\model\work\WorkGroupChat;
use app\model\work\WorkGroupMsgSendResult;

class WorkGroupMsgSendResultGroupChatDao extends BaseDao
{

    protected function setModel(): string
    {
        return WorkGroupMsgSendResult::class;
    }

    protected function setJoinModel(): string
    {
        return WorkGroupChat::class;
    }

    /**
     * @param string $alias
     * @param string $joinAlias
     * @param string $join
     * @return \mohe\basic\BaseModel
     */
    protected function getModel(string $alias = 'm', string $joinAlias = 'g', string $join = 'left')
    {
        /** @var WechatUser $wechcatUser */
        $wechcatUser = app()->make($this->setJoinModel());
        $table = $wechcatUser->getName();
        return parent::getModel()->alias($alias)->join($table . ' ' . $joinAlias, $alias . '.chat_id = ' . $joinAlias . '.chat_id', $join);
    }

    /**
     * @param array $where
     * @return \mohe\basic\BaseModel
     */
    public function groupChat(array $where)
    {
        return $this->getModel()->when(!empty($where['chat_id']), function ($query) use ($where) {
            $query->whereIn('m.chat_id', $where['chat_id']);
        })->when(isset($where['status']) && $where['status'] !== '', function ($query) use ($where) {
            $query->where('m.status', $where['status']);
        })->field(['m.*', 'g.name', 'g.member_num']);
    }

}
