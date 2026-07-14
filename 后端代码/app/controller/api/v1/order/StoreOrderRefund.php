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
namespace app\controller\api\v1\order;

use app\Request;


use app\services\order\StoreOrderRefundServices;
use app\services\order\StoreOrderServices;


/**
 * 售后订单
 * Class StoreOrderRefund
 * @package app\controller\api\v1\order
 */
class StoreOrderRefund
{

    /**
     * @var StoreOrderServices
     */
    protected $services;


    /**
     * StoreOrderRefund constructor.
     * @param StoreOrderRefundServices $services
     */
    public function __construct(StoreOrderRefundServices $services)
    {
        $this->services = $services;
    }


    /**
     * 订单列表
     * @param Request $request
     * @return mixed
     */
    public function lst(Request $request)
    {
        $where = $request->getMore([
            [['search', 's'], '', '', 'real_name'],
            ['refund_type', '', '', 'refundTypes']
        ]);
        $where['uid'] = $request->uid();
        $where['is_cancel'] = 0;
        $list = $this->services->getRefundOrderList($where);
        return app('json')->successful($list);
    }

    /**
     * 订单详情
     * @param Request $request
     * @param $uni
     * @return mixed
     */
    public function detail(StoreOrderRefundServices $services, Request $request, $uni)
    {
        if (!strlen(trim($uni))) return app('json')->fail('参数错误');
        $orderData = $services->refundDetail($uni);
        return app('json')->successful('ok', $orderData);
    }


    /**
     * 取消申请
     * @param $id
     * @return mixed
     */
    public function cancelApply(Request $request, $uni)
    {
        if (!$uni || !is_string($uni) || !strlen(trim($uni))) {
            return app('json')->fail('参数错误');
        }
		$uid = (int)$request->uid();
		$this->services->cancelApplyRefund($uid, $uni);
        return app('json')->success('取消成功');
    }

    /**
     * 删除已退款和拒绝退款的订单
     * @param Request $request
     * @param $uni
     * @return mixed
     */
    public function delRefundOrder(Request $request, $uni)
    {
        if (!$uni || !is_string($uni) || !strlen(trim($uni))) {
            return app('json')->fail('参数错误');
        }
        $orderRefund = $this->services->get(['order_id|id' => $uni, 'is_del' => 0]);
        if (!$orderRefund || $orderRefund['uid'] != $request->uid()) {
            return app('json')->fail('订单不存在');
        }
        if (!in_array($orderRefund['refund_type'], [3, 6])) {
            return app('json')->fail('当前状态不能删除退款单');
        }
        $this->services->update($orderRefund['id'], ['is_del' => 1]);
        /** @var StoreOrderServices $orderServices */
        $orderServices = app()->make(StoreOrderServices::class);
        $orderServices->update($orderRefund['store_order_id'], ['is_del' => 1]);
        return app('json')->success('删除成功');
    }

	/**
	 * 再次申请
	 * @param $id
	 * @return mixed
	 */
	public function againRefundOrder(Request $request, $id)
	{
        if (!$id || !is_numeric($id)) {
            return app('json')->fail('参数错误');
        }
		$uid = (int)$request->uid();
		$this->services->againRefundOrder($uid, (int)$id);
		return app('json')->success('申请成功');
	}
}
