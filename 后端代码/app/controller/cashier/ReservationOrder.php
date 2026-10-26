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
use app\services\product\product\StoreProductReservationServices;
use app\services\product\sku\StoreProductReservationTimeServices;
use app\services\order\{StoreOrderServices, StoreReservationOrderServices};
use think\facade\App;
use think\Response;

/**
 * 预约单控制器
 * Class ReservationOrder
 * @package app\controller\cashier
 */
class ReservationOrder extends AuthController
{

    /**
     * @var StoreOrderServices
     */
    protected $services;


	/**
	 * @param StoreReservationOrderServices $services
	 */
    public function __construct(App $app, StoreReservationOrderServices $services)
    {
		parent::__construct($app);
        $this->services = $services;
    }


	/**
	 * 获取预约单列表
	 * @param Request $request
	 * @return Response
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function reservationList(Request $request)
	{
		$where = $request->getMore([
			[['reservation_type', 'd'], ''],//预约类型2：到店服务，3：上门服务
			['status', ''],//状态预-1：已取消0：待服务1：服务中2：已完成
			[['phone', 's'], '', '', 'search'],//筛选关键词
			[['oid', 'd'], 0],//订单ID
			['reservation_time', ''],//预约日期时间
			[['service_staff_id', 'd'], ''],//服务人员ID
			[['uid', 'd'], ''],//客户ID
		]);
		if ($where['reservation_time']) {
			$where['reservation_time'] = is_string($where['reservation_time']) ? strtotime($where['reservation_time']) : $where['reservation_time'];
		}
		if ($where['oid'] && $where['status'] == 0) {//某个订单的待服务/待确认
			$where['status'] = [0, 1, 3];
		}
		$where['is_del'] = 0;
		$where['store_id'] = $this->storeId;
		return app('json')->successful($this->services->getSystemList($where));
	}


	/**
	 * 预约单详情
	 * @param Request $request
	 * @param $id
	 * @return Response
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function detail(Request $request, $id)
	{
		if (!strlen(trim($id))) return app('json')->fail('参数错误');
		$reservationOrderInfo = $this->services->getReservationOrderInfo(0, (int)$id, (int)$this->storeId);
		$reservationOrderInfo['now_staff_id'] = $this->cashierId;//当前登录操作店员ID
		return app('json')->success($reservationOrderInfo);
	}

	/**
	 * 获取预约单商品可选时段
	 * @param Request $request
	 * @param $id
	 * @return Response
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function getReservationProductTime(Request $request, $id)
	{
		if (!strlen(trim($id))) return app('json')->fail('参数错误');
		$reservationOrder = $this->services->get($id);
		if (!$reservationOrder) {
			return app('json')->fail('预约单不存在');
		}
		/** @var StoreProductReservationTimeServices $reservationTimeServices */
		$reservationTimeServices = app()->make(StoreProductReservationTimeServices::class);
		$reservationTimeData = $reservationTimeServices->getList(['product_id' => $reservationOrder['product_id'], 'sku_unique' => $reservationOrder['sku_unique']]);
		return app('json')->success($reservationTimeData);
	}

	/**
	 * 修改预约单
	 * @param Request $request
	 * @param $id
	 * @return Response
	 */
	public function update(Request $request, $id)
	{
		$data = $request->getMore([
			['reservation_name', ''],//预约人昵称
			['reservation_phone', ''],//预约人手机
			['reservation_time', ''],//预约日期
			[['reservation_time_id', 'd'], 0],//预约时间段ID（旧）
			['reservation_start', ''],//预约开始时间
			['reservation_end', ''],//预约结束时间
			[['service_duration_minutes', 'd'], 0],//服务时长
			['service_staff_id', 0],//主手艺人
			['sync_all', []],//手艺人
			['reservation_address', ''],//上门地址
			['mark', ''],//服务备注
			[['table_id', 'd'], 0],//服务房间ID
			['table_name', ''],//服务房间名称
		]);
		if (!strlen(trim($id))) return app('json')->fail('参数错误');
		if ($data['reservation_address']) {
			$data['reservation_address'] = str_replace('/',  ' ', $data['reservation_address']);
		}
		$this->services->updateReservationOrder((int)$id, $data);
		return app('json')->success('修改成功');
	}

	/**
	 * 设置预约服务状态
	 * @param Request $request
	 * @param $id
	 * @return Response
	 */
	public function setServiceStatus(Request $request, $id)
	{
		if (!strlen(trim($id))) return app('json')->fail('参数错误');
		[$service_staff_id, $status, $sync_all] = $request->getMore([
			['service_staff_id', 0],//服务人员ID
			['status', 1],//状态 1开始服务2结束服务
			['sync_all', []],//手艺人业绩
		],true);
        $where['store_id'] = $this->storeId;
		$where['sync_all'] = is_array($sync_all) ? $sync_all : [];
		$this->services->setServiceStatus(0, (int)$id, (int)$status, (int)$service_staff_id, $where);
		return app('json')->success('操作成功');
	}


	/**
	 * 取消预约单
	 * @param Request $request
	 * @param $id
	 * @return Response
	 */
	public function cancelReservationOrder(Request $request, $id)
	{
		if (!strlen(trim($id))) return app('json')->fail('参数错误');
		$this->services->cancelReservationOrder(0, (int)$id, false);
		return app('json')->success('取消成功');
	}

	/**
	 * 删除预约单
	 * @param Request $request
	 * @param $id
	 * @return Response
	 */
	public function delReservationOrder(Request $request, $id)
	{
		if (!strlen(trim($id))) return app('json')->fail('参数错误');
		$this->services->delReservationOrder(0,(int)$id);
		return app('json')->success('删除成功');
	}

    /**
     * 看板数据
     * @param Request $request
     * @return Response
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function noticeBoardData(Request $request)
    {
        $where = $request->getMore([
            [['reservation_type', 'd'], ''],//预约类型2：到店服务，3：上门服务
            ['reservation_time', ''],//预约日期时间
            [['service_staff_id', 'd'], ''],//服务人员ID
            ['reservation_phone', ''],//客户手机号
        ]);
        if(!$where['reservation_time']) return app('json')->fail('缺少时间参数！');
        $where['reservation_time'] = is_string($where['reservation_time']) ? strtotime($where['reservation_time']) : $where['reservation_time'];
        $where['is_del'] = 0;
		$where['status'] = [0, 1, 2, 3];
        $where['store_id'] = $this->storeId;
        return app('json')->successful($this->services->getNoticeBoardData($where));
    }

	/**
	 * 收银台是否可操作该已购订单（与消耗页跨店、空门店口径一致）
	 */
	protected function canCashierUsePurchasedOrder($orderInfo, int $storeId): bool
	{
		if (!$orderInfo) {
			return false;
		}
		$orderInfo = is_object($orderInfo) ? $orderInfo->toArray() : (array)$orderInfo;
		if ((int)($orderInfo['is_del'] ?? 0) === 1 || (int)($orderInfo['paid'] ?? 0) !== 1) {
			return false;
		}
		$orderStoreId = (int)($orderInfo['store_id'] ?? 0);
		if (!$orderStoreId || $orderStoreId === $storeId) {
			return true;
		}
		return (int)sys_config('cross_store_verification', 1) === 1;
	}

	/**
	 * 获取订单预约信息（先买后约）
	 * @param Request $request
	 * @param $id
	 * @return Response
	 */
	public function getOrderReservationInfo(Request $request, $id)
	{
		[$cart_info_id] = $request->getMore([
			[['cart_info_id', 'd'], 0],
		], true);
		if (!strlen(trim($id))) return app('json')->fail('参数错误');
		/** @var StoreOrderServices $orderServices */
		$orderServices = app()->make(StoreOrderServices::class);
		$orderInfo = $orderServices->get((int)$id);
		if (!$this->canCashierUsePurchasedOrder($orderInfo, (int)$this->storeId)) {
			return app('json')->fail('订单不存在');
		}
		$orderInfo = is_object($orderInfo) ? $orderInfo->toArray() : (array)$orderInfo;
		return app('json')->success($this->services->getOrderInfo((int)$orderInfo['uid'], (int)$id, (int)$cart_info_id));
	}

	/**
	 * 获取商品预约时段
	 * @param Request $request
	 * @return Response
	 */
	public function getGoodsReservationTime(Request $request)
	{
		[$product_id, $unique, $date] = $request->getMore([
			[['product_id', 'd'], 0],
			['unique', ''],
			['date', ''],
		], true);
		if (!$product_id || !$unique || !$date) return app('json')->fail('参数错误');
		/** @var StoreProductReservationServices $productReservationServices */
		$productReservationServices = app()->make(StoreProductReservationServices::class);
		return app('json')->success($productReservationServices->getReservationProductTimeStock((int)$product_id, $unique, $date));
	}

	/**
	 * 创建预约单
	 * @param Request $request
	 * @param $id
	 * @return Response
	 */
	public function createReservationOrder(Request $request, $id)
	{
		$data = $request->postMore([
			[['cart_num', 'd'], 1],
			['reservation_time', ''],
			[['reservation_time_id', 'd'], 0],
			['reservation_start', ''],
			['reservation_end', ''],
			['custom_form', []],
			[['cart_info_id', 'd'], 0],
			['service_staff_id', 0],
			['reservation_name', ''],
			['reservation_phone', ''],
			['reservation_address', ''],
			['mark', ''],
			[['service_duration_minutes', 'd'], 0],
			['addon_items', []],
			['sync_all', []],
			[['table_id', 'd'], 0],
			['table_name', ''],
		]);
		if (!strlen(trim($id))) return app('json')->fail('参数错误');
		/** @var StoreOrderServices $orderServices */
		$orderServices = app()->make(StoreOrderServices::class);
		$orderInfo = $orderServices->get((int)$id);
		if (!$this->canCashierUsePurchasedOrder($orderInfo, (int)$this->storeId)) {
			return app('json')->fail('订单不存在');
		}
		$orderInfo = is_object($orderInfo) ? $orderInfo->toArray() : (array)$orderInfo;
		if ($data['reservation_address']) {
			$data['reservation_address'] = str_replace('/', ' ', $data['reservation_address']);
		}
		$data['store_id'] = (int)$this->storeId;
		$this->services->setItem('is_check_order', 1);
		$this->services->setItem('reservation_initial_status', 0);
		$ids = $this->services->createReservationOrder((int)$orderInfo['uid'], (int)$id, $data, $orderInfo);
		$this->services->reset();
		return app('json')->success('预约成功', $ids);
	}

	/**
	 * 未购项目：仅生成预约记录
	 * @param Request $request
	 * @return Response
	 */
	public function createGuestReservationRecord(Request $request)
	{
		$data = $request->postMore([
			[['uid', 'd'], 0],
			[['product_id', 'd'], 0],
			['unique', ''],
			['reservation_time', ''],
			[['reservation_time_id', 'd'], 0],
			['reservation_start', ''],
			['reservation_end', ''],
			['service_staff_id', 0],
			['reservation_name', ''],
			['reservation_phone', ''],
			['reservation_address', ''],
			['mark', ''],
			[['service_duration_minutes', 'd'], 0],
			['addon_items', []],
			['sync_all', []],
			[['table_id', 'd'], 0],
			['table_name', ''],
		]);
		if (!$data['uid']) {
			return app('json')->fail('请选择会员');
		}
		if ($data['reservation_address']) {
			$data['reservation_address'] = str_replace('/', ' ', $data['reservation_address']);
		}
		$this->services->setItem('reservation_initial_status', 0);
		$ids = $this->services->createGuestReservationRecord((int)$data['uid'], (int)$this->storeId, $data);
		$this->services->reset();
		return app('json')->success('预约成功', $ids);
	}

	/**
	 * 确认预约
	 * @param Request $request
	 * @param $id
	 * @return Response
	 */
	public function confirmReservationOrder(Request $request, $id)
	{
		if (!strlen(trim($id))) return app('json')->fail('参数错误');
		[$table_id, $table_name] = $request->postMore([
			[['table_id', 'd'], 0],
			['table_name', ''],
		], true);
		$this->services->confirmReservationOrder((int)$id, (int)$this->storeId, (int)$this->cashierId, (int)$table_id, (string)$table_name, false);
		return app('json')->success('接单成功');
	}

	/**
	 * 门店可用房间（桌码/房号）
	 */
	public function tableList(Request $request)
	{
		return app('json')->success($this->services->getStoreTableList((int)$this->storeId));
	}

	/**
	 * 拒绝预约
	 */
	public function refuseReservationOrder(Request $request, $id)
	{
		if (!strlen(trim($id))) return app('json')->fail('参数错误');
		[$refuse_reason] = $request->postMore([
			['refuse_reason', ''],
		], true);
		$this->services->refuseReservationOrder((int)$id, (int)$this->storeId, (string)$refuse_reason, (int)$this->cashierId);
		return app('json')->success('已拒绝预约');
	}

	/**
	 * 获取用户可预约的已购项目（卡项/项目订单剩余次数）
	 * @param int $uid
	 * @return Response
	 */
	public function getUserPurchasedRemainItems($uid)
	{
		if (!$uid) {
			return app('json')->successful(['list' => []]);
		}
		return app('json')->successful($this->services->getUserPurchasedRemainItems((int)$uid, (int)$this->storeId));
	}

}
