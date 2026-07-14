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

namespace app\services\order\store;


use app\dao\order\StoreOrderDao;
use app\model\order\CombinationOrder;
use app\model\order\StoreOrder;
use app\model\yeji\CashType;
use app\services\BaseServices;
use app\services\order\OtherOrderServices;
use app\services\order\StoreOrderRefundServices;
use app\services\order\StoreOrderServices;
use app\services\order\ValidCashOrderServices;
use app\services\pay\PayServices;
use app\services\report\ReportServices;
use app\services\store\StoreUserServices;
use app\services\user\UserCardServices;
use app\services\user\UserRechargeServices;
use app\services\store\finance\StoreFinanceFlowServices;
use think\exception\ValidateException;
use think\facade\Db;

/**
 * Class StoreOrderWapServices
 * @package app\services\order
 * @mixin StoreOrderDao
 */
class BranchOrderServices extends BaseServices
{
    /**
     * StoreOrderWapServices constructor.
     * @param StoreOrderDao $dao
     */
    public function __construct(StoreOrderDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 获取订单数量
     * @param int $store_id
     * @param int $staff_id
     * @return array
     */
    public function getOrderData(int $store_id, int $staff_id = 0)
    {
        $where = ['pid' => 0, 'store_id' => $store_id, 'refund_status' => [0, 3], 'is_del' => 0, 'is_system_del' => 0];
        $data['order_count'] = (string)$this->dao->count($where);
        $where = $where + ['paid' => 1];
        $data['sum_price'] = (string)$this->dao->sum($where, 'pay_price', true);

        $countWhere = ['store_id' => $store_id];
        if ($staff_id) {
            $countWhere['staff_id'] = $staff_id;
        }
        $pid_where = ['pid' => 0];
        $not_pid_where = ['not_pid' => 1];
        $data['unpaid_count'] = (string)$this->dao->count(['status' => 0] + $countWhere + $pid_where);
        $data['unshipped_count'] = (string)$this->dao->count(['status' => 1] + $countWhere + $pid_where);
        $data['unwriteoff_count'] = (string)$this->dao->count(['status' => 5] + $countWhere + $pid_where);
        $data['received_count'] = (string)$this->dao->count(['status' => 2] + $countWhere + $pid_where);
        $data['evaluated_count'] = (string)$this->dao->count(['status' => 3] + $countWhere + $pid_where);
        $data['complete_count'] = (string)$this->dao->count(['status' => 4] + $countWhere + $pid_where);
        /** @var StoreOrderRefundServices $storeOrderRefundServices */
        $storeOrderRefundServices = app()->make(StoreOrderRefundServices::class);
        $refund_where = ['store_id' => $store_id, 'is_cancel' => 0];
        $data['refunding_count'] = (string)$storeOrderRefundServices->count($refund_where + ['refund_type' => [0, 1, 2, 4, 5]]);
        $data['refunded_count'] = (string)$storeOrderRefundServices->count($refund_where + ['refund_type' => [3, 6]]);
        $data['refund_count'] = (string)$storeOrderRefundServices->count($refund_where);
        return $data;
    }

    /**
     * 订单统计详情列表
     * @param int $store_id
     * @param int $staff_id
     * @param int $type
     * @param array $time
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function time(int $store_id, int $staff_id = 0, int $type = 1, array $time = [], string $timeType = 'day')
    {
        if (!$time) {
            return [[], []];
        }
        $order_where = ['pid' => 0, 'paid' => 1, 'store_id' => $store_id];
        if ($type != 4) {
            $order_where['refund_status'] = [0, 3];
        }
        if ($staff_id) $order_where['staff_id'] = $staff_id;
        switch ($type) {
            case 1://所有订单：
                break;
            case 2://配送
            case 3://配送订单数量
                $order_where['type'] = 107;
                break;
            case 4://退款
                $order_where['status'] = -3;
                break;
            case 5://收银订单
                $order_where['type'] = 106;
                break;
            case 6://核销
                $order_where['type'] = 105;
                break;
        }
        return $this->dao->orderAddTimeList($order_where, $time, $timeType);
    }

    /**
     * 订单每月统计数据(按天分组)
     * @param array $where
     * @param array|string[] $field
     * @return array
     */
    public function getOrderDataPriceCount(array $where, array $field = ['sum(pay_price) as price', 'count(id) as count', 'FROM_UNIXTIME(delivery_time, \'%m-%d\') as time'])
    {
        [$page, $limit] = $this->getPageValue();
        $order_where = ['is_system_del' => 0, 'paid' => 1, 'refund_status' => [0, 3]];
        $where = array_merge($where, $order_where);
        return $this->dao->getOrderDataPriceList($where, $field, $page, $limit);
    }

    /**
     * 获取订单列表
     * @param array $where
     * @param array $with
     * @param false $is_count
     * @return array|null
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getStoreOrderList(array $where, array $field = ['*'], array $with = [], $is_count = false)
    {
        [$page, $limit] = $this->getPageValue();
        $list = $this->dao->getOrderList($where, $field, $page, $limit, $with, 'id desc');
        if ($is_count) {
            $count = $this->dao->count($where);
            return compact('list', 'count');
        }
        /** @var StoreOrderServices $orderServices */
        $orderServices = app()->make(StoreOrderServices::class);
        $list = $orderServices->tidyOrderList($list);
        foreach ($list as &$item) {
            $refund_num = array_sum(array_column($item['refund'], 'refund_num'));
            $cart_num = 0;
            foreach ($item['_info'] as $items) {
                if (isset($items['cart_info']['cart_type']) && $items['cart_info']['cart_type'] > 0) continue;
                $cart_num += $items['cart_info']['cart_num'];
            }
            if ($item['delivery_time']) {
                $item['delivery_time'] = date('m月d日 H:i:s', $item['delivery_time']);
            }
            $item['is_all_refund'] = $refund_num == $cart_num;
        }
        return $list;
    }

