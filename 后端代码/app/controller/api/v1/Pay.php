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

namespace app\controller\api\v1;


use app\Request;
use mohe\services\AliPayService;
use mohe\services\wechat\HwcPayService;
use mohe\services\wechat\Payment;
use think\facade\Log;
use think\Response;

/**
 * 支付相关回调
 * Class Pay
 * @package app\controller\api\v1
 */
class Pay
{

    /**
     *   富友支付回调
     */
    public function fyNotify(string $channel, string $type, Request $request): Response
    {
        try {
            $success = HwcPayService::instance()->notify($request->param(), $channel, $type);
        } catch (\Throwable $exception) {
            Log::error('富友支付回调处理失败：' . $exception->getMessage());
            $success = false;
        }
        return Response::create($success ? '1' : 'fail', 'html', $success ? 200 : 500);
    }

    /**
     * 兼容修复前已生成的富友回调 URL。
     */
    public function fyNotifyLegacy(string $type, Request $request): Response
    {
        try {
            $success = HwcPayService::instance()->notifyLegacy($request->param(), $type);
        } catch (\Throwable $exception) {
            Log::error('富友支付旧版回调处理失败：' . $exception->getMessage());
            $success = false;
        }
        return Response::create($success ? '1' : 'fail', 'html', $success ? 200 : 500);
    }
    /**
     * 支付回调
     * @param string $type
     * @return string|\think\Response
     * @throws \EasyWeChat\Kernel\Exceptions\Exception
     */
    public function notify(string $type)
    {
        switch (urldecode($type)) {
            case 'alipay':
                return AliPayService::handleNotify();
                break;
            case 'routine':
                return Payment::instance()->setAccessEnd(Payment::MINI)->handleNotify();
                break;
            case 'wechat':
                return Payment::instance()->setAccessEnd(Payment::WEB)->handleNotify();
                break;
            case 'app':
                return Payment::instance()->setAccessEnd(Payment::APP)->handleNotify();
                break;
        }
    }

    /**
     * 退款回调
     * @param string $type
     * @return \think\Response
     * @throws \EasyWeChat\Kernel\Exceptions\Exception
     */
    public function refund(string $type)
    {
        switch (urldecode($type)) {
            case 'alipay':

                break;
            case 'routine':
                return Payment::instance()->setAccessEnd(Payment::MINI)->handleRefundedNotify();
                break;
            case 'wechat':
                return Payment::instance()->setAccessEnd(Payment::WEB)->handleRefundedNotify();
                break;
            case 'app':
                return Payment::instance()->setAccessEnd(Payment::APP)->handleRefundedNotify();
                break;
        }
    }

	/**
	 * 商户转账回调
	 * @param string $type
	 * @return void
	 */
	public function mchNotify(string $type)
	{
		switch (urldecode($type)) {
			case 'mini':
				return Payment::instance()->setAccessEnd(Payment::MINI)->handleMchNotify();
				break;
			case 'web':
				return Payment::instance()->setAccessEnd(Payment::WEB)->handleMchNotify();
				break;
			case 'app':
				return Payment::instance()->setAccessEnd(Payment::APP)->handleMchNotify();
				break;
		}
	}

}
