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


use app\jobs\order\OrderStatusJob;
use app\jobs\system\SocketPushJob;
use app\services\BaseServices;
use app\dao\order\StoreOrderDao;
use app\services\message\notice\NoticeSmsService;
use app\services\store\DeliveryServiceServices;
use app\services\store\finance\StoreFinanceFlowServices;
use app\services\serve\ServeServices;
use app\services\store\SystemStoreServices;
use mohe\services\CacheService;
use think\exception\ValidateException;
use mohe\services\FormBuilder as Form;
use app\services\other\ExpressServices;
use mohe\traits\OptionTrait;
use think\facade\Log;

/**
 * 订单发货
 * Class StoreOrderDeliveryServices
 * @package app\services\order
 * @mixin StoreOrderDao
 */
class StoreOrderDeliveryServices extends BaseServices
{
    use OptionTrait;

    /**
     * 构造方法
     * StoreOrderDeliveryServices constructor.
     * @param StoreOrderDao $dao
     */
    public function __construct(StoreOrderDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 订单整体发货
     * @param int $id
     * @param array $data
     * @param int $staff_id
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function delivery(int $id, array $data, int $staff_id = 0)
    {
        $orderInfo = $this->dao->get($id, ['*'], ['pink']);
        if (!$orderInfo) {
            throw new ValidateException('订单未能查到,不能发货!');
        }
        if ($orderInfo['paid'] != 1) {
            throw new ValidateException('订单未支付');
        }
        if ($orderInfo->is_del) {
            throw new ValidateException('订单已删除,不能发货!');
        }
        if ($orderInfo->status == 1) {
            throw new ValidateException('订单已发货请勿重复操作!');
        }
        if ($orderInfo->shipping_type == 2) {
            throw new ValidateException('核销订单不能发货!');
        }
        if (isset($orderInfo['pinkStatus']) && $orderInfo['pinkStatus'] != 2) {
            throw new ValidateException('拼团未完成暂不能发货!');
        }
        $store_id = $this->getItem('store_id', 0);
        $supplier_id = $this->getItem('supplier_id', 0);

        //拆分完整主订单查询未发货子订单
        if ($orderInfo['pid'] == -1) {
            $orderInfo = $this->dao->get(['pid' => $id, 'status' => 0, 'supplier_id' => $supplier_id, 'store_id' => $store_id, 'refund_type' => [0, 3]], ['*'], ['pink']);
            if (!$orderInfo) {
                throw new ValidateException('订单未能查到,请在详情发货列表确认后发货');
            }
            $id = (int)$orderInfo['id'];
        }
        /** @var StoreOrderRefundServices $storeOrderRefundServices */
        $storeOrderRefundServices = app()->make(StoreOrderRefundServices::class);
        if ($storeOrderRefundServices->count(['store_order_id' => $id, 'refund_type' => [0, 1, 2, 4, 5, 6], 'is_cancel' => 0, 'is_del' => 0])) {
            throw new ValidateException('订单有售后申请请先处理');
        }
        //预售订单 验证预售活动是否结束
//        if ($orderInfo['type'] == 6) {
//            /** @var StoreOrderCartInfoServices $storeOrderCartInfoServices */
//            $storeOrderCartInfoServices = app()->make(StoreOrderCartInfoServices::class);
//            $cartInfo = $storeOrderCartInfoServices->getOrderCartInfo($id);
//            $time = time();
//            foreach ($cartInfo as $cart) {
//                if (isset($cart['cart_info']['productInfo']['presale_end_time']) && $cart['cart_info']['productInfo']['presale_end_time'] > $time) {
//                    throw new ValidateException('预售活动暂未结束，请稍后发货');
//                }
//            }
//        }

        $dump = $this->doDelivery($id, $orderInfo, $data);
        //流水关联店员
        /** @var StoreFinanceFlowServices $storeFinanceFlow */
        $storeFinanceFlow = app()->make(StoreFinanceFlowServices::class);
        if ($staff_id && !$orderInfo['staff_id']) {
            $this->dao->update($id, ['staff_id' => $staff_id]);
            $storeFinanceFlow->setStaff($orderInfo['order_id'], $staff_id);
        } elseif ($orderInfo['staff_id']) {
            $storeFinanceFlow->setStaff($orderInfo['order_id'], $orderInfo['staff_id']);
        }
        return compact('orderInfo', 'dump');
    }

    /**
     * 重新发单
     * @param int $id
     * @param int $store_id
     * @return bool
     */
    public function reissueOrder(int $id = 0, int $store_id = 0)
    {
        try {
            /** @var SystemStoreServices $systemStoreServices */
            $systemStoreServices = app()->make(SystemStoreServices::class);
            /** @var StoreOrderCartInfoServices $orderInfoServices */
            $orderInfoServices = app()->make(StoreOrderCartInfoServices::class);
            $systemStore = $systemStoreServices->get($store_id, ['id', 'city_delivery_type']);
            if ($systemStore && $systemStore['city_delivery_type'] > 0) {
                $cargo_weight = $orderInfoServices->getCarIdByProductCargoWeight($id);
                $data['type'] = 2;
                $data['delivery_type'] = 2;
                $data['sh_delivery_name'] = '';
                $data['sh_delivery_id'] = '';
                $data['sh_delivery_uid'] = '';
                $data['station_type'] = $systemStore['city_delivery_type'];
                $data['cargo_weight'] = $cargo_weight > 0 ? $cargo_weight : 0.1;
                $this->delivery($id, $data);
            } else {
                throw new ValidateException('信息有误，请检查同城配送设置');
            }
            return true;
        } catch (\Throwable $e) {
            Log::error('发单失败，原因：' . $e->getMessage());
            throw new ValidateException($e->getMessage());
        }
    }

    /**
     * 订单拆单发货
     * @param int $id
     * @param array $data
     * @param int $staff_id
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function splitDelivery(int $id, array $data, int $staff_id = 0)
    {
        $orderInfo = $this->dao->get($id, ['*'], ['pink']);
        if (!$orderInfo) {
            throw new ValidateException('订单未能查到,不能发货!');
        }
        if ($orderInfo['paid'] != 1) {
            throw new ValidateException('订单未支付');
        }
        if ($orderInfo->is_del) {
            throw new ValidateException('订单已删除,不能发货!');
        }
        if ($orderInfo->shipping_type == 2) {
            throw new ValidateException('核销订单不能发货!');
        }
        if (isset($orderInfo['pinkStatus']) && $orderInfo['pinkStatus'] != 2) {
            throw new ValidateException('拼团未完成暂不能发货!');
        }
        $store_id = $this->getItem('store_id', 0);
        $supplier_id = $this->getItem('supplier_id', 0);

        //拆分完整主订单查询未发货子订单
        if ($orderInfo['pid'] == -1) {
            $orderInfo = $this->dao->get(['pid' => $id, 'status' => 0, 'supplier_id' => $supplier_id, 'store_id' => $store_id, 'refund_type' => [0, 3]], ['*'], ['pink']);
            if (!$orderInfo) {
                throw new ValidateException('订单未能查到,不能发货!');
            }
            $id = (int)$orderInfo['id'];
        }
        /** @var StoreOrderRefundServices $storeOrderRefundServices */
        $storeOrderRefundServices = app()->make(StoreOrderRefundServices::class);
        if ($storeOrderRefundServices->count(['store_order_id' => $id, 'refund_type' => [0, 1, 2, 4, 5, 6], 'is_cancel' => 0, 'is_del' => 0])) {
            throw new ValidateException('订单有售后申请请先处理');
        }
        //预售订单 验证预售活动是否结束
//        if ($orderInfo['type'] == 6) {
//            /** @var StoreOrderCartInfoServices $storeOrderCartInfoServices */
//            $storeOrderCartInfoServices = app()->make(StoreOrderCartInfoServices::class);
//            $cartInfo = $storeOrderCartInfoServices->getOrderCartInfo($id);
//            $time = time();
//            foreach ($cartInfo as $cart) {
//                if (isset($cart['cart_info']['productInfo']['presale_end_time']) && $cart['cart_info']['productInfo']['presale_end_time'] > $time) {
//                    throw new ValidateException('预售活动暂未结束，请稍后发货');
//                }
//            }
//        }

        $cart_ids = $data['cart_ids'];
        /** @var StoreOrderCartInfoServices $storeOrderCartInfoServices */
        $storeOrderCartInfoServices = app()->make(StoreOrderCartInfoServices::class);
        //检测选择商品是否还可拆分
        $storeOrderCartInfoServices->checkCartIdsIsSplit($id, $cart_ids);
        unset($data['cart_ids']);
        return $this->transaction(function () use ($id, $cart_ids, $orderInfo, $data, $storeOrderCartInfoServices, $staff_id) {
            /** @var StoreOrderSplitServices $storeOrderSplitServices */
            $storeOrderSplitServices = app()->make(StoreOrderSplitServices::class);
            //拆单
            $splitResult = $storeOrderSplitServices->equalSplit($id, $cart_ids);

            if ($splitResult) {//拆分发货
                [$orderInfo, $otherOrder] = $splitResult;

                //拆分订单执行发货
                $this->doDelivery((int)$orderInfo->id, $orderInfo, $data);
                //检测原订单商品是否 全部拆分发货完成  改原订单状态
                if (!$storeOrderCartInfoServices->getSplitCartList($id)) {//发货完成
                    $change_type = 'delivery_split';
                    $change_message = '已拆分发货';
                } else {
                    $change_type = 'delivery_part_split';
                    $change_message = '已拆分部分发货';
                }
                //记录原订单状态
                OrderStatusJob::dispatch([$id, $change_type, ['change_message' => $change_message]]);
                //流水关联店员
                /** @var StoreFinanceFlowServices $storeFinanceFlow */
                $storeFinanceFlow = app()->make(StoreFinanceFlowServices::class);
                if ($staff_id && !$orderInfo['staff_id']) {
                    $this->dao->update($orderInfo['id'], ['staff_id' => $staff_id]);
                    $storeFinanceFlow->setStaff($orderInfo['order_id'], $staff_id);
                } elseif ($orderInfo['staff_id']) {
                    $storeFinanceFlow->setStaff($orderInfo['order_id'], $orderInfo['staff_id']);
                }
            } else {//整体发货
                $this->delivery($id, $data, $staff_id);
            }
            return $splitResult;
        });
    }