    /**
     * 收款报表按支付方式汇总（与 home/header store_income 口径一致）
     * cash_pay_price + 组合支付排除旧卡录入；不含 refund_status > 0
     */
    public function reportOrder(array $where)
    {
        return ValidCashOrderServices::sumStoreCashIncomeByCashChoose($where);
    }

    /**
     * 门店现金收入（cash_pay_price），排除旧卡录入；组合支付需扣除明细中 cash_choose=9 部分
     * @param array $where
     * @return string
     */
    public function sumStoreCashIncome(array $where): string
    {
        return ValidCashOrderServices::sumStoreCashIncome($where);
    }

    /**
     * 组合支付中计入现金的部分（排除旧卡录入、余额支付）
     * @deprecated 请使用 ValidCashOrderServices::sumCombinationCashByCashChoose
     */
    public function sumCombinationCashPay(array $where, $refundStatus = 0): string
    {
        return ValidCashOrderServices::sumCombinationCashByCashChoose($where);
    }

    /**
     * 组合支付明细中的旧卡录入金额（cash_type=9）
     * @param array $where
     * @return string
     */
    public function sumCombinationOldCardCash(array $where): string
    {
        return ValidCashOrderServices::sumCombinationOldCardCash($where);
    }

    //type 1现金业绩  2耗卡业绩
    public function oldYeji($where,$type){
          $filed="cash_money";
          if($type == 2){
               $filed="use_money";
          }
          $info=Db::name("old_shop_money")
              ->when(isset($where['store_id']) && !empty($where['store_id']),function ($query) use ($where){
                  if (is_array($where['store_id'])) {
                      $query->whereIn('store_id', $where['store_id']);
                  } else {
                      $query->where('store_id', $where['store_id']);
                  }
               })->when(isset($where['time']) && !empty($where['time']), function ($query) use ($where) {
                  $query->whereBetween("add_time",$where['time']);
              })
              ->sum($filed);
          return $info;
    }
    /**
     * 门店首页头部统计
     * @param array $where
     * @return array
     */
    public function homeStatics(array $where)
    {
        $data = [];
        $order_where = ['paid' => 1,'not_old'=>1,'pid' =>-3, 'is_system_del' => 0, 'refund_status' =>0,'link_type'=>[0,1]];
        $hand_where = ['paid' => 1, 'pid' =>-2, 'is_system_del' => 0, 'refund_status' => [0, 3],'link_type'=>2];
        //门店营收（排除旧卡录入，含组合支付明细中的旧卡录入）
        $data['store_income'] = $this->sumStoreCashIncome($where);
        $oldYeji=$this->oldYeji($where,1);
        $data['store_income']=bcadd($data['store_income'],$oldYeji,2);
        //消耗余额
        $data['store_use_yue'] = $this->dao->sum($order_where + $where, 'yue_pay_price', true);
        //收银订单
        $data['cashier_order_price'] = $this->dao->sum(['type' => 106, 'valid_cash_only' => 1] + $order_where + $where, 'pay_price', true);
        //分配订单
        $data['store_order_price'] = $this->dao->sum(['type' => 107, 'valid_cash_only' => 1] + $order_where + $where, 'pay_price', true);
        //核销订单
        //$data['store_writeoff_order_price']=$this->dao->sum($where + $hand_where, 'pay_price', true);--不扣合作类项目
        $reportServices=app()->make(ReportServices::class);
        $data['store_writeoff_order_price']=$reportServices->activeYeji($where); //扣除合作类项目
        $oldYeji=$this->oldYeji($where,2);
        $data['store_writeoff_order_price']=bcadd($data['store_writeoff_order_price'],$oldYeji,2);
//        if (isset($where['store_id']) && $where['store_id'] > 0 && !is_array($where['store_id'])) {
//            /** @var StoreFinanceFlowServices $flowServices */
//            $flowServices = app()->make(StoreFinanceFlowServices::class);
//            $store_writeoff_order_price = $this->dao->sum(['product_type', 'not in', [4, 5, 6]] + ['shipping_type' => 2] + $order_where + $where, 'pay_price', true);
//            $writeoff_price = $flowServices->writeoffFlowPrice($where['store_id'], $where['time']);
//            $data['store_writeoff_order_price'] = bcadd($store_writeoff_order_price, $writeoff_price, 2);
//        } else {
//            $data['store_writeoff_order_price'] = $this->dao->sum(['shipping_type' => 2] + $order_where + $where, 'pay_price', true);
//        }
        /** @var StoreUserServices $storeUserServices */
        $storeUserServices = app()->make(StoreUserServices::class);
        $data['store_user_count'] = $storeUserServices->count($where);
        //门店成交用户数（有效现金订单，排除旧卡录入）
        $payUserWhere = ['paid' => 1, 'valid_cash_only' => 1, 'pid' => -3, 'is_system_del' => 0, 'refund_status' => 0, 'link_type' => [0, 1]];
        $data['store_pay_user_count'] = count(array_unique($this->dao->getColumn($payUserWhere + $where, 'uid', '', true)));
        /** @var OtherOrderServices $vipOrderServices */
        $vipOrderServices = app()->make(OtherOrderServices::class);
        $data['vip_price'] = $vipOrderServices->sum(['paid' => 1, 'type' => [0, 1, 2, 4]] + $where, 'pay_price', true);
        /** @var UserRechargeServices $userRecharge */
        $userRecharge = app()->make(UserRechargeServices::class);
        $data['recharge_price'] = $userRecharge->sum(['paid' => 1] + $where, 'price', true);
        /** @var UserCardServices $userCard */
        $userCard = app()->make(UserCardServices::class);
        $data['card_count'] = $userCard->count($where + ['is_submit' => 1]);
        return $data;
    }

