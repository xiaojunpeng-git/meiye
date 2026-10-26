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

namespace app\dao\store;


use app\dao\BaseDao;
use app\model\store\StoreStaffShiftHandover;
use app\services\order\OtherOrderServices;
use app\services\order\StoreOrderServices;
use app\services\order\StoreOrderRefundServices;
use app\services\user\UserRechargeServices;

/**
 * 收银台交班
 * Class StoreStaffShiftHandoverDao
 * @package app\dao\store
 */
class StoreStaffShiftHandoverDao extends BaseDao
{
    /**
     * 设置模型
     * @return string
     */
    protected function setModel(): string
    {
        return StoreStaffShiftHandover::class;
    }

    public function search(array $where = [])
    {
        return parent::search($where)->when(isset($where['staff_id']) && $where['staff_id'], function ($query) use ($where) {
            $query->where('staff_id', $where['staff_id']);
        })->when(isset($where['shift_time']) && $where['shift_time'] != '', function ($query) use ($where) {
            [$startTime, $endTime] = explode('-', $where['shift_time']);
            $startTime = trim($startTime) ? strtotime($startTime) : 0;
            $endTime = trim($endTime) ? strtotime($endTime) : 0;
            if ($startTime && $endTime) {
                if ($startTime == $endTime || $endTime == strtotime(date('Y-m-d', $endTime))) {
                    $endTime = $endTime + 86400;
                }
            }
            $query->whereBetween('shift_start_time', [$startTime, $endTime]);
        });
    }

