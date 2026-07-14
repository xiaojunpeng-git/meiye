<?php

namespace app\controller\admin\v1\store;

use app\controller\admin\AuthController;
use app\Request;
use app\services\agent\SystemRegionAgentServices;
use app\services\order\StoreOrderRefundServices;
use app\services\order\StoreOrderServices;
use app\services\other\ExpressServices;
use app\services\user\UserServices;
use think\facade\App;

class RefundOrder extends AuthController
{
    /**
     * RefundOrder constructor.
     * @param App $app
     * @param StoreOrderRefundServices $service
     */
    public function __construct(App $app, StoreOrderRefundServices $service)
    {
        parent::__construct($app);
        $this->services = $service;
    }

    /**
     * 退款订单列表
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getRefundList(SystemRegionAgentServices $regionAgentServices)
    {
        $where = $this->request->getMore([
            ['order_id', ''],
            ['time', ''],
            ['refund_type', ''],
            ['refund_reason', ''],
			['store_id', '']
        ]);
		if (!$where['store_id']) {//无筛选
			if ($this->adminType == 3 && $this->agentId) {//区域代理商登录
				$storeIds = $regionAgentServices->getRegionAgentStoreId((int)$this->agentId);
				if ($storeIds) {
					$where['store_id'] = $storeIds;
				} else {
					return $this->success(['count' => 0, 'list' => [], 'num' => []]);
				}
			}
		}
        return $this->success($this->services->refundList($where, ['user', 'store']));
    }

    /**
     * 退款表单生成
     * @param $id
     * @return mixed
     * @throws \FormBuilder\Exception\FormBuilderException
     */
    public function refund($id)
    {
        if (!$id) {
            return app('json')->fail('Data does not exist!');
        }
        return app('json')->success($this->services->refundOrderForm((int)$id));
    }

    /**
     * 订单退款
     * @param Request $request
     * @param StoreOrderServices $services
     * @param $id
     * @return mixed
     */
    public function update_refund(Request $request, StoreOrderServices $services, $id)
    {
        return app('json')->fail('暂不支持!');
        $data = $request->postMore([
            ['refund_price', 0],
            ['type', 1],
            ['refuse_reason', ''],
			['stock_in_type', '0'],//退款同步操作退货入库0:暂不入库1:良品入库2:残次品入库
        ]);
        if (!$id) {
            return app('json')->fail('Data does not exist!');
        }
        $orderRefund = $this->services->get($id);
        if (!$orderRefund) {
            return app('json')->fail('Data does not exist!');
        }
        if ($orderRefund['is_cancel'] == 1) {
            return app('json')->fail('用户已取消申请');
        }
        $order = $services->get((int)$orderRefund['store_order_id']);
        if (!$order) {
            return app('json')->fail('Data does not exist!');
        }
		if ($order['refund_status'] == 2) {
			return app('json')->fail('订单已完成退款，请勿重复操作!');
		}
        if (!in_array($orderRefund['refund_type'], [0, 1, 2, 5]) && !($orderRefund['refund_type'] == 4 && $orderRefund['apply_type'] == 3)) {
            return app('json')->fail('售后订单状态不支持该操作');
        }
        if ($data['type'] == 1) {
            $data['refund_type'] = 6;
        } else if ($data['type'] == 2) {
            $data['refund_type'] = 3;
        }
        $data['refunded_time'] = time();
        $type = $data['type'];
        //拒绝退款
        if ($type == 2) {
            $this->services->refuseRefund((int)$id, $data, $orderRefund);
            return app('json')->successful('修改退款状态成功!');
        } else {
            //0元退款
            if ($orderRefund['refund_price'] == 0) {
                $refund_price = 0;
            } else {
                if (!$data['refund_price']) {
                    return app('json')->fail('请输入退款金额');
                }
                if ($orderRefund['refund_price'] == $orderRefund['refunded_price']) {
                    return app('json')->fail('已退完支付金额!不能再退款了');
                }
                $refund_price = $data['refund_price'];
                $data['refunded_price'] = bcadd($data['refund_price'], $orderRefund['refunded_price'], 2);
                $bj = bccomp((string)$orderRefund['refund_price'], (string)$data['refunded_price'], 2);
                if ($bj < 0) {
                    return app('json')->fail('退款金额大于支付金额，请修改退款金额');
                }
            }

            unset($data['type']);
            $refund_data['pay_price'] = $order['pay_price'];
            $refund_data['refund_price'] = $refund_price;
            if ($order['refund_price'] > 0) {
                mt_srand();
                $refund_data['refund_id'] = $order['order_id'] . rand(100, 999);
            }
            //修改订单退款状态
            unset($data['refund_price']);
			$this->services->setItem('change_manager_type', 'store')
				->setItem('change_manager_id', $request->storeStaffId())
				->setItem('stock_in_type', $data['stock_in_type'] ?? 0);
            $this->services->agreeRefund($id, $refund_data);
			$this->services->reset();

			$this->services->update($id, $data);
			return app('json')->success('退款成功');
        }
    }

