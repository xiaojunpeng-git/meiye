<?php
// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2022 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------

namespace app\services\user;

use app\services\BaseServices;
use app\services\wechat\WechatUserServices;
use think\facade\Log;

class CancelUserServices extends BaseServices
{
    public function cancelUser(int $uid)
    {
        try {
            /** @var UserServices $userService */
            $userService = app()->make(UserServices::class);
            /** @var WechatUserServices $wechatUserServices */
            $wechatUserServices = app()->make(WechatUserServices::class);
            $userService->update(['spread_uid' => $uid], ['spread_uid' => 0]);// 清除用户与下级的关系
            $userService->update($uid, ['spread_uid' => 0, 'integral' => 0, 'now_money' => 0]);//清除
            $userService->update(['work_uid' => $uid], ['work_uid' => 0, 'work_userid' => '']);//清除企业微信上下级关系
            $userService->destroy($uid);// 软删除用户
            $wechatUserServices->update(['uid' => $uid], ['is_del' => 1]);// 删除微信用户
//            CancelUserJob::dispatch([$uid]);// 相关记录删除队列

        } catch (\Throwable $e) {
            Log::error('注销用户失败,失败原因:' . $e->getMessage());
        }

    }
}
