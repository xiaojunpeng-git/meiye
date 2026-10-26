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

namespace app\jobs\notice;


use app\services\message\sms\SmsSendServices;
use mohe\basic\BaseJobs;
use mohe\traits\QueueTrait;
use think\facade\Log;

/**
 * 短信
 * Class SmsJob
 * @package app\jobs\notice
 */
class SmsJob extends BaseJobs
{
    use QueueTrait;

    /**
     * 发送短信
     * @param $switch
     * @param $adminList
     * @param $order
     * @return bool
     */
    public function doJob($phone, array $data, string $template)
    {

        try {
            /** @var SmsSendServices $smsServices */
            $smsServices = app()->make(SmsSendServices::class);
            $smsServices->send(true, $phone, $data, $template);
        } catch (\Throwable $e) {
            Log::error('发送短信消息失败,失败原因:' . $e->getMessage());
        }
		return true;
    }

}
