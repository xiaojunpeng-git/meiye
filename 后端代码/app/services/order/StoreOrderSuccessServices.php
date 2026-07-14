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

namespace app\services\order;


use app\dao\order\StoreOrderDao;
use app\model\user\User;
use app\services\activity\lottery\LuckLotteryServices;
use app\services\BaseServices;
use app\services\pay\IntegralPayServices;
use app\services\pay\PayServices;
use app\services\product\inventory\ProductInventoryChangeServices;
use app\services\user\UserServices;
use mohe\traits\ServicesTrait;
use think\exception\ValidateException;
use think\facade\Db;

/**
 * Class StoreOrderSuccessServices
 * @package app\services\order
 * @mixin StoreOrderDao
 */
class StoreOrderSuccessServices extends BaseServices
{
    use ServicesTrait;

    /**
     *
     * StoreOrderSuccessServices constructor.
     * @param StoreOrderDao $dao
     */
    public function __construct(StoreOrderDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 0元支付
     * @param array $orderInfo
     * @param int $uid
     * @return bool
     * @throws \think\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function zeroYuanPayment(array $orderInfo, int $uid, string $payType = PayServices::YUE_PAY)
    {
		$id = $orderInfo['id'] ?? 0;
		if (!$orderInfo || !$id) {
			throw new ValidateException('订单不存在!');
		}
		//更新订单信息
		$orderInfo = $this->dao->get($id);
		if (!$orderInfo) {
			throw new ValidateException('订单不存在');
		}
		$orderInfo = $orderInfo->toArray();
		if ($orderInfo['paid']) {
            throw new ValidateException('该订单已支付!');
        }
        /** @var \app\services\product\inventory\ProductInventoryChangeServices $inventoryChange */
        $inventoryChange = app()->make(\app\services\product\inventory\ProductInventoryChangeServices::class);
        $inventoryChange->assertOrderCanPay($orderInfo);
        return $this->paySuccess($orderInfo, $payType);//余额支付成功
    }

    /**
     * 支付成功
     * @param array $orderInfo
     * @param string $paytype
     * @return bool
     */
    public function paySuccess(array $orderInfo, string $paytype = PayServices::WEIXIN_PAY, array $other = [])
    {
        $orderId = (int)($orderInfo['id'] ?? 0);
        if ($orderId <= 0) {
            throw new ValidateException('订单不存在');
        }

        Db::transaction(function () use (&$orderInfo, $orderId, $paytype, $other) {
            $locked = Db::name('store_order')->where('id', $orderId)->lock(true)->find();
            if (!$locked) {
                throw new ValidateException('订单不存在');
            }
            if ((int)$locked['paid'] === 1) {
                $orderInfo = $locked;
                return;
            }

            /** @var ProductInventoryChangeServices $inventoryChange */
            $inventoryChange = app()->make(ProductInventoryChangeServices::class);
            $cartInfo = $inventoryChange->loadOrderCartInfoForInventory($orderId);
            // 同事务：扣库存 + 加销量 + 写销售出库台账
            $inventoryChange->handlePaidOrderInventory($locked, $cartInfo);

            $updata = ['paid' => 1, 'pay_type' => $paytype, 'pay_time' => time()];
            if ($other && isset($other['trade_no'])) {
                $updata['trade_no'] = $other['trade_no'];
            }
            $updata['yue_money'] = User::where('uid', $locked['uid'])->value('now_money');
            $this->dao->update($orderId, $updata);

            $orderInfo = array_merge($locked, $updata);
            $orderInfo['trade_no'] = $other['trade_no'] ?? ($locked['trade_no'] ?? '');
        });

        //缓存抽奖次数 除过线下支付 抽奖中奖订单
		if (isset($orderInfo['pay_type']) && $orderInfo['pay_type'] != 'offline' && isset($orderInfo['type']) && $orderInfo['type'] != 8) {
            /** @var LuckLotteryServices $luckLotteryServices */
            $luckLotteryServices = app()->make(LuckLotteryServices::class);
            $luckLotteryServices->setCacheLotteryNum((int)$orderInfo['uid'], 'order');
        }
		$userInfo = app()->make(UserServices::class)->get($orderInfo['uid']);
		if (!empty($orderInfo['pay_integral']) && $userInfo) {//需要支付积分
			/** @var IntegralPayServices $integralPayServices */
			$integralPayServices = app()->make(IntegralPayServices::class);
			$integralPayServices->integralOrderPay((int)$userInfo['uid'], $orderInfo, $userInfo->toArray());
		}
        //订单支付成功事件
        event('order.pay', [$orderInfo['id'], $orderInfo]);
        return true;
    }

}
