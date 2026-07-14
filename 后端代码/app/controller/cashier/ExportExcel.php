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


use app\controller\store\AuthController;
use app\Request;
use app\services\order\StoreReservationOrderServices;
use app\services\other\export\ExportServices;
use think\facade\App;

/**
 * 导出excel类
 * Class ExportExcel
 * @package app\controller\cashier\export
 */
class ExportExcel extends AuthController
{
    /**
     * @var ExportServices
     */
    protected $service;

    /**
     * ExportExcel constructor.
     * @param App $app
     * @param ExportServices $services
     */
    public function __construct(App $app, ExportServices $services)
    {
        parent::__construct($app);
        $this->service = $services;
    }

	/**
	 * 导出预约单
	 * @param Request $request
	 * @param StoreReservationOrderServices $reservationOrderServices
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function reservationOrder(Request $request, StoreReservationOrderServices $reservationOrderServices)
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
		$where['is_del'] = 0;
		$where['store_id'] = $this->storeId;
        $data = $reservationOrderServices->getSystemList($where);
        return $this->success($this->service->reservationOrder($data['list'] ?? []));
    }


}
