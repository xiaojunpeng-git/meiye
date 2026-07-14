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

namespace app\services\store\finance;


use app\dao\store\finance\StaffFlowingWaterDao;
use app\dao\store\StoreUserDao;
use app\services\BaseServices;
use app\services\order\StoreOrderServices;
use app\services\store\SystemStoreStaffServices;
use app\services\user\UserServices;

/**
 * 店员流水
 * Class StaffFlowingWaterServices
 * @package app\services\store\finance
 * @mixin StaffFlowingWaterDao
 */
class StaffFlowingWaterServices extends BaseServices
{
    /**
     * 支付类型
     * @var string[]
     */
    public $pay_type = ['weixin' => '微信支付', 'yue' => '余额支付', 'offline' => '线下支付', 'alipay' => '支付宝支付', 'cash' => '现金支付', 'automatic' => '自动转账', 'store' => '微信支付'];

    public $tradeType = [
        1 => '流水',
        2 => '退款'
    ];

    /**
     * 交易类型
     * @var string[]
     */
    public $type = [
        1 => '支付订单',
        2 => '储值订单',
        3 => '会员订单',
        4 => '退款订单',
        5 => '储值退款',
        6 => '核销业绩',
    ];

    /**
     * 构造方法
     * StoreUser constructor.
     * @param StoreUserDao $dao
     */
    public function __construct(StaffFlowingWaterDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 显示资源列表
     * @param array $where
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getList(array $where)
    {
        [$page, $limit] = $this->getPageValue();
        $list = $this->dao->getList($where, '*', $page, $limit, ['user', 'systemStoreStaff', 'systemStore' => function ($query) {
            $query->field('id,name')->bind(['store_name' => 'name']);
        }]);
        foreach ($list as &$item) {
            $item['type_name'] = isset($this->type[$item['type']]) ? $this->type[$item['type']] : '其他类型';
            $item['pay_type_name'] = isset($this->pay_type[$item['pay_type']]) ? $this->pay_type[$item['pay_type']] : '其他方式';
            $item['add_time'] = $item['add_time'] ? date('Y-m-d H:i:s', $item['add_time']) : '';
            $item['trade_time'] = $item['trade_time'] ? date('Y-m-d H:i:s', $item['trade_time']) : $item['add_time'];
            $item['user_nickname'] = $item['user_nickname'] ?: '游客';
        }
        $count = $this->dao->getCount($where);
        return compact('list', 'count');
    }


    /**
     * 获取百分比
     * @param $num
     * @return string|null
     */
    public function getPercent($num)
    {
        return bcdiv($num, '100', 6);
    }

    /**
     * 写入流水账单
     * @param $order
     * @param int $type
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function setFinance($order, $type = 1, $price = 0)
    {
        /** @var StoreOrderServices $storeOrderServices */
        $storeOrderServices = app()->make(StoreOrderServices::class);
        switch ($type) {
            case 1 ://商品订单
                $append = [
                    'pay_price' => $order['pay_price'],
                    'total_price' => $order['total_price'],
                ];
                $product_type = $order['product_type'] ?? $storeOrderServices->value(['id' => $order['id']], 'product_type');
                if (!in_array($product_type, [4, 5, 6])) {
                    //支付订单
                    $this->savaData($order, $order['pay_price'], 1, 1, $append);
                }
                break;
            case 2://储值订单
                //储值订单返点
                $order['pay_type'] = $order['recharge_type'];
                $append = [
                    'pay_price' => $order['price'],
                    'total_price' => $order['price'],
                ];
                //订单账单
                $this->savaData($order, $order['price'], 1, 2, $append);
                break;
            case 3://付费会员订单
                $append = [
                    'pay_price' => $order['pay_price'],
                    'total_price' => $order['pay_price'],
                ];
                //订单账单
                $this->savaData($order, $order['pay_price'], 1, 3, $append);
                break;
            case 4://退款
                $append = [
                    'pay_price' => $price,
                    'total_price' => $price,
                ];
                $this->savaData($order, $price, 0, 4, $append);
                break;
            case 5://储值退款
                $order['pay_type'] = $order['recharge_type'];
                $append = [
                    'pay_price' => $order['price'],
                    'total_price' => $order['price'],
                ];
                if (!$price) $price = $order['price'];
                $this->savaData($order, $price, 0, 5, $append);
                break;
            case 6://核销业绩
                $append = [
                    'pay_price' => $price,
                    'total_price' => $price,
                ];
                $this->savaData($order, $price, 1, 6, $append);
                break;
        }
    }