    /**
     * 具体执行发货
     * @param int $id
     * @param $orderInfo
     * @param array $data
     * @return bool
     */
    public function doDelivery(int $id, $orderInfo, array $data)
    {
        $type = (int)$data['type'];
        unset($data['type']);
        //获取购物车内的商品标题
        /** @var StoreOrderCartInfoServices $orderInfoServices */
        $orderInfoServices = app()->make(StoreOrderCartInfoServices::class);
        $storeName = $orderInfoServices->getCarIdByProductTitle((int)$orderInfo['id']);
        //修改返回数据
        $res = [];
        switch ($type) {
            case 1://快递发货
                $res = $this->orderDeliverGoods($id, $data, $orderInfo, $storeName);
                if (isset($res['kuaidinum']) && $res['kuaidinum']) {
                    $data['delivery_id'] = $res['kuaidinum'];
                }
                break;
            case 2://配送
                $data['delivery_name'] = $data['sh_delivery_name'];
                $data['delivery_id'] = $data['sh_delivery_id'];
                $data['delivery_uid'] = $data['sh_delivery_uid'];
                $this->orderDelivery($id, $data, $orderInfo);
                break;
            case 3://虚拟发货
                $this->dao->update($id, ['delivery_type' => 'fictitious', 'fictitious_content' => $data['fictitious_content'] ?? '', 'status' => 1, 'shipping_time' => time()]);
                //记录主订单状态
                OrderStatusJob::dispatch([$id, 'delivery_fictitious', ['change_message' => '已虚拟发货']]);
                break;
            case 4://门店收银订单自动发货
                $this->dao->update($orderInfo['id'], ['delivery_type' => 'cashier', 'status' => 1, 'shipping_time' => time()]);
                //记录原订单状态
                OrderStatusJob::dispatch([$orderInfo['id'], 'delivery_cashier', ['change_message' => '门店收银台核销', 'change_manager_type' => 'system']]);
                break;
            default:
                throw new ValidateException('暂时不支持其他发货类型');
        }
        //跟新完成后重新赋值订单信息
        $orderInfo = $this->dao->get($id, ['*'], ['pink']);
        event('order.delivery', [$orderInfo, $storeName, $data, $type]);
        return $res;
    }


