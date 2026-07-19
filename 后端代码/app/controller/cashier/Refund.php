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

namespace app\controller\cashier;


use app\Request;
use app\services\order\StoreOrderRefundServices;
use app\services\order\StoreOrderServices;
use app\services\user\UserServices;
use think\facade\App;

/**
 * Class Refund
 * @package app\controller\store\order
 */
class Refund extends AuthController
{
    /**
     * StoreOrder constructor.
     * @param App $app
     * @param StoreOrderRefundServices $service
     * @method temp
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
    public function getRefundList(Request $request)
    {
        $where = $request->getMore([
            ['keyword', '', '', 'order_id'],
            ['time', ''],
            ['refund_type', '']
        ]);
		if ($where['time'] && is_array($where['time']) && count($where['time']) == 2) {
			[$start, $end] = $where['time'];
			if (strtotime($start) > strtotime($end)){
				return $this->fail('开始时间不能大于结束时间，请重新选择时间');
			}
		}
        $where['store_id'] = $this->storeId;
        return $this->success($this->services->refundList($where));
    }

    /**
     * 订单详情
     * @param UserServices $userServices
     * @param $id
     * @return mixed
     */
    public function detail(UserServices $userServices, $id)
    {
        $order = $this->services->refundDetail($id);
		$order['total_price'] = floatval(bcadd((string)$order['total_price'], (string)$order['vip_true_price'], 2));
        $data['orderInfo'] = $order;
        $userInfo = ['spread_uid' => '', 'spread_name' => '无'];
        if ($order['uid']) {
            $userInfo = $userServices->get((int)$order['uid']);
            if (!$userInfo) return app('json')->fail('用户信息不存在');
            $userInfo = $userInfo->hidden(['pwd', 'add_ip', 'last_ip', 'login_type']);
            $userInfo = $userInfo->toArray();
            $userInfo['spread_name'] = '无';
            if ($order['spread_uid']) {
                $spreadName = $userServices->value(['uid' => $order['spread_uid']], 'nickname');
                if ($spreadName) {
                    $userInfo['spread_name'] = $order['uid'] == $order['spread_uid'] ? $spreadName . '(自购)' : $spreadName;
                    $userInfo['spread_uid'] = $order['spread_uid'];
                } else {
                    $userInfo['spread_uid'] = '';
                }
            } else {
                $userInfo['spread_uid'] = '';
            }
        }
        $data['userInfo'] = $userInfo;
        return app('json')->successful('ok', $data);
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
        $data = $request->postMore([
            ['refund_price', 0],
            ['type', 1],
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
            $this->services->refuseRefund((int)$orderRefund['id'], $data, $orderRefund);
            return app('json')->successful('修改退款状态成功!');
        } else {
            if ($orderRefund['refund_price'] == 0) {
                $refund_price = 0;
            } else {
                if (!$data['refund_price']) {
                    return app('json')->fail('请输入退款金额');
                }
                $refund_price = $data['refund_price'];
            }

            unset($data['type']);
            try {
                $raw = $request->post();
                /** @var \app\services\order\StoreOrderRefundDomainServices $domain */
                $domain = app()->make(\app\services\order\StoreOrderRefundDomainServices::class);
                $result = $domain->agreeAfterSaleRefund((int)$id, [
                    'refund_amount' => $refund_price,
                    'refund_ben' => array_key_exists('refund_ben', $raw) ? $raw['refund_ben'] : null,
                    'refund_give' => array_key_exists('refund_give', $raw) ? $raw['refund_give'] : null,
                    'bookkeeping_confirmed' => (int)($raw['bookkeeping_confirmed'] ?? 0),
                    'bookkeeping_remark' => (string)($raw['bookkeeping_remark'] ?? ''),
                    'refund_business_date' => (string)$request->post('refund_business_date', ''),
                    'request_token' => (string)$request->post('request_token', ''),
                    'stock_in_type' => $data['stock_in_type'] ?? 0,
                    'return_coupon' => $request->post('return_coupon', 1),
                    'store_scope' => (int)($request->storeId ?? 0),
                    'source_type' => \app\model\order\StoreOrderTerminalOperation::SOURCE_CASHIER,
                    'operator_type' => 'cashier',
                    'operator_id' => (int)$request->cashierId(),
                    'is_split_order' => $request->post('is_split_order', 0),
                    'cart_ids' => $request->post('cart_ids', []),
                    'merge_refund_id' => $request->post('merge_refund_id', 0),
                    'refund_num' => $request->post('refund_num', ''),
                    'cart_num' => $request->post('cart_num', ''),
                ]);
                unset($data['refund_price']);
                $this->services->update($id, $data);
                return app('json')->success($result['message'] ?? '退款成功', $result);
            } catch (\think\exception\ValidateException $e) {
                return app('json')->fail($e->getMessage());
            } catch (\Throwable $e) {
                return app('json')->fail('操作未成功，订单状态未改变，请核对后重试或联系负责人。');
            }
        }
    }

    /**
     * 商家同意退货退款
     * @return mixed
     */
    public function agreeRefund()
    {
        [$id] = $this->request->getMore([
            ['id', '']
        ], true);
        $this->services->agreeRefundProdcut((int)$id);
        return app('json')->success('操作成功');
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

}