    /**
     * 写入数据
     * @param $order
     * @param $number
     * @param $pm
     * @param $type
     * @param $trade_type
     * @param array $append
     * @throws \Exception
     */
    public function savaData($order, $number, $pm, $type, array $append = [])
    {
        /** @var SystemStoreStaffServices $staffService */
        $staffService = app()->make(SystemStoreStaffServices::class);
        $order_id = $this->getUniqueId('ls');
        if ($order['staff_id']) {
            $staff_id = $order['staff_id'];
            $store_id = $staffService->value(['id' => $order['staff_id']], 'store_id') ?? 0;
        } else {
            /** @var UserServices $userService */
            $userService = app()->make(UserServices::class);
            $salesman_id = $userService->value(['uid' => $order['uid']], 'salesman_id');
            if (!$salesman_id) return true;
            $staffInfo = $staffService->get($salesman_id);
            if (!$staffInfo) return true;
            $staff_id = $staffInfo['id'];
            $store_id = $staffInfo['store_id'] ?? 0;
        }
        $data = [
            'store_id' => $store_id,
            'uid' => $order['uid'] ?? 0,
            'staff_id' => $staff_id,
            'order_id' => $order_id,
            'link_id' => $order['order_id'] ?? '',
            'pay_type' => $order['pay_type'] ?? '',
            'trade_time' => $order['pay_time'] ?? $order['add_time'] ?? '',
            'pm' => $pm,
            'number' => $number ?: 0,
            'type' => $type,
            'add_time' => time()
        ];
        $data = array_merge($data, $append);
        $this->dao->save($data);
    }

    /**
     * 获取店员财务记录
     * @param int $staff_id
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getStaffData(int $staff_id)
    {
        $list = $this->dao->getList(['staff_id' => $staff_id, 'pm' => 1, 'is_del' => 0]);
        $num = count($list);
        $values = array_column($list, 'number');
        $sum = sprintf("%.2f", array_sum($values));
        return ['num' => $num, 'sum' => $sum];
    }

    /**
     * 获取单个用户的业绩
     * @param int $uid
     * @return float
     */
    public function getUserData(int $uid)
    {
        return $this->dao->getOneUserData(['uid' => $uid, 'pm' => 1, 'is_del' => 0]);
    }

    /**
     * 关联门店店员
     * @param $link_id
     * @param int $staff_id
     * @return mixed
     */
    public function setStaff($link_id, int $staff_id)
    {
        return $this->dao->update(['link_id' => $link_id], ['staff_id' => $staff_id]);
    }

