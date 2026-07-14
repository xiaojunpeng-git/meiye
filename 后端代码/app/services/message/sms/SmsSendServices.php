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

namespace app\services\message\sms;

use app\services\BaseServices;
use app\services\message\SystemNotificationServices;
use app\services\serve\ServeServices;
use mohe\services\CacheService;
use think\exception\ValidateException;
use think\facade\Log;

/**
 * 短信发送
 * Class SmsSendServices
 * @package app\services\message\sms
 */
class SmsSendServices extends BaseServices
{
    private $smsType = ['yihaotong', 'aliyun'];

    /**
     * 发送短信
     * @param bool $switch
     * @param $phone
     * @param array $data
     * @param string $template
     * @return bool
     */
    public function send(bool $switch, $phone, array $data, string $template)
    {
        if ($switch && $phone) {
            /** @var ServeServices $services */
            $services = app()->make(ServeServices::class);
            //获取发送短信驱动类型
            $type = $this->smsType[sys_config('sms_type', 0)];
			//获取短信ID
			$templateId = CacheService::handler('TEMPLATE')->remember('NOTICE_SMS_' . $type . '_' . $template, function () use ($template) {
				/** @var SystemNotificationServices $notifyServices */
				$notifyServices = app()->make(SystemNotificationServices::class);
				return $notifyServices->value(['mark' => $template], 'sms_id') ?? 0;
			});

			try {
				$smsMake = $services->sms($type);
				$res = $smsMake->send($phone, $templateId, $data);
				if ($res === false) {
					throw new ValidateException($services->getError());
				}
			} catch (\Throwable $e) {
				Log::error('短信发送失败失败，原因：' . $e->getMessage());
			}
            return true;
        } else {
            return false;
        }
    }

}
