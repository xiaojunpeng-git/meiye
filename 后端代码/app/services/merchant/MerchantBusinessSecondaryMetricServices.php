<?php
namespace app\services\merchant;

use app\model\order\StoreOrder;
use app\services\BaseServices;
use app\services\metric\MetricDictionaryServices;
use app\services\order\ValidCashOrderServices;
use think\facade\Db;

/**
 * 数仓经营次级金额指标（开卡充值金额 / 产品收入 / 退款金额）
 *
 * 开卡充值订单底与 MerchantCustomerMetricServices 开卡充值客户共用 buildCardRechargeOrderQuery。
 */
class MerchantBusinessSecondaryMetricServices extends BaseServices
{
    public const CODE_CARD_RECHARGE_AMOUNT = 'card_recharge_amount';
    public const CODE_PRODUCT_INCOME = 'product_income';
    public const CODE_REFUND_AMOUNT = 'refund_amount';

    /**
     * @param int[] $scopeStoreIds
     * @return array{metric_code:string,title:string,number:?float,developing:bool,note:string,detail_api:?string,detail_developing:bool,tooltip_api:string}
     */
    public function cardRechargeAmountMetric(array $scopeStoreIds, int $startTs, int $endTs): array
    {
        return $this->buildAmountMetric(
            self::CODE_CARD_RECHARGE_AMOUNT,
            '开卡充值金额',
            $scopeStoreIds,
            $startTs,
            $endTs,
            function () use ($scopeStoreIds, $startTs, $endTs) {
                return $this->sumCardRechargeAmount($scopeStoreIds, $startTs, $endTs);
            },
            '开卡充值金额统计暂时不可用'
        );
    }

    /**
     * 开卡充值金额唯一数值出口（订单底 = 开卡充值客户）
     *
     * @param int[] $scopeStoreIds
     */
    public function sumCardRechargeAmount(array $scopeStoreIds, int $startTs, int $endTs): float
    {
        $scopeStoreIds = array_values(array_unique(array_filter(array_map('intval', $scopeStoreIds))));
        if (!$scopeStoreIds || $startTs <= 0 || $endTs <= 0 || $endTs < $startTs) {
            return 0.0;
        }

        /** @var MerchantCustomerMetricServices $customerMetrics */
        $customerMetrics = app()->make(MerchantCustomerMetricServices::class);
        $query = $customerMetrics->buildCardRechargeOrderQuery($scopeStoreIds, $startTs, $endTs);
        ValidCashOrderServices::applyScope($query, '');
        ValidCashOrderServices::applyHasValidCash($query, '');
        $amountExpr = ValidCashOrderServices::buildAmountExpr('', 'cash_pay_price');
        $sum = $query->sum(Db::raw($amountExpr));
        return round((float)$sum, 2);
    }

    /**
     * @param int[] $scopeStoreIds
     */
    public function productIncomeMetric(array $scopeStoreIds, int $startTs, int $endTs): array
    {
        return $this->buildAmountMetric(
            self::CODE_PRODUCT_INCOME,
            '产品收入',
            $scopeStoreIds,
            $startTs,
            $endTs,
            function () use ($scopeStoreIds, $startTs, $endTs) {
                return $this->sumProductIncome($scopeStoreIds, $startTs, $endTs);
            },
            '产品收入统计暂时不可用'
        );
    }

    /**
     * 产品收入唯一数值出口：购物车行 product_type=0 的行级现金有效金额
     * 对齐 ReportServices::getStoerProductYeji(yejiType=1)：c.cash_pay_amount + applyScope + buildAmountExpr
     *
     * @param int[] $scopeStoreIds
     */
    public function sumProductIncome(array $scopeStoreIds, int $startTs, int $endTs): float
    {
        $scopeStoreIds = array_values(array_unique(array_filter(array_map('intval', $scopeStoreIds))));
        if (!$scopeStoreIds || $startTs <= 0 || $endTs <= 0 || $endTs < $startTs) {
            return 0.0;
        }

        $amountExpr = ValidCashOrderServices::buildAmountExpr('b', 'c.cash_pay_amount');
        $query = StoreOrder::alias('b')
            ->join('store_order_cart_info c', 'b.id = c.oid', 'left')
            ->whereIn('b.store_id', $scopeStoreIds)
            ->where('b.paid', 1)
            ->where('b.is_system_del', 0)
            ->where('b.refund_status', 0)
            ->where('b.order_type', 0)
            ->where('c.product_type', 0)
            ->where(function ($q) {
                $q->whereIn('b.pid', [0, -2])->whereOr('b.pid', '>', 0);
            })
            ->whereBetween('b.add_time', [$startTs, $endTs]);
        ValidCashOrderServices::applyScope($query, 'b');
        ValidCashOrderServices::applyHasValidCash($query, 'b');
        $sum = $query->sum(Db::raw($amountExpr));
        return round((float)$sum, 2);
    }

