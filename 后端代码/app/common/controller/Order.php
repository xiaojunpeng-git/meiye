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

namespace app\common\controller;


use app\jobs\BatchHandleJob;
use app\jobs\order\OrderStatusJob;
use app\model\order\StoreOrder;
use app\model\order\StoreOrderCartInfo;
use app\model\yeji\StaffYeji;
use app\Request;
use app\services\activity\coupon\StoreCouponIssueServices;
use app\services\activity\coupon\StoreCouponUserServices;
use app\services\order\store\WriteOffOrderServices;
use app\services\order\StoreDeliveryOrderServices;
use app\services\order\StoreOrderCartInfoServices;
use app\services\order\StoreOrderCreateServices;
use app\services\order\StoreOrderDeliveryServices;
use app\services\order\StoreOrderRefundServices;
use app\services\order\StoreOrderServices;
use app\services\order\StoreOrderStatusServices;
use app\services\order\StoreOrderTakeServices;
use app\services\order\StoreOrderWriteOffServices;
use app\services\order\StoreOrderPromotionsServices;
use app\services\order\supplier\SupplierOrderServices;
use app\services\pay\OrderOfflineServices;
use app\services\other\queue\QueueServices;
use app\services\serve\ServeServices;
use app\services\other\ExpressServices;
use app\services\store\DeliveryServiceServices;
use app\services\store\SystemStoreServices;
use app\services\supplier\SystemSupplierServices;
use app\services\user\UserServices;
use app\services\user\UserCardHolderServices;
use app\validate\admin\order\StoreOrderValidate;
use mohe\services\DownloadImageService;
use mohe\services\SystemConfigService;
use mohe\traits\MacroTrait;
use think\db\exception\DataNotFoundException;
use think\db\exception\DbException;
use think\db\exception\ModelNotFoundException;
use think\Response;

/**
 * Trait Order
 * @package app\common\controller
 * @property StoreOrderServices $services
 */
trait Order
{
    use MacroTrait;

    /**
     * 获取订单类型数量
     * @param Request $request
     * @return mixed
     */
    public function chart(Request $request)
    {
        $where = $request->getMore([
            ['status', ''],
            ['real_name', ''],
            ['search_order_id', ''],
            ['search_product', ''],
            ['search_user', ''],
            ['data', '', '', 'time'],
            ['pay_time', ''],
            ['take_time', ''], //收货时间
            ['deliveryType', ''], //1 快递、2 商家配送、3 UU、4 达达、5 无需配送、6到店核销
            ['interval_price_min', ''],
            ['interval_price_max', ''],
            ['type', ''],
            ['plat_type', 0],
            ['pay_type', ''],
            ['field_key', ''],
            ['store_id', ''],
            ['supplier_id', '']
        ]);
        $where['plat_type'] = 0;//平台订单
        $where['type'] = trim($where['type']);
        if (!in_array($where['status'], [-1, -2, -3])) {
            $where['pid'] = [0, -1];
        }
        $where['type'] = trim($where['type'], ' ');
        $data = $this->services->orderCount($where);
        return app('json')->success($data);
    }

    /**
     * 获取订单列表
     * @param Request $request
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function lst(Request $request)
    {
        $where = $request->getMore([
            ['status', ''],
            ['real_name', ''],
            ['search_order_id', ''],
            ['search_product', ''],
            ['search_user', ''],
            ['is_del', ''],
            ['data', '', '', 'time'],
            ['pay_time', ''],
            ['take_time', ''], //收货时间
            ['deliveryType', ''], //1 快递、2 商家配送、3 UU、4 达达、5 无需配送、6到店核销
            ['interval_price_min', ''],
            ['interval_price_max', ''],
            ['type', ''],
            ['pay_type', ''],
            ['plat_type', -1],
            ['order', ''],
            ['field_key', ''],
            ['store_id', ''],
            ['supplier_id', '']
        ]);
        $where['type'] = trim($where['type']);
        $where['status'] = trim($where['status']);
        $where['is_system_del'] = 0;
        $where['plat_type'] = 0;//平台订单
        if (!in_array($where['status'], [-1, -2, -3])) {
            $where['pid'] = [0, -1];
        }
        $where['type'] = trim($where['type'], ' ');
        return app('json')->success($this->services->getOrderList($where, ['*'], ['split' => function ($query) {
            $query->field('id,pid');
        }, 'pink', 'invoice'], false, 'add_time DESC,id DESC', true));
    }

    /**
     * 获取订单拆分子订单列表
     * @return mixed
     */
    public function split_order(Request $request, $id)
    {
        [$status] = $request->getMore([
            ['status', -1]
        ], true);
        if (!$id) {
            return app('json')->fail('缺少订单ID');
        }
        $where = ['pid' => $id, 'is_system_del' => 0];
        if (!$this->services->count($where)) {
            $where = ['id' => $id, 'is_system_del' => 0];
        }
        return app('json')->success($this->services->getSplitOrderList($where, ['*'], ['split', 'pink', 'invoice', 'supplier', 'store' => function ($query) {
            $query->field('id,name')->bind(['store_name' => 'name']);
        }], true));
    }

    /**
     * 核销码核销
     * @param Request $request
     * @param WriteOffOrderServices $writeOffOrderServices
     * @return \think\Response
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function write_order(Request $request, WriteOffOrderServices $writeOffOrderServices)
    {
        [$code, $confirm] = $request->getMore([
            ['code', ''],
            ['confirm', 0]
        ], true);
        if (!$code) return app('json')->fail('Lack of write-off code');
        $orderInfo = $writeOffOrderServices->writeoffOrderInfo(0, $code);
        if ($confirm == 0) {
            return app('json')->success('验证成功', $orderInfo);
        }
        $writeOffOrderServices->writeoffOrder(0, $orderInfo);
        return app('json')->success('Write off successfully');
    }

    /**
     * 整单订单号核销
     * @param WriteOffOrderServices $writeOffOrderServices
     * @param $order_id
     * @return \think\Response
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function write_update(WriteOffOrderServices $writeOffOrderServices, $order_id)
    {
        $orderInfo = $this->services->getOne(['order_id' => $order_id, 'is_del' => 0], '*', ['pink']);
        if (!$orderInfo || $orderInfo->shipping_type != 2 && $orderInfo->delivery_type != 'send') {
            return app('json')->fail('核销订单未查到!');
        } else {
            if (!$orderInfo->verify_code) {
                return app('json')->fail('Lack of write-off code');
            }
            $orderInfo = $writeOffOrderServices->writeOffOrder(0, $orderInfo->toArray(), [], 'admin', $this->adminId);
            if ($orderInfo) {
                return app('json')->success('Write off successfully');
            } else {
                return app('json')->fail('核销失败!');
            }
        }
    }

    /**
     * 修改支付金额等
     * @param $id
     * @return Response
     */
    public function edit($id)
    {
        if (!$id) return app('json')->fail('Data does not exist!');
        return app('json')->success($this->services->updateForm($id));
    }

