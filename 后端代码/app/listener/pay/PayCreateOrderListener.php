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


use app\services\order\StoreOrderSuccessServices;
use app\services\pay\PayNotifyServices;
use app\services\wechat\WechatMessageServices;
use mohe\utils\Hook;

/**
 * 向第三方支付下单事件
 * Class PayCreateOrderListener
 * @package app\listener\pay
 */
class PayCreateOrderListener
{
	/**
	 * @param $event
	 * @return void
	 */
    public function handle($event)
    {
        [$data, $outTradeNo, $type] = $event;
		try {
			$attach = $data['attach'] ?? $data['passback_params'] ?? '';
			//购买商品
			if ($outTradeNo && $attach == 'product') {
				//记录调起支付的原始信息数据
				/** @var StoreOrderSuccessServices $orderService */
				$orderService = app()->make(StoreOrderSuccessServices::class);
				$orderService->update(['order_id' => $outTradeNo], ['create_data' => json_encode($data)]);
			}
		} catch (\Throwable $e) {

		}
    }

}