    /**
     * @param int[] $scopeStoreIds
     */
    public function refundAmountMetric(array $scopeStoreIds, int $startTs, int $endTs): array
    {
        return $this->buildAmountMetric(
            self::CODE_REFUND_AMOUNT,
            '退款金额',
            $scopeStoreIds,
            $startTs,
            $endTs,
            function () use ($scopeStoreIds, $startTs, $endTs) {
                return $this->sumRefundAmount($scopeStoreIds, $startTs, $endTs);
            },
            '退款金额统计暂时不可用'
        );
    }

    /**
     * 退款金额唯一数值出口：成功退款 refunded_price
     *
     * @param int[] $scopeStoreIds
     */
    public function sumRefundAmount(array $scopeStoreIds, int $startTs, int $endTs): float
    {
        $map = $this->mapRefundAmountByStores($scopeStoreIds, $startTs, $endTs);
        $total = 0.0;
        foreach ($map as $v) {
            $total = round($total + (float)$v, 2);
        }
        return $total;
    }

    /**
     * 退款金额按店 map（与 sumRefundAmount 同口径）。
     *
     * @param int[] $scopeStoreIds
     * @return array<int, float>
     */
    public function mapRefundAmountByStores(array $scopeStoreIds, int $startTs, int $endTs): array
    {
        $scopeStoreIds = array_values(array_unique(array_filter(array_map('intval', $scopeStoreIds))));
        $out = [];
        foreach ($scopeStoreIds as $sid) {
            if ($sid > 0) {
                $out[$sid] = 0.0;
            }
        }
        if (!$out || $startTs <= 0 || $endTs <= 0 || $endTs < $startTs) {
            return $out;
        }
        $rows = Db::name('store_order_refund')
            ->whereIn('store_id', array_keys($out))
            ->where('refund_type', 6)
            ->where('is_cancel', 0)
            ->where('is_del', 0)
            ->whereBetween('refunded_time', [$startTs, $endTs])
            ->field('store_id, SUM(refunded_price) AS total')
            ->group('store_id')
            ->select()
            ->toArray();
        foreach ($rows as $row) {
            $sid = (int)($row['store_id'] ?? 0);
            if (isset($out[$sid])) {
                $out[$sid] = round((float)($row['total'] ?? 0), 2);
            }
        }
        return $out;
    }

    /**
     * @param int[] $scopeStoreIds
     * @param callable():float $summer
     * @return array{metric_code:string,title:string,number:?float,developing:bool,note:string,detail_api:?string,detail_developing:bool,tooltip_api:string}
     */
    protected function buildAmountMetric(
        string $code,
        string $defaultTitle,
        array $scopeStoreIds,
        int $startTs,
        int $endTs,
        callable $summer,
        string $failNote
    ): array {
        /** @var MetricDictionaryServices $dict */
        $dict = app()->make(MetricDictionaryServices::class);
        $def = $dict->getByCode($code) ?: [];

        $out = [
            'metric_code' => $code,
            'title' => (string)($def['name'] ?? $defaultTitle),
            'number' => null,
            'developing' => true,
            'note' => '',
            'detail_api' => null,
            'detail_developing' => true,
            'tooltip_api' => 'metric/dictionary/' . $code,
        ];

        $scopeStoreIds = array_values(array_unique(array_filter(array_map('intval', $scopeStoreIds))));
        if (!$scopeStoreIds || $startTs <= 0 || $endTs <= 0 || $endTs < $startTs) {
            $out['note'] = '当前范围或时间无效，暂无统计';
            return $out;
        }

        try {
            $out['number'] = $summer();
            $out['developing'] = false;
            $out['note'] = '';
            return $out;
        } catch (\Throwable $e) {
            $out['note'] = $failNote;
            return $out;
        }
    }
}