    /**
     * 订单改价获取商品信息
     * @param StoreOrderCartInfoServices $cartInfoServices
     * @param $id
     * @return \think\Response
     */
    public function getUpdateInfo(StoreOrderCartInfoServices $cartInfoServices, $id)
    {
        if (!$id) return app('json')->fail('Missing order ID');
        $order = $this->services->get($id);
        if (!$order) {
            return app('json')->fail('订单获取失败!');
        }
        if ($order['paid']) {
            return app('json')->fail('订单已支付!');
        }
        $cartInfoList = $cartInfoServices->getColumn(['oid' => $id, 'cart_type' => 0], 'cart_info');
        if (!$cartInfoList) {
            return app('json')->fail('订单商品信息获取失败!');
        }
        $cartInfo = [];
        foreach ($cartInfoList as $cart) {
            $cartInfo[] = is_string($cart) ? json_decode($cart, true) : $cart;
        }
        $result = [
            'orderInfo' => ['id' => $order['id'], 'pay_postage' => $order['pay_postage'], 'pay_price' => $order['pay_price'], 'gain_integral' => $order['gain_integral']],
            'cartInfo' => $cartInfo
        ];
        return app('json')->success($result);
    }

    /**
     * 订单改价
     * @param $id
     * @return mixed
     */
    public function update($id)
    {
        if (!$id) return app('json')->fail('Missing order ID');
        $data = $this->request->postMore([
            ['gain_integral', 0],//赠送积分
            ['is_postage', 0],//是否包邮
            ['cart_info', []],//商品改价数组[['id' => 1, 'true_price' => 11.00],['id' => 2, 'true_price' => 11.00]]
        ]);
        if ($data['cart_info']) {
            foreach ($data['cart_info'] as $cart) {
                if (!isset($cart['id']) || !$cart['id'] || !isset($cart['true_price'])) {
                    return app('json')->fail('请选择需要改价的商品进行操作');
                }
            }
        }
        $this->services->setItem('change_manager_type', 'admin')->setItem('change_manager_id', $this->request->adminId());
        $this->services->updateOrder((int)$id, $data);
        $this->services->reset();
        return app('json')->success('Modified success');
    }

    /**
     * 获取快递公司
     * @param Request $request
     * @param ExpressServices $services
     * @return mixed
     */
    public function express(Request $request, ExpressServices $services)
    {
        [$status] = $request->getMore([
            ['status', ''],
        ], true);
        if ($status != '' && $status != 'undefined') $data['status'] = (int)$status;
        $data['is_show'] = 1;
        return app('json')->success($services->express($data));
    }

    /**
     * 批量删除用户已经删除的订单
     * @param Request $request
     * @return mixed
     */
    public function del_orders(Request $request)
    {
        [$ids, $all, $where] = $request->postMore([
            ['ids', []],
            ['all', 0],
            ['where', []],
        ], true);
        if (!count($ids) && $all == 0) return app('json')->fail('请选择需要删除的订单');
        if ($this->services->getOrderIdsCount($ids) && $all == 0) return app('json')->fail('您选择的的订单存在用户未删除的订单');
        if ($all == 0 && $this->services->batchUpdate($ids, ['is_system_del' => 1])) return app('json')->success('删除成功');
        if ($all == 1) $ids = [];
        $type = 6;// 订单删除
        $where['status'] = -4;
        /** @var QueueServices $queueService */
        $queueService = app()->make(QueueServices::class);
        $queueService->setQueueData($where, 'id', $ids, $type);
        //加入队列
        BatchHandleJob::dispatch([false, $type]);
        return app('json')->success('后台程序已执行批量删除任务!');

    }

    /**
     * 删除订单
     * @param $id
     * @return mixed
     */
    public function del($id)
    {
        if (!$id || !($orderInfo = $this->services->get($id)))
            return app('json')->fail('订单不存在');
        if (!$orderInfo->is_del)
            return app('json')->fail('订单用户未删除无法删除');
        $orderInfo->is_system_del = 1;
        if ($orderInfo->save())
            return app('json')->success('SUCCESS');
        else
            return app('json')->fail('ERROR');
    }

    /**
     * 订单发送货
     * @param Request $request
     * @param StoreOrderDeliveryServices $services
     * @param $id
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function update_delivery(Request $request, StoreOrderDeliveryServices $services, $id)
    {
        $data = $request->postMore([
            ['type', 1],

            ['delivery_name', ''],//快递公司名称
            ['delivery_id', ''],//快递单号
            ['delivery_code', ''],//快递公司编码

            ['express_record_type', 2],//发货记录类型
            ['express_temp_id', ""],//电子面单模板
            ['to_name', ''],//寄件人姓名
            ['to_tel', ''],//寄件人电话
            ['to_addr', ''],//寄件人地址

            ['sh_delivery_name', ''],//送货人姓名
            ['sh_delivery_id', ''],//送货人电话
            ['sh_delivery_uid', ''],//送货人ID
            ['delivery_type', 1],//送货类型
            ['station_type', 1],//送货类型
            ['cargo_weight', 0],//重量
            ['mark', '', '', 'remark'],//管理员备注
            ['remark', '', '', 'delivery_remark'],//第三方配送备注

            ['fictitious_content', '']//虚拟发货内容
        ]);
        if (!$id) {
            return app('json')->fail('缺少发货ID');
        }
        $msg = $data['type'] == 2 ? '派单成功' : '发货成功';
        return app('json')->success($msg, $services->delivery((int)$id, $data));
    }

    /**
     * 订单拆单发送货
     * @param Request $request
     * @param StoreOrderDeliveryServices $services
     * @param $id
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function split_delivery(Request $request, StoreOrderDeliveryServices $services, $id)
    {
        $data = $request->postMore([
            ['type', 1],
            ['delivery_name', ''],//快递公司名称
            ['delivery_id', ''],//快递单号
            ['delivery_code', ''],//快递公司编码

            ['express_record_type', 2],//发货记录类型
            ['express_temp_id', ""],//电子面单模板
            ['to_name', ''],//寄件人姓名
            ['to_tel', ''],//寄件人电话
            ['to_addr', ''],//寄件人地址

            ['sh_delivery_name', ''],//送货人姓名
            ['sh_delivery_id', ''],//送货人电话
            ['sh_delivery_uid', ''],//送货人ID
            ['delivery_type', 1],//送货类型
            ['station_type', 1],//送货类型
            ['cargo_weight', 0],//重量
            ['mark', ''],//备注
            ['remark', ''],//配送备注

            ['fictitious_content', ''],//虚拟发货内容

            ['cart_ids', []]
        ]);
        if (!$id) {
            return app('json')->fail('缺少发货ID');
        }
        if (!$data['cart_ids']) {
            return app('json')->fail('请选择发货商品');
        }
        foreach ($data['cart_ids'] as $cart) {
            if (!isset($cart['cart_id']) || !$cart['cart_id'] || !isset($cart['cart_num']) || !$cart['cart_num']) {
                return app('json')->fail('请重新选择发货商品，或发货件数');
            }
        }
        $services->splitDelivery((int)$id, $data);
        return app('json')->success('SUCCESS');
    }

    /**
     * 获取订单可拆分发货商品列表
     * @param StoreOrderCartInfoServices $services
     * @param $id
     * @return mixed
     */
    public function split_cart_info(StoreOrderCartInfoServices $services, $id)
    {
        if (!$id) {
            return app('json')->fail('缺少发货ID');
        }
        return app('json')->success($services->getSplitCartList((int)$id));
    }

