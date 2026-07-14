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

namespace app\services\statistic;


use app\services\BaseServices;
use app\services\order\StoreOrderRefundServices;
use app\services\other\export\ExportServices;
use app\services\order\StoreCartServices;
use app\services\order\StoreOrderServices;
use app\services\product\product\StoreProductLogServices;
use app\services\product\product\StoreVisitServices;
use mohe\exceptions\AdminException;

/**
 * Class ProductStatisticServices
 * @package app\services\statistic
 */
class ProductStatisticServices extends BaseServices
{
    /**
     * 商品基础
     * @param $where
     * @return array
     */
    public function getBasic($where)
    {
        $time = explode('-', $where['time']);
        if (count($time) != 2) throw new AdminException('参数错误');
        //当前数据
        $now = $this->basicInfo($where, $time);

        //环比数据
        $dayNum = floor((strtotime($time[1]) - strtotime($time[0])) / 86400) + 1;
        $lastTime = array(
            date("Y/m/d", strtotime("-$dayNum days", strtotime($time[0]))),
            date("Y/m/d", strtotime("-1 days", strtotime($time[0])))
        );
        $where['time'] = implode('-', $lastTime);
        $last = $this->basicInfo($where, $lastTime);

        //组合数据，计算环比
        $data = [];
        foreach ($now as $key => $item) {
            $data[$key]['num'] = $item;
            $num = $last[$key] > 0 ? $last[$key] : 1;
            $data[$key]['percent'] = bcmul((string)bcdiv((string)($item - $last[$key]), (string)$num, 4), 100, 2);
        }
        return $data;
    }

    /**
     * 商品基础数据
     * @param $where
     * @param $time
     * @return mixed
     */
    public function basicInfo($where, $time)
    {
        /** @var StoreVisitServices $storeVisit */
        $storeVisit = app()->make(StoreVisitServices::class);
        /** @var StoreCartServices $storeCart */
        $storeCart = app()->make(StoreCartServices::class);
        /** @var StoreOrderServices $storeOrder */
        $storeOrder = app()->make(StoreOrderServices::class);

        $data['browse'] = $storeVisit->getSum($where, 'count');//商品浏览量
        $data['user'] = $storeVisit->getDistinctCount($where, 'uid');//商品访客数
        $data['cart'] = $storeCart->getSum($where, 'cart_num');//加入购物车件数
        $data['order'] = $storeOrder->sum($where + ['pid' => 0], 'total_num', true);//下单件数
        $startTime = strtotime($time[0]);
        $endTime = strtotime($time[1]) + 86400;
        $order_where = ['paid' => 1, 'pay_time' => [$startTime, $endTime]];
		$refund_where = ['is_cancel' => 0, ['refund_type' => [6]], 'time' => [$startTime, $endTime]];
		if (!isset($where['store_id']) || in_array($where['store_id'], [-1, 0])) {
			$order_where['pid'] = 0;
		} else {
			$refund_where['store_id'] = $order_where['store_id'] = $where['store_id'];
		}
        $data['pay'] = $storeOrder->sum($order_where, 'total_num', true);//支付件数
        $data['payPrice'] = $storeOrder->sum($order_where, 'pay_price', true);//支付金额
        $data['cost'] = $storeOrder->sum($order_where, 'cost', true);//成本金额
        /** @var StoreOrderRefundServices $storeOrderRefundServices */
        $storeOrderRefundServices = app()->make(StoreOrderRefundServices::class);
        $data['refundPrice'] = $storeOrderRefundServices->sum($refund_where, 'refunded_price', true);//退款金额
        $data['refund'] = $storeOrderRefundServices->sum($refund_where, 'refund_num', true);//退款件数

        $payPeople = $storeOrder->getDistinctCount($where + ['pid' => 0, 'paid' => 1], 'uid');//成交用户数
        $data['payPercent'] = $data['user'] > 0 ? bcmul(bcdiv($payPeople, $data['user'], 4), 100, 2) : 0;//访问-付款转化率
        return $data;
    }

    /**
     * 商品趋势
     * @param $where
     * @param $excel
     * @return array
     */
    public function getTrend(array $where, bool $excel = false)
    {
        $time = explode('-', $where['time']);
		unset($where['time']);
        if (count($time) != 2) throw new AdminException('参数错误');
        $dayCount = floor((strtotime($time[1]) - strtotime($time[0])) / 86400) + 1;
        if ($dayCount == 1) {
			$num = 0;
        } elseif ($dayCount > 1 && $dayCount <= 31) {
			$num = 1;
        } elseif ($dayCount > 31 && $dayCount <= 92) {
			$num = 3;
        } else {
			$num = 30;
		}
		return  $this->trend($where, $time, $num, $excel);
    }

