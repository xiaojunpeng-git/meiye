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

namespace app\controller\admin\v1\store;


use app\controller\admin\AuthController;
use app\jobs\order\SpliteOrderAfterJob;
use app\model\order\StoreOrder;
use app\model\order\StoreOrderCartInfo;
use app\model\order\StoreOrderWriteoff;
use app\model\user\UserCardHolder;
use app\model\yeji\CashSource;
use app\model\yeji\CashType;
use app\model\yeji\StaffYeji;
use app\services\agent\SystemRegionAgentServices;
use app\services\order\OtherOrderServices;
use app\services\order\StoreOrderServices;
use app\services\pay\PayServices;
use app\services\user\UserRechargeServices;
use app\Request;
use think\facade\App;
use \app\common\controller\Order as CommonOrder;

/**
 * Class Order
 * @package app\controller\admin\v1\order
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

    //记账类型
    public function getCash(){
        $result['cash_type']=CashType::select();
        $result['source']=CashSource::select();
        $result['pay_type']=[
            ['id'=>PayServices::COMBINATION_PAY,'name'=>'组合支付'],
            ['id'=>PayServices::YUE_PAY,'name'=>'余额支付'],
            ['id'=>PayServices::CASH_PAY,'name'=>'现金支付'],
            ['id'=>'cika','name'=>'次卡支付']
        ];
        return $this->success('ok',$result);
    }
    /**
     * 订单列表
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function index(SystemRegionAgentServices $regionAgentServices)
    {
        $where = $this->request->getMore([
            ['store_id', -1],
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
            ['is_kuadian',''],
            ['product_type', '']//商品类型0:普通商品，1：卡密，2：优惠券，3：虚拟商品,4：次卡商品,5:卡项商品6：预约商品
        ]);
		$where['type'] = trim($where['type']);
		$where['status'] = trim($where['status']);
		if (!$where['store_id']) {//无筛选
			if ($this->adminType == 3 && $this->agentId) {//区域代理商登录
				$storeIds = $regionAgentServices->getRegionAgentStoreId((int)$this->agentId);
				if ($storeIds) {
					$where['store_id'] = $storeIds;
				} else {
					return $this->success(['list' => [], 'count' => 0, 'stat' => [], 'bat_url' => 'file/upload/1']);
				}
			}
		}
        $where['is_system_del'] = 0;
        $where['pid'] = -2;
        $hasSearch = StoreOrderServices::hasOrderListSearch($where);
        if (!$hasSearch && !in_array($where['status'], [-1, -2, -3])) {
            $where['pid'] = -3;
        }
        $where['not_recharge']=1;
        $where['plat_type'] = 1;//门店订单
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

    /**
     * 拆分子订单列表（与门店后台列表商品展示一致）
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

	/**
	 * 获取订单类型数量
	 * @param SystemRegionAgentServices $regionAgentServices
	 * @return mixed
	 */
    public function chart(SystemRegionAgentServices $regionAgentServices)
    {
        $where = $this->request->getMore([
            ['store_id', -1],
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
        ]);
        $where['is_system_del'] = 0;
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
		if (!$where['store_id']) {//无筛选
			if ($this->adminType == 3 && $this->agentId) {//区域代理商登录
				$storeIds = $regionAgentServices->getRegionAgentStoreId((int)$this->agentId);
				if ($storeIds) {
					$where['store_id'] = $storeIds;
				} else {
					return $this->success(['all' => 0, 'unpaid' => 0, 'unshipped' => 0, 'partshipped' => 0, 'untake' => 0, 'write_off' => 0, 'write_offed' => 0, 'unevaluate' => 0, 'complete' => 0, 'del' => 0]);
				}
			}
		}
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
        [$store_id] = $this->request->getMore([
            ['store_id', -1]
        ], true);
        $store_id = $store_id ?: -1;
        $store_id = (int)$store_id;
        $data = $this->services->getStoreOrderHeader($store_id);
        $data['recharg'] = $services->getRechargeCount($store_id);
        $data['vip'] = $orderServices->getvipOrderCount($store_id);
        return $this->success($data);
    }

    /**
     * 获取配置信息
     * @return mixed
     */
    public function getDeliveryInfo()
    {
        return $this->success([
            'express_temp_id' => store_config($this->storeId, 'config_export_temp_id'),
            'id' => store_config($this->storeId, 'config_export_id'),
            'to_name' => store_config($this->storeId, 'config_export_to_name'),
            'to_tel' => store_config($this->storeId, 'config_export_to_tel'),
            'to_add' => store_config($this->storeId, 'config_export_to_address'),
            'export_open' => (bool)store_config($this->storeId, 'config_export_open')
        ]);
    }

    /**
     * 订单分配
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function shareOrder()
    {
        [$oid, $store_id] = $this->request->getMore([
            ['oid', 0],
            ['store_id', 0]
        ], true);
        if (!$oid || !$store_id) {
            return $this->fail('缺少参数');
        }
        $this->services->shareOrder((int)$oid, (int)$store_id);
        return $this->success('分配成功');
    }

    /**
     * 撤销核销订单
     * @param $id
     * @return mixed
     */
    public function postChexiao($id)
    {
        $data = $this->request->param('remarks', '');
        $order = $this->services->get($id);
        if (!$order) {
            return $this->fail('订单不存在');
        }
        if ($order['refund_status'] != 0) {
            return $this->fail('该订单状态不允许撤销！');
        }
        $this->services->update(['id' => $id], ['back_reason' => $data, 'refund_status' => 2]);
        StoreOrderWriteoff::where('id', $order['link_id'])->update(['status' => 1]);
        StaffYeji::where('link_id', $order['link_id'])->where('type', 3)->update(['status' => 1]);
        $writeoff = StoreOrderWriteoff::where('id', $order['link_id'])->find();
        if ($writeoff) {
            $cateId = $writeoff['order_cart_id'];
            $number = $writeoff['writeoff_num'] ?? 1;
            StoreOrderCartInfo::where('id', $cateId)->inc('write_surplus_times', $number)->update();
            UserCardHolder::where('uid', $writeoff['uid'])->where('oid', $writeoff['oid'])->inc('write_surplus_times', $number)->update();
            StoreOrderCartInfo::where('id', $cateId)->update(['is_writeoff' => 0]);
            StoreOrder::where('id', $writeoff['oid'])->update(['status' => 5]);
        }
        return $this->success('提交成功');
    }
}