    /**
     * 获取核销订单商品列表
     * @param Request $request
     * @param WriteOffOrderServices $writeOffOrderServices
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function orderCartInfo(Request $request, WriteOffOrderServices $writeOffOrderServices)
    {
        [$oid] = $request->postMore([
            ['oid', '']
        ], true);
        return app('json')->success($writeOffOrderServices->getOrderCartInfo(0, (int)$oid));
    }

    /**
     * 分单核销订单
     * @param Request $request
     * @param WriteOffOrderServices $writeOffOrderServices
     * @param $order_id
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function wirteoff(Request $request, WriteOffOrderServices $writeOffOrderServices, $order_id)
    {
        $orderInfo = $this->services->getOne(['order_id' => $order_id, 'is_del' => 0], '*', ['pink']);
        if (!$orderInfo) {
            return app('json')->fail('核销订单未查到!');
        }
        [$cart_ids] = $request->postMore([
            ['cart_ids', []]
        ], true);
        if ($cart_ids) {
            foreach ($cart_ids as $cart) {
                if (!isset($cart['cart_id']) || !$cart['cart_id'] || !isset($cart['cart_num']) || !$cart['cart_num']) {
                    return $this->fail($orderInfo['type'] == 12 ? '您有待服务的预约单，请前往预约列表完成核销' : '请重新选择核销商品，或核销件数');
                }
            }
        }
        return app('json')->success('核销成功', $writeOffOrderServices->writeoffOrder(0, $orderInfo->toArray(), $cart_ids));
    }

    /**
     * 确认收货
     * @param Request $request
     * @param StoreOrderTakeServices $services
     * @param $id
     * @return Response
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function take_delivery(Request $request, StoreOrderTakeServices $services, $id)
    {
        if (!$id) return app('json')->fail('缺少参数');
        $order = $this->services->get($id);
        if (!$order)
            return app('json')->fail('Data does not exist!');
        if ($order['status'] == 2)
            return app('json')->fail('不能重复收货!');
        if (($order['paid'] == 1 && $order['status'] == 1) || $order['pay_type'] == 'offline') {
            $data['status'] = 2;
            $data['delivery_time'] = time();
        } else
            return app('json')->fail('请先发货或者送货!');

        if ($services->count(['pid' => $id])) {
            return app('json')->fail('该订单已拆分发货!');
        }

        if (!$this->services->update($id, $data)) {
            return app('json')->fail('收货失败,请稍候再试!');
        } else {
            $services->storeProductOrderUserTakeDelivery($order);
            //增加收货订单状态
            OrderStatusJob::dispatch([$order['id'], 'take_delivery', ['change_message' => '已收货', 'change_manager_type' => 'admin', 'change_manager_id' => $request->adminId()]]);
            return app('json')->success('收货成功');
        }
    }


    /**
     * 获取配置信息
     * @return mixed
     */
    public function getDeliveryInfo()
    {
        $data = SystemConfigService::more([
            'config_export_temp_id',
            'config_export_to_name',
            'config_export_id',
            'config_export_to_tel',
            'config_export_to_address',
            'config_export_open',
            'city_delivery_status',
            'self_delivery_status',
            'dada_delivery_status',
            'uu_delivery_status'
        ]);
        return app('json')->success([
            'express_temp_id' => $data['config_export_temp_id'] ?? '',
            'id' => $data['config_export_id'] ?? '',
            'to_name' => $data['config_export_to_name'] ?? '',
            'to_tel' => $data['config_export_to_tel'] ?? '',
            'to_add' => $data['config_export_to_address'] ?? '',
            'export_open' => (bool)((int)($data['config_export_open'] ?? 0)),
            'city_delivery_status' => $data['city_delivery_status'] && ($data['self_delivery_status'] || $data['dada_delivery_status'] || $data['uu_delivery_status']),
            'self_delivery_status' => $data['city_delivery_status'] && $data['self_delivery_status'],
            'dada_delivery_status' => $data['city_delivery_status'] && $data['dada_delivery_status'],
            'uu_delivery_status' => $data['city_delivery_status'] && $data['uu_delivery_status'],
        ]);
    }

