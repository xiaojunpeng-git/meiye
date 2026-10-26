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
use app\services\order\cashier\CashierAutoWriteoffServices;
use app\services\order\cashier\CashierChannelPayPendingException;
use app\services\order\cashier\CashierChannelPayPendingServices;
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

        // 微信/支付宝：渠道已真实扣款，支付落库后不得因核销失败回滚，否则会重复扣款
        $isChannelChargedPay = in_array($paytype, [PayServices::WEIXIN_PAY, PayServices::ALIAPY_PAY], true);

        /** @var ProductInventoryChangeServices $inventoryChange */
        $inventoryChange = app()->make(ProductInventoryChangeServices::class);

        // 口径 A：支付成功同事务扣库存+加销量（门店+平台）+销售出库；若已在外层事务则并入
        // 锁顺序：订单行 → 库存/出库 →（现金路径）自动核销/院装 → 同事务原子加销量（平台行不 FOR UPDATE，仅原子 INC）
        $applyPaid = function () use (&$orderInfo, $orderId, $paytype, $other, $isChannelChargedPay, $inventoryChange) {
            // 锁订单并颁发租约（唯一合法入口：本类私有方法内 FOR UPDATE）
            // 重开草稿关闭同事务：锁顺序 新订单行 → 草稿行
            [$locked, $lockLease] = $this->lockOrderAndIssueLease($orderId);
            try {
                if ((int)$locked['paid'] === 1) {
                    $orderInfo = $locked;
                    // 已支付重复回调：幂等对账关闭重开草稿
                    app()->make(\app\services\order\StoreOrderReopenServices::class)
                        ->bindOnPaySuccess($orderId);
                    return;
                }

                // 欠款补交：只落支付态；禁止再次库存/出库/销量/核销/院装（原单已完成销售）
                if (!empty($locked['is_debt_repay'])) {
                    $updata = [
                        'paid' => 1,
                        'pay_type' => $paytype,
                        'pay_time' => time(),
                        // 业务不适用但标已处理，避免重复回调再次进入库存/销量路径
                        'inventory_handled' => 1,
                        'sales_handled' => 1,
                    ];
                    if ($other && isset($other['trade_no'])) {
                        $updata['trade_no'] = $other['trade_no'];
                    }
                    $updata['yue_money'] = User::where('uid', $locked['uid'])->value('now_money');
                    $this->dao->update($orderId, $updata);
                    $orderInfo = array_merge($locked, $updata);
                    $orderInfo['trade_no'] = $other['trade_no'] ?? ($locked['trade_no'] ?? '');
                    if (env('APP_DEBUG') && getenv('REOPEN_INJECT_FAIL_AFTER_PAID') === '1') {
                        throw new \RuntimeException('inject_fail_after_paid_before_reopen_bind');
                    }
                    app()->make(\app\services\order\StoreOrderReopenServices::class)
                        ->bindOnPaySuccess($orderId);
                    return;
                }

                // 支付阶段：强制重新 load 购物车并构建 Context（禁止复用 assert 快照）
                $cartInfo = $inventoryChange->loadOrderCartInfoForInventory($orderId);
                // 先库存+出库（不加销量）；return_ops 供同事务销量复用（只读）
                \app\services\order\cashier\SmokeCheckoutTiming::mark('inventory_begin');
                $payOps = $inventoryChange->handlePaidOrderInventory($locked, $cartInfo, false, [
                    'return_ops' => true,
                ]);
                \app\services\order\cashier\SmokeCheckoutTiming::mark('inventory_end');

                $updata = ['paid' => 1, 'pay_type' => $paytype, 'pay_time' => time()];
                if ($other && isset($other['trade_no'])) {
                    $updata['trade_no'] = $other['trade_no'];
                }
                $updata['yue_money'] = User::where('uid', $locked['uid'])->value('now_money');
                $this->dao->update($orderId, $updata);

                $orderInfo = array_merge($locked, $updata);
                $orderInfo['trade_no'] = $other['trade_no'] ?? ($locked['trade_no'] ?? '');

                // 现金/余额/组合：自动核销失败可整单回滚（渠道未单独扣款）
                if (($orderInfo['channel_type'] ?? '') === 'cashier' && !$isChannelChargedPay) {
                    \app\services\order\cashier\SmokeCheckoutTiming::mark('writeoff_salon_begin');
                    app()->make(CashierAutoWriteoffServices::class)->cashierAutoWriteoffAfterPay($orderInfo);
                    \app\services\order\cashier\SmokeCheckoutTiming::mark('writeoff_salon_end');
                }

                // 同事务末尾加门店+平台销量；失败则整单回滚且不会写 sales_handled
                \app\services\order\cashier\SmokeCheckoutTiming::mark('sales_begin');
                $skuLocked = is_array($payOps) && !empty($payOps['lock_targets']);
                $inventoryChange->handlePaidOrderSales(
                    $orderInfo,
                    $cartInfo,
                    $lockLease,
                    is_array($payOps) ? $payOps : null,
                    $skuLocked
                );
                \app\services\order\cashier\SmokeCheckoutTiming::mark('sales_end');

                // 本地 DEBUG：paid 已写、草稿关闭前注入，验证同事务回滚
                if (env('APP_DEBUG') && getenv('REOPEN_INJECT_FAIL_AFTER_PAID') === '1') {
                    throw new \RuntimeException('inject_fail_after_paid_before_reopen_bind');
                }

                // 重开草稿关闭必须与 paid=1 同事务
                app()->make(\app\services\order\StoreOrderReopenServices::class)
                    ->bindOnPaySuccess($orderId);
            } finally {
                $lockLease->release();
            }
        };

        if ($this->isInDbTransaction()) {
            $applyPaid();
        } else {
            Db::transaction($applyPaid);
        }

        // 微信/支付宝：支付已提交后同步核销；失败保持已支付并落「待人工处理」
        // 补交单不走核销/院装
        if (($orderInfo['channel_type'] ?? '') === 'cashier' && $isChannelChargedPay && empty($orderInfo['is_debt_repay'])) {
            try {
                app()->make(CashierAutoWriteoffServices::class)->cashierAutoWriteoffAfterPay($orderInfo);
            } catch (\Throwable $e) {
                $tradeNo = (string)($other['trade_no'] ?? $orderInfo['trade_no'] ?? '');
                $writeoffReason = $e->getMessage();
                $pendingServices = app()->make(CashierChannelPayPendingServices::class);
                $cashierMsg = CashierChannelPayPendingServices::MSG_WAIT_PLATFORM;
                try {
                    $pendingServices->record($orderInfo, $paytype, $writeoffReason, $tradeNo);
                } catch (\Throwable $recordEx) {
                    // 禁止无记录地提示「等待平台处理」；写信号供 MicroPay 轮询识别
                    $cashierMsg = CashierChannelPayPendingServices::MSG_RECORD_FAIL;
                    $writeoffReason = trim($writeoffReason) . '；登记失败：' . $recordEx->getMessage();
                    $pendingServices->markRecordFailSignal((int)$orderInfo['id'], $recordEx->getMessage());
                }
                $this->queueOrRunAfterPaySideEffects($orderInfo);
                throw new CashierChannelPayPendingException(
                    $cashierMsg,
                    $orderInfo,
                    $writeoffReason
                );
            }
        }

        $this->queueOrRunAfterPaySideEffects($orderInfo);
        return true;
    }

    /**
     * 支付事务内：FOR UPDATE 锁订单行并颁发租约（唯一合法入口）。
     * 禁止其它服务直接调用 PaidOrderLockLease 做锁单。
     *
     * @return array{0: array, 1: \app\services\order\cashier\PaidOrderLockLease}
     */
    private function lockOrderAndIssueLease(int $orderId): array
    {
        if ($orderId <= 0) {
            throw new ValidateException('订单不存在');
        }
        if (!$this->isInDbTransaction()) {
            throw new ValidateException('PaidOrderLockLease 必须在数据库事务内颁发');
        }
        $locked = Db::name('store_order')->where('id', $orderId)->lock(true)->find();
        if (!$locked) {
            throw new ValidateException('订单不存在');
        }
        if ((int)($locked['id'] ?? 0) !== $orderId) {
            throw new ValidateException('订单锁快照不一致');
        }
        $lease = \app\services\order\cashier\PaidOrderLockLease::bindFromLockedOrder($this, $orderId, $locked);
        return [$locked, $lease];
    }

    protected function isInDbTransaction(): bool
    {
        try {
            $pdo = Db::getPdo();
            return $pdo && $pdo->inTransaction();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 已在支付事务内时延后 order.pay 等非库存副作用，避免拉长持锁。
     */
    protected function queueOrRunAfterPaySideEffects(array $orderInfo): void
    {
        if ($this->isInDbTransaction()) {
            \app\services\order\cashier\DeferredCashierPostWriteoff::pushPaySideEffects($orderInfo);
            return;
        }
        $this->runDeferredAfterPaySideEffects($orderInfo);
    }

    /**
     * 支付成功后的非库存副作用（抽奖次数、积分、订单支付事件）
     * 供事务提交后 flush 调用。
     */
    public function runDeferredAfterPaySideEffects(array $orderInfo): void
    {
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
    }

}
