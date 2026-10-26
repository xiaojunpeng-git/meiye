<?php

namespace app\controller\cashier;

use app\services\order\StoreDebtServices;
use app\services\pay\PayServices;
use app\services\user\UserServices;
use mohe\services\pay\extend\ali_pay\AliPayService;
use mohe\services\pay\extend\wechat\Payment;
use think\exception\ValidateException;

/**
 * 收银台欠款
 */
class Debt extends AuthController
{
    /**
     * 会员待还欠款汇总
     */
    public function summary()
    {
        $uid = (int)$this->request->get('uid', 0);
        if (!$uid) {
            return $this->fail('缺少用户ID');
        }
        /** @var StoreDebtServices $services */
        $services = app()->make(StoreDebtServices::class);
        return $this->success($services->getUserPendingSummary($uid));
    }

    /**
     * 欠款提醒列表（按明细）
     */
    public function reminder()
    {
        [$uid, $page, $limit] = $this->request->getMore([
            ['uid', 0],
            ['page', 1],
            ['limit', 5],
        ], true);
        $uid = (int)$uid;
        if (!$uid) {
            return $this->fail('缺少用户ID');
        }
        /** @var StoreDebtServices $services */
        $services = app()->make(StoreDebtServices::class);
        return $this->success($services->getUserReminderItemList($uid, (int)$page, (int)$limit));
    }

    /**
     * 核销补交：订单待还欠款明细
     */
    public function orderItems($orderId)
    {
        $orderId = (int)$orderId;
        if (!$orderId) {
            return $this->fail('参数错误');
        }
        /** @var StoreDebtServices $services */
        $services = app()->make(StoreDebtServices::class);
        return $this->success($services->getOrderRepayItems($orderId));
    }

    /**
     * 用户欠款/还款涉及门店（筛选用）
     */
    public function filterStores($uid)
    {
        $uid = (int)$uid;
        if (!$uid) {
            return $this->fail('缺少用户ID');
        }
        /** @var StoreDebtServices $services */
        $services = app()->make(StoreDebtServices::class);
        return $this->success($services->getFilterStoreList($uid));
    }

    /**
     * 用户欠款记录
     */
    public function userList($uid)
    {
        $uid = (int)$uid;
        if (!$uid) {
            return $this->fail('缺少用户ID');
        }
        [$storeId, $page, $limit] = $this->request->getMore([
            ['store_id', 0],
            ['page', 1],
            ['limit', 20],
        ], true);
        /** @var StoreDebtServices $services */
        $services = app()->make(StoreDebtServices::class);
        return $this->success($services->getUserDebtList($uid, (int)$storeId, (int)$page, (int)$limit));
    }

    /**
     * 还款记录
     */
    public function repayList()
    {
        [$uid, $storeId, $page, $limit] = $this->request->getMore([
            ['uid', 0],
            ['store_id', 0],
            ['page', 1],
            ['limit', 20],
        ], true);
        /** @var StoreDebtServices $services */
        $services = app()->make(StoreDebtServices::class);
        return $this->success($services->getUserRepayList((int)$uid, (int)$storeId, (int)$page, (int)$limit));
    }

    /**
     * 欠款还款支付
     */
    public function repayPay()
    {
        [$debtId, $debtItemId, $amount, $payType, $combinationInfo, $userCode, $authCode, $isBudan, $budanTime, $source, $cashChoose, $remarkInfo, $setYejiAll] = $this->request->postMore([
            ['debt_id', 0],
            ['debt_item_id', 0],
            ['repay_amount', 0],
            ['pay_type', ''],
            ['combination_info', []],
            ['user_code', ''],
            ['auth_code', ''],
            ['is_budan', 0],
            ['budan_time', ''],
            ['source', 0],
            ['cash_choose', 0],
            ['remark_info', []],
            ['setYejiAll', []],
        ], true);
        $debtId = (int)$debtId;
        $amount = (float)$amount;
        if (!$debtId || $amount <= 0) {
            return $this->fail('参数错误');
        }
        $uid = 0;
        /** @var StoreDebtServices $services */
        $services = app()->make(StoreDebtServices::class);
        $debt = $services->get($debtId);
        if ($debt) {
            $uid = (int)$debt['uid'];
        }
        if (!in_array($payType, ['yue', 'cash', 'combination']) && $authCode) {
            /** @var UserServices $userService */
            $userService = app()->make(UserServices::class);
            if (Payment::isWechatAuthCode((string)$authCode)) {
                $payType = PayServices::WEIXIN_PAY;
            } elseif (AliPayService::isAliPayAuthCode((string)$authCode)) {
                $payType = PayServices::ALIAPY_PAY;
            } elseif ($userService->isUserCode((int)$authCode, $uid)) {
                $payType = PayServices::YUE_PAY;
                $userCode = $authCode;
            } else {
                return $this->fail('未知付款二维码');
            }
        }
        if ($payType === 'combination') {
            $payType = PayServices::COMBINATION_PAY;
        } elseif ($payType === 'cash') {
            $payType = PayServices::CASH_PAY;
        } elseif ($payType === 'yue') {
            $payType = PayServices::YUE_PAY;
        }
        try {
            $res = $services->repayPay($debtId, $amount, $payType, [
                'debt_item_id' => (int)$debtItemId,
                'combination_info' => (array)$combinationInfo,
                'user_code' => (string)$userCode,
                'auth_code' => (string)$authCode,
                'pay_store_id' => (int)$this->storeId,
                'staff_id' => (int)$this->cashierId,
                'is_budan' => (int)$isBudan,
                'budan_time' => (string)$budanTime,
                'source' => (int)$source,
                'cash_choose' => (int)$cashChoose,
                'remark_info' => (array)$remarkInfo,
                'set_yeji_all' => (array)$setYejiAll,
            ]);
            return $this->success($res);
        } catch (ValidateException $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * 还款支付状态轮询
     */
    public function repayCheck()
    {
        $repayNo = (string)$this->request->post('repay_no', '');
        if (!$repayNo) {
            return $this->fail('缺少还款单号');
        }
        /** @var StoreDebtServices $services */
        $services = app()->make(StoreDebtServices::class);
        return $this->success($services->checkRepayPayStatus($repayNo));
    }
}