    /**
     * 订单主动退款表单生成
     * @param StoreOrderRefundServices $services
     * @param $id
     * @return Response
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function refund(StoreOrderRefundServices $services, $id)
    {
        if (!$id) {
            return app('json')->fail('Data does not exist!');
        }
        return app('json')->success($services->refundOrderForm((int)$id, 'order'));
    }

    /**
     * 订单主动退款
     * @param Request $request
     * @param StoreOrderRefundServices $services
     * @param StoreOrderCartInfoServices $storeOrderCartInfoServices
     * @param $id
     * @return Response
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function update_refund(Request $request, StoreOrderRefundServices $services, StoreOrderCartInfoServices $storeOrderCartInfoServices, $id)
    {
        $type = (int)$request->post('type', 1);
        if ($type === 2) {
            if (!$id) {
                return $this->fail('Data does not exist!');
            }
            $order = $this->services->get($id);
            if (!$order) {
                return $this->fail('Data does not exist!');
            }
            $this->services->update((int)$order['id'], ['refund_status' => 0, 'refund_type' => 3]);
            return app('json')->successful('修改退款状态成功!');
        }
        return $this->terminal_order_refund($request, $id);
    }

    /**
     * 后台拆单退款
     * @param Request $request
     * @param StoreOrderRefundServices $services
     * @param StoreOrderCreateServices $storeOrderCreateServices
     * @param StoreOrderCartInfoServices $storeOrderCartInfoServices
     * @param $id
     * @return \think\Response
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function open_order_refund(Request $request, StoreOrderRefundServices $services, $id)
    {
        // 兼容旧路由：拒绝拆单/合并/部分退，整单退款走统一终态编排
        return $this->terminal_order_refund($request, $id);
    }

    /**
     * 整单退款（阶段 2）
     * POST order/:id/refund
     */
    public function terminal_order_refund(Request $request, $id)
    {
        $data = $request->postMore([
            ['refund_price', null],
            ['refund_amount', null],
            ['refund_ben', null],
            ['refund_give', null],
            ['type', 1],
            ['is_split_order', 0],
            ['cart_ids', []],
            ['refund_reason', ''],
            ['refund_explain', ''],
            ['refund_business_date', ''],
            ['request_token', ''],
            ['stock_in_type', '0'],
            ['merge_refund_id', 0],
            ['refund_num', ''],
            ['cart_num', ''],
            ['return_coupon', 1],
            ['bookkeeping_confirmed', 0],
            ['bookkeeping_remark', ''],
        ]);
        if (!$id) {
            return app('json')->fail('订单不存在，请核对后再操作。');
        }
        // 拒绝退款仍走旧状态（非整单退款执行）
        if ((int)$data['type'] === 2) {
            $order = $this->services->get($id);
            if (!$order) {
                return app('json')->fail('订单不存在，请核对后再操作。');
            }
            $this->services->update((int)$order['id'], ['refund_status' => 0, 'refund_type' => 3]);
            return app('json')->successful('修改退款状态成功!');
        }

        $changeManagerType = 'admin';
        $changeManagerId = (int)$request->adminId();
        $storeScope = 0;
        $sourceType = \app\model\order\StoreOrderTerminalOperation::SOURCE_ADMIN;
        if (!empty($request->storeStaffId)) {
            $changeManagerType = 'store';
            $changeManagerId = (int)$request->storeStaffId;
            $storeScope = (int)($request->storeId ?? 0);
            $sourceType = \app\model\order\StoreOrderTerminalOperation::SOURCE_STORE;
        }
        if (!empty($request->cashierId)) {
            $changeManagerType = 'cashier';
            $changeManagerId = (int)$request->cashierId;
            $storeScope = (int)($request->storeId ?? $storeScope);
            $sourceType = \app\model\order\StoreOrderTerminalOperation::SOURCE_CASHIER;
        }

        $raw = $request->post();
        $refundAmount = array_key_exists('refund_amount', $raw) ? $raw['refund_amount'] : null;
        $refundPrice = array_key_exists('refund_price', $raw) ? $raw['refund_price'] : null;
        $refundBen = array_key_exists('refund_ben', $raw) ? $raw['refund_ben'] : null;
        $refundGive = array_key_exists('refund_give', $raw) ? $raw['refund_give'] : null;
        $refundAmountProvided = array_key_exists('refund_amount', $raw) || array_key_exists('refund_price', $raw);
        $refundPriceProvided = array_key_exists('refund_price', $raw);

        try {
            /** @var \app\services\order\StoreOrderRefundDomainServices $domain */
            $domain = app()->make(\app\services\order\StoreOrderRefundDomainServices::class);
            $result = $domain->refundWholeOrder([
                'store_order_id' => (int)$id,
                'store_scope' => $storeScope,
                'request_token' => (string)$data['request_token'],
                'refund_amount' => $refundAmount !== null ? $refundAmount : $refundPrice,
                'refund_price' => $refundPrice,
                'refund_ben' => $refundBen,
                'refund_give' => $refundGive,
                'refund_amount_provided' => $refundAmountProvided,
                'refund_price_provided' => $refundPriceProvided,
                'refund_business_date' => (string)$data['refund_business_date'],
                'refund_reason' => (string)$data['refund_reason'],
                'refund_explain' => (string)$data['refund_explain'],
                'stock_in_type' => $data['stock_in_type'],
                'return_coupon' => $data['return_coupon'],
                'bookkeeping_confirmed' => $data['bookkeeping_confirmed'],
                'bookkeeping_remark' => $data['bookkeeping_remark'],
                'source_type' => $sourceType,
                'operator_type' => $changeManagerType,
                'operator_id' => $changeManagerId,
                // 旧拆单参数原样传入，由 assertWholeOrderOnly 明确拒绝
                'is_split_order' => $data['is_split_order'],
                'cart_ids' => $data['cart_ids'],
                'merge_refund_id' => $data['merge_refund_id'],
                'refund_num' => $data['refund_num'],
                'cart_num' => $data['cart_num'],
            ]);
            return app('json')->success($result['message'] ?? '退款成功', $result);
        } catch (\think\exception\ValidateException $e) {
            return app('json')->fail($e->getMessage());
        } catch (\Throwable $e) {
            return app('json')->fail('操作未成功，订单状态未改变，请核对后重试或联系负责人。');
        }
    }

    /**
     * 整单作废（阶段 3）
     * POST order/:id/void
     * 不计入退款统计；不写退款申请表；不对微信/支付宝/现金自动原路退。
     */
    public function terminal_order_void(Request $request, $id)
    {
        $data = $request->postMore([
            ['void_reason', ''],
            ['refund_reason', ''],
            ['reason', ''],
            ['request_token', ''],
            ['return_coupon', 1],
            ['stock_in_type', '0'],
            ['bookkeeping_confirmed', 0],
            ['bookkeeping_remark', ''],
        ]);
        if (!$id) {
            return app('json')->fail('订单不存在，请核对后再操作。');
        }

        $changeManagerType = 'admin';
        $changeManagerId = (int)$request->adminId();
        $storeScope = 0;
        $sourceType = \app\model\order\StoreOrderTerminalOperation::SOURCE_ADMIN;
        if (!empty($request->storeStaffId)) {
            $changeManagerType = 'store';
            $changeManagerId = (int)$request->storeStaffId;
            $storeScope = (int)($request->storeId ?? 0);
            $sourceType = \app\model\order\StoreOrderTerminalOperation::SOURCE_STORE;
        }
        if (!empty($request->cashierId)) {
            $changeManagerType = 'cashier';
            $changeManagerId = (int)$request->cashierId;
            $storeScope = (int)($request->storeId ?? $storeScope);
            $sourceType = \app\model\order\StoreOrderTerminalOperation::SOURCE_CASHIER;
        }

        $reason = trim((string)$data['void_reason']);
        if ($reason === '') {
            $reason = trim((string)$data['reason']);
        }
        if ($reason === '') {
            $reason = trim((string)$data['refund_reason']);
        }

        try {
            /** @var \app\services\order\StoreOrderVoidServices $voidServices */
            $voidServices = app()->make(\app\services\order\StoreOrderVoidServices::class);
            $result = $voidServices->voidWholeOrder([
                'store_order_id' => (int)$id,
                'store_scope' => $storeScope,
                'request_token' => (string)$data['request_token'],
                'reason' => $reason,
                'void_reason' => $reason,
                'return_coupon' => $data['return_coupon'],
                'stock_in_type' => (int)$data['stock_in_type'],
                'bookkeeping_confirmed' => $data['bookkeeping_confirmed'],
                'bookkeeping_remark' => $data['bookkeeping_remark'],
                'source_type' => $sourceType,
                'operator_type' => $changeManagerType,
                'operator_id' => $changeManagerId,
            ]);
            return app('json')->success($result['message'] ?? '作废成功', $result);
        } catch (\think\exception\ValidateException $e) {
            return app('json')->fail($e->getMessage());
        } catch (\Throwable $e) {
            return app('json')->fail('操作未成功，订单状态未改变，请核对后重试或联系负责人。');
        }
    }

