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


use mohe\services\wechat\WechatResponse;
use mohe\services\wechat\Work;
use think\facade\Log;

/**
 * 发送企业微信应用消息
 * Class MessageSendListener
 * @package app\listener\work
 */
class MessageSendListener
{

    /**
     * @param $event
     * @return bool|WechatResponse
     */
    public function handle($event)
    {
        try {

            [$msgType, $text, $where, $params] = $event;

            $message = [
                'msgtype' => $msgType,
                'text' => [
                    'content' => $text
                ],
                'safe' => isset($params['safe']) ? $params['safe'] : 0,
                'enable_id_trans' => isset($params['enable_id_trans']) ? $params['enable_id_trans'] : 0,
                'enable_duplicate_check' => isset($params['enable_duplicate_check']) ? $params['enable_duplicate_check'] : 0,
                'duplicate_check_interval' => isset($params['duplicate_check_interval']) ? $params['safe'] : 1800,
            ];

            if (isset($where['toUser'])) {
                if (is_array($where['toUser'])) {
                    $where['toUser'] = join('|', $where['toUser']);
                }
                $message['touser'] = $where['toUser'];
            } elseif (isset($where['toParty'])) {
                if (is_array($where['toParty'])) {
                    $where['toParty'] = join('|', $where['toParty']);
                }
                $message['toparty'] = $where['toParty'];
            } elseif (isset($where['toTag'])) {
                if (is_array($where['toTag'])) {
                    $where['toTag'] = join('|', $where['toTag']);
                }
                $message['totag'] = $where['toTag'];
            }

            return Work::sendMessage($message);
        } catch (\Throwable $e) {
            Log::error([
                'message' => '发送应用消息失败:' . $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            return false;
        }
    }
}
