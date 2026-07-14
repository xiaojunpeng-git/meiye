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
use mohe\utils\Hook;

/**
 * 支付回调
 * Class PayNotifyListener
 * @package app\listener\pay
 */
class PayNotifyListener
{
    /**
     * @param $event
     * @return bool
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function handle($event)
    {
        [$notify, $type] = $event;

        if (isset($notify['attach']) && $notify['attach']) {
			$outTradeNo = $notify['out_trade_no'];
			if ($notify['attach'] == 'product') {//购买商品
				//记录支付原始返回数据
				/** @var StoreOrderSuccessServices $orderService */
				$orderService = app()->make(StoreOrderSuccessServices::class);
				$orderService->update(['order_id' => $outTradeNo], ['notify_data' => json_encode($notify)]);
			}
            if (($count = strpos($notify['out_trade_no'], '_')) !== false) {
				if ($type == 'aliyun') {
					$notify['trade_no'] = $notify->out_trade_no;
				}
                $notify['out_trade_no'] = substr($notify['out_trade_no'], $count + 1);
            }
			$tradeNo = $type == 'wechat' ? $notify['transaction_id'] : $notify['trade_no'];
            return (new Hook(PayNotifyServices::class, $type))->listen($notify['attach'], $outTradeNo, $tradeNo);
        }

        return false;
    }

}