    /**
     * 创建或获取重开草稿（阶段 4）
     * POST order/:id/reopen
     */
    public function terminal_order_reopen(Request $request, $id)
    {
        if (!$id) {
            return app('json')->fail('订单不存在，请核对后再操作。');
        }
        $operatorType = 'admin';
        $operatorId = (int)$request->adminId();
        $storeScope = 0;
        $sourceType = \app\model\order\StoreOrderTerminalOperation::SOURCE_ADMIN;
        if (!empty($request->storeStaffId)) {
            $operatorType = 'store';
            $operatorId = (int)$request->storeStaffId;
            $storeScope = (int)($request->storeId ?? 0);
            $sourceType = \app\model\order\StoreOrderTerminalOperation::SOURCE_STORE;
        }
        if (!empty($request->cashierId)) {
            $operatorType = 'cashier';
            $operatorId = (int)$request->cashierId;
            $storeScope = (int)($request->storeId ?? $storeScope);
            $sourceType = \app\model\order\StoreOrderTerminalOperation::SOURCE_CASHIER;
        }
        try {
            /** @var \app\services\order\StoreOrderReopenServices $reopen */
            $reopen = app()->make(\app\services\order\StoreOrderReopenServices::class);
            $result = $reopen->createOrGetDraft([
                'store_order_id' => (int)$id,
                'store_scope' => $storeScope,
                'operator_type' => $operatorType,
                'operator_id' => $operatorId,
                'source_type' => $sourceType,
            ]);
            return app('json')->success($result['created'] ? '已创建重开草稿' : '已返回现有重开草稿', $result);
        } catch (\think\exception\ValidateException $e) {
            return app('json')->fail($e->getMessage());
        } catch (\Throwable $e) {
            return app('json')->fail('重新开单未成功，请核对后重试或联系负责人。');
        }
    }

    /**
     * 收银台加载重开草稿（阶段 4）
     * GET order/reopen/:token
     */
    public function terminal_order_reopen_load(Request $request, $token)
    {
        $token = trim((string)$token);
        if ($token === '') {
            return app('json')->fail('重开草稿不存在或已失效，请重新发起。');
        }
        $storeScope = 0;
        $staffId = 0;
        if (!empty($request->storeStaffId) || !empty($request->cashierId)) {
            $storeScope = (int)($request->storeId ?? 0);
        }
        if (!empty($request->cashierId)) {
            $staffId = (int)$request->cashierId;
        } elseif (!empty($request->storeStaffId)) {
            $staffId = (int)$request->storeStaffId;
        }
        try {
            /** @var \app\services\order\StoreOrderReopenServices $reopen */
            $reopen = app()->make(\app\services\order\StoreOrderReopenServices::class);
            $result = $reopen->loadDraft($token, [
                'store_scope' => $storeScope,
                'staff_id' => $staffId,
            ]);
            return app('json')->success('重开草稿已加载', $result);
        } catch (\think\exception\ValidateException $e) {
            return app('json')->fail($e->getMessage());
        } catch (\Throwable $e) {
            return app('json')->fail('加载重开草稿未成功，请核对后重试或联系负责人。');
        }
    }

    /**
     * 撤销本次核销（阶段 3 明确入口；旧 postChexiao 仍可用）
     * PUT order/writeoff/:subOrderId/cancel
     */
    public function cancel_writeoff(Request $request, $subOrderId)
    {
        if (!$subOrderId) {
            return app('json')->fail('核销单据不存在，请核对后再操作。');
        }
        $remark = (string)$request->param('remarks', $request->param('remark', '撤销本次核销'));
        $storeScope = 0;
        if (!empty($request->storeStaffId) || !empty($request->cashierId)) {
            $storeScope = (int)($request->storeId ?? 0);
        }
        try {
            /** @var \app\services\order\StoreOrderWriteOffServices $writeOffServices */
            $writeOffServices = app()->make(\app\services\order\StoreOrderWriteOffServices::class);
            $writeOffServices->cancelWriteoff((int)$subOrderId, $remark !== '' ? $remark : '撤销本次核销', $storeScope);
            return app('json')->success('撤销本次核销成功');
        } catch (\think\exception\ValidateException $e) {
            return app('json')->fail($e->getMessage());
        } catch (\Throwable $e) {
            return app('json')->fail('撤销核销未成功，请核对后重试或联系负责人。');
        }
    }

    /**
     * 订单详情
     * @param $id
     * @return mixed
     */
    public function order_info($id)
    {
        if (!$id || !($orderInfo = $this->services->get($id, ['*'], ['invoice', 'virtual', 'pink', 'refund', 'supplierInfo']))) {
            return app('json')->fail('订单不存在');
        }
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
        $orderInfo = is_object($orderInfo) ? $orderInfo->toArray() : $orderInfo;
        $orderInfo = $this->services->tidyOrder($orderInfo, true, true);
        $_status = $orderInfo['_status'];
        [$pink_name, $color] = $this->services->tidyOrderType($orderInfo);
        $orderInfo['pink_name'] = $pink_name;
        $orderInfo['store_order_sn'] = $orderInfo['pid'] ? $this->services->value(['id' => $orderInfo['pid']], 'order_id') : '';
        //核算优惠金额
        $vipTruePrice = 0;
        $settlePrice = 0;
        foreach ($orderInfo['cartInfo'] ?? [] as $nk=>$cart) {
            if(isset($cart['productInfo']['pid'])) {
                if ($cart['productInfo']['id'] == 8154 || $cart['productInfo']['pid'] == 8154){
                    $orderInfo['cartInfo'][$nk]['productInfo']['attrInfo']['price'] = $orderInfo['pay_price'];
                    $orderInfo['cartInfo'][$nk]['pay_price'] = $orderInfo['pay_price'];
                    $orderInfo['cartInfo'][$nk]['truePrice'] = $orderInfo['pay_price'];
                    $orderInfo['cartInfo'][$nk]['sum_price'] = $orderInfo['pay_price'];
                }
            }
            //销售
            $staffs=StaffYeji::where("type",2)
                ->where("goods_id",$cart['product_id'])
                ->where("cart_id",$cart['id'])
                ->where("link_id",$id)
                ->column("staff_name");
            $orderInfo['cartInfo'][$nk]['yeji_staff']=implode(",",$staffs);
            $vipTruePrice = bcadd((string)$vipTruePrice, (string)$cart['vip_sum_truePrice'], 2);
            if (isset($cart['productInfo']['attrInfo'])) {
                $settle_price = $cart['productInfo']['attrInfo']['settle_price'] ?? 0;
            } else {
                $settle_price = $cart['productInfo']['settle_price'] ?? 0;
            }
            $settlePrice = bcadd((string)$settlePrice, bcmul((string)$cart['cart_num'], (string)$settle_price, 2), 2);
        }
        $orderInfo['vip_true_price'] = $vipTruePrice;
        $orderInfo['settlePrice'] = $settlePrice;
//        $orderInfo['total_price'] = floatval(bcsub((string)$orderInfo['total_price'], (string)$vipTruePrice, 2));
        //优惠活动优惠详情
        /** @var StoreOrderPromotionsServices $storeOrderPromotiosServices */
        $storeOrderPromotiosServices = app()->make(StoreOrderPromotionsServices::class);
        $orderInfo['promotions_detail'] = $storeOrderPromotiosServices->getOrderPromotionsDetail((int)$orderInfo['id']);
        if ($orderInfo['give_coupon'] || $orderInfo['coupon_id']) {
            $couponIds = is_string($orderInfo['give_coupon']) ? stringToIntArray($orderInfo['give_coupon']) : $orderInfo['give_coupon'];
            /** @var StoreCouponIssueServices $couponIssueService */
            $couponIssueService = app()->make(StoreCouponIssueServices::class);
            /** @var StoreCouponUserServices $couponUserService */
            $couponUserService = app()->make(StoreCouponUserServices::class);
            if ($orderInfo['coupon_id']) {
                $cid = $couponUserService->value(['id' => $orderInfo['coupon_id']], 'cid');
                $couponIds = $couponIds + [$cid];
            }
            $give_coupon = $couponIssueService->getColumn([['id', 'IN', $couponIds]], 'id,coupon_title,coupon_issue_type,relation_id');
            foreach ($give_coupon as $key => &$item) {
                $format = $couponIssueService->getFormat($item['coupon_issue_type'], $item['relation_id']);
                $item['author'] = $format['author'] ?? '';
                $item['author_image'] = $format['author_image'] ?? '';
            }
            $orderInfo['give_coupon'] = $give_coupon;
        }
        $orderInfo['_store_name'] = '';
        if ($orderInfo['store_id'] && in_array($orderInfo['shipping_type'], [2, 4])) {
            /** @var  $storeServices */
            $storeServices = app()->make(SystemStoreServices::class);
            $orderInfo['_store_name'] = $storeServices->value(['id' => $orderInfo['store_id']], 'name');
        }

        $orderInfo = $this->services->tidyOrderList([$orderInfo])[0];
        $orderInfo['_status_new'] = $orderInfo['_status'];
        $orderInfo['_status'] = $_status;
        $refund_num = array_sum(array_column($orderInfo['refund'], 'refund_num'));
        $cart_num = 0;
        foreach ($orderInfo['_info'] as $items) {
            if (isset($items['cart_info']['cart_type']) && $items['cart_info']['cart_type'] > 0) continue;
            $cart_num += $items['cart_info']['cart_num'];
        }
        if ($orderInfo['delivery_voucher_img']) {
            $orderInfo['delivery_voucher_img'] = json_decode($orderInfo['delivery_voucher_img']);
        }
        $orderInfo['is_all_refund'] = $refund_num == $cart_num;
        return app('json')->success(compact('orderInfo', 'userInfo'));
    }

