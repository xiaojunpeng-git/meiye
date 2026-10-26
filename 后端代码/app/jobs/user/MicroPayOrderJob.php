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

namespace app\jobs\user;


use app\jobs\system\SocketPushJob;
use app\services\order\OtherOrderServices;
use app\services\order\StoreCartServices;
use app\services\order\StoreOrderCreateServices;
use app\services\order\StoreOrderSuccessServices;
use app\services\pay\PayServices;
use app\services\user\UserRechargeServices;
use mohe\basic\BaseJobs;
use mohe\services\wechat\Payment;
use mohe\traits\QueueTrait;
use think\facade\Log;

/**
 * 付款码支付
 * Class MicroPayOrderJob
 * @package app\jobs\user
 */
class MicroPayOrderJob extends BaseJobs
{

    use QueueTrait;


    public function doJob(string $outTradeNo, int $type = 1, int $num = 1)
    {
        if ($type == 2) {//付费会员
            /** @var OtherOrderServices $OtherOrderServices */
            $make = app()->make(OtherOrderServices::class);
        } else if ($type == 1) {//充值
            /** @var UserRechargeServices $make */
            $make = app()->make(UserRechargeServices::class);
        } else {
            /** @var StoreOrderSuccessServices $make */
            $make = app()->make(StoreOrderSuccessServices::class);
        }
        $orderInfo = $make->get(['order_id' => $outTradeNo]);
        if (!$orderInfo) {
            return true;
        }
        if ($orderInfo->paid) {
            return true;
        }
        if (!$type && $orderInfo->is_del) {
            return true;
        }
        try {
            //查询订单支付状态
            $response = Payment::queryOrder($outTradeNo);
            if ($response['paid'] && ($response['payInfo']['trade_state'] ?? '') == 'SUCCESS') {
				$other = [
					'trade_no' => $response['payInfo']['transaction_id'] ?? ''
				];
                if ($type == 2) {
                    $orderInfo = $orderInfo->toArray();
                    $make->paySuccess($orderInfo, $orderInfo['pay_type'], $other);
                } else if ($type == 1) {
                    $make->rechargeSuccess($outTradeNo, $other);
                } else {
                    //删除购物车
                    /** @var StoreCartServices $cartServices */
                    $cartServices = app()->make(StoreCartServices::class);
                    $cartServices->deleteCartStatus($orderInfo['cart_id'] ?? []);
                    //修改支付状态
                    $make->paySuccess($orderInfo->toArray(), PayServices::WEIXIN_PAY, $other);
					//记录支付原始返回数据
					$make->update($orderInfo['id'], ['notify_data' => json_encode($response)]);
					//发送消息
                    if ($orderInfo->staff_id) {
						SocketPushJob::dispatch([$orderInfo['store_id'], 'changSuccess', [], 'cashier']);
                    }
                }
            } else {
                //15秒后还是状态异常直接取消订单
                if ($num >= 3) {
                    try {
                        if ($type) {
							$response = Payment::reverseOrder($outTradeNo);
                            $make->update(
                                [
                                    'order_id' => $outTradeNo
                                ],
                                [
                                    $type ? 'remarks' : 'remark' => '支付状态异常自动撤销订单，并从新生成订单号',
                                    'order_id' => $type == 1 ? $make->getUniqueId('cz') : ($type == 2 ? $make->getUniqueId('hy') : $make->getUniqueId()),
                                ]
                            );
                        }
                    } catch (\Throwable $e) {
                        Log::error([
                            'message' => '撤销订单失败，订单号:' . $outTradeNo . ';错误原因：' . $e->getMessage(),
                            'file' => $e->getFile(),
                            'line' => $e->getLine()
                        ]);
                    }
                    return true;
                }
                $secs = 5;
                if (isset($response['payInfo']['err_code']) && $response['payInfo']['err_code'] === 'USERPAYING') {
                    $secs = 10;
                }
                self::dispatchSece($secs, [$outTradeNo, $type, $num + 1]);
            }
        } catch (\Throwable $e) {
			Log::error([
				'message' => '收银台订单查询支付状态，订单号:' . $outTradeNo . ';错误原因：' . $e->getMessage(),
				'file' => $e->getFile(),
				'line' => $e->getLine()
			]);
        }
        return true;
    }
}
