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


use app\dao\store\finance\StoreFinanceFlowDao;
use app\dao\store\StoreUserDao;
use app\services\BaseServices;
use app\services\order\StoreOrderCartInfoServices;
use app\services\order\StoreOrderServices;
use app\services\pay\PayServices;
use app\services\store\SystemStoreServices;
use app\services\store\SystemStoreStaffServices;
use think\exception\ValidateException;

/**
 * 门店流水
 * Class StoreExtractServices
 * @package app\services\store\finance
 * @mixin StoreFinanceFlowDao
 */
class StoreFinanceFlowServices extends BaseServices
{
    /**
     * 支付类型
     * @var string[]
     */
    public $pay_type = ['weixin' => '微信支付', 'yue' => '余额支付', 'offline' => '线下支付', 'alipay' => '支付宝支付', 'cash' => '现金支付', 'automatic' => '自动转账', 'store' => '微信支付'];

    public $tradeType = [
        1 => '流水',
        2 => '交易'
    ];

    /**
     * 交易类型
     * @var string[]
     */
    public $type = [
        1 => '支付订单',
        2 => '支付订单',
        3 => '订单手续费',
        4 => '退款订单',
        5 => '储值返点',
        6 => '付费会员返点',
        7 => '储值订单',
        8 => '付费订单',
        9 => '收银订单',
        10 => '核销订单',
        11 => '分配订单',
        12 => '配送订单',
        13 => '同城配送订单',
        14 => '核销流水',
        15 => '卡项、次卡、预约退款流水', //卡项、次卡、预约购买时不给门店流水，增加展示记录使用
    ];

    /**
     * 构造方法
     * StoreUser constructor.
     * @param StoreUserDao $dao
     */
    public function __construct(StoreFinanceFlowDao $dao)
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
        if (isset($where['timeType'])) {
			if (!isset($where['day']) || !$where['day']) {
				throw new ValidateException('参数错误');
			}
            $start_time = '';
            $end_time = '';
            switch ($where['timeType']) {
                case 'day':
                    $time = strtotime($where['day']);
                    $start_time = date("Y/m/d 00:00", $time);
                    $end_time = date("Y/m/d 23:59", $time);
                    break;
                case 'week':
                    [$year, $week] = explode('-', $where['day']);
                    $startOfWeek = strtotime("{$year}-W{$week}-1");
                    $endOfWeek = strtotime("+6 days", $startOfWeek);
                    $start_time = date('Y/m/d H:i', $startOfWeek);
                    $end_time = date('Y/m/d H:i', $endOfWeek);
                    break;
                case 'month':
                    [$year, $month] = explode('-', $where['day']);
                    $startOfMonth = strtotime("{$year}-{$month}-01 00:00:00");
                    $endOfMonth = strtotime("{$year}-{$month}-01 +1 month -1 day 23:59:59");
                    $start_time = date('Y/m/d H:i', $startOfMonth);
                    $end_time = date('Y/m/d H:i', $endOfMonth);
                    break;
            }
            $where['timeType'] = 'add_time';
            $where['time'] = $start_time . '-' . $end_time;
        }
        $list = $this->dao->getList($where, '*', $page, $limit, ['user', 'systemStoreStaff', 'systemStore' => function ($query) {
            $query->field('id,name')->bind(['store_name' => 'name']);
        }]);
        if ($list) {
            $linkIds = array_column($list, 'link_id');
            $orderInfo = [];
            if ($linkIds) {
                /** @var StoreOrderServices $orderServices */
                $orderServices = app()->make(StoreOrderServices::class);
                $orderInfo = $orderServices->getColumn([['order_id', 'in', $linkIds]], 'id,order_id', 'order_id');
            }
            /** @var StoreOrderCartInfoServices $orderCart */
            $orderCart = app()->make(StoreOrderCartInfoServices::class);
            foreach ($list as &$item) {
                $oid = $orderInfo[$item['link_id']]['id'] ?? 0;
                $_info = [];
                if ($oid) {
                    $_info = $orderCart->getCartColunm(['oid' => $oid], 'cart_info', 'unique');
                    foreach ($_info as $k => $v) {
                        $cart_info = is_string($v) ? json_decode($v, true) : $v;
                        if (!isset($cart_info['productInfo'])) $cart_info['productInfo'] = [];
                        $_info[$k] = $cart_info;
                        unset($cart_info);
                    }
                }
                $item['_info'] = $_info;
                $item['type_name'] = $this->type[$item['type']] ?? '其他类型';
                $item['pay_type_name'] = $this->pay_type[$item['pay_type']] ?? '其他方式';
                $item['add_time'] = $item['add_time'] ? date('Y-m-d H:i:s', $item['add_time']) : '';
                $item['take_time'] = $item['take_time'] ? date('Y-m-d H:i:s', $item['take_time']) : '';
                $item['trade_time'] = $item['trade_time'] ? date('Y-m-d H:i:s', $item['trade_time']) : $item['add_time'];
                $item['user_nickname'] = $item['user_nickname'] ?: '游客';
            }
        }