    /**
     * 门店首页运营统计
     * @param array $where
     * @param array $time
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function operateChart(array $where, array $time)
    {
        [$start, $end, $timeType, $timeKey] = $time;
        $order = $this->dao->orderAddTimeList($where, [$start, $end], $timeType);
        /** @var StoreUserServices $storeUserServices */
        $storeUserServices = app()->make(StoreUserServices::class);
        $storeUser = $storeUserServices->userTimeList($where, [$start, $end], $timeType);

        $order = array_column($order, 'price', 'day');
        $storeUser = array_column($storeUser, 'count', 'day');

        $data = $series = [];
        $xAxis = [];
        foreach ($timeKey as $key) {
            $data['门店收款'][] = isset($order[$key]) ? floatval($order[$key]) : 0;
            $data['新增用户数'][] = isset($storeUser[$key]) ? floatval($storeUser[$key]) : 0;
            $xAxis[] = in_array($timeType, ['hour', 'weekly']) ? $key : date('m-d', strtotime($key));
        }
        foreach ($data as $key => $item) {
            $series[] = [
                'name' => $key,
                'data' => $item,
                'type' => 'line',
                'smooth' => 'true',
                'yAxisIndex' => 1,
            ];
        }
        return compact('xAxis', 'series');

    }

    /**
     * 首页交易统计
     * @param array $where
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function orderChart(array $where = [])
    {
        $chartdata = [];
        if (isset($where['store_id']) && in_array($where['store_id'], [-1, 0])) {
            $where['pid'] = 0;
        }

        $order_where = ['paid' => 1, 'pid' => 0, 'is_system_del' => 0, 'refund_status' => [0, 3]];

        $list = $this->dao->getOrderList($where + $order_where, ['id', 'order_id', 'uid', 'pay_price', 'pay_time'], 0, 10);
        $chartdata['order_list'] = $list;

        $chartdata['bing_xdata'] = ['收银订单', '储值订单', '分配订单', '核销订单', '付费会员订单'];
        $color = ['#2EC479', '#7F7AE5', '#FFA21B', '#46A3FF', '#FF6046'];
        //收银订单
        $pay[] = $this->dao->sum(['type' => 106] + $order_where + $where, 'pay_price', true);
        /** @var UserRechargeServices $userRecharge */
        $userRecharge = app()->make(UserRechargeServices::class);
        $pay[] = $userRecharge->sum(['paid' => 1] + $where, 'price', true);
        //分配订单
        $pay[] = $this->dao->sum(['type' => 107] + $order_where + $where, 'pay_price', true);
        //核销订单
        $pay[] = $this->dao->sum(['type' => 105] + $order_where + $where, 'pay_price', true);

        /** @var OtherOrderServices $vipOrderServices */
        $vipOrderServices = app()->make(OtherOrderServices::class);
        $pay[] = $vipOrderServices->sum(['paid' => 1, 'type' => [0, 1, 2, 4]] + $where, 'pay_price', true);
        foreach ($pay as $key => $item) {
            $bing_data[] = ['name' => $chartdata['bing_xdata'][$key], 'value' => $pay[$key], 'itemStyle' => ['color' => $color[$key]]];
        }
        $chartdata['bing_data'] = $bing_data;
        return $chartdata;
    }

}
