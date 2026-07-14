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

namespace app\listener\pay;


use app\services\pay\PayMchNotifyServices;
use app\services\wechat\WechatMessageServices;
use mohe\utils\Hook;
use think\facade\Log;

/**
 * 支付回调
 * Class PayNotifyListener
 * @package app\listener\pay
 */
class PayMchNotifyListener
{
    /**
     * @param $event
     * @return bool
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function handle($event)
    {
        [$notify] = $event;
        if (isset($notify['out_bill_no']) && $notify['out_bill_no']) {
            $attach = substr($notify['out_bill_no'], 0, 2);
            $fail_reason = $notify['fail_reason'] ?? '';
            return (new Hook(PayMchNotifyServices::class, 'wechat'))->listen($attach, $notify['out_bill_no'], $notify['transfer_bill_no'], $notify['state'], $fail_reason);
        }
        return true;
    }

}
