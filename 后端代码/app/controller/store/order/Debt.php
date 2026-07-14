<?php

namespace app\controller\store\order;

use app\controller\store\AuthController;
use app\services\order\StoreDebtServices;
use app\services\pay\PayServices;
use app\services\user\UserServices;
use mohe\services\AliPayService;
use mohe\services\wechat\Payment;
use think\exception\ValidateException;

/**
 * 门店欠款管理
 */
class Debt extends AuthController
{
    /**
     * 欠款列表
     */
    public function index()
    {
        $where = $this->request->getMore([
            ['status', ''],
            ['keyword', ''],
            ['time', ''],
        ]);
        if (!empty($where['time']) && is_array($where['time'])) {
            $where['add_time'] = $where['time'];
        }
        unset($where['time']);
        $where['store_id'] = (int)$this->storeId;
        [$page, $limit] = $this->request->getMore([
            ['page', 1],
            ['limit', 20],
        ], true);
        /** @var StoreDebtServices $services */
        $services = app()->make(StoreDebtServices::class);
        return $this->success($services->getAdminList($where, (int)$page, (int)$limit));
    }

    /**
     * 用户欠款记录（当前门店）
     */
    public function userList($uid)
    {
        $uid = (int)$uid;
        if (!$uid) {
            return $this->fail('缺少用户ID');
        }
        [$page, $limit] = $this->request->getMore([
            ['page', 1],
            ['limit', 20],
        ], true);
        /** @var StoreDebtServices $services */
        $services = app()->make(StoreDebtServices::class);
        return $this->success($services->getUserDebtList($uid, (int)$this->storeId, (int)$page, (int)$limit));
    }

    /**
     * 用户还款记录（当前门店）
     */
    public function repayList()
    {
        [$uid, $page, $limit] = $this->request->getMore([
            ['uid', 0],
            ['page', 1],
            ['limit', 20],
        ], true);
        $uid = (int)$uid;
        if (!$uid) {
            return $this->fail('缺少用户ID');
        }
        /** @var StoreDebtServices $services */
        $services = app()->make(StoreDebtServices::class);
        return $this->success($services->getUserRepayList($uid, (int)$this->storeId, (int)$page, (int)$limit));
    }

    /**
     * 订单欠款详情
     */
    public function orderDetail($orderId)
    {
        $orderId = (int)$orderId;
        if (!$orderId) {
            return $this->fail('参数错误');
        }
        /** @var StoreDebtServices $services */
        $services = app()->make(StoreDebtServices::class);
        $detail = $services->getDetailByOrderIdForStore($orderId, (int)$this->storeId);
        if (!$detail) {
            return $this->fail('该订单无欠款记录');
        }
        return $this->success($detail);
    }

    /**
     * 关闭欠款
     */
    public function close($id)
    {
        $id = (int)$id;
        if (!$id) {
            return $this->fail('参数错误');
        }
        /** @var StoreDebtServices $services */
        $services = app()->make(StoreDebtServices::class);
        try {
            $services->closeDebtForStore($id, (int)$this->storeId);
            return $this->success('操作成功');
        } catch (ValidateException $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * 订单待还欠款明细（核销补交）
     */
    public function orderItems($orderId)
    {
        $orderId = (int)$orderId;
        if (!$orderId) {
            return $this->fail('参数错误');
        }
        /** @var StoreDebtServices $services */
        $services = app()->make(StoreDebtServices::class);
        $detail = $services->getDetailByOrderIdForStore($orderId, (int)$this->storeId);
        if (!$detail) {
            return $this->fail('该订单无欠款记录');
        }
        return $this->success($services->getOrderRepayItems($orderId));
    }

    /**
     * 欠款补交支付
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
            $debtArr = is_array($debt) ? $debt : $debt->toArray();
            if ((int)($debtArr['store_id'] ?? 0) !== (int)$this->storeId) {
                return $this->fail('无权操作该欠款');
            }
            $uid = (int)$debtArr['uid'];
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
                'staff_id' => (int)$this->storeStaffId,
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