    /**
     * 商家同意退货退款
     * @return mixed
     */
    public function agreeRefund()
    {
        [$order_id] = $this->request->getMore([
            ['order_id', '']
        ], true);
        $this->services->agreeRefundProdcut((int)$order_id);
        return app('json')->success('操作成功');
    }

	/**
	 * 查询用户退货物流信息
	 * @param $id
	 * @return \think\Response
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function getRefundExpress($id)
	{
		if (!$id || !is_numeric($id)) {
			return app('json')->fail('参数错误');
		}
		return app('json')->success($this->services->getRefundExpress((int)$id));
	}

    /**
     * 修改备注
     * @param $id
     * @return mixed
     */
    public function remark($id)
    {
        $data = $this->request->postMore([['remark', '']]);
        if (!$data['remark'])
            return app('json')->fail('请输入要备注的内容');
        if (!$id)
            return app('json')->fail('缺少参数');
        if (!$order = $this->services->get($id)) {
            return app('json')->fail('修改的订单不存在!');
        }
        $order->remark = $data['remark'];
        if ($order->save()) {
            return app('json')->success('备注成功');
        } else
            return app('json')->fail('备注失败');
    }

	/**
	 * 售后详情
	 * @param $id
	 * @return \think\Response
	 */
    public function refundDetail($id)
    {
        if (!$id) return app('json')->fail('缺少参数');
        $orderInfo = $this->services->refundDetail($id, ['*'], ['invoice', 'virtual', 'supplierInfo']);
        $userInfo = ['spread_uid' => '', 'spread_name' => '无'];
        if ($orderInfo['uid']) {
            /** @var UserServices $services */
            $services = app()->make(UserServices::class);
            $userInfo = $services->getUserWithTrashedInfo($orderInfo['uid']);
            if (!$userInfo) return app('json')->fail('用户信息不存在');
            $userInfo = $userInfo->hidden(['pwd', 'add_ip', 'last_ip', 'login_type']);
            $userInfo = $userInfo->toArray();
            $userInfo['spread_name'] = '无';
            if ($orderInfo['spread_uid']) {
                $spreadName = $services->value(['uid' => $orderInfo['spread_uid']], 'nickname');
                if ($spreadName) {
                    $userInfo['spread_name'] = $orderInfo['uid'] == $orderInfo['spread_uid'] ? $spreadName . '(自购)' : $spreadName;
                    $userInfo['spread_uid'] = $orderInfo['spread_uid'];
                } else {
                    $userInfo['spread_uid'] = '';
                }
            } else {
                $userInfo['spread_uid'] = '';
            }
        }
		$orderInfo['total_price'] = floatval(bcadd((string)$orderInfo['total_price'], (string)$orderInfo['vip_true_price'], 2));
        return $this->success(compact('orderInfo', 'userInfo'));
    }

	/**
	 * 删除订单
	 * @param $id
	 * @return mixed
	 */
	public function del($id)
	{
		if (!$id || !($orderInfo = $this->services->get($id)))
			return app('json')->fail('售后订单不存在');
		if (!$orderInfo->is_del)
			return app('json')->fail('售后订单用户未删除无法删除');
		$orderInfo->is_system_del = 1;
		if ($orderInfo->save())
			return app('json')->success('SUCCESS');
		else
			return app('json')->fail('ERROR');
	}
}