    /**
     * 订单快递发货
     * @param int $id
     * @param array $data
     */
    public function orderDeliverGoods(int $id, array $data, $orderInfo, $storeTitle)
    {
        if (!$data['delivery_name']) {
            throw new ValidateException('请选择快递公司');
        }
        //修改
        $dump = [];
        $data['delivery_type'] = 'express';
        if ($data['express_record_type'] == 2) {//电子面单
            if (!sys_config('config_export_open', 0)) {
                throw new ValidateException('系统通知：电子面单已关闭，请选择其他发货方式！');
            }
            if (!$data['delivery_code']) {
                throw new ValidateException('快递公司编缺失');
            }
            if (!$data['express_temp_id']) {
                throw new ValidateException('请选择电子面单模板');
            }
            if (!$data['to_name']) {
                throw new ValidateException('请填写寄件人姓名');
            }
            if (!$data['to_tel']) {
                throw new ValidateException('请填写寄件人电话');
            }
            if (!$data['to_addr']) {
                throw new ValidateException('请填写寄件人地址');
            }
            $expData['com'] = $data['delivery_code'];
            $expData['to_name'] = $orderInfo['real_name'];
            $expData['to_tel'] = $orderInfo['user_phone'];
            $expData['to_addr'] = $orderInfo['user_address'];
            $expData['from_name'] = $data['to_name'];
            $expData['from_tel'] = $data['to_tel'];
            $expData['from_addr'] = $data['to_addr'];
            $expData['siid'] = sys_config('config_export_siid');
            $expData['temp_id'] = $data['express_temp_id'];
            $expData['count'] = $orderInfo['total_num'];
            $expData['weight'] = $this->getOrderSumWeight($id);
            /** @var StoreOrderCartInfoServices $orderInfoServices */
            $orderInfoServices = app()->make(StoreOrderCartInfoServices::class);
            $expData['cargo'] = $orderInfoServices->getCarIdByProductTitle((int)$orderInfo['id'], true);
            $expData['order_id'] = $orderInfo['order_id'];
            /** @var ServeServices $expressService */
            $expressService = app()->make(ServeServices::class);
            $dump = $expressService->express()->dump($expData);
            $data['express_dump'] = json_encode([
                'com' => $expData['com'],
                'from_name' => $expData['from_name'],
                'from_tel' => $expData['from_tel'],
                'from_addr' => $expData['from_addr'],
                'temp_id' => $expData['temp_id'],
                'cargo' => $expData['cargo'],
            ]);
            $data['delivery_id'] = $dump['kuaidinum'] ?? '';
            $orderInfo->delivery_id = $data['delivery_id'];
            //修改快递面单图片写入数据库
            if (!empty($dump['label'])) {
                $data['kuaidi_label'] = $dump['label'];
            }
        } else {
            if (!$data['delivery_id']) {
                throw new ValidateException('请输入快递单号');
            }
            $orderInfo->delivery_id = $data['delivery_id'];
        }
        $data['status'] = 1;
        $data['shipping_time'] = time();
        unset($data['delivery_remark']);
        $res = $this->dao->update($id, $data);
        if (!$res) {
            throw new ValidateException('发货失败：数据保存不成功');
        }
        //记录主订单状态
        OrderStatusJob::dispatch([$id, 'delivery_goods', ['change_message' => '已发货 快递公司：' . $data['delivery_name'] . ' 快递单号：' . $data['delivery_id']]]);
        return $dump;
    }


