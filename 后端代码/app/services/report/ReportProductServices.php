<?php

namespace app\services\report;

use app\dao\order\StoreOrderDao;
use app\model\order\CombinationOrder;
use app\model\order\StoreOrder;
use app\model\order\StoreOrderWriteoff;
use app\services\order\ValidCashOrderServices;
use app\services\pay\PayServices;
use think\facade\Db;
use app\services\BaseServices;

/**
 * Class OtherOrder
 * @package app\controller\admin\v1\order
 */
class ReportProductServices extends BaseServices
{
    /**
     * 现金业绩查询公共条件（排除旧卡录入，含组合支付明细）
     */
    protected function applyCashYejiScope($query): void
    {
        ValidCashOrderServices::applyScope($query, 'b');
    }

    //区间产品销售现金业绩
    public function rangeYeji($monthRange, $storeId, $productIds)
    {
        $payTypes = [
            PayServices::WEIXIN_PAY,
            PayServices::ALIAPY_PAY,
            PayServices::CASH_PAY,
            PayServices::OFFLINE_PAY,
            PayServices::COMBINATION_PAY,
        ];
        $amountExpr = ValidCashOrderServices::buildAmountExpr('b', 'c.pay_price');
        $yeji = StoreOrder::alias("b")
            ->join('store_order_cart_info c', "b.id=c.oid", 'left')
            ->whereIn("c.product_id", $productIds)
            ->where(function ($query) {
                $query->whereIn("b.pid", [0, -2])->whereOr("b.pid", ">", 0);
            })
            ->where("b.paid", 1)
            ->whereIn("b.pay_type", $payTypes)
            ->where("b.is_system_del", 0)
            ->whereIn("b.refund_status", 0)
            ->whereIn("b.order_type", [0, 1])
            ->when(true, function ($query) {
                $this->applyCashYejiScope($query);
            })
            ->when(!empty($storeId), function ($query) use ($storeId) {
                $query->where('b.store_id', $storeId);
            })->group("b.uid")
            ->whereBetween("b.add_time", [strtotime($monthRange[0]), strtotime($monthRange[1])])
            ->sum(Db::raw($amountExpr));
        return $yeji;
    }

    //销售数量
    public function rangeCartInfo($monthRange, $storeId, $productIds, $field)
    {
        $yeji = StoreOrder::alias("b")
            ->join('store_order_cart_info c', "b.id=c.oid", 'left')
            ->whereIn("c.product_id", $productIds)
            ->where(function ($query) {
                $query->whereIn("b.pid", [0, -2])->whereOr("b.pid", ">", 0);
            })
            ->where("b.paid", 1)
            ->where("b.is_system_del", 0)
            ->whereIn("b.refund_status", 0)
            ->whereIn("b.order_type", [0, 1])
            ->when(true, function ($query) {
                $this->applyCashYejiScope($query);
            })
            ->when(!empty($storeId), function ($query) use ($storeId) {
                $query->where('b.store_id', $storeId);
            })->group("b.uid")
            ->whereBetween("b.add_time", [strtotime($monthRange[0]), strtotime($monthRange[1])])
            ->sum($field);
        return $yeji;
    }

    //剩余业绩金额
    public function rangeCartInfoMoney($monthRange, $storeId, $productIds)
    {
        $innerExpr = "IF(c.write_times > 0, (c.pay_price / c.write_times) * c.write_surplus_times, 0)";
        $amountExpr = ValidCashOrderServices::buildAmountExpr('b', $innerExpr);
        $yeji = StoreOrder::alias("b")
            ->join('store_order_cart_info c', "b.id=c.oid", 'left')
            ->whereIn("c.product_id", $productIds)
            ->where(function ($query) {
                $query->whereIn("b.pid", [0, -2])->whereOr("b.pid", ">", 0);
            })
            ->where("b.paid", 1)
            ->where("b.is_system_del", 0)
            ->whereIn("b.refund_status", 0)
            ->whereIn("b.order_type", [0, 1])
            ->when(true, function ($query) {
                $this->applyCashYejiScope($query);
            })
            ->when(!empty($storeId), function ($query) use ($storeId) {
                $query->where('b.store_id', $storeId);
            })->group("b.uid")
            ->whereBetween("b.add_time", [strtotime($monthRange[0]), strtotime($monthRange[1])])
            ->sum(Db::raw($amountExpr));
        return $yeji;
    }

