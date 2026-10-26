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

namespace app\services\order;


use app\dao\order\StoreOrderDao;
use app\services\BaseServices;
use app\services\product\product\StoreProductLogServices;

/**
 * Class StoreOrderWapServices
 * @package app\services\order
 * @mixin StoreOrderDao
 */
class StoreOrderWapServices extends BaseServices
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
     * 获取 今日 昨日 本月 订单金额
     * @return mixed
     */
    public function getOrderTimeData(int $store_id = 0)
    {
        $where = ['pid' => 0, 'timeKey' => 'add_time', 'paid' => 1, 'is_del' => 0, 'is_system_del' => 0, 'refund_status' => [0, 3], 'store_id' => $store_id, 'plat_type' => 0];
        //今日成交额
        $data['todayPrice'] = $this->dao->together($where + ['time' => 'today'], 'pay_price');
        //今日订单数
        $data['todayCount'] = $this->dao->count($where + ['time' => 'today']);
        //昨日成交额
        $data['proPrice'] = $this->dao->together($where + ['time' => 'yesterday'], 'pay_price');
        //昨日订单数
        $data['proCount'] = $this->dao->count($where + ['time' => 'yesterday']);
        //本月成交额
        $data['monthPrice'] = $this->dao->together($where + ['time' => 'month'], 'pay_price');
        //本月订单数
        $data['monthCount'] = $this->dao->count($where + ['time' => 'month']);
        return $data;
    }

    /**
     * 前端订单列表
     * @param array $where
     * @param array|string[] $field
     * @param array $with
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getOrderApiList(array $where, array $field = ['*'], array $with = [])
    {
        [$page, $limit] = $this->getPageValue();
        if (isset($where['status']) && $where['status'] === '') {
            $data = $this->dao->getOrderList($where, $field, $page, $limit, $with, 'id DESC');
        } else {
            $data = $this->dao->getOrderList($where, $field, $page, $limit, $with);
        }
        /** @var StoreOrderServices $orderServices */
        $orderServices = app()->make(StoreOrderServices::class);
        foreach ($data as &$item) {
            $item['type_name'] = '';
            if ($item['type'] > 0) {
                [$type_name, $color] = $orderServices->tidyOrderType($item, true);
                $item['type_name'] = $type_name;
            }
            $item = $orderServices->tidyOrder($item, true);
            $cart_num = 0;
            foreach ($item['cartInfo'] ?: [] as $key => $product) {
                if (isset($item['_status']['_type']) && $item['_status']['_type'] == 3) {
                    $item['cartInfo'][$key]['add_time'] = isset($product['add_time']) ? date('Y-m-d H:i', (int)$product['add_time']) : '时间错误';
                }
                $item['cartInfo'][$key]['productInfo']['price'] = $product['truePrice'] ?? 0;

                if (isset($product['cart_type']) && $product['cart_type'] > 0) continue;
                $cart_num += $product['cart_num'];
            }
            if (count($item['refund'])) {
                $refund_num = array_sum(array_column($item['refund'], 'refund_num'));
                $item['is_all_refund'] = $refund_num == $cart_num;
            } else {
                $item['is_all_refund'] = false;
            }
        }
        return $data;
    }

    /**
     * 订单每月统计数据
     * @param array $where
     * @param int $store_id
     * @return array
     */
    public function getOrderDataPriceCount(array $where = [], int $store_id = 0)
    {
        [$page, $limit] = $this->getPageValue();
        $data = $this->dao->getOrderDataPriceCount($where + ['pid' => 0, 'is_del' => 0, 'paid' => 1, 'refund_status' => [0, 3], 'is_system_del' => 0, 'store_id' => $store_id], ['sum(pay_price) as price', 'count(id) as count', 'add_time'], $page, $limit);
        if (count($data) > 0) {
            /** @var StoreProductLogServices $productLogServices */
            $productLogServices = app()->make(StoreProductLogServices::class);
            foreach ($data as $key => &$time) {
                $time['time'] = date('m-d', $time['add_time']);
                $time['visit'] = $productLogServices->count(['time' => [$time['add_time'], $time['add_time'] + 86400], 'type' => 'visit']);
            }
        }
        return $data;
    }

    /**
     * 获取手机端订单管理
     * @param array $where
     * @param array $with
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getWapAdminOrderList(array $where, array $with = [])
    {
        [$page, $limit] = $this->getPageValue();
        $list = $this->dao->getOrderList($where, ['*'], $page, $limit, $with);
        /** @var StoreOrderServices $orderServices */
        $orderServices = app()->make(StoreOrderServices::class);
        $list = $orderServices->tidyOrderList($list, true, true);
        foreach ($list as &$item) {
            $refund_num = array_sum(array_column($item['refund'], 'refund_num'));
            $cart_num = 0;
            foreach ($item['_info'] as $items) {
				if ((isset($items['cart_info']['cart_type']) && $items['cart_info']['cart_type'] > 0)) continue;
                $cart_num += $items['cart_info']['cart_num'];
            }
            $item['is_all_refund'] = $refund_num == $cart_num;
        }
        return $list;
    }

}
