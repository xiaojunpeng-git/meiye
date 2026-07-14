<?php

namespace app\jobs\order;

use mohe\basic\BaseJobs;
use mohe\services\wechat\MiniProgram;
use mohe\traits\QueueTrait;

/**
* 小程序订单处理
*/
class MiniOrderJob extends BaseJobs
{
    use QueueTrait;

    /**
	* @param string $out_trade_no
	* @param int $logistics_type
	* @param array $shipping_list
	* @param string $payer_openid
	* @param string $path
	* @param int $delivery_mode
	* @param bool $is_all_delivered
	* @return bool
	*/
    public function doJob(string $out_trade_no, int $logistics_type, array $shipping_list, string $payer_openid, string $path, int $delivery_mode = 1, bool $is_all_delivered = true)
    {
        try {
            MiniProgram::shippingByTradeNo($out_trade_no, $logistics_type, $shipping_list, $payer_openid, $path, $delivery_mode, $is_all_delivered);
        } catch (\Throwable $e) {
           \think\facade\Log::error('小程序订单处理失败，原因：' . $e->getMessage());
        }
		return true;
    }
}