    /**
     * 订单配送
     * @param int $id
     * @param array $data
     * @return bool
     */
    public function orderDelivery(int $id, array $data, $orderInfo = [])
    {
        $delivery_type = $data['delivery_type'] ?? 1;
        $delivery_type = $delivery_type ? $delivery_type : 1;
        $station_type = $data['station_type'] ?? 1;
        unset($data['delivery_type'], $data['station_type']);
        switch ($delivery_type) {
            case 1://自己配送
                if (!$data['delivery_name']) {
                    throw new ValidateException('请输入送货人姓名');
                }
                if (!$data['delivery_id']) {
                    throw new ValidateException('请输入送货人电话号码');
                }
                if (!$data['delivery_uid']) {
                    throw new ValidateException('请输入送货人信息');
                }
                if (!check_phone($data['delivery_id'])) {
                    throw new ValidateException('请输入正确的送货人电话号码');
                }
                //获取核销码
                /** @var StoreOrderCreateServices $storeOrderCreateService */
                $storeOrderCreateService = app()->make(StoreOrderCreateServices::class);
                $data['verify_code'] = $storeOrderCreateService->getStoreCode();
                $data['delivery_type'] = 'send';
                $station_type = 0;
                break;
            case 2://第三方配送
                if (!isset($data['cargo_weight']) || !$data['cargo_weight']) {
                    throw new ValidateException('请填写配送商品重量');
                }
                $data['delivery_type'] = $station_type == 1 ? 'city_delivery_dd' : 'city_delivery_uu';
                break;
        }
        /** @var StoreDeliveryOrderServices $storeDeliverOrderServices */
        $storeDeliverOrderServices = app()->make(StoreDeliveryOrderServices::class);
        $storeDeliverOrderServices->create($id, $data, $station_type, $orderInfo);
        $data['status'] = 1;
        $data['shipping_time'] = time();
        $city_delivery = $data['delivery_type'];
        unset($data['sh_delivery_name'], $data['sh_delivery_id'], $data['sh_delivery_uid'], $data['delivery_remark']);

        //修改订单发货信息
        $this->dao->update($id, $data);
        $delivery_id = 0;
        if ($delivery_type == 1) {
            /** @var DeliveryServiceServices $deliverServiceServices */
            $deliverServiceServices = app()->make(DeliveryServiceServices::class);
            $staffInfo = $deliverServiceServices->get(['uid' => $data['delivery_uid']], ['id', 'nickname']);
            $delivery_id = $staffInfo['id'];
            $message = '已配送 发货人：' . $data['delivery_name'] . ' 发货人电话：' . $data['delivery_id'];
        } else {
            $message = '已' . ($station_type == 1 ? '达达' : 'UU跑腿') . '同城配送';
        }
        //记录主订单状态
        OrderStatusJob::dispatch([$id, $delivery_type == 1 ? 'delivery' : $city_delivery, ['change_message' => $message, 'change_mange_type' => 'delivery', 'change_manager_id' => $delivery_id]]);
        return true;
    }


