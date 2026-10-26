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

use app\jobs\system\SocketPushJob;
use mohe\interfaces\ListenerInterface;
use app\services\system\admin\SystemAdminServices;

/**
 * 用户申请提现事件
 * Class Recharge
 * @package app\listener\user
 */
class Extract implements ListenerInterface
{
    /**
     * 用户申请提现事件
     * @param $event
     */
    public function handle($event): void
    {
        [$user, $data, $res] = $event;

		SocketPushJob::dispatch(['', 'WITHDRAW', ['id' => $res->id], 'admin']);

        /** @var SystemAdminServices $systemAdmin */
        $systemAdmin = app()->make(SystemAdminServices::class);
        $systemAdmin->adminNewPush();
        //提醒推送
        event('notice.notice', [['nickname' => $user['nickname'], 'money' => $data['money']], 'kefu_send_extract_application']);
    }
}