    /**
     * 门店销售数量合计（与 store_product_sales_report / 项目数 xiangmushu 口径一致）
     * 汇总 product_type 0/5/6 且 cart_type=0 的 cart_num
     * @param array $monthRange [开始, 结束] 日期时间字符串
     * @param int $storeId 门店ID，0 表示全部门店
     * @return int
     */
    public function sumStoreSalesQuantity(array $monthRange, int $storeId = 0): int
    {
        $start = strtotime($monthRange[0]);
        $end = strtotime($monthRange[1]);
        if (!$start || !$end) {
            return 0;
        }
        $query = StoreOrder::alias('b')
            ->join('store_order_cart_info c', 'c.oid = b.id AND IFNULL(c.cart_type, 0) = 0')
            ->join('store_product cp', 'cp.id = c.product_id')
            ->leftJoin('store_product parent', 'parent.id = cp.pid AND cp.pid > 0')
            ->where('b.paid', 1)
            ->where('b.is_system_del', 0)
            ->where('b.is_del', 0)
            ->whereIn('b.refund_status', [0, 3])
            ->whereRaw('IFNULL(b.is_auto, 0) <> 1')
            ->where(function ($query) {
                $query->whereIn('b.pid', [0, -2])->whereOr('b.pid', '>', 0);
            })
            ->where('b.order_type', 0)
            ->whereIn('cp.product_type', [0, 5, 6])
            ->whereRaw("IFNULL(NULLIF(parent.store_name, ''), cp.store_name) <> ''")
            ->when(true, function ($query) {
                $this->applyCashYejiScope($query);
            })
            ->when($storeId > 0, function ($query) use ($storeId) {
                $query->where('b.store_id', $storeId);
            })
            ->whereBetween('b.add_time', [$start, $end]);
        return (int)$query->sum('c.cart_num');
    }

    //销售人数
    public function rangeUserCount($monthRange, $storeId, $productIds)
    {
        $yeji = StoreOrder::alias("b")
            ->join('store_order_cart_info c', "b.id=c.oid", 'left')
            ->whereIn("c.product_id", $productIds)
            ->where(function ($query) {
                $query->whereIn("b.pid", [0, -2])->whereOr("b.pid", ">", 0);
            })
            ->where("b.paid", 1)
            ->where("b.is_system_del", 0)
            ->whereIn("b.refund_status", 0)
            ->whereIn("b.order_type", [0, 1])
            ->when(true, function ($query) {
                $this->applyCashYejiScope($query);
            })
            ->when(!empty($storeId), function ($query) use ($storeId) {
                $query->where('b.store_id', $storeId);
            })->group("b.uid")
            ->whereBetween("b.add_time", [strtotime($monthRange[0]), strtotime($monthRange[1])])
            ->count();
        return $yeji;
    }

    //消耗业绩
    public function rangeXiaohao($monthRange, $storeId, $productIds, $field)
    {
        $yeji = StoreOrderWriteoff::where("status", 0)
            ->when(!empty($storeId), function ($query) use ($storeId) {
                $query->where('relation_id', $storeId);
            })->whereIn("product_id", $productIds)
            ->whereBetween("add_time", [strtotime($monthRange[0]), strtotime($monthRange[1])])
            ->sum($field);
        return $yeji;
    }

    /**
     * 门店消耗项目数（核销 writeoff_num 合计，与消耗数量报表 SQL 口径一致）
     * @param array $monthRange [开始, 结束] 日期时间字符串
     * @param int $storeId 门店ID（relation_id），0 表示全部门店
     * @return int
     */
    public function sumStoreWriteoffNum(array $monthRange, int $storeId = 0): int
    {
        $start = strtotime($monthRange[0]);
        $end = strtotime($monthRange[1]);
        if (!$start || !$end) {
            return 0;
        }
        $query = StoreOrderWriteoff::where('status', 0)
            ->when($storeId > 0, function ($query) use ($storeId) {
                $query->where('relation_id', $storeId);
            })
            ->whereBetween('add_time', [$start, $end]);
        return (int)$query->sum('writeoff_num');
    }
}
