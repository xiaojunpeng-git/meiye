<?php

namespace app\controller\api\v1\order;

use app\Request;
use app\services\order\StoreDebtServices;
use think\exception\ValidateException;

/**
 * 用户端欠款
 */
class StoreDebt
{
    /**
     * 会员端待还欠款列表。
     * 欠款表是金额与状态的权威来源；不依赖历史普通订单是否仍保留。
     */
    public function lst(Request $request, StoreDebtServices $services)
    {
        [$page, $limit] = $request->getMore([
            ['page', 1],
            ['limit', 20],
        ], true);
        $page = max(1, (int)$page);
        $limit = min(50, max(1, (int)$limit));
        return app('json')->successful($services->getUserReminderList((int)$request->uid(), $page, $limit));
    }

    /**
     * 欠款汇总
     */
    public function summary(Request $request, StoreDebtServices $services)
    {
        $uid = (int)$request->uid();
        return app('json')->successful($services->getUserPendingSummary($uid));
    }

    /**
     * 欠款收银台
     */
    public function cashier(Request $request, StoreDebtServices $services, $orderId)
    {
        if (!$orderId) {
            return app('json')->fail('参数错误');
        }
        try {
            $data = $services->getMobileCashierInfo((int)$request->uid(), (string)$orderId);
        } catch (ValidateException $e) {
            return app('json')->fail($e->getMessage());
        }
        return app('json')->successful($data);
    }

    /**
     * 欠款还款支付
     */
    public function repayPay(Request $request, StoreDebtServices $services)
    {
        [$orderId, $paytype, $from, $quitUrl, $repayAmount] = $request->postMore([
            ['order_id', ''],
            ['paytype', 'weixin'],
            ['from', 'routine'],
            ['quitUrl', ''],
            ['repay_amount', 0],
        ], true);
        if (!$orderId) {
            return app('json')->fail('参数错误');
        }
        try {
            $result = $services->repayPayMobile(
                (int)$request->uid(),
                (string)$orderId,
                (float)$repayAmount,
                (string)$paytype,
                (string)$from,
                (string)$quitUrl
            );
        } catch (ValidateException $e) {
            return app('json')->fail($e->getMessage());
        }
        $status = $result['status'] ?? 'SUCCESS';
        if ($status === 'SUCCESS') {
            return app('json')->status('success', $result['message'] ?? '还款成功', [
                'order_id' => $result['order_id'] ?? $orderId,
            ]);
        }
        if ($status === 'wechat_pay') {
            return app('json')->status('wechat_pay', '发起支付', [
                'jsConfig' => $result['jsConfig'] ?? [],
                'order_id' => $result['order_id'] ?? $orderId,
            ]);
        }
        if ($status === 'wechat_h5_pay') {
            return app('json')->status('wechat_h5_pay', '发起支付', [
                'jsConfig' => $result['jsConfig'] ?? [],
                'order_id' => $result['order_id'] ?? $orderId,
            ]);
        }
        if ($status === 'alipay_pay') {
            return app('json')->status('alipay_pay', '发起支付', [
                'jsConfig' => $result['jsConfig'] ?? [],
                'order_id' => $result['order_id'] ?? $orderId,
            ]);
        }
        return app('json')->successful($result['message'] ?? '', $result);
    }
}