    /**
     * 获取用户列表
     * @param array $where
     * @param string $field
     * @param array $with
     * @param int $page
     * @param int $limit
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getList(array $where, string $field = '*', array $with = [], int $page = 0, int $limit = 0): array
    {
        return $this->search($where)->field($field)->when($with, function ($query) use ($with) {
            $query->with($with);
        })->when($page && $limit, function ($query) use ($page, $limit) {
            $query->page($page, $limit);
        })->order('id DESC')->select()->toArray();
    }

    /**
     * 交接班数据
     * @param int $staff_id
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getStaffShiftHandoverData(int $store_id = 0, int $staff_id = 0, int $shift_start_time = 0, int $shift_end_time = 0, $is_details = false)
    {
        /** @var StoreOrderServices $storeOrderServices */
        $storeOrderServices = app()->make(StoreOrderServices::class);
        /** @var OtherOrderServices $otherOrderServices */
        $otherOrderServices = app()->make(OtherOrderServices::class);
        /** @var UserRechargeServices $userRechargeServices */
        $userRechargeServices = app()->make(UserRechargeServices::class);
        if (!$shift_start_time) {
            return ['sum' => 0, 'sumPrice' => 0, 'order_price' => 0, 'other_price' => 0, 'recharge_price' => 0, 'refund_price' => 0, 'cash_price' => 0, 'details' => []];
        }
        $shift_start_time = date('Y/m/d H:i', $shift_start_time);
        $shift_end_time = date('Y/m/d H:i', $shift_end_time);
        $data = $shift_start_time . '-' . $shift_end_time;
        $where = ['store_id' => $store_id, 'staff_id' => $staff_id, 'time' => $data, 'paid' => 1, 'not_pid' => -1, 'channel_type' => 'cashier'];
        $storeOrder = $storeOrderServices->getList($where + ['shipping_type','in', [2, 4]], ['*']);
        $where1 = ['store_id' => $store_id, 'paid' => 1, 'type' => [0, 1, 2, 4], 'staff_id' => $staff_id];
        $otherOrder = $otherOrderServices->otherOrderList($where1, [$shift_start_time, $shift_end_time], 'add_time');
        $where2 = ['store_id' => $store_id, 'paid' => 1, 'staff_id' => $staff_id];
        $userRecharge = $userRechargeServices->rechargeList($where2, [$shift_start_time, $shift_end_time], 'add_time');
        $sum = count($storeOrder) + count($otherOrder) + count($userRecharge);
        $order_price = sprintf("%.2f",array_sum(array_column($storeOrder, 'pay_price')));
        $other_price = sprintf("%.2f",array_sum(array_column($otherOrder, 'pay_price')));
        $recharge_price = sprintf("%.2f",array_sum(array_column($userRecharge, 'price')));
        $refund_price_list = $storeOrderServices->getList($where + ['refund_status' => [1, 2]] + ['shipping_type','in', [2, 4]], ['*'],0,0,['refund']);
        $refund_price = 0;
        foreach ($refund_price_list as &$items) {
            $refunded_price = sprintf("%.2f",array_sum(array_column($items['refund'], 'refunded_price')));
            $items['refunded_price'] = $refunded_price;
            $refund_price += $refunded_price;
        }
        $refund_price = sprintf("%.2f",$refund_price);
        $sumPrice = sprintf("%.2f", $order_price + $other_price + $recharge_price - $refund_price);
        $cash_price = 0;
        $details = [];
        if ($is_details) {
            $order_price_w = $recharge_price_w = $other_price_w = $refund_price_w = $sum_price_w = 0;
            $order_price_a = $recharge_price_a = $other_price_a = $refund_price_a = $sum_price_a = 0;
            $order_price_y = $recharge_price_y = $other_price_y = $refund_price_y = $sum_price_y = 0;
            $order_price_c = $recharge_price_c = $other_price_c = $refund_price_c = $sum_price_c = 0;
            if (count($storeOrder)) {
                foreach ($storeOrder as $item) {
                    switch ($item['pay_type']) {
                        case 'weixin':
                            $order_price_w += $item['pay_price'];
                            break;
                        case 'alipay':
                            $order_price_a += $item['pay_price'];
                            break;
                        case 'yue':
                            $order_price_y += $item['pay_price'];
                            break;
                        case 'cash':
                            $order_price_c += $item['pay_price'];
                            break;
                    }
                }
            }
            if (count($userRecharge)) {
                foreach ($userRecharge as $item) {
                    switch ($item['recharge_type']) {
                        case 'weixin':
                        case 'weixinh5':
                        case 'store':
                        case 'pc':
                            $recharge_price_w += $item['price'];
                            break;
                        case 'alipay':
                            $recharge_price_a += $item['price'];
                            break;
                        case 'yue':
                            $recharge_price_y += $item['price'];
                            break;
                        case 'cash':
                            $recharge_price_c += $item['price'];
                            break;
                    }
                }
            }
            if (count($otherOrder)) {
                foreach ($otherOrder as $item) {
                    switch ($item['pay_type']) {
                        case 'weixin':
                            $other_price_w += $item['pay_price'];
                            break;
                        case 'alipay':
                            $other_price_a += $item['pay_price'];
                            break;
                        case 'yue':
                            $other_price_y += $item['pay_price'];
                            break;
                        case 'cash':
                            $other_price_c += $item['pay_price'];
                            break;
                    }
                }
            }
            if (count($refund_price_list)) {
                foreach ($refund_price_list as $item) {
                    switch ($item['pay_type']) {
                        case 'weixin':
                            $refund_price_w += $item['refunded_price'];
                            break;
                        case 'alipay':
                            $refund_price_a += $item['refunded_price'];
                            break;
                        case 'yue':
                            $refund_price_y += $item['refunded_price'];
                            break;
                        case 'cash':
                            $refund_price_c += $item['refunded_price'];
                            break;
                    }
                }
            }

            $sum_price_w = sprintf("%.2f", $order_price_w + $recharge_price_w + $other_price_w - $refund_price_w);
            $sum_price_a = sprintf("%.2f", $order_price_a + $recharge_price_a + $other_price_a - $refund_price_a);
            $sum_price_y = sprintf("%.2f", $order_price_y + $recharge_price_y + $other_price_y - $refund_price_y);
            $sum_price_c = sprintf("%.2f", $order_price_c + $recharge_price_c + $other_price_c - $refund_price_c);
            $cash_price = $sum_price_c;

            $details = [
                ['pay_type' => '微信支付', 'order_price' => sprintf("%.2f",$order_price_w), 'recharge_price' => sprintf("%.2f",$recharge_price_w), 'other_price' => sprintf("%.2f",$other_price_w), 'refund_price' => sprintf("%.2f",$refund_price_w), 'sum_price' => $sum_price_w],
                ['pay_type' => '支付宝支付', 'order_price' => sprintf("%.2f",$order_price_a), 'recharge_price' => sprintf("%.2f",$recharge_price_a), 'other_price' => sprintf("%.2f",$other_price_a), 'refund_price' => sprintf("%.2f",$refund_price_a), 'sum_price' => $sum_price_a],
                ['pay_type' => '余额支付', 'order_price' => sprintf("%.2f",$order_price_y), 'recharge_price' => sprintf("%.2f",$recharge_price_y), 'other_price' => sprintf("%.2f",$other_price_y), 'refund_price' => sprintf("%.2f",$refund_price_y), 'sum_price' => $sum_price_y],
                ['pay_type' => '现金支付', 'order_price' => sprintf("%.2f",$order_price_c), 'recharge_price' => sprintf("%.2f",$recharge_price_c), 'other_price' => sprintf("%.2f",$other_price_c), 'refund_price' => sprintf("%.2f",$refund_price_c), 'sum_price' => $sum_price_c]
            ];
        }
        return ['sum' => $sum, 'sumPrice' => $sumPrice, 'order_price' => $order_price, 'other_price' => $other_price, 'recharge_price' => $recharge_price, 'refund_price' => $refund_price, 'cash_price' => $cash_price, 'details' => $details];
    }
}