    /**
     * 获取修改配送信息表单结构
     * @param int $id
     * @return array
     */
    public function distributionForm(int $id)
    {
        if (!$orderInfo = $this->dao->get($id))
            throw new ValidateException('订单不存在');

        $f[] = Form::input('order_id', '订单号：', $orderInfo->getData('order_id'))->disabled(1);

        switch ($orderInfo['delivery_type']) {
            case 'send':
                $f[] = Form::input('delivery_name', '送货人姓名：', $orderInfo->getData('delivery_name'))->required('请输入送货人姓名');
                $f[] = Form::input('delivery_id', '送货人电话：', $orderInfo->getData('delivery_id'))->required('请输入送货人电话');
                break;
            case 'express':
                /** @var ExpressServices $expressServices */
                $expressServices = app()->make(ExpressServices::class);
                $f[] = Form::select('delivery_name', '快递公司：', (string)$orderInfo->getData('delivery_name'))->setOptions(array_map(function ($item) {
                    $item['value'] = $item['label'];
                    return $item;
                }, $expressServices->expressSelectForm(['is_show' => 1])))->filterable(true)->required('请选择快递公司');
                $f[] = Form::input('delivery_id', '快递单号：', $orderInfo->getData('delivery_id'))->required('请填写快递单号');
                break;
            case 'fictitious'://虚拟发货
                $f[] = Form::input('fictitious_content', '备注：', $orderInfo->getData('fictitious_content'));
                break;
        }
        return create_form('配送信息', $f, $this->url('/order/distribution/' . $id), 'PUT');
    }

