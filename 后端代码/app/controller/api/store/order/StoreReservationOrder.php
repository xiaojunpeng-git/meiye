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
namespace app\controller\api\store\order;

use app\Request;
use app\services\store\SystemStoreStaffServices;
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
	 * @var int
	 */
	protected int $uid;
	/**
	 * 门店店员信息
	 * @var array
	 */
	protected array $staffInfo;
	/**
	 * 门店id
	 * @var int|mixed
	 */
	protected $store_id;

	/**
	 * 门店店员ID
	 * @var int|mixed
	 */
	protected $staff_id;


	/**
	 * @param StoreReservationOrderServices $services
	 * @param Request $request
	 */
    public function __construct(StoreReservationOrderServices $services, Request $request)
    {
        $this->services = $services;
		$this->uid = (int)$request->uid();
		$this->getStaffInfo();
    }

	/**
	 * @return void
	 */
	protected function getStaffInfo()
	{
		try {
			/** @var SystemStoreStaffServices $staffServices */
			$staffServices = app()->make(SystemStoreStaffServices::class);
			$staffInfo = $staffServices->getStaffInfoByUid($this->uid)->toArray();
		} catch (\Throwable $e) {
			$staffInfo = [];
		}
		$this->staffInfo = $staffInfo;
		$this->store_id = (int)($this->staffInfo['store_id'] ?? 0);
		$this->staff_id = (int)($this->staffInfo['id'] ?? 0);
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
			['is_manager', 0],//是否是店长
			['teacher_mode', 0],//老师中心
			['status', ''],//状态
			[['search', 's'], ''],//筛选关键词
			[['oid', 'd'], 0],//订单ID
			['date', ''],//预约日期 Y-m-d
		]);
		$is_manager = $where['is_manager'];
		$teacher_mode = (int)$where['teacher_mode'];
		unset($where['is_manager'], $where['teacher_mode']);
		if (!empty($where['date'])) {
			$where['reservation_time'] = [
				strtotime($where['date'] . ' 00:00:00'),
				strtotime($where['date'] . ' 23:59:59'),
			];
		}
		unset($where['date']);
		if (!$where['oid']) {
			$where['store_id'] = $this->store_id;
			$canSeeAllStore = $is_manager || (int)($this->staffInfo['is_manager'] ?? 0) === 1 || (int)($this->staffInfo['is_butler'] ?? 0) === 1;
			if ($teacher_mode) {
				$where['teacher_staff_id'] = $this->staff_id;
				if ($where['status'] !== '' && $where['status'] !== null) {
					$where = $this->services->applyTeacherTabFilter($where, $where['status']);
				}
			} elseif (!$canSeeAllStore && $this->staffInfo) {
				$where['service_staff_id'] = $this->staff_id;
			}
		}
		$where['is_del'] = 0;
		return app('json')->successful($this->services->getReservationOrderList($where));
	}

	/**
	 * 管家中心 / 老师中心状态统计
	 */
	public function statistics(Request $request)
	{
		[$is_manager, $date, $teacher_mode] = $request->getMore([
			['is_manager', 0],
			['date', ''],
			['teacher_mode', 0],
		], true);
		$where = ['store_id' => $this->store_id];
		if ($date) {
			$where['reservation_time'] = [
				strtotime($date . ' 00:00:00'),
				strtotime($date . ' 23:59:59'),
			];
		}
		$canSeeAllStore = $is_manager || (int)($this->staffInfo['is_manager'] ?? 0) === 1 || (int)($this->staffInfo['is_butler'] ?? 0) === 1;
		if ((int)$teacher_mode) {
			$where['teacher_staff_id'] = $this->staff_id;
			return app('json')->successful($this->services->getTeacherOrderStatistics($where));
		}
		if (!$canSeeAllStore && $this->staffInfo) {
			$where['service_staff_id'] = $this->staff_id;
		}
		return app('json')->successful($this->services->getButlerCenterStatistics($where));
	}

	/**
	 * 门店可用房间（桌码/房号）
	 */
	public function tableList(Request $request)
	{
		return app('json')->successful($this->services->getStoreTableList((int)$this->store_id));
	}

	/**
	 * 管家/店长修改预约
	 */
	public function updateReservationOrder(Request $request, $id)
	{
		if (!strlen(trim($id))) return app('json')->fail('参数错误');
		$this->services->assertReservationManagePermission($this->staffInfo);
		$data = $request->postMore([
			['reservation_name', ''],
			['reservation_phone', ''],
			['reservation_time', ''],
			[['reservation_time_id', 'd'], 0],
			['reservation_start', ''],
			['reservation_end', ''],
			[['service_duration_minutes', 'd'], 0],
			['service_staff_id', 0],
			['sync_all', []],
			['reservation_address', ''],
			['addon_items', []],
			['custom_form', []],
			['mark', ''],
		]);
		if (!empty($data['reservation_address'])) {
			$data['reservation_address'] = str_replace('/', ' ', $data['reservation_address']);
		}
		$this->services->updateReservationOrder((int)$id, $data);
		return app('json')->success('修改成功');
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
		$reservationOrderInfo = $this->services->getReservationOrderInfo(0, (int)$id, (int)$this->store_id);
		$reservationOrderInfo['now_staff_id'] = $this->staff_id;//当前登录操作店员ID
		return app('json')->success($reservationOrderInfo);
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
		[$status, $service_describe, $service_images] = $request->getMore([
			['status', 1],//状态 1开始服务2结束服务
			['service_describe', ''],//服务描述
			['service_images', ''],//服务凭证
		],true);
        $this->store_id = (int)($this->staffInfo['store_id'] ?? 0);
		$result = $this->services->setServiceStatus(0, (int)$id, (int)$status, (int)$this->staff_id, ['service_describe' => $service_describe, 'service_images' => $service_images, 'store_id' => $this->store_id]);
		return app('json')->success('操作成功', is_array($result) ? $result : []);
	}

	/**
	 * 老师服务标签选项
	 */
	public function serviceTagList()
	{
		return app('json')->success($this->services->getTeacherServiceTagOptions());
	}

	/**
	 * 设置老师服务标签
	 */
	public function setServiceTag(Request $request, $id)
	{
		if (!strlen(trim($id))) return app('json')->fail('参数错误');
		[$tagList, $other_tag] = $request->postMore([
			['tagList', []],
			['other_tag', ''],
		], true);
		$this->services->setTeacherServiceTags((int)$id, (int)$this->staff_id, [
			'tagList' => $tagList,
			'other_tag' => $other_tag,
		]);
		return app('json')->success('设置成功');
	}

	/**
	 * 管家/店长接单确认
	 */
	public function confirmReservationOrder(Request $request, $id)
	{
		if (!strlen(trim($id))) return app('json')->fail('参数错误');
		$this->services->assertReservationManagePermission($this->staffInfo);
		[$table_id, $table_name] = $request->postMore([
			[['table_id', 'd'], 0],
			['table_name', ''],
		], true);
		$this->services->confirmReservationOrder((int)$id, (int)$this->store_id, (int)$this->staff_id, (int)$table_id, (string)$table_name, true);
		return app('json')->success('接单成功');
	}

	/**
	 * 管家/店长拒绝预约
	 */
	public function refuseReservationOrder(Request $request, $id)
	{
		if (!strlen(trim($id))) return app('json')->fail('参数错误');
		[$refuse_reason] = $request->postMore([
			['refuse_reason', ''],
		], true);
		$this->services->assertReservationManagePermission($this->staffInfo);
		$this->services->refuseReservationOrder((int)$id, (int)$this->store_id, (string)$refuse_reason, (int)$this->staff_id);
		return app('json')->success('已拒绝预约');
	}




}