    /**
     * 获取店员业绩列表
     * @param array $where
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getgetStaffPerformanceData(array $where)
    {
        $where['pm'] = 1;
        $where['is_del'] = 0;
        [$page, $limit] = $this->getPageValue();
        $list = $this->dao->getList($where, '*', $page, $limit, ['user']);
        foreach ($list as &$item) {
            $item['add_time'] = date('Y-m-d H:i:s', $item['add_time']);
        }
        $count = $this->dao->count($where);
        return compact('list', 'count');
    }

    /**
     * 店员交易统计头部数据
     * @param $where
     * @return mixed
     */
    public function getStatisticsHeader($where)
    {
        $data = [];
        $data['legend'] = '服务业绩';
        $color = ['#2EC479', '#7F7AE5', '#FFA21B', '#46A3FF', '#FF6046', '#5cadff', '#b37feb', '#19be6b', '#ff9900'];
        $data['series'] = [];
        $list = $this->dao->getStatisticsHeader($where, 'staff_id', 'pay_price');
        if ($list) {
            $lists = [];
            $i = 0;
            foreach ($list as $item) {
                $data['series'][$i] = $item['total_number'] ?? 0;
                $data['xAxis'][$i] = $item['staff_name'] ?? '';
                if ($i < 5) {
                    $lists[$i] = $item;
                } else {
                    $lists[5]['staff_name'] = '其他';
                    $lists[5]['total_number'] = (float)bcadd((string)($lists[5]['total_number'] ?? 0), (string)$item['total_number'], 2);
                }
                $i++;
            }
            foreach ($lists as $key => &$item) {
                $data['bing_data'][$key]['itemStyle']['color'] = $color[$key];
                $data['bing_data'][$key]['name'] = $item['staff_name'] ?? '';
                $data['bing_data'][$key]['value'] = $item['total_number'] ?? 0;
                $data['bing_xdata'][$key] = $item['staff_name'] ?? '';
            }
            $data['yAxis']['maxnum'] = $data['series'] ? max($data['series']) : 0;
        } else {
            /** @var SystemStoreStaffServices $systemStoreStaffServices */
            $systemStoreStaffServices = app()->make(SystemStoreStaffServices::class);
            $storeList = $systemStoreStaffServices->getSelectList(['store_id' => $where['store_id'], 'is_del' => 0, 'status' => 1]);
            foreach ($storeList as $store) {
                $data['series'][] = 0;
                $data['xAxis'][] = $store['label'] ?? '';
            }
        }

        return $data;
    }

    /**
     * 获取一段时间订单统计数量、金额
     * @param $where
     * @param $time
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getTypeHeader($where, $time)
    {
        [$start, $end, $timeType, $xAxis] = $time;
        $order = $this->dao->orderAddTimeList($where, [$start, $end], $timeType, '*', 'pay_price');
        $price = array_column($order, 'price', 'day');
        $count = array_column($order, 'count', 'day');
        $datas = $series = [];
        foreach ($xAxis as $i => $key) {
            $datas['销售业绩金额'][] = isset($price[$key]) ? floatval($price[$key]) : 0;
            $datas['销售业绩单数'][] = isset($count[$key]) ? floatval($count[$key]) : 0;
            $arr = explode('-', $key);
            if (count($arr) >= 3) {
                $xAxis[$i] = $arr[1] . '-' . $arr[2];
            }
        }

        foreach ($datas as $key => $item) {
            $series[] = [
                'name' => $key,
                'data' => $item,
                'type' => 'line',
                'smooth' => 'true',
                'yAxisIndex' => 1,
            ];
        }
        $data['order']['xAxis'] = $xAxis;
        $data['order']['series'] = $series;

        $color = ['#2EC479', '#7F7AE5', '#FFA21B', '#46A3FF', '#FF6046', '#5cadff', '#b37feb', '#19be6b', '#ff9900'];
        $data['bing']['series'] = [];
        $list = $this->dao->getStatisticsHeader($where, 'type', 'pay_price');
        foreach ($list as $key => &$item) {
            $item['type_name'] = isset($this->type[$item['type']]) ? $this->type[$item['type']] : '其他类型';
            $data['bing']['bing_data'][$key]['itemStyle']['color'] = $color[$key];
            $data['bing']['bing_data'][$key]['name'] = $item['type_name'];
            $data['bing']['bing_data'][$key]['value'] = $item['total_number'];
            $data['bing']['bing_xdata'][$key] = $item['type_name'];
            $data['bing']['series'][$key] = $item['total_number'];
            $data['bing']['xAxis'][$key] = $item['type_name'];
        }
        $data['bing']['yAxis']['maxnum'] = $data['bing']['series'] ? max($data['bing']['series']) : 0;
        return $data;
    }
}