    /**
     * 查询物流信息
     * @param ExpressServices $services
     * @param $id
     * @return mixed
     */
    public function get_express(ExpressServices $services, $id)
    {
        if (!$id || !($orderInfo = $this->services->get($id)))
            return app('json')->fail('订单不存在');
        if ($orderInfo['delivery_type'] != 'express')
            return app('json')->fail('该订单不是快递发货，无法查询物流信息');
        if (!$orderInfo['delivery_id'])
            return app('json')->fail('该订单不存在快递单号');

        $cacheName = $orderInfo['order_id'] . $orderInfo['delivery_id'];

        $data['delivery_name'] = $orderInfo['delivery_name'];
        $data['delivery_id'] = $orderInfo['delivery_id'];

        $delivery_id = $orderInfo['delivery_id'];
        if (!str_contains($orderInfo['delivery_id'], ':') && $orderInfo['user_phone'] && $orderInfo['delivery_code'] == 'shunfengkuaiyun') {
            $delivery_id = $orderInfo['delivery_id'] . ':' . substr($orderInfo['user_phone'], -4);
        }
        $data['result'] = $delivery_id ? $services->query($cacheName, $delivery_id, '', (string)$orderInfo['user_phone']) : [];
        return app('json')->success($data);
    }


    /**
     * 获取修改配送信息表单结构
     * @param StoreOrderDeliveryServices $services
     * @param $id
     * @return Response
     */
    public function distribution(StoreOrderDeliveryServices $services, $id)
    {
        if (!$id) {
            return app('json')->fail('订单不存在');
        }
        return app('json')->success($services->distributionForm((int)$id));
    }

    /**
     * 修改配送信息
     * @param StoreOrderDeliveryServices $services
     * @param ExpressServices $expressServices
     * @param $id
     * @return mixed
     */
    public function update_distribution(StoreOrderDeliveryServices $services, ExpressServices $expressServices, $id)
    {
        $data = $this->request->postMore([
            ['delivery_name', ''],
            ['delivery_id', ''],
            ['fictitious_content', ''],
        ]);
        if (!$id) return app('json')->fail('Data does not exist!');
        $orderInfo = $this->services->get((int)$id);
        if (!$orderInfo) {
            return app('json')->fail('订单不存在');
        }
        $express = [];
        if ($orderInfo['delivery_type'] == 'express') {
            $express = $expressServices->getOne(['name' => $data['delivery_name']], 'id,name,code');
            if (!$express) {
                return app('json')->fail('Data does not exist!');
            }
            $data['delivery_code'] = $express['code'];
        }

        $services->updateDistribution($id, $data);
        return app('json')->success('Modified success');
    }

    /**
     * 不退款表单结构
     * @param StoreOrderRefundServices $services
     * @param $id
     * @return Response
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function no_refund(StoreOrderRefundServices $services, $id)
    {
        if (!$id) return app('json')->fail('Data does not exist!');
        return app('json')->success($services->noRefundForm((int)$id));
    }

    /**
     * 订单不退款
     * @param StoreOrderRefundServices $services
     * @param $id
     * @return mixed
     */
    public function update_un_refund(StoreOrderRefundServices $services, $id)
    {
        if (!$id || !($orderRefundInfo = $services->get($id)))
            return app('json')->fail('订单不存在');
        [$refund_reason] = $this->request->postMore([['refund_reason', '']], true);
        if (!$refund_reason) {
            return app('json')->fail('请输入不退款原因');
        }
        $refundData = [
            'refuse_reason' => $refund_reason,
            'refund_type' => 3,
            'refunded_time' => time()
        ];
        //拒绝退款处理
        $services->refuseRefund((int)$id, $refundData, $orderRefundInfo);

        return app('json')->success('Modified success');
    }

    /**
     * 线下支付
     * @param OrderOfflineServices $services
     * @param $id
     * @return mixed
     */
    public function pay_offline(OrderOfflineServices $services, $id)
    {
        if (!$id) return app('json')->fail('缺少参数');
        $res = $services->orderOffline((int)$id);
        if ($res) {
            return app('json')->success('Modified success');
        } else {
            return app('json')->fail('Modification failed');
        }
    }

    /**
     * 退积分表单获取
     * @param StoreOrderRefundServices $services
     * @param $id
     * @return Response
     */
    public function refund_integral(StoreOrderRefundServices $services, $id)
    {
        return app('json')->success('退积分成功');
    }