    /**
     * 修改配送信息
     * @param int $id 订单id
     * @return mixed
     */
    public function updateDistribution(int $id, array $data)
    {
        $order = $this->dao->get($id);
        if (!$order) {
            throw new ValidateException('数据不存在！');
        }
        switch ($order['delivery_type']) {
            case 'send':
                if (!$data['delivery_name']) {
                    throw new ValidateException('请输入送货人姓名');
                }
                if (!$data['delivery_id']) {
                    throw new ValidateException('请输入送货人电话号码');
                }
                if (!check_phone($data['delivery_id'])) {
                    throw new ValidateException('请输入正确的送货人电话号码');
                }
                break;
            case 'express':
                if (!$data['delivery_name']) {
                    throw new ValidateException('请选择快递公司');
                }
                if (!$data['delivery_id']) {
                    throw new ValidateException('请输入快递单号');
                }
                break;
            case 'fictitious':
                break;
            default:
                throw new ValidateException('未发货，请先发货再修改配送信息');
                break;
        }
        $this->dao->update($id, $data);
        //记录主订单状态
        OrderStatusJob::dispatch([$id, 'distribution', ['change_message' => '修改发货信息为' . $data['delivery_name'] . '号' . $data['delivery_id']]]);
        return true;
    }

    /**
     * 订单发货后打印电子面单
     * @param $orderId
     * @return bool|mixed
     */
    public function orderDump($orderId)
    {
        if (!$orderId) throw new ValidateException('订单号缺失');
        /** @var StoreOrderServices $orderService */
        $orderService = app()->make(StoreOrderServices::class);
        $orderInfo = $orderService->getOne(['id' => $orderId]);
        if (!$orderInfo) throw new ValidateException('订单不存在');
        if (in_array($orderInfo->shipping_type, [2, 4])) throw new ValidateException('订单无法打印');
        if (!$orderInfo->express_dump) throw new ValidateException('请先发货');
        if (!sys_config('config_export_open', 0)) {
            throw new ValidateException('请先在设置->系统设置->第三方接口：电子面单中，打开单子面单打印开关');
        }
        $dumpInfo = json_decode($orderInfo->express_dump, true);
        /** @var ServeServices $expressService */
        $expressService = app()->make(ServeServices::class);
        $expData['com'] = $dumpInfo['com'];
        $expData['to_name'] = $orderInfo->real_name;
        $expData['to_tel'] = $orderInfo->user_phone;
        $expData['to_addr'] = $orderInfo->user_address;
        $expData['from_name'] = $dumpInfo['from_name'];
        $expData['from_tel'] = $dumpInfo['from_tel'];
        $expData['from_addr'] = $dumpInfo['from_addr'];
        $expData['siid'] = sys_config('config_export_siid');
        $expData['temp_id'] = $dumpInfo['temp_id'];
        $expData['cargo'] = $dumpInfo['cargo'];
        $expData['count'] = $orderInfo->total_num;
        $expData['weight'] = $this->getOrderSumWeight((int)$orderId);
        $expData['order_id'] = $orderInfo->order_id;
        try {
            $dump = $expressService->express()->dump($expData);
            $data['express_dump'] = json_encode([
                'com' => $expData['com'],
                'from_name' => $expData['from_name'],
                'from_tel' => $expData['from_tel'],
                'from_addr' => $expData['from_addr'],
                'temp_id' => $expData['temp_id'],
                'cargo' => $expData['cargo'],
            ]);
            $data['delivery_id'] = $dump['kuaidinum'] ?? '';
            //修改快递面单图片写入数据库
            if (!empty($dump['label'])) {
                $data['kuaidi_label'] = $dump['label'];
            }
            $orderService->update($orderId, $data);
        } catch (\Throwable $e) {
            $dump = [];
        }
        return $dump;
    }

    /**
     * 返回订单商品总重量
     * @param int $id
     * @return int|string
     */
    public function getOrderSumWeight(int $id, $default = false)
    {
        /** @var StoreOrderCartInfoServices $services */
        $services = app()->make(StoreOrderCartInfoServices::class);
        $orderGoodInfo = $services->getOrderCartInfoCache((int)$id);
        $weight = 0;
        foreach ($orderGoodInfo as $cartInfo) {
            $cart = $cartInfo['cart_info'] ?? [];
            if ($cart) {
                $weight = bcadd((string)$weight, (string)bcmul((string)$cart['cart_num'] ?? '0', (string)$cart['productInfo']['attrInfo']['weight'] ?? '0', 4), 2);
            }
        }
        return $weight ? $weight : ($default === false ? 0 : $default);
    }