        $count = $this->dao->getCount($where);
        return compact('list', 'count');
    }

    /**
     * 门店账单
     * @param $where
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getFundRecord($where)
    {
        [$page, $limit] = $this->getPageValue();
        $where['is_del'] = 0;
        $data = $this->dao->getFundRecord($where, $page, $limit);
        $i = 1;
        foreach ($data['list'] as &$item) {
            $item['id'] = $i;
            $i++;
            $item['entry_num'] = bcsub($item['income_num'], $item['exp_num'], 2);
            switch ($where['timeType']) {
                case "day" :
                    $item['title'] = "日账单";
                    $item['add_time'] = date('Y-m-d', $item['add_time']);
                    break;
                case "week" :
                    $item['title'] = "周账单";
                    $item['add_time'] = '第' . $item['day'] . '周(' . date('m', $item['add_time']) . '月)';
                    break;
                case "month" :
                    $item['title'] = "月账单";
                    $item['add_time'] = date('Y-m', $item['add_time']);
                    break;
            }
            $item['take_time'] = $item['take_time'] ? date('Y-m-d H:i:s', $item['take_time']) : '';
        }
        return $data;
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
	 * 获取费率
	 * @param int $order_type
	 * @param int $store_id
	 * @param int $shipping_type
	 * @return array
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function getRate(int $order_type = 1, int $store_id = 0, int $shipping_type = 1)
    {
        $type = $rate = 0;
		/** @var SystemStoreServices $storeServices */
		$storeServices = app()->make(SystemStoreServices::class);
		$data = $storeServices->getStoreFinanceConfig($store_id);
        switch ($order_type) {
            case 1://商品订单
                if ($shipping_type == 1) {//配送订单
                    //分配订单费率
                    $rate = $data['store_self_order_rate'] ?? sys_config('store_self_order_rate');
                    $type = 12;
                } elseif ($shipping_type == 2) {
                    //核销订单费率
                    $rate = $data['store_writeoff_order_rate'] ?? sys_config('store_writeoff_order_rate');
                    $type = 10;
                } else if ($shipping_type == 4) {
                    //收银订单费率
                    $rate = $data['store_cashier_order_rate'] ?? sys_config('store_cashier_order_rate');
                    $type = 9;
                } else {
                    //分配订单费率
                    $rate = $data['store_self_order_rate'] ?? sys_config('store_self_order_rate');
                    $type = 11;
                }
                break;
            case 2://储值订单
                $rate = $data['store_recharge_order_rate'] ?? sys_config('store_recharge_order_rate');
                $type = 7;
                break;
            case 3://付费会员订单
                //购买付费会员返点
                $rate = $data['store_svip_order_rate'] ?? sys_config('store_svip_order_rate');
                $type = 8;
                break;
        }
        return [$type, $rate];
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
                //2.1修改门店流水按照下单支付金额计算，
                if ($order['type'] == 8) {
                    $order['pay_price'] = $order['total_price'];
                }
                $total_price = $order['pay_price'];
                //支付金额+积分抵扣（由平台承担）
                $total_price = bcadd((string)$total_price, (string)($order['deduction_price'] ?? 0), 2);
                $append = [
                    'pay_price' => $total_price,
                    'total_price' => $total_price,
                    'rate' => 1
                ];
                //支付订单
                $this->savaData($order, $total_price, 1, 1, 1);
                //线下支付、现金支付增加
                if (in_array($order['pay_type'], [PayServices::OFFLINE_PAY, PayServices::CASH_PAY])) {
                    //交易订单记录
                    $this->savaData($order, $order['pay_price'], 0, 2, 1, $append);
                }
                $product_type = $storeOrderServices->value(['id' => $order['id']], 'product_type');
                if (!in_array($product_type, [4, 5, 6])) {
                    //门店订单
                    $this->savaData($order, $total_price, 1, 2, 1, $append);
                }
                [$type, $rate] = $this->getRate(1, (int)($order['store_id'] ?? 0), (int)$order['shipping_type']);
                //费率按实付金额计算
                $total_price = bcmul((string)$order['pay_price'], (string)$this->getPercent($rate), 2);
                $append['rate'] = $rate;
                //交易订单记录
                if (!in_array($product_type, [4, 5, 6])) {
                    $this->savaData($order, $total_price, 1, $type, 2, $append);
                    $this->savaData($order, $total_price, 0, 3, 1, $append);
                }
                break;
            case 2://储值订单
                //储值订单返点
                [$type, $rate] = $this->getRate(2, (int)($order['store_id'] ?? 0));
                $order['pay_type'] = $order['recharge_type'];
                $append = [
                    'pay_price' => $order['price'],
                    'total_price' => $order['price'],
                    'rate' => $rate
                ];
                //订单账单
                $this->savaData($order, $order['price'], 1, $type, 2, $append);

                //收银台储值线下付款记录一条负记录
                if (in_array($order['recharge_type'], [PayServices::OFFLINE_PAY, PayServices::CASH_PAY])) {
                    $this->savaData($order, $order['price'], 0, $type, 1, $append);
                }
                //返点
                $pay_price = bcmul($order['price'], $this->getPercent($rate), 2);
                $this->savaData($order, $pay_price, 1, 5, 1);
                break;
            case 3://付费会员订单
                //购买付费会员返点
                [$type, $rate] = $this->getRate(3, (int)($order['store_id'] ?? 0));
                $append = [
                    'pay_price' => $order['pay_price'],
                    'total_price' => $order['pay_price'],
                    'rate' => $rate
                ];
                //订单账单
                $this->savaData($order, $order['pay_price'], 1, $type, 2, $append);

                //收银台储值线下付款记录一条负记录
                if (in_array($order['pay_type'], [PayServices::OFFLINE_PAY, PayServices::CASH_PAY])) {
                    $this->savaData($order, $order['pay_price'], 0, $type, 1, $append);
                }
                //返点
                $pay_price = bcmul($order['pay_price'], $this->getPercent($rate), 2);
                $this->savaData($order, $pay_price, 1, 6, 1);
                break;
            case 4://退款
                //取下单流水记录费率
                $rate = $this->dao->value(['link_id' => $order['order_id'], 'type' => 3, 'trade_type' => 1], 'rate');
                if (!$rate) {
                    //获取失败,如果是子订单；在查询主订单
                    if (isset($order['pid']) && $order['pid']) {
                        $order_id = $storeOrderServices->value(['id' => $order['pid']], 'order_id');
                        if ($order_id) $rate = $this->dao->value(['link_id' => $order_id, 'type' => 3, 'trade_type' => 1], 'rate');
                    }
                }
                if (!$rate) {//未获取到，下单保存费率；获取系统配置
                    [$type, $rate] = $this->getRate(1, (int)($order['store_id'] ?? 0), (int)$order['shipping_type']);
                }
                if (isset($order['pay_price']) && $order['pay_price'] <= $price) {
                    //支付金额+积分抵扣（由平台承担）
                    $price = bcadd((string)$order['pay_price'], (string)($order['deduction_price'] ?? 0), 2);
                    $total_price = bcmul((string)$order['pay_price'], $this->getPercent($rate), 2);
                } else {
                    $total_price = bcmul($price, $this->getPercent($rate), 2);
                }

                $append['rate'] = $rate;
                //退款
                //线下支付、现金支付增加
                if (in_array($order['pay_type'], [PayServices::OFFLINE_PAY, PayServices::CASH_PAY])) {
                    //交易订单记录
                    $this->savaData($order, $price, 1, 4, 1, $append);
                }
                $product_type = $storeOrderServices->value(['id' => $order['id']], 'product_type');
                //交易订单记录
                if (!in_array($product_type, [4, 5, 6])) {
                    $this->savaData($order, $price, 0, 4, 1, $append);
                    $this->savaData($order, $total_price, 1, 3, 1, $append);
                } else {
                    $this->savaData($order, $price, 0, 15, 1, $append);
                }
                break;
            case 5://储值退款
                //取储值流水记录费率
                $rate = $this->dao->value(['link_id' => $order['order_id'], 'type' => 5, 'trade_type' => 1], 'rate');
                if (!$rate) {//获取失败，取系统配置
					[$type, $rate] = $this->getRate(2, (int)($order['store_id'] ?? 0));
                }
                $order['pay_type'] = $order['recharge_type'];
                $append = [
                    'pay_price' => $order['price'],
                    'total_price' => $order['price'],
                    'rate' => $rate
                ];
                //订单账单
                $this->savaData($order, $price, 0, 4, 2, $append);
                //返点扣除
                $pay_price = bcmul($price, $this->getPercent($rate), 2);
                $this->savaData($order, $pay_price, 0, 5, 1, $append);
                break;
            case 6://配送订单
                $append = ['pay_price' => $order['cargo_price']];
                $this->savaData($order, $price, 0, 13, 1, $append);
                break;
            case 7://取消配送订单
                $append = ['pay_price' => $order['cargo_price']];
                $this->savaData($order, $price, 1, 13, 1, $append);
                break;
            case 8://核销次卡、卡项、预约
                $product_type = $storeOrderServices->value(['id' => $order['id']], 'product_type');
                if (!in_array($product_type, [4, 5, 6])) return true;
                [$type, $rate] = $this->getRate(1, (int)($order['store_id'] ?? 0), (int)$order['shipping_type']);
                //费率金额计算
                $total_price = bcmul((string)$price, (string)$this->getPercent($rate), 2);
                $append['rate'] = $rate;
                $append = [
                    'pay_price' => $price,
                    'total_price' => $price
                ];
                $this->savaData($order, $price, 1, 14, 1, $append);
                //交易订单记录
                $this->savaData($order, $total_price, 1, $type, 2, $append);
                $this->savaData($order, $total_price, 0, 3, 1, $append);
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
    public function savaData($order, $number, $pm, $type, $trade_type, array $append = [])
    {
        $order_id = $this->getUniqueId('ls');
        $data = [
            'store_id' => $order['store_id'] ?? $order['relation_id'] ?? 0,
            'uid' => $order['uid'] ?? 0,
            'staff_id' => $order['staff_id'] ?? 0,
            'order_id' => $order_id,
            'link_id' => $order['order_id'] ?? '',
            'pay_type' => $order['pay_type'] ?? '',
            'trade_time' => $order['pay_time'] ?? $order['add_time'] ?? '',
            'pm' => $pm,
            'number' => $trade_type == 1 ? ($number ?: 0) : 0,
            'type' => $type,
            'trade_type' => $trade_type,
            'take_time' => in_array($type, [1, 2, 9, 10, 11, 12]) ? 0 : time(),
            'add_time' => time()
        ];
        $data = array_merge($data, $append);
        $this->dao->save($data);
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
     * 可提现金额
     * @param array $where
     * @return int|string
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getSumFinance(array $where, array $whereData)
    {
        $field = 'sum(if(pm = 1,number,0)) as income_num,take_time,sum(if(pm = 0,number,0)) as exp_num';
        $data = $this->dao->getList($whereData, $field);
        if (!$data) return 0;
        $income_num = isset($data[0]['income_num']) ? $data[0]['income_num'] : 0;
        $exp_num = isset($data[0]['exp_num']) ? $data[0]['exp_num'] : 0;
        $number = bcsub($income_num, $exp_num, 2);
        //已提现金额
        /** @var StoreExtractServices $storeExtractServices */
        $storeExtractServices = app()->make(StoreExtractServices::class);
        $where['not_status'] = -1;
        $extract_price = $storeExtractServices->getExtractMoneyByWhere($where, 'extract_price');
        $price_not = bcsub((string)$number, (string)$extract_price, 2);
        return $price_not;
    }

    /**
     * 冻结中金额
     * @param array $whereData
     * @return int|mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getFreezeSumFinance(array $whereData)
    {
        $field = 'sum(if(pm = 1,number,0)) as income_num,take_time';
        $data = $this->dao->getList($whereData, $field);
        if (!$data) return 0;
        return isset($data[0]['income_num']) ? $data[0]['income_num'] : 0;
    }

    /**
     * 更新完成时间
     * @param $order
     * @param $take_time
     * @return mixed
     */
    public function addFinanceTakeTime($order, $take_time)
    {
        if ($this->dao->be(['link_id' => $order['order_id'], 'is_del' => 0])) {
            return $this->dao->update(['link_id' => $order['order_id'], 'is_del' => 0], ['take_time' => $take_time]);
        } else {
            if ($order['pid'] > 0) {
                /** @var StoreOrderServices $orderServices */
                $orderServices = app()->make(StoreOrderServices::class);
                $order = $orderServices->get((int)$order['id']);
                return $this->dao->update(['link_id' => $order['order_id'], 'is_del' => 0], ['take_time' => $take_time]);
            }
        }

    }

}