    /**
     * 退积分保存
     * @param $id
     * @return mixed
     */
    public function update_refund_integral(StoreOrderRefundServices $services, $id)
    {
        return app('json')->success('退积分成功');
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
     * 获取订单状态列表并分页
     * @param $id
     * @return mixed
     */
    public function status(StoreOrderStatusServices $services, $id)
    {
        if (!$id) return app('json')->fail('缺少参数');
        return app('json')->success($services->getStatusList(['oid' => $id])['list']);
    }

    /**
     * 电子面单模板
     * @param $com
     * @return mixed
     */
    public function expr_temp(ServeServices $services, $com)
    {
        if (!$com) {
            return app('json')->fail('快递公司编号缺失');
        }
        $list = $services->express()->temp($com);
        return app('json')->success($list);
    }

    /**
     * 获取模板
     * @param ServeServices $services
     * @return mixed
     */
    public function express_temp(ServeServices $services)
    {
        $data = $this->request->getMore([['com', '']]);
        $tpd = $services->express()->temp($data['com']);
        return app('json')->success($tpd['data']);
    }

    /**
     * 订单发货后打印电子面单
     * @param $orderId
     * @param StoreOrderDeliveryServices $storeOrderDeliveryServices
     * @return mixed
     */
    public function order_dump($order_id, StoreOrderDeliveryServices $storeOrderDeliveryServices)
    {
        return app('json')->success($storeOrderDeliveryServices->orderDump($order_id));
    }

    /**
     * 手动批量发货
     * @param DownloadImageService $downloadImageService
     * @return Response
     * @throws \PhpOffice\PhpSpreadsheet\Reader\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function hand_batch_delivery(DownloadImageService $downloadImageService)
    {
        $data = $this->request->getMore([
            ['file', ""]
        ]);
        if (!$data['file']) return app('json')->fail('请上传文件');
        if (str_contains($data['file'], 'http')) {//现在文件到本地临时文件
            // 使用 cURL 获取内容
            $file = $data['file'];
            try {
                $downUrl = $downloadImageService->downloadImage($data['file'])['path'] ?? '';
                if ($downUrl) {
                    $file = public_path() . substr($downUrl, 1);
                }
            } catch (\Throwable $e) {

            }
        } else {
            $file = public_path() . substr($data['file'], 1);
        }
        $type = 7;//手动批量发货
        /** @var QueueServices $queueService */
        $queueService = app()->make(QueueServices::class);
        $expreData = $this->services->readExpreExcel($file, 2);
        $queueId = $queueService->setQueueData([], false, $expreData, $type);
        $data['queueType'] = $type;
        $data['cacheType'] = 3;
        $data['type'] = 1;
        $data['queueId'] = $queueId ? $queueId : 0;
        $this->services->adminQueueOrderDo($data);
        return app('json')->success('后台程序已执行批量发货任务!');
    }

