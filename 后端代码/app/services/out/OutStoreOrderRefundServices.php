<?php
// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2023 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------
namespace app\services\out;

use app\dao\order\StoreOrderRefundDao;
use app\services\BaseServices;
use app\services\pay\PayServices;
use mohe\exceptions\ApiException;
use app\services\order\StoreOrderRefundServices;

/**
 * 售后单
 * Class OutStoreOrderRefundServices
 * @package app\services\order
 */
class OutStoreOrderRefundServices extends BaseServices
{
    /**
     * 订单services
     * @var StoreOrderServices
     */
    protected $storeOrderServices;

    /**
     * 构造方法
     * OutStoreOrderRefundServices constructor.
     * @param StoreOrderRefundDao $dao
     */
    public function __construct(StoreOrderRefundDao $dao, OutStoreOrderServices $storeOrderServices)
    {
        $this->dao = $dao;
        $this->storeOrderServices = $storeOrderServices;
    }

    /**
 	* 售后单列表
	* @param array $where
	* @return array
	*/
    public function refundList(array $where)
    {
        [$page, $limit] = $this->getPageValue();
        $field = 'id, store_order_id, uid, order_id, refund_type, refund_num, refund_price, refunded_price, refund_phone, refund_express, refund_express_name, 
        refund_explain, refund_img, refund_reason, refuse_reason, remark, refunded_time, cart_info, is_cancel, is_del, add_time';
        $list = $this->dao->getRefundList($where, $field, [], $page, $limit);
        $count = $this->dao->count($where);
        foreach ($list as $key => &$item) {
            $item['pay_price'] = $item['refund_price'];
            unset($item['refund_price']);
            $item['items'] = $this->tidyCartList($item['cart_info']);
            unset($list[$key]['cart_info']);
        }
        return compact('list', 'count');
    }

    /**
     * 格式化订单商品
     * @param array $carts
     * @return array
     */
    public function tidyCartList(array $carts): array
    {
        $list = [];
        foreach ($carts as $cart) {
            $list[] = [
                'store_name' => $cart['productInfo']['store_name'] ?? '',
                'suk' => $cart['productInfo']['attrInfo']['suk'] ?? '',
                'image' => $cart['productInfo']['attrInfo']['image'] ?: $cart['productInfo']['image'],
                'price' => sprintf("%.2f", $cart['truePrice'] ?? '0.00'),
                'cart_num' => $cart['cart_num'] ?? 0
            ];
        }
        return $list;
    }


    /**
     * 退款订单详情
     * @param string $orderId 售后单号
     * @param int $id 售后单ID
     * @return mixed
     */
    public function getInfo(string $orderId = '', int $id = 0)
    {
        $field = ['id', 'store_order_id', 'order_id', 'uid', 'refund_type', 'refund_num', 'refund_price',
            'refunded_price', 'refund_phone', 'refund_express', 'refund_express_name', 'refund_explain',
            'refund_img', 'refund_reason', 'refuse_reason', 'remark', 'refunded_time', 'cart_info', 'is_cancel', 'is_del', 'add_time'];

        if ($id > 0) {
            $where = $id;
        } else {
            $where = ['order_id' => $orderId];
        }
        $refund = $this->dao->get($where, $field, ['orderData']);
        if (!$refund) throw new ApiException(410173);
        $refund = $refund->toArray();

        //核算优惠金额
        $totalPrice = 0;
        $vipTruePrice = 0;
        foreach ($refund['cart_info'] ?? [] as $key => &$cart) {
            $cart['sum_true_price'] = sprintf("%.2f", $cart['sum_true_price'] ?? bcmul((string)$cart['truePrice'], (string)$cart['cart_num'], 2));
            $cart['vip_sum_truePrice'] = bcmul($cart['vip_truePrice'], $cart['cart_num'] ?: 1, 2);
            $vipTruePrice = bcadd((string)$vipTruePrice, $cart['vip_sum_truePrice'], 2);
            if (isset($order['split']) && $order['split']) {
                $refund['cart_info'][$key]['cart_num'] = $cart['surplus_num'];
                if (!$cart['surplus_num']) unset($refund['cart_info'][$key]);
            }
            $totalPrice = bcadd($totalPrice, $cart['sum_true_price'], 2);
        }
        $refund['vip_true_price'] = $vipTruePrice;

        /** @var StoreOrderRefundServices $refundServices */
        $refundServices = app()->make(StoreOrderRefundServices::class);
        $refund['use_integral'] = $refundServices->getOrderSumPrice($refund['cart_info'], 'use_integral', false);
        $refund['coupon_price'] = $refundServices->getOrderSumPrice($refund['cart_info'], 'coupon_price', false);
        $refund['deduction_price'] = $refundServices->getOrderSumPrice($refund['cart_info'], 'integral_price', false);
        $refund['pay_postage'] = $refundServices->getOrderSumPrice($refund['cart_info'], 'postage_price', false);
        $refund['total_price'] = bcadd((string)$totalPrice, bcadd((string)$refund['deduction_price'], (string)$refund['coupon_price'], 2), 2);
        $refund['items'] = $this->tidyCartList($refund['cart_info']);
        if (in_array($refund['refund_type'], [0, 1, 2, 4, 5])) {
            $title = '申请退款中';
        } elseif ($refund['refund_type'] == 3) {
            $title = '拒绝退款';
        } else {
            $title = '已退款';
        }

        $refund['refund_type_name'] = $title;
        $refund['pay_type_name'] = PayServices::PAY_TYPE[$refund['pay_type']] ?? '其他方式';
        unset($refund['cart_info']);
        return $refund;
    }

}
