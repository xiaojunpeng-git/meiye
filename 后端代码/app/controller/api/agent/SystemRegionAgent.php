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
namespace app\controller\api\agent;

use app\controller\admin\AuthController;
use app\Request;
use app\services\agent\SystemRegionAgentServices;
use app\services\order\agent\AgentOrderServices;
use app\services\order\StoreOrderWapServices;
use app\services\store\SystemStoreServices;
use app\services\yeji\SatffYejiServices;


/**
 * 区域代理商
 * Class SystemRegionAgent
 * @package app\controller\admin
 */
class SystemRegionAgent
{
	/**
	 * @var SystemRegionAgentServices
	 */
	protected $services;

	/**
	 * @var int
	 */
	protected $uid;

	/**
	 * 门店店员信息
	 * @var array
	 */
	protected $agentInfo;

	/**
	 * 区域代理商id
	 * @var int|mixed
	 */
	protected $agentId;

	/**
	 * StoreOrder constructor.
	 * @param SystemRegionAgentServices $services
	 */
	public function __construct(SystemRegionAgentServices $services, Request $request)
	{
		$this->services = $services;
		$this->uid = (int)$request->uid();
		$this->getAgentInfo();
	}

	/**
	 * 获取当前登录
	 * @return void
	 */
	protected function getAgentInfo()
	{
		try {
			$agentInfo = $this->services->getRegionAgentByUid($this->uid);
		} catch (\Throwable $e) {
			$agentInfo = [];
		}
		$this->agentInfo = $agentInfo;
		$this->agentId = (int)($agentInfo['id'] ?? 0);
	}

	/**
	 * 获取区域内所有门店
	 * @param SystemStoreServices $services
	 * @return \think\Response
	 */
	public function storeList(SystemStoreServices $services)
	{
		$storeIds = $this->services->getRegionAgentStoreId((int)$this->agentId);
		$data = [];
		if ($storeIds) {
			$data = $services->getColumn(['id' => $storeIds, 'is_del' => 0, 'is_show' => 1], 'id,name');
		}
		return app('json')->success($data);
	}

