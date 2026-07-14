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

namespace app\controller\store\order;


use app\model\order\StoreOrder;
use app\model\user\UserCardHolder;
use app\controller\store\AuthController;
use app\model\order\StoreOrderCartInfo;
use app\model\order\StoreOrderWriteoff;
use app\model\yeji\CashSource;
use app\model\yeji\CashType;
use app\model\yeji\StaffYeji;
use app\Request;
use app\services\order\StoreOrderWriteOffServices;
use app\services\other\export\ExportServices;
use app\services\order\OtherOrderServices;
use app\services\order\store\WriteOffOrderServices;
use app\services\order\StoreOrderDeliveryServices;
use app\services\order\StoreOrderServices;
use app\services\pay\PayServices;
use app\services\store\DeliveryServiceServices;
use app\services\store\SystemStoreServices;
use app\services\user\UserRechargeServices;
use mohe\services\SystemConfigService;
use think\facade\App;
use \app\common\controller\Order as CommonOrder;
use think\Response;

/**
 * Class Order
 * @package app\controller\store\order
 * @property Request $request
 */
class Order extends AuthController
{

    use CommonOrder;

    /**
     * @var StoreOrderServices
     */
    protected $services;

    /**
     * Order constructor.
     * @param App $app
     * @param StoreOrderServices $services
     */
    public function __construct(App $app, StoreOrderServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    /**
     * 订单列表
     * @return mixed
     */
    public function index()
    {
        $where = $this->request->getMore([
            ['order_type', ''],
            ['link_type', ''],
            ['yeji_staff', ''],
            ['yeji_shouyi', ''],
            ['cash_choose',''],
            ['source',''],
            ['type', ''],
            ['pay_type', ''],
            ['active_pay', ''],
            ['pay_sub_type', ''],
            ['combination_cash_choose', ''],
            ['status', ''],
            ['time', ''],
            ['date_range', ''],
            ['staff_id', ''],
            ['real_name', ''],
            ['search_order_id', ''],
            ['search_verify_code', ''],
            ['search_product', ''],
            ['search_user', ''],
            ['product_type', ''],//商品类型0:普通商品，1：卡密，2：优惠券，3：虚拟商品,4：次卡商品,5:卡项商品6：预约商品
            ['service_object', ''],//服务对象：本人/朋友（核销订单）
        ]);
        //里面不使用pay_type了 因为这里搜索付款方式有次卡支付
        $where['is_system_del'] = 0;
        $where['store_id'] = $this->storeId;
        $where['pid'] = -2;
        $hasSearch = StoreOrderServices::hasOrderListSearch($where);
        if (!$hasSearch && !in_array($where['status'], [-1, -2, -3])) {
            $where['pid'] = -3;
        }
        $where['not_recharge']=1;
        $where['not_auto']=1;
        if (trim((string)($where['search_verify_code'] ?? '')) !== '') {
            unset($where['not_auto']);
        }
        $where['status'] = trim($where['status']);
        $where['type'] = trim($where['type']);
        return $this->success($this->services->getOrderList($where, ['*'], ['split' => function ($query) {
            $query->field('id,pid');
        }, 'pink', 'invoice', 'storeStaff'], false, 'add_time DESC,id DESC', true));
    }

    //记账类型
    public function getCash(){
        $result['cash_type']=CashType::select();
        $result['source']=CashSource::select();
        $result['pay_type']=[
            ['id'=>PayServices::COMBINATION_PAY,'name'=>'组合支付'],
            ['id'=>PayServices::YUE_PAY,'name'=>'余额支付'],
            ['id'=>PayServices::CASH_PAY,'name'=>'现金支付'],
            ['id'=>'cika','name'=>'次卡支付'],
        ];
        return $this->success('ok',$result);
    }
    /**
     * 撤销订单
     */
    public function postChexiao($id){
        $data = $this->request->param('remarks', '');
        $order=$this->services->get($id);
        if($order['refund_status'] != 0){
            return app('json')->fail('该订单状态不允许撤销！');
        }
        $this->services->update(['id' => $id], ['back_reason' => $data,'refund_status'=>2]);
        //核销记录
        StoreOrderWriteoff::where("id",$order['link_id'])->update(['status'=>1]);
        //业绩失效
        StaffYeji::where("link_id",$order['link_id'])->where("type",3)->update(['status'=>1]);
        //退换次数
        $writeoff=StoreOrderWriteoff::where("id",$order['link_id'])->find();
        $cateId=$writeoff['order_cart_id'];
        $number=$writeoff['writeoff_num'] ?? 1;
        StoreOrderCartInfo::where("id",$cateId)->inc("write_surplus_times",$number)->update();
        UserCardHolder::where("uid",$writeoff['uid'])->where("oid",$writeoff['oid'])->inc("write_surplus_times",$number)->update();
        StoreOrderCartInfo::where("id",$cateId)->update(['is_writeoff'=>0]);
        //修改订单状态为部分核销
        StoreOrder::where("id",$writeoff['oid'])->update(['status'=>5]);
        return $this->success('提交成功');
    }

    /**
     * 获取订单类型数量
     * @return mixed
     */
    public function chart()
    {
        $where = $this->request->getMore([
            ['time', ''],
            ['date_range', ''],
            [['type', 'd'], ''],
            ['pay_type', ''],
            ['staff_id', ''],
            ['order_type', ''],
            ['product_type', ''],//商品类型0:普通商品，1：卡密，2：优惠券，3：虚拟商品,4：次卡商品,5:卡项商品6：预约商品
            ['real_name', ''],
            ['search_order_id', ''],
            ['search_verify_code', ''],
            ['search_product', ''],
            ['search_user', ''],
        ]);
        $where['pid'] = -2;
        $where['store_id'] = $this->storeId;
        $data = $this->services->orderStoreCount($where);
        return $this->success($data);
    }

    /**
     * 获取头部统计数据
     * @param UserRechargeServices $services
     * @param OtherOrderServices $orderServices
     * @return mixed
     */
    public function header(UserRechargeServices $services, OtherOrderServices $orderServices)
    {
        $data = $this->services->getStoreOrderHeader($this->storeId);
        $data['recharg'] = $services->getRechargeCount($this->storeId);
        $data['vip'] = $orderServices->getvipOrderCount($this->storeId);
        return $this->success($data);
    }

    /**
     * 获取配置信息
     * @return mixed
     */
    public function getDeliveryInfo(SystemStoreServices $storeServices)
    {
        $storeId = (int)$this->storeId;
        $storeInfo = [];
        if ($storeId) {
            $storeInfo = $storeServices->getStoreInfo($storeId);
        }
        $data = SystemConfigService::more([
            'city_delivery_status',
            'self_delivery_status',
            'dada_delivery_status',
            'uu_delivery_status'
        ]);
        return $this->success([
            'express_temp_id' => store_config($this->storeId, 'store_config_export_temp_id'),
            'id' => store_config($this->storeId, 'store_config_export_id'),
            'to_name' => store_config($this->storeId, 'store_config_export_to_name'),
            'to_tel' => store_config($this->storeId, 'store_config_export_to_tel'),
            'to_add' => store_config($this->storeId, 'store_config_export_to_address'),
            'export_open' => (bool)((int)store_config($this->storeId, 'store_config_export_open')),
            'city_delivery_status' => isset($storeInfo['city_delivery_status']) && $storeInfo['city_delivery_status'] && $data['city_delivery_status'] && ($data['self_delivery_status'] || $data['dada_delivery_status'] || $data['uu_delivery_status']),
            'self_delivery_status' => isset($storeInfo['city_delivery_status']) && $storeInfo['city_delivery_status'] && $storeInfo['city_delivery_type'] == 0 && $data['city_delivery_status'] && $data['self_delivery_status'],
            'dada_delivery_status' => isset($storeInfo['city_delivery_status']) && $storeInfo['city_delivery_status'] && $storeInfo['city_delivery_type'] == 1 && $data['city_delivery_status'] && $data['dada_delivery_status'],
            'uu_delivery_status' => isset($storeInfo['city_delivery_status']) && $storeInfo['city_delivery_status'] && $storeInfo['city_delivery_type'] == 2 && $data['city_delivery_status'] && $data['uu_delivery_status'],
        ]);
    }

    /**
     * 订单导出
     * @param UserRechargeServices $services
     * @param ExportServices $exportServices
     * @param OtherOrderServices $otherOrderServices
     * @param $type
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function export(UserRechargeServices $services, ExportServices $exportServices, OtherOrderServices $otherOrderServices, $type)
    {
        switch ((int)$type) {
            case 1:
                $where = $this->request->postMore([
                    ['order_type', ''],
                    ['link_type',''],
                    ['yeji_staff', ''],
                    ['yeji_shouyi', ''],
                    ['cash_choose',''],
                    ['source',''],
                    ['pay_type', ''],
                    ['status', ''],
                    ['time', ''],
                    ['date_range', ''],
                    ['staff_id', ''],
                    ['real_name', ''],
                    ['search_order_id', ''],
                    ['search_verify_code', ''],
                    ['search_product', ''],
                    ['search_user', ''],
                    ['service_object', ''],
                    ['product_type', ''],//商品类型0:普通商品，1：卡密，2：优惠券，3：虚拟商品,4：次卡商品,5:卡项商品6：预约商品
                    ['ids', ''],
                ]);
                $where['is_system_del'] = 0;
                $where['store_id'] = $this->storeId;
                $exportByIds = !empty($where['ids']);
                $exportIds = [];
                if ($exportByIds) {
                    $exportIds = array_values(array_filter(array_map('intval', explode(',', (string)$where['ids']))));
                    $exportByIds = !empty($exportIds);
                }
                if ($exportByIds) {
                    $where = [
                        'id' => $exportIds,
                        'is_system_del' => 0,
                        'store_id' => $this->storeId,
                    ];
                } else {
                    unset($where['ids']);
                    $where['pid'] = -2;
                    $hasSearch = StoreOrderServices::hasOrderListSearch($where);
                    if (!$hasSearch && !in_array($where['status'], [-1, -2, -3])) {
                        $where['pid'] = -3;
                    }
                    $where['not_recharge'] = 1;
                    $where['not_auto'] = 1;
                    if (trim((string)($where['search_verify_code'] ?? '')) !== '') {
                        unset($where['not_auto']);
                    }
                }
                $where['status'] = trim((string)($where['status'] ?? ''));
                $data = $this->services->getExportList($where, [], $exportServices->limit);
                return $this->success($exportServices->storeOrder($data, ''));
            case 2:
                $where = $this->request->postMore([
                    ['data', ''],
                    ['paid', 1],
                    ['nickname', ''],
                    ['excel', '1'],
                    ['staff_id', ''],
                ]);
                $where['store_id'] = $this->storeId;
                $data = $services->getRechargeList($where, '*', $exportServices->limit);
                return $this->success($exportServices->userRecharge($data['list'] ?? []));
            case 3:
                $where = $this->request->postMore([
                    ['name', ""],
                    ['add_time', ""],
                    ['member_type', ""],
                    ['pay_type', ""],
                    ['staff_id', ''],
                ]);
                $where['store_id'] = $this->storeId;
                $data = $otherOrderServices->getMemberRecord($where, $exportServices->limit);
                return $this->success($exportServices->vipOrder($data['list'] ?? []));
            default:
                return $this->fail('导出类型错误');
        }
    }

    /**
     * @param DeliveryServiceServices $services
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getDeliveryList(DeliveryServiceServices $services)
    {
        $where = $this->request->getMore([
            ['field_key', ''],
            ['keyword', '']
        ]);
        return $this->success($services->getDeliveryList(1, $this->storeId,$where));
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
     * 核销订单
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
            return $this->fail('核销订单未查到!');
        }
        [$cart_ids] = $request->postMore([
            ['cart_ids', []]
        ], true);
        if ($cart_ids) {
            foreach ($cart_ids as $cart) {
                if (!isset($cart['cart_id']) || !$cart['cart_id'] || !isset($cart['cart_num']) || !$cart['cart_num'] || $cart['cart_num'] <= 0) {
                    return $this->fail($orderInfo['type'] == 12 ? '您有待服务的预约单，请前往预约列表完成核销' : '请重新选择核销商品，或核销件数');
                }
            }
        }
        return app('json')->success('核销成功', $writeOffOrderServices->writeoffOrder(0, $orderInfo->toArray(), $cart_ids, 'store', (int)$this->storeStaffId));
    }
    /**
     * 核销记录--门店  展示所有核销记录
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
            ['order_id', '', '', 'keyword'], //订单编号
            ['ordering_store_id', ''], //下单门店
            ['service_type', ''], //核销身份
            ['staff', ''], //核销店员
            ['product_name', ''], //商品名称
            ['yeji_staff', ''], //手艺人
            ['data', '', '', 'time'], //核销时间
        ]);
        $where['relation_id'] = (int)$this->storeId;
        return app('json')->successful($services->getAllWriteOffRecords($where));
    }
    /**
     * 订单发送货
     * @param $id 订单id
     * @return mixed
     */
    public function update_delivery($id, StoreOrderDeliveryServices $services)
    {
        $data = $this->request->postMore([
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
            return $this->fail('缺少发货ID');
        }
        $msg = $data['type'] == 2 ? '派单成功' : '发货成功';
        return $this->success($msg, $services->delivery((int)$id, $data, (int)$this->storeStaffId));
    }

    /**
     * 订单拆单发送货
     * @param $id 订单id
     * @return mixed
     */
    public function split_delivery($id, StoreOrderDeliveryServices $services)
    {
        $data = $this->request->postMore([
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
            return $this->fail('缺少发货ID');
        }
        if (!$data['cart_ids']) {
            return $this->fail('请选择发货商品');
        }
        foreach ($data['cart_ids'] as $cart) {
            if (!isset($cart['cart_id']) || !$cart['cart_id'] || !isset($cart['cart_num']) || !$cart['cart_num']) {
                return $this->fail('请重新选择发货商品，或发货件数');
            }
        }
        $services->splitDelivery((int)$id, $data, (int)$this->storeStaffId);
        return $this->success('SUCCESS');
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
        return $this->success($writeOffOrderServices->writeOrderFrom((int)$id, (int)$this->storeStaffId, (int)$cart_num));
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
        return app('json')->success('核销成功', $writeOffOrderServices->writeoffOrder(0, $orderInfo->toArray(), $cart_ids, 'store', (int)$this->storeStaffId));
    }

    /**
     * 易联云打印机打印
     * @param $id
     * @return mixed
     */
    public function order_print($id)
    {
        if (!$id) return app('json')->fail('缺少参数');
        $order = $this->services->get($id);
        if (!$order) {
            return app('json')->fail('订单没有查到,无法打印!');
        }
        $this->services->orderPrint((int)$id, 1, (int)$this->storeId);
        return app('json')->success('打印成功');
    }

    /**
     * 修改订单关联店员
     * @return \think\Response
     */
    public function updateOrderStaff()
    {
        $where = $this->request->getMore([
            ['order_id', ''],
            ['staff_id', 0],
        ]);
        $res = $this->services->update(['order_id' => $where['order_id']], ['staff_id' => $where['staff_id']]);
        if ($res) {
            return app('json')->success('修改成功');
        } else {
            return app('json')->fail('修改失败');
        }
    }

    /**
     * 拆分子订单列表（门店：商品信息与主列表一致）
     * @param Request $request
     * @param int|string $id
     * @return mixed
     */
    public function split_order(Request $request, $id)
    {
        [$status] = $request->getMore([
            ['status', -1]
        ], true);
        if (!$id) {
            return $this->fail('缺少订单ID');
        }
        $where = ['pid' => $id, 'is_system_del' => 0];
        if (!$this->services->count($where)) {
            $where = ['id' => $id, 'is_system_del' => 0];
        }
        $data = $this->services->getSplitOrderList($where, ['*'], ['split', 'pink', 'invoice', 'supplier', 'store' => function ($query) {
            $query->field('id,name')->bind(['store_name' => 'name']);
        }], true);
        return $this->success($data);
    }
}
