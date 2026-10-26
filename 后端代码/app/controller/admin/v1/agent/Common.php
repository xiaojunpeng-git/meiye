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
namespace app\controller\admin\v1\agent;

use app\controller\admin\AuthController;
use app\services\agent\SystemRegionAgentServices;
use app\services\order\agent\AgentOrderServices;
use app\services\store\SystemStoreServices;


/**
 * 公共接口基类 主要存放公共接口
 * Class Common
 * @package app\controller\admin
 */
class Common extends AuthController
{

	/**
	 * 获取区域内所有门店
	 * @param SystemStoreServices $services
	 * @param SystemRegionAgentServices $regionAgentServices
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function storeList(SystemStoreServices $services, SystemRegionAgentServices $regionAgentServices)
	{
		$storeIds = $regionAgentServices->getRegionAgentStoreId((int)$this->agentId);
		$data = [];
		if ($storeIds) {
			$data = $services->getColumn(['id' => $storeIds, 'is_del' => 0, 'is_show' => 1], 'id,name');
		}
		return $this->success($data);
	}

	/**
	 * 代理商首页头部统计数据
	 * @param AgentOrderServices $agentOrderServices
	 * @param SystemRegionAgentServices $regionAgentServices
	 * @return mixed
	 */
    public function homeStatics(AgentOrderServices $agentOrderServices, SystemRegionAgentServices $regionAgentServices)
    {
		$where = $this->request->getMore([
			['store_id', ''],
			['store_ids', ''],
			['data', '', '', 'time']
		]);
		$where['store_id'] = $regionAgentServices->resolveRequestStoreIds(
			(int)$this->agentId,
			$where['store_id'] ?? '',
			$where['store_ids'] ?? ''
		);
		unset($where['store_ids']);
        return $this->success($agentOrderServices->homeStatics($where));
    }

	/**
	 * 订单图表
	 * @param AgentOrderServices $agentOrderServices
	 * @param SystemRegionAgentServices $regionAgentServices
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function orderChart(AgentOrderServices $agentOrderServices, SystemRegionAgentServices $regionAgentServices)
    {
		$where = $this->request->getMore([
			['store_id', ''],
			['store_ids', ''],
			['data', '', '', 'time'],//默认30天
		]);
		$where['store_id'] = $regionAgentServices->resolveRequestStoreIds(
			(int)$this->agentId,
			$where['store_id'] ?? '',
			$where['store_ids'] ?? ''
		);
		unset($where['store_ids']);
        return $this->success($agentOrderServices->orderCharts($where));
    }

	/**
	 * 门店统计、排行
	 * @param AgentOrderServices $agentOrderServices
	 * @return mixed
	 */
    public function storeChart(AgentOrderServices $agentOrderServices, SystemRegionAgentServices $regionAgentServices)
    {
		$where = $this->request->getMore([
			['data', '', '', 'time'],
			['store_id', ''],
			['store_ids', ''],
		]);
		$storeIds = $regionAgentServices->resolveRequestStoreIds(
			(int)$this->agentId,
			$where['store_id'] ?? '',
			$where['store_ids'] ?? ''
		);
		unset($where['store_id'], $where['store_ids']);
		$result = [];
		if ($storeIds) {
			$where['store_id'] = $storeIds;
			$result = $agentOrderServices->storeChart($where);
		}
        return $this->success($result);
    }


}