	/**
	 * 代理商首页头部统计数据
	 * @param Request $request
	 * @param AgentOrderServices $agentOrderServices
	 * @return \think\Response
	 */
    public function homeStatics(Request $request, AgentOrderServices $agentOrderServices)
    {
		$where = $request->getMore([
			['store_id', ''],
			['store_ids', ''],
			['data', '', '', 'time']
		]);
		$where['store_id'] = $this->services->resolveRequestStoreIds(
			(int)$this->agentId,
			$where['store_id'] ?? '',
			$where['store_ids'] ?? ''
		);
		unset($where['store_ids']);
		$result = $agentOrderServices->homeStatics($where);
		//去掉门店数据
		unset($result[3]);
        return app('json')->success($result);
    }
	/**
	 * 订单图表
	 * @param Request $request
	 * @param AgentOrderServices $agentOrderServices
	 * @return \think\Response
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function orderChart(Request $request, AgentOrderServices $agentOrderServices)
    {
		$where = $request->getMore([
			['type', 1],//图表类型1:销售额2:订单量3:客单价
			['store_id', ''],
			['store_ids', ''],
			['data', '', '', 'time'],//默认30天
		]);
		$where['store_id'] = $this->services->resolveRequestStoreIds(
			(int)$this->agentId,
			$where['store_id'] ?? '',
			$where['store_ids'] ?? ''
		);
		unset($where['store_ids']);
		$time = explode('-', $where['time']);
		$type = $where['type'];
		unset($where['time'], $where['type']);
		if (count($time) != 2) app('json')->fail('参数错误');
		$dt_start = strtotime($time[0]);
		$dt_end = strtotime($time[1]);
		$dayCount = floor(($dt_end - $dt_start) / 86400) + 1;
		$data = [];
		if ($dayCount == 1) {
			$num = 0;
		} elseif ($dayCount > 1 && $dayCount <= 31) {
			$num = 1;
		} elseif ($dayCount > 31 && $dayCount <= 92) {
			$num = 3;
		} elseif ($dayCount > 92) {
			$num = 30;
		} else {
			$num = 30;
		}
		if ($num == 0) {
			$xAxis = ['00', '01', '02', '03', '04', '05', '06', '07', '08', '09', '10', '11', '12', '13', '14', '15', '16', '17', '18', '19', '20', '21', '22', '23'];
			$timeType = '%H';
		} elseif ($num != 0) {
			while ($dt_start <= $dt_end) {
				if ($num == 30) {
					$xAxis[] = date('Y-m', $dt_start);
					$dt_start = strtotime("+1 month", $dt_start);
					$timeType = '%Y-%m';
				} else {
					$xAxis[] = date('Y-m-d', $dt_start);
					$dt_start = strtotime("+$num day", $dt_start);
					$timeType = '%Y-%m-%d';
				}
			}
		}
		if ($dt_start == $dt_end) {//一天
			$time[1] = date('Y-m-d H:i:s', $dt_end + 86400);
		}
		$order_list = $agentOrderServices->orderAddTimeList($where, $time, $timeType);
		if ($order_list) {
			if ($type == 2) {//订单量
				$order_list = array_column($order_list, 'count', 'day');
			} else {
				$order_list = array_column($order_list, 'price', 'day');
			}
		}
		$seriesData = [];
		$pay_count = [];
		if ($type == 3) $pay_count = array_column($agentOrderServices->getProductTrend($where, $time, $timeType, 'add_time', 'count(distinct(uid))', 'pay'), 'num', 'days');
		foreach ($xAxis as $key => $item) {
			$number = $order_list[$item] ?? 0;
			if ($type == 3) {//计算客单价
				$payUserCount = $pay_count[$item] ?? 0;
				$number = $payUserCount ? (float)bcdiv((string)$number, (string)$payUserCount, 2) : 0;
			}
			$seriesData[] = (float)$number;
			if (in_array($num, [1, 3])) {
				$xAxis[$key] = date('m-d', strtotime($item));
			}
		}
		$name = $type == 1 ? '销售额' : ($type == 2 ? '订单数量' : '客单价');
		$data = [
			'categories' => $xAxis,
			'series' => [
				['name' => $name, 'data' => $seriesData]
			],
		];
        return app('json')->success($data);
    }

	/**
	 * 门店统计、排行
	 * @param Request $request
	 * @param AgentOrderServices $agentOrderServices
	 * @return \think\Response
	 */
    public function storeChart(Request $request, AgentOrderServices $agentOrderServices)
    {
		$where = $request->getMore([
			['data', '', '', 'time'],
			['store_id', ''],
			['store_ids', ''],
			['orderby', 'pay_price desc'],//排序
			['show_type',1],//排序
		]);
		$storeIds = $this->services->resolveRequestStoreIds(
			(int)$this->agentId,
			$where['store_id'] ?? '',
			$where['store_ids'] ?? ''
		);
		unset($where['store_id'], $where['store_ids']);
		$result = [];
		if ($storeIds) {
			$where['store_id'] = $storeIds;
			$orderBy = (string)$where['orderby'];
			if ($orderBy) {
				$orderArr = explode(' ', $orderBy);
				if (!in_array($orderArr[0] ?? '', ['pay_price', 'order_number', 'unit_price'])) {
					return app('json')->fail('参数错误');
				}
				//去掉排序
				if (!in_array($orderArr[1] ?? '', ['asc', 'desc'])) {
					$orderBy = '';
				}
			}
			unset($where['orderby']);
			$result = $agentOrderServices->storeChart($where, $orderBy);
		}
        return app('json')->success($result);
    }

    //员工业绩表 类型1销售业绩 2耗卡业绩
    public function yejiRanking(Request $request,SatffYejiServices $service){
        $where = $request->getMore([
            ['data', '', '', 'created_time'],
            ['store_id',0],
            ['store_ids', ''],
            ['sum_type',1]
        ]);
        $where['store_id'] = $this->services->resolveRequestStoreIds(
            (int)$this->agentId,
            $where['store_id'] ?? '',
            $where['store_ids'] ?? ''
        );
        unset($where['store_ids']);
        $result=$service->yejiRanking($where);
        return app('json')->success($result);
    }

    /** 劳动项目数排行 */
    public function projectRanking(Request $request, SatffYejiServices $service)
    {
        $where = $request->getMore([
            ['data', '', '', 'created_time'],
            ['store_id', 0],
            ['store_ids', ''],
        ]);
        $where['store_id'] = $this->services->resolveRequestStoreIds(
            (int)$this->agentId,
            $where['store_id'] ?? '',
            $where['store_ids'] ?? ''
        );
        unset($where['store_ids']);
        $result = $service->projectRanking($where);
        return app('json')->success($result);
    }

    //获取点客
    public function dianke(Request $request,SatffYejiServices $service){
        $where = $request->getMore([
            ['data', '', '', 'created_time'],
            ['store_id',0],
            ['store_ids', ''],
        ]);
        $where['store_id'] = $this->services->resolveRequestStoreIds(
            (int)$this->agentId,
            $where['store_id'] ?? '',
            $where['store_ids'] ?? ''
        );
        unset($where['store_ids']);
        $where['sum_type']=2;
        $result=$service->dianke($where);
        return app('json')->success($result);
    }
}
