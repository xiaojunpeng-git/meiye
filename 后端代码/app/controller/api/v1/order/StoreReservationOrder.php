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
use app\services\order\{
	StoreOrderServices,
	StoreReservationOrderServices};
use think\Response;

/**
 * 预约单控制器
 * Class StoreReservationOrder
 * @package app\controller\api\order
 */
class StoreReservationOrder
{

    /**
     * @var StoreOrderServices
     */
    protected $services;


    /**
     * StoreReservationOrder constructor.
     * @param StoreReservationOrderServices $services
     */
    public function __construct(StoreReservationOrderServices $services)
    {
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
			['status', ''],//状态
			[['search', 's'], ''],//筛选关键词
			[['oid', 'd'], 0],//订单ID
			[['book_uid', 'd'], 0],//代客预约用户ID
		]);
		$loginUid = (int)$request->uid();
		$where['uid'] = $this->services->resolveReservationListUid(
			$loginUid,
			(int)($where['oid'] ?? 0),
			(int)($where['book_uid'] ?? 0)
		);
		unset($where['book_uid']);
		$where['is_del'] = 0;
		return app('json')->successful($this->services->getReservationOrderList($where));
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
		$uid = (int)$request->uid();
		return app('json')->success($this->services->getReservationOrderInfo($uid, (int)$id));
	}

	/**
	 * 订单信息（售后预约）
	 * @param Request $request
	 * @param StoreOrderServices $orderServices
	 * @param $id
	 * @return Response
	 */
	public function getOrderInfo(Request $request, $id)
	{
		[$cart_info_id, $book_uid] = $request->getMore([
			[['cart_info_id', 'd'], 0],//订单商品ID
			[['book_uid', 'd'], 0],//代客预约用户ID
		], true);
		if (!strlen(trim($id))) return app('json')->fail('参数错误');
		$loginUid = (int)$request->uid();
		$uid = $this->services->resolveBookingUid($loginUid, (int)$book_uid);
		return app('json')->success($this->services->getOrderInfo($uid, (int)$id, (int)$cart_info_id));
	}

    /**
     * 切换门店获取该商品信息
     * @param Request $request
     * @return Response
     */
    public function switchGoodsInfo(Request $request)
    {
        [$store_id,$pid,$unique] = $request->postMore([
            [['store_id', 'd'], 0],//门店ID
            [['pid', 'd'], 0],//平台商品ID
            ['unique', ''],//唯一值
        ], true);
        if (!$store_id || !$pid) return app('json')->fail('参数错误');
        return app('json')->success($this->services->getSwitchGoodsInfo($store_id,$pid,$unique));
    }

	/**
	 * 创建预约单
	 * @param Request $request
	 * @param $id
	 * @return Response
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function createReservationOrder(Request $request, $id)
	{
		$data = $request->postMore([
			[['cart_num', 'd'], 1],//预约数量
			['reservation_time', ''],//预约日期
			[['reservation_time_id', 'd'], 0],//预约商品时段ID（旧，可选）
			['reservation_start', ''],//预约开始时间 HH:mm 或 datetime
			['reservation_end', ''],//预约结束时间
			['custom_form', []],//补充信息
			[['cart_info_id', 'd'], 0],//订单商品ID
			['service_staff_id', 0],//服务人员ID
			[['service_duration_minutes', 'd'], 0],//服务总时长
			['addon_items', []],//加项服务
			['sync_all', []],//多手艺人
			['store_id', 0],//门店ID
			[['book_uid', 'd'], 0],//代客预约用户ID
			['mark', ''],//服务备注
		]);
		$loginUid = (int)$request->uid();
		$uid = $this->services->resolveBookingUid($loginUid, (int)($data['book_uid'] ?? 0));
		unset($data['book_uid']);

		$this->services->setItem('is_check_order', 1);
		$this->services->setItem('reservation_initial_status', 3);
		$ids = $this->services->createReservationOrder($uid,(int)$id, $data);
		$this->services->reset();

		return app('json')->success('预约成功', $ids);
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
		$uid = (int)$request->uid();
		$this->services->cancelReservationOrder($uid,(int)$id);
		return app('json')->success('取消成功');
	}

	/**
	 * 已取消预约单删除
	 * @param Request $request
	 * @param $id
	 * @return Response
	 */
	public function delReservationOrder(Request $request, $id)
	{
		if (!strlen(trim($id))) return app('json')->fail('参数错误');
		$uid = (int)$request->uid();
		$this->services->delReservationOrder($uid,(int)$id);
		return app('json')->success('删除成功');
	}

	/**
	 * 获取当前用户可预约的已购项目
	 * @param Request $request
	 * @return Response
	 */
	public function getUserPurchasedRemainItems(Request $request)
	{
		$loginUid = (int)$request->uid();
		$bookUid = (int)$request->get('book_uid', 0);
		$uid = $this->services->resolveBookingUid($loginUid, $bookUid);
		$storeId = (int)$request->get('store_id', 0);
		return app('json')->successful($this->services->getUserPurchasedRemainItems($uid, $storeId));
	}



}
