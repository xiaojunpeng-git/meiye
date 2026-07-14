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
use app\model\work\WorkGroupMsgSendResult;
use mohe\basic\BaseAuth;
use mohe\traits\SearchDaoTrait;

/**
 * Class WorkGroupMsgSendResultDao
 * @package app\dao\work
 */
class WorkGroupMsgSendResultDao extends BaseDao
{

    use SearchDaoTrait;

    /**
     * @return string
     */
    protected function setModel(): string
    {
        return WorkGroupMsgSendResult::class;
    }

    /**
     * @param array $where
     * @param bool $authWhere
     * @return \mohe\basic\BaseModel
     */
    public function searchWhere(array $where, bool $authWhere = true)
    {
        [$with, $whereKey] = app()->make(BaseAuth::class)->________(array_keys($where), $this->setModel());
        $whereData = [];
        foreach ($whereKey as $key) {
            if (isset($where[$key]) && 'timeKey' !== $key) {
                $whereData[$key] = $where[$key];
            }
        }

        return $this->getModel()->withSearch($with, $where)->when(!empty($where['client_name']), function ($query) use ($where) {
            $query->whereIn('external_userid', function ($query) use ($where) {
                $query->name('work_client')->where('name', 'like', '%' . $where['client_name'] . '%')->field(['external_userid']);
            });
        })->when(!empty($where['notChatId']), function ($query) {
            $query->whereNotNull('chat_id');
        });
    }

    /**
     * @param array $where
     * @return \mohe\basic\BaseModel
     */
    public function getGroupChatMsg(array $where)
    {
        return $this->getModel()->when(!empty($where['msg_id']), function ($query) use ($where) {
            $query->whereIn('msg_id', $where['msg_id']);
        })->when(!empty($where['status']), function ($query) use ($where) {
            $query->where('status', $where['status']);
        })->when(!empty($where['owner']), function ($query) use ($where) {
            $query->whereIn('userid', $where['owner']);
        })->group('userid')->field([
            "GROUP_CONCAT(chat_id) as chat_ids",
            "GROUP_CONCAT(status) as status_all", 'userid', 'status']);
    }
}