	/**
	 * 商品趋势
	 * @param array $where
	 * @param array $time
	 * @param int $num
	 * @param bool $excel
	 * @return array|mixed
	 */
    public function trend(array $where, array $time, int $num, bool $excel = false)
    {
        /** @var StoreVisitServices $storeVisit */
        $storeVisit = app()->make(StoreVisitServices::class);
        /** @var StoreOrderServices $storeOrder */
        $storeOrder = app()->make(StoreOrderServices::class);
        /** @var StoreCartServices $storeCart */
        $storeCart = app()->make(StoreCartServices::class);
        /** @var StoreOrderRefundServices $storeOrderRefundServices */
        $storeOrderRefundServices = app()->make(StoreOrderRefundServices::class);

        if ($num == 0) {
            $xAxis = ['00', '01', '02', '03', '04', '05', '06', '07', '08', '09', '10', '11', '12', '13', '14', '15', '16', '17', '18', '19', '20', '21', '22', '23'];
            $timeType = '%H';
        } elseif ($num != 0) {
            $dt_start = strtotime($time[0]);
            $dt_end = strtotime($time[1]);
            while ($dt_start <= $dt_end) {
                if ($num == 30) {
                    $xAxis[] = date('Y-m', $dt_start);
                    $dt_start = strtotime("+1 month", $dt_start);
                    $timeType = '%Y-%m';
                } else {
                    $xAxis[] = date('Y-m-d', $dt_start);
                    $dt_start = strtotime("+$num day", $dt_start);
                    $timeType = '%Y-%m-%d';
                }
            }
        }
        $browse = array_column($storeVisit->getProductTrend($where, $time, $timeType, 'sum(count)'), 'num', 'days');
        $user = array_column($storeVisit->getProductTrend($where, $time, $timeType, 'count(distinct(uid))'), 'num', 'days');
        $pay = array_column($storeOrder->getProductTrend($where, $time, $timeType, 'pay_time', 'sum(pay_price)'), 'num', 'days');
        $refundList = $storeOrderRefundServices->getUserRefundPriceList($where, $time, $timeType, 'sum(refunded_price)', 'add_time');
        $refund = array_column($refundList, 'num', 'days');
        if ($excel) {
            $cart = array_column($storeCart->getProductTrend($where, $time, $timeType, 'sum(cart_num)'), 'num', 'days');
            $order = array_column($storeOrder->getProductTrend($where, $time, $timeType, 'add_time', 'sum(total_num)'), 'num', 'days');
            $payNum = array_column($storeOrder->getProductTrend($where, $time, $timeType, 'pay_time', 'sum(total_num)'), 'num', 'days');
            $payCountNum = array_column($storeOrder->getProductTrend($where, $time, $timeType, 'pay_time', 'count(distinct(uid))'), 'num', 'days');
            $cost = array_column($storeOrder->getProductTrend($where, $time, $timeType, 'pay_time', 'sum(cost)'), 'num', 'days');
            $orderIds = array_column($refundList, 'link_ids');
            $ids = implode(',', $orderIds);
            $totalNumList = $storeOrder->column(['refund_id' => $ids], 'total_num', 'id');
            $refundNum = array_column($refundList, 'link_ids', 'days');
            foreach ($refundNum as &$i) {
                $oIds = explode(',', $i);
                $i = array_map(function ($o) use ($totalNumList) {
                    if (isset($totalNumList[$o])) {
                        return $totalNumList[$o];
                    }
                }, $oIds);
                $i = array_sum($i);
            }
            foreach ($xAxis as &$item) {
                if (isset($user[$item]) && isset($payCountNum[$item])) {
                    $changes = bcmul(bcdiv((string)$payCountNum[$item], (string)$user[$item], 4), 100, 2);
                    $changes = $changes > 100 ? 100 : $changes;
                } else {
                    $changes = 0;
                }
                $data[] = [
                    'time' => $item,
                    'browse' => $browse[$item] ?? 0,
                    'user' => $user[$item] ?? 0,
                    'pay' => $pay[$item] ?? 0,
                    'refund' => $refund[$item] ?? 0,
                    'cart' => $cart[$item] ?? 0,
                    'order' => $order[$item] ?? 0,
                    'payNum' => $payNum[$item] ?? 0,
                    'cost' => $cost[$item] ?? 0,
                    'refundNum' => $refundNum[$item] ?? 0,
                    'changes' => $changes
                ];
            }
            /** @var ExportServices $exportService */
            $exportService = app()->make(ExportServices::class);
            return $exportService->productTrade($data);
        } else {
            $data = $series = [];
            foreach ($xAxis as $item) {
                $data['商品浏览量'][] = isset($browse[$item]) ? floatval($browse[$item]) : 0;
                $data['商品访客量'][] = isset($user[$item]) ? floatval($user[$item]) : 0;
                $data['支付金额'][] = isset($pay[$item]) ? floatval($pay[$item]) : 0;
                $data['退款金额'][] = isset($refund[$item]) ? floatval($refund[$item]) : 0;
            }
            foreach ($data as $key => $item) {
                if ($key == '商品浏览量' || $key == '商品访客量') {
                    $series[] = [
                        'name' => $key,
                        'data' => $item,
                        'type' => 'line',
                        'smooth' => 'true',
                        'yAxisIndex' => 1,
                    ];
                } else {
                    $series[] = [
                        'name' => $key,
                        'data' => $item,
                        'type' => 'bar',
                    ];
                }
            }
            return compact('xAxis', 'series');
        }

    }

    /**
     * 商品排行
     * @param $where
     * @return mixed
     */
    public function getProductRanking(array $where)
    {
        /** @var StoreProductLogServices $productLog */
        $productLog = app()->make(StoreProductLogServices::class);
        return $productLog->getRanking($where);
    }
}
