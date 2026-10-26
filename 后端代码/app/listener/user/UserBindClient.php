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


use app\services\work\WorkClientServices;
use mohe\interfaces\ListenerInterface;

/**
 * 用户绑定客户
 * Class UserBindClient
 * @package app\listener\user
 */
class UserBindClient implements ListenerInterface
{

    public function handle($event): void
    {
        [$uid, $unionid] = $event;

        try {
            /** @var WorkClientServices $make */
            $make = app()->make(WorkClientServices::class);
            $clientInfo = $make->get(['unionid' => $unionid], ['id', 'unionid', 'uid']);
            if ($clientInfo) {
                $clientInfo->uid = $uid;
                $clientInfo->save();
            }
        } catch (\Throwable $e) {
            \think\facade\Log::error([
                'error' => '用户绑定客户失败:' . $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        }
    }
}