    /**
     * 批量手动以外发货
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function other_batch_delivery()
    {
        $data = $this->request->postMore([
            ['where', []],
            ['ids', []],
            ['express_record_type', 1],
            ['type', 1],
            ['delivery_name', ''],//快递公司名称
            ['delivery_id', ''],//快递单号
            ['delivery_code', ''],//快递公司编码
            ['all', 0],//发货记录类型
            ['express_temp_id', ""],//电子面单模板
            ['to_name', ''],//寄件人姓名
            ['to_tel', ''],//寄件人电话
            ['to_addr', ''],//寄件人地址

            ['sh_delivery_name', ''],//送货人姓名
            ['sh_delivery_id', ''],//送货人电话
            ['sh_delivery_uid', ''],//送货人ID

            ['fictitious_content', '']//虚拟发货内容
        ]);
        if ($data['all'] == 0 && empty($data['ids'])) return app('json')->fail('请选择需要发货的订单');
        if ($data['express_record_type'] == 2 && !sys_config('config_export_open', 0)) return app('json')->fail('请先在设置->系统设置->第三方接口：电子面单中，打开单子面单打印开关');
        if ($data['all'] == 1) $data['ids'] = [];
        if ($data['type'] == 1) {//批量打印电子面单
            $data['queueType'] = 8;
            $data['cacheType'] = 4;
        }
        if ($data['type'] == 2) {//批量送货
            $data['queueType'] = 9;
            $data['cacheType'] = 5;
        }
        if ($data['type'] == 3) {//批量虚拟
            $data['queueType'] = 10;
            $data['cacheType'] = 6;
        }
        /** @var QueueServices $queueService */
        $queueService = app()->make(QueueServices::class);
        $queueId = $queueService->setQueueData($data['where'], 'id', $data['ids'], $data['queueType']);
        $data['queueId'] = $queueId ? $queueId : 0;
        /** @var StoreOrderDeliveryServices $deliveryService */
        $this->services->adminQueueOrderDo($data);
        return app('json')->success('后台程序已执行批量发货任务');
    }


    /**
     * 配货单信息
     * @return mixed
     */
    public function distributionInfo()
    {
        [$ids] = $this->request->postMore([
            ['ids', '']
        ], true);
        if (!$ids) {
            return app('json')->fail('缺少参数');
        }
        $id = explode(',', $ids);
        /** @var SupplierOrderServices $supplierOrderServices */
        $supplierOrderServices = app()->make(SupplierOrderServices::class);
        $data = $supplierOrderServices->getDistribution($id);
        $order = $data[0] ?? [];
        if (!$data || !$order) {
            return app('json')->fail('获取失败');
        }
        $res['list'] = $data;
        $station = [];
        if ($order['store_id']) {//门店
            /** @var SystemStoreServices $storeServices */
            $storeServices = app()->make(SystemStoreServices::class);
            $storeInfo = $storeServices->get($order['store_id']);
            $station['site_name'] = $storeInfo['name'];
            $station['refund_phone'] = $storeInfo['phone'];
            $station['refund_address'] = $storeInfo['address'] . $storeInfo['detailed_address'];
        } elseif ($order['supplier_id']) {//供应商
            /** @var SystemSupplierServices $supplierServices */
            $supplierServices = app()->make(SystemSupplierServices::class);
            $supplierIno = $supplierServices->get($order['supplier_id']);
            $station['site_name'] = $supplierIno['supplier_name'];
            $station['refund_phone'] = $supplierIno['phone'];
            $station['refund_address'] = $supplierIno['address'] . $supplierIno['detailed_address'];
        } else {//平台
            $station = SystemConfigService::more(['site_name', 'refund_address', 'refund_phone']);
        }
        $res = array_merge($res, $station);
        return app('json')->success($res);
    }

    /**
     * 获取次卡商品核销表单
     * @param WriteOffOrderServices $writeOffOrderServices
     * @param $id
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function writeOrderFrom(WriteOffOrderServices $writeOffOrderServices, $id)
    {
        if (!$id) {
            return $this->fail('缺少核销订单ID');
        }
        [$cart_num] = $this->request->getMore([
            ['cart_num', 1]
        ], true);
        return $this->success($writeOffOrderServices->writeOrderFrom((int)$id, (int)$cart_num));
    }

    /**
     * 次卡商品核销表单提交
     * @param WriteOffOrderServices $writeOffOrderServices
     * @param $id
     * @return \think\Response
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function writeoffFrom(WriteOffOrderServices $writeOffOrderServices, $id)
    {
        if (!$id) {
            return $this->fail('缺少核销订单ID');
        }
        $orderInfo = $this->services->getOne(['id' => $id, 'is_del' => 0], '*', ['pink']);
        if (!$orderInfo) {
            return $this->fail('核销订单未查到!');
        }
        $data = $this->request->postMore([
            ['cart_id', ''],//核销订单商品cart_id
            ['cart_num', 0]
        ]);
        $cart_ids[] = $data;
        if ($cart_ids) {
            foreach ($cart_ids as $cart) {
                if (!isset($cart['cart_id']) || !$cart['cart_id'] || !isset($cart['cart_num']) || !$cart['cart_num'] || $cart['cart_num'] <= 0) {
                    return $this->fail($orderInfo['type'] == 12 ? '您有待服务的预约单，请前往预约列表完成核销' : '请重新选择核销商品，或核销件数');
                }
            }
        }
        return app('json')->success('核销成功', $writeOffOrderServices->writeoffOrder(0, $orderInfo->toArray(), $cart_ids));
    }

    /**
     * 订单核销记录
     * @param Request $request
     * @param StoreOrderWriteOffServices $services
     * @return \think\Response
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function writeOffRecords(Request $request, StoreOrderWriteOffServices $services, $id)
    {
        if (!$id) return app('json')->fail('参数错误');
        return app('json')->successful($services->getOrderWriteOffRecords(['oid' => $id]));
    }

    public function sendDetail(Request $request, StoreOrderServices $services, $id)
    {
        if (!$id) return app('json')->fail('参数错误');
        return app('json')->successful($services->sendDetail($id));
    }
    /**
     * 核销记录--平台  展示所有核销记录
     * @param Request $request
     * @param StoreOrderWriteOffServices $services
     * @return Response
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getWriteOffRecords(Request $request, StoreOrderWriteOffServices $services)
    {
        $where = $this->request->postMore([
            ['order_id', '', '', 'keyword'], //订单编号（请求里字段名仍为 order_id）
            ['ordering_store_id', ''], //下单门店
            ['write_off_store_id', ''], //核销门店
            ['service_type', ''], //核销身份
            ['staff', ''], //核销店员
            ['user_key', ''], //客户资料
            ['product_name', ''], //商品名称
            ['yeji_staff', ''], //手艺人（staff_yeji type=3 + link_id）
            ['data', '', '', 'time'], //核销时间（组装进 $where['time']）
        ]);
        // postMore 已接收上述字段；旧前端或其它客户端若未走表单字段名时，再从 param 兜底一次
        $pn = trim((string)($where['product_name'] ?? ''));
        $where['product_name'] = $pn !== '' ? $pn : trim((string)$this->request->param('product_name', ''));
        $yj = trim((string)($where['yeji_staff'] ?? ''));
        $where['yeji_staff'] = $yj !== '' ? $yj : trim((string)$this->request->param('yeji_staff', ''));

        return app('json')->successful($services->getAllWriteOffRecords($where));
    }

    /**
     * 获取卡项权益
     * @param StoreOrderCartInfoServices $cartInfoService
     * @param $id
     * @return Response
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getCardBenefits(Request $request, StoreOrderCartInfoServices $cartInfoService, $id)
    {
        if (!$id) {
            return app('json')->fail('参数错误');
        }
        return app('json')->successful($cartInfoService->getOrderCardRelatedCartInfo((int)$id));
    }

    /**
     * 后台退款信息
     * @param UserCardHolderServices $holderService
     * @param $id
     * @return Response
     */
    public function cardBenefits(UserCardHolderServices $holderService, $id)
    {
        if (!$id) {
            return app('json')->fail('参数错误');
        }
        return app('json')->successful($holderService->cardBenefitsData((int)$id));
    }

    /**
     * 未发货订单修改收货地址
     * @param $id
     * @return Response
     */
    public function editAddress($id)
    {
        if (!$id) return app('json')->fail('缺少参数');
        $data = $this->request->postMore([
            ['real_name', ''],//用户昵称
            ['user_phone', ''],//电话
            ['user_address', ''],//收货地址
        ]);
        $info = $this->services->get($id);
        if (!$info) {
            return app('json')->fail('订单不存在');
        }
        if ($info['status'] != 0) {
            return app('json')->fail('订单已发货');
        }
        $this->services->update($id, $data);
        OrderStatusJob::dispatch([$id, 'edit_address', ['change_message' => '修改收货地址', 'change_manager_id' => $this->request->adminId(), 'change_manager_type' => 'admin']]);
        return app('json')->success('修改成功');
    }

    /**
     * 订单改派
     * @param Request $request
     * @param StoreOrderDeliveryServices $services
     * @param $id
     * @return Response
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function reassign_delivery(Request $request, StoreOrderDeliveryServices $services, $id)
    {
        $data = $request->postMore([
            ['sh_delivery_name', ''],//送货人姓名
            ['sh_delivery_id', ''],//送货人电话
            ['sh_delivery_uid', ''],//送货人ID
        ]);
        if (!$id) {
            return app('json')->fail('缺少发货ID');
        }
        return app('json')->success('改派成功', $services->reassign((int)$id, $data));
    }

    /**
     * 配送员确认送达
     * @param Request $request
     * @param StoreOrderTakeServices $services
     * @return Response
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function confirm_delivery(Request $request, StoreOrderTakeServices $services, DeliveryServiceServices $deliveryServices)
    {
        $where = $request->postMore([
            ['order_id', ''],
            ['delivery_voucher', ''],
            ['delivery_voucher_img', []],
        ]);
        if (!$where['order_id']) return app('json')->fail('缺少参数');
        $order = $this->services->get(['order_id' => $where['order_id']]);
        if (!$order) return app('json')->fail('Data does not exist!');
        if ($order['status'] == 2) return app('json')->fail('订单已完成!');
        $delivery = $deliveryServices->get(['phone' => $order['delivery_id'], 'uid' => $order['delivery_uid']]);
        if (!$delivery) return app('json')->fail('Data does not exist!');
//        if ($order['delivery_id'] != $delivery['phone'] || $order['delivery_uid'] != $delivery['uid']) return app('json')->fail('设置配送员不匹配');
        $data['status'] = 2;
        $data['delivery_time'] = time();
        $data['delivery_voucher'] = $where['delivery_voucher'];
        $data['delivery_voucher_img'] = json_encode($where['delivery_voucher_img']);
        if (!$this->services->update(['order_id' => $where['order_id']], $data)) {
            return app('json')->fail('送达失败,请稍候再试!');
        } else {
            $res = $services->storeProductOrderUserTakeDelivery($order);
            if ($res) {
                /** @var StoreDeliveryOrderServices $storeDeliverOrderServices */
                $storeDeliverOrderServices = app()->make(StoreDeliveryOrderServices::class);
                $storeDeliverOrderServices->update(['oid' => $order['id'], 'type' => 0, 'station_type' => 0], ['status' => 4]);
            }
            //增加收货订单状态
            OrderStatusJob::dispatch([$order['id'], 'confirm_delivery', ['change_message' => '确认送达', 'change_manager_type' => 'delivery', 'change_manager_id' => $delivery['id']]]);
            return app('json')->success('送达成功');
        }
    }

    /**
     * 管理员操作重新发单
     * @param $id
     * @return Response
     */
    public function adminReissueOrder($id)
    {
        [$store_id] = $this->request->getMore([
            ['store_id', 0]
        ], true);
        if (!$id) return app('json')->fail('缺少参数');
        /** @var StoreOrderDeliveryServices $orderDeliveryServices */
        $orderDeliveryServices = app()->make(StoreOrderDeliveryServices::class);
        $res = $orderDeliveryServices->reissueOrder((int)$id, (int)$store_id);
        if ($res) {
            return app('json')->success('发单成功');
        } else {
            return app('json')->fail('发单失败');
        }
    }
}
