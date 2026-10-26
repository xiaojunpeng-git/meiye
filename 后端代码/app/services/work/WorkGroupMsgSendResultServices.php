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


use app\dao\work\WorkGroupMsgSendResultDao;
use app\jobs\work\WorkGroupMsgJob;
use app\services\BaseServices;
use mohe\services\wechat\Work;
use mohe\traits\ServicesTrait;
use think\facade\Log;

/**
 * 企业微信群发消息客户发送详情
 * Class WorkGroupMsgSendResultServices
 * @package app\services\work
 * @mixin WorkGroupMsgSendResultDao
 */
class WorkGroupMsgSendResultServices extends BaseServices
{

    use ServicesTrait;

    /**
     * WorkGroupMsgSendResultServices constructor.
     * @param WorkGroupMsgSendResultDao $dao
     */
    public function __construct(WorkGroupMsgSendResultDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * @param array $where
     * @return array
     */
    public function getClientList(array $where)
    {
        [$page, $limit] = $this->getPageValue();
        $list = $this->dao->getDataList($where, ['*'], $page, $limit, 'create_time', ['client']);
        $count = $this->dao->count($where);
        return compact('list', 'count');
    }


    /**
     * 获取群主发送详情
     * @param array $where
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getGroupChatMsgList(array $where)
    {
        [$page, $limit] = $this->getPageValue();
        $list = $this->dao->getGroupChatMsg($where)->whereNotNull('chat_id')->page($page, $limit)->select()->toArray();
        $count = $this->dao->getGroupChatMsg($where)->whereNotNull('chat_id')->count();
        return compact('list', 'count');
    }

    /**
     * 获取企业群发成员执行结果
     * @param int $type
     * @param string $userid
     * @param string $msgid
     * @param string|null $cursor
     * @return bool
     */
    public function getSendResult(int $type, string $userid, string $msgid, string $cursor = null)
    {
        try {
            $response = Work::getGroupmsgSendResult($msgid, $userid, 500, $cursor);

            $sendList = $response['send_list'] ?? [];
            foreach ($sendList as $item) {
                $where = ['msg_id' => $msgid, 'userid' => $userid];
                if ($type) {
                    $where['chat_id'] = $item['chat_id'];
                } else {
                    $where['external_userid'] = $item['external_userid'];
                }
                $info = $this->dao->get($where);
                if (!$info) {
                    $this->dao->save([
                        'msg_id' => $msgid,
                        'userid' => $item['userid'],
                        'chat_id' => $item['chat_id'] ?? '',
                        'status' => $item['status'],
                        'send_time' => $item['send_time'] ?? 0,
                        'external_userid' => $item['external_userid'] ?? '',
                    ]);
                } else {
                    $info->status = $item['status'];
                    $info->send_time = $item['send_time'] ?? 0;
                    $info->save();
                }
            }

            if ($response['next_cursor']) {
                WorkGroupMsgJob::dispatchDo('getSendResult', [$type, $userid, $msgid, $response['next_cursor']]);
            }

            return true;
        } catch (\Throwable $e) {
            Log::error([
                'message' => '获取企业群发成员执行结果失败:' . $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return false;
        }
    }
}