    /**
     * 提醒发货
     * @param int $supplierId
     * @param int $id
     * @return bool
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function deliverRemind(int $id)
    {
        $expire = 600;
        $val = time() + $expire;

        $order = $this->dao->get($id);
        if (!$order) {
            throw new ValidateException('订单不存在');
        }
        $order = $order->toArray();

        $cacheName = 'order_deliver_remind_' . $id;
        if (CacheService::has($cacheName)) {
            $interval = CacheService::get($cacheName);
            $remain = $interval - time();
            if ($remain > 0) {
                throw new ValidateException('请' . ceil($remain / 60) . '分钟后再次提醒');
            }
            CacheService::delete($cacheName);
        } else {
            CacheService::set($cacheName, $val, $expire);
        }
        $typeId = 0;
        $type = 'admin';
        if ($order['store_id']) {
            $typeId = $order['store_id'];
            $type = 'store';
        } else if ($order['supplier_id']) {
            $typeId = $order['supplier_id'];
            $type = 'supplier';
        }

        //向供应商后台发送待发货订单消息
        try {
            /** @var  NoticeSmsService $NoticeSms */
            $NoticeSms = app()->make(NoticeSmsService::class);
            $mark = 'admin_pay_success_code';
            $NoticeSms->setEvent($mark)->sendAdminPaySuccess($order);

            SocketPushJob::dispatch([$typeId, 'WAIT_DELIVER_ORDER', ['order_id' => $order['order_id']], $type]);
        } catch (\Throwable $e) {
            Log::error('向供应商发送提醒发货消息失败,失败原因:' . $e->getMessage());
        }
        return true;
    }

    /**
     * 订单改派
     * @param int $id
     * @param array $data
     * @param int $staff_id
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function reassign(int $id, array $data)
    {
        $orderInfo = $this->dao->get($id, ['*'], ['pink']);
        if (!$orderInfo) {
            throw new ValidateException('订单未能查到,不能发货!');
        }
        if ($orderInfo['paid'] != 1) {
            throw new ValidateException('订单未支付');
        }
        if ($orderInfo->is_del) {
            throw new ValidateException('订单已删除,不能发货!');
        }

        /** @var StoreOrderRefundServices $storeOrderRefundServices */
        $storeOrderRefundServices = app()->make(StoreOrderRefundServices::class);
        if ($storeOrderRefundServices->count(['store_order_id' => $id, 'refund_type' => [0, 1, 2, 4, 5, 6], 'is_cancel' => 0, 'is_del' => 0])) {
            throw new ValidateException('订单有售后申请请先处理');
        }

        $data['delivery_name'] = $data['sh_delivery_name'];
        $data['delivery_id'] = $data['sh_delivery_id'];
        $data['delivery_uid'] = $data['sh_delivery_uid'];

        if (!$data['delivery_name']) {
            throw new ValidateException('请输入送货人姓名');
        }
        if (!$data['delivery_id']) {
            throw new ValidateException('请输入送货人电话号码');
        }
        if (!$data['delivery_uid']) {
            throw new ValidateException('请输入送货人信息');
        }
        if (!check_phone($data['delivery_id'])) {
            throw new ValidateException('请输入正确的送货人电话号码');
        }
        unset($data['sh_delivery_name'], $data['sh_delivery_id'], $data['sh_delivery_uid'], $data['delivery_remark']);

        //修改订单发货信息
        $this->dao->update($id, $data);
        $message = '已配送 发货人：' . $data['delivery_name'] . ' 发货人电话：' . $data['delivery_id'];
        /** @var DeliveryServiceServices $deliverServiceServices */
        $deliverServiceServices = app()->make(DeliveryServiceServices::class);
        $staffInfo = $deliverServiceServices->get(['uid' => $data['delivery_uid']], ['id', 'nickname']);
        /** @var StoreOrderStatusServices $statusService */
        $statusService = app()->make(StoreOrderStatusServices::class);
        $statusService->update(['oid' => $id, 'change_type' => 'delivery'], ['change_manager' => $staffInfo['nickname'], 'change_manager_id' => $staffInfo['id'], 'change_message' => $message]);
        return compact('orderInfo');
    }
}
